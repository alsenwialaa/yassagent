<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use YassinStore\AiAssistant\Application\Ai\FunctionFeedback;
use YassinStore\AiAssistant\Application\Ai\ModelProtocolException;
use YassinStore\AiAssistant\Application\Ai\ModelSessionInterface;
use YassinStore\AiAssistant\Application\Ai\OutputLimitRecoverableSessionInterface;
use YassinStore\AiAssistant\Application\Ai\ProviderTimeoutAwareSessionInterface;
use YassinStore\AiAssistant\Application\Port\ProviderWaitIsolationPort;
use YassinStore\AiAssistant\Application\Tool\ToolCatalog;
use YassinStore\AiAssistant\Application\Execution\ExecutionBoundary;
use YassinStore\AiAssistant\Domain\Chat\AssistantResponse;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Support\Json;

/** Explicit bounded model/tool state machine for one customer turn. */
final class AgentModelLoop
{
    /** @var ToolCatalog */ private $tools;
    /** @var TerminalOutcomeAssembler */ private $outcomes;
    /** @var AgentLimits */ private $limits;
    /** @var ProviderWaitIsolationPort */ private $providerWaitIsolation;

    public function __construct(
        ToolCatalog $tools,
        TerminalOutcomeAssembler $outcomes,
        AgentLimits $limits,
        ProviderWaitIsolationPort $providerWaitIsolation
    ) {
        $this->tools = $tools;
        $this->outcomes = $outcomes;
        $this->limits = $limits;
        $this->providerWaitIsolation = $providerWaitIsolation;
    }

