<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Exception;

use RuntimeException;

final class ExecutionBudgetException extends RuntimeException
{
    /** @var string */ private $boundary;

    public function __construct(string $boundary, string $message = 'Turn execution budget is exhausted.')
    {
        parent::__construct($message);
        $this->boundary = $boundary;
    }

    public function boundary(): string
    {
        return $this->boundary;
    }
}
