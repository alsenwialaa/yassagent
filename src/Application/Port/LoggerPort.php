<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface LoggerPort
{
    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = array()): void;
}
