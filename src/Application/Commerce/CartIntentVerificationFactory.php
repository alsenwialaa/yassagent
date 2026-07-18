<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Commerce;

use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Domain\Commerce\CartCommand;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\PendingCartIntent;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\TrustedCommerceText;
use YassinStore\AiAssistant\Support\Utf8;

/** Projects live opaque authority into bounded semantic facts for the verifier. */
final class CartIntentVerificationFactory
{
    /** @var CatalogTextNormalizer */ private $normalizer;

    public function __construct(CatalogTextNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /** @param array<string,mixed> $arguments */
    public function forPlan(
        AgentContext $context,
        string $intentText,
        CartPlan $plan,
        array $arguments,
        ?PendingCartIntent $activePending,
        string $serverBoundContinuationId = ''
    ): CartIntentVerificationRequest {
        $commands = $plan->commands();
        $rows = is_array($arguments['commands'] ?? null)
            ? $arguments['commands'] : array();
        $command = count($commands) === 1 ? $commands[0] : null;
        $row = count($rows) === 1 && is_array($rows[0]) ? $rows[0] : null;
        if (!$command instanceof CartCommand || !is_array($row)) {
            throw new ContractViolation(
                'cart_intent_proposal_invalid',
                'Semantic cart verification requires exactly one resolved command.'
            );
        }

        $requestedAction = (string) ($row['type'] ?? '');
        if (!in_array($requestedAction, CartCommand::types(), true)) {
            throw new ContractViolation(
                'cart_intent_proposal_invalid',
                'Semantic cart verification received an unsupported requested action.'
            );
        }

        $source = array();
        $target = array();
        $sourceQuantity = null;
        if (
            in_array($requestedAction, array(
            CartCommand::UPDATE, CartCommand::REMOVE, CartCommand::REPLACE,
            ), true)
        ) {
            $ref = isset($row['cart_item_ref']) && is_string($row['cart_item_ref'])
                ? $row['cart_item_ref'] : '';
            $item = $context->authority()->requireCartItem($ref);
            $source = $this->cartItem($ref, $item);
            $sourceQuantity = isset($item['quantity']) && is_numeric($item['quantity'])
                ? (int) $item['quantity'] : null;
        }
        if (in_array($requestedAction, array(CartCommand::ADD, CartCommand::REPLACE), true)) {
            $productRef = isset($row['product_ref']) && is_string($row['product_ref'])
                ? $row['product_ref'] : '';
            $product = $context->authority()->requireProduct($productRef);
            $variationRef = isset($row['variation_ref']) && is_string($row['variation_ref'])
                ? $row['variation_ref'] : '';
            $variation = $variationRef !== ''
                ? $context->authority()->requireVariation($variationRef) : array();
            $target = $this->product($productRef, $product, $variationRef, $variation);
        }

        $mode = isset($row['quantity_mode']) && is_string($row['quantity_mode'])
            ? $row['quantity_mode'] : 'none';
        $requestedQuantity = isset($row['quantity']) && is_int($row['quantity'])
            ? $row['quantity'] : null;
        $resultingQuantity = null;
        if (
            in_array($requestedAction, array(
            CartCommand::ADD, CartCommand::UPDATE, CartCommand::REPLACE,
            ), true)
        ) {
            $resultingQuantity = (int) $command->quantity();
        }
        if (
            $requestedAction === CartCommand::UPDATE
            && $command->type() === CartCommand::REMOVE
        ) {
            $resultingQuantity = 0;
        }

        $continuationId = $serverBoundContinuationId;
        if (
            $continuationId !== '' && (
            !$activePending instanceof PendingCartIntent
            || !hash_equals($activePending->id(), $continuationId)
            )
        ) {
            throw new ContractViolation(
                'cart_continuation_binding_invalid',
                'Semantic verification received a stale server-bound continuation.'
            );
        }
        $continuation = $activePending instanceof PendingCartIntent
            ? $activePending->forModel() : array();
        $resolution = $this->continuationResolution(
            $activePending,
            $continuationId,
            $source,
            $target,
            $mode,
            $requestedQuantity,
            $resultingQuantity
        );

        $message = new CurrentCustomerMessage(
            $context->currentUserMessage(),
            $context->currentReplyContext()
        );
        return new CartIntentVerificationRequest(
            $message->text(),
            $message->quotedContext(),
            $intentText,
            $context->cartIntentHistory(),
            array(
                'kind' => 'execute_now',
                'requested_action' => $requestedAction,
                'effective_action' => $command->type(),
                'source' => $source,
                'target' => $target,
                'quantity' => array(
                    'mode' => $mode,
                    'stated_value' => $requestedQuantity,
                    'current_value' => $sourceQuantity,
                    'resulting_value' => $resultingQuantity,
                ),
                'declared_continuation_id' => $continuationId,
                'server_bound_continuation' => $continuationId !== '',
                'server_owned_continuation' => $continuation,
                'resolved_missing_values' => $resolution,
            ),
            $context->currentAttachments()
        );
    }

    public function forClarification(
        AgentContext $context,
        string $intentText,
        string $question,
        PendingCartIntent $intent,
        array $questionEvidence,
        ?PendingCartIntent $activePending = null,
        array $resolvedValues = array()
    ): CartIntentVerificationRequest {
        $isBoundRefinement = $activePending instanceof PendingCartIntent
            && $resolvedValues !== array();
        if (
            ($activePending instanceof PendingCartIntent) !== ($resolvedValues !== array())
            || ($isBoundRefinement
                && ($activePending->missing() !== PendingCartIntent::MISSING_VARIATION
                    || $intent->missing() !== PendingCartIntent::MISSING_VARIATION))
        ) {
            throw new ContractViolation(
                'cart_continuation_refinement_invalid',
                'A refined clarification requires one active variation continuation and new values.'
            );
        }
        $pending = $intent->forModel();
        $message = new CurrentCustomerMessage(
            $context->currentUserMessage(),
            $context->currentReplyContext()
        );
        return new CartIntentVerificationRequest(
            $message->text(),
            $message->quotedContext(),
            $intentText,
            $context->cartIntentHistory(),
            array(
                'kind' => 'ask_for_missing_value',
                'requested_action' => $intent->action(),
                'proposed_customer_question' => $question,
                'server_bound_continuation' => $isBoundRefinement,
                'server_owned_continuation' => $isBoundRefinement
                    ? $activePending->forModel() : array(),
                'resolved_missing_values' => $isBoundRefinement ? array(
                    'missing' => PendingCartIntent::MISSING_VARIATION,
                    'attributes' => array_values($resolvedValues),
                ) : array(),
                'question_authority' => $questionEvidence,
                'target' => array(
                    'name' => $intent->label(),
                    'source_name' => $pending['source_label'],
                    'server_owned_missing_field' => $intent->missing(),
                    'bound_attributes' => $pending['bound_attributes'],
                    'missing_attributes' => $pending['missing_attributes'],
                    'candidate_labels' => $pending['candidate_labels'],
                    'candidate_options' => $pending['candidate_options'],
                ),
                'quantity' => array(
                    'mode' => $pending['quantity_mode'],
                    'resulting_value' => $pending['quantity'],
                ),
            ),
            $context->currentAttachments()
        );
    }

    /** @param array<string,mixed> $questionEvidence */
    public function forContinuationRetry(
        AgentContext $context,
        string $intentText,
        string $question,
        PendingCartIntent $activePending,
        array $questionEvidence
    ): CartIntentVerificationRequest {
        $pending = $activePending->forModel();
        $message = new CurrentCustomerMessage(
            $context->currentUserMessage(),
            $context->currentReplyContext()
        );
        return new CartIntentVerificationRequest(
            $message->text(),
            $message->quotedContext(),
            $intentText,
            $context->cartIntentHistory(),
            array(
                'kind' => 'reask_missing_value',
                'requested_action' => $activePending->action(),
                'proposed_customer_question' => $question,
                'server_bound_continuation' => true,
                'server_owned_continuation' => $pending,
                'resolved_missing_values' => array(),
                'question_authority' => $questionEvidence,
                'target' => array(
                    'name' => $activePending->label(),
                    'source_name' => $pending['source_label'],
                    'server_owned_missing_field' => $activePending->missing(),
                    'bound_attributes' => $pending['bound_attributes'],
                    'missing_attributes' => $pending['missing_attributes'],
                    'candidate_labels' => $pending['candidate_labels'],
                    'candidate_options' => $pending['candidate_options'],
                ),
                'quantity' => array(
                    'mode' => $pending['quantity_mode'],
                    'resulting_value' => $pending['quantity'],
                ),
            ),
            $context->currentAttachments()
        );
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function cartItem(string $ref, array $item): array
    {
        return array(
            'authority_ref' => $ref,
            'name' => $this->text((string) ($item['name'] ?? ''), 500),
            'quantity' => isset($item['quantity']) && is_numeric($item['quantity'])
                ? (int) $item['quantity'] : null,
            'attributes' => $this->attributes($item['attributes'] ?? array()),
            'item_data' => $this->attributes($item['item_data'] ?? array()),
        );
    }

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $variation
     * @return array<string,mixed>
     */
    private function product(
        string $productRef,
        array $product,
        string $variationRef,
        array $variation
    ): array {
        $entity = $variation !== array() ? $variation : $product;
        return array(
            'product_authority_ref' => $productRef,
            'variation_authority_ref' => $variationRef,
            'name' => $this->text((string) ($entity['name'] ?? $product['name'] ?? ''), 500),
            'parent_name' => $this->text((string) ($product['name'] ?? ''), 500),
            'sku' => $this->text((string) ($entity['sku'] ?? $product['sku'] ?? ''), 191),
            'attributes' => $this->attributes($entity['attributes'] ?? array()),
        );
    }

    /** @param mixed $rows @return array<int,array{label:string,value:string}> */
    private function attributes($rows): array
    {
        if (!is_array($rows) || !Arr::isList($rows)) {
            return array();
        }
        $out = array();
        foreach (array_slice($rows, 0, 16) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = (string) ($row['label'] ?? $row['name'] ?? '');
            $value = (string) ($row['display'] ?? $row['value'] ?? '');
            if ($value === '' && is_array($row['values'] ?? null)) {
                $values = array();
                foreach (array_slice($row['values'], 0, 16) as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') {
                        $values[] = trim($candidate);
                    }
                }
                $value = implode('، ', $values);
            }
            $label = $this->text($label, 160);
            $value = $this->text($value, 320);
            if ($label !== '' && $value !== '') {
                $out[] = array('label' => $label, 'value' => $value);
            }
        }
        return $out;
    }

    private function text(string $value, int $limit): string
    {
        $value = TrustedCommerceText::decodeEntities($value);
        if ($value === '' || !Utf8::isPlainText($value)) {
            return '';
        }
        return Utf8::truncate($value, $limit);
    }

    /**
     * @param array<string,mixed> $target
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function continuationResolution(
        ?PendingCartIntent $pending,
        string $boundId,
        array $source,
        array $target,
        string $quantityMode,
        ?int $requestedQuantity,
        ?int $resultingQuantity
    ): array {
        if ($boundId === '' || !$pending instanceof PendingCartIntent) {
            return array();
        }
        if ($pending->missing() === PendingCartIntent::MISSING_TARGET) {
            return array(
                'missing' => PendingCartIntent::MISSING_TARGET,
                'selected_candidate' => array(
                    'source' => $source,
                    'target' => $target,
                ),
            );
        }
        if ($pending->missing() === PendingCartIntent::MISSING_QUANTITY) {
            return array(
                'missing' => PendingCartIntent::MISSING_QUANTITY,
                'quantity_mode' => $quantityMode,
                'stated_value' => $requestedQuantity,
                'resulting_value' => $resultingQuantity,
            );
        }

        $missing = $pending->forModel()['missing_attributes'] ?? array();
        $attributes = is_array($target['attributes'] ?? null)
            ? $target['attributes'] : array();
        $attributesByLabel = array();
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $identity = $this->normalizer->normalize(
                (string) ($attribute['label'] ?? '')
            );
            if ($identity === '' || isset($attributesByLabel[$identity])) {
                throw new ContractViolation(
                    'cart_continuation_resolution_invalid',
                    'The live variation contains ambiguous normalized attribute labels.'
                );
            }
            $attributesByLabel[$identity] = $attribute;
        }
        $values = array();
        $seenMissing = array();
        foreach ($missing as $missingLabel) {
            if (!is_string($missingLabel)) {
                throw new ContractViolation(
                    'cart_continuation_resolution_invalid',
                    'The pending variation contains a malformed missing label.'
                );
            }
            $identity = $this->normalizer->normalize($missingLabel);
            if (
                $identity === '' || isset($seenMissing[$identity])
                || !isset($attributesByLabel[$identity])
            ) {
                throw new ContractViolation(
                    'cart_continuation_resolution_invalid',
                    'The server-bound variation continuation has incomplete or ambiguous resolved values.'
                );
            }
            $seenMissing[$identity] = true;
            $values[] = array(
                'label' => $missingLabel,
                'value' => (string) ($attributesByLabel[$identity]['value'] ?? ''),
            );
        }
        if (count($values) !== count($missing)) {
            throw new ContractViolation(
                'cart_continuation_resolution_invalid',
                'The server-bound variation continuation has incomplete resolved values.'
            );
        }
        return array(
            'missing' => PendingCartIntent::MISSING_VARIATION,
            'attributes' => $values,
        );
    }
}
