<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/**
 * Short-lived authorization proof for an exact physical schema validation.
 *
 * The proof is never sufficient on its own: every protected assistant entry
 * point also executes a structural canary. Only a recent proof with the exact
 * definition, database scope, and version may skip the full metadata scan.
 */
final class SchemaRuntimeProof
{
    public const TTL_SECONDS = 300;
    private const CLOCK_SKEW_SECONDS = 30;

    /** @param array<string,mixed> $status */
    public function isFresh(
        array $status,
        SchemaDefinition $definition,
        string $scopeKey,
        string $installedVersion,
        string $targetVersion,
        int $now
    ): bool {
        $verifiedAt = isset($status['verified_at_epoch']) && is_int($status['verified_at_epoch'])
            ? $status['verified_at_epoch']
            : 0;
        $expiresAt = isset($status['expires_at_epoch']) && is_int($status['expires_at_epoch'])
            ? $status['expires_at_epoch']
            : 0;

        return $installedVersion === $targetVersion
            && ($status['state'] ?? '') === 'ready'
            && ($status['version'] ?? '') === $targetVersion
            && ($status['fingerprint'] ?? '') === $definition->fingerprint()
            && ($status['scope_hash'] ?? '') === $this->scopeHash($scopeKey)
            && ($status['reason'] ?? '') === ''
            && ($status['issues'] ?? null) === array()
            && $verifiedAt > 0
            && $verifiedAt <= $now + self::CLOCK_SKEW_SECONDS
            && $expiresAt === $verifiedAt + self::TTL_SECONDS
            && $expiresAt >= $now;
    }

    /** @return array<string,mixed> */
    public function readyStatus(
        SchemaDefinition $definition,
        string $scopeKey,
        string $targetVersion,
        int $now
    ): array {
        return array(
            'state' => 'ready',
            'version' => $targetVersion,
            'fingerprint' => $definition->fingerprint(),
            'scope_hash' => $this->scopeHash($scopeKey),
            'verified_at' => gmdate('Y-m-d H:i:s', $now),
            'verified_at_epoch' => $now,
            'expires_at_epoch' => $now + self::TTL_SECONDS,
            'reason' => '',
            'issues' => array(),
        );
    }

    private function scopeHash(string $scopeKey): string
    {
        return hash('sha256', $scopeKey);
    }
}
