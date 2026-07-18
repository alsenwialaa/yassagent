<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Admin;

use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;
use YassinStore\AiAssistant\Infrastructure\WordPress\Capabilities;

/** Minimal recovery surface that remains available while runtime storage is blocked. */
final class SchemaAdmin
{
    private const ACTION = 'ysai_repair_schema';

    /** @var Capabilities */
    private $capabilities;

    public function __construct(Capabilities $capabilities)
    {
        $this->capabilities = $capabilities;
    }

    public function register(): void
    {
        add_action('admin_notices', array($this, 'notice'));
        add_action('admin_post_' . self::ACTION, array($this, 'repair'));
        add_action('load-plugins.php', array($this, 'verifyRuntime'));
    }

    public function verifyRuntime(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }
        if (class_exists('WooCommerce')) {
            SchemaLifecycle::verifyRuntime();
        }
    }

    public function repair(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : '';
        if ($method !== 'POST') {
            wp_die(esc_html__('يتطلب إصلاح قاعدة البيانات طلب POST.', 'yassin-ai-assistant'));
        }
        if (!$this->capabilities->currentUserCanManage()) {
            wp_die(esc_html__('ليست لديك صلاحية إصلاح قاعدة بيانات المساعد.', 'yassin-ai-assistant'));
        }
        check_admin_referer(self::ACTION);

        $success = SchemaLifecycle::repair();
        $target = add_query_arg(
            'ysai_schema_repair',
            $success ? 'success' : 'failed',
            admin_url('plugins.php')
        );
        wp_safe_redirect($target);
        exit;
    }

    public function notice(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }

        $result = '';
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only status follows the nonce-protected repair redirect.
        if (isset($_GET['ysai_schema_repair']) && is_string($_GET['ysai_schema_repair'])) {
            $result = sanitize_key(wp_unslash($_GET['ysai_schema_repair']));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ($result === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('تمت إعادة بناء مخطط قاعدة بيانات المساعد والتحقق منه.', 'yassin-ai-assistant')
                . '</p></div>';
            return;
        }

        $status = SchemaLifecycle::status();
        $state = isset($status['state']) && is_string($status['state'])
            ? $status['state']
            : '';
        $reason = isset($status['reason']) && is_string($status['reason'])
            ? $status['reason']
            : 'database_schema_error';
        $issues = isset($status['issues']) && is_array($status['issues'])
            ? array_values(array_filter($status['issues'], 'is_string'))
            : array();

        if ($state === 'unverifiable') {
            echo '<div class="notice notice-warning"><p><strong>'
                . esc_html__('تعذر التحقق من قاعدة بيانات المساعد في هذا الطلب.', 'yassin-ai-assistant')
                . '</strong></p><p>'
                . esc_html__('تم تعطيل مساعد المتجر مؤقتاً. لم تبدأ إعادة بناء المخطط، وتم الاحتفاظ بإثبات جاهزية Gemini التشغيلي الموثق. أعد التحميل بعد تعافي خدمة بيانات قاعدة البيانات الوصفية.', 'yassin-ai-assistant')
                . '</p><p><code>' . esc_html($reason) . '</code>';
            if ($issues !== array()) {
                echo '<br><code>' . esc_html(implode(', ', array_slice($issues, 0, 12))) . '</code>';
            }
            echo '</p></div>';
            return;
        }

        if ($state !== 'blocked') {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('قاعدة بيانات وكيل المبيعات الذكي لمتجر ياسين محظورة.', 'yassin-ai-assistant')
            . '</strong></p><p>'
            . esc_html__('تم تعطيل مساعد المتجر. يعيد الإصلاح بناء جميع جداول المساعد المملوكة للإضافة، ولا يغير سلال WooCommerce أو إعدادات الإضافة.', 'yassin-ai-assistant')
            . '</p><p><code>' . esc_html($reason) . '</code>';
        if ($issues !== array()) {
            echo '<br><code>' . esc_html(implode(', ', array_slice($issues, 0, 12))) . '</code>';
        }
        echo '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        wp_nonce_field(self::ACTION);
        submit_button(
            esc_html__('إعادة بناء قاعدة بيانات المساعد والتحقق منها', 'yassin-ai-assistant'),
            'primary',
            'submit',
            false
        );
        echo '</form></div>';
    }
}
