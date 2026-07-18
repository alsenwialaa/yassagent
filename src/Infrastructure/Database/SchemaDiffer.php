<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/** Pure, testable exact-schema comparison. */
final class SchemaDiffer
{
    public function diff(SchemaDefinition $definition, SchemaInspection $inspection): SchemaDiff
    {
        $changes = array(
            'missing_table' => array(),
            'engine_mismatch' => array(),
            'collation_mismatch' => array(),
            'missing_column' => array(),
            'changed_column' => array(),
            'unexpected_column' => array(),
            'missing_index' => array(),
            'changed_index' => array(),
            'unexpected_index' => array(),
        );

        foreach ($definition->tables() as $expectedTable) {
            $tableName = (string) $expectedTable['name'];
            $actual = $inspection->table($tableName);
            if ($actual === null) {
                $changes['missing_table'][$tableName] = true;
                continue;
            }

            if (strcasecmp((string) $actual['engine'], $definition->engine()) !== 0) {
                $changes['engine_mismatch'][$tableName] = array(
                    'actual' => (string) $actual['engine'],
                    'expected' => $definition->engine(),
                );
            }
            if (
                $definition->collation() !== ''
                && strcasecmp((string) $actual['collation'], $definition->collation()) !== 0
            ) {
                $changes['collation_mismatch'][$tableName] = array(
                    'actual' => (string) $actual['collation'],
                    'expected' => $definition->collation(),
                );
            }

            $actualColumns = isset($actual['columns']) && is_array($actual['columns']) ? $actual['columns'] : array();
            foreach ($expectedTable['columns'] as $columnName => $expectedColumn) {
                $key = $tableName . '.' . $columnName;
                if (!isset($actualColumns[$columnName])) {
                    $changes['missing_column'][$key] = true;
                    continue;
                }
                if (!$this->sameColumn($expectedColumn, $actualColumns[$columnName])) {
                    $changes['changed_column'][$key] = array(
                        'table' => $tableName,
                        'column' => $columnName,
                    );
                }
            }
            foreach ($actualColumns as $columnName => $unused) {
                if (!isset($expectedTable['columns'][$columnName])) {
                    $changes['unexpected_column'][$tableName . '.' . $columnName] = array(
                        'table' => $tableName,
                        'column' => $columnName,
                    );
                }
            }

            $actualIndexes = isset($actual['indexes']) && is_array($actual['indexes']) ? $actual['indexes'] : array();
            foreach ($expectedTable['indexes'] as $indexName => $expectedIndex) {
                $key = $tableName . '.' . $indexName;
                if (!isset($actualIndexes[$indexName])) {
                    $changes['missing_index'][$key] = true;
                    continue;
                }
                if (!$this->sameIndex($expectedIndex, $actualIndexes[$indexName])) {
                    $changes['changed_index'][$key] = array(
                        'table' => $tableName,
                        'index' => $indexName,
                    );
                }
            }
            foreach ($actualIndexes as $indexName => $unused) {
                if (!isset($expectedTable['indexes'][$indexName])) {
                    $changes['unexpected_index'][$tableName . '.' . $indexName] = array(
                        'table' => $tableName,
                        'index' => $indexName,
                    );
                }
            }
        }

        return new SchemaDiff($changes);
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function sameColumn(array $expected, array $actual): bool
    {
        return $this->normalizeType((string) $expected['type']) === $this->normalizeType((string) ($actual['type'] ?? ''))
            && (bool) $expected['nullable'] === (bool) ($actual['nullable'] ?? false)
            && $this->normalizeDefault($expected['default']) === $this->normalizeDefault($actual['default'] ?? null)
            && $this->normalizeExtra((string) $expected['extra']) === $this->normalizeExtra((string) ($actual['extra'] ?? ''))
            && $this->normalizeNullableString($expected['charset'] ?? null) === $this->normalizeNullableString($actual['charset'] ?? null)
            && $this->sameOptionalIdentifier($expected['collation'] ?? null, $actual['collation'] ?? null);
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function sameIndex(array $expected, array $actual): bool
    {
        return (bool) $expected['unique'] === (bool) ($actual['unique'] ?? false)
            && strtoupper((string) ($expected['type'] ?? 'BTREE')) === strtoupper((string) ($actual['type'] ?? ''))
            && array_values($expected['columns']) === array_values(isset($actual['columns']) && is_array($actual['columns']) ? $actual['columns'] : array())
            && array_values($expected['prefixes'] ?? array()) === array_values(isset($actual['prefixes']) && is_array($actual['prefixes']) ? $actual['prefixes'] : array());
    }

    private function normalizeType(string $value): string
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
        return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $value) ?? $value;
    }

    /** @param mixed $value */
    private function normalizeDefault($value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function normalizeExtra(string $value): string
    {
        $parts = preg_split('/\s+/', strtolower(trim($value))) ?: array();
        $parts = array_values(array_filter($parts, static function (string $part): bool {
            return $part !== '' && $part !== 'default_generated';
        }));
        sort($parts, SORT_STRING);
        return implode(' ', $parts);
    }

    /** @param mixed $expected @param mixed $actual */
    private function sameOptionalIdentifier($expected, $actual): bool
    {
        $normalizedExpected = $this->normalizeNullableString($expected);
        return $normalizedExpected === null
            || $normalizedExpected === $this->normalizeNullableString($actual);
    }

    /** @param mixed $value */
    private function normalizeNullableString($value): ?string
    {
        return $value === null || $value === '' ? null : strtolower((string) $value);
    }
}
