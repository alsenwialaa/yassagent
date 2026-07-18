<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface BrowserContinuityAuthorityPort
{
    public function activate(string $secretHash): string;

    public function rotate(
        string $previousSecretHash,
        string $nextSecretHash
    ): string;

    public function cleanupExpired(int $limit): int;

    public function assertActiveNonce(string $sessionNonce): void;
}
