<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use YassinStore\AiAssistant\Application\Commerce\CartIntentVerdict;

/** Canonical model-only corrections emitted by the bounded agent state machine. */
final class AgentPromptFeedback
{
    public static function plainOutput(): string
    {
        return 'SERVER CONTRACT: Plain prose cannot finish this turn. Call exactly one '
            . 'respond_answer, respond_follow_up, or respond_safe_failure function.';
    }

    public static function invalidTerminal(): string
    {
        return 'Call exactly one terminal function with arguments that satisfy its declared closed schema.';
    }

    public static function requiredCartClarification(string $reason): string
    {
        if ($reason === 'cart_intent_' . CartIntentVerdict::CONTINUATION_MISMATCH) {
            return 'Do not call another read or mutation tool. Call respond_follow_up alone with '
                . 'purpose=cart_continuation_retry and one new natural Arabic question that helps '
                . 'the customer supply the same active missing value. Omit cart_continuation and '
                . 'product_refs or variation_refs; the server preserves and validates the existing authority.';
        }
        return 'Do not call another read or mutation tool. Call respond_follow_up alone with one '
            . 'natural Arabic question authored by you. Use purpose=cart_continuation with '
            . 'missing=target only when two to eight complete live candidate_commands from this '
            . 'turn preserve the same action and quantity meaning. Use cart_continuation for a '
            . 'server-bindable missing variation or quantity. Otherwise use purpose=cart_ambiguity.';
    }

    public static function mutationMustBeAlone(): string
    {
        return 'Issue exactly one cart_apply call containing one complete cart action and no sibling calls.';
    }

    public static function terminalMustBeAlone(): string
    {
        return 'After all read and state tools finish, call exactly one terminal function by itself.';
    }

    public static function semanticDenial(string $reason): string
    {
        if ($reason === CartIntentVerdict::CONTINUATION_MISMATCH) {
            return 'WooCommerce was not called. The customer did not resolve the active missing '
                . 'value. Call respond_follow_up alone with purpose=cart_continuation_retry and '
                . 'write a clearer natural Arabic question for that same missing value. Do not '
                . 'include cart_continuation, product_refs, or variation_refs and do not repeat a fixed server message.';
        }
        if ($reason === CartIntentVerdict::PLAN_MISMATCH) {
            return 'WooCommerce was not called. Re-read customer_message and the current live tool '
                . 'results, preserve the customer\'s exact action and quantity meaning, correct the '
                . 'single cart plan, and retry cart_apply alone. Ask a cart follow-up only if the '
                . 'customer meaning is genuinely unresolved.';
        }
        if (
            in_array($reason, array(
            CartIntentVerdict::AMBIGUOUS_ACTION,
            CartIntentVerdict::AMBIGUOUS_TARGET,
            CartIntentVerdict::AMBIGUOUS_QUANTITY,
            CartIntentVerdict::MULTIPLE_ACTIONS_UNSUPPORTED,
            CartIntentVerdict::UNSAFE_OR_UNRESOLVED,
            ), true)
        ) {
            return 'WooCommerce was not called. Finish with respond_follow_up and one concise natural '
                . 'Arabic question authored by you. Prefer a bounded cart_continuation when live '
                . 'authority can represent the one missing target, variation, or quantity; otherwise '
                . 'use cart_ambiguity. Never expose this diagnostic text.';
        }
        return 'WooCommerce was not called. Re-evaluate customer_message and finish with a truthful '
            . 'model-authored non-mutation response. Never expose this diagnostic text.';
    }
}
