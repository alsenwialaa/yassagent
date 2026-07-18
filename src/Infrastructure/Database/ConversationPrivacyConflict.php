<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use RuntimeException;

/** Export/deletion cannot race an admitted turn or nonterminal cart operation. */
final class ConversationPrivacyConflict extends RuntimeException
{
}
