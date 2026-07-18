<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use RuntimeException;

/** Internal rollback signal for a denied multi-scope decision. */
final class RateLimitAdmissionDenied extends RuntimeException
{
    /** @var int */
    private $retryAfter;

    public function __construct(int $retryAfter)
    {
        parent::__construct('Rate-limit admission denied.');
        $this->retryAfter = max(1, $retryAfter);
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
