<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use YassinStore\AiAssistant\Infrastructure\Database\WpdbError;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * High-ceiling public-ingress limiter that is independent of assistant tables.
 *
 * The exact assistant-schema canary must never be the first expensive public
 * operation. These fixed-window counters live in the ordinary WordPress
 * options table. Admission first proves that the concrete options table uses
 * InnoDB; transaction and row-lock semantics are never inferred from a
 * WordPress installation convention. One low-cardinality route/site row is
 * locked first, then all higher-cardinality scopes are evaluated in the same
 * transaction. A denied request increments no scope and leaves no newly
 * allocated identity row.
 */
final class IngressRateLimiter
{
    private const WINDOW_SECONDS = 60;
    private const RETENTION_SECONDS = 172800;

    private const HEALTH_NETWORK_LIMIT = 60;
    private const HEALTH_SITE_LIMIT = 600;

    private const BOOT_NETWORK_LIMIT = 120;
    private const BOOT_SITE_LIMIT = 600;

    // The signed Woo-session authority is the first chat transport scope.
    // This ceiling is deliberately much higher than the durable business/AI
    // turn quota, but it still bounds exact terminal replay before any
    // conversation lock, reconciliation, transcript projection, or cart read.
    private const CHAT_SESSION_LIMIT = 180;
    private const CHAT_NETWORK_LIMIT = 600;
    private const CHAT_SITE_LIMIT = 6000;

    private const PRIVACY_SESSION_LIMIT = 30;
    private const PRIVACY_NETWORK_LIMIT = 120;
    private const PRIVACY_SITE_LIMIT = 1200;

    /** @var callable */
    private $admitter;

    /** @var callable */
    private $clock;

    /**
     * @param callable|null $admitter function(array<int,array{identity:string,limit:int,shared:bool}>,int,int):array{allowed:bool,retry_after:int}
     * @param callable|null $clock function():int
     */
    public function __construct($admitter = null, $clock = null)
    {
        if ($admitter !== null && !is_callable($admitter)) {
            throw new InvalidArgumentException('Ingress admitter must be callable.');
        }
        if ($clock !== null && !is_callable($clock)) {
            throw new InvalidArgumentException('Ingress clock must be callable.');
        }

        $this->admitter = $admitter !== null
            ? $admitter
            : function (array $buckets, int $window, int $now): array {
                return $this->admitOptionBuckets($buckets, $window, $now);
            };
        $this->clock = $clock !== null
            ? $clock
            : static function (): int {
                return time();
            };
    }

    /** @return array{allowed:bool,retry_after:int} */
    public function consumeHealth(string $ip): array
    {
        return $this->consumeRoute(
            'health',
            $ip,
            self::HEALTH_NETWORK_LIMIT,
            self::HEALTH_SITE_LIMIT
        );
    }

    /** @return array{allowed:bool,retry_after:int} */
    public function consumeBoot(string $ip): array
    {
        return $this->consumeRoute(
            'boot',
            $ip,
            self::BOOT_NETWORK_LIMIT,
            self::BOOT_SITE_LIMIT
        );
    }

    /** @return array{allowed:bool,retry_after:int} */
    public function consumeChat(string $sessionHash, string $ip): array
    {
        $sessionHash = strtolower(trim($sessionHash));
        if (preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1) {
            throw new InvalidArgumentException('Chat transport session identity is invalid.');
        }

        return $this->consumeAuthenticatedRoute(
            'chat',
            $sessionHash,
            $ip,
            self::CHAT_SESSION_LIMIT,
            self::CHAT_NETWORK_LIMIT,
            self::CHAT_SITE_LIMIT
        );
    }

    /** @return array{allowed:bool,retry_after:int} */
    public function consumeConversationPrivacy(string $sessionHash, string $ip): array
    {
        $sessionHash = strtolower(trim($sessionHash));
        if (preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1) {
            throw new InvalidArgumentException('Conversation privacy session identity is invalid.');
        }

        return $this->consumeAuthenticatedRoute(
            'conversation_privacy',
            $sessionHash,
            $ip,
            self::PRIVACY_SESSION_LIMIT,
            self::PRIVACY_NETWORK_LIMIT,
            self::PRIVACY_SITE_LIMIT
        );
    }

