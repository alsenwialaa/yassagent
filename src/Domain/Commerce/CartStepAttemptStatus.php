<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

final class CartStepAttemptStatus
{
    public const STARTED = 'started';
    public const INTENT_STAGED = 'intent_staged';
    public const SEALED = 'sealed';
    public const SESSION_PERSISTED = 'session_persisted';
    public const VERIFIED = 'verified';
    public const ABANDONED = 'abandoned';
    public const UNCERTAIN = 'uncertain';

    /** @return array<int,string> */
    public static function all(): array
    {
        return array(
            self::STARTED,
            self::INTENT_STAGED,
            self::SEALED,
            self::SESSION_PERSISTED,
            self::VERIFIED,
            self::ABANDONED,
            self::UNCERTAIN,
        );
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, array(
            self::VERIFIED,
            self::ABANDONED,
            self::UNCERTAIN,
        ), true);
    }
}
