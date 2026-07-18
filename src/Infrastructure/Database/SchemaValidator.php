<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

final class SchemaValidator
{
    /** @var SchemaDiffer */ private $differ;

    public function __construct(?SchemaDiffer $differ = null)
    {
        $this->differ = $differ ?: new SchemaDiffer();
    }

    public function validate(SchemaDefinition $definition, SchemaInspection $inspection): SchemaValidationResult
    {
        return new SchemaValidationResult($this->differ->diff($definition, $inspection));
    }
}
