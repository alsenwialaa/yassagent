<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Admin;

use Throwable;
use YassinStore\AiAssistant\Application\Port\RuntimeReadinessPort;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationMaintenanceRepository;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationMaintenanceConflict;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;
use YassinStore\AiAssistant\Infrastructure\Security\ClientIpResolver;
use YassinStore\AiAssistant\Infrastructure\Gemini\RuntimeProbeTiming;
use YassinStore\AiAssistant\Infrastructure\WordPress\Capabilities;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Presentation\Rest\RestApi;
use YassinStore\AiAssistant\Support\AssetVersion;
use YassinStore\AiAssistant\Support\Json;

final class AdminPages
{
    private const SETTINGS_PAGE = 'ysai-settings';
    private const CONVERSATIONS_PAGE = 'ysai-conversations';

    /** @var Settings */
    private $settings;

    /** @var RuntimeReadinessPort */
    private $readiness;

    /** @var ConversationMaintenanceRepository */
    private $conversations;

    /** @var Capabilities */
    private $capabilities;

    /** @var ClientIpResolver */
    private $clientIps;

    /** @var bool|null */
    private $schemaReady;

    public function __construct(
        Settings $settings,
        RuntimeReadinessPort $readiness,
        ConversationMaintenanceRepository $conversations,
        Capabilities $capabilities,
        ClientIpResolver $clientIps
    ) {
        $this->settings = $settings;
        $this->readiness = $readiness;
        $this->conversations = $conversations;
        $this->capabilities = $capabilities;
        $this->clientIps = $clientIps;
    }

    public function register(): void
    {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'registerSettings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_filter('option_page_capability_ysai_settings_group', array($this, 'settingsCapability'));
        add_action('admin_post_ysai_purge_conversations', array($this, 'purgeConversations'));
        add_action('update_option_' . Settings::OPTION_KEY, array($this, 'retentionChanged'), 10, 3);
    }

    public function menu(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }

        $parent = 'woocommerce';
        $settingsHook = add_submenu_page(
            $parent,
            __('وكيل المبيعات الذكي', 'yassin-ai-assistant'),
            __('وكيل المبيعات الذكي', 'yassin-ai-assistant'),
            $this->capabilities->manage(),
            self::SETTINGS_PAGE,
            array($this, 'renderSettings')
        );
        $conversationsHook = add_submenu_page(
            $parent,
            __('محادثات المساعد الذكي', 'yassin-ai-assistant'),
            __('محادثات المساعد الذكي', 'yassin-ai-assistant'),
            $this->capabilities->manage(),
            self::CONVERSATIONS_PAGE,
            array($this, 'renderConversations')
        );

