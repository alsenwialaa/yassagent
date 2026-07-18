<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use YassinStore\AiAssistant\Application\Port\TransactionPort;
use RuntimeException;
use Throwable;

/** Database transaction boundary with deterministic nested savepoints. */
final class TransactionManager implements TransactionPort
{
    /** @var int */ private $depth = 0;

    /** @template T @param callable():T $callback @return T */
    public function run(callable $callback)
    {
        global $wpdb;
        $level = $this->depth;
        $savepoint = 'ysai_sp_' . ($level + 1);

        if ($level === 0) {
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new RuntimeException('Unable to start database transaction.');
            }
        } elseif ($wpdb->query('SAVEPOINT ' . $savepoint) === false) {
            throw new RuntimeException('Unable to create nested database savepoint.');
        }

        $this->depth = $level + 1;
        try {
            $result = $callback();
            if ($level === 0) {
                if ($wpdb->query('COMMIT') === false) {
                    throw new RuntimeException('Unable to commit database transaction.');
                }
            } elseif ($wpdb->query('RELEASE SAVEPOINT ' . $savepoint) === false) {
                throw new RuntimeException('Unable to release nested database savepoint.');
            }
            $this->depth = $level;
            return $result;
        } catch (Throwable $exception) {
            $this->depth = $level;
            $rolledBack = $level === 0
                ? $wpdb->query('ROLLBACK')
                : $wpdb->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
            if ($rolledBack === false) {
                throw new RuntimeException('Database transaction rollback failed.', 0, $exception);
            }
            if ($level > 0 && $wpdb->query('RELEASE SAVEPOINT ' . $savepoint) === false) {
                throw new RuntimeException('Nested database savepoint cleanup failed.', 0, $exception);
            }
            throw $exception;
        }
    }
}
