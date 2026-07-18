<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

/** Append-only execution-attempt identity; evidence is sealed before session I/O. */
final class CartStepAttempt
{
    private const MAX_SAFE_MESSAGE_CODE_POINTS = 4096;
    private const MAX_SAFE_MESSAGE_BYTES = 16384;

    /** @var int */ private $id;
    /** @var string */ private $publicId;
    /** @var int */ private $stepId;
    /** @var int */ private $attemptNumber;
    /** @var int */ private $conversationFence;
    /** @var string */ private $commerceResourceHash;
    /** @var int */ private $commerceFence;
    /** @var string */ private $status;
    /** @var string */ private $markerDigest;
    /** @var array<string,mixed>|null */ private $marker;
    /** @var array<string,mixed>|null */ private $candidateEffect;
    /** @var CartSnapshot|null */ private $candidatePostState;
    /** @var string */ private $failureCode;
    /** @var string */ private $safeMessage;

    /** @param array<string,mixed>|null $marker @param array<string,mixed>|null $candidateEffect */
    public function __construct(
        int $id,
        string $publicId,
        int $stepId,
        int $attemptNumber,
        int $conversationFence,
        string $commerceResourceHash,
        int $commerceFence,
        string $status,
        string $markerDigest,
        ?array $marker,
        ?array $candidateEffect,
        ?CartSnapshot $candidatePostState,
        string $failureCode,
        string $safeMessage
    ) {
        $publicId = strtolower(trim($publicId));
        $commerceResourceHash = strtolower(trim($commerceResourceHash));
        $markerDigest = strtolower(trim($markerDigest));
        $failureCode = trim($failureCode);
        $safeMessage = trim($safeMessage);
        if (
            $id < 1 || $stepId < 1 || !Uuid::isV4($publicId)
            || $attemptNumber < 1 || $attemptNumber > 65535
            || $conversationFence < 1
            || preg_match('/^[a-f0-9]{64}$/', $commerceResourceHash) !== 1
            || $commerceFence < 1
            || !in_array($status, CartStepAttemptStatus::all(), true)
            || ($markerDigest !== '' && preg_match('/^[a-f0-9]{64}$/', $markerDigest) !== 1)
            || ($marker !== null && ($marker === array() || Arr::isList($marker)))
            || ($candidateEffect !== null && ($candidateEffect === array() || Arr::isList($candidateEffect)))
            || ($failureCode !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $failureCode) !== 1)
            || !Utf8::isBounded(
                $safeMessage,
                self::MAX_SAFE_MESSAGE_CODE_POINTS,
                self::MAX_SAFE_MESSAGE_BYTES
            )
        ) {
            throw new InvalidArgumentException('Durable cart step attempt is invalid.');
        }

        if ($status === CartStepAttemptStatus::STARTED) {
            $this->assert(
                $marker === null && $markerDigest === '' && $candidateEffect === null
                && $candidatePostState === null && $failureCode === '' && $safeMessage === '',
                'Started cart attempt contains unsupported evidence.'
            );
        } elseif ($status === CartStepAttemptStatus::INTENT_STAGED) {
            $this->assert(
                $marker !== null && $markerDigest !== '' && $candidateEffect === null
                && $candidatePostState === null && $failureCode === '' && $safeMessage === '',
                'Intent-staged cart attempt evidence is incomplete.'
            );
        } elseif (
            in_array($status, array(
            CartStepAttemptStatus::SEALED,
            CartStepAttemptStatus::SESSION_PERSISTED,
            CartStepAttemptStatus::VERIFIED,
            ), true)
        ) {
            $this->assert(
                $marker !== null && $markerDigest !== '' && $candidateEffect !== null
                && $candidatePostState !== null && $failureCode === '' && $safeMessage === '',
                'Sealed cart attempt proof is incomplete.'
            );
        } else {
            $this->assert(
                $failureCode !== '' && $safeMessage !== '',
                'Failed cart attempt evidence is incomplete.'
            );
        }

        $this->id = $id;
        $this->publicId = $publicId;
        $this->stepId = $stepId;
        $this->attemptNumber = $attemptNumber;
        $this->conversationFence = $conversationFence;
        $this->commerceResourceHash = $commerceResourceHash;
        $this->commerceFence = $commerceFence;
        $this->status = $status;
        $this->markerDigest = $markerDigest;
        $this->marker = $marker;
        $this->candidateEffect = $candidateEffect;
        $this->candidatePostState = $candidatePostState;
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
    public function stepId(): int
    {
        return $this->stepId;
    }
    public function attemptNumber(): int
    {
        return $this->attemptNumber;
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
    public function markerDigest(): string
    {
        return $this->markerDigest;
    }
    /** @return array<string,mixed>|null */ public function marker(): ?array
    {
        return $this->marker;
    }
    /** @return array<string,mixed>|null */ public function candidateEffect(): ?array
    {
        return $this->candidateEffect;
    }
    public function candidatePostState(): ?CartSnapshot
    {
        return $this->candidatePostState;
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
        return CartStepAttemptStatus::isTerminal($this->status);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
