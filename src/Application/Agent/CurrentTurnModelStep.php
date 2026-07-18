<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use YassinStore\AiAssistant\Application\Ai\FunctionCall;
use YassinStore\AiAssistant\Application\Ai\ModelStep;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;

/** One provider step captured under one immutable customer-turn authority. */
final class CurrentTurnModelStep
{
    /** @var ModelStep */ private $step;
    /** @var AgentContext */ private $context;
    /** @var string */ private $clientTurnId;
    /** @var string */ private $conversationId;
    /** @var string */ private $leaseResource;
    /** @var int */ private $leaseFence;
    /** @var string */ private $currentTurnDigest;
    /** @var int */ private $round;

    private function __construct(
        ModelStep $step,
        AgentContext $context,
        string $clientTurnId,
        string $conversationId,
        string $leaseResource,
        int $leaseFence,
        string $currentTurnDigest,
        int $round
    ) {
        $this->step = $step;
        $this->context = $context;
        $this->clientTurnId = $clientTurnId;
        $this->conversationId = $conversationId;
        $this->leaseResource = $leaseResource;
        $this->leaseFence = $leaseFence;
        $this->currentTurnDigest = $currentTurnDigest;
        $this->round = $round;
    }

    public static function capture(ModelStep $step, AgentContext $context, int $round): self
    {
        if ($round < 1 || $round > 64) {
            throw new ContractViolation(
                'model_step_turn_evidence_invalid',
                'The current-turn model-step round is invalid.'
            );
        }

        $lease = $context->lease();
        return new self(
            $step,
            $context,
            $context->turnId(),
            $context->conversationPublicId(),
            $lease->resource(),
            $lease->fence(),
            $context->currentTurnEvidenceDigest(),
            $round
        );
    }

    public function assertCurrent(AgentContext $context): void
    {
        $lease = $context->lease();
        if (
            $this->context !== $context
            || !hash_equals($this->clientTurnId, $context->turnId())
            || !hash_equals($this->conversationId, $context->conversationPublicId())
            || !hash_equals($this->leaseResource, $lease->resource())
            || $this->leaseFence !== $lease->fence()
            || !hash_equals($this->currentTurnDigest, $context->currentTurnEvidenceDigest())
        ) {
            throw new ContractViolation(
                'model_step_turn_evidence_stale',
                'The model step does not belong to the active customer turn.'
            );
        }
    }

    public function hasExactlyOneCall(FunctionCall $call): bool
    {
        $calls = $this->step->calls();
        return count($calls) === 1 && $calls[0] === $call;
    }

    public function modelStepId(): string
    {
        return $this->step->token();
    }
    public function currentTurnDigest(): string
    {
        return $this->currentTurnDigest;
    }
    public function round(): int
    {
        return $this->round;
    }
}
