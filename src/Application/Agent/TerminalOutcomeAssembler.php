<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use YassinStore\AiAssistant\Application\Ai\FunctionCall;
use YassinStore\AiAssistant\Application\Commerce\PendingCartIntentFactory;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;
use YassinStore\AiAssistant\Domain\Chat\AssistantResponse;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;

final class TerminalOutcomeAssembler
{
    /** @var ResponseProjection */ private $projection;
    /** @var AgentFailureMessages */ private $failures;
    /** @var TextLocalizerPort */ private $text;
    /** @var ArabicCustomerText */ private $arabic;
    /** @var PendingCartIntentFactory */ private $pendingCartIntents;
    /** @var ModelAuthoredQuestionFactory */ private $modelQuestions;

    public function __construct(
        ResponseProjection $projection,
        AgentFailureMessages $failures,
        TextLocalizerPort $text,
        ArabicCustomerText $arabic,
        PendingCartIntentFactory $pendingCartIntents,
        ModelAuthoredQuestionFactory $modelQuestions
    ) {
        $this->projection = $projection;
        $this->failures = $failures;
        $this->text = $text;
        $this->arabic = $arabic;
        $this->pendingCartIntents = $pendingCartIntents;
        $this->modelQuestions = $modelQuestions;
    }

    /** @param array<string,mixed> $arguments */
    public function fromTerminal(
        CurrentTurnModelStep $step,
        FunctionCall $call,
        array $arguments,
        AgentContext $context
    ): AssistantResponse {
        $toolName = $call->name();
        if ($context->effects()->hasReceipt()) {
            return $this->verifiedAction($context);
        }
        if ($context->effects()->mutationsBlocked()) {
            return $this->failure(
                $context->effects()->mutationFailureMessage(),
                $context,
                $context->effects()->mutationFailureCode() !== ''
                    ? $context->effects()->mutationFailureCode()
                    : 'cart_mutation_failed',
                $context->effects()->stateMayBeUncertain()
            );
        }
        if (
            $context->effects()->modelCartClarificationRequired()
            && $toolName !== 'respond_follow_up'
        ) {
            throw new ContractViolation(
                'cart_clarification_response_required',
                'The previous pre-execution semantic result requires one model-authored follow-up question.'
            );
        }

        if ($toolName === 'respond_answer') {
            $productRefs = (array) ($arguments['product_refs'] ?? array());
            $variationRefs = (array) ($arguments['variation_refs'] ?? array());
            $displayRefs = array_merge($productRefs, $variationRefs);
            $products = $this->projection->cards(
                $productRefs,
                $variationRefs,
                $context->authority()
            );
            return AssistantResponse::answer(
                $this->modelText((string) $arguments['text']),
                $products,
                $this->projection->continuity($displayRefs, $context->authority()),
                $context->effects()->shoppingMemoryPatches()
            );
        }

        if ($toolName === 'respond_follow_up') {
            $purpose = isset($arguments['purpose']) && is_string($arguments['purpose'])
                ? $arguments['purpose'] : '';
            $hasContinuation = array_key_exists('cart_continuation', $arguments)
                && is_array($arguments['cart_continuation']);
            if (!isset($arguments['question']) || !is_string($arguments['question'])) {
                throw new ContractViolation(
                    'follow_up_question_missing',
                    'Every follow-up requires one model-authored question.'
                );
            }
            if (
                !in_array($purpose, array(
                'ordinary', 'cart_ambiguity', 'cart_continuation',
                'cart_continuation_retry',
                ), true)
            ) {
                throw new ContractViolation(
                    'follow_up_purpose_invalid',
                    'Every follow-up must declare its exact closed purpose.'
                );
            }
            if ($purpose === 'cart_continuation' && !$hasContinuation) {
                throw new ContractViolation(
                    'cart_follow_up_continuation_missing',
                    'A server-bindable cart clarification requires its continuation descriptor.'
                );
            }
            if ($purpose !== 'cart_continuation' && $hasContinuation) {
                throw new ContractViolation(
                    'follow_up_continuation_forbidden',
                    'Only a declared cart continuation may include a continuation descriptor.'
                );
            }
            $productRefs = (array) ($arguments['product_refs'] ?? array());
            $variationRefs = (array) ($arguments['variation_refs'] ?? array());
            $displayRefs = array_merge($productRefs, $variationRefs);
            $preserveActive = $context->effects()
                ->preservePendingCartIntentForClarification();
            if ($purpose === 'cart_continuation_retry') {
                if (!$preserveActive || $displayRefs !== array()) {
                    throw new ContractViolation(
                        'cart_continuation_retry_invalid',
                        'An adaptive retry requires the unchanged active continuation and forbids cards.'
                    );
                }
            } elseif ($preserveActive) {
                throw new ContractViolation(
                    'cart_continuation_retry_required',
                    'The unresolved active continuation requires one adaptive retry question.'
                );
            }
            if ($purpose === 'cart_continuation') {
                $continuation = $arguments['cart_continuation'];
                $isTarget = (string) ($continuation['missing'] ?? '') === 'target';
                $candidateDisplayRefs = array();
                foreach ((array) ($continuation['candidate_commands'] ?? array()) as $candidate) {
                    if (
                        is_array($candidate) && isset($candidate['product_ref'])
                        && is_string($candidate['product_ref'])
                    ) {
                        $candidateDisplayRefs[$candidate['product_ref']] = true;
                    }
                    if (
                        is_array($candidate) && isset($candidate['variation_ref'])
                        && is_string($candidate['variation_ref'])
                    ) {
                        $candidateDisplayRefs[$candidate['variation_ref']] = true;
                    }
                }
                foreach ($displayRefs as $displayRef) {
                    if (
                        !$isTarget || !is_string($displayRef)
                        || !isset($candidateDisplayRefs[$displayRef])
                    ) {
                        throw new ContractViolation(
                            'cart_follow_up_products_forbidden',
                            'Cart clarification cards must belong to bounded target candidates.'
                        );
                    }
                }
            }
            if (
                $context->effects()->modelCartClarificationRequired()
                && $purpose === 'ordinary'
            ) {
                throw new ContractViolation(
                    'cart_follow_up_purpose_required',
                    'A required cart clarification cannot be mislabeled as an ordinary question.'
                );
            }
            $question = $this->modelQuestions->accept($step, $call, $arguments, $context);
            $products = $this->projection->cards(
                $productRefs,
                $variationRefs,
                $context->authority()
            );
            if ($purpose === 'cart_continuation_retry') {
                $pendingCartIntent = $this->pendingCartIntents->rephraseActive(
                    $question,
                    $context
                );
            } elseif ($hasContinuation) {
                $pendingCartIntent = $this->pendingCartIntents->create(
                    $arguments['cart_continuation'],
                    $question,
                    $context
                );
            } else {
                $pendingCartIntent = null;
            }
            // The visible follow-up is the exact validated model question.
            // Its provider and turn provenance remain durable server-side.
            return AssistantResponse::followUp(
                $question,
                $products,
                $pendingCartIntent,
                $this->projection->continuity($displayRefs, $context->authority()),
                $context->effects()->shoppingMemoryPatches()
            );
        }

        if ($toolName === 'respond_safe_failure') {
            return $this->failure(
                $this->modelText((string) $arguments['text']),
                $context,
                'model_safe_failure'
            );
        }

        return $this->failure(
            $this->text->text('لم أحصل على نتيجة نهائية صالحة. حاول صياغة الطلب بطريقة أخرى.'),
            $context,
            'terminal_tool_invalid'
        );
    }

