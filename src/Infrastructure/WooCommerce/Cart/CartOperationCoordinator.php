<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;
use Throwable;
use YassinStore\AiAssistant\Application\Commerce\CommerceExecutionContext;
use YassinStore\AiAssistant\Application\Port\CartMutationPort;
use YassinStore\AiAssistant\Application\Execution\ExecutionBoundary;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;
use YassinStore\AiAssistant\Domain\Commerce\ActionReceipt;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartStepStatus;
use YassinStore\AiAssistant\Domain\Commerce\OperationRecord;
use YassinStore\AiAssistant\Domain\Commerce\OperationStatus;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\LeaseLostException;
use YassinStore\AiAssistant\Domain\Exception\ExecutionBudgetException;
use YassinStore\AiAssistant\Domain\Exception\OperationPendingException;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepRepository;
use YassinStore\AiAssistant\Infrastructure\Database\OperationRepository;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Support\Json;

/** Sole durable cart mutation and recovery coordinator. */
final class CartOperationCoordinator implements CartMutationPort
{
    /** @var OperationRepository */ private $operations;
    /** @var CartStepRepository */ private $steps;
    /** @var CartSnapshotFactory */ private $snapshots;
    /** @var CartOperationPlanningGuard */ private $planning;
    /** @var CartDeltaVerifier */ private $planVerifier;
    /** @var WooSessionCartStore */ private $store;
    /** @var CartRecoveryCoordinator */ private $recovery;
    /** @var ReceiptPresenter */ private $receipts;
    /** @var Logger */ private $logger;
    /** @var CartLeaseScope */ private $scope;
    /** @var CartOperationMessages */ private $messages;
    /** @var CartSemanticEffectBuilder */ private $effects;
    /** @var CartOperationTerminalizer */ private $terminalizer;
    /** @var CartStepExecutionEngine */ private $stepEngine;
    /** @var CartMutationCapabilityQuarantine */ private $quarantine;
    /** @var CartMutationCapabilityProof */ private $capability;

    public function __construct(
        OperationRepository $operations,
        CartStepRepository $steps,
        CartSnapshotFactory $snapshots,
        CartOperationPlanningGuard $planning,
        CartDeltaVerifier $planVerifier,
        WooSessionCartStore $store,
        CartRecoveryCoordinator $recovery,
        ReceiptPresenter $receipts,
        Logger $logger,
        CartLeaseScope $scope,
        CartOperationMessages $messages,
        CartSemanticEffectBuilder $effects,
        CartOperationTerminalizer $terminalizer,
        CartStepExecutionEngine $stepEngine,
        CartMutationCapabilityQuarantine $quarantine,
        CartMutationCapabilityProof $capability
    ) {
        $this->operations = $operations;
        $this->steps = $steps;
        $this->snapshots = $snapshots;
        $this->planning = $planning;
        $this->planVerifier = $planVerifier;
        $this->store = $store;
        $this->recovery = $recovery;
        $this->receipts = $receipts;
        $this->logger = $logger;
        $this->scope = $scope;
        $this->messages = $messages;
        $this->effects = $effects;
        $this->terminalizer = $terminalizer;
        $this->stepEngine = $stepEngine;
        $this->quarantine = $quarantine;
        $this->capability = $capability;
    }