        foreach (array($settingsHook, $conversationsHook) as $hook) {
            if (is_string($hook) && $hook !== '') {
                add_action('load-' . $hook, array($this, 'verifySchema'));
            }
        }
    }

    public function verifySchema(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }
        $this->schemaReady = SchemaLifecycle::verifyRuntime();
    }

    public function settingsCapability(string $defaultCapability): string
    {
        return $this->capabilities->manage();
    }

    /** @param mixed $oldValue @param mixed $newValue */
    public function retentionChanged($oldValue, $newValue, string $option): void
    {
        if ($option !== Settings::OPTION_KEY || !is_array($newValue)) {
            return;
        }
        $oldDays = is_array($oldValue)
            ? max(1, min(3650, (int) ($oldValue['conversation_retention_days'] ?? 45)))
            : 45;
        $newDays = max(1, min(3650, (int) ($newValue['conversation_retention_days'] ?? 45)));
        $this->settings->refresh();
        if ($newDays >= $oldDays || !SchemaLifecycle::verifyRuntime()) {
            return;
        }
        try {
            $this->conversations->shortenRetention($newDays);
        } catch (Throwable $exception) {
            add_settings_error(
                Settings::OPTION_KEY,
                'ysai_retention_rebase_failed',
                __('تم حفظ السياسة الجديدة، لكن تعذر تحديث تواريخ السجلات القديمة فوراً. سيظل الاستئناف والتنظيف يطبقان المدة الجديدة.', 'yassin-ai-assistant'),
                'error'
            );
        }
    }

    public function registerSettings(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }

        register_setting(
            'ysai_settings_group',
            Settings::OPTION_KEY,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this->settings, 'sanitize'),
                'default' => Settings::defaults(),
            )
        );

        foreach ($this->sections() as $sectionId => $section) {
            add_settings_section(
                $sectionId,
                (string) $section['title'],
                array($this, 'renderSection'),
                self::SETTINGS_PAGE,
                array('description' => (string) ($section['description'] ?? ''))
            );

            foreach ($section['fields'] as $field => $definition) {
                add_settings_field(
                    $field,
                    (string) $definition['label'],
                    array($this, 'renderField'),
                    self::SETTINGS_PAGE,
                    $sectionId,
                    array_merge($definition, array('field' => $field))
                );
            }
        }
    }

    /** @param array<string,mixed> $args */
    public function renderSection(array $args): void
    {
        $description = trim((string) ($args['description'] ?? ''));
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
    }

    /** @param array<string,mixed> $args */
    public function renderField(array $args): void
    {
        $field = (string) $args['field'];
        $type = (string) ($args['type'] ?? 'text');
        $value = $this->settings->get($field, '');
        $name = Settings::OPTION_KEY . '[' . $field . ']';
        $id = 'ysai-' . str_replace('_', '-', $field);
        $description = trim((string) ($args['description'] ?? ''));

        if ($type === 'preview') {
            $this->renderAppearancePreview();
            if ($description !== '') {
                echo '<p class="description">' . wp_kses_post($description) . '</p>';
            }
            return;
        }

        if ($type === 'checkbox') {
            printf(
                '<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
                esc_attr($id),
                esc_attr($name),
                checked((bool) $value, true, false),
                esc_html((string) ($args['checkbox_label'] ?? __('مفعّل', 'yassin-ai-assistant')))
            );
        } elseif ($type === 'select') {
            echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
            foreach ((array) ($args['options'] ?? array()) as $optionValue => $optionLabel) {
                printf(
                    '<option value="%1$s" %2$s>%3$s</option>',
                    esc_attr((string) $optionValue),
                    selected((string) $value, (string) $optionValue, false),
                    esc_html((string) $optionLabel)
                );
            }
            echo '</select>';
        } elseif ($type === 'textarea') {
            $attributes = isset($args['maxlength'])
                ? ' maxlength="' . esc_attr((string) $args['maxlength']) . '"'
                : '';
            printf(
                '<textarea class="large-text code" rows="%1$d" id="%2$s" name="%3$s"%5$s>%4$s</textarea>',
                (int) ($args['rows'] ?? 6),
                esc_attr($id),
                esc_attr($name),
                esc_textarea((string) $value),
                $attributes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from an escaped integer setting.
            );
        } elseif ($type === 'range') {
            printf(
                '<span class="ysai-range-field"><input type="range" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="%6$s" data-ysai-range="1"><output for="%1$s">%3$s</output></span>',
                esc_attr($id),
                esc_attr($name),
                esc_attr((string) $value),
                esc_attr((string) ($args['min'] ?? 0)),
                esc_attr((string) ($args['max'] ?? 100)),
                esc_attr((string) ($args['step'] ?? 1))
            );
        } else {
            $displayValue = (string) $value;
            $placeholder = (string) ($args['placeholder'] ?? '');
            if ($field === 'gemini_api_key') {
                $displayValue = '';
                $placeholder = $this->settings->apiKey() !== ''
                    ? __('تم الإعداد — اترك الحقل فارغاً للاحتفاظ بالمفتاح، أو استخدم خيار إزالة مفتاح API المخزن', 'yassin-ai-assistant')
                    : __('ألصق مفتاح Gemini API', 'yassin-ai-assistant');
            }
            $attributes = '';
            foreach (array('min', 'max', 'step', 'maxlength') as $attribute) {
                if (isset($args[$attribute])) {
                    $attributes .= ' ' . $attribute . '="' . esc_attr((string) $args[$attribute]) . '"';
                }
            }
            if ($field === 'gemini_api_key' && defined('YSAI_GEMINI_API_KEY')) {
                $attributes .= ' disabled aria-disabled="true"';
                $placeholder = __('مقدم بواسطة YSAI_GEMINI_API_KEY', 'yassin-ai-assistant');
            }
            printf(
                '<input class="%1$s" type="%2$s" id="%3$s" name="%4$s" value="%5$s" placeholder="%6$s"%7$s autocomplete="%8$s">',
                esc_attr((string) ($args['class'] ?? 'regular-text')),
                esc_attr($type),
                esc_attr($id),
                esc_attr($name),
                esc_attr($displayValue),
                esc_attr($placeholder),
                $attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped attributes.
                $field === 'gemini_api_key' ? 'new-password' : 'off'
            );
        }

        if ($description !== '') {
            echo '<p class="description">' . wp_kses_post($description) . '</p>';
        }
    }

    public function renderSettings(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            wp_die(esc_html__('ليست لديك صلاحية إدارة هذا المساعد.', 'yassin-ai-assistant'));
        }

        if (!$this->schemaIsReady()) {
            $this->renderSchemaUnavailable();
            return;
        }

        echo '<div class="wrap ysai-admin">';
        echo '<h1>' . esc_html__('وكيل المبيعات الذكي لمتجر ياسين', 'yassin-ai-assistant') . '</h1>';
        echo '<p class="ysai-lead">' . esc_html__('يفسر الذكاء الاصطناعي مقصد العميل، بينما يتحقق الخادم من العقود وينفذ أدوات WooCommerce ويسجل الإيصالات الموثقة.', 'yassin-ai-assistant') . '</p>';
        settings_errors();

        echo '<div class="ysai-status-card">';
        echo '<strong>' . esc_html__('جاهزية Gemini التشغيلية', 'yassin-ai-assistant') . '</strong>';
        $runtimeStatus = $this->readiness->status();
        $runtimeReady = !empty($runtimeStatus['ready']);
        echo '<span class="ysai-status ' . ($runtimeReady ? 'is-ready' : 'is-not-ready') . '">';
        echo esc_html($this->runtimeStatusLabel($runtimeStatus));
        echo '</span>';
        echo '<button type="button" class="button" id="ysai-test-connection">' . esc_html__('فحص جاهزية Gemini', 'yassin-ai-assistant') . '</button>';
        echo '<span id="ysai-test-result" role="status" aria-live="polite"></span>';
        echo '</div>';
        $this->renderBootAdmissionDiagnostics();
        $this->renderCartMutationSessionPolicy();

        if (defined('YSAI_GEMINI_API_KEY')) {
            echo '<div class="notice notice-info inline"><p>'
                . esc_html__('يتم توفير مفتاح API بواسطة YSAI_GEMINI_API_KEY ولا يمكن استبداله من هذه الصفحة.', 'yassin-ai-assistant')
                . '</p></div>';
        }

        echo '<form action="options.php" method="post">';
        settings_fields('ysai_settings_group');
        do_settings_sections(self::SETTINGS_PAGE);
        submit_button();
        echo '</form></div>';
    }

    private function renderBootAdmissionDiagnostics(): void
    {
        $diagnostics = $this->clientIps->diagnostics();
        $mode = (string) ($diagnostics['mode'] ?? 'unavailable');
        $headerStatus = (string) ($diagnostics['header_status'] ?? 'remote_invalid');
        $remote = (string) ($diagnostics['remote_ip'] ?? 'unknown');
        $resolved = (string) ($diagnostics['resolved_ip'] ?? 'unknown');
        $count = (int) ($diagnostics['trusted_proxy_count'] ?? 0);
        $headerLabels = array(
            'accepted' => __('مقبولة', 'yassin-ai-assistant'),
            'absent' => __('غير موجودة', 'yassin-ai-assistant'),
            'ignored_untrusted_peer' => __('تم تجاهلها لأن النظير غير موثوق', 'yassin-ai-assistant'),
            'missing' => __('مفقودة', 'yassin-ai-assistant'),
            'invalid' => __('غير صالحة', 'yassin-ai-assistant'),
            'remote_invalid' => __('عنوان النظير غير صالح', 'yassin-ai-assistant'),
        );
        $headerLabel = isset($headerLabels[$headerStatus]) ? $headerLabels[$headerStatus] : __('غير معروفة', 'yassin-ai-assistant');

        echo '<div class="ysai-status-card">';
        echo '<strong>' . esc_html__('هوية قبول بدء الجلسة', 'yassin-ai-assistant') . '</strong>';
        if ($mode === 'trusted_proxy' && $headerStatus === 'accepted') {
            echo '<span class="ysai-status is-ready">'
                . esc_html(sprintf(
                    __('تم التحقق من سلسلة الوكيل الموثوق: النظير %1$s ← العميل %2$s', 'yassin-ai-assistant'),
                    $remote,
                    $resolved
                ))
                . '</span>';
        } elseif ($mode === 'trusted_proxy') {
            echo '<span class="ysai-status is-not-ready">'
                . esc_html(sprintf(
                    __('تم اكتشاف النظير الموثوق %1$s، لكن حالة X-Forwarded-For هي %2$s؛ سيتم استخدام عنوان النظير.', 'yassin-ai-assistant'),
                    $remote,
                    $headerLabel
                ))
                . '</span>';
        } elseif ($mode === 'direct_peer') {
            echo '<span class="ysai-status is-ready">'
                . esc_html(sprintf(
                    __('وضع الاتصال المباشر: %1$s. يتم تجاهل ترويسات التحويل.', 'yassin-ai-assistant'),
                    $resolved
                ))
                . '</span>';
        } else {
            echo '<span class="ysai-status is-not-ready">'
                . esc_html__('تعذر تحديد عنوان عميل الطلب الحالي.', 'yassin-ai-assistant')
                . '</span>';
        }
        echo '<span class="description">'
            . esc_html(sprintf(
                __('عدد شبكات الوكيل الموثوقة المضبوطة: %d.', 'yassin-ai-assistant'),
                $count
            ))
            . '</span>';
        echo '<span class="description">'
            . esc_html__('حدود بدء الجلسة خلال عشر دقائق: 30 لكل متصفح، و600 لكل شبكة محددة، و3000 للموقع كله.', 'yassin-ai-assistant')
            . '</span>';
        echo '</div>';
    }

    private function renderCartMutationSessionPolicy(): void
    {
        echo '<div class="ysai-status-card">';
        echo '<strong>' . esc_html__('تعديلات السلة عبر المحادثة', 'yassin-ai-assistant') . '</strong>';
        echo '<span class="ysai-status">'
            . esc_html__('يتم التحقق داخل جلسة المتجر الفعلية عند بدء المساعد.', 'yassin-ai-assistant')
            . '</span>';
        echo '<span class="description">'
            . esc_html__('لا تنشئ صفحة الإدارة جلسة متسوق. يعلن بدء المساعد النتيجة الدقيقة لكل جلسة؛ وإذا تعذر التعديل تبقى الدردشة وقراءة السلة وروابط إتمام الطلب متاحة.', 'yassin-ai-assistant')
            . '</span>';
        echo '</div>';
    }

    public function renderConversations(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            wp_die(esc_html__('ليست لديك صلاحية عرض المحادثات.', 'yassin-ai-assistant'));
        }

        if (!$this->schemaIsReady()) {
            $this->renderSchemaUnavailable();
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only administrator pagination does not mutate state.
        $rawPage = isset($_GET['paged']) && is_scalar($_GET['paged'])
            ? sanitize_text_field(wp_unslash((string) $_GET['paged']))
            : '1';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $page = max(1, absint($rawPage));
        $perPage = 25;
        try {
            $rows = $this->conversations->adminList($page, $perPage);
            $total = $this->conversations->adminCount();
        } catch (Throwable $exception) {
            $this->renderConversationDatabaseUnavailable();
            return;
        }
        $pages = max(1, (int) ceil($total / $perPage));

        echo '<div class="wrap ysai-admin"><h1>' . esc_html__('محادثات المساعد الذكي', 'yassin-ai-assistant') . '</h1>';
        echo '<p>' . esc_html__('تعرض هذه الشاشة بيانات التشغيل الوصفية فقط. المتصفح ليس مصدر حقيقة المحادثة؛ الرسائل القانونية محفوظة على الخادم.', 'yassin-ai-assistant') . '</p>';

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only status follows a nonce-protected redirect.
        $purged = isset($_GET['purged']) && is_scalar($_GET['purged'])
            ? sanitize_key(wp_unslash((string) $_GET['purged']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ($purged === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('تم حذف بيانات المحادثات.', 'yassin-ai-assistant') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ysai-danger-zone">';
        echo '<input type="hidden" name="action" value="ysai_purge_conversations">';
        wp_nonce_field('ysai_purge_conversations');
        submit_button(__('حذف جميع بيانات المحادثات', 'yassin-ai-assistant'), 'delete', 'submit', false, array(
            'onclick' => "return window.confirm('" . esc_js(__('سيؤدي هذا إلى حذف جميع محادثات المساعد وإيصالات الإجراءات نهائياً. هل تريد المتابعة؟', 'yassin-ai-assistant')) . "');",
        ));
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        foreach (
            array(
            __('المحادثة', 'yassin-ai-assistant'),
            __('الرسائل', 'yassin-ai-assistant'),
            __('آخر نتيجة', 'yassin-ai-assistant'),
            __('آخر تحديث (UTC)', 'yassin-ai-assistant'),
            __('انتهاء الصلاحية (UTC)', 'yassin-ai-assistant'),
            ) as $heading
        ) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($rows === array()) {
            echo '<tr><td colspan="5">' . esc_html__('لا توجد محادثات.', 'yassin-ai-assistant') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                try {
                    $state = Json::decodeRequiredObject((string) ($row['state'] ?? ''), 'Conversation state');
                    $lastOutcome = (string) ($state['last_outcome'] ?? '—');
                } catch (\Throwable $exception) {
                    $lastOutcome = __('حالة مخزنة غير صالحة', 'yassin-ai-assistant');
                }
                echo '<tr>';
                echo '<td><code>' . esc_html((string) ($row['public_id'] ?? '')) . '</code></td>';
                echo '<td>' . esc_html((string) (int) ($row['message_count'] ?? 0)) . '</td>';
                echo '<td>' . esc_html($lastOutcome) . '</td>';
                echo '<td>' . esc_html((string) ($row['updated_at'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['expires_at'] ?? '')) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $page,
                'total' => $pages,
            )));
            echo '</div></div>';
        }
        echo '</div>';
    }

    public function purgeConversations(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : '';
        if ($method !== 'POST') {
            wp_die(esc_html__('يتطلب حذف المحادثات طلب POST.', 'yassin-ai-assistant'));
        }
        if (!$this->capabilities->currentUserCanManage()) {
            wp_die(esc_html__('غير مسموح.', 'yassin-ai-assistant'), '', array('response' => 403));
        }
        check_admin_referer('ysai_purge_conversations');
        if (!SchemaLifecycle::verifyRuntime()) {
            wp_die(
                esc_html__('قاعدة بيانات المساعد غير جاهزة. أصلحها قبل حذف المحادثات.', 'yassin-ai-assistant'),
                '',
                array('response' => 503)
            );
        }
        try {
            $this->conversations->purgeAll();
        } catch (ConversationMaintenanceConflict $exception) {
            wp_die(
                esc_html__('لا يمكن حذف بيانات المحادثات أثناء وجود طلب أو عملية سلة نشطة. انتظر اكتمالها ثم أعد المحاولة.', 'yassin-ai-assistant'),
                '',
                array('response' => 409)
            );
        } catch (Throwable $exception) {
            wp_die(
                esc_html__('تعذر حذف بيانات المحادثات من قاعدة البيانات. لم تُعتبر العملية ناجحة.', 'yassin-ai-assistant'),
                '',
                array('response' => 500)
            );
        }
        wp_safe_redirect(add_query_arg(array('page' => self::CONVERSATIONS_PAGE, 'purged' => '1'), admin_url('admin.php')));
        exit;
    }

    public function enqueue(string $hook): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }
        if (strpos($hook, self::SETTINGS_PAGE) === false && strpos($hook, self::CONVERSATIONS_PAGE) === false) {
            return;
        }

        wp_enqueue_style(
            'ysai-admin',
            YSAI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            AssetVersion::for('assets/css/admin.css')
        );
        wp_enqueue_script(
            'ysai-admin',
            YSAI_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            AssetVersion::for('assets/js/admin.js'),
            true
        );
        wp_localize_script('ysai-admin', 'YSAIAdmin', array(
            'testUrl' => rest_url(RestApi::NAMESPACE . '/admin/test'),
            'nonce' => wp_create_nonce('wp_rest'),
            'testing' => __('جارٍ فحص الوصول والاستدعاء المنظم…', 'yassin-ai-assistant'),
            'failed' => __('فشل فحص الجاهزية.', 'yassin-ai-assistant'),
            'timedOut' => __('انتهت مهلة فحص الجاهزية. أعد المحاولة وتحقق من اتصال الخادم بالمزوّد.', 'yassin-ai-assistant'),
            'connected' => __('تم التحقق من الوصول إلى النموذج واستدعاء دالة منظمة بنجاح.', 'yassin-ai-assistant'),
            'timeoutMs' => RuntimeProbeTiming::clientTimeoutMilliseconds(
                (int) $this->settings->get('http_timeout_seconds', 30)
            ),
        ));
    }

    private function renderAppearancePreview(): void
    {
        $siteIcon = esc_url((string) get_site_icon_url(96));
        echo '<div class="ysai-admin-preview" data-ysai-preview="1" inert aria-hidden="true">';
        echo '<div class="ysai-preview-panel">';
        echo '<div class="ysai-preview-header"><span class="ysai-preview-mark'
            . ($siteIcon !== '' ? ' has-site-icon' : '')
            . '" aria-hidden="true">';
        if ($siteIcon !== '') {
            echo '<img src="' . esc_url($siteIcon) . '" alt="">';
        }
        echo '<svg class="ysai-preview-mark-fallback" viewBox="0 0 24 24"><path d="M7.5 18.5 4 20l1.2-3.6A8 8 0 1 1 20 12a8 8 0 0 1-12.5 6.5Z"></path><path d="M8 10.5h8M8 14h5"></path></svg>';
        echo '</span><span><strong data-ysai-preview-title>مساعدة متجر ياسين</strong><small>متصل الآن</small></span><button type="button" aria-label="إغلاق المعاينة">×</button></div>';
        echo '<div class="ysai-preview-chat">';
        echo '<div class="ysai-preview-message is-assistant"><span dir="auto">مرحباً، أخبرني ما الذي تبحث عنه وسأساعدك.</span></div>';
        echo '<div class="ysai-preview-products" data-ysai-preview-products="1">';
        for ($index = 0; $index < 3; $index++) {
            echo '<article class="ysai-preview-product"><div class="ysai-preview-product-image" aria-hidden="true">◇</div><div><strong>'
                . esc_html($index === 0 ? __('منتج طبيعي', 'yassin-ai-assistant') : __('منتج مقترح', 'yassin-ai-assistant'))
                . '</strong><b>' . esc_html($index === 0 ? '$12.00' : '$8.50') . '</b><p>'
                . esc_html__('وصف قصير ومفيد للمنتج.', 'yassin-ai-assistant')
                . '</p></div></article>';
        }
        echo '</div>';
        echo '<div class="ysai-preview-message is-user"><span dir="auto">أريد الخيار الأفضل للاستخدام اليومي.</span></div>';
        echo '</div>';
        echo '<div class="ysai-preview-composer"><div class="ysai-preview-composer-dock"><button type="button" class="ysai-preview-send" aria-label="إرسال"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5"></path><path d="m6.5 10.5 5.5-5.5 5.5 5.5"></path></svg></button><span>اكتب رسالتك…</span><button type="button" class="ysai-preview-attach" aria-label="إرفاق صورة"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 5.5h15v13h-15z"></path><path d="m5 16 4.2-4.2 3.1 3.1 2.1-2.1 5.1 5.1"></path><path d="M15.8 9.2h.1"></path></svg></button></div></div>';
        echo '</div></div>';
    }

    private function schemaIsReady(): bool
    {
        if ($this->schemaReady === null) {
            $this->schemaReady = SchemaLifecycle::verifyRuntime();
        }
        return $this->schemaReady;
    }

    private function renderSchemaUnavailable(): void
    {
        echo '<div class="wrap ysai-admin"><h1>'
            . esc_html__('وكيل المبيعات الذكي لمتجر ياسين', 'yassin-ai-assistant')
            . '</h1><div class="notice notice-error inline"><p>'
            . esc_html__('قاعدة بيانات المساعد غير جاهزة. استخدم إجراء الإصلاح من صفحة الإضافات ثم أعد فتح هذه الصفحة.', 'yassin-ai-assistant')
            . '</p></div><p><a class="button button-primary" href="'
            . esc_url(admin_url('plugins.php'))
            . '">'
            . esc_html__('فتح صفحة الإضافات', 'yassin-ai-assistant')
            . '</a></p></div>';
    }

    private function renderConversationDatabaseUnavailable(): void
    {
        echo '<div class="wrap ysai-admin"><h1>'
            . esc_html__('محادثات المساعد الذكي', 'yassin-ai-assistant')
            . '</h1><div class="notice notice-error inline"><p>'
            . esc_html__('تعذر قراءة بيانات المحادثات من قاعدة البيانات. لم تُعرض نتيجة فارغة ولم يُتح إجراء الحذف. أعد المحاولة بعد التحقق من قاعدة البيانات.', 'yassin-ai-assistant')
            . '</p></div></div>';
    }

    /** @param array<string,mixed> $status */
    private function runtimeStatusLabel(array $status): string
    {
        $code = is_string($status['code'] ?? null) ? (string) $status['code'] : '';
        $labels = array(
            'ready' => __('تم التحقق من الوصول إلى النموذج والاستدعاء المنظم المصغر.', 'yassin-ai-assistant'),
            'ready_recheck_in_progress' => __('إثبات الجاهزية الحالي صالح، ويجري فحص إداري جديد.', 'yassin-ai-assistant'),
            'ready_recheck_interrupted' => __('إثبات الجاهزية الحالي صالح، لكن الفحص الإداري الأخير انقطع.', 'yassin-ai-assistant'),
            'ready_with_probe_failure' => __('إثبات الجاهزية الحالي صالح، لكن الفحص الإداري الأخير فشل مؤقتاً.', 'yassin-ai-assistant'),
            'api_key_missing' => __('مفتاح Gemini API غير مُعد.', 'yassin-ai-assistant'),
            'runtime_check_in_progress' => __('يجري فحص جاهزية Gemini الآن.', 'yassin-ai-assistant'),
            'runtime_check_interrupted' => __('انقطع فحص الجاهزية قبل اكتماله. أعد تشغيله.', 'yassin-ai-assistant'),
            'runtime_check_expired' => __('انتهت صلاحية إثبات الجاهزية. شغّل فحصاً جديداً.', 'yassin-ai-assistant'),
            'runtime_configuration_changed' => __('تغير إعداد المزود منذ آخر إثبات. شغّل فحصاً جديداً.', 'yassin-ai-assistant'),
            'disabled' => __('المساعد معطل من الإعدادات.', 'yassin-ai-assistant'),
        );
        return isset($labels[$code])
            ? $labels[$code]
            : __('غير متحقق — شغّل فحص جاهزية Gemini.', 'yassin-ai-assistant');
    }

    /** @return array<string,array<string,mixed>> */
    private function sections(): array
    {
        return array(
            'ysai_ai' => array(
                'title' => __('تشغيل الذكاء الاصطناعي', 'yassin-ai-assistant'),
                'description' => __('يفسر نموذج واحد مقصد العميل ويستدعي أدوات يتحقق منها الخادم. لا يوجد موجه نوايا محلي أو محلل منتجات محلي.', 'yassin-ai-assistant'),
                'fields' => array(
                    'enabled' => array('label' => __('المساعد', 'yassin-ai-assistant'), 'type' => 'checkbox'),
                    'gemini_api_key' => array('label' => __('مفتاح Gemini API', 'yassin-ai-assistant'), 'type' => 'password', 'description' => __('يُخزن في خيارات WordPress ما لم يكن YSAI_GEMINI_API_KEY معرفاً. اتركه فارغاً للاحتفاظ بالمفتاح المخزن.', 'yassin-ai-assistant')),
                    'clear_gemini_api_key' => array('label' => __('إزالة مفتاح API المخزن', 'yassin-ai-assistant'), 'type' => 'checkbox', 'description' => __('يزيل المفتاح المخزن في WordPress عند حفظ الإعدادات. لن يتم تغيير الثابت YSAI_GEMINI_API_KEY.', 'yassin-ai-assistant')),
                    'gemini_thinking_level' => array('label' => __('مستوى التفكير لنماذج Gemini 3', 'yassin-ai-assistant'), 'type' => 'select', 'options' => array(
                        'minimal' => __('أدنى', 'yassin-ai-assistant'),
                        'low' => __('منخفض — موصى به للمبيعات', 'yassin-ai-assistant'),
                        'medium' => __('متوسط', 'yassin-ai-assistant'),
                        'high' => __('مرتفع', 'yassin-ai-assistant'),
                    ), 'description' => __('يستخدم Gemini 3.5 Flash حصراً، والمستوى المنخفض افتراضياً لتقليل زمن الاستجابة مع الحفاظ على التفكير في مهام الأدوات.', 'yassin-ai-assistant')),
                    'max_output_tokens' => array('label' => __('الحد الأقصى لرموز الإخراج', 'yassin-ai-assistant'), 'type' => 'number', 'min' => 256, 'max' => 8192, 'step' => 1, 'class' => 'small-text'),
                    'http_timeout_seconds' => array('label' => __('مهلة HTTP (بالثواني)', 'yassin-ai-assistant'), 'type' => 'number', 'min' => 10, 'max' => 90, 'step' => 1, 'class' => 'small-text'),
                    'max_tool_rounds' => array('label' => __('الحد الأقصى لجولات الأدوات', 'yassin-ai-assistant'), 'type' => 'number', 'min' => 3, 'max' => 10, 'step' => 1, 'class' => 'small-text'),
                    'allow_images' => array('label' => __('إدخال الصور', 'yassin-ai-assistant'), 'type' => 'checkbox'),
                    'store_guidance' => array(
                        'label' => __('إرشادات خاصة بالمتجر', 'yassin-ai-assistant'),
                        'type' => 'textarea',
                        'rows' => 7,
                        'maxlength' => Settings::STORE_GUIDANCE_MAX_BYTES,
                        'description' => __('اكتب حقائق المتجر ونبرة البيع والتفضيلات العامة فقط. يرسلها الخادم كبيانات JSON منخفضة السلطة؛ لا يمكنها تغيير اللغة أو الأدوات أو قواعد التحقق والتنفيذ. يطبق الخادم حد الحجم النهائي بالبايت.', 'yassin-ai-assistant'),
                    ),
                ),
            ),
            'ysai_widget' => array(
                'title' => __('واجهة المساعد في المتجر', 'yassin-ai-assistant'),
                'description' => __('تحكم في مكان ظهور المساعد والنصوص المعروضة للعميل. يتم ضبط التصميم المرئي بشكل منفصل أدناه.', 'yassin-ai-assistant'),
                'fields' => array(
                    'widget_enabled' => array('label' => __('الواجهة', 'yassin-ai-assistant'), 'type' => 'checkbox'),
                    'widget_auto_insert' => array('label' => __('الإدراج التلقائي', 'yassin-ai-assistant'), 'type' => 'checkbox', 'description' => __('عطّل هذا الخيار لعرض الواجهة فقط بواسطة [yassin_ai_assistant].', 'yassin-ai-assistant')),
                    'widget_position' => array('label' => __('موضع الشاشة', 'yassin-ai-assistant'), 'type' => 'select', 'options' => array('right' => __('اليمين', 'yassin-ai-assistant'), 'left' => __('اليسار', 'yassin-ai-assistant'))),
                    'widget_button_text' => array('label' => __('نص زر الفتح', 'yassin-ai-assistant'), 'type' => 'text', 'maxlength' => Settings::WIDGET_TEXT_LIMITS['widget_button_text']),
                    'widget_title' => array('label' => __('عنوان اللوحة', 'yassin-ai-assistant'), 'type' => 'text', 'maxlength' => Settings::WIDGET_TEXT_LIMITS['widget_title']),
                    'widget_subtitle' => array('label' => __('العنوان الفرعي للوحة', 'yassin-ai-assistant'), 'type' => 'text', 'maxlength' => Settings::WIDGET_TEXT_LIMITS['widget_subtitle']),
                    'empty_state_hint' => array('label' => __('نص الحالة الفارغة', 'yassin-ai-assistant'), 'type' => 'text', 'maxlength' => Settings::WIDGET_TEXT_LIMITS['empty_state_hint'], 'description' => __('يظهر كإرشاد واجهة قبل بدء المحادثة، وليس كرسالة من المساعد.', 'yassin-ai-assistant')),
                ),
            ),
            'ysai_widget_appearance' => array(
                'title' => __('مظهر الواجهة وعرض المنتجات', 'yassin-ai-assistant'),
                'description' => __('يتحكم نظام موحد لرموز التصميم في الواجهة الحية والمعاينة. تطبق إعدادات تخطيط المنتجات على كل مجموعة منتجات موثقة يعرضها المساعد.', 'yassin-ai-assistant'),
                'fields' => array(
                    'widget_preview' => array('label' => __('معاينة مباشرة', 'yassin-ai-assistant'), 'type' => 'preview'),
                    'widget_brand_color' => array('label' => __('لون العلامة التجارية', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_header_background_color' => array('label' => __('خلفية رأس المحادثة', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_header_foreground_color' => array('label' => __('نص وأيقونات رأس المحادثة', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_chat_background' => array('label' => __('خلفية المحادثة', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_surface_color' => array('label' => __('سطح اللوحة والبطاقات', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_assistant_bubble_color' => array('label' => __('فقاعة المساعد', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_user_bubble_color' => array('label' => __('فقاعة العميل', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_user_text_color' => array('label' => __('نص فقاعة العميل', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_text_color' => array('label' => __('النص الأساسي', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_muted_color' => array('label' => __('النص الثانوي', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_border_color' => array('label' => __('الحدود', 'yassin-ai-assistant'), 'type' => 'color', 'class' => ''),
                    'widget_panel_width' => array('label' => __('عرض سطح المكتب (بكسل)', 'yassin-ai-assistant'), 'type' => 'range', 'min' => 340, 'max' => 560, 'step' => 10),
                    'widget_panel_height' => array('label' => __('ارتفاع سطح المكتب (بكسل)', 'yassin-ai-assistant'), 'type' => 'range', 'min' => 520, 'max' => 860, 'step' => 10),
                    'widget_panel_radius' => array('label' => __('استدارة زوايا اللوحة (بكسل)', 'yassin-ai-assistant'), 'type' => 'range', 'min' => 12, 'max' => 36, 'step' => 1),
                    'widget_bubble_radius' => array('label' => __('استدارة زوايا الفقاعة (بكسل)', 'yassin-ai-assistant'), 'type' => 'range', 'min' => 10, 'max' => 28, 'step' => 1),
                    'widget_product_card_radius' => array('label' => __('استدارة زوايا بطاقة المنتج (بكسل)', 'yassin-ai-assistant'), 'type' => 'range', 'min' => 8, 'max' => 32, 'step' => 1),
                    'widget_font_size' => array('label' => __('حجم خط المحادثة (بكسل)', 'yassin-ai-assistant'), 'type' => 'range', 'min' => 13, 'max' => 18, 'step' => 1),
                    'widget_product_layout' => array('label' => __('تخطيط المنتجات', 'yassin-ai-assistant'), 'type' => 'select', 'options' => array(
                        'carousel' => __('شريط أفقي', 'yassin-ai-assistant'),
                        'grid' => __('شبكة', 'yassin-ai-assistant'),
                        'list' => __('قائمة مدمجة', 'yassin-ai-assistant'),
                    )),
                    'widget_product_cards_per_view' => array('label' => __('عدد البطاقات في العرض', 'yassin-ai-assistant'), 'type' => 'select', 'options' => array(
                        '1' => __('واحدة', 'yassin-ai-assistant'),
                        '2' => __('اثنتان', 'yassin-ai-assistant'),
                        '3' => __('ثلاث', 'yassin-ai-assistant'),
                    )),
                    'widget_product_image_ratio' => array('label' => __('نسبة صورة المنتج', 'yassin-ai-assistant'), 'type' => 'select', 'options' => array(
                        '1-1' => __('مربع 1:1', 'yassin-ai-assistant'),
                        '4-3' => __('أفقي 4:3', 'yassin-ai-assistant'),
                        '3-4' => __('عمودي 3:4', 'yassin-ai-assistant'),
                        '16-9' => __('عريض 16:9', 'yassin-ai-assistant'),
                    )),
                    'widget_product_show_description' => array('label' => __('أوصاف المنتجات', 'yassin-ai-assistant'), 'type' => 'checkbox', 'checkbox_label' => __('عرض الأوصاف القصيرة عند توفرها', 'yassin-ai-assistant')),
                ),
            ),
            'ysai_limits' => array(
                'title' => __('الحدود والاحتفاظ والتشخيص', 'yassin-ai-assistant'),
                'fields' => array(
                    'rate_limit_turns' => array('label' => __('عدد الطلبات في نافذة الجلسة', 'yassin-ai-assistant'), 'type' => 'number', 'min' => 5, 'max' => 500, 'step' => 1, 'class' => 'small-text'),
                    'trusted_proxy_cidrs' => array(
                        'label' => __('شبكات الوكيل العكسي الموثوقة', 'yassin-ai-assistant'),
                        'type' => 'textarea',
                        'rows' => 5,
                        'description' => __('عنوان IP أو نطاق CIDR واحد في كل سطر. اتركه فارغاً ما لم يكن WordPress خلف وكيل عكسي يعيد كتابة X-Forwarded-For. لا تُوثق ترويسات التحويل إلا عندما يطابق النظير المباشر هذه القائمة.', 'yassin-ai-assistant'),
                    ),
                    'rate_window_seconds' => array('label' => __('نافذة الحد (بالثواني)', 'yassin-ai-assistant'), 'type' => 'number', 'min' => 60, 'max' => 86400, 'step' => 1, 'class' => 'small-text'),
                    'daily_ai_turn_limit' => array('label' => __('الطلبات اليومية للذكاء الاصطناعي على مستوى الموقع', 'yassin-ai-assistant'), 'type' => 'number', 'min' => 10, 'max' => 100000, 'step' => 1, 'class' => 'small-text'),
                    'conversation_retention_days' => array('label' => __('مدة الاحتفاظ بالمحادثات (بالأيام)', 'yassin-ai-assistant'), 'type' => 'number', 'min' => 1, 'max' => 3650, 'step' => 1, 'class' => 'small-text'),
                    'diagnostic_logging' => array(
                        'label' => __('تفاصيل السجل التشخيصي', 'yassin-ai-assistant'),
                        'type' => 'checkbox',
                        'checkbox_label' => __('تسجيل سياق تشخيصي منقّح وأحداث التصحيح', 'yassin-ai-assistant'),
                        'description' => __('تُسجّل أسماء أخطاء التشغيل الأساسية دائماً دون سياق متغير. يضيف هذا الخيار سياقاً منقّحاً ومحدوداً وأحداث التصحيح، ولا يسجل نصوص المحادثة أو أجسام ردود المزود.', 'yassin-ai-assistant'),
                    ),
                    'delete_data_on_uninstall' => array('label' => __('حذف البيانات عند إزالة الإضافة', 'yassin-ai-assistant'), 'type' => 'checkbox', 'description' => __('عند التفعيل، تؤدي إزالة الإضافة إلى حذف جداولها وخياراتها نهائياً.', 'yassin-ai-assistant')),
                ),
            ),
            'ysai_links' => array(
                'title' => __('روابط المتجر الرسمية', 'yassin-ai-assistant'),
                'description' => __('تعيد أدوات السياسات هذه الروابط المضبوطة أو المحتوى المحلي المنشور فقط.', 'yassin-ai-assistant'),
                'fields' => array(
                    'contact_url' => array('label' => __('رابط التواصل', 'yassin-ai-assistant'), 'type' => 'url'),
                    'about_url' => array('label' => __('رابط من نحن', 'yassin-ai-assistant'), 'type' => 'url'),
                    'shipping_url' => array('label' => __('رابط الشحن', 'yassin-ai-assistant'), 'type' => 'url'),
                    'returns_url' => array('label' => __('رابط الإرجاع', 'yassin-ai-assistant'), 'type' => 'url'),
                    'terms_url' => array('label' => __('رابط الشروط', 'yassin-ai-assistant'), 'type' => 'url'),
                    'account_url' => array('label' => __('رابط الحساب', 'yassin-ai-assistant'), 'type' => 'url'),
                ),
            ),
        );
    }
}
