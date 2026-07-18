<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

use RuntimeException;

final class ModelProtocolException extends RuntimeException
{
    /** @var string */
    private $reasonCode;

    public function __construct(string $reasonCode, string $message)
    {
        parent::__construct($message);
        $this->reasonCode = trim($reasonCode) !== '' ? trim($reasonCode) : 'model_protocol_error';
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
