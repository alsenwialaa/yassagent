<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use RuntimeException;
use YassinStore\AiAssistant\Application\Port\BrowserContinuityAuthorityPort;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Support\Base64Url;
use YassinStore\AiAssistant\Support\BrowserContinuitySecret;

/** Durable one-way browser-secret authority; raw bearer values never persist. */
final class BrowserContinuityAuthorityRepository implements BrowserContinuityAuthorityPort
{
    /** @var TransactionManager */ private $transactions;
    /** @var Settings */ private $settings;

    public function __construct(TransactionManager $transactions, Settings $settings)
    {
        $this->transactions = $transactions;
        $this->settings = $settings;
    }

    public function activate(string $secretHash): string
    {
        $this->assertHash($secretHash);
        return $this->transactions->run(function () use ($secretHash): string {
            global $wpdb;
            $now = time();
            $nowSql = gmdate('Y-m-d H:i:s', $now);
            $row = $this->lockOptional($secretHash);
            if ($row === null) {
                $nonce = $this->nonce();
                $inserted = $wpdb->query($wpdb->prepare(
                    'INSERT IGNORE INTO ' . SchemaRegistry::browserContinuityAuthorities()
                    . ' (secret_hash,session_nonce,status,rotated_to_hash,created_at,updated_at,expires_at)'
                    . " VALUES (%s,%s,'active',NULL,%s,%s,%s)",
                    $secretHash,
                    $nonce,
                    $nowSql,
                    $nowSql,
                    $this->expirySql($now)
                ));
                if ($inserted === false || WpdbError::has($wpdb)) {
                    throw new RuntimeException('Unable to initialize browser continuity authority.');
                }
                $row = $this->lock($secretHash);
            }
            if ($row['status'] !== 'active') {
                throw new RuntimeException('Browser continuity credential has been revoked.');
            }
            $expiresAt = $this->expirySql($now);
            $updated = $wpdb->update(
                SchemaRegistry::browserContinuityAuthorities(),
                array('updated_at' => $nowSql, 'expires_at' => $expiresAt),
                array('id' => $row['id'], 'status' => 'active'),
                array('%s', '%s'),
                array('%d', '%s')
            );
            if ($updated === false || WpdbError::has($wpdb)) {
                throw new RuntimeException('Unable to refresh browser continuity authority.');
            }
            return $row['session_nonce'];
        });
    }

    public function rotate(
        string $previousSecretHash,
        string $nextSecretHash
    ): string {
        $this->assertHash($previousSecretHash);
        $this->assertHash($nextSecretHash);
        if (hash_equals($previousSecretHash, $nextSecretHash)) {
            throw new RuntimeException('Browser continuity rotation must change its credential.');
        }
        return $this->transactions->run(function () use (
            $previousSecretHash,
            $nextSecretHash
        ): string {
            global $wpdb;
            $previous = $this->lockOptional($previousSecretHash);
            $next = $this->lockOptional($nextSecretHash);
            $now = time();
            $nowSql = gmdate('Y-m-d H:i:s', $now);

            if ($previous === null) {
                // Cleanup may remove the revoked predecessor after a successful
                // A -> B rotation while the browser is still retrying the lost
                // response. The already-active successor is sufficient proof
                // of that exact bearer authority; never create it here when
                // both rows are absent.
                if ($next !== null) {
                    if ($next['status'] !== 'active') {
                        throw new RuntimeException('Browser continuity credential has been revoked.');
                    }
                    return $next['session_nonce'];
                }
                throw new RuntimeException('Previous browser continuity authority is missing.');
            }
            if ($previous['status'] === 'revoked') {
                // A lost successful boot may replay the same exact A -> B
                // transition. No other reuse of A or B is permitted.
                if (
                    $next !== null
                    && $next['status'] === 'active'
                    && is_string($previous['rotated_to_hash'])
                    && hash_equals($previous['rotated_to_hash'], $nextSecretHash)
                ) {
                    return $next['session_nonce'];
                }
                throw new RuntimeException('Browser continuity credential has been revoked.');
            }
            if ($previous['status'] !== 'active') {
                throw new RuntimeException('Browser continuity authority state is invalid.');
            }
            if ($next !== null) {
                throw new RuntimeException('A browser continuity credential cannot be reused.');
            }

            $nextNonce = $this->nonce();
            $inserted = $wpdb->insert(
                SchemaRegistry::browserContinuityAuthorities(),
                array(
                    'secret_hash' => $nextSecretHash,
                    'session_nonce' => $nextNonce,
                    'status' => 'active',
                    'rotated_to_hash' => null,
                    'created_at' => $nowSql,
                    'updated_at' => $nowSql,
                    'expires_at' => $this->expirySql($now),
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            if ($inserted !== 1 || WpdbError::has($wpdb)) {
                throw new RuntimeException('Unable to create rotated browser continuity authority.');
            }
            $revoked = $wpdb->update(
                SchemaRegistry::browserContinuityAuthorities(),
                array(
                    'status' => 'revoked',
                    'rotated_to_hash' => $nextSecretHash,
                    'updated_at' => $nowSql,
                    'expires_at' => $this->expirySql($now),
                ),
                array('id' => $previous['id'], 'status' => 'active'),
                array('%s', '%s', '%s', '%s'),
                array('%d', '%s')
            );
            if ($revoked !== 1 || WpdbError::has($wpdb)) { // @phpstan-ignore booleanOr.rightAlwaysFalse (wpdb last_error is mutable at runtime)
                throw new RuntimeException('Unable to revoke prior browser continuity authority.');
            }
            return $nextNonce;
        });
    }

    public function cleanupExpired(int $limit): int
    {
        $limit = max(1, min(1000, $limit));
        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . SchemaRegistry::browserContinuityAuthorities()
            . ' WHERE expires_at < %s ORDER BY id ASC LIMIT %d',
            gmdate('Y-m-d H:i:s'),
            $limit
        ));
        if (
            !is_int($deleted) || $deleted < 0 || $deleted > $limit
            || WpdbError::has($wpdb)
        ) {
            throw new RuntimeException('Unable to clean expired browser continuity authorities.');
        }
        return $deleted;
    }

