<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;
use Throwable;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartSnapshot;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttempt;
use YassinStore\AiAssistant\Domain\Commerce\CartStepStatus;
use YassinStore\AiAssistant\Domain\Commerce\OperationRecord;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\OperationPendingException;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Infrastructure\Concurrency\TurnLeaseManager;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepAttemptRepository;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepRepository;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

/** Executes and proves one durable cart primitive at a time. */
final class CartStepExecutionEngine
{
    /** @var CartStepRepository */ private $steps;
    /** @var CartStepAttemptRepository */ private $attempts;
    /** @var CartSnapshotFactory */ private $snapshots;
    /** @var CartCommandExecutor */ private $executor;
    /** @var CartStepVerifier */ private $verifier;
    /** @var WooSessionOperationMarker */ private $markers;
    /** @var WooSessionCartStore */ private $store;
    /** @var CartRecoveryCoordinator */ private $recovery;
    /** @var TurnLeaseManager */ private $leases;
    /** @var Logger */ private $logger;
    /** @var CartLeaseScope */ private $scope;
    /** @var CartStateEvidence */ private $evidence;
    /** @var CartOperationMessages */ private $messages;
    /** @var CartOperationTerminalizer */ private $terminalizer;
    /** @var CartWorkingStateRestorer */ private $workingStateRestorer;
    /** @var CartMutationCapabilityProof */ private $capability;

    public function __construct(
        CartStepRepository $steps,
        CartStepAttemptRepository $attempts,
        CartSnapshotFactory $snapshots,
        CartCommandExecutor $executor,
        CartStepVerifier $verifier,
        WooSessionOperationMarker $markers,
        WooSessionCartStore $store,
        CartRecoveryCoordinator $recovery,
        TurnLeaseManager $leases,
        Logger $logger,
        CartLeaseScope $scope,
        CartStateEvidence $evidence,
        CartOperationMessages $messages,
        CartOperationTerminalizer $terminalizer,
        CartWorkingStateRestorer $workingStateRestorer,
        CartMutationCapabilityProof $capability
    ) {
        $this->steps = $steps;
        $this->attempts = $attempts;
        $this->snapshots = $snapshots;
        $this->executor = $executor;
        $this->verifier = $verifier;
        $this->markers = $markers;
        $this->store = $store;
        $this->recovery = $recovery;
        $this->leases = $leases;
        $this->logger = $logger;
        $this->scope = $scope;
        $this->evidence = $evidence;
        $this->messages = $messages;
        $this->terminalizer = $terminalizer;
        $this->workingStateRestorer = $workingStateRestorer;
        $this->capability = $capability;
    }