    public function execute(CartPlan $plan, CommerceExecutionContext $context): ActionReceipt
    {
        $supervisor = $context->supervisor();
        if ($supervisor !== null) {
            $supervisor->before(ExecutionBoundary::CART_OPERATION);
        }
        try {
            if ($this->operations->findByTurn($context->conversationId(), $context->turnId()) !== null) {
                throw new RuntimeException(
                    'An existing cart operation must be reconciled before agent execution.'
                );
            }
            $this->beginProtectedMutation(null);
            return $this->scope->withCommerceLease($context, function (TurnLease $commerceLease) use (
                $plan,
                $context,
                $supervisor
            ): ActionReceipt {
                $this->stepEngine->beginControlledSession(false);
                $pre = $this->snapshots->capture();
                $durable = $this->store->readDurable();
                if (!hash_equals($durable->authorityRevision(), $pre->revision()) || $durable->marker() !== null) {
                    throw new SafeCommerceException(
                        'cart_session_not_canonical',
                        $this->messages->uncertain(),
                        'Live and durable Woo cart state differ before operation preparation.',
                        true
                    );
                }
                $this->store->assertWorkingPreState($pre, $durable);
                if (!$plan->authorizesPreState($pre)) {
                    throw new SafeCommerceException(
                        'cart_changed_since_view',
                        ('تغيرت السلة منذ عرضها. اعرض السلة مجدداً قبل إفراغها.')
                    );
                }
                if (!$pre->restorable() && $this->planVerifier->wouldChange($plan, $pre)) {
                    // WooCommerce hooks may rewrite any line while totals and
                    // sessions are updated. Exact rollback and delta proof therefore
                    // require every pre-state line to be reconstructible.
                    throw new SafeCommerceException(
                        'cart_not_safely_recoverable',
                        ('تحتوي السلة على بيانات مخصصة لا يمكن التحقق منها واستعادتها بالكامل بأمان.')
                    );
                }

                $operationKey = hash('sha256', Json::canonical(array(
                'conversation' => $context->conversationPublicId(),
                'turn' => $context->turnId(),
                'plan' => $plan->canonical(),
                'pre_revision' => $pre->revision(),
                'pre_restoration_revision' => $pre->restorationRevision(),
                'commerce_resource_hash' => $commerceLease->resourceHash(),
                )));
                $operation = $this->scope->guarded(
                    $context->lease(),
                    $commerceLease,
                    function () use ($context, $operationKey, $plan, $pre, $commerceLease): OperationRecord {
                        return $this->operations->prepare(
                            $context->conversationId(),
                            $context->turnId(),
                            $operationKey,
                            $context->lease()->fence(),
                            $commerceLease->resourceHash(),
                            $commerceLease->fence(),
                            $plan,
                            $pre
                        );
                    }
                );
                return $this->runExisting($operation, $context, $commerceLease, $supervisor);
            });
        } finally {
            if ($supervisor !== null) {
                // The CART_STEP and WOO_SESSION_SAVE boundaries seal renewal
                // at the first point where cart state may have changed. A
                // preflight or already-terminal operation is side-effect free.
                $supervisor->after(ExecutionBoundary::CART_OPERATION);
            }
        }
    }

    public function recoverForTurn(CommerceExecutionContext $context): ?ActionReceipt
    {
        $operation = $this->operations->findByTurn($context->conversationId(), $context->turnId());
        if ($operation === null) {
            return null;
        }
        $supervisor = $context->supervisor();
        if ($supervisor !== null) {
            $supervisor->before(ExecutionBoundary::RECONCILIATION);
        }
        try {
            $terminal = $this->terminalizer->resultForContext($operation, $context);
            if ($terminal !== null) {
                return $terminal;
            }
            return $this->scope->withCommerceLease($context, function (TurnLease $commerceLease) use (
                $operation,
                $context,
                $supervisor
            ): ActionReceipt {
                $this->quarantine->enforce($operation, $context, $commerceLease);
                $this->beginProtectedMutation($operation);
                $this->stepEngine->beginControlledSession(true);
                return $this->runExisting($operation, $context, $commerceLease, $supervisor);
            });
        } finally {
            if ($supervisor !== null) {
                $supervisor->after(ExecutionBoundary::RECONCILIATION, true);
            }
        }
    }

