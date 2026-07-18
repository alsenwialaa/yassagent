<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;
use Throwable;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartSessionMarker;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttempt;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttemptStatus;
use YassinStore\AiAssistant\Domain\Commerce\CartStepStatus;
use YassinStore\AiAssistant\Domain\Commerce\OperationRecord;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Domain\Exception\OperationPendingException;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepAttemptRepository;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepRepository;
use YassinStore\AiAssistant\Support\Json;

/** Reconciles one nonterminal step from append-only attempt and session-marker evidence. */
final class CartRecoveryCoordinator
{
    /** @var CartStepRepository */ private $steps;
    /** @var CartStepAttemptRepository */ private $attempts;
    /** @var WooSessionCartStore */ private $store;
    /** @var WooSessionOperationMarker */ private $markers;
    /** @var CartStepVerifier */ private $verifier;
    /** @var CartLeaseScope */ private $scope;
    /** @var CartStateEvidence */ private $evidence;
    /** @var CartOperationMessages */ private $messages;

    public function __construct(
        CartStepRepository $steps,
        CartStepAttemptRepository $attempts,
        WooSessionCartStore $store,
        WooSessionOperationMarker $markers,
        CartStepVerifier $verifier,
        CartLeaseScope $scope,
        CartStateEvidence $evidence,
        CartOperationMessages $messages
    ) {
        $this->steps = $steps;
        $this->attempts = $attempts;
        $this->store = $store;
        $this->markers = $markers;
        $this->verifier = $verifier;
        $this->scope = $scope;
        $this->evidence = $evidence;
        $this->messages = $messages;
    }

