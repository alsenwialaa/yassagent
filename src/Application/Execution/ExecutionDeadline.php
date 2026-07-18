<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Execution;

use InvalidArgumentException;
use YassinStore\AiAssistant\Domain\Exception\ExecutionBudgetException;

/** Immutable hard deadline backed by a process-monotonic clock. */
final class ExecutionDeadline
{
    /** @var float */ private $deadline;
    /** @var callable */ private $clock;

    public function __construct(float $durationSeconds, ?callable $clock = null)
    {
        if (!is_finite($durationSeconds) || $durationSeconds < 1.0 || $durationSeconds > 3600.0) {
            throw new InvalidArgumentException('Execution deadline duration is invalid.');
        }
        $this->clock = $clock ?: static function (): float {
            return (float) hrtime(true) / 1000000000.0;
        };
        $this->deadline = $this->now() + $durationSeconds;
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->deadline - $this->now());
    }

    public function hasBudget(float $requiredSeconds): bool
    {
        return is_finite($requiredSeconds)
            && $requiredSeconds >= 0.0
            && $this->remainingSeconds() >= $requiredSeconds;
    }

    public function assertBudget(string $boundary, float $requiredSeconds): void
    {
        if (!$this->hasBudget($requiredSeconds)) {
            throw new ExecutionBudgetException(
                $boundary,
                'Insufficient execution budget remains for ' . $boundary . '.'
            );
        }
    }

    private function now(): float
    {
        $value = call_user_func($this->clock);
        if (!is_float($value) && !is_int($value)) {
            throw new InvalidArgumentException('Execution deadline clock is invalid.');
        }
        return (float) $value;
    }
}
