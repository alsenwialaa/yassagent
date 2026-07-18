<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Chat;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

/**
 * Opaque evidence produced only after one exact current-turn model call has
 * been verified by the application boundary.
 */
abstract class VerifiedModelQuestionEvidence
{
    /** @var string */ private $question;
    /** @var string */ private $modelStepId;
    /** @var string */ private $toolName;
    /** @var string */ private $toolCallId;
    /** @var string */ private $providerCallId;
    /** @var string */ private $clientTurnId;
    /** @var string */ private $conversationId;
    /** @var string */ private $purpose;
    /** @var int */ private $modelRound;
    /** @var string */ private $validatedArgumentsDigest;
    /** @var string */ private $currentTurnDigest;

    final protected function __construct(
        string $question,
        string $modelStepId,
        string $toolName,
        string $toolCallId,
        string $providerCallId,
        string $clientTurnId,
        string $conversationId,
        string $purpose,
        int $modelRound,
        string $validatedArgumentsDigest,
        string $currentTurnDigest
    ) {
        if (
            $question === '' || Utf8::hasOuterWhitespace($question)
            || !Utf8::isPlainText($question) || !Utf8::isBounded($question, 320, 1280)
        ) {
            throw new InvalidArgumentException('Verified model-question text is invalid.');
        }
        if (
            $modelStepId === '' || trim($modelStepId) !== $modelStepId
            || strlen($modelStepId) > 128
            || $toolName !== 'respond_follow_up'
            || $toolCallId === '' || trim($toolCallId) !== $toolCallId
            || strlen($toolCallId) > 128
            || $providerCallId === '' || trim($providerCallId) !== $providerCallId
            || strlen($providerCallId) > 256
            || !Uuid::isV4(strtolower($clientTurnId))
            || !Uuid::isV4(strtolower($conversationId))
            || !in_array($purpose, ModelAuthoredQuestion::purposes(), true)
            || $modelRound < 1 || $modelRound > 64
            || preg_match('/^[a-f0-9]{64}$/', $validatedArgumentsDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $currentTurnDigest) !== 1
        ) {
            throw new InvalidArgumentException('Verified model-question provenance is invalid.');
        }

        $this->question = $question;
        $this->modelStepId = $modelStepId;
        $this->toolName = $toolName;
        $this->toolCallId = $toolCallId;
        $this->providerCallId = $providerCallId;
        $this->clientTurnId = strtolower($clientTurnId);
        $this->conversationId = strtolower($conversationId);
        $this->purpose = $purpose;
        $this->modelRound = $modelRound;
        $this->validatedArgumentsDigest = $validatedArgumentsDigest;
        $this->currentTurnDigest = $currentTurnDigest;
    }

    final public function question(): string
    {
        return $this->question;
    }
    final public function modelStepId(): string
    {
        return $this->modelStepId;
    }
    final public function toolName(): string
    {
        return $this->toolName;
    }
    final public function toolCallId(): string
    {
        return $this->toolCallId;
    }
    final public function providerCallId(): string
    {
        return $this->providerCallId;
    }
    final public function clientTurnId(): string
    {
        return $this->clientTurnId;
    }
    final public function conversationId(): string
    {
        return $this->conversationId;
    }
    final public function purpose(): string
    {
        return $this->purpose;
    }
    final public function modelRound(): int
    {
        return $this->modelRound;
    }
    final public function validatedArgumentsDigest(): string
    {
        return $this->validatedArgumentsDigest;
    }
    final public function currentTurnDigest(): string
    {
        return $this->currentTurnDigest;
    }
}
