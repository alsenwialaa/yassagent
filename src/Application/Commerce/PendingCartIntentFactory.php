<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Commerce;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use YassinStore\AiAssistant\Application\Agent\AgentContext;
use YassinStore\AiAssistant\Application\Port\ClockPort;
use YassinStore\AiAssistant\Application\Port\CartIntentVerifierPort;
use YassinStore\AiAssistant\Domain\Chat\ModelAuthoredQuestion;
use YassinStore\AiAssistant\Domain\Commerce\CartCommand;
use YassinStore\AiAssistant\Domain\Commerce\CartContinuationCandidate;
use YassinStore\AiAssistant\Domain\Commerce\PendingCartIntent;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\TrustedCommerceText;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

/** Creates one durable clarification from AI semantics and current live authority. */
final class PendingCartIntentFactory
{
    /** @var CatalogTextNormalizer */ private $text;
    /** @var ClockPort */ private $clock;
    /** @var CurrentTurnCartIntentEvidence */ private $evidence;
    /** @var CartIntentVerificationFactory */ private $verificationRequests;
    /** @var CartIntentVerifierPort */ private $intentVerifier;
    /** @var VariableProductAuthority */ private $variableProducts;

    public function __construct(
        CatalogTextNormalizer $text,
        ClockPort $clock,
        CurrentTurnCartIntentEvidence $evidence,
        CartIntentVerificationFactory $verificationRequests,
        CartIntentVerifierPort $intentVerifier,
        VariableProductAuthority $variableProducts
    ) {
        $this->text = $text;
        $this->clock = $clock;
        $this->evidence = $evidence;
        $this->verificationRequests = $verificationRequests;
        $this->intentVerifier = $intentVerifier;
        $this->variableProducts = $variableProducts;
    }

    /**
     * Natural-language meaning and customer-facing wording belong to the
     * model. The server validates the exact excerpt, declared missing field,
     * question, and live opaque product/cart identities without rewriting it.
     *
     * @param array<string,mixed> $spec
     */
    public function create(
        array $spec,
        ModelAuthoredQuestion $question,
        AgentContext $context
    ): PendingCartIntent {
        $this->assertQuestion(
            $question,
            $context,
            ModelAuthoredQuestion::PURPOSE_CART_CONTINUATION
        );
        $this->assertSpec($spec);
        $this->evidence->assertCurrentExcerpt(
            $context->currentUserMessage(),
            (string) $spec['intent_text'],
            $context->currentReplyContext()
        );

        if ($spec['missing'] === PendingCartIntent::MISSING_VARIATION) {
            $intent = $this->variationIntent($spec, $question, $context);
        } elseif ($spec['missing'] === PendingCartIntent::MISSING_QUANTITY) {
            $intent = $this->quantityIntent($spec, $question, $context);
        } else {
            $intent = $this->targetIntent($spec, $question, $context);
        }
        $activePending = $context->pendingCartIntentAt($this->clock->now());
        $refinedValues = $this->evidence->variationRefinement($activePending, $intent);
        $boundPrior = $refinedValues !== array() ? $activePending : null;
        $verdict = $this->intentVerifier->verify(
            $this->verificationRequests->forClarification(
                $context,
                (string) $spec['intent_text'],
                $question->text(),
                $intent,
                $this->questionEvidence($spec, $intent, $context),
                $boundPrior,
                $refinedValues
            ),
            $context->supervisor()
        );
        if (!$verdict->authorized()) {
            throw new ContractViolation(
                'cart_intent_needs_clarification',
                'The current message does not unambiguously authorize this exact cart clarification: '
                    . $verdict->reason() . '.'
            );
        }
        return $intent;
    }

