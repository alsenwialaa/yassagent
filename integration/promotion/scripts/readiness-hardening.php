<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Gemini\RuntimeReadinessPolicy;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "WordPress must be loaded.\n");
    exit(1);
}

/** @return array<string,mixed> */
function ysai_promotion_fake_request(string $method, string $path, array $body = array()): array
{
    $base = rtrim((string) getenv('YSAI_FAKE_GEMINI_URL'), '/');
    $token = (string) getenv('YSAI_TEST_CONTROL_TOKEN');
    if ($base === '' || $token === '') {
        WP_CLI::error('Fake-provider control authority is missing from the promotion environment.');
    }

    $response = wp_remote_request($base . $path, array(
        'method' => $method,
        'timeout' => 10,
        'redirection' => 0,
        'reject_unsafe_urls' => false,
        'headers' => array(
            'Content-Type' => 'application/json',
            'x-ysai-test-token' => $token,
        ),
        'body' => $body === array() ? '' : wp_json_encode($body),
    ));
    if (is_wp_error($response)) {
        WP_CLI::error('Fake-provider control request failed: ' . $response->get_error_code());
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        WP_CLI::error('Fake-provider control request was rejected: HTTP ' . $status);
    }
    return $decoded;
}

function ysai_promotion_reset_provider(string $scenario): void
{
    $result = ysai_promotion_fake_request('POST', '/control/reset', array(
        'scenario' => $scenario,
        'options' => array(),
    ));
    if (($result['ok'] ?? false) !== true || ($result['scenario'] ?? '') !== $scenario) {
        WP_CLI::error('Fake-provider scenario reset did not take effect.');
    }
}

/** @return array{status:int,data:array<string,mixed>} */
function ysai_promotion_admin_readiness(): array
{
    $request = new WP_REST_Request('POST', '/yassin-ai/v1/admin/test');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body('{}');
    $response = rest_do_request($request);
    $data = $response->get_data();
    return array(
        'status' => (int) $response->get_status(),
        'data' => is_array($data) ? $data : array(),
    );
}

/** @return array<string,mixed> */
function ysai_promotion_readiness_state(): array
{
    wp_cache_delete('ysai_runtime_readiness', 'options');
    $state = get_option('ysai_runtime_readiness', array());
    if (!is_array($state)) {
        WP_CLI::error('Runtime-readiness state is not an array.');
    }
    return $state;
}

$initial = ysai_promotion_readiness_state();
$initialCheckedAt = (int) ($initial['proof_checked_at'] ?? 0);
$initialExpiresAt = (int) ($initial['proof_expires_at'] ?? 0);
if (($initial['schema'] ?? null) !== RuntimeReadinessPolicy::STATE_SCHEMA
    || $initialCheckedAt <= 0
    || $initialExpiresAt !== $initialCheckedAt + RuntimeReadinessPolicy::READY_TTL_SECONDS
    || (string) ($initial['check_attempt_id'] ?? '') !== ''
) {
    WP_CLI::error('The initial provider proof is not a closed ready state.');
}

ysai_promotion_reset_provider('runtime_access_unavailable');
$transient = ysai_promotion_admin_readiness();
$afterTransient = ysai_promotion_readiness_state();
$transientCode = (string) ($transient['data']['code'] ?? '');
$proofPreserved = (int) ($afterTransient['proof_checked_at'] ?? 0) === $initialCheckedAt
    && (int) ($afterTransient['proof_expires_at'] ?? 0) === $initialExpiresAt;
if ($transient['status'] !== 503
    || $transientCode !== 'runtime_probe_upstream_unavailable'
    || !$proofPreserved
    || (string) ($afterTransient['last_failure_code'] ?? '') !== $transientCode
    || (string) ($afterTransient['check_attempt_id'] ?? '') !== ''
) {
    WP_CLI::error('Transient readiness failure did not preserve the existing proof: '
        . wp_json_encode(array('response' => $transient, 'state' => $afterTransient)));
}

ysai_promotion_reset_provider('runtime_access_authentication');
$deterministic = ysai_promotion_admin_readiness();
$afterDeterministic = ysai_promotion_readiness_state();
$deterministicCode = (string) ($deterministic['data']['code'] ?? '');
$proofRevoked = (int) ($afterDeterministic['proof_checked_at'] ?? -1) === 0
    && (int) ($afterDeterministic['proof_expires_at'] ?? -1) === 0;
if ($deterministic['status'] !== 422
    || $deterministicCode !== 'authentication_error'
    || !$proofRevoked
    || (string) ($afterDeterministic['last_failure_code'] ?? '') !== $deterministicCode
    || (string) ($afterDeterministic['check_attempt_id'] ?? '') !== ''
) {
    WP_CLI::error('Deterministic provider contradiction did not revoke the proof: '
        . wp_json_encode(array('response' => $deterministic, 'state' => $afterDeterministic)));
}

ysai_promotion_reset_provider('answer');
$recovery = ysai_promotion_admin_readiness();
$afterRecovery = ysai_promotion_readiness_state();
$providerState = ysai_promotion_fake_request('GET', '/control/state');
$providerCalls = isset($providerState['calls']) && is_array($providerState['calls'])
    ? count($providerState['calls'])
    : -1;
$recoveryReady = $recovery['status'] === 200
    && ($recovery['data']['ok'] ?? false) === true
    && (int) ($recovery['data']['result']['provider_requests'] ?? 0) === 2
    && (int) ($afterRecovery['proof_checked_at'] ?? 0) > 0
    && (int) ($afterRecovery['proof_expires_at'] ?? 0)
        === (int) ($afterRecovery['proof_checked_at'] ?? 0) + RuntimeReadinessPolicy::READY_TTL_SECONDS
    && (string) ($afterRecovery['last_failure_code'] ?? '') === ''
    && (string) ($afterRecovery['check_attempt_id'] ?? '') === '';
if (!$recoveryReady || $providerCalls !== 2) {
    WP_CLI::error('Readiness recovery did not publish a fresh two-request proof: '
        . wp_json_encode(array(
            'response' => $recovery,
            'state' => $afterRecovery,
            'provider_calls' => $providerCalls,
        )));
}

echo wp_json_encode(array(
    'ok' => true,
    'state_schema' => RuntimeReadinessPolicy::STATE_SCHEMA,
    'proof_ttl_seconds' => RuntimeReadinessPolicy::READY_TTL_SECONDS,
    'transient_http_status' => $transient['status'],
    'transient_code' => $transientCode,
    'proof_preserved' => $proofPreserved,
    'proof_checked_at_unchanged' => (int) ($afterTransient['proof_checked_at'] ?? 0) === $initialCheckedAt,
    'proof_expires_at_unchanged' => (int) ($afterTransient['proof_expires_at'] ?? 0) === $initialExpiresAt,
    'deterministic_http_status' => $deterministic['status'],
    'deterministic_code' => $deterministicCode,
    'proof_revoked' => $proofRevoked,
    'recovery_http_status' => $recovery['status'],
    'recovery_provider_requests' => $providerCalls,
    'ready_after_recovery' => $recoveryReady,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
