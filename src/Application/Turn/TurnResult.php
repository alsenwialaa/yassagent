<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use InvalidArgumentException;

final class TurnResult
{
    /** @var array<string,mixed> */ private $message;
    /** @var bool */ private $committed;
    /** @param array<string,mixed> $message */
    public function __construct(array $message, bool $committed)
    {
        if (trim((string) ($message['text'] ?? '')) === '') {
            throw new InvalidArgumentException('A processed turn must expose nonblank server-safe text.');
        }
        $this->message = $message;
        $this->committed = $committed;
    }

    /** @return array<string,mixed> */ public function message(): array
    {
        return $this->message;
    }
    public function isCommitted(): bool
    {
        return $this->committed;
    }
}