    private function runExisting(
        OperationRecord $operation,
        CommerceExecutionContext $context,
        TurnLease $commerceLease,
        ?TurnExecutionSupervisor $supervisor = null
    ): ActionReceipt {
        if (!hash_equals($operation->commerceResourceHash(), $commerceLease->resourceHash())) {
            throw new SafeCommerceException(
                'cart_session_binding_changed',
                $this->messages->uncertain(),
                'The operation is bound to a different commerce resource.',
                true
            );
        }
        $terminal = $this->terminalizer->result($operation);
        if ($terminal !== null) {
            return $terminal;
        }

        $conversationLease = $context->lease();
        $operation = $this->scope->guarded($conversationLease, $commerceLease, function () use (
            $operation,
            $conversationLease,
            $commerceLease
        ): OperationRecord {
            return $this->operations->adopt(
                $operation,
                $conversationLease->fence(),
                $commerceLease->resourceHash(),
                $commerceLease->fence()
            );
        });
        $terminal = $this->terminalizer->result($operation);
        if ($terminal !== null) {
            return $terminal;
        }

        $wasPrepared = $operation->status() === OperationStatus::PREPARED;
        $planning = $this->planning->resolve($operation, $conversationLease, $commerceLease);
        $primitives = $planning['primitives'];
        $storedSteps = $planning['steps'];
        if ($wasPrepared) {
            $operation = $this->scope->guarded($conversationLease, $commerceLease, function () use (
                $operation,
                $conversationLease,
                $commerceLease
            ): OperationRecord {
                return $this->operations->markExecuting(
                    $operation->id(),
                    $conversationLease->fence(),
                    $commerceLease->fence()
                );
            });
        }
        if ($operation->status() !== OperationStatus::EXECUTING) {
            throw $this->messages->pending('cart_operation_state_invalid', 'The cart operation has an unsupported state.');
        }

        if (count($storedSteps) > count($primitives)) {
            return $this->terminalizer->uncertain(
                $operation,
                $conversationLease,
                $commerceLease,
                'cart_step_count_invalid',
                'Durable steps exceed the deterministic plan.'
            );
        }

        $verified = array();
        $expected = $operation->preState();
        try {
            foreach ($primitives as $stepIndex => $primitive) {
                $step = $storedSteps[$stepIndex] ?? null;
                if ($step === null) {
                    $this->stepEngine->assertExpectedState($expected, $verified, $conversationLease, $commerceLease);
                    $step = $this->scope->guarded($conversationLease, $commerceLease, function () use (
                        $operation,
                        $stepIndex,
                        $primitive,
                        $expected,
                        $conversationLease,
                        $commerceLease
                    ): CartOperationStep {
                        return $this->steps->prepare(
                            $operation->id(),
                            $stepIndex,
                            $primitive,
                            $expected,
                            $conversationLease->fence(),
                            $commerceLease->resourceHash(),
                            $commerceLease->fence()
                        );
                    });
                } else {
                    // Re-running prepare performs strict immutable-intent checks.
                    $step = $this->scope->guarded($conversationLease, $commerceLease, function () use (
                        $operation,
                        $stepIndex,
                        $primitive,
                        $expected,
                        $step,
                        $conversationLease,
                        $commerceLease
                    ): CartOperationStep {
                        $canonical = $this->steps->prepare(
                            $operation->id(),
                            $stepIndex,
                            $primitive,
                            $expected,
                            $step->conversationFence(),
                            $step->commerceResourceHash(),
                            $step->commerceFence()
                        );
                        return $this->steps->adopt(
                            $canonical,
                            $conversationLease->fence(),
                            $commerceLease->resourceHash(),
                            $commerceLease->fence()
                        );
                    });
                }

                $recovered = $this->recovery->reconcile(
                    $operation,
                    $step,
                    $conversationLease,
                    $commerceLease
                );
                if ($recovered === null) {
                    $durablePre = $this->stepEngine->assertExpectedState(
                        $expected,
                        $verified,
                        $conversationLease,
                        $commerceLease
                    );
                    if ($supervisor !== null) {
                        $supervisor->before(ExecutionBoundary::CART_STEP);
                    }
                    try {
                        $recovered = $this->stepEngine->execute(
                            $operation,
                            $step,
                            $durablePre,
                            $conversationLease,
                            $commerceLease,
                            $supervisor
                        );
                    } finally {
                        if ($supervisor !== null) {
                            $supervisor->after(ExecutionBoundary::CART_STEP, true);
                        }
                    }
                }
                if ($recovered->status() !== CartStepStatus::VERIFIED || $recovered->postState() === null) {
                    throw new RuntimeException('A cart step returned without exact verified post-state.');
                }
                $verified[] = $recovered;
                $expected = $recovered->postState();
            }

            $finalDurable = $this->stepEngine->assertExpectedState(
                $expected,
                $verified,
                $conversationLease,
                $commerceLease,
                false
            );
            $applied = $this->effects->build($operation->plan(), $operation->preState(), $verified);
            $verification = $this->planVerifier->verify(
                $operation->plan(),
                $operation->preState(),
                $expected,
                $applied
            );
            if (!$verification->isVerified()) {
                $this->logger->error('cart_semantic_verification_failed', array(
                    'operation' => $operation->publicId(),
                    'reason' => $verification->reason(),
                    'pre_revision' => $operation->preState()->revision(),
                    'post_revision' => $expected->revision(),
                    'command_count' => count($operation->plan()->commands()),
                    'verified_step_count' => count($verified),
                ));
                return $this->terminalizer->uncertain(
                    $operation,
                    $conversationLease,
                    $commerceLease,
                    'cart_semantic_delta_invalid',
                    $verification->reason()
                );
            }
            $receipt = $this->receipts->create(
                $operation->plan(),
                $operation->preState(),
                $expected,
                $verification->changed()
            );
            try {
                $receipt = $this->scope->guarded($conversationLease, $commerceLease, function () use (
                    $operation,
                    $conversationLease,
                    $commerceLease,
                    $applied,
                    $expected,
                    $receipt,
                    $finalDurable
                ): ActionReceipt {
                    $this->store->assertDurableFinalStateForUpdate($expected, $finalDurable);
                    $this->operations->recordApplied(
                        $operation->id(),
                        $conversationLease->fence(),
                        $commerceLease->fence(),
                        $applied
                    );
                    $this->operations->markVerified(
                        $operation->id(),
                        $conversationLease->fence(),
                        $commerceLease->fence(),
                        $expected,
                        $receipt
                    );
                    return $receipt;
                });
            } catch (PersistentCartMismatchException $exception) {
                // A stable dual-store contradiction cannot be repaired by retry.
                return $this->terminalizer->uncertain(
                    $operation,
                    $conversationLease,
                    $commerceLease,
                    'persistent_cart_final_mismatch',
                    $exception->getMessage()
                );
            } catch (Throwable $exception) {
                // Verified primitives make aggregate journal loss retryable.
                throw $this->messages->pending('cart_operation_finalize_pending', $exception->getMessage());
            }
            try {
                $this->store->publishVerifiedCookies();
            } catch (Throwable $exception) {
                // Cookies are a derived hint and later requests recompute them.
                $this->logger->error('cart_cookie_publish_failed', array(
                    'operation' => $operation->publicId(),
                    'message' => $exception->getMessage(),
                ));
            }
            return $receipt;
        } catch (ExecutionBudgetException $exception) {
            throw $exception;
        } catch (OperationPendingException $exception) {
            throw $exception;
        } catch (LeaseLostException $exception) {
            throw $this->messages->pending('lease_lost_during_cart_operation', $exception->getMessage());
        } catch (SafeCommerceException $exception) {
            return $this->terminalizer->failure(
                $operation,
                $conversationLease,
                $commerceLease,
                $verified,
                $exception
            );
        } catch (Throwable $exception) {
            if ($verified !== array()) {
                // A verified primitive makes aggregate finalization retryable.
                throw $this->messages->pending('cart_operation_finalize_pending', $exception->getMessage());
            }
            $failure = new SafeCommerceException(
                'cart_operation_failed',
                ('لم يتم تنفيذ طلب السلة بأمان.'),
                $exception->getMessage(),
                false
            );
            return $this->terminalizer->failure(
                $operation,
                $conversationLease,
                $commerceLease,
                $verified,
                $failure
            );
        }
    }

    private function beginProtectedMutation(?OperationRecord $existing): void
    {
        try {
            $this->capability->beginProtectedMutation();
        } catch (Throwable $exception) {
            if ($existing !== null) {
                throw $this->messages->pending('cart_mutation_fence_pending', $exception->getMessage());
            }
            throw new SafeCommerceException(
                'cart_busy',
                ('السلة مشغولة بطلب آخر حالياً. أعد المحاولة بعد لحظات.'),
                $exception->getMessage(),
                false
            );
        }
    }
}