    /**
     * Validates an adaptive AI-authored retry question against the exact active
     * server continuation without changing its action, target, values, or
     * expiry. The customer's unresolved answer is evidence, not authority.
     */
    public function rephraseActive(ModelAuthoredQuestion $question, AgentContext $context): PendingCartIntent
    {
        $this->assertQuestion(
            $question,
            $context,
            ModelAuthoredQuestion::PURPOSE_CART_CONTINUATION_RETRY
        );
        $active = $context->pendingCartIntentAt($this->clock->now());
        if (!$active instanceof PendingCartIntent) {
            throw new ContractViolation(
                'cart_continuation_retry_missing',
                'An adaptive cart clarification requires one active server continuation.'
            );
        }
        $excerpt = Utf8::truncate($context->currentUserMessage(), 320);
        $verdict = $this->intentVerifier->verify(
            $this->verificationRequests->forContinuationRetry(
                $context,
                $excerpt,
                $question->text(),
                $active,
                $this->retryQuestionEvidence($active)
            ),
            $context->supervisor()
        );
        if (!$verdict->authorized()) {
            throw new ContractViolation(
                'cart_intent_needs_clarification',
                'The adaptive question does not preserve the exact active cart clarification: '
                    . $verdict->reason() . '.'
            );
        }
        return $active->withQuestion($question);
    }

    /** @return array<string,mixed> */
    private function retryQuestionEvidence(PendingCartIntent $intent): array
    {
        $pending = $intent->forModel();
        if ($intent->missing() === PendingCartIntent::MISSING_TARGET) {
            return array(
                'missing_kind' => PendingCartIntent::MISSING_TARGET,
                'candidate_labels' => $pending['candidate_labels'],
                'candidate_options' => $pending['candidate_options'],
                'candidate_count' => count($pending['candidate_labels']),
                'candidates_complete' => true,
            );
        }
        if ($intent->missing() === PendingCartIntent::MISSING_QUANTITY) {
            return array(
                'missing_kind' => PendingCartIntent::MISSING_QUANTITY,
                'quantity_mode' => $pending['quantity_mode'],
                'current_quantity' => null,
            );
        }
        $axes = array();
        foreach ($pending['missing_attributes'] as $label) {
            $axes[] = array(
                'label' => $label,
                'listed_values' => array(),
                'value_count' => null,
                'values_complete' => false,
            );
        }
        return array(
            'missing_kind' => PendingCartIntent::MISSING_VARIATION,
            'missing_axes' => $axes,
            'listed_valid_combinations' => array(),
            'combination_count' => null,
            'combinations_complete' => false,
            'catalog_complete' => false,
        );
    }

    private function assertQuestion(
        ModelAuthoredQuestion $question,
        AgentContext $context,
        string $purpose
    ): void {
        if (
            $question->purpose() !== $purpose
            || !hash_equals($context->turnId(), $question->clientTurnId())
            || !hash_equals($context->conversationPublicId(), $question->conversationId())
        ) {
            throw new ContractViolation(
                'cart_clarification_question_invalid',
                'A cart clarification requires current-turn model-question authority.'
            );
        }
    }

