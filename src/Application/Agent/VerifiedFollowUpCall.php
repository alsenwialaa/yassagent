<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use InvalidArgumentException;
use RuntimeException;
use YassinStore\AiAssistant\Application\Ai\FunctionCall;
use YassinStore\AiAssistant\Domain\Chat\ModelAuthoredQuestion;
use YassinStore\AiAssistant\Domain\Chat\VerifiedModelQuestionEvidence;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Support\Json;

/** Exact terminal follow-up call verified against the active turn capability. */
final class VerifiedFollowUpCall extends VerifiedModelQuestionEvidence
{
    /** @param array<string,mixed> $validatedArguments */
    public static function verify(
        CurrentTurnModelStep $step,
        FunctionCall $call,
        array $validatedArguments,
        AgentContext $context,
        ArabicCustomerText $customerText
    ): self {
        $step->assertCurrent($context);
        if (
            !$step->hasExactlyOneCall($call)
            || $call->name() !== 'respond_follow_up'
            || $validatedArguments !== $call->arguments()
            || !isset($validatedArguments['question'], $validatedArguments['purpose'])
            || !is_string($validatedArguments['question'])
            || !is_string($validatedArguments['purpose'])
            || !in_array($validatedArguments['purpose'], ModelAuthoredQuestion::purposes(), true)
        ) {
            throw new ContractViolation(
                'model_question_evidence_invalid',
                'A follow-up question requires the exact validated current-turn provider call.'
            );
        }

        $customerText->assertValidModelText($validatedArguments['question']);
        try {
            return new self(
                $validatedArguments['question'],
                $step->modelStepId(),
                $call->name(),
                $call->id(),
                $call->providerId(),
                $context->turnId(),
                $context->conversationPublicId(),
                $validatedArguments['purpose'],
                $step->round(),
                hash('sha256', Json::canonicalObject($validatedArguments)),
                $step->currentTurnDigest()
            );
        } catch (RuntimeException $exception) {
            throw new ContractViolation(
                'model_question_evidence_invalid',
                'The validated follow-up arguments cannot form durable evidence.'
            );
        } catch (InvalidArgumentException $exception) {
            throw new ContractViolation(
                'model_question_evidence_invalid',
                'The verified follow-up call contains invalid provenance.'
            );
        }
    }
}