    public function assertActiveNonce(string $sessionNonce): void
    {
        if (!BrowserContinuitySecret::isValid($sessionNonce)) {
            throw new RuntimeException('Browser continuity session authority is invalid.');
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id,status FROM ' . SchemaRegistry::browserContinuityAuthorities()
            . ' WHERE session_nonce = %s AND expires_at > %s LIMIT 2',
            $sessionNonce,
            gmdate('Y-m-d H:i:s')
        ), ARRAY_A);
        if (
            WpdbError::has($wpdb) || !is_array($rows)
            || count($rows) !== 1 || !is_array($rows[0])
            || (int) ($rows[0]['id'] ?? 0) < 1
            || (string) ($rows[0]['status'] ?? '') !== 'active'
        ) {
            throw new RuntimeException('Browser continuity session authority is not active.');
        }
    }

    /** @return array{id:int,secret_hash:string,session_nonce:string,status:string,rotated_to_hash:string|null} */
    private function lock(string $secretHash): array
    {
        $row = $this->lockOptional($secretHash);
        if ($row === null) {
            throw new RuntimeException('Browser continuity authority is missing.');
        }
        return $row;
    }

    /** @return array{id:int,secret_hash:string,session_nonce:string,status:string,rotated_to_hash:string|null}|null */
    private function lockOptional(string $secretHash): ?array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . SchemaRegistry::browserContinuityAuthorities()
            . ' WHERE secret_hash = %s LIMIT 2 FOR UPDATE',
            $secretHash
        ), ARRAY_A);
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to lock browser continuity authority.');
        }
        if (!is_array($rows) || count($rows) > 1) {
            throw new RuntimeException('Browser continuity authority is malformed.');
        }
        if ($rows === array()) {
            return null;
        }
        return is_array($rows[0])
            ? $this->normalizeRow($rows[0], $secretHash)
            : $this->malformedRow();
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,secret_hash:string,session_nonce:string,status:string,rotated_to_hash:string|null}
     */
    private function normalizeRow(array $row, string $expectedHash): array
    {
        $id = (int) ($row['id'] ?? 0);
        $hash = (string) ($row['secret_hash'] ?? '');
        $nonce = (string) ($row['session_nonce'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $rotated = $row['rotated_to_hash'] ?? null;
        if (
            $id < 1 || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
            || ($expectedHash !== '' && !hash_equals($expectedHash, $hash))
            || !BrowserContinuitySecret::isValid($nonce)
            || !in_array($status, array('active', 'revoked'), true)
            || ($rotated !== null
                && (!is_string($rotated) || preg_match('/^[a-f0-9]{64}$/', $rotated) !== 1))
            || ($status === 'active' && $rotated !== null)
            || ($status === 'revoked' && !is_string($rotated))
        ) {
            throw new RuntimeException('Browser continuity authority is malformed.');
        }
        return array(
            'id' => $id,
            'secret_hash' => $hash,
            'session_nonce' => $nonce,
            'status' => $status,
            'rotated_to_hash' => $rotated,
        );
    }

    /** @return never */
    private function malformedRow(): array
    {
        throw new RuntimeException('Browser continuity authority is malformed.');
    }

    private function assertHash(string $hash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new RuntimeException('Browser continuity authority hash is invalid.');
        }
    }

    private function nonce(): string
    {
        $nonce = Base64Url::encode(random_bytes(32));
        if (!BrowserContinuitySecret::isValid($nonce)) {
            throw new RuntimeException('Unable to generate browser continuity authority.');
        }
        return $nonce;
    }

    private function expirySql(int $now): string
    {
        $days = max(1, min(3650, (int) $this->settings->get('conversation_retention_days', 45)));
        return gmdate('Y-m-d H:i:s', $now + ($days * DAY_IN_SECONDS));
    }
}
