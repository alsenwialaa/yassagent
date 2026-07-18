<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

/** Immutable logical primitive plus its durable terminal proof. */
final class CartOperationStep
{
    private const MAX_SAFE_MESSAGE_CODE_POINTS = 4096;
    private const MAX_SAFE_MESSAGE_BYTES = 16384;

    /** @var int */ private $id;
    /** @var string */ private $publicId;
    /** @var int */ private $operationId;
    /** @var int */ private $stepIndex;
    /** @var int */ private $commandIndex;
    /** @var string */ private $commandHash;
    /** @var int */ private $conversationFence;
    /** @var string */ private $commerceResourceHash;
    /** @var int */ private $commerceFence;
    /** @var string */ private $status;
    /** @var CartPrimitive */ private $primitive;
    /** @var CartSnapshot */ private $preState;
    /** @var array<string,mixed>|null */ private $effect;
    /** @var CartSnapshot|null */ private $postState;
    /** @var string */ private $markerDigest;
    /** @var string */ private $failureCode;
    /** @var string */ private $safeMessage;

    /** @param array<string,mixed>|null $effect */
    public function __construct(
        int $id,
        string $publicId,
        int $operationId,
        int $stepIndex,
        int $commandIndex,
        string $commandHash,
        int $conversationFence,
        string $commerceResourceHash,
        int $commerceFence,
        string $status,
        CartPrimitive $primitive,
        CartSnapshot $preState,
        ?array $effect,
        ?CartSnapshot $postState,
        string $markerDigest,
        string $failureCode,
        string $safeMessage
    ) {
        $publicId = strtolower(trim($publicId));
        $commandHash = strtolower(trim($commandHash));
        $commerceResourceHash = strtolower(trim($commerceResourceHash));
        $markerDigest = strtolower(trim($markerDigest));
        $failureCode = trim($failureCode);
        $safeMessage = trim($safeMessage);
        if (
            $id < 1 || $operationId < 1 || !Uuid::isV4($publicId)
            || $stepIndex < 0 || $stepIndex > 4095
            || $commandIndex !== $primitive->commandIndex()
            || preg_match('/^[a-f0-9]{64}$/', $commandHash) !== 1
            || $conversationFence < 1
            || preg_match('/^[a-f0-9]{64}$/', $commerceResourceHash) !== 1
            || $commerceFence < 1
            || !in_array($status, CartStepStatus::all(), true)
            || ($effect !== null && ($effect === array() || Arr::isList($effect)))
            || ($markerDigest !== '' && preg_match('/^[a-f0-9]{64}$/', $markerDigest) !== 1)
            || ($failureCode !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $failureCode) !== 1)
            || !Utf8::isBounded(
                $safeMessage,
                self::MAX_SAFE_MESSAGE_CODE_POINTS,
                self::MAX_SAFE_MESSAGE_BYTES
            )
        ) {
            throw new InvalidArgumentException('Durable cart step is invalid.');
        }

        if (in_array($status, array(CartStepStatus::PREPARED, CartStepStatus::APPLYING), true)) {
            $this->assert(
                $effect === null && $postState === null && $failureCode === '' && $safeMessage === '',
                'A nonterminal cart step contains terminal evidence.'
            );
        } elseif ($status === CartStepStatus::VERIFIED) {
            $this->assert($effect !== null && $postState !== null && $markerDigest !== ''
                && $failureCode === '' && $safeMessage === '', 'Verified cart step proof is incomplete.');
        } elseif ($status === CartStepStatus::REJECTED) {
            $this->assert(
                $effect === null && $failureCode !== '' && $safeMessage !== '',
                'Rejected cart step evidence is incomplete.'
            );
        } else {
            $this->assert(
                $failureCode !== '' && $safeMessage !== '',
                'Failed cart step evidence is incomplete.'
            );
        }

        $this->id = $id;
        $this->publicId = $publicId;
        $this->operationId = $operationId;
        $this->stepIndex = $stepIndex;
        $this->commandIndex = $commandIndex;
        $this->commandHash = $commandHash;
        $this->conversationFence = $conversationFence;
        $this->commerceResourceHash = $commerceResourceHash;
        $this->commerceFence = $commerceFence;
        $this->status = $status;
        $this->primitive = $primitive;
        $this->preState = $preState;
        $this->effect = $effect;
        $this->postState = $postState;
        $this->markerDigest = $markerDigest;
        $this->failureCode = $failureCode;
        $this->safeMessage = $safeMessage;
    }

    public function id(): int
    {
        return $this->id;
    }
    public function publicId(): string
    {
        return $this->publicId;
    }
    public function operationId(): int
    {
        return $this->operationId;
    }
    public function stepIndex(): int
    {
        return $this->stepIndex;
    }
    public function commandIndex(): int
    {
        return $this->commandIndex;
    }
    public function commandHash(): string
    {
        return $this->commandHash;
    }
    public function conversationFence(): int
    {
        return $this->conversationFence;
    }
    public function commerceResourceHash(): string
    {
        return $this->commerceResourceHash;
    }
    public function commerceFence(): int
    {
        return $this->commerceFence;
    }
    public function status(): string
    {
        return $this->status;
    }
    public function primitive(): CartPrimitive
    {
        return $this->primitive;
    }
    public function preState(): CartSnapshot
    {
        return $this->preState;
    }
    /** @return array<string,mixed>|null */ public function effect(): ?array
    {
        return $this->effect;
    }
    public function postState(): ?CartSnapshot
    {
        return $this->postState;
    }
    public function markerDigest(): string
    {
        return $this->markerDigest;
    }
    public function failureCode(): string
    {
        return $this->failureCode;
    }
    public function safeMessage(): string
    {
        return $this->safeMessage;
    }
    public function isTerminal(): bool
    {
        return CartStepStatus::isTerminal($this->status);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
