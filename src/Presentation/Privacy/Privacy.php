<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Privacy;

use YassinStore\AiAssistant\Infrastructure\WordPress\Capabilities;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

final class Privacy
{
    /** @var Settings */
    private $settings;

    /** @var Capabilities */
    private $capabilities;

    public function __construct(Settings $settings, Capabilities $capabilities)
    {
        $this->settings = $settings;
        $this->capabilities = $capabilities;
    }

    public function register(): void
    {
        add_action('admin_init', array($this, 'addPolicyText'));
    }

    public function addPolicyText(): void
    {
        if (!$this->capabilities->currentUserCanManage()) {
            return;
        }
        $days = max(1, (int) $this->settings->get('conversation_retention_days', 45));
        $content = '<p>'
            . esc_html__(
                'عندما يستخدم الزائر مساعد المتجر الذكي، يخزن الموقع سجل المحادثة على الخادم ومعرّفات المحادثة التقنية وردود الذكاء الاصطناعي وإيصالات إجراءات السلة الموثقة. يفضّل المتصفح استخدام localStorage لحفظ معرّف قبول عشوائي ورمز استمرارية عشوائي بحجم 256 بت وبيانات المحادثة النشطة؛ يستطيع حامل الرمز استئناف جلسة المساعد والمحادثة. يُرسل الرمز إلى نقطة بدء المساعد من المصدر نفسه، ولا يسجله الخادم أو يخزنه بقيمته الخام، بل يشتق منه سلطة مرتبطة بالموقع باستخدام HMAC سرياً. يؤدي بدء محادثة جديدة إلى تدوير الرمز ومعرّف القبول. ولمنع تكرار طلب غير مكتمل بعد تحديث الصفحة، قد تخزن علامة التبويب مؤقتاً في sessionStorage هوية الطلب ومظروفه المطابق، بما فيه رمز المحادثة ونص العميل وسياق الرد المنفصل وبايتات الصور المرفقة المحدودة، حتى ظهور نتيجة نهائية أو انتهاء المهلة أو نهاية جلسة علامة التبويب. إذا تعذر التخزين أو رفض القراءة أو الكتابة أو الحذف، تستخدم الصفحة الحالية الذاكرة فقط؛ تظل المحادثة وإعادة المحاولة المطابقة داخل الصفحة متاحتين، لكن الاستمرارية بعد إعادة التحميل أو بين علامات التبويب تصبح محدودة. هذا المظروف مادة حساسة قابلة لإعادة الطلب نفسه، ولا يقبله الخادم إلا مع جلسة المساعد والمحادثة الصحيحتين وجميع فحوصات السلطة والتحقق؛ ولا يحتوي خطة سلة مباشرة أو معرّفات منتجات أو أسطر سلة موثوقة أو كميات تنفيذ مستقلة، ولا يمنح تخزين المتصفح سلطة تنفيذ السلة.',
                'yassin-ai-assistant'
            )
            . '</p><p>'
            . sprintf(
                esc_html__('يتم الاحتفاظ بسجلات المحادثات لمدة تصل إلى %d يوماً. تُخزن رسائل المحادثة المقبولة بنصها المطابق، بما في ذلك أي عناوين بريد إلكتروني أو قيم مشابهة لأرقام الهاتف يكتبها الزائر. يقتصر حجب هذه القيم على سياق سجلات التشخيص ولا يغيّر نص المحادثة المخزن. تُرسل رسائل العميل والصور الاختيارية إلى Gemini API المضبوط لإنشاء الرد، ولا تخزن هذه الإضافة بايتات الصور.', 'yassin-ai-assistant'),
                $days
            )
            . '</p><p>'
            . esc_html__(
                'يستطيع حامل جلسة المساعد الحالية ومعرّف المحادثة ورمزها السري تصدير بيانات تلك المحادثة أو حذفها عبر واجهة المحادثة الموثقة. ترفض الإضافة التصدير أو الحذف أثناء وجود طلب أو عملية سلة نشطة حتى لا تتلف نتيجة قيد التنفيذ.',
                'yassin-ai-assistant'
            )
            . '</p>';

        wp_add_privacy_policy_content(
            __('وكيل المبيعات الذكي لمتجر ياسين', 'yassin-ai-assistant'),
            wp_kses_post(wpautop($content, false))
        );
    }
}
