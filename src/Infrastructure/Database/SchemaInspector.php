<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/** Reads all relevant table, column, and index metadata in three bounded queries. */
final class SchemaInspector
{
    /** @var object */ private $database;

    /** @param object $database wpdb-compatible database adapter */
    public function __construct($database)
    {
        $this->database = $database;
    }


    public function inspect(SchemaDefinition $definition): SchemaInspection
    {
        $names = $definition->tableNames();
        if ($names === array()) {
            return new SchemaInspection(array());
        }

        $tableRows = $this->rows(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION'
            . ' FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $this->placeholders(count($names)) . ')',
            $names
        );
        $columnRows = $this->rows(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, ORDINAL_POSITION'
            . ' FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $this->placeholders(count($names)) . ')'
            . ' ORDER BY TABLE_NAME, ORDINAL_POSITION',
            $names
        );
        $indexRows = $this->rows(
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SUB_PART, INDEX_TYPE, SEQ_IN_INDEX'
            . ' FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $this->placeholders(count($names)) . ')'
            . ' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            $names
        );

        $tables = array();
        foreach ($tableRows as $row) {
            $name = isset($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
            if ($name === '') {
                continue;
            }
            $tables[$name] = array(
                'engine' => isset($row['ENGINE']) ? (string) $row['ENGINE'] : '',
                'collation' => isset($row['TABLE_COLLATION']) ? (string) $row['TABLE_COLLATION'] : '',
                'columns' => array(),
                'indexes' => array(),
            );
        }

        foreach ($columnRows as $row) {
            $tableName = isset($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
            $columnName = isset($row['COLUMN_NAME']) ? (string) $row['COLUMN_NAME'] : '';
            if ($tableName === '' || $columnName === '' || !isset($tables[$tableName])) {
                continue;
            }
            $tables[$tableName]['columns'][$columnName] = array(
                'type' => isset($row['COLUMN_TYPE']) ? (string) $row['COLUMN_TYPE'] : '',
                'nullable' => isset($row['IS_NULLABLE']) && strtoupper((string) $row['IS_NULLABLE']) === 'YES',
                'default' => array_key_exists('COLUMN_DEFAULT', $row) && $row['COLUMN_DEFAULT'] !== null
                    ? (string) $row['COLUMN_DEFAULT']
                    : null,
                'extra' => isset($row['EXTRA']) ? (string) $row['EXTRA'] : '',
                'charset' => isset($row['CHARACTER_SET_NAME'])
                    ? (string) $row['CHARACTER_SET_NAME']
                    : null,
                'collation' => isset($row['COLLATION_NAME'])
                    ? (string) $row['COLLATION_NAME']
                    : null,
            );
        }

        foreach ($indexRows as $row) {
            $tableName = isset($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
            $indexName = isset($row['INDEX_NAME']) ? (string) $row['INDEX_NAME'] : '';
            $columnName = isset($row['COLUMN_NAME']) ? (string) $row['COLUMN_NAME'] : '';
            if ($tableName === '' || $indexName === '' || $columnName === '' || !isset($tables[$tableName])) {
                continue;
            }
            if (!isset($tables[$tableName]['indexes'][$indexName])) {
                $tables[$tableName]['indexes'][$indexName] = array(
                    'unique' => isset($row['NON_UNIQUE']) && (string) $row['NON_UNIQUE'] === '0',
                    'type' => isset($row['INDEX_TYPE']) ? strtoupper((string) $row['INDEX_TYPE']) : '',
                    'columns' => array(),
                    'prefixes' => array(),
                );
            }
            $tables[$tableName]['indexes'][$indexName]['columns'][] = $columnName;
            $tables[$tableName]['indexes'][$indexName]['prefixes'][] = isset($row['SUB_PART'])
                ? (int) $row['SUB_PART']
                : null;
        }

        return new SchemaInspection($tables);
    }

    /** @param array<int,string> $values @return array<int,array<string,mixed>> */
    private function rows(string $sql, array $values): array
    {
        $args = array_merge(array($sql), $values);
        $prepared = call_user_func_array(array($this->database, 'prepare'), $args);
        if (!is_string($prepared) || $prepared === '') {
            throw new SchemaInspectionUnavailableException('schema_inspection_prepare_failed', 'Unable to prepare the schema inspection query.', array('information_schema_prepare'));
        }

        $this->clearLastError();
        $rows = $this->database->get_results($prepared, ARRAY_A);
        if (!is_array($rows) || $this->lastError() !== '') {
            throw new SchemaInspectionUnavailableException('schema_inspection_failed', 'Unable to inspect the physical database schema.', array('information_schema_read'));
        }
        return $rows;
    }

    private function placeholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '%s'));
    }

    private function clearLastError(): void
    {
        $this->database->last_error = '';
    }

    private function lastError(): string
    {
        return trim((string) $this->database->last_error);
    }
}
