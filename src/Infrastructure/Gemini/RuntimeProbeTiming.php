<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

/** Bounded timing policy for the two-request administrative runtime probe. */
final class RuntimeProbeTiming
{
    private const MAX_PROVIDER_REQUEST_SECONDS = 20;
    private const LOCAL_WORK_GRACE_SECONDS = 10;
    private const INTERRUPTED_PROCESS_GRACE_SECONDS = 15;
    private const CLIENT_RESPONSE_GRACE_SECONDS = 15;

    public static function providerRequestSeconds(int $configuredSeconds): int
    {
        return max(1, min(self::MAX_PROVIDER_REQUEST_SECONDS, $configuredSeconds));
    }

    public static function maximumExecutionSeconds(int $configuredSeconds): int
    {
        return (RuntimeProbeContract::REQUEST_COUNT * self::providerRequestSeconds($configuredSeconds))
            + self::LOCAL_WORK_GRACE_SECONDS;
    }

    public static function staleAfterSeconds(int $configuredSeconds): int
    {
        return self::maximumExecutionSeconds($configuredSeconds)
            + self::INTERRUPTED_PROCESS_GRACE_SECONDS;
    }

    public static function clientTimeoutMilliseconds(int $configuredSeconds): int
    {
        return (self::staleAfterSeconds($configuredSeconds)
            + self::CLIENT_RESPONSE_GRACE_SECONDS) * 1000;
    }
}
