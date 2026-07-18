<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

final class SchemaValidationResult
{
    /** @var SchemaDiff */ private $diff;

    public function __construct(SchemaDiff $diff)
    {
        $this->diff = $diff;
    }

    public function isValid(): bool
    {
        return $this->diff->isClean();
    }

    /** @return array<int,string> */
    public function issues(): array
    {
        return $this->diff->issueCodes();
    }
}
