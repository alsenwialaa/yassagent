<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface CartQueryPort
{
    /** @return array<string,mixed> */
    public function snapshot(bool $includeAuthority = false): array;
    /** @return array<string,mixed> */
    public function displaySummary(): array;
}
