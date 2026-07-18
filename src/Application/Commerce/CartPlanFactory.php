<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Commerce;

use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;
use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Authority\AuthorityRegistry;
use YassinStore\AiAssistant\Domain\Commerce\CartCommand;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartPurchaseIdentity;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;

/** Maps current-turn opaque evidence references into resolved server commands. */
final class CartPlanFactory
{
    /** @param array<string,mixed> $arguments */
    public function fromToolArguments(
        array $arguments,
        AuthorityRegistry $authority,
        string $viewedCartRevision = ''
    ): CartPlan {
        $rows = is_array($arguments['commands'] ?? null) ? $arguments['commands'] : array();
        try {
            $commands = array();
            foreach ($rows as $index => $row) {
                if (!is_array($row)) {
                    throw new ContractViolation('cart_command_invalid', 'Cart command ' . $index . ' must be an object.');
                }
                $commands[] = $this->command($row, $authority, $index);
            }
            $isClear = count($commands) === 1 && $commands[0]->type() === CartCommand::CLEAR;
            if ($isClear && preg_match('/^[a-f0-9]{64}$/', $viewedCartRevision) !== 1) {
                throw new ContractViolation(
                    'cart_clear_requires_view',
                    'cart_view must supply its exact cart revision earlier in the same turn before clear.'
                );
            }
            $plan = new CartPlan($commands, $isClear ? $viewedCartRevision : '');
        } catch (InvalidArgumentException $exception) {
            throw new ContractViolation('cart_plan_invalid', $exception->getMessage());
        }

        return $plan;
    }

    /** @param array<string,mixed> $row */
    private function command(array $row, AuthorityRegistry $authority, int $index): CartCommand
    {
        $type = (string) ($row['type'] ?? '');
        if ($type === CartCommand::ADD) {
            $this->requireKeys(
                $row,
                array('type', 'product_ref', 'variation_ref', 'quantity_mode', 'quantity'),
                array('type', 'product_ref', 'quantity_mode'),
                $index
            );
            $product = $authority->requireProduct((string) $row['product_ref']);
            $variation = isset($row['variation_ref'])
                ? $authority->requireVariation((string) $row['variation_ref'])
                : null;
            $this->assertVariation($product, $variation);
            $purchaseIdentity = CartPurchaseIdentity::fromAuthority($product, $variation);
            $quantity = $this->addQuantity($row, $index);
            return CartCommand::add(
                (int) ($product['id'] ?? 0),
                $variation !== null ? (int) ($variation['id'] ?? 0) : 0,
                (float) $quantity,
                $purchaseIdentity->fingerprint(),
                (string) ($variation['name'] ?? $product['name'] ?? '')
            );
        }

        if ($type === CartCommand::UPDATE) {
            $this->requireKeys(
                $row,
                array('type', 'cart_item_ref', 'quantity_mode', 'quantity'),
                array('type', 'cart_item_ref', 'quantity_mode', 'quantity'),
                $index
            );
            $item = $authority->requireCartItem((string) $row['cart_item_ref']);
            $quantity = $this->updatedQuantity($row, $item, $index);
            if ($quantity === 0) {
                return CartCommand::remove(
                    (string) ($item['cart_item_key'] ?? ''),
                    (string) ($item['line_fingerprint'] ?? ''),
                    (string) ($item['name'] ?? '')
                );
            }
            return CartCommand::update(
                (string) ($item['cart_item_key'] ?? ''),
                (string) ($item['line_fingerprint'] ?? ''),
                (float) $quantity,
                (string) ($item['name'] ?? '')
            );
        }

        if ($type === CartCommand::REMOVE) {
            $this->requireKeys($row, array('type', 'cart_item_ref'), array('type', 'cart_item_ref'), $index);
            $item = $authority->requireCartItem((string) $row['cart_item_ref']);
            return CartCommand::remove(
                (string) ($item['cart_item_key'] ?? ''),
                (string) ($item['line_fingerprint'] ?? ''),
                (string) ($item['name'] ?? '')
            );
        }

        if ($type === CartCommand::REPLACE) {
            $this->requireKeys(
                $row,
                array(
                    'type', 'cart_item_ref', 'product_ref', 'variation_ref',
                    'quantity_mode', 'quantity',
                ),
                array('type', 'cart_item_ref', 'product_ref', 'quantity_mode'),
                $index
            );
            $source = $authority->requireCartItem((string) $row['cart_item_ref']);
            $product = $authority->requireProduct((string) $row['product_ref']);
            $variation = isset($row['variation_ref'])
                ? $authority->requireVariation((string) $row['variation_ref'])
                : null;
            $this->assertVariation($product, $variation);
            $purchaseIdentity = CartPurchaseIdentity::fromAuthority($product, $variation);
            $quantity = $this->replacementQuantity($row, $source, $index);
            return CartCommand::replace(
                (string) ($source['cart_item_key'] ?? ''),
                (string) ($source['line_fingerprint'] ?? ''),
                (int) ($product['id'] ?? 0),
                $variation !== null ? (int) ($variation['id'] ?? 0) : 0,
                (float) $quantity,
                $purchaseIdentity->fingerprint(),
                (string) ($variation['name'] ?? $product['name'] ?? '')
            );
        }

        if ($type === CartCommand::CLEAR) {
            $this->requireKeys($row, array('type'), array('type'), $index);
            return CartCommand::clear();
        }

        throw new ContractViolation('cart_command_type_invalid', 'Cart command ' . $index . ' has an unsupported type.');
    }