    /** @param array<string,mixed> $spec */
    private function variationIntent(
        array $spec,
        ModelAuthoredQuestion $question,
        AgentContext $context
    ): PendingCartIntent {
        $action = (string) $spec['action'];
        $product = $context->authority()->requireProduct((string) $spec['target_ref']);
        $productId = (int) ($product['id'] ?? 0);
        $label = TrustedCommerceText::decodeEntities((string) ($product['name'] ?? ''));
        if ($productId < 1 || $label === '' || empty($product['requires_variation'])) {
            throw new ContractViolation(
                'pending_cart_variation_not_required',
                'Only a current live variable product can create a missing-variation clarification.'
            );
        }

        $productAuthority = $this->variableProducts->inspect($product);
        $axes = $productAuthority['axes'];
        $inspection = $context->authority()->variationCatalogForProduct($productId);
        $catalogEpoch = is_string($inspection['epoch'] ?? null)
            ? strtolower(trim($inspection['epoch'])) : '';
        if (
            $inspection['variations'] === array()
            || preg_match('/^[a-f0-9]{64}$/', $catalogEpoch) !== 1
        ) {
            throw new ContractViolation(
                'cart_clarification_variations_not_inspected',
                'The model must inspect current live variations before asking for an option.'
            );
        }
        $bound = $this->selectedAttributes(
            (array) ($spec['selected_attributes'] ?? array()),
            $axes
        );
        $quantity = isset($spec['quantity']) && is_int($spec['quantity'])
            ? $spec['quantity'] : ($action === CartCommand::ADD ? 1 : 0);
        if (
            !CartQuantity::isNonNegativeInteger($quantity)
            || ($action === CartCommand::ADD && !CartQuantity::isPositiveInteger($quantity))
            || ($action === CartCommand::REPLACE
                && (string) $spec['quantity_mode'] === 'exact'
                && !CartQuantity::isPositiveInteger($quantity))
        ) {
            throw new ContractViolation(
                'pending_cart_quantity_invalid',
                'A pending cart quantity is outside the supported range.'
            );
        }

        $replacement = array();
        if ($action === CartCommand::REPLACE) {
            $item = $context->authority()->requireCartItem(
                (string) $spec['source_cart_item_ref']
            );
            $key = trim((string) ($item['cart_item_key'] ?? ''));
            $fingerprint = strtolower(trim((string) ($item['line_fingerprint'] ?? '')));
            $sourceLabel = $this->cartItemLabel($item);
            if (
                $key === '' || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1
                || $sourceLabel === ''
            ) {
                throw new ContractViolation(
                    'pending_cart_line_invalid',
                    'A pending replacement requires exact current source-line authority.'
                );
            }
            $replacement = array(
                'source_cart_item_key' => $key,
                'source_line_fingerprint' => $fingerprint,
                'source_label' => $sourceLabel,
                'quantity_mode' => (string) $spec['quantity_mode'],
            );
        }

        $prior = $context->pendingCartIntentAt($this->clock->now());
        if (
            $prior instanceof PendingCartIntent
            && $this->sameVariationContinuation(
                $prior,
                $action,
                $productAuthority,
                $catalogEpoch,
                $quantity,
                $replacement
            )
        ) {
            foreach ((array) ($prior->target()['bound_attributes'] ?? array()) as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $identity = $this->identity((string) ($attribute['label'] ?? ''));
                if ($identity !== '' && !isset($bound[$identity])) {
                    $bound[$identity] = array(
                        'label' => (string) $attribute['label'],
                        'value' => (string) $attribute['value'],
                    );
                }
            }
        }

        $missing = array();
        foreach ($axes as $identity => $axis) {
            if (!isset($bound[$identity])) {
                $missing[] = $axis['label'];
            }
        }
        if ($missing === array()) {
            throw new ContractViolation(
                'pending_cart_variation_not_missing',
                'All live variation axes are already selected; cart_apply must be used instead.'
            );
        }

        $target = array(
            'kind' => 'product',
            'product_id' => $productId,
            'product_fingerprint' => $productAuthority['product_fingerprint'],
            'variation_axes_fingerprint' => $productAuthority['axes_fingerprint'],
            'variation_catalog_epoch' => $catalogEpoch,
            'bound_attributes' => array_values($bound),
            'missing_attributes' => $missing,
        );
        if ($action === CartCommand::REPLACE) {
            $target = array_merge(array(
                'kind' => 'replacement',
                'product_id' => $productId,
                'product_fingerprint' => $productAuthority['product_fingerprint'],
                'variation_axes_fingerprint' => $productAuthority['axes_fingerprint'],
                'variation_catalog_epoch' => $catalogEpoch,
                'bound_attributes' => array_values($bound),
                'missing_attributes' => $missing,
            ), $replacement);
        }

        return $this->intent(
            $action,
            $target,
            $quantity,
            PendingCartIntent::MISSING_VARIATION,
            $label,
            $question
        );
    }

    /** @param array<string,mixed> $spec */
    private function quantityIntent(
        array $spec,
        ModelAuthoredQuestion $question,
        AgentContext $context
    ): PendingCartIntent {
        $item = $context->authority()->requireCartItem((string) $spec['target_ref']);
        $key = trim((string) ($item['cart_item_key'] ?? ''));
        $fingerprint = strtolower(trim((string) ($item['line_fingerprint'] ?? '')));
        $label = $this->cartItemLabel($item);
        if ($key === '' || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1 || $label === '') {
            throw new ContractViolation(
                'pending_cart_line_invalid',
                'A pending quantity clarification requires exact current cart-line authority.'
            );
        }

        return $this->intent(
            CartCommand::UPDATE,
            array(
                'kind' => 'cart_item',
                'cart_item_key' => $key,
                'line_fingerprint' => $fingerprint,
                'quantity_mode' => (string) $spec['quantity_mode'],
            ),
            0,
            PendingCartIntent::MISSING_QUANTITY,
            $label,
            $question
        );
    }

