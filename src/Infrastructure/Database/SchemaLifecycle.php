<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use Throwable;

/**
 * Exact-schema lifecycle facade for the first public assistant storage authority.
 *
 * Protected assistant entry points only verify and fail closed. Activation and
 * the explicit administration repair action may install or cleanly rebuild the
 * full schema. Generic WordPress boot never inspects the physical schema.
 * Partial physical repair is prohibited because it could preserve contradictory
 * authority across related tables.
 */
final class SchemaLifecycle
{
    public const SCHEMA_VERSION = '20260718.54';
    public const SCHEMA_OPTION = 'ysai_schema_version';
    public const SCHEMA_STATUS_OPTION = 'ysai_schema_status';

    /** @var array<string,array<string,mixed>> */ private static $lastStatusByScope = array();

    /** Full activation/install pass. Returns false instead of causing a fatal activation. */
    public static function install(): bool
    {
        return self::installOrRepair(10);
    }

    /**
     * Exact entry-point verification only. Customer requests never execute destructive DDL.
     */
    public static function verifyRuntime(): bool
    {
        try {
            global $wpdb;
            $definition = SchemaRegistry::current();
            $installed = self::installedVersion();

            $proof = new SchemaRuntimeProof();
            $storedStatus = get_option(self::SCHEMA_STATUS_OPTION, array());
            if (
                is_array($storedStatus)
                && $proof->isFresh(
                    $storedStatus,
                    $definition,
                    SchemaRegistry::scopeKey(),
                    $installed,
                    self::SCHEMA_VERSION,
                    time()
                )
                && (new SchemaCanary($wpdb))->passes($definition)
            ) {
                self::$lastStatusByScope[SchemaRegistry::scopeKey()] = $storedStatus;
                return true;
            }

            // A missing/failed canary never revokes readiness by itself. The
            // exact metadata inspection below distinguishes proven drift from a
            // transient inability to inspect database metadata.
            $validation = (new SchemaValidator())->validate(
                $definition,
                (new SchemaInspector($wpdb))->inspect($definition)
            );
            $policy = new SchemaRecoveryPolicy();
            if ($policy->runtimeIsReady($installed, self::SCHEMA_VERSION, $validation)) {
                self::recordReady($definition);
                return true;
            }

            self::recordBlocked(
                $policy->runtimeReason($installed, self::SCHEMA_VERSION, $validation),
                $policy->runtimeIssues($installed, self::SCHEMA_VERSION, $validation)
            );
            return false;
        } catch (SchemaInspectionUnavailableException $exception) {
            self::recordUnverifiable($exception->reasonCode(), $exception->issues());
            return false;
        } catch (SchemaException $exception) {
            // Runtime metadata persistence or definition failures block this
            // request, but they do not prove physical incompatibility.
            self::recordUnverifiable($exception->reasonCode(), $exception->issues());
            return false;
        } catch (Throwable $exception) {
            self::recordUnverifiable('database_schema_error', array('database_schema_error'));
            return false;
        }
    }

