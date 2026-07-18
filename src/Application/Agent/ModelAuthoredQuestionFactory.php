<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Ai\FunctionCall;
use YassinStore\AiAssistant\Application\Port\ClockPort;
use YassinStore\AiAssistant\Domain\Chat\ModelAuthoredQuestion;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;

/** Issues durable question authority only from one verified current-turn call. */
final class ModelAuthoredQuestionFactory
{
    /** @var ArabicCustomerText */ private $customerText;
    /** @var ClockPort */ private $clock;

    public function __construct(ArabicCustomerText $customerText, ClockPort $clock)
    {
        $this->customerText = $customerText;
        $this->clock = $clock;
    }

    /** @param array<string,mixed> $validatedArguments */
    public function accept(
        CurrentTurnModelStep $step,
        FunctionCall $call,
        array $validatedArguments,
        AgentContext $context
    ): ModelAuthoredQuestion {
        $evidence = VerifiedFollowUpCall::verify(
            $step,
            $call,
            $validatedArguments,
            $context,
            $this->customerText
        );
        try {
            return ModelAuthoredQuestion::acceptVerified($evidence, $this->clock->now());
        } catch (InvalidArgumentException $exception) {
            throw new ContractViolation(
                'model_question_evidence_invalid',
                'The verified model-authored question cannot form durable authority.'
            );
        }
    }
}
