<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Readiness\RuntimeReadinessFailurePolicy;

/** Maps provider failures into closed, durable runtime-probe failure codes. */
final class RuntimeProbeFailureMapper
{
    public const PROVIDER_ACCESS = 'provider_access';
    public const STRUCTURED_TOOL = 'structured_tool';

    public static function code(string $stage, string $providerCode): string
    {
        if (!in_array($stage, array(self::PROVIDER_ACCESS, self::STRUCTURED_TOOL), true)) {
            throw new InvalidArgumentException('Runtime-readiness probe stage is invalid.');
        }
        if (RuntimeReadinessFailurePolicy::contradictsProof($providerCode)) {
            return $providerCode;
        }

        $shared = array(
            'provider_timeout' => 'runtime_probe_timeout',
            'network_error' => 'runtime_probe_network_error',
            'rate_limited' => 'runtime_probe_rate_limited',
            'upstream_unavailable' => 'runtime_probe_upstream_unavailable',
            'upstream_payload_too_large' => 'runtime_probe_response_too_large',
            'upstream_rejected' => 'runtime_probe_upstream_rejected',
            'unknown_upstream_error' => 'runtime_probe_upstream_unknown',
        );
        if (isset($shared[$providerCode])) {
            return $shared[$providerCode];
        }

        if ($providerCode === 'request_contract_rejected') {
            return $stage === self::PROVIDER_ACCESS
                ? 'runtime_probe_access_contract_rejected'
                : 'runtime_probe_tool_contract_rejected';
        }
        if ($providerCode === 'request_precondition_rejected') {
            return $stage === self::PROVIDER_ACCESS
                ? 'runtime_probe_access_precondition_rejected'
                : 'runtime_probe_tool_precondition_rejected';
        }
        if ($providerCode === 'upstream_payload_invalid') {
            return $stage === self::PROVIDER_ACCESS
                ? 'runtime_probe_access_response_invalid'
                : 'runtime_probe_structured_tool_invalid';
        }

        return $stage === self::PROVIDER_ACCESS
            ? 'runtime_probe_provider_access_failed'
            : 'runtime_probe_structured_tool_failed';
    }

    private function __construct()
    {
    }
}
