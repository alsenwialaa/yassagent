<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

final class OperationStatus
{
    public const PREPARED = 'prepared';
    public const EXECUTING = 'executing';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';
    public const UNCERTAIN = 'uncertain';

    /** @return array<int,string> */
    public static function all(): array
    {
        return array(
            self::PREPARED,
            self::EXECUTING,
            self::VERIFIED,
            self::REJECTED,
            self::UNCERTAIN,
        );
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, array(
            self::VERIFIED,
            self::REJECTED,
            self::UNCERTAIN,
        ), true);
    }
}
