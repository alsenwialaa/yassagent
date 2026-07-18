<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/**
 * Installs the exact current schema and optionally discards all prior assistant
 * storage first. With no prior public schema, damaged authority is never
 * repaired piecemeal or translated into trusted current state.
 */
final class SchemaInstaller
{
    /** @var object */ private $database;
    /** @var callable|null */ private $schemaUpdater;

    /** @param object $database wpdb-compatible database adapter @param callable|null $schemaUpdater */
    public function __construct($database, $schemaUpdater = null)
    {
        if ($schemaUpdater !== null && !is_callable($schemaUpdater)) {
            throw new \InvalidArgumentException('Schema updater must be callable.');
        }
        $this->database = $database;
        $this->schemaUpdater = $schemaUpdater;
    }

    public function install(SchemaDefinition $definition, bool $resetExisting): void
    {
        if ($resetExisting) {
            $this->dropOwnedTables($definition);
        }
        $this->runDbDelta($definition);
    }

    /** Remove the complete unpublished plugin table namespace before rebuild. */
    public function dropOwnedTables(SchemaDefinition $definition): void
    {
        $prefix = $definition->ownedTablePrefix();
        $escaped = addcslashes($prefix, '\\_%') . '%';
        $this->clearLastError();
        $sql = $this->database->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
            $escaped
        );
        $rows = $this->database->get_col($sql);
        if (!is_array($rows) || $this->lastError() !== '') {
            throw new SchemaException(
                'schema_install_failed',
                'Database schema installation failed.',
                array('list_owned_tables')
            );
        }
        $names = array_values(array_unique(array_filter($rows, static function ($name) use ($prefix): bool {
            return is_string($name) && strpos($name, $prefix) === 0
                && preg_match('/^[A-Za-z0-9_]+$/D', $name) === 1;
        })));
        rsort($names, SORT_STRING);
        foreach ($names as $tableName) {
            $this->query(
                'DROP TABLE IF EXISTS ' . SchemaDefinition::quoteIdentifier($tableName),
                'drop_table:' . $tableName
            );
        }
    }

    private function runDbDelta(SchemaDefinition $definition): void
    {
        if ($this->schemaUpdater === null) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $updater = 'dbDelta';
        } else {
            $updater = $this->schemaUpdater;
        }

        foreach ($definition->createStatements() as $tableName => $statement) {
            $this->clearLastError();
            call_user_func($updater, $statement);
            if ($this->lastError() !== '') {
                throw new SchemaException(
                    'schema_install_failed',
                    'Database schema installation failed.',
                    array('dbdelta:' . $tableName)
                );
            }
        }
    }

    private function query(string $sql, string $issue): void
    {
        $this->clearLastError();
        $result = $this->database->query($sql);
        if ($result === false || $this->lastError() !== '') {
            throw new SchemaException(
                'schema_install_failed',
                'Database schema installation failed.',
                array($issue)
            );
        }
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
