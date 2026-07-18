<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Widget;

use YassinStore\AiAssistant\Application\Ai\ImageAttachmentPolicy;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionPolicy;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Presentation\Rest\RestApi;
use YassinStore\AiAssistant\Support\AssetVersion;

final class Widget
{
    /** @var Settings */
    private $settings;

    /** @var bool */
    private $automaticRendered = false;

    /** @var bool */
    private $assetsLocalized = false;

    /** @var bool */
    private $appearanceInjected = false;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', array($this, 'registerAssets'));
        add_action('wp_footer', array($this, 'renderAutomatic'), 5);
        add_shortcode('yassin_ai_assistant', array($this, 'shortcode'));
    }

    public function registerAssets(): void
    {
        wp_register_style(
            'ysai-widget',
            YSAI_PLUGIN_URL . 'assets/css/widget.css',
            array(),
            AssetVersion::for('assets/css/widget.css')
        );
        wp_register_script(
            'ysai-widget',
            YSAI_PLUGIN_URL . 'assets/js/widget.js',
            array(),
            AssetVersion::for('assets/js/widget.js'),
            true
        );

        if ($this->shouldRenderAutomatically()) {
            $this->enqueueAssets();
        }
    }

    public function renderAutomatic(): void
    {
        if ($this->automaticRendered || !$this->shouldRenderAutomatically()) {
            return;
        }

        $this->automaticRendered = true;
        echo $this->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup() escapes dynamic values.
    }

    /** @param array<string,mixed> $attributes */
    public function shortcode(array $attributes = array()): string
    {
        if (!(bool) $this->settings->get('widget_enabled', 1)) {
            return '';
        }

        $this->automaticRendered = true;
        $this->enqueueAssets();
        return $this->markup();
    }

    private function enqueueAssets(): void
    {
        if (!wp_style_is('ysai-widget', 'registered') || !wp_script_is('ysai-widget', 'registered')) {
            $this->registerAssets();
        }

        wp_enqueue_style('ysai-widget');
        wp_enqueue_script('ysai-widget');

        if (!$this->appearanceInjected) {
            wp_add_inline_style('ysai-widget', $this->appearanceCss());
            $this->appearanceInjected = true;
        }

        // A shortcode may be rendered from a late block/template after
        // wp_head has already printed the style queue. Print this registered
        // handle at the shortcode location in that case; WordPress marks it
        // done, so automatic and multi-shortcode rendering stay single-load.
        if (did_action('wp_head') > 0 && !wp_style_is('ysai-widget', 'done')) {
            wp_print_styles(array('ysai-widget'));
        }

        if ($this->assetsLocalized) {
            return;
        }
        $this->assetsLocalized = true;
        $httpTimeout = (int) $this->settings->get('http_timeout_seconds', 30);
        $maxToolRounds = (int) $this->settings->get('max_tool_rounds', 6);
        wp_localize_script('ysai-widget', 'YSAIWidgetConfig', array(
            'bootUrl' => rest_url(RestApi::NAMESPACE . '/boot'),
            'chatUrl' => rest_url(RestApi::NAMESPACE . '/chat'),
            'conversationExportUrl' => rest_url(RestApi::NAMESPACE . '/conversation/export'),
            'conversationDeleteUrl' => rest_url(RestApi::NAMESPACE . '/conversation/delete'),
            'storageKey' => 'ysai_storefront_v1_'
                . substr(hash('sha256', home_url('/')), 0, 16),
            'maxImageBytes' => ImageAttachmentPolicy::MAX_DECODED_BYTES,
            'maxSourceImageBytes' => ImageAttachmentPolicy::MAX_SOURCE_BYTES,
            'maxSourceImageHeaderBytes' => ImageAttachmentPolicy::MAX_SOURCE_HEADER_BYTES,
            'maxSourceImageWidth' => ImageAttachmentPolicy::MAX_SOURCE_WIDTH,
            'maxSourceImageHeight' => ImageAttachmentPolicy::MAX_SOURCE_HEIGHT,
            'maxSourceImagePixels' => ImageAttachmentPolicy::MAX_SOURCE_PIXELS,
            'maxImages' => ImageAttachmentPolicy::MAX_ITEMS,
            'turnDeadlineMs' => TurnExecutionPolicy::clientDeadlineMilliseconds(
                $httpTimeout,
                $maxToolRounds
            ),
            'retryRetentionMs' => TurnExecutionPolicy::retryRetentionMilliseconds(
                $httpTimeout,
                $maxToolRounds
            ),
            'siteIconUrl' => esc_url_raw((string) get_site_icon_url(96)),
            'text' => array(
                'open' => (string) $this->settings->get('widget_button_text', ('مساعدة التسوق')),
                'close' => ('إغلاق المساعد'),
                'send' => ('إرسال'),
                'attach' => ('إرفاق صور'),
                'placeholder' => ('اكتب رسالتك…'),
                'loading' => ('جارٍ بدء المساعد…'),
                'thinking' => ('جارٍ التحقق من معلومات المتجر…'),
                'retry' => ('إعادة المحاولة'),
                'imageLimit' => ('يمكنك إرفاق صورتين بصيغة JPEG أو PNG أو WebP.'),
                'imageTooLarge' => ('إحدى الصور المحددة كبيرة جداً.'),
                'imageDimensionsTooLarge' => ('أبعاد الصورة كبيرة جداً. اختر صورة لا تتجاوز 4096 بكسل لأي ضلع و12 ميجابكسل إجمالاً.'),
                'unsupportedImage' => ('تُقبل صور JPEG وPNG وWebP فقط.'),
                'empty' => ('اكتب رسالة أو أرفق صورة.'),
                'cart' => ('السلة'),
                'cartUnavailable' => ('تعذر تحديث ملخص السلة. افتح صفحة السلة للتحقق منها.'),
                'checkout' => ('إتمام الطلب'),
                'items' => ('منتجات'),
                'selected' => ('تم الاختيار'),
                'imageAttachment' => ('صورة مرفقة'),
                'imageTurnOnly' => ('صورة مرفقة (متاحة للمعالجة في هذا الطلب فقط)'),
                'imagesTurnOnly' => ('صور مرفقة × {count} (متاحة للمعالجة في هذا الطلب فقط)'),
                'imageReading' => ('جارٍ تجهيز الصورة…'),
                'imageReadFailure' => ('تعذرت قراءة الصورة.'),
                'remove' => ('إزالة'),
                'sessionRefreshing' => ('جارٍ تحديث جلسة المساعد…'),
                'conversationReset' => ('انتهت المحادثة السابقة. ابدأ طلباً جديداً.'),
                'genericFailure' => ('تعذر إكمال الطلب بأمان. حاول مرة أخرى.'),
                'requestTimeout' => ('استغرق الطلب وقتاً أطول من الحد الآمن. أعد المحاولة بنفس الطلب.'),
                'retryExpired' => ('انتهت صلاحية إعادة المحاولة المحفوظة. أرسل الطلب مرة أخرى.'),
                'retryRetentionFailed' => ('تعذر الاحتفاظ بالطلب الحالي لإعادة المحاولة الآمنة. لم يتم إرساله.'),
                'browserStorageDegraded' => ('يمكنك متابعة المحادثة في هذه الصفحة بأمان، لكن الاستمرارية بعد إعادة التحميل أو بين علامات التبويب محدودة لأن تخزين المتصفح غير متاح.'),
                'turnRetryPending' => ('تعذر التحقق من نتيجة الطلب. أعد المحاولة نفسها قبل متابعة المحادثة.'),
                'turnRecheckPending' => ('انتهت مهلة إعادة المحاولة. يجري التحقق من النتيجة قبل متابعة المحادثة.'),
                'unavailable' => ('مساعد التسوق غير متاح مؤقتاً.'),
                'invalidResponse' => ('أعاد الخادم استجابة غير صالحة. يمكن إعادة المحاولة بأمان.'),
                'requiresOptions' => ('يتطلب تحديد الخيارات'),
                'outOfStock' => ('غير متوفر'),
                'copy' => ('نسخ الرسالة'),
                'copied' => ('تم النسخ'),
                'reply' => ('الرد على الرسالة'),
                'cancelReply' => ('إلغاء الرد'),
                'replyingTo' => ('الرد على'),
                'previousProducts' => ('المنتجات السابقة'),
                'nextProducts' => ('المنتجات التالية'),
                'quoteProduct' => ('الرد باستخدام هذا المنتج'),
                'productImage' => ('صورة المنتج'),
                'online' => ('متصل الآن'),
                'privacy' => ('بيانات المحادثة'),
                'exportConversation' => ('تصدير المحادثة'),
                'deleteConversation' => ('حذف المحادثة'),
                'exportingConversation' => ('جارٍ تجهيز ملف المحادثة…'),
                'conversationExported' => ('تم تنزيل ملف المحادثة.'),
                'deletingConversation' => ('جارٍ حذف المحادثة…'),
                'conversationDeleted' => ('تم حذف المحادثة وبدء محادثة جديدة.'),
                'conversationDeletedBootFailed' => ('تم حذف المحادثة، لكن تعذر بدء محادثة جديدة. أعد تحميل الصفحة.'),
                'confirmDeleteConversation' => ('هل تريد حذف هذه المحادثة نهائياً؟ لا يمكن التراجع عن الحذف.'),
            ),
        ));
    }

    private function markup(): string
    {
        $instance = 'ysai-' . wp_generate_uuid4();
        $position = (string) $this->settings->get('widget_position', 'right');
        $position = $position === 'left' ? 'left' : 'right';
        $layout = (string) $this->settings->get('widget_product_layout', 'carousel');
        $layout = in_array($layout, array('list', 'grid', 'carousel'), true) ? $layout : 'carousel';
        $cardsPerView = $this->boundedSetting('widget_product_cards_per_view', 1, 3, 1);
        $classes = array(
            'ysai-widget-root',
            'ysai-position-' . $position,
            'ysai-product-layout-' . $layout,
            'ysai-product-cards-' . $cardsPerView,
        );
        if ((bool) $this->settings->get('widget_product_show_description', 1)) {
            $classes[] = 'ysai-show-product-description';
        }

        return sprintf(
            '<div class="%1$s" id="%2$s" dir="rtl" data-ysai-widget="1"><noscript>%3$s</noscript></div>',
            esc_attr(implode(' ', $classes)),
            esc_attr($instance),
            esc_html__('يلزم تفعيل JavaScript لاستخدام مساعد التسوق.', 'yassin-ai-assistant')
        );
    }

    private function appearanceCss(): string
    {
        $defaults = Settings::defaults();
        $colors = array(
            '--ysai-brand' => 'widget_brand_color',
            '--ysai-header-bg' => 'widget_header_background_color',
            '--ysai-header-fg' => 'widget_header_foreground_color',
            '--ysai-chat-bg' => 'widget_chat_background',
            '--ysai-surface' => 'widget_surface_color',
            '--ysai-assistant-bubble' => 'widget_assistant_bubble_color',
            '--ysai-user-bubble' => 'widget_user_bubble_color',
            '--ysai-user-text' => 'widget_user_text_color',
            '--ysai-text' => 'widget_text_color',
            '--ysai-muted' => 'widget_muted_color',
            '--ysai-border' => 'widget_border_color',
        );
        $declarations = array();
        foreach ($colors as $variable => $setting) {
            $color = sanitize_hex_color((string) $this->settings->get($setting, $defaults[$setting]));
            $declarations[] = $variable . ':' . ($color !== false ? $color : (string) $defaults[$setting]);
        }

        $declarations[] = '--ysai-panel-width:' . $this->boundedSetting('widget_panel_width', 340, 560, (int) $defaults['widget_panel_width']) . 'px';
        $declarations[] = '--ysai-panel-height:' . $this->boundedSetting('widget_panel_height', 520, 860, (int) $defaults['widget_panel_height']) . 'px';
        $declarations[] = '--ysai-panel-radius:' . $this->boundedSetting('widget_panel_radius', 12, 36, (int) $defaults['widget_panel_radius']) . 'px';
        $declarations[] = '--ysai-bubble-radius:' . $this->boundedSetting('widget_bubble_radius', 10, 28, (int) $defaults['widget_bubble_radius']) . 'px';
        $declarations[] = '--ysai-card-radius:' . $this->boundedSetting('widget_product_card_radius', 8, 32, (int) $defaults['widget_product_card_radius']) . 'px';
        $declarations[] = '--ysai-font-size:' . $this->boundedSetting('widget_font_size', 13, 18, (int) $defaults['widget_font_size']) . 'px';
        $declarations[] = '--ysai-product-cards:' . $this->boundedSetting('widget_product_cards_per_view', 1, 3, (int) $defaults['widget_product_cards_per_view']);

        $ratios = array('1-1' => '1 / 1', '4-3' => '4 / 3', '3-4' => '3 / 4', '16-9' => '16 / 9');
        $ratio = (string) $this->settings->get('widget_product_image_ratio', (string) $defaults['widget_product_image_ratio']);
        $declarations[] = '--ysai-product-ratio:' . ($ratios[$ratio] ?? $ratios['1-1']);

        return '.ysai-widget-root{' . implode(';', $declarations) . ';}';
    }

    private function boundedSetting(string $key, int $minimum, int $maximum, int $fallback): int
    {
        $value = $this->settings->get($key, $fallback);
        if (!is_numeric($value)) {
            return $fallback;
        }
        return max($minimum, min($maximum, (int) $value));
    }

    private function shouldRenderAutomatically(): bool
    {
        if (
            !(bool) $this->settings->get('widget_enabled', 1)
            || !(bool) $this->settings->get('widget_auto_insert', 1)
            || is_admin()
            || wp_doing_ajax()
            || is_feed()
            || is_embed()
        ) {
            return false;
        }

        if (
            is_checkout() && !is_wc_endpoint_url('order-received')
        ) {
            return false;
        }

        return (bool) apply_filters('ysai_should_render_widget', true);
    }
}
