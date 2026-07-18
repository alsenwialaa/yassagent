<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use RuntimeException;

/** Destructive maintenance cannot overlap admitted turn or cart work. */
final class ConversationMaintenanceConflict extends RuntimeException
{
}
