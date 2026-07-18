<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use RuntimeException;
use Throwable;

class SchemaException extends RuntimeException
{
    /** @var string */ private $reason;
    /** @var array<int,string> */ private $issues;

    /** @param array<int,string> $issues */
    public function __construct(string $reason, string $message, array $issues = array(), ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->reason = $reason;
        $this->issues = array_values($issues);
    }

    public function reasonCode(): string
    {
        return $this->reason;
    }

    /** @return array<int,string> */
    public function issues(): array
    {
        return $this->issues;
    }
}
