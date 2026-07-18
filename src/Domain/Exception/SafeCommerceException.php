<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Exception;

use RuntimeException;

final class SafeCommerceException extends RuntimeException
{
    /** @var string */
    private $reasonCode;

    /** @var string */
    private $safeMessage;

    /** @var bool */
    private $stateMayHaveChanged;

    public function __construct(
        string $reasonCode,
        string $safeMessage,
        string $internalMessage = '',
        bool $stateMayHaveChanged = false
    ) {
        parent::__construct($internalMessage !== '' ? $internalMessage : $safeMessage);
        $this->reasonCode = $reasonCode;
        $this->safeMessage = $safeMessage;
        $this->stateMayHaveChanged = $stateMayHaveChanged;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function safeMessage(): string
    {
        return $this->safeMessage;
    }

    public function stateMayHaveChanged(): bool
    {
        return $this->stateMayHaveChanged;
    }
}
