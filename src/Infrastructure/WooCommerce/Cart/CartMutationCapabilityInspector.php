<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use Throwable;
use YassinStore\AiAssistant\Application\Port\CartMutationCapabilityPort;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;
use YassinStore\AiAssistant\Domain\Commerce\CartMutationCapability;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;

final class CartMutationCapabilityInspector implements CartMutationCapabilityPort
{
    /** @var WooSession */ private $session;
    /** @var CartMutationCapabilityProof */ private $proof;
    /** @var TextLocalizerPort */ private $text;

    public function __construct(
        WooSession $session,
        CartMutationCapabilityProof $proof,
        TextLocalizerPort $text
    ) {
        $this->session = $session;
        $this->proof = $proof;
        $this->text = $text;
    }

    public function inspect(): CartMutationCapability
    {
        if (!$this->session->allowsVerifiedCartMutation()) {
            return new CartMutationCapability(
                false,
                CartMutationCapability::VERSION_NOT_PROMOTION_TESTED,
                $this->text->text('يمكن للمساعد متابعة التسوق وقراءة السلة، لكن تعديل السلة داخل الدردشة معطل لأن إصدار WooCommerce الحالي لم يجتز بعد بوابة النشر الفعلية.')
            );
        }
        $handler = $this->session->sessionHandlerClass();
        if ($handler === '') {
            return new CartMutationCapability(
                false,
                CartMutationCapability::RUNTIME_UNAVAILABLE,
                $this->text->text('يمكن للمساعد متابعة الدردشة، لكن حالة جلسة WooCommerce غير متاحة الآن، لذلك لا يمكن تعديل السلة بأمان.')
            );
        }
        if (!$this->session->hasCoreSessionHandler()) {
            return new CartMutationCapability(
                false,
                CartMutationCapability::SESSION_HANDLER_UNSUPPORTED,
                $this->text->text('يمكن للمساعد قراءة السلة، لكن تعديلها داخل الدردشة غير متاح لأن الموقع يستخدم مخزن جلسات WooCommerce مخصصاً.')
            );
        }
        try {
            $this->proof->assertAvailable();
            return new CartMutationCapability(true, CartMutationCapability::AVAILABLE, '');
        } catch (CartMutationCapabilityException $exception) {
            $code = $exception->capabilityCode();
            $notices = array(
                CartMutationCapability::REQUEST_FENCE_UNAVAILABLE => 'تعذر تأمين جلسة السلة الحالية ضد الطلبات المتزامنة، لذلك تم تعطيل التعديل داخل الدردشة.',
                CartMutationCapability::STORAGE_TOPOLOGY_UNSUPPORTED => 'تخزين WooCommerce الحالي لا يحقق متطلبات الكتابة الآمنة، لذلك تم تعطيل تعديل السلة داخل الدردشة.',
                CartMutationCapability::SESSION_RUNTIME_UNSUPPORTED => 'بنية جلسة WooCommerce الحالية غير مدعومة لتعديل السلة الآمن داخل الدردشة.',
                CartMutationCapability::SESSION_AUTHORITY_UNAVAILABLE => 'لم يتم تثبيت هوية جلسة السلة الحالية في التخزين بعد، لذلك أعد تحميل الصفحة قبل تعديلها داخل الدردشة.',
            );
            return new CartMutationCapability(
                false,
                $code,
                $this->text->text($notices[$code] ?? 'تعذر إثبات قدرة تعديل السلة بأمان في هذه الجلسة.')
            );
        } catch (Throwable $exception) {
            return new CartMutationCapability(
                false,
                CartMutationCapability::RUNTIME_UNAVAILABLE,
                $this->text->text('تعذر التحقق من قدرة تعديل السلة في هذه الجلسة.')
            );
        }
    }
}
