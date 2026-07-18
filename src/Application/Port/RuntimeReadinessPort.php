<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface RuntimeReadinessPort
{
    public function isReady(): bool;
    public function invalidate(string $code): void;
    /** @return array<string,mixed> */
    public function status(): array;
}