    public function verifiedAction(AgentContext $context): AssistantResponse
    {
        $receipt = $context->effects()->receipt();
        if ($receipt === null) {
            return $this->failure('', $context, 'verified_receipt_missing');
        }
        // A durable verified receipt is the sole customer-visible authority for
        // an applied cart effect. Recovery must replay it byte-for-byte: any
        // rewrite here would contradict AssistantResponse's receipt equality
        // invariant and could permanently block reconciliation.
        $text = $receipt->safeMessage();
        return AssistantResponse::verifiedAction(
            $text,
            array($receipt),
            $context->effects()->shoppingMemoryPatches()
        );
    }

    public function failure(
        string $message,
        AgentContext $context,
        string $failureCode = '',
        bool $uncertain = false
    ): AssistantResponse {
        if ($context->effects()->hasReceipt()) {
            return $this->verifiedAction($context);
        }
        $code = $context->effects()->mutationFailureCode() !== ''
            ? $context->effects()->mutationFailureCode()
            : $failureCode;
        $safeMessage = $context->effects()->failureMessage($message);
        if (!$this->arabic->accepts($safeMessage)) {
            $safeMessage = $this->failures->forCode($code);
        }
        // Shopping-memory updates are provisional model effects. A failed
        // turn must not create hidden durable context that the customer never
        // saw confirmed in a successful answer, follow-up, or action receipt.
        return AssistantResponse::safeFailure(
            $safeMessage,
            $code !== '' ? $code : 'agent_safe_failure',
            $uncertain || $context->effects()->stateMayBeUncertain()
        );
    }

    private function modelText(string $text): string
    {
        $this->arabic->assertValidModelText($text);
        return $text;
    }
}
