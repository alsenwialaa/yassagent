<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/**
 * One bounded, data-free structural query used between exact metadata scans.
 *
 * It immediately detects missing required tables, columns, named indexes,
 * engine, and configured table collation. Exact column/index definitions and
 * unexpected metadata remain the responsibility of the short-lived full proof.
 */
final class SchemaCanary
{
    /** @var object */ private $database;

    /** @param object $database wpdb-compatible database adapter */
    public function __construct($database)
    {
        $this->database = $database;
    }

    public function passes(SchemaDefinition $definition): bool
    {
        $tables = $definition->tables();
        if ($tables === array()) {
            return true;
        }

        $metadataConditions = array();
        $arguments = array();
        $structuralConditions = array();

        foreach ($tables as $table) {
            $tableName = (string) $table['name'];
            $metadata = '(TABLE_NAME = %s AND UPPER(ENGINE) = %s';
            $arguments[] = $tableName;
            $arguments[] = strtoupper($definition->engine());
            if ($definition->collation() !== '') {
                $metadata .= ' AND LOWER(TABLE_COLLATION) = %s';
                $arguments[] = strtolower($definition->collation());
            }
            $metadataConditions[] = $metadata . ')';

            $columns = array();
            foreach (array_keys($table['columns']) as $columnName) {
                $columns[] = $this->identifier((string) $columnName);
            }
            $indexes = array();
            foreach (array_keys($table['indexes']) as $indexName) {
                $indexes[] = $this->identifier((string) $indexName);
            }

            $query = 'SELECT ' . implode(',', $columns)
                . ' FROM ' . $this->identifier($tableName);
            if ($indexes !== array()) {
                $query .= ' FORCE INDEX (' . implode(',', $indexes) . ')';
            }
            // No customer rows are read. The database still resolves every
            // required table, column, and named index while planning the query.
            $query .= ' WHERE 1 = 0';
            $structuralConditions[] = 'NOT EXISTS (' . $query . ')';
        }

        $sql = 'SELECT /* ysai_schema_canary */ 1 WHERE '
            . '(SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND (' . implode(' OR ', $metadataConditions) . ')) = '
            . count($tables)
            . ' AND ' . implode(' AND ', $structuralConditions);

        $prepared = call_user_func_array(
            array($this->database, 'prepare'),
            array_merge(array($sql), $arguments)
        );
        if (!is_string($prepared) || $prepared === '') {
            return false;
        }

        $this->clearLastError();
        $result = $this->database->get_var($prepared);
        return $this->lastError() === '' && (string) $result === '1';
    }

    private function identifier(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new SchemaException(
                'schema_definition_invalid',
                'Schema canary identifier is invalid.',
                array('schema_canary_identifier')
            );
        }
        return '`' . $value . '`';
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
