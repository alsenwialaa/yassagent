<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use RuntimeException;
use YassinStore\AiAssistant\Application\Port\ClockPort;
use YassinStore\AiAssistant\Application\Port\RuntimeReadinessPort;
use YassinStore\AiAssistant\Application\Readiness\RuntimeReadinessFailurePolicy;
use YassinStore\AiAssistant\Infrastructure\Database\AdvisoryLock;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaRegistry;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;

/**
 * Cached evidence for the deliberately small Gemini runtime compatibility probe.
 *
 * One state record keeps an unexpired successful proof separate from the one
 * active administrative attempt. A recheck therefore cannot take an otherwise
 * ready storefront offline, and a transient recheck failure cannot revoke a
 * proof it does not contradict. Fresh concurrent checks are rejected; only a
 * stale or configuration-old attempt may be superseded.
 */
final class GeminiRuntimeReadiness implements RuntimeReadinessPort
{
    public const OPTION_KEY = 'ysai_runtime_readiness';

    /** @var Settings */ private $settings;
    /** @var RuntimeReadinessStateStore */ private $store;
    /** @var ClockPort */ private $clock;

    public function __construct(
        Settings $settings,
        ClockPort $clock,
        ?RuntimeReadinessStateStore $store = null
    ) {
        $this->settings = $settings;
        $this->clock = $clock;
        $this->store = $store !== null ? $store : new RuntimeReadinessStateStore(self::OPTION_KEY);
    }

    public function isReady(): bool
    {
        return (bool) $this->status()['ready'];
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        if (!(bool) $this->settings->get('enabled', 1)) {
            return $this->report(false, 'disabled', array(), 0);
        }
        if ($this->settings->apiKey() === '') {
            return $this->report(false, 'api_key_missing', array(), 0);
        }

        $now = $this->now();
        $state = $this->store->read();
        if (!$this->validState($state, $now)) {
            return $this->report(false, 'runtime_check_required', array(), 0);
        }
        if (!$this->matchesCurrentConfiguration($state)) {
            return $this->report(false, 'runtime_configuration_changed', array(), 0);
        }

        $proofReady = $this->proofIsReady($state, $now);
        if ((string) $state['check_attempt_id'] !== '') {
            $staleAt = (int) $state['check_started_at'] + $this->checkStaleAfterSeconds();
            if ($staleAt > $now) {
                return $this->report(
                    $proofReady,
                    $proofReady ? 'ready_recheck_in_progress' : 'runtime_check_in_progress',
                    $state,
                    $staleAt - $now
                );
            }
            return $this->report(
                $proofReady,
                $proofReady ? 'ready_recheck_interrupted' : 'runtime_check_interrupted',
                $state,
                0
            );
        }

        if ($proofReady) {
            return $this->report(
                true,
                (string) $state['last_failure_code'] !== '' ? 'ready_with_probe_failure' : 'ready',
                $state,
                0
            );
        }
        if ((int) $state['proof_checked_at'] > 0) {
            return $this->report(false, 'runtime_check_expired', $state, 0);
        }
        if ((string) $state['last_failure_code'] !== '') {
            return $this->report(false, (string) $state['last_failure_code'], $state, 0);
        }

        return $this->report(false, 'runtime_check_required', $state, 0);
    }

    /**
     * Begin the only fresh administrative check for the current configuration.
     *
     * A current unexpired proof is retained while the check is running. This is
     * a compatibility proof, not a live uptime monitor, so a manual recheck must
     * not take admitted shopper traffic offline.
     *
     * @throws RuntimeProbeInProgress when another non-stale check is active.
     */
    public function beginCheck(): string
    {
        return $this->withTransitionLock(function (): string {
            $now = $this->now();
            $current = $this->store->readFresh();
            $sameConfiguration = $this->validState($current, $now)
                && $this->matchesCurrentConfiguration($current);

            if ($sameConfiguration && (string) $current['check_attempt_id'] !== '') {
                $staleAt = (int) $current['check_started_at'] + $this->checkStaleAfterSeconds();
                if ($staleAt > $now) {
                    throw new RuntimeProbeInProgress($staleAt - $now);
                }
            }

            $proofCheckedAt = $sameConfiguration && $this->proofIsReady($current, $now)
                ? (int) $current['proof_checked_at']
                : 0;
            $proofExpiresAt = $proofCheckedAt > 0
                ? (int) $current['proof_expires_at']
                : 0;
            $attemptId = bin2hex(random_bytes(16));
            $this->store->writeExact($this->state(
                $proofCheckedAt,
                $proofExpiresAt,
                $now,
                $attemptId,
                '',
                0
            ));
            return $attemptId;
        });
    }

    /** Stop a superseded/configuration-changed attempt before another provider request. */
    public function assertCurrentAttempt(string $attemptId): void
    {
        $this->requireCurrentAttemptState($attemptId, $this->now());
    }