    /** @param array<string,mixed> $spec */
    private function targetIntent(
        array $spec,
        ModelAuthoredQuestion $question,
        AgentContext $context
    ): PendingCartIntent {
        $rows = $spec['candidate_commands'] ?? null;
        if (
            !is_array($rows) || !Arr::isList($rows)
            || count($rows) < 2 || count($rows) > 8
        ) {
            throw new ContractViolation(
                'pending_cart_candidates_invalid',
                'A target clarification requires two to eight complete live candidate commands.'
            );
        }
        $candidates = array();
        $labels = array();
        $seen = array();
        $seenLabels = array();
        $commonSemantics = null;
        foreach ($rows as $row) {
            if (
                !is_array($row) || Arr::isList($row)
                || (string) ($row['type'] ?? '') !== (string) $spec['action']
            ) {
                throw new ContractViolation(
                    'pending_cart_candidates_invalid',
                    'Every target candidate must use the same declared cart action.'
                );
            }
            $plan = (new CartPlanFactory())->fromToolArguments(
                array('commands' => array($row)),
                $context->authority(),
                $context->effects()->viewedCartRevision()
            );
            $commands = $plan->commands();
            $command = count($commands) === 1 ? $commands[0] : null;
            if (!$command instanceof CartCommand) {
                throw new ContractViolation(
                    'pending_cart_candidates_invalid',
                    'A target candidate did not resolve to one complete cart command.'
                );
            }
            $label = $this->candidateLabel($row, $command, $context);
            $candidate = CartContinuationCandidate::create($command, $row, $label);
            $semantics = CartContinuationCandidate::semantics($candidate);
            if ($commonSemantics !== null && $commonSemantics !== $semantics) {
                throw new ContractViolation(
                    'pending_cart_candidates_invalid',
                    'Target candidates must preserve one exact action and quantity meaning.'
                );
            }
            $commonSemantics = $semantics;
            $fingerprint = CartContinuationCandidate::fingerprint($candidate);
            if (isset($seen[$fingerprint])) {
                throw new ContractViolation(
                    'pending_cart_candidates_invalid',
                    'Target clarification candidates must be unique.'
                );
            }
            $labelIdentity = $this->identity($label);
            if ($labelIdentity === '' || isset($seenLabels[$labelIdentity])) {
                throw new ContractViolation(
                    'pending_cart_candidate_labels_ambiguous',
                    'Target clarification candidates require distinct customer-visible labels.'
                );
            }
            $seen[$fingerprint] = true;
            $seenLabels[$labelIdentity] = true;
            $candidates[] = $candidate;
            $labels[] = $label;
        }
        $label = Utf8::truncate(implode(' / ', $labels), 500);
        return $this->intent(
            (string) $spec['action'],
            array('kind' => 'command_candidates', 'candidates' => $candidates),
            0,
            PendingCartIntent::MISSING_TARGET,
            $label,
            $question
        );
    }

    /** @param array<string,mixed> $row */
    private function candidateLabel(
        array $row,
        CartCommand $command,
        AgentContext $context
    ): string {
        $target = TrustedCommerceText::decodeEntities($command->displayName());
        if ($command->type() === CartCommand::ADD) {
            return $target;
        }
        $item = isset($row['cart_item_ref']) && is_string($row['cart_item_ref'])
            ? $context->authority()->requireCartItem($row['cart_item_ref']) : array();
        $source = $this->cartItemLabel($item);
        if ($command->type() !== CartCommand::REPLACE) {
            return $source;
        }
        if ($source === '' || $target === '') {
            throw new ContractViolation(
                'pending_cart_candidates_invalid',
                'A replacement candidate requires source and destination labels.'
            );
        }
        return Utf8::truncate($source . ' ← ' . $target, 500);
    }

    /** @param array<string,mixed> $item */
    private function cartItemLabel(array $item): string
    {
        $label = TrustedCommerceText::decodeEntities((string) ($item['name'] ?? ''));
        $details = array();
        $seen = array();
        foreach (array('attributes', 'item_data') as $field) {
            foreach ((array) ($item[$field] ?? array()) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = TrustedCommerceText::decodeEntities((string) ($row['label'] ?? ''));
                $value = TrustedCommerceText::decodeEntities((string) ($row['value'] ?? ''));
                $identity = $this->identity($name) . "\0" . $this->identity($value);
                if ($name !== '' && $value !== '' && !isset($seen[$identity])) {
                    $seen[$identity] = true;
                    $details[] = $name . ': ' . $value;
                }
            }
        }
        if ($details !== array()) {
            $label .= ' (' . implode('، ', array_slice($details, 0, 32)) . ')';
        }
        return Utf8::truncate($label, 500);
    }

