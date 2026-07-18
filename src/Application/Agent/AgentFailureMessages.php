<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;

/** Customer-safe, cause-specific terminal failure text. */
final class AgentFailureMessages
{
    /** @var TextLocalizerPort */ private $text;

    public function __construct(TextLocalizerPort $text)
    {
        $this->text = $text;
    }

    public function forCode(string $code): string
    {
        $code = trim($code);

        if (in_array($code, array('tool_call_limit', 'tool_round_limit', 'tool_feedback_budget_exceeded'), true)) {
            return $this->text->text('كان الطلب معقداً أكثر من حد التنفيذ الآمن. لم يتم تأكيد أي إجراء غير موثّق. جرّب طلباً أبسط.');
        }

        if ($code === 'model_output_limit') {
            return $this->text->text('استنفد مزود الذكاء الاصطناعي حد الإخراج قبل إنتاج نتيجة صالحة. لم يتم تأكيد أي إجراء. أعد المحاولة بطلب أبسط.');
        }

        if ($code === 'verified_receipt_missing') {
            return $this->text->text('تعذر إثبات نتيجة إجراء السلة، لذلك لم يتم تأكيد نجاحه. راجع السلة قبل المحاولة مجدداً.');
        }

        if ($code === 'terminal_tool_invalid' || $code === 'terminal_contract_invalid') {
            return $this->text->text('لم يُنتج المساعد نتيجة نهائية صالحة. لم يتم تأكيد أي تغيير. أعد صياغة الطلب.');
        }

        if (
            strpos($code, 'model_') === 0
            || strpos($code, 'function_') === 0
            || strpos($code, 'tool_') === 0
        ) {
            return $this->text->text('أعاد مزود الذكاء الاصطناعي استجابة لا تطابق بروتوكول الإضافة. لم يتم تأكيد أي إجراء. أعد المحاولة.');
        }

        if (
            strpos($code, 'authority_') !== false
            || strpos($code, 'product_') === 0
            || strpos($code, 'variation_') === 0
        ) {
            return $this->text->text('تعذر التحقق من مرجع الطلب الحالي. اطلب عرض المنتج من جديد ثم أعد المحاولة.');
        }

        return $this->text->text('تعذر تثبيت نتيجة موثوقة لهذا الطلب. لم يتم تأكيد أي إجراء غير موثّق. أعد المحاولة أو أعد صياغة الطلب.');
    }
}