    public function execute(
        OperationRecord $operation,
        CartOperationStep $step,
        WooSessionCartEnvelope $durablePre,
        TurnLease $conversationLease,
        TurnLease $commerceLease,
        ?TurnExecutionSupervisor $supervisor = null
    ): CartOperationStep {
        $step = $this->scope->guarded($conversationLease, $commerceLease, function () use (
            $step,
            $conversationLease,
            $commerceLease
        ): CartOperationStep {
            return $this->steps->markApplying(
                $step->id(),
                $conversationLease->fence(),
                $commerceLease->fence()
            );
        });
        $attempt = $this->scope->guarded($conversationLease, $commerceLease, function () use (
            $step,
            $conversationLease,
            $commerceLease
        ): CartStepAttempt {
            return $this->attempts->start(
                $step->id(),
                $conversationLease->fence(),
                $commerceLease->resourceHash(),
                $commerceLease->fence()
            );
        });

        try {
            $intent = $this->markers->intent($operation, $step, $attempt, $conversationLease, $commerceLease);
            $this->markers->write($intent);
            $attempt = $this->scope->guarded(
                $conversationLease,
                $commerceLease,
                function () use ($attempt, $intent): CartStepAttempt {
                    return $this->attempts->stageIntent($attempt->id(), $intent);
                }
            );

            $this->store->assertWorkingPreState($step->preState(), $durablePre, $intent);
            $this->leases->assertCurrent($conversationLease);
            $this->leases->assertCurrent($commerceLease);

            $draft = $this->executor->execute($step->primitive(), $step->preState());
            $post = $this->snapshots->capture();
            try {
                $effect = $this->verifier->seal($step->primitive(), $step->preState(), $post, $draft);
            } catch (Throwable $exception) {
                $this->logger->error('cart_step_verification_failed', array(
                    'operation' => $operation->publicId(),
                    'step' => $step->publicId(),
                    'primitive_type' => $step->primitive()->type(),
                    'semantic_type' => $step->primitive()->semanticType(),
                    'phase' => $step->primitive()->phase(),
                    'reason' => $exception->getMessage(),
                    'pre_revision' => $step->preState()->revision(),
                    'post_revision' => $post->revision(),
                ));
                throw $exception;
            }

            $working = $this->store->stageCurrentWorkingCart();
            if (!hash_equals($working->authorityRevision(), $post->revision())) {
                throw new RuntimeException('Staged Woo session authority differs from the verified step post-state.');
            }
            $sealed = $this->markers->seal($intent, $effect, $post, $working->payloadHash());
            $this->markers->write($sealed);
            $attempt = $this->scope->guarded(
                $conversationLease,
                $commerceLease,
                function () use ($attempt, $sealed, $effect, $post): CartStepAttempt {
                    return $this->attempts->seal($attempt->id(), $sealed, $effect, $post);
                }
            );

            try {
                $this->store->persistAndReadBack(
                    $step->preState(),
                    $post,
                    $effect,
                    $durablePre,
                    $sealed,
                    $conversationLease,
                    $commerceLease,
                    $supervisor
                );
            } catch (Throwable $persistenceFailure) {
                $reconciled = $this->recovery->reconcile(
                    $operation,
                    $step,
                    $conversationLease,
                    $commerceLease
                );
                if ($reconciled !== null) {
                    return $reconciled;
                }
                throw $this->messages->pending(
                    'cart_step_retry_required',
                    $persistenceFailure->getMessage()
                );
            }

            try {
                $verified = $this->scope->guarded(
                    $conversationLease,
                    $commerceLease,
                    function () use (
                        $step,
                        $attempt,
                        $sealed,
                        $effect,
                        $post,
                        $conversationLease,
                        $commerceLease
                    ): CartOperationStep {
                        $this->attempts->markSessionPersisted($attempt->id());
                        $stored = $this->steps->markVerified(
                            $step->id(),
                            $conversationLease->fence(),
                            $commerceLease->fence(),
                            $effect,
                            $post,
                            $sealed->digest()
                        );
                        $this->attempts->markVerified($attempt->id());
                        return $stored;
                    }
                );
            } catch (Throwable $exception) {
                throw $this->messages->pending('cart_step_journal_finalize_pending', $exception->getMessage());
            }

            try {
                $this->store->clearMarker(
                    $post,
                    $sealed,
                    $conversationLease,
                    $commerceLease,
                    $supervisor
                );
            } catch (Throwable $exception) {
                $this->logger->error('cart_marker_cleanup_pending', array(
                    'operation' => $operation->publicId(),
                    'step' => $step->publicId(),
                    'message' => $exception->getMessage(),
                ));
                throw $this->messages->pending('cart_marker_cleanup_pending', $exception->getMessage());
            }
            return $verified;
        } catch (OperationPendingException $exception) {
            // A pending journal/storage transition may still have left a
            // request-local post-state. If durable authority is exactly the
            // pre-state, restore it before the REST layer can project a cart.
            $this->workingStateRestorer->restore($step, $durablePre);
            throw $exception;
        } catch (SafeCommerceException $exception) {
            if (
                $this->workingStateRestorer->restore($step, $durablePre)
                && $this->evidence->stepStillAtPre($step)
            ) {
                $this->terminalizer->rejectStep(
                    $step,
                    $attempt,
                    $conversationLease,
                    $commerceLease,
                    $exception
                );
                throw $exception;
            }
            throw $this->terminalizer->uncertainStep(
                $step,
                $attempt,
                $conversationLease,
                $commerceLease,
                $exception->reasonCode(),
                $exception->getMessage()
            );
        } catch (Throwable $exception) {
            if (
                $this->workingStateRestorer->restore($step, $durablePre)
                && $this->evidence->stepStillAtPre($step)
            ) {
                $safe = new SafeCommerceException(
                    'cart_step_failed',
                    ('لم يتم تغيير السلة.'),
                    $exception->getMessage()
                );
                $this->terminalizer->rejectStep(
                    $step,
                    $attempt,
                    $conversationLease,
                    $commerceLease,
                    $safe
                );
                throw $safe;
            }
            throw $this->terminalizer->uncertainStep(
                $step,
                $attempt,
                $conversationLease,
                $commerceLease,
                'cart_step_interrupted',
                $exception->getMessage()
            );
        }
    }

