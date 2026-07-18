<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use YassinStore\AiAssistant\Domain\Commerce\AppliedCartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartCommand;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Commerce\CartVerification;
use YassinStore\AiAssistant\Support\Json;

/**
 * Verifies an exact, ordered cart delta. Only lines targeted by the durable
 * command/effect pair may change; every unrelated line and coupon must remain
 * byte-for-byte equivalent in canonical authority form.
 */
final class CartDeltaVerifier
{
    /** @var CartLineAuthorityPolicy */ private $authority;

    public function __construct(?CartLineAuthorityPolicy $authority = null)
    {
        $this->authority = $authority ?: new CartLineAuthorityPolicy();
    }

    public function verify(
        CartPlan $plan,
        CartSnapshot $pre,
        CartSnapshot $post,
        AppliedCartPlan $applied
    ): CartVerification {
        if ($applied->count() !== count($plan->commands())) {
            return CartVerification::rejected('effect_count_mismatch');
        }
        return $this->verifyEffect($plan, $pre, $post, $applied);
    }

    /** Verifies that the current cart is exactly the sole recorded command. */
    private function verifyEffect(
        CartPlan $plan,
        CartSnapshot $pre,
        CartSnapshot $current,
        AppliedCartPlan $applied
    ): CartVerification {
        $commands = $plan->commands();
        $effects = $applied->effects();
        if (count($effects) !== count($commands)) {
            return CartVerification::rejected('effect_count_mismatch');
        }

        $expected = $this->lineAuthority($pre);
        $currentLines = $this->lineAuthority($current);
        $expectedCoupons = $pre->coupons();

        foreach ($effects as $index => $effect) {
            $command = $commands[$index] ?? null;
            if (!$command instanceof CartCommand || (string) ($effect['type'] ?? '') !== $command->type()) {
                return CartVerification::rejected('effect_type_mismatch');
            }
            $reason = $this->applyEffect($command, $effect, $expected, $currentLines, $expectedCoupons);
            if ($reason !== '') {
                return CartVerification::rejected($reason);
            }
        }

        if (
            !$this->same($expected, $currentLines)
            || !$this->same($expectedCoupons, $current->coupons())
        ) {
            return CartVerification::rejected('unexpected_cart_delta');
        }

        $changed = !hash_equals($pre->revision(), $current->revision());
        if (!$changed && !hash_equals($pre->restorationRevision(), $current->restorationRevision())) {
            return CartVerification::rejected('non_authority_cart_state_changed');
        }
        return CartVerification::verified($changed);
    }

    public function wouldChange(CartPlan $plan, CartSnapshot $pre): bool
    {
        foreach ($plan->commands() as $command) {
            if ($command->type() === CartCommand::ADD) {
                return true;
            }
            if ($command->type() === CartCommand::REPLACE) {
                return true;
            }
            if ($command->type() === CartCommand::CLEAR) {
                return !$pre->isEmpty() || $pre->coupons() !== array();
            }
            $line = $pre->line($command->cartItemKey());
            if ($line === null) {
                return true;
            }
            if ($command->type() === CartCommand::REMOVE) {
                return true;
            }
            if (
                $command->type() === CartCommand::UPDATE
                && !CartQuantity::equals($line->quantity(), $command->quantity())
            ) {
                return true;
            }
        }
        return false;
    }

    public function changedLineCount(CartSnapshot $pre, CartSnapshot $post): int
    {
        $before = $this->lineAuthority($pre);
        $after = $this->lineAuthority($post);
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $count = 0;
        foreach ($keys as $key) {
            if (!$this->same($before[$key] ?? null, $after[$key] ?? null)) {
                ++$count;
            }
        }
        return $count;
    }

    /**
     * @param array<string,mixed> $effect
     * @param array<string,array<string,mixed>> $expected
     * @param array<string,array<string,mixed>> $current
     * @param array<int,string> $coupons
     */
    private function applyEffect(
        CartCommand $command,
        array $effect,
        array &$expected,
        array $current,
        array &$coupons
    ): string {
        $type = $command->type();
        if ($type === CartCommand::CLEAR) {
            if ((int) ($effect['previous_line_count'] ?? -1) !== count($expected)) {
                return 'clear_previous_count_mismatch';
            }
            $expected = array();
            $coupons = array();
            return '';
        }

        if ($type === CartCommand::UPDATE) {
            $key = $command->cartItemKey();
            if (!$this->effectTargetMatches($effect, $command, $key) || !isset($expected[$key])) {
                return 'update_effect_mismatch';
            }
            $previous = (float) ($expected[$key]['quantity'] ?? 0);
            if (!CartQuantity::equals((float) ($effect['previous_quantity'] ?? -1), $previous)) {
                return 'update_previous_quantity_mismatch';
            }
            return $this->adoptQuantityTarget($command, $key, $expected, $current, 'update');
        }

        if ($type === CartCommand::REMOVE) {
            $key = $command->cartItemKey();
            if (!$this->effectIdentityMatches($effect, $command, $key) || !isset($expected[$key])) {
                return 'remove_effect_mismatch';
            }
            if (
                !CartQuantity::equals(
                    (float) ($effect['previous_quantity'] ?? -1),
                    (float) ($expected[$key]['quantity'] ?? 0)
                )
            ) {
                return 'remove_previous_quantity_mismatch';
            }
            unset($expected[$key]);
            return '';
        }

        if ($type === CartCommand::ADD) {
            $key = (string) ($effect['cart_item_key'] ?? '');
            if ($key === '' || !$this->additionEnvelopeMatches($effect, $command)) {
                return 'add_effect_mismatch';
            }
            return $this->applyAddedTarget($command, $effect, $key, $expected, $current);
        }

        if ($type === CartCommand::REPLACE) {
            $sourceKey = $command->cartItemKey();
            $targetKey = (string) ($effect['target_cart_item_key'] ?? '');
            if (
                $targetKey === '' || hash_equals($sourceKey, $targetKey)
                || (string) ($effect['source_cart_item_key'] ?? '') !== $sourceKey
                || (string) ($effect['display_name'] ?? '') !== $command->displayName()
                || !isset($expected[$sourceKey])
                || !CartQuantity::equals(
                    (float) ($effect['source_previous_quantity'] ?? -1),
                    (float) ($expected[$sourceKey]['quantity'] ?? 0)
                )
                || !$this->additionEnvelopeMatches($effect, $command)
            ) {
                return 'replace_effect_mismatch';
            }
            unset($expected[$sourceKey]);
            $targetEffect = $effect;
            $targetEffect['cart_item_key'] = $targetKey;
            $targetEffect['previous_quantity'] = (float) ($effect['target_previous_quantity'] ?? -1);
            return $this->applyAddedTarget(
                $command,
                $targetEffect,
                $targetKey,
                $expected,
                $current
            );
        }

        return 'unsupported_command';
    }

