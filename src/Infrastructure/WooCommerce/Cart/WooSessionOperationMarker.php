<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartSessionMarker;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttempt;
use YassinStore\AiAssistant\Domain\Commerce\OperationRecord;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooSession;
use YassinStore\AiAssistant\Infrastructure\Security\RecoveryKey;
use YassinStore\AiAssistant\Support\Json;

/** Creates and validates server-only HMAC markers bound to one Woo cart authority. */
final class WooSessionOperationMarker
{
    public const SESSION_KEY = WooSession::CART_OPERATION_MARKER_KEY;

    /** @var WooSession */ private $session;
    /** @var RecoveryKey */ private $recoveryKey;

    public function __construct(WooSession $session, RecoveryKey $recoveryKey)
    {
        $this->session = $session;
        $this->recoveryKey = $recoveryKey;
    }

    public function commerceResource(): string
    {
        return 'commerce|' . $this->sessionBinding();
    }

    public function sessionBinding(): string
    {
        return hash_hmac('sha256', $this->session->cartOperationNonce(), $this->key());
    }

    public function intent(
        OperationRecord $operation,
        CartOperationStep $step,
        CartStepAttempt $attempt,
        TurnLease $conversationLease,
        TurnLease $commerceLease
    ): CartSessionMarker {
        $payload = array(
            'v' => CartSessionMarker::VERSION,
            'session_binding' => $this->sessionBinding(),
            'operation_id' => $operation->publicId(),
            'step_id' => $step->publicId(),
            'attempt_id' => $attempt->publicId(),
            'step_index' => $step->stepIndex(),
            'command_hash' => $step->commandHash(),
            'conversation_fence' => $conversationLease->fence(),
            'commerce_resource_hash' => $commerceLease->resourceHash(),
            'commerce_fence' => $commerceLease->fence(),
            'pre_revision' => $step->preState()->revision(),
            'pre_restoration_revision' => $step->preState()->restorationRevision(),
            'phase' => CartSessionMarker::INTENT,
            'effect_hash' => '',
            'post_revision' => '',
            'post_restoration_revision' => '',
            'cart_payload_hash' => '',
            'issued_at' => time(),
        );
        return $this->signed($payload);
    }

    /** @param array<string,mixed> $effect */
    public function seal(
        CartSessionMarker $intent,
        array $effect,
        CartSnapshot $postState,
        string $cartPayloadHash
    ): CartSessionMarker {
        if (
            $intent->phase() !== CartSessionMarker::INTENT
            || preg_match('/^[a-f0-9]{64}$/', $cartPayloadHash) !== 1
        ) {
            throw new RuntimeException('Cart marker cannot be sealed from this intent.');
        }
        $payload = $intent->payload();
        $payload['phase'] = CartSessionMarker::SEALED;
        $payload['effect_hash'] = hash('sha256', Json::canonical($effect));
        $payload['post_revision'] = $postState->revision();
        $payload['post_restoration_revision'] = $postState->restorationRevision();
        $payload['cart_payload_hash'] = $cartPayloadHash;
        return $this->signed($payload);
    }

    public function write(CartSessionMarker $marker): void
    {
        $this->assertAuthentic($marker);
        $this->session->setValue(self::SESSION_KEY, $marker->toArray());
    }

    public function clear(): void
    {
        // Empty scalar is explicit absence and cannot be interpreted as marker authority.
        $this->session->setValue(self::SESSION_KEY, '');
    }

    /** @param array<string,mixed> $row */
    public function parseAndVerify(array $row): CartSessionMarker
    {
        $marker = CartSessionMarker::fromArray($row);
        $this->assertAuthentic($marker);
        return $marker;
    }

    public function assertMatches(
        CartSessionMarker $marker,
        OperationRecord $operation,
        CartOperationStep $step,
        CartStepAttempt $attempt
    ): void {
        $this->assertAuthentic($marker);
        if (
            !hash_equals($marker->operationId(), $operation->publicId())
            || !hash_equals($marker->stepId(), $step->publicId())
            || !hash_equals($marker->attemptId(), $attempt->publicId())
            || $marker->stepIndex() !== $step->stepIndex()
            || !hash_equals($marker->commandHash(), $step->commandHash())
            || $marker->conversationFence() !== $attempt->conversationFence()
            || !hash_equals($marker->commerceResourceHash(), $attempt->commerceResourceHash())
            || $marker->commerceFence() !== $attempt->commerceFence()
            || !hash_equals($marker->preRevision(), $step->preState()->revision())
            || !hash_equals(
                $marker->preRestorationRevision(),
                $step->preState()->restorationRevision()
            )
        ) {
            throw new RuntimeException('Cart marker does not match its durable step attempt.');
        }
    }

    public function assertAuthentic(CartSessionMarker $marker): void
    {
        $expected = $this->signature($marker->payload());
        if (
            !hash_equals($expected, $marker->signature())
            || !hash_equals($marker->sessionBinding(), $this->sessionBinding())
        ) {
            throw new RuntimeException('Cart session marker signature or session binding is invalid.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function signed(array $payload): CartSessionMarker
    {
        return new CartSessionMarker($payload, $this->signature($payload));
    }

    /** @param array<string,mixed> $payload */
    private function signature(array $payload): string
    {
        return hash_hmac('sha256', Json::canonical($payload), $this->key());
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'ysai-cart-step-marker-v1', $this->recoveryKey->key(), true);
    }
}
