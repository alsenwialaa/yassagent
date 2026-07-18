<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Exception;

use RuntimeException;

final class OperationPendingException extends RuntimeException
{
    /** @var string */ private $reasonCode;
    /** @var string */ private $safeMessage;

    public function __construct(string $reasonCode, string $safeMessage, string $internalMessage = '')
    {
        parent::__construct($internalMessage !== '' ? $internalMessage : $reasonCode);
        $this->reasonCode = $reasonCode;
        $this->safeMessage = trim($safeMessage);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
    public function safeMessage(): string
    {
        return $this->safeMessage;
    }
}
