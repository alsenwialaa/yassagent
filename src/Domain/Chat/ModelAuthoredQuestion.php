<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Chat;

/** Exact shopper-facing question backed by verified or strictly restored evidence. */
final class ModelAuthoredQuestion
{
    public const PURPOSE_ORDINARY = 'ordinary';
    public const PURPOSE_CART_AMBIGUITY = 'cart_ambiguity';
    public const PURPOSE_CART_CONTINUATION = 'cart_continuation';
    public const PURPOSE_CART_CONTINUATION_RETRY = 'cart_continuation_retry';

    /** @var StoredModelQuestionEvidence */ private $evidence;

    private function __construct(StoredModelQuestionEvidence $evidence)
    {
        $this->evidence = $evidence;
    }

    public static function acceptVerified(
        VerifiedModelQuestionEvidence $evidence,
        int $acceptedAt
    ): self {
        return new self(StoredModelQuestionEvidence::acceptVerified($evidence, $acceptedAt));
    }

    public static function restore(StoredModelQuestionEvidence $evidence): self
    {
        return new self($evidence);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->evidence->toArray();
    }
    public function text(): string
    {
        return $this->evidence->text();
    }
    public function modelStepId(): string
    {
        return $this->evidence->modelStepId();
    }
    public function toolName(): string
    {
        return $this->evidence->toolName();
    }
    public function toolCallId(): string
    {
        return $this->evidence->toolCallId();
    }
    public function providerCallId(): string
    {
        return $this->evidence->providerCallId();
    }
    public function clientTurnId(): string
    {
        return $this->evidence->clientTurnId();
    }
    public function conversationId(): string
    {
        return $this->evidence->conversationId();
    }
    public function purpose(): string
    {
        return $this->evidence->purpose();
    }
    public function modelRound(): int
    {
        return $this->evidence->modelRound();
    }
    public function validatedArgumentsDigest(): string
    {
        return $this->evidence->validatedArgumentsDigest();
    }
    public function currentTurnDigest(): string
    {
        return $this->evidence->currentTurnDigest();
    }
    public function acceptedAt(): int
    {
        return $this->evidence->acceptedAt();
    }
    public function evidenceDigest(): string
    {
        return $this->evidence->evidenceDigest();
    }

    /** @return array<int,string> */
    public static function purposes(): array
    {
        return array(
            self::PURPOSE_ORDINARY,
            self::PURPOSE_CART_AMBIGUITY,
            self::PURPOSE_CART_CONTINUATION,
            self::PURPOSE_CART_CONTINUATION_RETRY,
        );
    }
}