    /** Returns null when the logical step is safe to execute in a new attempt. */
    public function reconcile(
        OperationRecord $operation,
        CartOperationStep $step,
        TurnLease $conversationLease,
        TurnLease $commerceLease
    ): ?CartOperationStep {
        if ($step->status() === CartStepStatus::VERIFIED) {
            return $this->finishVerifiedMarkerCleanup(
                $operation,
                $step,
                $conversationLease,
                $commerceLease
            );
        }
        if ($step->status() === CartStepStatus::UNCERTAIN) {
            throw new SafeCommerceException($step->failureCode(), $step->safeMessage(), '', true);
        }
        if ($step->status() === CartStepStatus::REJECTED) {
            throw new SafeCommerceException($step->failureCode(), $step->safeMessage());
        }

        $attempt = $this->attempts->latestForStep($step->id());
        if ($attempt === null || $attempt->status() === CartStepAttemptStatus::ABANDONED) {
            return null;
        }
        if ($attempt->status() === CartStepAttemptStatus::UNCERTAIN) {
            throw new SafeCommerceException($attempt->failureCode(), $attempt->safeMessage(), '', true);
        }

        try {
            $durable = $this->store->readDurable();
        } catch (Throwable $exception) {
            throw new OperationPendingException(
                'cart_recovery_storage_pending',
                ('يجري التحقق من حالة السلة المحفوظة. أعد إرسال الطلب نفسه.'),
                $exception->getMessage()
            );
        }
        $durableMarker = null;
        if ($durable->marker() !== null) {
            try {
                $durableMarker = $this->markers->parseAndVerify($durable->marker());
            } catch (Throwable $exception) {
                return $this->failUncertain(
                    $step,
                    $attempt,
                    $conversationLease,
                    $commerceLease,
                    'cart_marker_invalid',
                    $exception->getMessage()
                );
            }
        }

        if (
            in_array($attempt->status(), array(
            CartStepAttemptStatus::STARTED,
            CartStepAttemptStatus::INTENT_STAGED,
            ), true)
        ) {
            if (hash_equals($durable->authorityRevision(), $step->preState()->revision())) {
                $abandonedWithMarkerClear = false;
                try {
                    $live = $this->evidence->capture();
                } catch (Throwable $exception) {
                    throw new OperationPendingException(
                        'cart_intent_recovery_pending',
                        ('يجري التحقق من محاولة السلة السابقة. أعد إرسال الطلب نفسه.'),
                        $exception->getMessage()
                    );
                }
                if (!$this->evidence->sameComplete($live, $step->preState())) {
                    throw new OperationPendingException(
                        'cart_intent_recovery_pending',
                        ('يجري التحقق من محاولة السلة السابقة. أعد إرسال الطلب نفسه.'),
                        'Live cart state is not the exact durable intent pre-state.'
                    );
                }
                if ($durableMarker !== null) {
                    try {
                        $this->markers->assertMatches($durableMarker, $operation, $step, $attempt);
                        if ($durableMarker->phase() !== CartSessionMarker::INTENT) {
                            throw new RuntimeException('An unsealed attempt has a sealed durable marker.');
                        }
                        try {
                            $this->store->clearMarker(
                                $step->preState(),
                                $durableMarker,
                                $conversationLease,
                                $commerceLease,
                                null,
                                function () use ($attempt): void {
                                    $this->attempts->markFailed(
                                        $attempt->id(),
                                        CartStepAttemptStatus::ABANDONED,
                                        'attempt_not_applied',
                                        'The attempt ended before a durable cart effect.'
                                    );
                                }
                            );
                            $abandonedWithMarkerClear = true;
                        } catch (Throwable $exception) {
                            throw new OperationPendingException(
                                'cart_marker_cleanup_pending',
                                ('يجري إكمال التحقق من السلة. أعد إرسال الطلب نفسه.'),
                                $exception->getMessage()
                            );
                        }
                    } catch (OperationPendingException $exception) {
                        throw $exception;
                    } catch (Throwable $exception) {
                        return $this->failUncertain(
                            $step,
                            $attempt,
                            $conversationLease,
                            $commerceLease,
                            'cart_marker_mismatch',
                            $exception->getMessage()
                        );
                    }
                }
                if (!$abandonedWithMarkerClear) {
                    try {
                        $this->scope->guarded($conversationLease, $commerceLease, function () use ($attempt): void {
                            $this->attempts->markFailed(
                                $attempt->id(),
                                CartStepAttemptStatus::ABANDONED,
                                'attempt_not_applied',
                                'The attempt ended before a durable cart effect.'
                            );
                        });
                    } catch (Throwable $exception) {
                        throw new OperationPendingException(
                            'cart_attempt_abandon_pending',
                            ('يجري إكمال سجل محاولة السلة السابقة. أعد إرسال الطلب نفسه.'),
                            $exception->getMessage()
                        );
                    }
                }
                return null;
            }
            return $this->failKnownOrUncertain(
                $operation,
                $step,
                $attempt,
                $durableMarker,
                $conversationLease,
                $commerceLease,
                'cart_step_interrupted_before_seal'
            );
        }

        $candidateEffect = $attempt->candidateEffect();
        $candidatePost = $attempt->candidatePostState();
        $attemptMarkerRow = $attempt->marker();
        if ($candidateEffect === null || $candidatePost === null || $attemptMarkerRow === null) {
            return $this->failUncertain(
                $step,
                $attempt,
                $conversationLease,
                $commerceLease,
                'cart_attempt_evidence_missing',
                'A sealed attempt lacks candidate evidence.'
            );
        }
        try {
            $attemptMarker = $this->markers->parseAndVerify($attemptMarkerRow);
            $this->markers->assertMatches($attemptMarker, $operation, $step, $attempt);
            $this->verifier->assertSealed($step->primitive(), $step->preState(), $candidatePost, $candidateEffect);
            if (
                !hash_equals(
                    $attemptMarker->effectHash(),
                    hash('sha256', Json::canonical($candidateEffect))
                )
            ) {
                throw new RuntimeException('Sealed marker does not authenticate its candidate effect.');
            }
        } catch (Throwable $exception) {
            return $this->failUncertain(
                $step,
                $attempt,
                $conversationLease,
                $commerceLease,
                'cart_attempt_evidence_invalid',
                $exception->getMessage()
            );
        }

        if (
            hash_equals($durable->authorityRevision(), $candidatePost->revision())
            && hash_equals($durable->payloadHash(), $attemptMarker->cartPayloadHash())
            && $durableMarker !== null
        ) {
            try {
                $this->markers->assertMatches($durableMarker, $operation, $step, $attempt);
                if (!hash_equals(Json::canonical($durableMarker->toArray()), Json::canonical($attemptMarker->toArray()))) {
                    throw new RuntimeException('Durable marker differs from the sealed attempt marker.');
                }
            } catch (Throwable $exception) {
                return $this->failUncertain(
                    $step,
                    $attempt,
                    $conversationLease,
                    $commerceLease,
                    'cart_marker_mismatch',
                    $exception->getMessage()
                );
            }
            try {
                $verified = $this->scope->guarded($conversationLease, $commerceLease, function () use (
                    $step,
                    $attempt,
                    $candidateEffect,
                    $candidatePost,
                    $attemptMarker,
                    $conversationLease,
                    $commerceLease
                ): CartOperationStep {
                    if ($attempt->status() === CartStepAttemptStatus::SEALED) {
                        $this->attempts->markSessionPersisted($attempt->id());
                    }
                    $verified = $this->steps->markVerified(
                        $step->id(),
                        $conversationLease->fence(),
                        $commerceLease->fence(),
                        $candidateEffect,
                        $candidatePost,
                        $attemptMarker->digest()
                    );
                    $this->attempts->markVerified($attempt->id());
                    return $verified;
                });
            } catch (Throwable $exception) {
                throw new OperationPendingException(
                    'cart_step_journal_finalize_pending',
                    ('تم حفظ تغيير السلة، ويجري إكمال سجل التحقق. أعد إرسال الطلب نفسه.'),
                    $exception->getMessage()
                );
            }
            return $this->finishVerifiedMarkerCleanup(
                $operation,
                $verified,
                $conversationLease,
                $commerceLease
            );
        }

        if (hash_equals($durable->authorityRevision(), $step->preState()->revision()) && $durableMarker === null) {
            try {
                $this->scope->guarded($conversationLease, $commerceLease, function () use ($attempt): void {
                    $this->attempts->markFailed(
                        $attempt->id(),
                        CartStepAttemptStatus::ABANDONED,
                        'sealed_attempt_not_persisted',
                        'The sealed attempt did not reach durable Woo session storage.'
                    );
                });
            } catch (Throwable $exception) {
                throw new OperationPendingException(
                    'cart_attempt_abandon_pending',
                    ('يجري إكمال سجل محاولة السلة السابقة. أعد إرسال الطلب نفسه.'),
                    $exception->getMessage()
                );
            }
            return null;
        }

        return $this->failKnownOrUncertain(
            $operation,
            $step,
            $attempt,
            $durableMarker,
            $conversationLease,
            $commerceLease,
            'cart_step_durable_state_diverged'
        );
    }

