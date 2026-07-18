<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Domain\Shopping\ShoppingMemoryPatch;

/**
 * Effect state is intentionally separate from opaque authority.
 * Authoritative mutation failures cannot be overwritten by lower-priority read
 * notices, and a verified receipt cannot be fabricated by model output.
 */
final class TurnEffects
{
    private const MAX_SAFE_MESSAGE_BYTES = 4096;

    /** @var ActionReceipt|null */ private $receipt;
    /** @var string */ private $viewedCartRevision = '';
    /** @var bool */ private $mutationsBlocked = false;
    /** @var string */ private $mutationCode = '';
    /** @var string */ private $mutationMessage = '';
    /** @var bool */ private $uncertain = false;
    /** @var bool */ private $mutationExecutionStarted = false;
    /** @var bool */ private $cartClarificationRequired = false;
    /** @var bool */ private $preservePendingCartIntent = false;
    /** @var string */ private $cartClarificationReason = '';
    /** @var string */ private $lastNotice = '';
    /** @var array<int,ShoppingMemoryPatch> */ private $shoppingMemoryPatches = array();

    public function recordViewedCartRevision(string $revision): void
    {
        $revision = strtolower(trim($revision));
        if (preg_match('/^[a-f0-9]{64}$/', $revision) !== 1) {
            throw new ContractViolation(
                'cart_view_revision_invalid',
                'A cart view must carry its exact server-owned cart revision.'
            );
        }
        $this->viewedCartRevision = $revision;
    }

    public function viewedCartRevision(): string
    {
        return $this->viewedCartRevision;
    }

    public function recordReceipt(ActionReceipt $receipt): void
    {
        if ($this->receipt !== null && $this->receipt->publicId() !== $receipt->publicId()) {
            throw new ContractViolation('multiple_receipts_in_turn', 'A turn cannot contain more than one verified commerce receipt.');
        }
        if ($this->mutationsBlocked && $this->receipt === null) {
            throw new ContractViolation('receipt_after_mutation_failure', 'A verified receipt cannot replace an authoritative mutation failure.');
        }
        $this->receipt = $receipt;
        $this->mutationsBlocked = true;
    }

    public function receipt(): ?ActionReceipt
    {
        return $this->receipt;
    }
    public function hasReceipt(): bool
    {
        return $this->receipt !== null;
    }

    /** Marks the exact boundary after semantic authorization and before Woo execution. */
    public function recordMutationExecutionStarted(): void
    {
        if ($this->receipt !== null || $this->mutationsBlocked) {
            throw new ContractViolation(
                'mutation_execution_boundary_invalid',
                'Cart execution cannot start after a terminal mutation outcome.'
            );
        }
        $this->mutationExecutionStarted = true;
    }

    public function mutationExecutionStarted(): bool
    {
        return $this->mutationExecutionStarted;
    }

    /**
     * Records a pre-execution semantic outcome that only the model may turn
     * into customer-facing clarification wording. This is not a mutation
     * failure: WooCommerce has not run and no server-authored question exists.
     */
    public function requireModelCartClarification(
        string $reason,
        bool $preservePending
    ): void {
        $reason = trim($reason);
        if (
            $this->receipt !== null || $this->mutationsBlocked || $this->mutationExecutionStarted
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reason) !== 1
        ) {
            throw new ContractViolation(
                'cart_clarification_state_invalid',
                'A model clarification cannot replace a cart execution outcome.'
            );
        }
        $this->cartClarificationRequired = true;
        $this->preservePendingCartIntent = $preservePending;
        if ($this->cartClarificationReason === '') {
            $this->cartClarificationReason = $reason;
        }
    }

    public function modelCartClarificationRequired(): bool
    {
        return $this->cartClarificationRequired;
    }

    public function preservePendingCartIntentForClarification(): bool
    {
        return $this->cartClarificationRequired && $this->preservePendingCartIntent;
    }

    public function cartClarificationReason(): string
    {
        return $this->cartClarificationReason;
    }

    public function recordMutationFailure(string $code, string $safeMessage, bool $uncertain = false): void
    {
        if ($this->receipt !== null) {
            throw new ContractViolation(
                'mutation_failure_after_receipt',
                'An authoritative mutation failure cannot be recorded after a verified commerce receipt.'
            );
        }
        $code = trim($code);
        $safeMessage = trim($safeMessage);
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
            throw new ContractViolation('mutation_failure_code_invalid', 'Mutation failure code is invalid.');
        }
        if ($safeMessage === '' || strlen($safeMessage) > self::MAX_SAFE_MESSAGE_BYTES) {
            throw new ContractViolation('mutation_failure_message_invalid', 'Mutation failure message is blank or too large.');
        }

        $this->mutationsBlocked = true;
        $this->uncertain = $this->uncertain || $uncertain;
        if ($this->mutationCode === '') {
            $this->mutationCode = $code;
        }
        // Preserve the first causal mutation message. A later read-tool notice
        // or secondary failure cannot suppress the state-changing failure.
        if ($this->mutationMessage === '') {
            $this->mutationMessage = $safeMessage;
        }
    }

    public function mutationsBlocked(): bool
    {
        return $this->mutationsBlocked;
    }
    public function mutationFailureCode(): string
    {
        return $this->mutationCode;
    }
    public function mutationFailureMessage(): string
    {
        return $this->mutationMessage;
    }
    public function stateMayBeUncertain(): bool
    {
        return $this->uncertain;
    }

    public function recordNotice(string $safeMessage): void
    {
        $safeMessage = trim($safeMessage);
        if ($safeMessage === '') {
            return;
        }
        if (strlen($safeMessage) > self::MAX_SAFE_MESSAGE_BYTES) {
            throw new ContractViolation('tool_notice_too_large', 'Tool notice exceeds the safe size limit.');
        }
        $this->lastNotice = $safeMessage;
    }

    public function recordShoppingMemoryPatch(ShoppingMemoryPatch $patch): void
    {
        if (count($this->shoppingMemoryPatches) >= 4) {
            throw new ContractViolation(
                'shopping_memory_patch_limit',
                'A turn cannot contain more than four shopping-memory transitions.'
            );
        }
        $this->shoppingMemoryPatches[] = $patch;
    }

    /** @return array<int,ShoppingMemoryPatch> */
    public function shoppingMemoryPatches(): array
    {
        return $this->shoppingMemoryPatches;
    }

    public function failureMessage(string $fallback): string
    {
        $fallback = trim($fallback);
        if (strlen($fallback) > self::MAX_SAFE_MESSAGE_BYTES) {
            throw new ContractViolation('fallback_message_too_large', 'Fallback message exceeds the safe size limit.');
        }
        if ($this->mutationsBlocked) {
            return $this->mutationMessage !== '' ? $this->mutationMessage : $fallback;
        }
        if ($this->lastNotice !== '') {
            return $this->lastNotice;
        }
        return $fallback;
    }
}