    public function run(ModelSessionInterface $session, AgentContext $context): AssistantResponse
    {
        $toolCalls = 0;
        $feedbackBytes = 0;
        for ($round = 0; $round < $this->limits->maxRounds(); ++$round) {
            // A read or failed cart operation may have reacquired the shared Woo
            // request fence. No provider request is allowed to retain that lock.
            // The isolation operation is idempotent while the request is already
            // in its unlocked provider-wait state.
            $this->providerWaitIsolation->releaseForProviderWait();
            $supervisor = $context->supervisor();
            if ($supervisor !== null) {
                $supervisor->before(ExecutionBoundary::PROVIDER_REQUEST);
                try {
                    if ($session instanceof ProviderTimeoutAwareSessionInterface) {
                        $session->setNextTimeoutSeconds(
                            $supervisor->providerTimeout($this->limits->providerTimeoutSeconds())
                        );
                    }
                    $step = $session->next();
                } finally {
                    $supervisor->after(ExecutionBoundary::PROVIDER_REQUEST);
                }
            } else {
                $step = $session->next();
            }
            if ($step->finishReason() === 'MAX_TOKENS') {
                if (
                    $round + 1 < $this->limits->maxRounds()
                    && $session instanceof OutputLimitRecoverableSessionInterface
                    && $session->recoverOutputLimit($step)
                ) {
                    continue;
                }
                return $this->outcomes->failure('', $context, 'model_output_limit');
            }

            $calls = $step->calls();

            if ($calls === array()) {
                $session->correctPlainOutput(
                    $step,
                    AgentPromptFeedback::plainOutput()
                );
                continue;
            }

            if (count($calls) === 1 && $this->tools->isTerminal($calls[0]->name())) {
                try {
                    return $this->outcomes->fromTerminal(
                        CurrentTurnModelStep::capture($step, $context, $round + 1),
                        $calls[0],
                        $this->tools->validateTerminal($calls[0]->name(), $calls[0]->arguments()),
                        $context
                    );
                } catch (ContractViolation $exception) {
                    $feedback = array(FunctionFeedback::forCall($calls[0], array(
                        'ok' => false,
                        'code' => 'terminal_contract_invalid',
                        'data' => array(
                            'reason' => $exception->reasonCode(),
                            'detail' => $exception->getMessage(),
                            'instruction' => AgentPromptFeedback::invalidTerminal(),
                        ),
                    )));
                    $feedbackBytes = $this->accountFeedback($feedback, $feedbackBytes);
                    $session->submit($step, $feedback);
                    continue;
                }
            }

            if ($context->effects()->modelCartClarificationRequired()) {
                $feedback = array();
                foreach ($calls as $call) {
                    $feedback[] = FunctionFeedback::forCall($call, array(
                        'ok' => false,
                        'code' => 'cart_clarification_response_required',
                        'data' => array(
                            'reason' => $context->effects()->cartClarificationReason(),
                            'instruction' => AgentPromptFeedback::requiredCartClarification(
                                $context->effects()->cartClarificationReason()
                            ),
                        ),
                    ));
                }
                $feedbackBytes = $this->accountFeedback($feedback, $feedbackBytes);
                $session->submit($step, $feedback);
                continue;
            }

            $hasMutation = false;
            $hasTerminal = false;
            foreach ($calls as $call) {
                $hasMutation = $hasMutation || $this->tools->isMutation($call->name());
                $hasTerminal = $hasTerminal || $this->tools->isTerminal($call->name());
            }
            if (count($calls) !== 1 && ($hasMutation || $hasTerminal)) {
                $code = $hasMutation ? 'mutation_must_be_alone' : 'terminal_must_be_alone';
                $instruction = $hasMutation
                    ? AgentPromptFeedback::mutationMustBeAlone()
                    : AgentPromptFeedback::terminalMustBeAlone();
                $feedback = array();
                foreach ($calls as $call) {
                    $feedback[] = FunctionFeedback::forCall($call, array(
                        'ok' => false,
                        'code' => $code,
                        'data' => array('instruction' => $instruction),
                    ));
                }
                $feedbackBytes = $this->accountFeedback($feedback, $feedbackBytes);
                $session->submit($step, $feedback);
                continue;
            }

            if ($supervisor !== null) {
                $supervisor->before(ExecutionBoundary::TOOL_BATCH);
            }
            $feedback = array();
            try {
                foreach ($calls as $call) {
                    ++$toolCalls;
                    if ($toolCalls > $this->limits->maxToolCalls()) {
                        return $this->outcomes->failure('', $context, 'tool_call_limit');
                    }
                    $isMutation = $this->tools->isMutation($call->name());
                    $result = $this->tools->execute($call->name(), $call->arguments(), $context);
                    if ($result->receipt() !== null) {
                        return $this->outcomes->verifiedAction($context);
                    }
                    if ($isMutation && $context->effects()->mutationsBlocked()) {
                        // Once the cart service records an authoritative
                        // mutation failure, no later provider round may
                        // reinterpret it. Contract failures which occur before
                        // execution have no TurnEffects mutation outcome and
                        // remain ordinary model feedback so the provider can
                        // correct the call after the request fence is released.
                        return $this->outcomes->failure('', $context, $result->code());
                    }
                    $feedback[] = FunctionFeedback::forCall($call, $result->forModel());
                }
            } finally {
                if ($supervisor !== null) {
                    $supervisor->after(
                        ExecutionBoundary::TOOL_BATCH,
                        $context->effects()->mutationExecutionStarted()
                    );
                }
            }
            $feedbackBytes = $this->accountFeedback($feedback, $feedbackBytes);
            $session->submit($step, $feedback);
        }

        return $this->outcomes->failure('', $context, 'tool_round_limit');
    }

    /** @param array<int,FunctionFeedback> $feedback */
    private function accountFeedback(array $feedback, int $used): int
    {
        $payloads = array();
        foreach ($feedback as $item) {
            $payloads[] = array('id' => $item->id(), 'name' => $item->name(), 'response' => $item->payload());
        }
        $next = $used + strlen(Json::encode($payloads));
        if ($next > $this->limits->maxFeedbackBytes()) {
            throw new ModelProtocolException(
                'tool_feedback_budget_exceeded',
                'Tool feedback exceeded the configured model context budget.'
            );
        }
        return $next;
    }
}