    private function finishVerifiedMarkerCleanup(
        OperationRecord $operation,
        CartOperationStep $step,
        TurnLease $conversationLease,
        TurnLease $commerceLease
    ): CartOperationStep {
        $post = $step->postState();
        if ($post === null || $step->markerDigest() === '') {
            throw new SafeCommerceException(
                'verified_step_evidence_missing',
                $this->messages->uncertain(),
                'A verified cart step lacks post-state or marker evidence.',
                true
            );
        }
        $durable = $this->store->readDurable();
        if ($durable->marker() === null) {
            return $step;
        }

        $attempt = $this->attempts->latestForStep($step->id());
        try {
            $marker = $this->markers->parseAndVerify($durable->marker());
            if (!hash_equals($marker->operationId(), $operation->publicId())) {
                throw new RuntimeException('Durable marker belongs to another operation.');
            }
            if (!hash_equals($marker->stepId(), $step->publicId())) {
                if ($marker->stepIndex() > $step->stepIndex()) {
                    // The loop replays verified steps in order. A later
                    // marker is validated and cleared when that step is reached.
                    return $step;
                }
                throw new RuntimeException('Durable marker points behind the verified step.');
            }
            if (
                $attempt === null
                || $attempt->status() !== CartStepAttemptStatus::VERIFIED
                || $attempt->marker() === null
            ) {
                throw new RuntimeException('Verified marker attempt evidence is missing.');
            }
            $attemptMarker = $this->markers->parseAndVerify($attempt->marker());
            $this->markers->assertMatches($marker, $operation, $step, $attempt);
            if (
                $marker->phase() !== CartSessionMarker::SEALED
                || !hash_equals($marker->digest(), $step->markerDigest())
                || !hash_equals(Json::canonical($marker->toArray()), Json::canonical($attemptMarker->toArray()))
                || !hash_equals($marker->postRevision(), $post->revision())
                || !hash_equals($marker->postRestorationRevision(), $post->restorationRevision())
                || !hash_equals($marker->cartPayloadHash(), $durable->payloadHash())
            ) {
                throw new RuntimeException('Verified durable marker evidence is contradictory.');
            }
            if (!hash_equals($durable->authorityRevision(), $post->revision())) {
                throw new RuntimeException('Durable cart state differs from the marked verified step.');
            }
        } catch (Throwable $exception) {
            throw new SafeCommerceException(
                'verified_cart_marker_invalid',
                $this->messages->uncertain(),
                $exception->getMessage(),
                true
            );
        }

        try {
            $this->store->clearMarker(
                $post,
                $marker,
                $conversationLease,
                $commerceLease
            );
        } catch (Throwable $exception) {
            throw new OperationPendingException(
                'cart_marker_cleanup_pending',
                ('تم حفظ تغيير السلة، ويجري إكمال التحقق النهائي. أعد إرسال الطلب نفسه.'),
                $exception->getMessage()
            );
        }
        return $step;
    }

