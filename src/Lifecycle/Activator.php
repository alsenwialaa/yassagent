<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Lifecycle;

use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;
use YassinStore\AiAssistant\Infrastructure\Gemini\GeminiRuntimeReadiness;
use Throwable;
use YassinStore\AiAssistant\Infrastructure\Security\RecoveryKey;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCommerceCompatibility;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSessionInternalsAdapter;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

final class Activator
{
    /**
     * WordPress supplies the network-wide flag to activation hooks. Version 1.0.0
     * intentionally supports only ordinary single-site installations.
     */
    public static function activate(bool $networkWide = false): void
    {
        unset($networkWide);
        self::rejectMultisite();
        self::requireWooCommerce();
        self::activateSite();
    }

    private static function rejectMultisite(): void
    {
        if (!is_multisite()) {
            return;
        }

        wp_die(
            esc_html__('وكيل المبيعات الذكي لمتجر ياسين لا يدعم ووردبريس متعدد المواقع في الإصدار 1.0.0. استخدمه في تثبيت ووردبريس أحادي الموقع.', 'yassin-ai-assistant'),
            esc_html__('ووردبريس متعدد المواقع غير مدعوم', 'yassin-ai-assistant'),
            array('back_link' => true)
        );
    }

    private static function requireWooCommerce(): void
    {
        if (!class_exists('WooCommerce')) {
            wp_die(
                esc_html__('يتطلب وكيل المبيعات الذكي لمتجر ياسين تثبيت WooCommerce وتفعيله قبل تفعيل الإضافة.', 'yassin-ai-assistant'),
                esc_html__('WooCommerce مطلوب', 'yassin-ai-assistant'),
                array('back_link' => true)
            );
        }

        try {
            $compatibility = WooCommerceCompatibility::fromPluginContract();
        } catch (Throwable $exception) {
            wp_die(
                esc_html__('تعذر قراءة عقد توافق WooCommerce الخاص بالإضافة. أعد تثبيت الحزمة الأصلية ثم حاول التفعيل مجدداً.', 'yassin-ai-assistant'),
                esc_html__('عقد توافق WooCommerce غير صالح', 'yassin-ai-assistant'),
                array('back_link' => true)
            );
        }

        if (!$compatibility->admitsInstalledVersion()) {
            wp_die(
                esc_html(sprintf(
                    /* translators: %s: accepted WooCommerce version range. */
                    __('يتطلب وكيل المبيعات الذكي WooCommerce ضمن النطاق %s قبل التفعيل.', 'yassin-ai-assistant'),
                    $compatibility->rangeLabel()
                )),
                esc_html__('إصدار WooCommerce غير مدعوم', 'yassin-ai-assistant'),
                array('back_link' => true)
            );
        }

        try {
            (new WooSessionInternalsAdapter($compatibility))->assertStaticCoreCapabilities();
        } catch (Throwable $exception) {
            wp_die(
                esc_html__('بنية جلسة WooCommerce المثبتة لا تطابق عقد التنفيذ الآمن للسلة. لا يمكن تفعيل الإضافة قبل استعادة بنية WooCommerce الأساسية المدعومة.', 'yassin-ai-assistant'),
                esc_html__('قدرات WooCommerce غير مدعومة', 'yassin-ai-assistant'),
                array('back_link' => true)
            );
        }
    }

    private static function activateSite(): void
    {
        try {
            GeminiRuntimeReadiness::deleteState();
        } catch (\Throwable $exception) {
            wp_die(
                esc_html__('تعذر إبطال حالة جاهزية قديمة بأمان. راجع تخزين خيارات ووردبريس ثم فعّل الإضافة مجدداً.', 'yassin-ai-assistant'),
                esc_html__('تعذر إبطال جاهزية المساعد', 'yassin-ai-assistant'),
                array('back_link' => true)
            );
        }
        if (!SchemaLifecycle::install()) {
            wp_die(
                esc_html__('تعذر إنشاء قاعدة بيانات المساعد والتحقق منها. راجع صلاحيات قاعدة البيانات ثم فعّل الإضافة مجدداً.', 'yassin-ai-assistant'),
                esc_html__('فشل تثبيت قاعدة بيانات المساعد', 'yassin-ai-assistant'),
                array('back_link' => true)
            );
        }

        $stored = get_option(Settings::OPTION_KEY, null);
        if (!is_array($stored)) {
            add_option(Settings::OPTION_KEY, Settings::defaults(), '', false);
        }

        (new RecoveryKey())->ensure();
        Cleanup::schedule();
    }
}
