<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use YassinStore\AiAssistant\Application\Agent\AgentRunner;
use YassinStore\AiAssistant\Application\Commerce\CommerceExecutionContext;
use YassinStore\AiAssistant\Application\Port\CartMutationPort;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;
use YassinStore\AiAssistant\Domain\Chat\AssistantResponse;
use YassinStore\AiAssistant\Domain\Chat\TurnRecord;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;

/** Reconciles one durable commerce operation into a terminal assistant response. */
final class CommerceTurnRecovery
{
    /** @var CartMutationPort */ private $mutations;
    /** @var AgentRunner */ private $agent;

    public function __construct(CartMutationPort $mutations, AgentRunner $agent)
    {
        $this->mutations = $mutations;
        $this->agent = $agent;
    }

    /** @param array<string,mixed> $conversation */
    public function recover(
        array $conversation,
        TurnRecord $turn,
        string $sessionHash,
        TurnLease $lease,
        ?TurnExecutionSupervisor $supervisor = null
    ): ?AssistantResponse {
        $context = new CommerceExecutionContext(
            (int) $conversation['id'],
            (string) $conversation['public_id'],
            $turn->turnId(),
            $supervisor !== null ? $supervisor->lease() : $lease,
            $supervisor
        );
        try {
            $receipt = $this->mutations->recoverForTurn($context);
            return $receipt !== null
                ? $this->agent->verifiedFromReceipt(
                    $conversation,
                    $turn->turnId(),
                    $sessionHash,
                    $supervisor !== null ? $supervisor->lease() : $lease,
                    $receipt,
                    $supervisor
                )
                : null;
        } catch (SafeCommerceException $exception) {
            return AssistantResponse::safeFailure(
                $exception->safeMessage(),
                $exception->reasonCode(),
                $exception->stateMayHaveChanged()
            );
        }
    }
}
