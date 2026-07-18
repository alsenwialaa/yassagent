<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Concurrency;

use YassinStore\AiAssistant\Infrastructure\Database\WpdbError;
use YassinStore\AiAssistant\Application\Port\TurnLeasePort;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\LeaseLostException;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaRegistry;

final class TurnLeaseManager implements TurnLeasePort
{
    public function acquire(string $resource, int $ttl): ?TurnLease
    {
        if ($resource === '' || strlen($resource) > 191) {
            throw new RuntimeException('Turn lease resource is invalid.');
        }
        global $wpdb;

        $ttl = max(30, min(3600, $ttl));
        $hash = hash('sha256', $resource);
        $owner = bin2hex(random_bytes(16));
        $now = time();
        $until = $now + $ttl;
        $table = SchemaRegistry::leases();
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $untilSql = gmdate('Y-m-d H:i:s', $until);

        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
                (resource_hash,resource,owner,fence,lease_until,updated_at)
             VALUES (%s,%s,%s,1,%s,%s)
             ON DUPLICATE KEY UPDATE
                fence = IF(lease_until <= %s, fence + 1, fence),
                owner = IF(lease_until <= %s, VALUES(owner), owner),
                resource = IF(lease_until <= %s, VALUES(resource), resource),
                updated_at = IF(lease_until <= %s, VALUES(updated_at), updated_at),
                lease_until = IF(lease_until <= %s, VALUES(lease_until), lease_until)",
            $hash,
            $resource,
            $owner,
            $untilSql,
            $nowSql,
            $nowSql,
            $nowSql,
            $nowSql,
            $nowSql,
            $nowSql
        );
        if ($wpdb->query($sql) === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to acquire turn lease.');
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE resource_hash = %s LIMIT 1", $hash),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) { // @phpstan-ignore if.alwaysFalse (wpdb last_error is mutable at runtime despite the upstream stub literal default)
            throw new RuntimeException('Unable to read acquired turn lease.');
        }
        if (!is_array($row) || !hash_equals((string) ($row['owner'] ?? ''), $owner)) {
            return null;
        }
        if (
            !hash_equals((string) ($row['resource_hash'] ?? ''), $hash)
            || !hash_equals((string) ($row['resource'] ?? ''), $resource)
        ) {
            throw new RuntimeException('Turn lease resource evidence is corrupt.');
        }
        $expiresAt = $this->timestamp((string) ($row['lease_until'] ?? ''));
        if ((int) ($row['fence'] ?? 0) < 1 || $expiresAt <= $now) {
            throw new RuntimeException('Acquired turn lease evidence is invalid.');
        }

