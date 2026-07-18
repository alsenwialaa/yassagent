<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Execution;

/**
 * One server-owned timing policy for execution, browser transport, and exact
 * replay retention. The browser receives the derived millisecond values from
 * Widget; it must not guess a shorter mutation window.
 */
final class TurnExecutionPolicy
{
    private const MIN_EXECUTION_SECONDS = 90;
    private const MAX_EXECUTION_SECONDS = 1500;
    private const SEMANTIC_VERIFIER_INVOCATIONS = 2;
    private const SEMANTIC_VERIFIER_ATTEMPTS = 2;
    private const CLIENT_RESPONSE_GRACE_SECONDS = 60;
    private const RETRY_AFTER_DEADLINE_SECONDS = 540;

    public static function executionSeconds(int $httpTimeoutSeconds, int $maxToolRounds): int
    {
        $timeout = max(1, min(90, $httpTimeoutSeconds));
        return max(
            self::MIN_EXECUTION_SECONDS,
            min(
                self::MAX_EXECUTION_SECONDS,
                ($timeout * self::maxProviderRequests($maxToolRounds)) + 120
            )
        );
    }

    /**
     * One turn may use every primary model round plus two independently
     * checked semantic proposals, each with one bounded malformed-output retry.
     */
    public static function maxProviderRequests(int $maxToolRounds): int
    {
        $rounds = max(1, min(10, $maxToolRounds));
        return $rounds
            + (self::SEMANTIC_VERIFIER_INVOCATIONS * self::SEMANTIC_VERIFIER_ATTEMPTS);
    }

    public static function clientDeadlineMilliseconds(int $httpTimeoutSeconds, int $maxToolRounds): int
    {
        return 1000 * (
            self::executionSeconds($httpTimeoutSeconds, $maxToolRounds)
            + self::CLIENT_RESPONSE_GRACE_SECONDS
        );
    }

    public static function retryRetentionMilliseconds(int $httpTimeoutSeconds, int $maxToolRounds): int
    {
        return self::clientDeadlineMilliseconds($httpTimeoutSeconds, $maxToolRounds)
            + (1000 * self::RETRY_AFTER_DEADLINE_SECONDS);
    }
}
