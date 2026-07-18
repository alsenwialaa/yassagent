<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Domain\Exception\ContractViolation;

final class ToolContract
{
    public const READ = 'read';
    public const STATE = 'state';
    public const MUTATION = 'mutation';
    public const TERMINAL = 'terminal';

    /** @var string */ private $name;
    /** @var string */ private $description;
    /** @var array<string,mixed> */ private $schema;
    /** @var string */ private $kind;

    /** @param array<string,mixed> $schema */
    public function __construct(string $name, string $description, array $schema, string $kind = self::READ)
    {
        $name = trim($name);
        $description = trim($description);
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name) !== 1) {
            throw new ContractViolation('tool_name_invalid', 'Tool names must be stable lowercase identifiers of at most 64 characters.');
        }
        if ($description === '' || strlen($description) > 2048) {
            throw new ContractViolation('tool_description_invalid', 'Tool descriptions must be nonblank and bounded.');
        }
        if (!in_array($kind, array(self::READ, self::STATE, self::MUTATION, self::TERMINAL), true)) {
            throw new ContractViolation('tool_kind_invalid', 'The tool kind is invalid.');
        }
        if (($schema['type'] ?? '') !== 'object') {
            throw new ContractViolation('tool_schema_invalid', 'Every tool argument schema must be a JSON object.');
        }
        $this->name = $name;
        $this->description = $description;
        $this->schema = $schema;
        $this->kind = $kind;
    }

    public function name(): string
    {
        return $this->name;
    }
    public function kind(): string
    {
        return $this->kind;
    }
    /** @return array<string,mixed> */ public function schema(): array
    {
        return $this->schema;
    }

    /** @return array<string,mixed> */
    public function modelDeclaration(): array
    {
        return array(
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->schema,
        );
    }
}
