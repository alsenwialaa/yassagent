<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

/** Closed persistence and timing policy for the administrative runtime proof. */
final class RuntimeReadinessPolicy
{
    public const STATE_SCHEMA = 2;
    public const READY_TTL_SECONDS = 2592000; // 30 days; live contradictions revoke it immediately.
    public const CLOCK_SKEW_SECONDS = 60;
    public const TRANSITION_LOCK_WAIT_SECONDS = 5;

    private function __construct()
    {
    }
}
