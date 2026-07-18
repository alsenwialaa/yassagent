<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Exception;

use RuntimeException;

final class ContractViolation extends RuntimeException
{
    /** @var string */
    private $reasonCode;

    public function __construct(string $reasonCode, string $message)
    {
        parent::__construct($message);
        $this->reasonCode = $reasonCode;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