    private function failKnownOrUncertain(
        OperationRecord $operation,
        CartOperationStep $step,
        CartStepAttempt $attempt,
        ?CartSessionMarker $durableMarker,
        TurnLease $conversationLease,
        TurnLease $commerceLease,
        string $reason
    ): ?CartOperationStep {
        if ($durableMarker !== null) {
            try {
                $this->markers->assertMatches($durableMarker, $operation, $step, $attempt);
                if ($durableMarker->phase() !== CartSessionMarker::SEALED) {
                    throw new RuntimeException('An intent marker has no post-state authority.');
                }
            } catch (Throwable $ignored) {
                // Mismatched marker is not attributable evidence.
            }
        }
        return $this->failUncertain(
            $step,
            $attempt,
            $conversationLease,
            $commerceLease,
            $reason,
            'The durable cart changed without exact matching step proof.'
        );
    }

    private function failUncertain(
        CartOperationStep $step,
        CartStepAttempt $attempt,
        TurnLease $conversationLease,
        TurnLease $commerceLease,
        string $reason,
        string $internal
    ): ?CartOperationStep {
        $safe = $this->messages->uncertain();
        $observed = $this->evidence->captureOptional();
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
                $this->attempts->markFailed($attempt->id(), CartStepAttemptStatus::UNCERTAIN, $reason, $safe);
            }
            $this->steps->markFailure(
                $step->id(),
                $conversationLease->fence(),
                $commerceLease->fence(),
                CartStepStatus::UNCERTAIN,
                $reason,
                $safe,
                $attempt->candidateEffect(),
                $observed,
                $attempt->markerDigest()
            );
        });
        throw new SafeCommerceException($reason, $safe, $internal, true);
    }
}