    /**
     * Projects only bounded live facts needed to verify the model's question.
     * Long option catalogs are deliberately truncated: when values_complete is
     * false, the model may ask for the axis naturally but may name only listed
     * values. The durable target still fingerprints the complete live catalog.
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function questionEvidence(
        array $spec,
        PendingCartIntent $intent,
        AgentContext $context
    ): array {
        if ($intent->missing() === PendingCartIntent::MISSING_TARGET) {
            $labels = $intent->forModel()['candidate_labels'];
            $options = $intent->forModel()['candidate_options'];
            return array(
                'missing_kind' => PendingCartIntent::MISSING_TARGET,
                'candidate_labels' => $labels,
                'candidate_options' => $options,
                'candidate_count' => count($labels),
                'candidates_complete' => true,
            );
        }
        if ($intent->missing() === PendingCartIntent::MISSING_QUANTITY) {
            $item = $context->authority()->requireCartItem((string) $spec['target_ref']);
            $quantity = $item['quantity'] ?? null;
            if (!CartQuantity::isPositiveInteger($quantity)) {
                throw new ContractViolation(
                    'pending_cart_line_invalid',
                    'The current cart line has no valid live quantity.'
                );
            }
            return array(
                'missing_kind' => PendingCartIntent::MISSING_QUANTITY,
                'quantity_mode' => (string) ($intent->target()['quantity_mode'] ?? ''),
                'current_quantity' => (int) $quantity,
            );
        }

        $product = $context->authority()->requireProduct((string) $spec['target_ref']);
        $axes = $this->variableProducts->inspect($product)['axes'];
        $inspection = $context->authority()->variationCatalogForProduct(
            (int) $product['id']
        );
        $target = $intent->target();
        if (
            !is_string($target['variation_catalog_epoch'] ?? null)
            || !is_string($inspection['epoch'] ?? null)
            || !hash_equals($target['variation_catalog_epoch'], $inspection['epoch'])
        ) {
            throw new ContractViolation(
                'cart_clarification_variation_catalog_changed',
                'The live variation catalog changed while the clarification was assembled.'
            );
        }
        $variations = $inspection['variations'];
        if ($variations === array()) {
            throw new ContractViolation(
                'cart_clarification_variations_not_inspected',
                'The model must inspect current live variations before asking for an option.'
            );
        }
        $available = $this->availableVariationValues(
            $variations,
            (array) ($target['bound_attributes'] ?? array())
        );
        $combinations = $this->availableVariationCombinations(
            $variations,
            (array) ($target['bound_attributes'] ?? array())
        );
        if ($combinations === array()) {
            throw new ContractViolation(
                'cart_clarification_options_unavailable',
                'No inspected purchasable variation matches the selected option tuple.'
            );
        }
        $rows = array();
        $remainingValues = 24;
        foreach ((array) ($target['missing_attributes'] ?? array()) as $missingLabel) {
            if (!is_string($missingLabel)) {
                continue;
            }
            $identity = $this->identity($missingLabel);
            if (!isset($axes[$identity])) {
                throw new ContractViolation(
                    'pending_cart_variation_axes_invalid',
                    'A missing variation axis is no longer present in live authority.'
                );
            }
            $liveValues = isset($available[$identity])
                ? array_values($available[$identity]) : array();
            if ($liveValues === array()) {
                throw new ContractViolation(
                    'cart_clarification_options_unavailable',
                    'No inspected purchasable variation supplies one missing option.'
                );
            }
            $listed = array();
            $axisLimit = min(8, $remainingValues);
            foreach (array_slice($liveValues, 0, $axisLimit) as $value) {
                $listed[] = Utf8::truncate(TrustedCommerceText::decodeEntities($value), 120);
            }
            $remainingValues -= count($listed);
            $rows[] = array(
                'label' => Utf8::truncate(
                    TrustedCommerceText::decodeEntities($axes[$identity]['label']),
                    120
                ),
                'listed_values' => $listed,
                'value_count' => count($liveValues),
                'values_complete' => $inspection['complete']
                    && count($listed) === count($liveValues),
            );
        }
        if ($rows === array()) {
            throw new ContractViolation(
                'pending_cart_variation_axes_invalid',
                'A variation clarification has no live missing-axis evidence.'
            );
        }
        return array(
            'missing_kind' => PendingCartIntent::MISSING_VARIATION,
            'missing_axes' => $rows,
            'listed_valid_combinations' => array_slice($combinations, 0, 24),
            'combination_count' => count($combinations),
            'combinations_complete' => $inspection['complete']
                && count($combinations) <= 24,
            'catalog_complete' => $inspection['complete'],
        );
    }

    /**
     * @param array<int,array<string,mixed>> $variations
     * @param array<int,mixed> $boundAttributes
     * @return array<string,array<string,string>>
     */
    private function availableVariationValues(
        array $variations,
        array $boundAttributes
    ): array {
        $required = array();
        foreach ($boundAttributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $required[$this->identity((string) ($attribute['label'] ?? ''))]
                = $this->identity((string) ($attribute['value'] ?? ''));
        }

        $available = array();
        foreach ($variations as $variation) {
            if (
                ($variation['purchasable'] ?? true) === false
                || ($variation['in_stock'] ?? true) === false
            ) {
                continue;
            }
            $actual = array();
            foreach ((array) ($variation['attributes'] ?? array()) as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $label = $this->identity((string) ($attribute['label'] ?? ''));
                $display = (string) ($attribute['display'] ?? '');
                $value = TrustedCommerceText::decodeEntities(
                    $display !== '' ? $display : (string) ($attribute['value'] ?? '')
                );
                $valueIdentity = $this->identity($value);
                if ($label !== '' && $valueIdentity !== '') {
                    $actual[$label] = array('identity' => $valueIdentity, 'value' => $value);
                }
            }
            $matches = true;
            foreach ($required as $label => $value) {
                if (
                    !isset($actual[$label])
                    || !hash_equals($value, $actual[$label]['identity'])
                ) {
                    $matches = false;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            foreach ($actual as $label => $row) {
                $available[$label][$row['identity']] = $row['value'];
            }
        }
        foreach ($available as &$values) {
            ksort($values, SORT_STRING);
        }
        unset($values);
        ksort($available, SORT_STRING);
        return $available;
    }

    /**
     * Preserves option tuples instead of presenting independent axis values as
     * though every cross-product were purchasable.
     *
     * @param array<int,array<string,mixed>> $variations
     * @param array<int,mixed> $boundAttributes
     * @return array<int,array<int,array{label:string,value:string}>>
     */
    private function availableVariationCombinations(
        array $variations,
        array $boundAttributes
    ): array {
        $required = array();
        foreach ($boundAttributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $required[$this->identity((string) ($attribute['label'] ?? ''))]
                = $this->identity((string) ($attribute['value'] ?? ''));
        }

        $out = array();
        $seen = array();
        foreach ($variations as $variation) {
            if (
                ($variation['purchasable'] ?? true) === false
                || ($variation['in_stock'] ?? true) === false
            ) {
                continue;
            }
            $actual = array();
            foreach ((array) ($variation['attributes'] ?? array()) as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $label = TrustedCommerceText::decodeEntities((string) ($attribute['label'] ?? ''));
                $display = (string) ($attribute['display'] ?? '');
                $value = TrustedCommerceText::decodeEntities(
                    $display !== '' ? $display : (string) ($attribute['value'] ?? '')
                );
                $labelIdentity = $this->identity($label);
                $valueIdentity = $this->identity($value);
                if (
                    $labelIdentity === '' || $valueIdentity === ''
                    || isset($actual[$labelIdentity])
                ) {
                    $actual = array();
                    break;
                }
                $actual[$labelIdentity] = array(
                    'label' => Utf8::truncate($label, 120),
                    'value' => Utf8::truncate($value, 120),
                    'identity' => $valueIdentity,
                );
            }
            if ($actual === array()) {
                continue;
            }
            $matches = true;
            foreach ($required as $labelIdentity => $valueIdentity) {
                if (
                    !isset($actual[$labelIdentity])
                    || !hash_equals($valueIdentity, $actual[$labelIdentity]['identity'])
                ) {
                    $matches = false;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            ksort($actual, SORT_STRING);
            $combination = array();
            $fingerprintParts = array();
            foreach ($actual as $labelIdentity => $row) {
                $combination[] = array('label' => $row['label'], 'value' => $row['value']);
                $fingerprintParts[] = $labelIdentity . '=' . $row['identity'];
            }
            $fingerprint = hash('sha256', implode("\0", $fingerprintParts));
            if (!isset($seen[$fingerprint])) {
                $seen[$fingerprint] = true;
                $out[] = $combination;
            }
        }
        return $out;
    }

    /**
     * @param array<int,mixed> $selected
     * @param array<string,array{label:string,values:array<string,string>}> $axes
     * @return array<string,array{label:string,value:string}>
     */
    private function selectedAttributes(array $selected, array $axes): array
    {
        if (!Arr::isList($selected) || count($selected) > 16) {
            throw new ContractViolation(
                'pending_cart_selected_attributes_invalid',
                'Selected variation attributes must be a bounded list.'
            );
        }
        $bound = array();
        foreach ($selected as $attribute) {
            if (!is_array($attribute)) {
                throw new ContractViolation(
                    'pending_cart_selected_attributes_invalid',
                    'A selected variation attribute is malformed.'
                );
            }
            $keys = array_keys($attribute);
            sort($keys, SORT_STRING);
            if (
                $keys !== array('label', 'value')
                || !is_string($attribute['label']) || !is_string($attribute['value'])
            ) {
                throw new ContractViolation(
                    'pending_cart_selected_attributes_invalid',
                    'A selected variation attribute must contain only label and value.'
                );
            }
            $axisIdentity = $this->identity($attribute['label']);
            $valueIdentity = $this->identity($attribute['value']);
            if (
                !isset($axes[$axisIdentity])
                || !isset($axes[$axisIdentity]['values'][$valueIdentity])
                || isset($bound[$axisIdentity])
            ) {
                throw new ContractViolation(
                    'pending_cart_selected_attributes_invalid',
                    'A selected variation attribute is not one unique current live option.'
                );
            }
            $bound[$axisIdentity] = array(
                'label' => $axes[$axisIdentity]['label'],
                'value' => $axes[$axisIdentity]['values'][$valueIdentity],
            );
        }
        return $bound;
    }

    /**
     * @param array<string,mixed> $productAuthority
     * @param array<string,mixed> $replacement
     */
    private function sameVariationContinuation(
        PendingCartIntent $prior,
        string $action,
        array $productAuthority,
        string $catalogEpoch,
        int $quantity,
        array $replacement
    ): bool {
        if (
            $prior->missing() !== PendingCartIntent::MISSING_VARIATION
            || $prior->action() !== $action
            || $prior->quantity() !== $quantity
        ) {
            return false;
        }
        $target = $prior->target();
        if (
            (int) ($target['product_id'] ?? 0) !== (int) $productAuthority['product_id']
            || !is_string($target['product_fingerprint'] ?? null)
            || !hash_equals($target['product_fingerprint'], $productAuthority['product_fingerprint'])
            || !is_string($target['variation_axes_fingerprint'] ?? null)
            || !hash_equals($target['variation_axes_fingerprint'], $productAuthority['axes_fingerprint'])
            || !is_string($target['variation_catalog_epoch'] ?? null)
            || !hash_equals($target['variation_catalog_epoch'], $catalogEpoch)
        ) {
            return false;
        }
        if ($action === CartCommand::ADD) {
            return ($target['kind'] ?? '') === 'product' && $replacement === array();
        }
        return $action === CartCommand::REPLACE
            && ($target['kind'] ?? '') === 'replacement'
            && (string) ($target['source_cart_item_key'] ?? '')
                === (string) ($replacement['source_cart_item_key'] ?? '')
            && (string) ($target['source_line_fingerprint'] ?? '')
                === (string) ($replacement['source_line_fingerprint'] ?? '')
            && (string) ($target['quantity_mode'] ?? '')
                === (string) ($replacement['quantity_mode'] ?? '');
    }

    /** @param array<string,mixed> $target */
    private function intent(
        string $action,
        array $target,
        int $quantity,
        string $missing,
        string $label,
        ModelAuthoredQuestion $question
    ): PendingCartIntent {
        $now = $this->clock->now();
        return new PendingCartIntent(
            Uuid::v4(),
            $action,
            $target,
            $quantity,
            $missing,
            $label,
            $question,
            $now,
            $now + 1200
        );
    }

    /** @param array<string,mixed> $spec */
    private function assertSpec(array $spec): void
    {
        $allowed = array(
            'candidate_commands',
            'action', 'intent_text', 'missing', 'quantity', 'quantity_mode',
            'selected_attributes', 'source_cart_item_ref', 'target_ref',
        );
        foreach (array_keys($spec) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new ContractViolation(
                    'pending_cart_intent_contract_invalid',
                    'The cart continuation contains an unsupported field.'
                );
            }
        }
        foreach (array('action', 'intent_text', 'missing') as $required) {
            if (
                !isset($spec[$required]) || !is_string($spec[$required])
                || trim($spec[$required]) === ''
            ) {
                throw new ContractViolation(
                    'pending_cart_intent_contract_invalid',
                    'The cart continuation is missing a required field.'
                );
            }
        }

        if ($spec['missing'] === PendingCartIntent::MISSING_TARGET) {
            if (
                !isset($spec['candidate_commands'])
                || !is_array($spec['candidate_commands'])
                || !Arr::isList($spec['candidate_commands'])
                || array_key_exists('target_ref', $spec)
                || array_key_exists('source_cart_item_ref', $spec)
                || array_key_exists('quantity_mode', $spec)
                || array_key_exists('quantity', $spec)
                || array_key_exists('selected_attributes', $spec)
                || !in_array($spec['action'], array(
                    CartCommand::ADD, CartCommand::UPDATE,
                    CartCommand::REMOVE, CartCommand::REPLACE,
                ), true)
            ) {
                throw new ContractViolation(
                    'pending_cart_intent_contract_invalid',
                    'A target continuation requires only bounded complete candidate commands.'
                );
            }
            return;
        }

        if (
            !isset($spec['target_ref']) || !is_string($spec['target_ref'])
            || trim($spec['target_ref']) === ''
            || array_key_exists('candidate_commands', $spec)
        ) {
            throw new ContractViolation(
                'pending_cart_intent_contract_invalid',
                'A variation or quantity continuation requires one live target reference.'
            );
        }

        if ($spec['missing'] === PendingCartIntent::MISSING_VARIATION) {
            $isAdd = $spec['action'] === CartCommand::ADD;
            $isReplace = $spec['action'] === CartCommand::REPLACE;
            $mode = isset($spec['quantity_mode']) && is_string($spec['quantity_mode'])
                ? $spec['quantity_mode'] : '';
            if (
                (!$isAdd && !$isReplace)
                || ($isAdd && (array_key_exists('quantity_mode', $spec)
                    || array_key_exists('source_cart_item_ref', $spec)))
                || ($isReplace && (!isset($spec['source_cart_item_ref'])
                    || !is_string($spec['source_cart_item_ref'])
                    || trim($spec['source_cart_item_ref']) === ''
                    || !in_array($mode, array('preserve', 'exact'), true)
                    || ($mode === 'preserve' && array_key_exists('quantity', $spec))
                    || ($mode === 'exact' && !isset($spec['quantity']))))
                || (isset($spec['quantity']) && !is_int($spec['quantity']))
                || (isset($spec['selected_attributes'])
                    && (!is_array($spec['selected_attributes'])
                        || !Arr::isList($spec['selected_attributes'])))
            ) {
                throw new ContractViolation(
                    'pending_cart_intent_contract_invalid',
                    'A variation continuation has contradictory fields.'
                );
            }
            return;
        }

        if (
            $spec['missing'] !== PendingCartIntent::MISSING_QUANTITY
            || $spec['action'] !== CartCommand::UPDATE
            || !isset($spec['quantity_mode']) || !is_string($spec['quantity_mode'])
            || !in_array($spec['quantity_mode'], array('set', 'increment', 'decrement'), true)
            || array_key_exists('quantity', $spec)
            || array_key_exists('selected_attributes', $spec)
            || array_key_exists('source_cart_item_ref', $spec)
        ) {
            throw new ContractViolation(
                'pending_cart_intent_contract_invalid',
                'A quantity continuation has contradictory fields.'
            );
        }
    }

    private function identity(string $value): string
    {
        return $this->text->normalize($value);
    }
}
