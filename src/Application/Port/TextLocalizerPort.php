<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

/** Arabic-only customer-safe text boundary. */
interface TextLocalizerPort
{
    public function text(string $arabic): string;
}
