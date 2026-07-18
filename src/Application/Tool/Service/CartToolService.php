<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool\Service;

use RuntimeException;
use Throwable;
use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Agent\AgentPromptFeedback;
use YassinStore\AiAssistant\Application\Commerce\CartIntentVerdict;
use YassinStore\AiAssistant\Application\Commerce\CartPlanFactory;
use YassinStore\AiAssistant\Application\Commerce\CartIntentVerificationFactory;
use YassinStore\AiAssistant\Application\Commerce\CurrentTurnCartIntentEvidence;
use YassinStore\AiAssistant\Application\Tool\ToolExecutionResult;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Domain\Exception\OperationPendingException;
use YassinStore\AiAssistant\Application\Port\CartMutationPort;
use YassinStore\AiAssistant\Application\Port\CartIntentVerifierPort;
use YassinStore\AiAssistant\Application\Port\ClockPort;
use YassinStore\AiAssistant\Application\Port\CartMutationCapabilityPort;
use YassinStore\AiAssistant\Application\Port\CartQueryPort;
use YassinStore\AiAssistant\Application\Port\LoggerPort;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;

/** Separates harmless reads from the authoritative mutation failure path. */
final class CartToolService
{
    /** @var CartQueryPort */ private $cart;
    /** @var CartPlanFactory */ private $plans;
    /** @var CartMutationPort */ private $mutations;
    /** @var CartMutationCapabilityPort */ private $capability;
    /** @var CurrentTurnCartIntentEvidence */ private $intentEvidence;
    /** @var CartIntentVerificationFactory */ private $verificationRequests;
    /** @var CartIntentVerifierPort */ private $intentVerifier;
    /** @var ClockPort */ private $clock;
    /** @var LoggerPort */ private $logger;
    /** @var TextLocalizerPort */ private $text;

    public function __construct(
        CartQueryPort $cart,
        CartPlanFactory $plans,
        CartMutationPort $mutations,
        CartMutationCapabilityPort $capability,
        CurrentTurnCartIntentEvidence $intentEvidence,
        CartIntentVerificationFactory $verificationRequests,
        CartIntentVerifierPort $intentVerifier,
        ClockPort $clock,
        LoggerPort $logger,
        TextLocalizerPort $text
    ) {
        $this->cart = $cart;
        $this->plans = $plans;
        $this->mutations = $mutations;
        $this->capability = $capability;
        $this->intentEvidence = $intentEvidence;
        $this->verificationRequests = $verificationRequests;
        $this->intentVerifier = $intentVerifier;
        $this->clock = $clock;
        $this->logger = $logger;
        $this->text = $text;
    }

    public function view(AgentContext $context): ToolExecutionResult
    {
        try {
            $snapshot = $this->cart->snapshot(true);
            $rawItems = (array) ($snapshot['items'] ?? array());
            $cartItemRefs = $context->authority()->recordCartSnapshot($rawItems);
            $items = array();
            foreach ($rawItems as $index => $item) {
                if (!is_array($item) || $item === array()) {
                    throw new RuntimeException('The live cart projection contains a malformed item.');
                }
                $item['cart_item_ref'] = $cartItemRefs[$index];
                unset($item['cart_item_key'], $item['line_fingerprint']);
                $items[] = $item;
            }
            $context->effects()->recordViewedCartRevision((string) ($snapshot['cart_revision'] ?? ''));
            $snapshot['items'] = $items;
            unset($snapshot['cart_revision']);
            return ToolExecutionResult::success($snapshot);
        } catch (OperationPendingException $exception) {
            throw $exception;
        } catch (SafeCommerceException $exception) {
            return ToolExecutionResult::failure($exception->reasonCode(), $exception->safeMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Cart read tool failed.', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            return ToolExecutionResult::failure(
                'cart_read_failed',
                $this->text->text('تعذر قراءة السلة حالياً.')
            );
        }
    }

    public function checkoutUrl(): ToolExecutionResult
    {
        try {
            $snapshot = $this->cart->snapshot(false);
            $checkoutUrl = (string) ($snapshot['checkout_url'] ?? '');
            $parts = parse_url($checkoutUrl);
            if (
                !is_array($parts) || !isset($parts['scheme'])
                || !in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)
            ) {
                throw new RuntimeException('WooCommerce returned an invalid checkout URL.');
            }
            return ToolExecutionResult::success(array(
                'checkout_url' => $checkoutUrl,
                'cart_is_empty' => !empty($snapshot['is_empty']),
                'cart_total' => (string) ($snapshot['formatted_total'] ?? ''),
            ));
        } catch (OperationPendingException $exception) {
            throw $exception;
        } catch (SafeCommerceException $exception) {
            return ToolExecutionResult::failure($exception->reasonCode(), $exception->safeMessage());
        } catch (Throwable $exception) {
            $this->logger->error('Checkout URL tool failed.', array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            return ToolExecutionResult::failure(
                'checkout_url_unavailable',
                $this->text->text('تعذر إنشاء رابط الدفع حالياً.')
            );
        }
    }

