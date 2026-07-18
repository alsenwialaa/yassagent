<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use Throwable;
use YassinStore\AiAssistant\Application\Commerce\CommerceExecutionContext;
use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttempt;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttemptStatus;
use YassinStore\AiAssistant\Domain\Commerce\CartStepStatus;
use YassinStore\AiAssistant\Domain\Commerce\OperationRecord;
use YassinStore\AiAssistant\Domain\Commerce\OperationStatus;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Infrastructure\Concurrency\TurnLeaseManager;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepAttemptRepository;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepRepository;
use YassinStore\AiAssistant\Infrastructure\Database\OperationRepository;

/** Owns durable terminal operation/step transitions and their safe public meaning. */
final class CartOperationTerminalizer
{
    /** @var OperationRepository */ private $operations;
    /** @var CartStepRepository */ private $steps;
    /** @var CartStepAttemptRepository */ private $attempts;
    /** @var CartLeaseScope */ private $scope;
    /** @var CartStateEvidence */ private $evidence;
    /** @var CartOperationMessages */ private $messages;
    /** @var WooSessionOperationMarker */ private $markers;
    /** @var TurnLeaseManager */ private $leases;

    public function __construct(
        OperationRepository $operations,
        CartStepRepository $steps,
        CartStepAttemptRepository $attempts,
        CartLeaseScope $scope,
        CartStateEvidence $evidence,
        CartOperationMessages $messages,
        WooSessionOperationMarker $markers,
        TurnLeaseManager $leases
    ) {
        $this->operations = $operations;
        $this->steps = $steps;
        $this->attempts = $attempts;
        $this->scope = $scope;
        $this->evidence = $evidence;
        $this->messages = $messages;
        $this->markers = $markers;
        $this->leases = $leases;
    }

    /**
     * @param array<int,CartOperationStep> $verified
     * @return never
     */
    public function failure(
        OperationRecord $operation,
        TurnLease $conversationLease,
        TurnLease $commerceLease,
        array $verified,
        SafeCommerceException $failure
    ): ActionReceipt {
        $observed = $this->evidence->captureOptional();
        $changed = $failure->stateMayHaveChanged() || $verified !== array();
        try {
            $this->scope->guarded($conversationLease, $commerceLease, function () use (
                $operation,
                $conversationLease,
                $commerceLease,
                $failure,
                $observed,
                $changed
            ): void {
                if ($changed) {
                    $this->operations->markUncertain(
                        $operation->id(),
                        $conversationLease->fence(),
                        $commerceLease->fence(),
                        $this->messages->reason($failure->reasonCode()),
                        $this->messages->uncertain(),
                        null
                    );
                    return;
                }
                $this->operations->markRejected(
                    $operation->id(),
                    $conversationLease->fence(),
                    $commerceLease->fence(),
                    $this->messages->reason($failure->reasonCode()),
                    $failure->safeMessage(),
                    $observed
                );
            });
        } catch (Throwable $exception) {
            throw $this->messages->pending('cart_terminal_persistence_pending', $exception->getMessage());
        }
        throw new SafeCommerceException(
            $this->messages->reason($failure->reasonCode()),
            $changed ? $this->messages->uncertain() : $failure->safeMessage(),
            $failure->getMessage(),
            $changed
        );
    }

    /** @return never */
    public function uncertain(
        OperationRecord $operation,
        TurnLease $conversationLease,
        TurnLease $commerceLease,
        string $reason,
        string $internal
    ): ActionReceipt {
        $observed = $this->evidence->captureOptional();
        try {
            $this->scope->guarded($conversationLease, $commerceLease, function () use (
                $operation,
                $conversationLease,
                $commerceLease,
                $reason,
                $observed
            ): void {
                $this->operations->markUncertain(
                    $operation->id(),
                    $conversationLease->fence(),
                    $commerceLease->fence(),
                    $this->messages->reason($reason),
                    $this->messages->uncertain(),
                    $observed
                );
            });
        } catch (Throwable $exception) {
            // Do not let a failed uncertainty write fall through to the generic
            // no-effect handler, which could terminalize an executing operation
            // as rejected. Exact replay must retry durable reconciliation.
            throw $this->messages->pending(
                'cart_uncertain_persistence_pending',
                $exception->getMessage()
            );
        }
        throw new SafeCommerceException(
            $this->messages->reason($reason),
            $this->messages->uncertain(),
            $internal,
            true
        );
    }