    public function markReady(string $attemptId): void
    {
        $this->withTransitionLock(function () use ($attemptId): void {
            $now = $this->now();
            $this->requireCurrentAttemptState($attemptId, $now);
            $this->store->writeExact($this->state(
                $now,
                $now + RuntimeReadinessPolicy::READY_TTL_SECONDS,
                0,
                '',
                '',
                0
            ));
        });
    }

    public function markFailed(string $code, string $attemptId): void
    {
        $code = RuntimeReadinessFailurePolicy::requireProbeFailure($code);
        $this->withTransitionLock(function () use ($code, $attemptId): void {
            $now = $this->now();
            $current = $this->requireCurrentAttemptState($attemptId, $now);
            $contradictsProof = RuntimeReadinessFailurePolicy::probeFailureContradictsProof($code);
            $preserveProof = $this->proofIsReady($current, $now) && !$contradictsProof;
            $this->store->writeExact($this->state(
                $preserveProof ? (int) $current['proof_checked_at'] : 0,
                $preserveProof ? (int) $current['proof_expires_at'] : 0,
                0,
                '',
                $code,
                $now
            ), $contradictsProof);
        });
    }

    public function invalidate(string $code): void
    {
        $code = RuntimeReadinessFailurePolicy::requireContradiction($code);
        $this->withTransitionLock(function () use ($code): void {
            $this->store->writeExact(
                $this->state(0, 0, 0, '', $code, $this->now()),
                true
            );
        });
    }

    public static function deleteState(): void
    {
        self::withStaticTransitionLock(static function (): void {
            (new RuntimeReadinessStateStore(self::OPTION_KEY))->deleteExact();
        });
    }

    /**
     * @template T
     * @param callable():T $transition
     * @return T
     */
    private function withTransitionLock(callable $transition)
    {
        return self::withStaticTransitionLock($transition);
    }

    /**
     * @template T
     * @param callable():T $transition
     * @return T
     */
    private static function withStaticTransitionLock(callable $transition)
    {
        global $wpdb;
        $lock = new AdvisoryLock($wpdb, 'runtime_ready', SchemaRegistry::scopeKey());
        if (!$lock->acquire(RuntimeReadinessPolicy::TRANSITION_LOCK_WAIT_SECONDS)) {
            throw new RuntimeException('Unable to serialize Gemini runtime-readiness state.');
        }
        try {
            return $transition();
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    private function state(
        int $proofCheckedAt,
        int $proofExpiresAt,
        int $checkStartedAt,
        string $checkAttemptId,
        string $lastFailureCode,
        int $lastFailureAt
    ): array {
        return array(
            'schema' => RuntimeReadinessPolicy::STATE_SCHEMA,
            'fingerprint' => $this->fingerprint(),
            'proof_checked_at' => $proofCheckedAt,
            'proof_expires_at' => $proofExpiresAt,
            'check_started_at' => $checkStartedAt,
            'check_attempt_id' => $checkAttemptId,
            'last_failure_code' => $lastFailureCode,
            'last_failure_at' => $lastFailureAt,
            'model' => Settings::GEMINI_MODEL,
            'probe_contract' => RuntimeProbeContract::REVISION,
            'checks' => RuntimeProbeContract::CHECKS,
            'provider_requests' => RuntimeProbeContract::REQUEST_COUNT,
            'proof_ttl_seconds' => RuntimeReadinessPolicy::READY_TTL_SECONDS,
        );
    }

    /** @param mixed $state */
    private function validState($state, int $now): bool
    {
        if (!is_array($state) || ($state !== array() && Arr::isList($state))) {
            return false;
        }
        $keys = array_keys($state);
        sort($keys);
        if (
            $keys !== array(
            'check_attempt_id',
            'check_started_at',
            'checks',
            'fingerprint',
            'last_failure_at',
            'last_failure_code',
            'model',
            'probe_contract',
            'proof_checked_at',
            'proof_expires_at',
            'proof_ttl_seconds',
            'provider_requests',
            'schema',
            )
        ) {
            return false;
        }
        if (
            $state['schema'] !== RuntimeReadinessPolicy::STATE_SCHEMA
            || !is_string($state['fingerprint'])
            || preg_match('/^[a-f0-9]{64}$/D', $state['fingerprint']) !== 1
            || !is_int($state['proof_checked_at'])
            || !is_int($state['proof_expires_at'])
            || !is_int($state['check_started_at'])
            || !is_string($state['check_attempt_id'])
            || !is_string($state['last_failure_code'])
            || !is_int($state['last_failure_at'])
            || !is_string($state['model'])
            || !hash_equals(Settings::GEMINI_MODEL, $state['model'])
            || !is_string($state['probe_contract'])
            || !hash_equals(RuntimeProbeContract::REVISION, $state['probe_contract'])
            || !is_array($state['checks'])
            || $state['checks'] !== RuntimeProbeContract::CHECKS
            || $state['provider_requests'] !== RuntimeProbeContract::REQUEST_COUNT
            || $state['proof_ttl_seconds'] !== RuntimeReadinessPolicy::READY_TTL_SECONDS
        ) {
            return false;
        }

        $proofAbsent = $state['proof_checked_at'] === 0 && $state['proof_expires_at'] === 0;
        $proofValid = $state['proof_checked_at'] > 0
            && $state['proof_checked_at'] <= $now + RuntimeReadinessPolicy::CLOCK_SKEW_SECONDS
            && $state['proof_expires_at'] === $state['proof_checked_at']
                + RuntimeReadinessPolicy::READY_TTL_SECONDS;
        if (!$proofAbsent && !$proofValid) {
            return false;
        }

        $checkAbsent = $state['check_started_at'] === 0 && $state['check_attempt_id'] === '';
        $checkValid = $state['check_started_at'] > 0
            && $state['check_started_at'] <= $now + RuntimeReadinessPolicy::CLOCK_SKEW_SECONDS
            && preg_match('/^[a-f0-9]{32}$/D', $state['check_attempt_id']) === 1;
        if (!$checkAbsent && !$checkValid) {
            return false;
        }

        $failureAbsent = $state['last_failure_code'] === '' && $state['last_failure_at'] === 0;
        $failureValid = RuntimeReadinessFailurePolicy::isProbeFailure($state['last_failure_code'])
            && $state['last_failure_at'] > 0
            && $state['last_failure_at'] <= $now + RuntimeReadinessPolicy::CLOCK_SKEW_SECONDS;
        if (!$failureAbsent && !$failureValid) {
            return false;
        }

        // A deterministic contradiction and a retained proof are mutually
        // exclusive. Reject impossible/corrupt combinations rather than trying
        // to infer which field should win.
        return $proofAbsent
            || !RuntimeReadinessFailurePolicy::probeFailureContradictsProof(
                (string) $state['last_failure_code']
            );
    }

    /** @param array<string,mixed> $state */
    private function matchesCurrentConfiguration(array $state): bool
    {
        return hash_equals((string) $state['fingerprint'], $this->fingerprint());
    }

    /** @param array<string,mixed> $state */
    private function proofIsReady(array $state, int $now): bool
    {
        return (int) $state['proof_checked_at'] > 0
            && (int) $state['proof_expires_at'] > $now;
    }

    /** @return array<string,mixed> */
    private function requireCurrentAttemptState(string $attemptId, int $now): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $attemptId) !== 1) {
            throw new RuntimeProbeSuperseded(
                'Gemini runtime-check attempt identity is no longer current.'
            );
        }

