<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface RateLimiterPort
{
    /** @return array<string,mixed> */
    public function consume(string $sessionHash, string $ip): array;
}
