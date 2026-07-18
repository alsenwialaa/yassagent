<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface FingerprintPort
{
    public function digest(string $purpose, string $value): string;
}