    /** @param array<string,mixed> $arguments */
    public function apply(array $arguments, AgentContext $context): ToolExecutionResult
    {
        if ($context->effects()->mutationsBlocked()) {
            return ToolExecutionResult::failure(
                'cart_mutations_blocked',
                $context->effects()->mutationFailureMessage()
            );
        }
        $activePending = $context->pendingCartIntentAt($this->clock->now());
        $serverBoundContinuationId = '';
        try {
            $plan = $this->plans->fromToolArguments(
                $arguments,
                $context->authority(),
                $context->effects()->viewedCartRevision()
            );
            $serverBoundContinuationId = $this->intentEvidence->assertForPlan(
                $context->currentUserMessage(),
                (string) ($arguments['intent_text'] ?? ''),
                $plan,
                $context->authority(),
                $arguments,
                $activePending,
                $context->currentReplyContext()
            );
        } catch (SafeCommerceException $exception) {
            $context->effects()->recordMutationFailure(
                $exception->reasonCode(),
                $exception->safeMessage(),
                $exception->stateMayHaveChanged()
            );
            return ToolExecutionResult::failure($exception->reasonCode(), $exception->safeMessage());
        }

        $capability = $this->capability->inspect();
        if (!$capability->available()) {
            $context->effects()->recordMutationFailure(
                'cart_mutation_unavailable',
                $capability->notice(),
                false
            );
            return ToolExecutionResult::failure(
                'cart_mutation_unavailable',
                $capability->notice(),
                array('capability_code' => $capability->code())
            );
        }

        $intentText = (string) ($arguments['intent_text'] ?? '');
        $verdict = $this->intentVerifier->verify(
            $this->verificationRequests->forPlan(
                $context,
                $intentText,
                $plan,
                $arguments,
                $activePending,
                $serverBoundContinuationId
            ),
            $context->supervisor()
        );
        if (!$verdict->authorized()) {
            $code = 'cart_intent_' . $verdict->reason();
            if ($this->requiresClarification($verdict->reason())) {
                $preservePending = $activePending !== null
                    && $verdict->reason() === CartIntentVerdict::CONTINUATION_MISMATCH;
                $context->effects()->requireModelCartClarification(
                    $code,
                    $preservePending
                );
            }
            $data = array(
                'reason' => $verdict->reason(),
                'mutation_executed' => false,
                'customer_response' => $verdict->reason() === CartIntentVerdict::PLAN_MISMATCH
                    ? 'model_replan_cart_action'
                    : ($this->requiresClarification($verdict->reason())
                        ? 'model_authored_follow_up'
                        : 'model_authored_non_mutation_response'),
            );
            $data['instruction'] = AgentPromptFeedback::semanticDenial($verdict->reason());
            return ToolExecutionResult::failure(
                $code,
                '',
                $data
            );
        }

        if ($serverBoundContinuationId !== '') {
            $currentPending = $context->pendingCartIntentAt($this->clock->now());
            if (
                $currentPending === null
                || !hash_equals($currentPending->id(), $serverBoundContinuationId)
            ) {
                return $this->terminalMutationFailure(
                    $context,
                    'cart_continuation_expired',
                    $this->text->text('انتهت مهلة توضيح السلة السابق قبل التنفيذ. أعد طلب التغيير بصياغة كاملة.')
                );
            }
        }

        try {
            $context->effects()->recordMutationExecutionStarted();
            $receipt = $this->mutations->execute($plan, $context->commerce());
            $context->effects()->recordReceipt($receipt);
            return ToolExecutionResult::verified($receipt, array('receipt' => $receipt->forClient()));
        } catch (SafeCommerceException $exception) {
            $context->effects()->recordMutationFailure(
                $exception->reasonCode(),
                $exception->safeMessage(),
                $exception->stateMayHaveChanged()
            );
            return ToolExecutionResult::failure($exception->reasonCode(), $exception->safeMessage());
        }
    }

    private function terminalMutationFailure(
        AgentContext $context,
        string $code,
        string $message
    ): ToolExecutionResult {
        $context->effects()->recordMutationFailure($code, $message, false);
        return ToolExecutionResult::failure(
            $code,
            $message,
            array('mutation_terminal' => true)
        );
    }

    private function requiresClarification(string $reason): bool
    {
        return in_array($reason, array(
            CartIntentVerdict::AMBIGUOUS_ACTION,
            CartIntentVerdict::AMBIGUOUS_TARGET,
            CartIntentVerdict::AMBIGUOUS_QUANTITY,
            CartIntentVerdict::CONTINUATION_MISMATCH,
            CartIntentVerdict::MULTIPLE_ACTIONS_UNSUPPORTED,
            CartIntentVerdict::UNSAFE_OR_UNRESOLVED,
        ), true);
    }
}