        $state = $this->store->readFresh();
        if (
            !$this->validState($state, $now)
            || !$this->matchesCurrentConfiguration($state)
            || !hash_equals((string) $state['check_attempt_id'], $attemptId)
            || (int) $state['check_started_at'] + $this->checkStaleAfterSeconds() <= $now
        ) {
            throw new RuntimeProbeSuperseded(
                'Gemini runtime-check attempt is no longer current.'
            );
        }
        return $state;
    }

    private function checkStaleAfterSeconds(): int
    {
        return RuntimeProbeTiming::staleAfterSeconds(
            (int) $this->settings->get('http_timeout_seconds', 30)
        );
    }

    private function fingerprint(): string
    {
        $thinkingLevel = (string) $this->settings->get('gemini_thinking_level', 'low');
        return hash('sha256', Json::canonical(array(
            'api_key' => hash('sha256', $this->settings->apiKey()),
            'model' => Settings::GEMINI_MODEL,
            'endpoint' => GeminiEndpoint::configured()->fingerprint(),
            'configuration_epoch' => $this->settings->runtimeConfigurationEpoch(),
            'probe_contract' => RuntimeProbeContract::fingerprint($thinkingLevel),
        )));
    }

    private function now(): int
    {
        return $this->clock->now();
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function report(bool $ready, string $code, array $state, int $retryAfter): array
    {
        return array(
            'ready' => $ready,
            'code' => $code,
            'checked_at' => (int) ($state['proof_checked_at'] ?? 0),
            'expires_at' => (int) ($state['proof_expires_at'] ?? 0),
            'check_started_at' => (int) ($state['check_started_at'] ?? 0),
            'retry_after' => max(0, $retryAfter),
            'last_failure_code' => (string) ($state['last_failure_code'] ?? ''),
            'last_failure_at' => (int) ($state['last_failure_at'] ?? 0),
            'model' => Settings::GEMINI_MODEL,
            'checks' => RuntimeProbeContract::CHECKS,
            'provider_requests' => RuntimeProbeContract::REQUEST_COUNT,
            'proof_ttl_seconds' => RuntimeReadinessPolicy::READY_TTL_SECONDS,
        );
    }
}
