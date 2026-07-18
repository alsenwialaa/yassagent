<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

/**
 * Clean-slate recovery policy for the first public schema authority.
 *
 * No row from an unversioned, differently versioned, or physically damaged
 * assistant schema is promoted into current execution authority.
 */
final class SchemaRecoveryPolicy
{
    public function runtimeIsReady(
        string $installedVersion,
        string $targetVersion,
        SchemaValidationResult $validation
    ): bool {
        return $installedVersion === $targetVersion && $validation->isValid();
    }

    public function requiresCleanSlate(
        string $installedVersion,
        string $targetVersion,
        SchemaDefinition $definition,
        SchemaInspection $inspection,
        SchemaValidationResult $validation
    ): bool {
        if (!$inspection->anyDefinedTable($definition)) {
            return false;
        }

        return $installedVersion !== $targetVersion || !$validation->isValid();
    }

    public function runtimeReason(
        string $installedVersion,
        string $targetVersion,
        SchemaValidationResult $validation
    ): string {
        if ($installedVersion !== $targetVersion) {
            return 'schema_rebuild_required';
        }
        return $validation->isValid() ? '' : 'database_schema_incomplete';
    }

    /** @return array<int,string> */
    public function runtimeIssues(
        string $installedVersion,
        string $targetVersion,
        SchemaValidationResult $validation
    ): array {
        if ($installedVersion !== $targetVersion) {
            return array('schema_version:' . ($installedVersion !== '' ? $installedVersion : 'missing'));
        }
        return $validation->issues();
    }
}
