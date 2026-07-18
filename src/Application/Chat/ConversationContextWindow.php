<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Chat;

/** Normal terminal-turn window shared by model context and transcript projection. */
final class ConversationContextWindow
{
    private const TERMINAL_TURN_LIMIT = 12;

    public function terminalTurnLimit(): int
    {
        return self::TERMINAL_TURN_LIMIT;
    }
}
