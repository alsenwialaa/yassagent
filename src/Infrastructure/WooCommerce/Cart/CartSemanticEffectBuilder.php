<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;
use YassinStore\AiAssistant\Domain\Commerce\AppliedCartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartCommand;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;

/** Converts verified primitive-step evidence into one semantic applied cart plan. */
final class CartSemanticEffectBuilder
{
    /** @param array<int,CartOperationStep> $steps */
    public function build(CartPlan $plan, CartSnapshot $pre, array $steps): AppliedCartPlan
    {
        $byCommand = array();
        foreach ($steps as $step) {
            $byCommand[$step->commandIndex()][] = $step;
        }
        $effects = array();
        foreach ($plan->commands() as $index => $command) {
            $group = $byCommand[$index] ?? array();
            if ($group === array()) {
                throw new RuntimeException('Semantic command has no verified primitive proof.');
            }
            if ($command->type() === CartCommand::CLEAR) {
                $effects[] = array('type' => CartCommand::CLEAR, 'previous_line_count' => count($pre->lines()));
                continue;
            }
            if ($command->type() === CartCommand::ADD) {
                $effect = $this->effectForPhase($group, 'single');
                $effects[] = array(
                    'type' => CartCommand::ADD,
                    'cart_item_key' => (string) $effect['cart_item_key'],
                    'previous_quantity' => (float) $effect['previous_quantity'],
                    'quantity' => $command->quantity(),
                    'product_id' => $command->productId(),
                    'variation_id' => $command->variationId(),
                    'display_name' => $command->displayName(),
                );
                continue;
            }
            if ($command->type() === CartCommand::UPDATE) {
                $effect = $this->effectForPhase($group, 'single');
                $effects[] = array(
                    'type' => CartCommand::UPDATE,
                    'cart_item_key' => $command->cartItemKey(),
                    'previous_quantity' => (float) $effect['previous_quantity'],
                    'quantity' => $command->quantity(),
                    'display_name' => $command->displayName(),
                );
                continue;
            }
            if ($command->type() === CartCommand::REMOVE) {
                $effect = $this->effectForPhase($group, 'single');
                $effects[] = array(
                    'type' => CartCommand::REMOVE,
                    'cart_item_key' => $command->cartItemKey(),
                    'previous_quantity' => (float) $effect['previous_quantity'],
                    'display_name' => $command->displayName(),
                );
                continue;
            }
            if ($command->type() === CartCommand::REPLACE) {
                $effect = $this->effectForPhase($group, 'replace_atomic');
                $effects[] = array(
                    'type' => CartCommand::REPLACE,
                    'source_cart_item_key' => $command->cartItemKey(),
                    'source_previous_quantity' => (float) $effect['source_previous_quantity'],
                    'target_cart_item_key' => (string) $effect['target_cart_item_key'],
                    'target_previous_quantity' => (float) $effect['target_previous_quantity'],
                    'quantity' => $command->quantity(),
                    'product_id' => $command->productId(),
                    'variation_id' => $command->variationId(),
                    'display_name' => $command->displayName(),
                );
                continue;
            }

            throw new RuntimeException('Semantic cart command is unsupported.');
        }
        return new AppliedCartPlan($effects);
    }

    /** @param array<int,CartOperationStep> $steps @return array<string,mixed> */
    private function effectForPhase(array $steps, string $phase): array
    {
        foreach ($steps as $step) {
            if ($step->primitive()->phase() === $phase) {
                return $step->effect();
            }
        }
        throw new RuntimeException('Verified primitive phase evidence is missing.');
    }
}
