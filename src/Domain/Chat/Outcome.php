<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Chat;

final class Outcome
{
    public const ANSWER = 'answer';
    public const FOLLOW_UP = 'follow_up';
    public const ACTION_VERIFIED = 'action_verified';
    public const SAFE_FAILURE = 'safe_failure';

    /**
     * @return array<int,string>
     */
    public static function all(): array
    {
        return array(
            self::ANSWER,
            self::FOLLOW_UP,
            self::ACTION_VERIFIED,
            self::SAFE_FAILURE,
        );
    }
}