    /** @param array<string,mixed> $effect */
    private function effectIdentityMatches(array $effect, CartCommand $command, string $key): bool
    {
        return (string) ($effect['cart_item_key'] ?? '') === $key
            && (string) ($effect['display_name'] ?? '') === $command->displayName();
    }

    /** @param array<string,mixed> $effect */
    private function effectTargetMatches(array $effect, CartCommand $command, string $key): bool
    {
        return $this->effectIdentityMatches($effect, $command, $key)
            && CartQuantity::equals((float) ($effect['quantity'] ?? -1), $command->quantity());
    }

    /** @param array<string,mixed> $effect */
    private function additionEnvelopeMatches(array $effect, CartCommand $command): bool
    {
        return (int) ($effect['product_id'] ?? 0) === $command->productId()
            && (int) ($effect['variation_id'] ?? -1) === $command->variationId()
            && CartQuantity::equals((float) ($effect['quantity'] ?? -1), $command->quantity())
            && (string) ($effect['display_name'] ?? '') === $command->displayName();
    }

    /**
     * @param array<string,mixed> $effect
     * @param array<string,array<string,mixed>> $expected
     * @param array<string,array<string,mixed>> $current
     */
    private function applyAddedTarget(
        CartCommand $command,
        array $effect,
        string $key,
        array &$expected,
        array $current
    ): string {
        if (!isset($current[$key])) {
            return 'added_target_missing';
        }
        $actual = $current[$key];
        if (
            (int) ($actual['product_id'] ?? 0) !== $command->productId()
            || (int) ($actual['variation_id'] ?? -1) !== $command->variationId()
        ) {
            return 'added_target_identity_mismatch';
        }
        $previous = isset($expected[$key]) ? (float) ($expected[$key]['quantity'] ?? 0) : 0.0;
        if (
            !CartQuantity::equals((float) ($effect['previous_quantity'] ?? -1), $previous)
            || !CartQuantity::equals((float) ($actual['quantity'] ?? 0), $previous + $command->quantity())
        ) {
            return 'added_target_quantity_mismatch';
        }
        if (isset($expected[$key]) && !$this->authority->stableIdentityMatches($expected[$key], $actual)) {
            return 'added_target_identity_changed';
        }

        // Legitimate WooCommerce hooks may add metadata to the one affected
        // target. The exact observed target becomes expected; no other line may
        // change because the whole-state comparison follows this step.
        $expected[$key] = $actual;
        return '';
    }

    /** @return array<string,array<string,mixed>> */
    private function lineAuthority(CartSnapshot $snapshot): array
    {
        $authority = $snapshot->authorityArray();
        return is_array($authority['lines'] ?? null) ? $authority['lines'] : array();
    }

    /**
     * @param array<string,array<string,mixed>> $expected
     * @param array<string,array<string,mixed>> $current
     */
    private function adoptQuantityTarget(
        CartCommand $command,
        string $key,
        array &$expected,
        array $current,
        string $reasonPrefix
    ): string {
        if (!isset($current[$key])) {
            return $reasonPrefix . '_target_missing';
        }
        $actual = $current[$key];
        if (!$this->authority->stableIdentityMatches($expected[$key], $actual)) {
            return $reasonPrefix . '_target_identity_changed';
        }
        if (!CartQuantity::equals((float) ($actual['quantity'] ?? 0), $command->quantity())) {
            return $reasonPrefix . '_target_quantity_mismatch';
        }

        // Target-only WooCommerce metadata may legitimately evolve during the
        // quantity mutation. Seal the exact observed target; the final whole-cart
        // comparison still rejects every unrelated line and coupon change.
        $expected[$key] = $actual;
        return '';
    }

    /** @param mixed $left @param mixed $right */
    private function same($left, $right): bool
    {
        return hash_equals(Json::canonical($left), Json::canonical($right));
    }
}
