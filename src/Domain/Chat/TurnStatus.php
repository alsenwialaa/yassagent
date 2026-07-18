<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Chat;

final class TurnStatus
{
    public const RECEIVED = 'received';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const SAFE_FAILED = 'safe_failed';
    public const UNCERTAIN = 'uncertain';

    /** @return array<int,string> */
    public static function all(): array
    {
        return array(self::RECEIVED, self::RUNNING, self::COMPLETED, self::SAFE_FAILED, self::UNCERTAIN);
    }

    /** @return array<int,string> */
    public static function terminal(): array
    {
        return array(self::COMPLETED, self::SAFE_FAILED, self::UNCERTAIN);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::terminal(), true);
    }
}
