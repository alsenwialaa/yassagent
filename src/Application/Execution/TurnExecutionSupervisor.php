<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Execution;

use InvalidArgumentException;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\ExecutionBudgetException;
use YassinStore\AiAssistant\Application\Port\TurnLeasePort;

/** Owns the current lease grant and the independent hard turn deadline. */
final class TurnExecutionSupervisor
{
    private const LEASE_MARGIN_SECONDS = 8.0;

    /** @var TurnLeasePort */ private $leases;
    /** @var TurnLease */ private $lease;
    /** @var ExecutionDeadline */ private $deadline;
    /** @var int */ private $renewalTtl;
    /** @var int */ private $maxProviderRequests;
    /** @var int */ private $providerRequests = 0;
    /** @var bool */ private $renewalSealed = false;

    public function __construct(
        TurnLeasePort $leases,
        TurnLease $lease,
        ExecutionDeadline $deadline,
        int $renewalTtl,
        int $maxProviderRequests = 14
    ) {
        $this->leases = $leases;
        $this->lease = $lease;
        $this->deadline = $deadline;
        $this->renewalTtl = max(30, min(3600, $renewalTtl));
        $this->maxProviderRequests = max(1, min(50, $maxProviderRequests));
    }

    public function lease(): TurnLease
    {
        return $this->lease;
    }

    /**
     * Guard a synchronous boundary. Renewal is permitted only before work whose
     * side effects have not started.
     */
    public function before(string $boundary, ?float $minimumBudget = null, bool $renewAllowed = true): TurnLease
    {
        $this->assertBoundary($boundary);
        $required = $minimumBudget !== null
            ? $minimumBudget
            : ExecutionBoundary::minimumBudget($boundary);
        if (!is_finite($required) || $required < 0.0) {
            throw new InvalidArgumentException('Execution boundary budget is invalid.');
        }
        $this->deadline->assertBudget($boundary, $required);

        // Only an admission call with the boundary's default reserve starts a
        // provider request. after() reuses before() with an explicit near-zero
        // reserve and therefore does not double count the completed request.
        if ($boundary === ExecutionBoundary::PROVIDER_REQUEST && $minimumBudget === null) {
            if ($this->providerRequests >= $this->maxProviderRequests) {
                throw new ExecutionBudgetException(
                    $boundary,
                    'The closed per-turn provider-request budget is exhausted.'
                );
            }
            ++$this->providerRequests;
        }

        $remainingLease = $this->leases->remainingSeconds($this->lease);
        if ($remainingLease < $required + self::LEASE_MARGIN_SECONDS) {
            if (!$renewAllowed || $this->renewalSealed) {
                throw new ExecutionBudgetException(
                    $boundary,
                    'The current lease has insufficient safe time for ' . $boundary . '.'
                );
            }
            $this->lease = $this->leases->renew($this->lease, max(
                $this->renewalTtl,
                (int) ceil($required + self::LEASE_MARGIN_SECONDS)
            ));
        }
        $this->leases->assertCurrent($this->lease);
        return $this->lease;
    }

    /**
     * Re-check after a boundary. If a side effect may have occurred, renewal is
     * prohibited: callers must reconcile instead of extending and continuing.
     */
    public function after(string $boundary, bool $sideEffectMayHaveOccurred = false): TurnLease
    {
        // Once execution may have changed external state, lease renewal remains
        // forbidden for the rest of this supervisor's lifetime. A later provider
        // or terminal boundary must finish under the original fencing authority
        // or enter reconciliation; it may never silently extend that authority.
        $this->renewalSealed = $this->renewalSealed || $sideEffectMayHaveOccurred;
        return $this->before(
            $boundary,
            // Boundary-specific minimums are admission reserves, not work that
            // must remain after the boundary has already completed. The next
            // boundary performs its own reserve check.
            0.001,
            !$sideEffectMayHaveOccurred
        );
    }

    /** Returns a safe integer provider timeout within the hard deadline. */
    public function providerTimeout(int $configuredSeconds, float $terminalReserve = 10.0): int
    {
        $configuredSeconds = max(1, min(90, $configuredSeconds));
        $available = $this->deadline->remainingSeconds() - max(0.0, $terminalReserve);
        if ($available < 1.0) {
            throw new ExecutionBudgetException(
                ExecutionBoundary::PROVIDER_REQUEST,
                'No safe provider-request budget remains.'
            );
        }
        return max(1, min($configuredSeconds, (int) floor($available)));
    }

    private function assertBoundary(string $boundary): void
    {
        if (!in_array($boundary, ExecutionBoundary::all(), true)) {
            throw new InvalidArgumentException('Unknown execution boundary.');
        }
    }
}