    /** @param array<int,CartOperationStep> $verified */
    public function assertExpectedState(
        CartSnapshot $expected,
        array $verified,
        TurnLease $conversationLease,
        TurnLease $commerceLease,
        bool $allowVerifiedMarker = true
    ): WooSessionCartEnvelope {
        $live = $this->snapshots->captureCurrent();
        $durable = $this->store->readDurable();
        if (
            !$this->evidence->sameComplete($expected, $live)
            || !hash_equals($durable->authorityRevision(), $expected->revision())
        ) {
            throw new SafeCommerceException(
                $verified !== array() ? 'cart_operation_state_diverged' : 'cart_changed_before_execution',
                $verified !== array() ? $this->messages->uncertain() : ('تغيرت السلة قبل بدء التنفيذ.'),
                'Expected live/durable cart state no longer matches.',
                $verified !== array()
            );
        }
        if ($durable->marker() !== null) {
            $last = $verified !== array() ? $verified[count($verified) - 1] : null;
            try {
                $marker = $this->markers->parseAndVerify($durable->marker());
                if (
                    !$last instanceof CartOperationStep
                    || $last->status() !== CartStepStatus::VERIFIED
                    || !hash_equals($marker->stepId(), $last->publicId())
                    || !hash_equals($marker->digest(), $last->markerDigest())
                    || !hash_equals($marker->postRevision(), $expected->revision())
                ) {
                    throw new RuntimeException('Surviving marker is not the previous verified step proof.');
                }
                if (!$allowVerifiedMarker) {
                    throw $this->messages->pending(
                        'cart_marker_cleanup_pending',
                        'The final verified cart marker is still durable.'
                    );
                }
            } catch (OperationPendingException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new SafeCommerceException(
                    'cart_marker_blocks_next_step',
                    $this->messages->uncertain(),
                    $exception->getMessage(),
                    true
                );
            }
        }
        $this->leases->assertCurrent($conversationLease);
        $this->leases->assertCurrent($commerceLease);
        return $durable;
    }

    public function beginControlledSession(bool $pendingOnFailure): void
    {
        try {
            // This is the final non-mutating gate before any core hook is
            // removed and before a request-local cart effect can begin.
            $this->capability->assertSupported();
            $this->store->beginAuthoritativeMutation();
        } catch (Throwable $exception) {
            if ($pendingOnFailure) {
                throw $this->messages->pending('cart_session_persistence_uncontrolled', $exception->getMessage());
            }
            throw new SafeCommerceException(
                'cart_session_persistence_uncontrolled',
                ('تعذر بدء تغيير السلة ضمن حدود الحفظ الآمنة.'),
                $exception->getMessage()
            );
        }
    }
}
