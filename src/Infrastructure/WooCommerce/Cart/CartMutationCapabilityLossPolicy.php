<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttempt;
use YassinStore\AiAssistant\Domain\Commerce\CartStepStatus;
use YassinStore\AiAssistant\Domain\Commerce\OperationRecord;
use YassinStore\AiAssistant\Domain\Commerce\OperationStatus;

/** Pure classification of unfinished journals when mutation capability disappears. */
final class CartMutationCapabilityLossPolicy
{
    /**
     * @param array<int,CartOperationStep>          $steps
     * @param array<int,CartStepAttempt|null>       $latestAttempts keyed by step id
     */
    public function provesNoEffect(
        OperationRecord $operation,
        array $steps,
        array $latestAttempts
    ): bool {
        if (
            !in_array($operation->status(), array(
            OperationStatus::PREPARED,
            OperationStatus::EXECUTING,
            ), true)
        ) {
            return false;
        }
        if ($steps === array()) {
            return true;
        }
        foreach ($steps as $step) {
            if (
                !$step instanceof CartOperationStep
                || $step->operationId() !== $operation->id()
                || !in_array($step->status(), array(
                    CartStepStatus::PREPARED,
                    CartStepStatus::APPLYING,
                ), true)
                || !array_key_exists($step->id(), $latestAttempts)
                || $latestAttempts[$step->id()] !== null
            ) {
                return false;
            }
        }
        return true;
    }
}
