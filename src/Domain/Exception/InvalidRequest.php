<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Exception;

use RuntimeException;

final class InvalidRequest extends RuntimeException
{
    /** @var string */
    private $reasonCode;

    /** @var string */
    private $safeMessage;

    /** @var int */
    private $httpStatus;

    public function __construct(
        string $reasonCode,
        string $safeMessage,
        string $internalMessage = '',
        int $httpStatus = 400
    ) {
        parent::__construct($internalMessage !== '' ? $internalMessage : $reasonCode);
        $this->reasonCode = $reasonCode;
        $this->safeMessage = trim($safeMessage);
        $this->httpStatus = max(400, min(499, $httpStatus));
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
}
