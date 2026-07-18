<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Domain\Exception\OperationPendingException;

final class CartOperationMessages
{
    public function reason(string $reason): string
    {
        $reason = trim($reason);
        return preg_match('/^[a-z0-9_]{1,64}$/', $reason) === 1 ? $reason : 'cart_operation_failed';
    }

    public function uncertain(): string
    {
        return ('تعذر إثبات الحالة النهائية للسلة. راجع صفحة السلة قبل إجراء أي تغيير آخر.');
    }

    public function pending(string $reason, string $internal): OperationPendingException
    {
        return new OperationPendingException(
            $this->reason($reason),
            ('يجري التحقق من طلب السلة السابق. أعد إرسال الطلب نفسه.'),
            $internal
        );
    }
}