    /**
     * Removes a bounded batch of stale ingress rows without requiring the
     * assistant schema. Active rows are protected by their last-update time.
     */
    public function cleanupExpired(int $limit = 500): int
    {
        if ($limit < 1 || $limit > 5000) {
            throw new InvalidArgumentException('Ingress cleanup limit is invalid.');
        }

        $now = (int) call_user_func($this->clock);
        if ($now < 1) {
            throw new RuntimeException('Ingress limiter clock is invalid.');
        }

        global $wpdb;
        $table = isset($wpdb->options) ? (string) $wpdb->options : '';
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('WordPress options storage is unavailable for ingress cleanup.');
        }

        $sql = $wpdb->prepare(
            "DELETE FROM {$table}
             WHERE LEFT(option_name, 14) = %s
               AND CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) < %d
             LIMIT %d",
            '_ysai_ingress_',
            $now - self::RETENTION_SECONDS,
            $limit
        );
        $deleted = $wpdb->query($sql);
        if ($deleted === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to clean public ingress admission rows.');
        }

        return max(0, (int) $deleted);
    }

    /** Removes all schema-independent ingress rows during opted-in uninstall. */
    public static function deleteAll(): void
    {
        global $wpdb;
        $table = isset($wpdb->options) ? (string) $wpdb->options : '';
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('WordPress options storage is unavailable for ingress removal.');
        }
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE LEFT(option_name, 14) = %s",
                '_ysai_ingress_'
            )
        );
        if ($deleted === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to remove public ingress admission rows.');
        }
    }

    /** @return array{allowed:bool,retry_after:int} */
    private function consumeAuthenticatedRoute(
        string $route,
        string $sessionHash,
        string $ip,
        int $sessionLimit,
        int $networkLimit,
        int $siteLimit
    ): array {
        if (
            preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $route) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1
            || $sessionLimit < 1
            || $networkLimit < 1
            || $siteLimit < 1
        ) {
            throw new InvalidArgumentException('Authenticated ingress limiter policy is invalid.');
        }

        $now = $this->now();
        return $this->admit(array(
            array('identity' => $route . '|site', 'limit' => $siteLimit, 'shared' => true),
            array('identity' => $route . '|session|' . $sessionHash, 'limit' => $sessionLimit, 'shared' => false),
            array('identity' => $route . '|network|' . $this->networkScope($ip), 'limit' => $networkLimit, 'shared' => false),
        ), $now);
    }

    /** @return array{allowed:bool,retry_after:int} */
    private function consumeRoute(
        string $route,
        string $ip,
        int $networkLimit,
        int $siteLimit
    ): array {
        if (
            preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $route) !== 1
            || $networkLimit < 1
            || $siteLimit < 1
        ) {
            throw new InvalidArgumentException('Ingress limiter policy is invalid.');
        }

        $now = $this->now();
        return $this->admit(array(
            array('identity' => $route . '|site', 'limit' => $siteLimit, 'shared' => true),
            array('identity' => $route . '|network|' . $this->networkScope($ip), 'limit' => $networkLimit, 'shared' => false),
        ), $now);
    }

    /**
     * @param array<int,array{identity:string,limit:int,shared:bool}> $buckets
     * @return array{allowed:bool,retry_after:int}
     */
    private function admit(array $buckets, int $now): array
    {
        $result = call_user_func($this->admitter, $buckets, self::WINDOW_SECONDS, $now);
        if (
            !is_array($result)
            || !array_key_exists('allowed', $result)
            || !array_key_exists('retry_after', $result)
            || !is_bool($result['allowed'])
            || !is_int($result['retry_after'])
            || $result['retry_after'] < 0
            || ($result['allowed'] && $result['retry_after'] !== 0)
            || (!$result['allowed'] && $result['retry_after'] < 1)
        ) {
            throw new RuntimeException('Ingress admitter returned invalid evidence.');
        }

        return array(
            'allowed' => $result['allowed'],
            'retry_after' => $result['retry_after'],
        );
    }

    private function now(): int
    {
        $now = (int) call_user_func($this->clock);
        if ($now < 1) {
            throw new RuntimeException('Ingress limiter clock is invalid.');
        }
        return $now;
    }

    private function networkScope(string $ip): string
    {
        $normalized = IpNetwork::normalize($ip);
        if ($normalized === '') {
            return 'unknown';
        }

        $prefix = strpos($normalized, ':') !== false ? 64 : 24;
        $network = IpNetwork::canonicalCidr($normalized . '/' . $prefix);
        return $network !== '' ? $network : 'unknown';
    }

    private function windowId(int $now, int $window): int
    {
        return intdiv($now, $window);
    }

    /**
     * Atomically admits a complete multi-scope decision in wp_options.
     *
     * The one shared site row is initialized and locked before any local row
     * can be allocated. Existing local rows are evaluated before missing rows
     * are inserted. A denial rolls back the transaction, so no scope is
     * incremented and no new high-cardinality row survives.
     *
     * @param array<int,array{identity:string,limit:int,shared:bool}> $buckets
     * @return array{allowed:bool,retry_after:int}
     */
    private function admitOptionBuckets(array $buckets, int $window, int $now): array
    {
        if ($buckets === array() || count($buckets) > 8 || $window < 1 || $now < 1) {
            throw new InvalidArgumentException('Ingress bucket set is invalid.');
        }

        global $wpdb;
        $table = isset($wpdb->options) ? (string) $wpdb->options : '';
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('WordPress options storage is unavailable for ingress admission.');
        }
        $this->assertTransactionalOptionsTable($table);

        $shared = array();
        $local = array();
        foreach ($buckets as $bucket) {
            $identity = isset($bucket['identity']) ? (string) $bucket['identity'] : '';
            $limit = isset($bucket['limit']) ? (int) $bucket['limit'] : 0;
            $isShared = isset($bucket['shared']) && $bucket['shared'] === true;
            if ($identity === '' || strlen($identity) > 256 || $limit < 1) {
                throw new InvalidArgumentException('Ingress bucket definition is invalid.');
            }

            $name = '_ysai_ingress_' . substr(hash('sha256', $identity), 0, 48);
            if (isset($shared[$name]) || isset($local[$name])) {
                throw new InvalidArgumentException('Duplicate ingress bucket identity.');
            }
            $entry = array('name' => $name, 'limit' => $limit);
            if ($isShared) {
                $shared[$name] = $entry;
            } else {
                $local[$name] = $entry;
            }
        }
        if (count($shared) !== 1) {
            throw new InvalidArgumentException('Ingress admission requires exactly one shared site bucket.');
        }
        ksort($shared, SORT_STRING);
        ksort($local, SORT_STRING);

        $windowId = $this->windowId($now, $window);
        $started = false;
        try {
            $this->databaseStatement('START TRANSACTION', 'Unable to start public ingress transaction.');
            $started = true;

            $locked = array();
            foreach ($shared as $name => $bucket) {
                $this->initializeOptionRow($table, $name, $windowId, $now);
                $state = $this->lockOptionState($table, $name, $windowId, $window, $now, false);
                if ($state === null) {
                    throw new RuntimeException('Shared ingress bucket evidence is missing.');
                }
                if ($state['count'] >= $bucket['limit']) {
                    $this->rollbackIngressTransaction();
                    $started = false;
                    return array('allowed' => false, 'retry_after' => $state['retry_after']);
                }
                $locked[$name] = $state;
            }

            $missing = array();
            foreach ($local as $name => $bucket) {
                $state = $this->lockOptionState($table, $name, $windowId, $window, $now, true);
                if ($state === null) {
                    $missing[$name] = $bucket;
                    continue;
                }
                if ($state['count'] >= $bucket['limit']) {
                    $this->rollbackIngressTransaction();
                    $started = false;
                    return array('allowed' => false, 'retry_after' => $state['retry_after']);
                }
                $locked[$name] = $state;
            }

            foreach ($missing as $name => $bucket) {
                $this->initializeOptionRow($table, $name, $windowId, $now);
                $state = $this->lockOptionState($table, $name, $windowId, $window, $now, false);
                if ($state === null) {
                    throw new RuntimeException('Ingress bucket evidence is missing after initialization.');
                }
                if ($state['count'] >= $bucket['limit']) {
                    $this->rollbackIngressTransaction();
                    $started = false;
                    return array('allowed' => false, 'retry_after' => $state['retry_after']);
                }
                $locked[$name] = $state;
            }

            foreach ($locked as $name => $state) {
                $value = $windowId . ':' . ($state['count'] + 1) . ':' . $now;
                $updated = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$table} SET option_value = %s WHERE option_name = %s",
                        $value,
                        $name
                    )
                );
                if ($updated === false || WpdbError::has($wpdb) || $updated !== 1) {
                    throw new RuntimeException('Unable to persist public ingress admission decision.');
                }
            }

            $this->databaseStatement('COMMIT', 'Unable to commit public ingress transaction.');
            $started = false;
            return array('allowed' => true, 'retry_after' => 0);
        } catch (Throwable $exception) {
            if ($started) {
                $rolledBack = $wpdb->query('ROLLBACK');
                if ($rolledBack === false) {
                    throw new RuntimeException('Public ingress transaction rollback failed.', 0, $exception);
                }
            }
            throw $exception;
        }
    }

    private function initializeOptionRow(string $table, string $name, int $windowId, int $now): void
    {
        global $wpdb;
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (option_name,option_value,autoload) VALUES (%s,%s,'no')",
                $name,
                $windowId . ':0:' . $now
            )
        );
        if ($inserted === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to initialize public ingress admission.');
        }
    }

    /** @return array{count:int,retry_after:int}|null */
    private function lockOptionState(
        string $table,
        string $name,
        int $windowId,
        int $window,
        int $now,
        bool $missingAllowed
    ): ?array {
        global $wpdb;
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1 FOR UPDATE",
                $name
            )
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to lock public ingress admission evidence.');
        }
        if ($raw === null) {
            if ($missingAllowed) {
                return null;
            }
            throw new RuntimeException('Public ingress admission evidence is missing.');
        }
        if (!is_string($raw) || preg_match('/^([0-9]+):([0-9]+):([0-9]+)$/', $raw, $matches) !== 1) {
            throw new RuntimeException('Public ingress admission evidence is invalid.');
        }

        $storedWindow = (int) $matches[1];
        $storedCount = (int) $matches[2];
        $count = $storedWindow === $windowId ? $storedCount : 0;
        return array(
            'count' => $count,
            'retry_after' => max(1, (($windowId + 1) * $window) - $now),
        );
    }

    private function databaseStatement(string $statement, string $message): void
    {
        global $wpdb;
        $result = $wpdb->query($statement);
        if ($result === false || WpdbError::has($wpdb)) {
            throw new RuntimeException($message);
        }
    }

    private function assertTransactionalOptionsTable(string $table): void
    {
        global $wpdb;
        $engine = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
                $table
            )
        );
        if (
            WpdbError::has($wpdb)
            || !is_string($engine)
            || strtoupper(trim($engine)) !== 'INNODB'
        ) {
            throw new RuntimeException(
                'WordPress options storage cannot prove the transactional ingress contract.'
            );
        }
    }

    private function rollbackIngressTransaction(): void
    {
        global $wpdb;
        if ($wpdb->query('ROLLBACK') === false) {
            throw new RuntimeException('Public ingress transaction rollback failed.');
        }
    }
}
