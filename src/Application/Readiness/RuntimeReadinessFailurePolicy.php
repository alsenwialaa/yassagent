<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Readiness;

use InvalidArgumentException;

/**
 * Closed policy for failures that actually contradict the cached minimal
 * provider proof.
 *
 * Ordinary shopper turns may revoke readiness only for deterministic provider
 * or configuration failures. A manual runtime probe may additionally revoke a
 * proof when the exact two-step contract itself is rejected or decoded into an
 * invalid result. Network, quota, timeout, response-size, and temporary
 * upstream-availability errors never revoke an otherwise unexpired proof.
 */
final class RuntimeReadinessFailurePolicy
{
    private const PROVIDER_CONTRADICTIONS = array(
        'api_key_missing',
        'model_invalid',
        'authentication_error',
        'model_not_found',
        'provider_service_disabled',
        'provider_billing_disabled',
    );

    private const PROBE_CONTRADICTIONS = array(
        'runtime_probe_access_contract_rejected',
        'runtime_probe_tool_contract_rejected',
        'runtime_probe_access_precondition_rejected',
        'runtime_probe_tool_precondition_rejected',
        'runtime_probe_access_response_invalid',
        'runtime_probe_structured_tool_invalid',
    );

    private const PROBE_TRANSIENT_FAILURES = array(
        'runtime_probe_timeout',
        'runtime_probe_network_error',
        'runtime_probe_rate_limited',
        'runtime_probe_upstream_unavailable',
        'runtime_probe_response_too_large',
        'runtime_probe_upstream_rejected',
        'runtime_probe_upstream_unknown',
        'runtime_probe_provider_access_failed',
        'runtime_probe_structured_tool_failed',
        'runtime_probe_interrupted',
    );

    public static function contradictsProof(string $code): bool
    {
        return in_array($code, self::PROVIDER_CONTRADICTIONS, true);
    }

    public static function probeFailureContradictsProof(string $code): bool
    {
        return self::contradictsProof($code)
            || in_array($code, self::PROBE_CONTRADICTIONS, true);
    }

    public static function isProbeFailure(string $code): bool
    {
        return self::probeFailureContradictsProof($code)
            || in_array($code, self::PROBE_TRANSIENT_FAILURES, true);
    }

    public static function requireProbeFailure(string $code): string
    {
        if (!self::isProbeFailure($code)) {
            throw new InvalidArgumentException('Runtime probe failure code is outside the closed policy.');
        }
        return $code;
    }

    public static function requireContradiction(string $code): string
    {
        if (!self::contradictsProof($code)) {
            throw new InvalidArgumentException(
                'Runtime readiness can be invalidated only by a deterministic provider/configuration contradiction.'
            );
        }
        return $code;
    }

    /** @return array<int,string> */
    public static function contradictionCodes(): array
    {
        return self::PROVIDER_CONTRADICTIONS;
    }

    /** @return array<int,string> */
    public static function probeContradictionCodes(): array
    {
        return array_merge(self::PROVIDER_CONTRADICTIONS, self::PROBE_CONTRADICTIONS);
    }

    /** @return array<int,string> */
    public static function probeFailureCodes(): array
    {
        return array_merge(
            self::PROVIDER_CONTRADICTIONS,
            self::PROBE_CONTRADICTIONS,
            self::PROBE_TRANSIENT_FAILURES
        );
    }

    private function __construct()
    {
    }
}