    public function rejectStep(
        CartOperationStep $step,
        CartStepAttempt $attempt,
        TurnLease $conversationLease,
        TurnLease $commerceLease,
        SafeCommerceException $failure
    ): void {
        try {
            $this->scope->guarded($conversationLease, $commerceLease, function () use (
                $step,
                $attempt,
                $conversationLease,
                $commerceLease,
                $failure
            ): void {
                if (!$attempt->isTerminal()) {
                    $this->attempts->markFailed(
                        $attempt->id(),
                        CartStepAttemptStatus::ABANDONED,
                        $this->messages->reason($failure->reasonCode()),
                        $failure->safeMessage()
                    );
                }
                $this->steps->markFailure(
                    $step->id(),
                    $conversationLease->fence(),
                    $commerceLease->fence(),
                    CartStepStatus::REJECTED,
                    $this->messages->reason($failure->reasonCode()),
                    $failure->safeMessage()
                );
            });
        } catch (Throwable $exception) {
            throw $this->messages->pending(
                'cart_step_rejection_persistence_pending',
                $exception->getMessage()
            );
        }
    }

    public function uncertainStep(
        CartOperationStep $step,
        CartStepAttempt $attempt,
        TurnLease $conversationLease,
        TurnLease $commerceLease,
        string $reason,
        string $internal
    ): SafeCommerceException {
        $observed = $this->evidence->captureOptional();
        $safe = $this->messages->uncertain();
        try {
            $this->scope->guarded($conversationLease, $commerceLease, function () use (
                $step,
                $attempt,
                $conversationLease,
                $commerceLease,
                $reason,
                $safe,
                $observed
            ): void {
                if (!$attempt->isTerminal()) {
                    $this->attempts->markFailed(
                        $attempt->id(),
                        CartStepAttemptStatus::UNCERTAIN,
                        $this->messages->reason($reason),
                        $safe
                    );
                }
                $this->steps->markFailure(
                    $step->id(),
                    $conversationLease->fence(),
                    $commerceLease->fence(),
                    CartStepStatus::UNCERTAIN,
                    $this->messages->reason($reason),
                    $safe,
                    $attempt->candidateEffect(),
                    $observed,
                    $attempt->markerDigest()
                );
            });
        } catch (Throwable $exception) {
            throw $this->messages->pending(
                'cart_step_uncertain_persistence_pending',
                $exception->getMessage()
            );
        }
        return new SafeCommerceException($this->messages->reason($reason), $safe, $internal, true);
    }

    public function result(OperationRecord $operation): ?ActionReceipt
    {
        if ($operation->status() === OperationStatus::VERIFIED) {
            if ($operation->receipt() === null) {
                throw $this->messages->pending('verified_receipt_missing', 'Verified operation receipt is missing.');
            }
            return $operation->receipt();
        }
        if ($operation->status() === OperationStatus::REJECTED) {
            throw new SafeCommerceException($operation->failureCode(), $operation->safeMessage());
        }
        if ($operation->status() === OperationStatus::UNCERTAIN) {
            throw new SafeCommerceException($operation->failureCode(), $operation->safeMessage(), '', true);
        }
        return null;
    }

    public function resultForContext(
        OperationRecord $operation,
        CommerceExecutionContext $context
    ): ?ActionReceipt {
        if (!$operation->isTerminal()) {
            return null;
        }
        try {
            $this->leases->assertCurrent($context->lease());
            $currentResourceHash = hash('sha256', $this->markers->commerceResource());
        } catch (Throwable $exception) {
            throw $this->messages->pending('cart_session_binding_check_pending', $exception->getMessage());
        }
        if (!hash_equals($operation->commerceResourceHash(), $currentResourceHash)) {
            throw new SafeCommerceException(
                'cart_session_binding_changed',
                $this->messages->uncertain(),
                'The terminal operation is bound to a different commerce resource.',
                true
            );
        }
        return $this->result($operation);
    }
}