    /** Explicit administration repair pass. */
    public static function repair(): bool
    {
        try {
            // New boot/admission work and destructive repair share one lock.
            // The repair path acquires this outer barrier exactly once; the
            // activation installer intentionally has no nested maintenance
            // lock because the plugin runtime is not composed yet.
            return (new MaintenanceGate())->run(static function (): bool {
                return self::installOrRepair(15, true);
            });
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public static function status(): array
    {
        $scope = SchemaRegistry::scopeKey();
        if (isset(self::$lastStatusByScope[$scope])) {
            return self::$lastStatusByScope[$scope];
        }
        $stored = get_option(self::SCHEMA_STATUS_OPTION, array());
        return is_array($stored) ? $stored : array();
    }

    public static function isReady(): bool
    {
        $status = self::status();
        return isset($status['state']) && $status['state'] === 'ready'
            && isset($status['version']) && $status['version'] === self::SCHEMA_VERSION;
    }

    public static function dropAll(): void
    {
        global $wpdb;
        $failed = false;
        try {
            (new SchemaInstaller($wpdb))->dropOwnedTables(SchemaRegistry::current());
        } catch (Throwable $exception) {
            $failed = true;
        }
        $sentinel = '__ysai_uninstall_option_missing__';
        foreach (
            array(
            self::SCHEMA_OPTION,
            self::SCHEMA_STATUS_OPTION,
            ) as $option
        ) {
            delete_option($option);
            if (get_option($option, $sentinel) !== $sentinel) {
                $failed = true;
            }
        }
        unset(self::$lastStatusByScope[SchemaRegistry::scopeKey()]);
        if ($failed) {
            throw new \RuntimeException('One or more assistant database stores could not be removed.');
        }
    }

    private static function installOrRepair(
        int $lockTimeout,
        bool $rejectLiveWork = false
    ): bool {
        $lock = null;

        try {
            global $wpdb;
            $definition = SchemaRegistry::current();
            $inspector = new SchemaInspector($wpdb);
            $validator = new SchemaValidator();
            $policy = new SchemaRecoveryPolicy();
            $installer = new SchemaInstaller($wpdb);
            $lock = new AdvisoryLock(
                $wpdb,
                'schema',
                SchemaRegistry::scopeKey()
            );

            if (!$lock->acquire($lockTimeout)) {
                $installed = self::installedVersion();
                $validation = $validator->validate($definition, $inspector->inspect($definition));
                if ($policy->runtimeIsReady($installed, self::SCHEMA_VERSION, $validation)) {
                    self::recordReady($definition);
                    return true;
                }

                self::recordBlocked('schema_lock_busy', array('schema_lock_busy'));
                return false;
            }

            $installed = self::installedVersion();
            $inspection = $inspector->inspect($definition);
            $validation = $validator->validate($definition, $inspection);

            if (!$policy->runtimeIsReady($installed, self::SCHEMA_VERSION, $validation)) {
                if ($rejectLiveWork) {
                    $liveWork = self::liveWorkBeforeRepair($definition, $inspection);
                    // A live lease or an operational failure while reading a
                    // structurally usable lease store both reject repair. No
                    // readiness invalidation or DDL has occurred at this point.
                    if ($liveWork !== false) {
                        return false;
                    }
                }
                self::recordRebuilding($validation->issues());
                $installer->install(
                    $definition,
                    $policy->requiresCleanSlate(
                        $installed,
                        self::SCHEMA_VERSION,
                        $definition,
                        $inspection,
                        $validation
                    )
                );
            }

            $finalValidation = $validator->validate($definition, $inspector->inspect($definition));
            if (!$finalValidation->isValid()) {
                throw new SchemaException(
                    'database_schema_incomplete',
                    'Database schema verification failed.',
                    $finalValidation->issues()
                );
            }

            self::recordReady($definition);
            return true;
        } catch (SchemaInspectionUnavailableException $exception) {
            // An administrator-triggered repair that cannot read metadata has
            // not yet proved incompatibility or crossed the rebuild barrier.
            self::recordUnverifiable($exception->reasonCode(), $exception->issues());
            return false;
        } catch (SchemaException $exception) {
            self::recordBlocked($exception->reasonCode(), $exception->issues());
            return false;
        } catch (Throwable $exception) {
            self::recordBlocked('database_schema_error', array('database_schema_error'));
            return false;
        } finally {
            if ($lock instanceof AdvisoryLock) {
                $lock->release();
            }
        }
    }

    private static function installedVersion(): string
    {
        $installed = get_option(self::SCHEMA_OPTION, '');
        return is_string($installed) ? $installed : '';
    }

    /**
     * Returns true for live work, false for proven idle, and null when a
     * usable lease store could not be read safely.
     */
    private static function liveWorkBeforeRepair(
        SchemaDefinition $definition,
        SchemaInspection $inspection
    ): ?bool {
        $leaseTable = $inspection->table($definition->tableName('leases'));
        if ($leaseTable === null) {
            // No physical lease store means no current database lease can be
            // authoritative. New work is already excluded by the maintenance
            // barrier and every execution boundary fails closed without it.
            return false;
        }
        $columns = isset($leaseTable['columns']) && is_array($leaseTable['columns'])
            ? $leaseTable['columns']
            : array();
        foreach (array('resource_hash', 'resource', 'lease_until') as $column) {
            if (!array_key_exists($column, $columns)) {
                // An existing but malformed lease table cannot prove that no
                // worker owns authority. Destructive repair must fail closed.
                return null;
            }
        }

        try {
            return (new TransactionManager())->run(static function (): bool {
                return (new ActiveWorkInspector())->hasAny();
            });
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * Persists a short-lived exact-schema proof and verifies the committed
     * values. Runtime cache hits never call this method; one option write occurs
     * only after a complete physical validation or explicit install/repair.
     */
    private static function recordReady(SchemaDefinition $definition): void
    {
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        if (self::installedVersion() !== self::SCHEMA_VERSION) {
            throw new SchemaException(
                'schema_metadata_write_failed',
                'Unable to persist the schema version.',
                array('schema_version')
            );
        }

        $status = (new SchemaRuntimeProof())->readyStatus(
            $definition,
            SchemaRegistry::scopeKey(),
            self::SCHEMA_VERSION,
            time()
        );
        update_option(self::SCHEMA_STATUS_OPTION, $status, false);
        $stored = get_option(self::SCHEMA_STATUS_OPTION, array());
        if (!is_array($stored) || $stored !== $status) {
            throw new SchemaException(
                'schema_metadata_write_failed',
                'Unable to persist the verified schema status.',
                array('schema_status')
            );
        }

        self::$lastStatusByScope[SchemaRegistry::scopeKey()] = $status;
    }

    /** @param array<int,string> $issues */
    private static function recordRebuilding(array $issues): void
    {
        $status = array(
            'state' => 'rebuilding',
            'version' => self::SCHEMA_VERSION,
            'fingerprint' => '',
            'verified_at' => '',
            'started_at' => gmdate('Y-m-d H:i:s'),
            'reason' => 'schema_rebuild_in_progress',
            'issues' => array_slice(array_values(array_unique(array_filter($issues, 'is_string'))), 0, 50),
        );
        update_option(self::SCHEMA_STATUS_OPTION, $status, false);
        delete_option(self::SCHEMA_OPTION);

        $stored = get_option(self::SCHEMA_STATUS_OPTION, array());
        if (!is_array($stored) || $stored !== $status || self::installedVersion() !== '') {
            throw new SchemaException(
                'schema_metadata_write_failed',
                'Unable to invalidate ready schema metadata before rebuilding.',
                array('schema_rebuild_status')
            );
        }
        self::$lastStatusByScope[SchemaRegistry::scopeKey()] = $status;
    }

    /** @param array<int,string> $issues */
    private static function recordBlocked(string $reason, array $issues): void
    {
        // Storage readiness and provider readiness are independent. A blocked
        // schema makes health and protected entry points fail closed without
        // destroying a still-valid provider-access proof.
        self::recordUnavailableState('blocked', $reason, $issues, false);
    }

    /** @param array<int,string> $issues */
    private static function recordUnverifiable(string $reason, array $issues): void
    {
        // A failed metadata read proves only that this request cannot establish
        // storage safety. Keep the last verified provider runtime proof; the
        // next request must inspect the physical schema again before the kernel
        // can boot. No stale schema status is used as runtime authorization.
        self::recordUnavailableState('unverifiable', $reason, $issues, true);
    }

    /** @param array<int,string> $issues */
    private static function recordUnavailableState(
        string $state,
        string $reason,
        array $issues,
        bool $preserveLastVerification
    ): void {
        $issues = array_values(array_unique(array_filter($issues, static function ($issue): bool {
            return is_string($issue) && $issue !== '';
        })));
        $previous = get_option(self::SCHEMA_STATUS_OPTION, array());
        $status = array(
            'state' => $state,
            'version' => self::SCHEMA_VERSION,
            'fingerprint' => $preserveLastVerification && is_array($previous)
                ? (string) ($previous['fingerprint'] ?? '')
                : '',
            'scope_hash' => $preserveLastVerification && is_array($previous)
                ? (string) ($previous['scope_hash'] ?? '')
                : '',
            'verified_at' => $preserveLastVerification && is_array($previous)
                ? (string) ($previous['verified_at'] ?? '')
                : '',
            'verified_at_epoch' => $preserveLastVerification && is_array($previous)
                ? (int) ($previous['verified_at_epoch'] ?? 0)
                : 0,
            'expires_at_epoch' => $preserveLastVerification && is_array($previous)
                ? (int) ($previous['expires_at_epoch'] ?? 0)
                : 0,
            'failed_at' => gmdate('Y-m-d H:i:s'),
            'reason' => $reason !== '' ? $reason : 'database_schema_error',
            'issues' => array_slice($issues, 0, 50),
        );
        update_option(self::SCHEMA_STATUS_OPTION, $status, false);
        $stored = get_option(self::SCHEMA_STATUS_OPTION, array());
        if (!is_array($stored) || $stored !== $status) {
            // Keep the current request unavailable even when diagnostic
            // metadata cannot be persisted. The next request inspects again.
            self::$lastStatusByScope[SchemaRegistry::scopeKey()] = $status;
            return;
        }
        self::$lastStatusByScope[SchemaRegistry::scopeKey()] = $stored;
    }
}
