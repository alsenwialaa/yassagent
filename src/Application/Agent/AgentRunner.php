<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use Throwable;
use YassinStore\AiAssistant\Application\Ai\ModelGatewayException;
use YassinStore\AiAssistant\Application\Ai\ModelGatewayInterface;
use YassinStore\AiAssistant\Application\Ai\ModelProtocolException;
use YassinStore\AiAssistant\Application\Authority\AuthorityRegistry;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;
use YassinStore\AiAssistant\Application\Port\LoggerPort;
use YassinStore\AiAssistant\Application\Port\ClockPort;
use YassinStore\AiAssistant\Application\Port\RuntimeReadinessPort;
use YassinStore\AiAssistant\Application\Readiness\RuntimeReadinessFailurePolicy;
use YassinStore\AiAssistant\Domain\Chat\AssistantResponse;
use YassinStore\AiAssistant\Domain\Chat\ConversationState;
use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Exception\ExecutionBudgetException;
use YassinStore\AiAssistant\Domain\Exception\LeaseLostException;
use YassinStore\AiAssistant\Domain\Exception\OperationPendingException;

/** Application facade around request construction, model loop, and safe failure mapping. */
final class AgentRunner
{
    /** @var ModelGatewayInterface */ private $model;
    /** @var AgentRequestFactory */ private $requests;
    /** @var AgentModelLoop */ private $loop;
    /** @var TerminalOutcomeAssembler */ private $outcomes;
    /** @var LoggerPort */ private $logger;
    /** @var RuntimeReadinessPort */ private $readiness;
    /** @var ClockPort */ private $clock;

    public function __construct(
        ModelGatewayInterface $model,
        AgentRequestFactory $requests,
        AgentModelLoop $loop,
        TerminalOutcomeAssembler $outcomes,
        LoggerPort $logger,
        ClockPort $clock,
        RuntimeReadinessPort $readiness
    ) {
        $this->model = $model;
        $this->requests = $requests;
        $this->loop = $loop;
        $this->outcomes = $outcomes;
        $this->logger = $logger;
        $this->clock = $clock;
        $this->readiness = $readiness;
    }

    /**
     * @param array<string,mixed>             $conversation
     * @param array<int,array<string,mixed>>  $history
     * @param array<int,\YassinStore\AiAssistant\Application\Ai\ImageAttachment> $attachments
     */
    public function handle(
        array $conversation,
        array $history,
        string $message,
        string $replyContext,
        array $attachments,
        string $sessionHash,
        string $turnId,
        TurnLease $lease,
        ?TurnExecutionSupervisor $supervisor = null,
        ?array $quotedProduct = null
    ): AssistantResponse {
        $authority = new AuthorityRegistry();
        $quotedProductRef = $quotedProduct !== null
            ? $authority->recordProduct($quotedProduct)
            : '';
        $effects = new TurnEffects();
        $state = is_array($conversation['state'] ?? null) ? $conversation['state'] : array();
        $conversationState = ConversationState::fromArray($state);
        $context = new AgentContext(
            $conversation,
            $turnId,
            $sessionHash,
            $authority,
            $effects,
            $lease,
            $supervisor,
            $message,
            $conversationState->pendingCartIntent($this->clock->now()),
            $this->cartIntentHistory($history),
            $replyContext,
            $attachments
        );
        try {
            $request = $this->requests->create(
                $conversation,
                $history,
                $message,
                $replyContext,
                $attachments,
                $authority,
                $quotedProductRef
            );
            return $this->loop->run($this->model->start($request), $context);
        } catch (ExecutionBudgetException $exception) {
            throw $exception;
        } catch (OperationPendingException $exception) {
            throw $exception;
        } catch (LeaseLostException $exception) {
            throw $exception;
        } catch (ModelGatewayException $exception) {
            $this->logger->error('Agent model gateway failure.', array('code' => $exception->reasonCode()));
            $code = $exception->reasonCode();
            if (RuntimeReadinessFailurePolicy::contradictsProof($code)) {
                $this->invalidateReadiness($code);
                $code = 'assistant_not_ready';
            }
            return $this->outcomes->failure($exception->safeMessage(), $context, $code);
        } catch (ModelProtocolException $exception) {
            $this->logger->error('Agent model protocol failure.', array(
                'code' => $exception->reasonCode(),
                'message' => $exception->getMessage(),
            ));
            // The cached readiness proof covers provider/model access and
            // one minimal function call only. Production prompt, full-catalog,
            // verifier, and per-turn output defects fail this turn but cannot
            // revoke a proof that never certified those deeper contracts.
            return $this->outcomes->failure('', $context, $exception->reasonCode());
        } catch (ContractViolation $exception) {
            $this->logger->error('Agent authority contract failure.', array(
                'code' => $exception->reasonCode(),
                'message' => $exception->getMessage(),
            ));
            return $this->outcomes->failure('', $context, $exception->reasonCode());
        } catch (Throwable $exception) {
            $this->logger->error('Unhandled agent failure.', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            return $this->outcomes->failure('', $context, 'unhandled_agent_failure');
        }
    }

    /**
     * The independent cart-intent pass receives only the nearest six complete
     * turns. History resolves ordinary pronouns and ellipsis; it never supplies
     * WooCommerce execution authority.
     *
     * @param array<int,array<string,mixed>> $history
     * @return array<int,array{role:string,text:string}>
     */
    private function cartIntentHistory(array $history): array
    {
        $recent = array_slice($history, -12);
        $rows = array();
        foreach ($recent as $row) {
            if (
                !is_array($row) || !isset($row['role'], $row['content'])
                || !is_string($row['role']) || !is_string($row['content'])
            ) {
                throw new ModelProtocolException(
                    'model_history_record_invalid',
                    'Canonical history cannot be projected for cart-intent verification.'
                );
            }
            $rows[] = array('role' => $row['role'], 'text' => $row['content']);
        }
        return $rows;
    }
    private function invalidateReadiness(string $code): void
    {
        try {
            $this->readiness->invalidate($code);
        } catch (Throwable $exception) {
            $this->logger->error('Runtime readiness invalidation failed.', array(
                'code' => $code,
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
        }
    }

    /** @param array<string,mixed> $conversation */
    public function verifiedFromReceipt(
        array $conversation,
        string $turnId,
        string $sessionHash,
        TurnLease $lease,
        ActionReceipt $receipt,
        ?TurnExecutionSupervisor $supervisor = null
    ): AssistantResponse {
        $effects = new TurnEffects();
        $effects->recordReceipt($receipt);
        return $this->outcomes->verifiedAction(new AgentContext(
            $conversation,
            $turnId,
            $sessionHash,
            new AuthorityRegistry(),
            $effects,
            $lease,
            $supervisor
        ));
    }
}
