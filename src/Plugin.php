<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant;

use Throwable;
use YassinStore\AiAssistant\Infrastructure\Composition\PluginKernel;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCommerceCompatibility;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSessionInternalsAdapter;
use YassinStore\AiAssistant\Infrastructure\WordPress\Capabilities;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Presentation\Admin\SchemaAdmin;

final class Plugin
{
    /** @var self|null */ private static $instance;
    /** @var bool */ private $booted = false;
    /** @var Capabilities */ private $capabilities;
    /** @var WooCommerceCompatibility|null */ private $compatibility;
    /** @var bool */ private $compatibilityContractInvalid = false;
    /** @var bool */ private $coreContractInvalid = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->capabilities = new Capabilities();
        $this->compatibility = null;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        (new SchemaAdmin($this->capabilities))->register();
        add_filter(
            'plugin_action_links_' . plugin_basename(YSAI_PLUGIN_FILE),
            array($this, 'actionLinks')
        );

        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerceMissingNotice'));
            return;
        }

        try {
            $this->compatibility = WooCommerceCompatibility::fromPluginContract();
        } catch (Throwable $exception) {
            $this->compatibilityContractInvalid = true;
            add_action('admin_notices', array($this, 'woocommerceCompatibilityContractNotice'));
            return;
        }
        if (!$this->compatibility->admitsInstalledVersion()) {
            add_action('admin_notices', array($this, 'woocommerceVersionNotice'));
            return;
        }

        $wooInternals = new WooSessionInternalsAdapter($this->compatibility);
        try {
            $wooInternals->assertStaticCoreCapabilities();
        } catch (Throwable $exception) {
            $this->coreContractInvalid = true;
            add_action('admin_notices', array($this, 'woocommerceCoreContractNotice'));
            return;
        }

        if (!$this->compatibility->isInstalledVersionPromotionTested()) {
            add_action('admin_notices', array($this, 'woocommerceUntestedVersionNotice'));
        }

        $settings = new Settings();
        $logger = new Logger($settings);
        (new PluginKernel($settings, $logger, $wooInternals))->register();
    }

    public function woocommerceMissingNotice(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . esc_html__(
                'يتطلب وكيل المبيعات الذكي لمتجر ياسين إضافة WooCommerce. تم تعطيل واجهة المساعد وأدوات التجارة حتى يتم تفعيل WooCommerce.',
                'yassin-ai-assistant'
            )
            . '</p></div>';
    }

    public function woocommerceVersionNotice(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }
        $range = $this->compatibility !== null
            ? $this->compatibility->rangeLabel()
            : 'غير متاح';
        echo '<div class="notice notice-error"><p>'
            . esc_html(sprintf(
                /* translators: %s: accepted WooCommerce version range. */
                __('إصدار WooCommerce المثبت خارج النطاق المدعوم (%s). تم تعطيل المساعد بدلاً من تشغيل مسار سلة غير مثبت التوافق.', 'yassin-ai-assistant'),
                $range
            ))
            . '</p></div>';
    }

    public function woocommerceCompatibilityContractNotice(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . esc_html__(
                'تعذر قراءة عقد توافق WooCommerce الخاص بالإضافة. تم تعطيل المساعد لأن حدود التوافق لا يمكن إثباتها.',
                'yassin-ai-assistant'
            )
            . '</p></div>';
    }

    public function woocommerceCoreContractNotice(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . esc_html__(
                'بنية جلسة WooCommerce المثبتة لا تطابق عقد التنفيذ الآمن للسلة. تم تعطيل المساعد بدلاً من التخمين أو استخدام وصول داخلي غير مثبت.',
                'yassin-ai-assistant'
            )
            . '</p></div>';
    }

    public function woocommerceUntestedVersionNotice(): void
    {
        if (!$this->capabilities->currentUserCanManage() || $this->compatibility === null) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html(sprintf(
                /* translators: 1: installed WooCommerce version, 2: latest promotion-tested version. */
                __('WooCommerce %1$s اجتاز عقد القدرات البنيوي، لكنه لم يجتز بوابة النشر الفعلية. ستبقى الدردشة وقراءة السلة متاحتين، وسيظل تعديل السلة معطلاً. آخر إصدار مختبر هو %2$s.', 'yassin-ai-assistant'),
                $this->compatibility->installedVersion(),
                $this->compatibility->testedUpTo()
            ))
            . '</p></div>';
    }

    /** @param array<int,string> $links @return array<int,string> */
    public function actionLinks(array $links): array
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return $links;
        }

        if (!class_exists('WooCommerce')) {
            $url = admin_url('plugins.php');
            $label = esc_html__('WooCommerce مطلوب', 'yassin-ai-assistant');
        } elseif (
            $this->compatibilityContractInvalid
            || $this->coreContractInvalid
            || $this->compatibility === null
            || !$this->compatibility->admitsInstalledVersion()
        ) {
            $url = admin_url('plugins.php');
            $label = esc_html__('توافق WooCommerce غير مثبت', 'yassin-ai-assistant');
        } elseif (SchemaLifecycle::isReady()) {
            $url = admin_url('admin.php?page=ysai-settings');
            $label = esc_html__('الإعدادات', 'yassin-ai-assistant');
        } else {
            $status = SchemaLifecycle::status();
            $url = admin_url('plugins.php');
            $label = isset($status['state']) && $status['state'] === 'unverifiable'
                ? esc_html__('التحقق من قاعدة البيانات غير متاح', 'yassin-ai-assistant')
                : esc_html__('يلزم إصلاح قاعدة البيانات', 'yassin-ai-assistant');
        }

        array_unshift($links, '<a href="' . esc_url($url) . '">' . $label . '</a>');
        return $links;
    }
}
