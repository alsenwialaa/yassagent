<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use YassinStore\AiAssistant\Infrastructure\Database\WpdbError;
use YassinStore\AiAssistant\Application\Port\RateLimiterPort;
use InvalidArgumentException;
use RuntimeException;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaRegistry;
use YassinStore\AiAssistant\Infrastructure\Database\TransactionManager;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Support\Uuid;

/**
 * Database-backed fixed-window limiter.
 *
 * One low-cardinality shared bucket is locked before local session, client,
 * or network identities. Existing local rows are evaluated before any missing
 * local row is initialized. A denied request rolls the transaction back, so
 * no counter is incremented and no newly allocated zero-count identity row is
 * committed. Stable hash ordering prevents avoidable lock-order deadlocks.
 */
final class RateLimiter implements RateLimiterPort
{
    private const BOOT_CLIENT_LIMIT = 30;
    private const BOOT_NETWORK_LIMIT = 600;
    private const BOOT_SITE_LIMIT = 3000;
    private const BOOT_WINDOW_SECONDS = 600;

    /** @var Settings */
    private $settings;

    /** @var TransactionManager */
    private $transactions;

    public function __construct(Settings $settings, TransactionManager $transactions)
    {
        $this->settings = $settings;
        $this->transactions = $transactions;
    }

    /** @return array{allowed:bool,retry_after:int} */
    public function consumeBoot(string $clientInstanceId, string $ip): array
    {
        $clientInstanceId = strtolower(trim($clientInstanceId));
        if (!Uuid::isV4($clientInstanceId)) {
            throw new InvalidArgumentException('Boot client identity is invalid.');
        }
        $network = IpNetwork::normalize($ip);
        if ($network === '') {
            $network = 'unknown';
        }

        return $this->consumeBuckets(array(
            array(
                'identity' => 'boot-site',
                'limit' => self::BOOT_SITE_LIMIT,
                'window' => self::BOOT_WINDOW_SECONDS,
                'shared' => true,
            ),
            array(
                'identity' => 'boot-client|' . $clientInstanceId,
                'limit' => self::BOOT_CLIENT_LIMIT,
                'window' => self::BOOT_WINDOW_SECONDS,
                'shared' => false,
            ),
            array(
                'identity' => 'boot-network|' . $network,
                'limit' => self::BOOT_NETWORK_LIMIT,
                'window' => self::BOOT_WINDOW_SECONDS,
                'shared' => false,
            ),
        ));
    }

    /** @return array{allowed:bool,retry_after:int} */
    public function consume(string $sessionHash, string $ip): array
    {
        $sessionHash = trim($sessionHash);
        if (preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1) {
            throw new InvalidArgumentException('Rate-limit session identity is invalid.');
        }

        $limit = max(1, (int) $this->settings->get('rate_limit_turns', 40));
        $window = max(60, (int) $this->settings->get('rate_window_seconds', 600));
        $dailyLimit = max(1, (int) $this->settings->get('daily_ai_turn_limit', 1200));

        return $this->consumeBuckets(array(
            array(
                'identity' => 'day|' . gmdate('Y-m-d'),
                'limit' => $dailyLimit,
                'window' => DAY_IN_SECONDS + HOUR_IN_SECONDS,
                'shared' => true,
            ),
            array(
                'identity' => 'session|' . $sessionHash,
                'limit' => $limit,
                'window' => $window,
                'shared' => false,
            ),
            array(
                'identity' => 'ip|' . $ip,
                'limit' => max($limit * 3, 60),
                'window' => $window,
                'shared' => false,
            ),
        ));
    }

