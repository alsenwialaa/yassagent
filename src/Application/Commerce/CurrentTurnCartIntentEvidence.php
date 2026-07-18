<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Authority\AuthorityRegistry;
use YassinStore\AiAssistant\Domain\Commerce\CartCommand;
use YassinStore\AiAssistant\Domain\Commerce\CartContinuationCandidate;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\PendingCartIntent;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Shopping\CatalogTextNormalizer;
use YassinStore\AiAssistant\Support\Arr;

/**
 * Binds AI-interpreted cart semantics to customer text and live authority.
 *
 * This class deliberately does not interpret Arabic. It proves only that the
 * model copied current evidence byte-for-byte and whether the fresh live plan
 * completes the exact active server-owned target and missing-field contract.
 */
final class CurrentTurnCartIntentEvidence
{
    /** @var CatalogTextNormalizer */ private $text;
    /** @var VariableProductAuthority */ private $variableProducts;

    public function __construct(
        CatalogTextNormalizer $text,
        VariableProductAuthority $variableProducts
    ) {
        $this->text = $text;
        $this->variableProducts = $variableProducts;
    }

    /**
     * @param array<string,mixed> $toolArguments
     */
    public function assertForPlan(
        string $exactUserMessage,
        string $intentText,
        CartPlan $plan,
        AuthorityRegistry $authority,
        array $toolArguments,
        ?PendingCartIntent $pendingCartIntent = null,
        string $replyContext = ''
    ): string {
        $current = $this->currentMessage($exactUserMessage, $replyContext)->text();
        $matchesPending = $pendingCartIntent instanceof PendingCartIntent
            && $this->continuationMatches(
                $pendingCartIntent,
                $plan,
                $authority,
                $toolArguments
            );

        if (trim($current) === '') {
            throw new ContractViolation(
                'cart_intent_evidence_missing',
                'cart_apply requires an exact nonblank fragment from the current customer message.'
            );
        }
        $this->assertExactExcerpt($current, $intentText);

        // The continuation ID is server-owned routing state, not customer
        // intent and not model authority. When the exact live plan resolves the
        // active missing-field contract, bind it here even if the model omitted
        // the opaque ID. The model never receives authority to choose which
        // pending clarification a mutation completes.
        return $matchesPending && $pendingCartIntent instanceof PendingCartIntent
            ? $pendingCartIntent->id()
            : '';
    }

    public function assertCurrentExcerpt(
        string $exactUserMessage,
        string $intentText,
        string $replyContext = ''
    ): void {
        $current = $this->currentMessage($exactUserMessage, $replyContext)->text();
        if (trim($current) === '') {
            throw new ContractViolation(
                'cart_intent_evidence_missing',
                'A cart clarification requires a nonblank current customer fragment.'
            );
        }
        $this->assertExactExcerpt($current, $intentText);
    }