        return new TurnLease($resource, $hash, $owner, (int) $row['fence'], $expiresAt);
    }

    public function renew(TurnLease $lease, int $ttl): TurnLease
    {
        global $wpdb;
        $ttl = max(30, min(3600, $ttl));
        $now = time();
        $until = $now + $ttl;
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . SchemaRegistry::leases()
                . ' SET lease_until = %s, updated_at = %s'
                . ' WHERE resource_hash = %s AND resource = %s AND owner = %s AND fence = %d AND lease_until > %s',
                gmdate('Y-m-d H:i:s', $until),
                gmdate('Y-m-d H:i:s', $now),
                $lease->resourceHash(),
                $lease->resource(),
                $lease->owner(),
                $lease->fence(),
                gmdate('Y-m-d H:i:s', $now)
            )
        );
        if ($updated !== 1 || WpdbError::has($wpdb)) {
            throw new LeaseLostException('The expired or superseded turn lease cannot be renewed.');
        }
        return $lease->renewedUntil($until);
    }

    public function remainingSeconds(TurnLease $lease): float
    {
        return max(0.0, (float) ($lease->expiresAt() - time()));
    }

    public function assertCurrent(TurnLease $lease): void
    {
        if (!$this->isCurrent($lease)) {
            throw new LeaseLostException('The turn lease is no longer current.');
        }
    }

    public function isCurrent(TurnLease $lease): bool
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT resource,owner,fence,lease_until FROM ' . SchemaRegistry::leases()
                . ' WHERE resource_hash = %s LIMIT 1',
                $lease->resourceHash()
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            return false;
        }
        return is_array($row)
            && hash_equals((string) ($row['resource'] ?? ''), $lease->resource())
            && hash_equals((string) ($row['owner'] ?? ''), $lease->owner())
            && (int) ($row['fence'] ?? 0) === $lease->fence()
            && $this->timestamp((string) ($row['lease_until'] ?? '')) > time();
    }

    /** Must be called inside a database transaction. */
    public function assertCurrentForUpdate(TurnLease $lease): void
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT resource,owner,fence,lease_until FROM ' . SchemaRegistry::leases()
                . ' WHERE resource_hash = %s LIMIT 1 FOR UPDATE',
                $lease->resourceHash()
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new LeaseLostException('The turn lease could not be verified.');
        }
        if (
            !is_array($row)
            || !hash_equals((string) ($row['resource'] ?? ''), $lease->resource())
            || !hash_equals((string) ($row['owner'] ?? ''), $lease->owner())
            || (int) ($row['fence'] ?? 0) !== $lease->fence()
            || $this->timestamp((string) ($row['lease_until'] ?? '')) <= time()
        ) {
            throw new LeaseLostException('The turn lease is no longer current.');
        }
    }

    public function release(TurnLease $lease): void
    {
        global $wpdb;
        $now = time();
        // Keep the row beyond release so immediate reacquisition advances the
        // fence instead of resetting it. Conversation purge or the aged
        // standalone-resource cleanup owns eventual row retirement.
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . SchemaRegistry::leases()
                . ' SET lease_until = %s, updated_at = %s'
                . ' WHERE resource_hash = %s AND resource = %s AND owner = %s AND fence = %d',
                gmdate('Y-m-d H:i:s', $now - 1),
                gmdate('Y-m-d H:i:s', $now),
                $lease->resourceHash(),
                $lease->resource(),
                $lease->owner(),
                $lease->fence()
            )
        );
        if ($updated === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to release turn lease.');
        }
    }

    public function cleanupExpired(int $limit = 250): int
    {
        global $wpdb;
        $limit = max(1, min(1000, $limit));
        $now = time();
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $retireBeforeSql = gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS);
        $leaseTable = SchemaRegistry::leases();
        $operationTable = SchemaRegistry::operations();

        // Conversation-turn lease rows are retired with their owner. Boot and
        // commerce resources have no conversation owner, so retire them after
        // a full safety day; commerce rows additionally remain while referenced
        // by a nonterminal durable cart operation. Rechecking every predicate
        // in the DELETE protects against an acquire between select and delete.
        $hashes = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT l.resource_hash FROM {$leaseTable} l"
                . ' WHERE l.lease_until < %s AND l.updated_at < %s AND ('
                . 'l.resource LIKE %s OR (l.resource LIKE %s'
                . " AND NOT EXISTS (SELECT 1 FROM {$operationTable} o"
                . " WHERE o.commerce_resource_hash = l.resource_hash AND o.status IN (%s,%s))))"
                . ' ORDER BY l.updated_at ASC LIMIT %d',
                $nowSql,
                $retireBeforeSql,
                'boot|%',
                'commerce|%',
                'prepared',
                'executing',
                $limit
            )
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to select retired standalone leases.');
        }
        $selectedCount = is_array($hashes) ? count($hashes) : 0;
        $hashes = is_array($hashes) ? array_values(array_unique(array_filter(array_map(
            static function ($value): string {
                $value = strtolower(trim((string) $value));
                return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '';
            },
            $hashes
        )))) : array();
        if (count($hashes) !== $selectedCount) {
            throw new RuntimeException('Retired standalone lease identity is corrupt or duplicated.');
        }
        if ($hashes === array()) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));
        $arguments = array_merge(
            $hashes,
            array($nowSql, $retireBeforeSql, 'boot|%', 'commerce|%', 'prepared', 'executing')
        );
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$leaseTable} WHERE resource_hash IN ({$placeholders})"
                . ' AND lease_until < %s AND updated_at < %s AND ('
                . 'resource LIKE %s OR (resource LIKE %s'
                . " AND NOT EXISTS (SELECT 1 FROM {$operationTable} o"
                . " WHERE o.commerce_resource_hash = {$leaseTable}.resource_hash AND o.status IN (%s,%s))))",
                $arguments
            )
        );
        if ($deleted === false || WpdbError::has($wpdb)) { // @phpstan-ignore booleanOr.rightAlwaysFalse (wpdb last_error is mutable at runtime)
            throw new RuntimeException('Unable to retire expired standalone leases.');
        }
        return (int) $deleted;
    }

    private function timestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp !== false ? $timestamp : 0;
    }
}
