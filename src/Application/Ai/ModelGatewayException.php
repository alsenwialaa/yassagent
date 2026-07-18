<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

use InvalidArgumentException;
use RuntimeException;

/** Provider-neutral customer-safe model transport failure. */
class ModelGatewayException extends RuntimeException
{
    private const MAX_SAFE_MESSAGE_BYTES = 4096;

    /** @var string */ private $safeMessage;
    /** @var string */ private $reasonCode;

    public function __construct(string $reasonCode, string $safeMessage, string $internalMessage = '')
    {
        $reasonCode = trim($reasonCode);
        $safeMessage = trim($safeMessage);
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Model gateway failure code is invalid.');
        }
        if ($safeMessage === '' || strlen($safeMessage) > self::MAX_SAFE_MESSAGE_BYTES) {
            throw new InvalidArgumentException('Model gateway safe failure text is blank or too large.');
        }

        parent::__construct($internalMessage !== '' ? $internalMessage : $safeMessage);
        $this->reasonCode = $reasonCode;
        $this->safeMessage = $safeMessage;
    }

    public function safeMessage(): string
    {
        return $this->safeMessage;
    }
    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