    /**
     * Returns only values newly supplied or corrected by the current terse
     * answer when a model-authored variation question is refined in place.
     * Stable product, cart-line, quantity, and live variation-catalog authority
     * must remain identical; language meaning is still judged by the isolated
     * verifier from the current customer text.
     *
     * @return array<int,array{label:string,value:string}>
     */
    public function variationRefinement(
        ?PendingCartIntent $prior,
        PendingCartIntent $next
    ): array {
        if (
            !$prior instanceof PendingCartIntent
            || $prior->missing() !== PendingCartIntent::MISSING_VARIATION
            || $next->missing() !== PendingCartIntent::MISSING_VARIATION
            || $prior->action() !== $next->action()
            || $prior->quantity() !== $next->quantity()
        ) {
            return array();
        }
        $before = $prior->target();
        $after = $next->target();
        foreach (
            array(
            'kind', 'product_id', 'product_fingerprint',
            'variation_axes_fingerprint', 'variation_catalog_epoch',
            ) as $key
        ) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                return array();
            }
        }
        if ($prior->action() === CartCommand::REPLACE) {
            foreach (
                array(
                'source_cart_item_key', 'source_line_fingerprint', 'quantity_mode',
                ) as $key
            ) {
                if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                    return array();
                }
            }
        }

        $priorBound = $this->attributeMap((array) ($before['bound_attributes'] ?? array()));
        $nextBound = $this->attributeMap((array) ($after['bound_attributes'] ?? array()));
        $priorMissing = array();
        foreach ((array) ($before['missing_attributes'] ?? array()) as $label) {
            if (!is_string($label)) {
                return array();
            }
            $identity = $this->identity($label);
            if ($identity === '' || isset($priorMissing[$identity])) {
                return array();
            }
            $priorMissing[$identity] = true;
        }
        foreach ($priorBound as $identity => $row) {
            if (!isset($nextBound[$identity])) {
                return array();
            }
        }

        $resolved = array();
        foreach ($nextBound as $identity => $row) {
            if (!isset($priorBound[$identity])) {
                if (!isset($priorMissing[$identity])) {
                    return array();
                }
                $resolved[] = array('label' => $row['label'], 'value' => $row['value']);
                continue;
            }
            if (!hash_equals($priorBound[$identity]['identity'], $row['identity'])) {
                $resolved[] = array('label' => $row['label'], 'value' => $row['value']);
            }
        }
        return $resolved;
    }

    private function currentMessage(string $message, string $replyContext): CurrentCustomerMessage
    {
        try {
            return new CurrentCustomerMessage($message, $replyContext);
        } catch (InvalidArgumentException $exception) {
            throw new ContractViolation(
                'cart_intent_evidence_missing',
                'A cart action requires nonblank customer-authored text in the current turn.'
            );
        }
    }

    private function assertExactExcerpt(string $current, string $intentText): void
    {
        if (
            $intentText === '' || trim($intentText) === ''
            || strpos($current, $intentText) === false
        ) {
            throw new ContractViolation(
                'cart_intent_evidence_not_current',
                'The cart intent evidence must be copied byte-for-byte from the current customer message.'
            );
        }
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<string,array{label:string,value:string,identity:string}>
     */
    private function attributeMap(array $rows): array
    {
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return array();
            }
            $label = (string) ($row['label'] ?? '');
            $value = (string) ($row['value'] ?? '');
            $labelIdentity = $this->identity($label);
            $valueIdentity = $this->identity($value);
            if ($labelIdentity === '' || $valueIdentity === '' || isset($out[$labelIdentity])) {
                return array();
            }
            $out[$labelIdentity] = array(
                'label' => $label,
                'value' => $value,
                'identity' => $valueIdentity,
            );
        }
        return $out;
    }

    /** @param array<string,mixed> $arguments */
    private function continuationMatches(
        PendingCartIntent $pending,
        CartPlan $plan,
        AuthorityRegistry $authority,
        array $arguments
    ): bool {
        $commands = $plan->commands();
        $command = count($commands) === 1 ? $commands[0] : null;
        $rows = is_array($arguments['commands'] ?? null)
            ? $arguments['commands'] : array();
        $row = count($rows) === 1 && is_array($rows[0]) ? $rows[0] : array();
        if (!$command instanceof CartCommand || $row === array()) {
            throw new ContractViolation(
                'cart_continuation_plan_invalid',
                'A cart continuation must resolve to exactly one command.'
            );
        }

        if ($pending->missing() === PendingCartIntent::MISSING_TARGET) {
            if ((string) ($row['type'] ?? '') !== $pending->action()) {
                return false;
            }
            foreach ((array) ($pending->target()['candidates'] ?? array()) as $candidate) {
                if (
                    is_array($candidate)
                    && CartContinuationCandidate::matches($candidate, $command, $row)
                ) {
                    return true;
                }
            }
            return false;
        }

        if ($pending->missing() === PendingCartIntent::MISSING_VARIATION) {
            $target = $pending->target();
            $product = isset($row['product_ref']) && is_string($row['product_ref'])
                ? $authority->requireProduct($row['product_ref']) : array();
            $variation = isset($row['variation_ref']) && is_string($row['variation_ref'])
                ? $authority->requireVariation($row['variation_ref']) : array();
            $commonMismatch = (int) ($target['product_id'] ?? 0) !== $command->productId()
                || (int) ($product['id'] ?? 0) !== $command->productId()
                || (int) ($variation['id'] ?? 0) !== $command->variationId()
                || (int) ($variation['parent_id'] ?? 0) !== $command->productId()
                || !$this->variationMatchesPending(
                    $product,
                    $variation,
                    $target,
                    $authority
                );
            if ($commonMismatch) {
                return false;
            }
            if ($pending->action() === CartCommand::ADD) {
                return $command->type() === CartCommand::ADD
                    && (string) ($row['type'] ?? '') === CartCommand::ADD
                    && (int) $command->quantity() === $pending->quantity()
                    && $this->pendingAddQuantityMatches($row, $pending->quantity());
            }
            if (
                $pending->action() !== CartCommand::REPLACE
                || $command->type() !== CartCommand::REPLACE
                || (string) ($row['type'] ?? '') !== CartCommand::REPLACE
            ) {
                return false;
            }
            $item = isset($row['cart_item_ref']) && is_string($row['cart_item_ref'])
                ? $authority->requireCartItem($row['cart_item_ref']) : array();
            return (string) ($item['cart_item_key'] ?? '')
                    === (string) ($target['source_cart_item_key'] ?? '')
                && (string) ($item['line_fingerprint'] ?? '')
                    === (string) ($target['source_line_fingerprint'] ?? '')
                && $command->cartItemKey()
                    === (string) ($target['source_cart_item_key'] ?? '')
                && $command->expectedLineFingerprint()
                    === (string) ($target['source_line_fingerprint'] ?? '')
                && $this->pendingReplacementQuantityMatches(
                    $row,
                    $item,
                    $command,
                    (string) ($target['quantity_mode'] ?? ''),
                    $pending->quantity()
                );
        }

        $target = $pending->target();
        $item = isset($row['cart_item_ref']) && is_string($row['cart_item_ref'])
            ? $authority->requireCartItem($row['cart_item_ref']) : array();
        return !($pending->missing() !== PendingCartIntent::MISSING_QUANTITY
            || $pending->action() !== CartCommand::UPDATE
            || !in_array($command->type(), array(CartCommand::UPDATE, CartCommand::REMOVE), true)
            || (string) ($row['type'] ?? '') !== CartCommand::UPDATE
            || (string) ($row['quantity_mode'] ?? '') !== (string) ($target['quantity_mode'] ?? '')
            || (string) ($item['cart_item_key'] ?? '') !== (string) ($target['cart_item_key'] ?? '')
            || (string) ($item['line_fingerprint'] ?? '') !== (string) ($target['line_fingerprint'] ?? '')
            || $command->cartItemKey() !== (string) ($target['cart_item_key'] ?? '')
            || $command->expectedLineFingerprint() !== (string) ($target['line_fingerprint'] ?? '')
        );
    }

    /** @param array<string,mixed> $row */
    private function pendingAddQuantityMatches(array $row, int $quantity): bool
    {
        $mode = (string) ($row['quantity_mode'] ?? '');
        if ($quantity === 1 && $mode === 'default' && !array_key_exists('quantity', $row)) {
            return true;
        }
        return $mode === 'exact'
            && isset($row['quantity']) && is_int($row['quantity'])
            && $row['quantity'] === $quantity;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $item */
    private function pendingReplacementQuantityMatches(
        array $row,
        array $item,
        CartCommand $command,
        string $mode,
        int $quantity
    ): bool {
        if ($mode === 'preserve') {
            $current = $item['quantity'] ?? null;
            return (string) ($row['quantity_mode'] ?? '') === 'preserve'
                && !array_key_exists('quantity', $row)
                && (is_int($current) || is_float($current))
                && is_finite((float) $current)
                && (float) $current === $command->quantity();
        }
        return $mode === 'exact'
            && (string) ($row['quantity_mode'] ?? '') === 'exact'
            && isset($row['quantity']) && is_int($row['quantity'])
            && $row['quantity'] === $quantity
            && (float) $quantity === $command->quantity();
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $variation @param array<string,mixed> $target */
    private function variationMatchesPending(
        array $product,
        array $variation,
        array $target,
        AuthorityRegistry $authority
    ): bool {
        if (
            !$this->variableProducts->matches($product, $target)
            || !$authority->variationBelongsToCatalog(
                (int) ($product['id'] ?? 0),
                (int) ($variation['id'] ?? 0),
                (string) ($target['variation_catalog_epoch'] ?? '')
            )
        ) {
            return false;
        }
        $attributes = $variation['attributes'] ?? null;
        if (!is_array($attributes) || !Arr::isList($attributes) || $attributes === array()) {
            return false;
        }
        $actual = array();
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                return false;
            }
            $label = $this->identity((string) ($attribute['label'] ?? ''));
            $display = trim((string) ($attribute['display'] ?? ''));
            $value = $this->identity($display !== ''
                ? $display : (string) ($attribute['value'] ?? ''));
            if ($label === '' || $value === '' || isset($actual[$label])) {
                return false;
            }
            $actual[$label] = $value;
        }
        foreach ((array) ($target['bound_attributes'] ?? array()) as $required) {
            if (!is_array($required)) {
                return false;
            }
            $label = $this->identity((string) ($required['label'] ?? ''));
            $value = $this->identity((string) ($required['value'] ?? ''));
            if (!isset($actual[$label]) || !hash_equals($value, $actual[$label])) {
                return false;
            }
        }
        foreach ((array) ($target['missing_attributes'] ?? array()) as $label) {
            if (!is_string($label) || !isset($actual[$this->identity($label)])) {
                return false;
            }
        }
        return count($actual) === count((array) ($target['bound_attributes'] ?? array()))
            + count((array) ($target['missing_attributes'] ?? array()));
    }

    private function identity(string $value): string
    {
        return $this->text->normalize($value);
    }
}
