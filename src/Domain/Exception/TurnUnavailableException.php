<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Exception;

use RuntimeException;

final class TurnUnavailableException extends RuntimeException
{
    /** @var string */ private $reasonCode;
    /** @var string */ private $safeMessage;
    /** @var int */ private $httpStatus;
    /** @var int */ private $retryAfter;

    public function __construct(
        string $reasonCode,
        string $safeMessage,
        int $httpStatus = 409,
        int $retryAfter = 2,
        string $internalMessage = ''
    ) {
        parent::__construct($internalMessage !== '' ? $internalMessage : $reasonCode);
        $this->reasonCode = $reasonCode;
        $this->safeMessage = trim($safeMessage);
        $this->httpStatus = max(400, min(599, $httpStatus));
        $this->retryAfter = max(0, min(300, $retryAfter));
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
    public function safeMessage(): string
    {
        return $this->safeMessage;
    }
    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
