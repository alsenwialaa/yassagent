<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use RuntimeException;

/** A fresh administrator probe already owns the one runtime-readiness attempt. */
final class RuntimeProbeInProgress extends RuntimeException
{
    /** @var int */ private $retryAfterSeconds;

    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct('A Gemini runtime-readiness check is already in progress.');
        $this->retryAfterSeconds = max(1, $retryAfterSeconds);
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
