<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;

/** Proves that every table inside the fenced cart transaction is transactional and lockable. */
final class WooStorageTopologyPolicy
{
    /** @var object|null */ private $database;
    /** @var array<string,bool> */ private $verified = array();

    /** @param object|null $database */
    public function __construct($database = null)
    {
        $this->database = $database;
    }

    public function assertSupported(string $sessionTable, ?string $userMetaTable): void
    {
        $this->assertTableName($sessionTable);
        if ($userMetaTable !== null) {
            $this->assertTableName($userMetaTable);
        }
        $key = $sessionTable . '|' . ($userMetaTable ?? 'guest');
        if (isset($this->verified[$key])) {
            return;
        }

        $this->assertInnoDb($sessionTable, 'WooCommerce session');
        $sessionIndexes = $this->indexes($sessionTable);
        if (!$this->hasExactUniqueIndex($sessionIndexes, array('session_key'))) {
            throw new RuntimeException('WooCommerce session storage lacks an exact unique session_key index.');
        }

        if ($userMetaTable !== null) {
            $this->assertInnoDb($userMetaTable, 'WordPress usermeta');
            $userMetaIndexes = $this->indexes($userMetaTable);
            if (
                !$this->hasExactUniqueIndex($userMetaIndexes, array('umeta_id'))
                || !$this->hasLeadingIndex($userMetaIndexes, 'user_id')
            ) {
                throw new RuntimeException('WordPress usermeta storage lacks the indexes required for fenced persistent-cart locking.');
            }
        }

        $this->verified[$key] = true;
    }

    private function assertInnoDb(string $table, string $label): void
    {
        $database = $this->database();
        $engine = $database->get_var($database->prepare(
            'SELECT ENGINE FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s LIMIT 1',
            (string) $database->dbname,
            $table
        ));
        if (trim((string) $database->last_error) !== '' || strcasecmp((string) $engine, 'InnoDB') !== 0) {
            throw new RuntimeException($label . ' storage must use InnoDB for durable cart transactions.');
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function indexes(string $table): array
    {
        $database = $this->database();
        $rows = $database->get_results($database->prepare(
            'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME'
            . ' FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s'
            . ' ORDER BY INDEX_NAME,SEQ_IN_INDEX',
            (string) $database->dbname,
            $table
        ), ARRAY_A);
        if (trim((string) $database->last_error) !== '' || !is_array($rows)) {
            throw new RuntimeException('Unable to inspect WooCommerce cart storage indexes.');
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<int,string> $columns */
    private function hasExactUniqueIndex(array $rows, array $columns): bool
    {
        foreach ($this->groupedIndexes($rows) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function hasLeadingIndex(array $rows, string $column): bool
    {
        foreach ($this->groupedIndexes($rows) as $index) {
            if (($index['columns'][0] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,array{unique:bool,columns:array<int,string>}> */
    private function groupedIndexes(array $rows): array
    {
        $grouped = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['INDEX_NAME'] ?? ''));
            $column = trim((string) ($row['COLUMN_NAME'] ?? ''));
            $sequence = (int) ($row['SEQ_IN_INDEX'] ?? 0);
            if ($name === '' || $column === '' || $sequence < 1) {
                continue;
            }
            if (!isset($grouped[$name])) {
                $grouped[$name] = array(
                    'unique' => (int) ($row['NON_UNIQUE'] ?? 1) === 0,
                    'columns' => array(),
                );
            }
            $grouped[$name]['columns'][$sequence] = $column;
        }
        foreach ($grouped as &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);
        return $grouped;
    }

    private function assertTableName(string $table): void
    {
        if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException('WooCommerce cart storage table name is invalid.');
        }
    }

    /** @return object */
    private function database()
    {
        if (is_object($this->database)) {
            return $this->database;
        }
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->dbname) || trim((string) $wpdb->dbname) === '') {
            throw new RuntimeException('WordPress database identity is unavailable for cart storage verification.');
        }
        return $wpdb;
    }
}