    /** @param array<string,mixed> $row */
    private function addQuantity(array $row, int $index): int
    {
        $mode = (string) ($row['quantity_mode'] ?? '');
        if ($mode === 'default' && !array_key_exists('quantity', $row)) {
            return 1;
        }
        if (
            $mode === 'exact' && isset($row['quantity'])
            && CartQuantity::isStrictPositiveInteger($row['quantity'])
        ) {
            return $row['quantity'];
        }
        throw new ContractViolation(
            'cart_quantity_mode_invalid',
            'Add command ' . $index . ' requires default without quantity or exact with quantity.'
        );
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $item */
    private function updatedQuantity(array $row, array $item, int $index): int
    {
        $mode = (string) ($row['quantity_mode'] ?? '');
        $value = isset($row['quantity']) && is_int($row['quantity']) ? $row['quantity'] : 0;
        $current = $this->currentQuantity($item, $index);
        if (
            !in_array($mode, array('set', 'increment', 'decrement'), true)
            || ($mode === 'set' ? $value < 0 : $value < 1)
        ) {
            throw new ContractViolation(
                'cart_quantity_mode_invalid',
                'Update command ' . $index . ' requires set, increment, or decrement with a positive integer.'
            );
        }
        $target = $mode === 'set' ? $value
            : ($mode === 'increment' ? $current + $value : $current - $value);
        if (!CartQuantity::isNonNegativeInteger($target)) {
            throw new ContractViolation(
                'cart_quantity_result_invalid',
                'Update command ' . $index . ' produces an unsupported cart quantity.'
            );
        }
        return $target;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $source */
    private function replacementQuantity(array $row, array $source, int $index): int
    {
        $mode = (string) ($row['quantity_mode'] ?? '');
        if ($mode === 'preserve' && !array_key_exists('quantity', $row)) {
            return $this->currentQuantity($source, $index);
        }
        if (
            $mode === 'exact' && isset($row['quantity'])
            && CartQuantity::isStrictPositiveInteger($row['quantity'])
        ) {
            return $row['quantity'];
        }
        throw new ContractViolation(
            'cart_quantity_mode_invalid',
            'Replace command ' . $index . ' requires preserve without quantity or exact with quantity.'
        );
    }

    /** @param array<string,mixed> $item */
    private function currentQuantity(array $item, int $index): int
    {
        $quantity = $item['quantity'] ?? null;
        if (!CartQuantity::isPositiveInteger($quantity)) {
            throw new ContractViolation(
                'cart_line_quantity_invalid',
                'Cart line for command ' . $index . ' has an unsupported quantity.'
            );
        }
        return (int) $quantity;
    }

    /** @param array<string,mixed> $product @param array<string,mixed>|null $variation */
    private function assertVariation(array $product, ?array $variation): void
    {
        $requires = !empty($product['requires_variation']);
        if ($requires && $variation === null) {
            throw new ContractViolation('variation_required', 'The selected variable product requires a current-turn variation_ref.');
        }
        if (!$requires && $variation !== null) {
            throw new ContractViolation('variation_not_allowed', 'The selected product does not accept a variation_ref.');
        }
        if ($variation !== null && (int) ($variation['parent_id'] ?? 0) !== (int) ($product['id'] ?? 0)) {
            throw new ContractViolation('variation_parent_mismatch', 'The variation_ref does not belong to the product_ref.');
        }
    }

    /** @param array<string,mixed> $row @param array<int,string> $allowed @param array<int,string> $required */
    private function requireKeys(array $row, array $allowed, array $required, int $index): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $row)) {
                throw new ContractViolation('cart_command_field_missing', 'Command ' . $index . ' requires ' . $key . '.');
            }
        }
        foreach (array_keys($row) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new ContractViolation('cart_command_field_invalid', 'Command ' . $index . ' does not allow ' . $key . '.');
            }
        }
    }
}
