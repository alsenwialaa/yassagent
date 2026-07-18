<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

final class CartStepStatus
{
    public const PREPARED = 'prepared';
    public const APPLYING = 'applying';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';
    public const UNCERTAIN = 'uncertain';

    /** @return array<int,string> */
    public static function all(): array
    {
        return array(
            self::PREPARED,
            self::APPLYING,
            self::VERIFIED,
            self::REJECTED,
            self::UNCERTAIN,
        );
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, array(self::VERIFIED, self::REJECTED, self::UNCERTAIN), true);
    }
}
