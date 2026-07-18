<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\TrustedCommerceText;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;

/** Stable, non-executable description of one complete candidate cart plan. */
final class CartContinuationCandidate
{
    /**
     * @param array<string,mixed> $toolCommand
     * @return array<string,mixed>
     */
    public static function create(CartCommand $command, array $toolCommand, string $label): array
    {
        $requestedAction = isset($toolCommand['type']) && is_string($toolCommand['type'])
            ? $toolCommand['type'] : '';
        $quantityMode = isset($toolCommand['quantity_mode']) && is_string($toolCommand['quantity_mode'])
            ? $toolCommand['quantity_mode'] : 'none';
        $statedQuantity = array_key_exists('quantity', $toolCommand)
            && is_int($toolCommand['quantity']) ? $toolCommand['quantity'] : null;
        $label = TrustedCommerceText::decodeEntities($label);
        if (
            !in_array($requestedAction, CartCommand::types(), true)
            || $requestedAction === CartCommand::CLEAR
            || !in_array($quantityMode, array(
                'none', 'default', 'exact', 'set', 'increment', 'decrement', 'preserve',
            ), true)
            || $label === '' || !Utf8::isPlainText($label)
            || !Utf8::isBounded($label, 500, 2000)
        ) {
            throw new ContractViolation(
                'pending_cart_candidate_invalid',
                'A target clarification candidate is malformed.'
            );
        }
        return self::validate(array(
            'requested_action' => $requestedAction,
            'quantity_mode' => $quantityMode,
            'stated_quantity' => $statedQuantity,
            'command' => $command->toStorageArray(),
            'label' => $label,
        ));
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public static function validate(array $candidate): array
    {
        self::assertKeys($candidate, array(
            'command', 'label', 'quantity_mode', 'requested_action', 'stated_quantity',
        ));
        if (
            !is_string($candidate['requested_action'])
            || !in_array($candidate['requested_action'], CartCommand::types(), true)
            || $candidate['requested_action'] === CartCommand::CLEAR
            || !is_string($candidate['quantity_mode'])
            || !in_array($candidate['quantity_mode'], array(
                'none', 'default', 'exact', 'set', 'increment', 'decrement', 'preserve',
            ), true)
            || ($candidate['stated_quantity'] !== null
                && !CartQuantity::isStrictNonNegativeInteger($candidate['stated_quantity']))
            || !is_array($candidate['command']) || Arr::isList($candidate['command'])
            || !is_string($candidate['label'])
        ) {
            throw new InvalidArgumentException('Stored target clarification candidate is invalid.');
        }
        $command = CartCommand::fromStorageArray($candidate['command']);
        $label = TrustedCommerceText::decodeEntities($candidate['label']);
        if (
            $label === '' || !Utf8::isPlainText($label)
            || !Utf8::isBounded($label, 500, 2000)
            || !self::requestMatchesCommand(
                $candidate['requested_action'],
                $candidate['quantity_mode'],
                $candidate['stated_quantity'],
                $command
            )
        ) {
            throw new InvalidArgumentException('Stored target clarification candidate is contradictory.');
        }
        return array(
            'requested_action' => $candidate['requested_action'],
            'quantity_mode' => $candidate['quantity_mode'],
            'stated_quantity' => $candidate['stated_quantity'],
            'command' => $command->toStorageArray(),
            'label' => $label,
        );
    }

    /** @param array<string,mixed> $candidate */
    public static function fingerprint(array $candidate): string
    {
        $candidate = self::validate($candidate);
        unset($candidate['label']);
        return hash('sha256', "cart-continuation-candidate-v1\0" . Json::canonical($candidate));
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public static function semantics(array $candidate): array
    {
        $candidate = self::validate($candidate);
        return array(
            'requested_action' => $candidate['requested_action'],
            'quantity_mode' => $candidate['quantity_mode'],
            'stated_quantity' => $candidate['stated_quantity'],
        );
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public static function forModel(array $candidate): array
    {
        $candidate = self::validate($candidate);
        $command = CartCommand::fromStorageArray($candidate['command']);
        return array(
            'label' => $candidate['label'],
            'requested_action' => $candidate['requested_action'],
            'effective_action' => $command->type(),
            'quantity_mode' => $candidate['quantity_mode'],
            'stated_quantity' => $candidate['stated_quantity'],
            'resulting_quantity' => in_array($command->type(), array(
                CartCommand::ADD, CartCommand::UPDATE, CartCommand::REPLACE,
            ), true) ? (int) $command->quantity() : null,
        );
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $toolCommand
     */
    public static function matches(array $candidate, CartCommand $command, array $toolCommand): bool
    {
        try {
            $fresh = self::create($command, $toolCommand, $candidate['label'] ?? '');
            return hash_equals(self::fingerprint($candidate), self::fingerprint($fresh));
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /** @param mixed $statedQuantity */
    private static function requestMatchesCommand(
        string $requestedAction,
        string $quantityMode,
        $statedQuantity,
        CartCommand $command
    ): bool {
        if ($requestedAction === CartCommand::ADD) {
            return $command->type() === CartCommand::ADD
                && (($quantityMode === 'default' && $statedQuantity === null
                        && $command->quantity() === 1.0)
                    || ($quantityMode === 'exact' && is_int($statedQuantity)
                        && (float) $statedQuantity === $command->quantity()));
        }
        if ($requestedAction === CartCommand::UPDATE) {
            return in_array($command->type(), array(CartCommand::UPDATE, CartCommand::REMOVE), true)
                && in_array($quantityMode, array('set', 'increment', 'decrement'), true)
                && is_int($statedQuantity);
        }
        if ($requestedAction === CartCommand::REMOVE) {
            return $command->type() === CartCommand::REMOVE
                && $quantityMode === 'none' && $statedQuantity === null;
        }
        return $requestedAction === CartCommand::REPLACE
            && $command->type() === CartCommand::REPLACE
            && (($quantityMode === 'preserve' && $statedQuantity === null)
                || ($quantityMode === 'exact' && is_int($statedQuantity)
                    && (float) $statedQuantity === $command->quantity()));
    }

    /** @param array<string,mixed> $row @param array<int,string> $expected */
    private static function assertKeys(array $row, array $expected): void
    {
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Stored target clarification candidate fields are invalid.');
        }
    }
}
