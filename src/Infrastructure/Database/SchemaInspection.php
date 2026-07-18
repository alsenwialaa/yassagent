<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/** Immutable physical-schema snapshot returned by SchemaInspector. */
final class SchemaInspection
{
    /** @var array<string,array<string,mixed>> */ private $tables;

    /** @param array<string,array<string,mixed>> $tables */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    /** @return array<string,array<string,mixed>> */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return array<string,mixed>|null */
    public function table(string $name): ?array
    {
        return isset($this->tables[$name]) ? $this->tables[$name] : null;
    }

    public function anyDefinedTable(SchemaDefinition $definition): bool
    {
        foreach ($definition->tableNames() as $name) {
            if (isset($this->tables[$name])) {
                return true;
            }
        }
        return false;
    }
}
