<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use RuntimeException;
use YassinStore\AiAssistant\Application\Port\MaintenanceGatePort;

/** Short site-wide barrier shared by turn admission and destructive purge. */
final class MaintenanceGate implements MaintenanceGatePort
{
    /** @template T @param callable():T $critical @return T */
    public function run(callable $critical)
    {
        global $wpdb;
        $lock = new AdvisoryLock(
            $wpdb,
            'maintenance',
            SchemaRegistry::scopeKey()
        );
        if (!$lock->acquire(5)) {
            throw new RuntimeException('Unable to serialize assistant maintenance admission.');
        }
        try {
            return $critical();
        } finally {
            $lock->release();
        }
    }
}