    public function cleanupExpired(int $limit = 1000): int
    {
        $limit = max(1, min(2000, $limit));
        global $wpdb;
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . SchemaRegistry::rateLimits() . ' WHERE reset_at < %s LIMIT %d',
                gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
                $limit
            )
        );
        if ($deleted === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to clean expired rate-limit rows.');
        }
        return (int) $deleted;
    }

    /**
     * @param array<int,array{identity:string,limit:int,window:int,shared:bool}> $buckets
     * @return array{allowed:bool,retry_after:int}
     */
    private function consumeBuckets(array $buckets): array
    {
        if ($buckets === array() || count($buckets) > 8) {
            throw new InvalidArgumentException('Rate-limit bucket set is invalid.');
        }

        $now = time();
        $shared = array();
        $local = array();
        foreach ($buckets as $bucket) {
            $identity = isset($bucket['identity']) ? (string) $bucket['identity'] : '';
            $limit = isset($bucket['limit']) ? (int) $bucket['limit'] : 0;
            $window = isset($bucket['window']) ? (int) $bucket['window'] : 0;
            $isShared = isset($bucket['shared']) && $bucket['shared'] === true;
            if ($identity === '' || strlen($identity) > 1024 || $limit < 1 || $window < 1) {
                throw new InvalidArgumentException('Rate-limit bucket definition is invalid.');
            }

            $hash = hash('sha256', $identity);
            if (isset($shared[$hash]) || isset($local[$hash])) {
                throw new InvalidArgumentException('Duplicate rate-limit bucket identity.');
            }
            $entry = array(
                'hash' => $hash,
                'limit' => $limit,
                'window' => $window,
            );
            if ($isShared) {
                $shared[$hash] = $entry;
            } else {
                $local[$hash] = $entry;
            }
        }
        if (count($shared) !== 1) {
            throw new InvalidArgumentException('Rate-limit admission requires exactly one shared bucket.');
        }
        ksort($shared, SORT_STRING);
        ksort($local, SORT_STRING);

        try {
            return $this->transactions->run(function () use ($shared, $local, $now): array {
                $nowSql = gmdate('Y-m-d H:i:s', $now);
                $locked = array();

                foreach ($shared as $hash => $bucket) {
                    $state = $this->initializeAndLockBucket($hash, $bucket, $now, $nowSql);
                    if ($state['count'] >= $bucket['limit']) {
                        throw new RateLimitAdmissionDenied($state['retry_after']);
                    }
                    $locked[$hash] = $state;
                }

                $missing = array();
                foreach ($local as $hash => $bucket) {
                    $state = $this->lockExistingBucket($hash, $bucket, $now);
                    if ($state === null) {
                        $missing[$hash] = $bucket;
                        continue;
                    }
                    if ($state['count'] >= $bucket['limit']) {
                        throw new RateLimitAdmissionDenied($state['retry_after']);
                    }
                    $locked[$hash] = $state;
                }

                foreach ($missing as $hash => $bucket) {
                    $state = $this->initializeAndLockBucket($hash, $bucket, $now, $nowSql);
                    if ($state['count'] >= $bucket['limit']) {
                        throw new RateLimitAdmissionDenied($state['retry_after']);
                    }
                    $locked[$hash] = $state;
                }

                global $wpdb;
                $table = SchemaRegistry::rateLimits();
                foreach ($locked as $hash => $state) {
                    $updated = $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$table}
                             SET request_token = %s, request_count = %d, reset_at = %s, updated_at = %s
                             WHERE bucket_hash = %s",
                            bin2hex(random_bytes(16)),
                            $state['count'] + 1,
                            gmdate('Y-m-d H:i:s', $state['reset']),
                            $nowSql,
                            $hash
                        )
                    );
                    $this->assertDatabaseWrite($updated, 'Unable to persist a rate-limit decision.');
                    if ($updated !== 1) {
                        throw new RuntimeException('Rate-limit bucket update did not affect exactly one row.');
                    }
                }

                return array('allowed' => true, 'retry_after' => 0);
            });
        } catch (RateLimitAdmissionDenied $denied) {
            return array('allowed' => false, 'retry_after' => $denied->retryAfter());
        }
    }

    /**
     * @param array{hash:string,limit:int,window:int} $bucket
     * @return array{count:int,reset:int,retry_after:int}
     */
    private function initializeAndLockBucket(string $hash, array $bucket, int $now, string $nowSql): array
    {
        global $wpdb;
        $table = SchemaRegistry::rateLimits();
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                    (bucket_hash,request_token,request_count,reset_at,updated_at)
                 VALUES (%s,%s,0,%s,%s)",
                $hash,
                bin2hex(random_bytes(16)),
                gmdate('Y-m-d H:i:s', $now + $bucket['window']),
                $nowSql
            )
        );
        $this->assertDatabaseWrite($inserted, 'Unable to initialize a rate-limit bucket.');

        $state = $this->lockExistingBucket($hash, $bucket, $now);
        if ($state === null) {
            throw new RuntimeException('Rate-limit bucket evidence is missing after initialization.');
        }
        return $state;
    }

    /**
     * @param array{hash:string,limit:int,window:int} $bucket
     * @return array{count:int,reset:int,retry_after:int}|null
     */
    private function lockExistingBucket(string $hash, array $bucket, int $now): ?array
    {
        global $wpdb;
        $table = SchemaRegistry::rateLimits();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT bucket_hash,request_count,reset_at FROM {$table}
                 WHERE bucket_hash = %s LIMIT 1 FOR UPDATE",
                $hash
            ),
            ARRAY_A
        );
        $this->assertDatabaseRead('Unable to lock a rate-limit bucket.');
        if ($row === null) {
            return null;
        }
        if (!is_array($row) || !hash_equals((string) ($row['bucket_hash'] ?? ''), $hash)) {
            throw new RuntimeException('Rate-limit bucket evidence is missing or corrupt.');
        }

        $storedCount = (int) ($row['request_count'] ?? -1);
        $storedReset = $this->timestamp((string) ($row['reset_at'] ?? ''));
        if ($storedCount < 0 || $storedReset < 1) {
            throw new RuntimeException('Rate-limit bucket evidence is invalid.');
        }

        $expired = $storedReset <= $now;
        $count = $expired ? 0 : $storedCount;
        $reset = $expired ? $now + $bucket['window'] : $storedReset;
        return array(
            'count' => $count,
            'reset' => $reset,
            'retry_after' => max(1, $reset - $now),
        );
    }

    private function timestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp !== false ? $timestamp : 0;
    }

    /** @param int|false $result */
    private function assertDatabaseWrite($result, string $message): void
    {
        global $wpdb;
        if ($result === false || WpdbError::has($wpdb)) {
            throw new RuntimeException($message);
        }
    }

    private function assertDatabaseRead(string $message): void
    {
        global $wpdb;
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException($message);
        }
    }
}
