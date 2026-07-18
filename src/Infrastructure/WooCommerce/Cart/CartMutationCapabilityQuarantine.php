<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use Throwable;
use YassinStore\AiAssistant\Application\Commerce\CommerceExecutionContext;
use YassinStore\AiAssistant\Domain\Commerce\CartStepAttemptStatus;
use YassinStore\AiAssistant\Domain\Commerce\CartStepStatus;
use YassinStore\AiAssistant\Domain\Commerce\OperationRecord;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepAttemptRepository;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepRepository;
use YassinStore\AiAssistant\Infrastructure\Database\OperationRepository;

/** Quarantines unfinished authority when the verified cart persistence topology is unavailable. */
final class CartMutationCapabilityQuarantine
{
    /** @var OperationRepository */ private $operations;
    /** @var CartStepRepository */ private $steps;
    /** @var CartStepAttemptRepository */ private $attempts;
    /** @var CartMutationCapabilityProof */ private $capability;
    /** @var CartLeaseScope */ private $scope;
    /** @var CartStateEvidence */ private $evidence;
    /** @var CartOperationMessages */ private $messages;
    /** @var CartMutationCapabilityLossPolicy */ private $lossPolicy;

    public function __construct(
        OperationRepository $operations,
        CartStepRepository $steps,
        CartStepAttemptRepository $attempts,
        CartMutationCapabilityProof $capability,
        CartLeaseScope $scope,
        CartStateEvidence $evidence,
        CartOperationMessages $messages,
        CartMutationCapabilityLossPolicy $lossPolicy
    ) {
        $this->operations = $operations;
        $this->steps = $steps;
        $this->attempts = $attempts;
        $this->capability = $capability;
        $this->scope = $scope;
        $this->evidence = $evidence;
        $this->messages = $messages;
        $this->lossPolicy = $lossPolicy;
    }

    public function enforce(
        OperationRecord $operation,
        CommerceExecutionContext $context,
        TurnLease $commerceLease
    ): void {
        if ($this->capability->available()) {
            return;
        }

        $safe = ('تغيرت حالة جلسة السلة أثناء التحقق، لذلك لم يتم تأكيد نتيجة التعديل. راجع صفحة السلة.');
        $noEffectSafe = ('تعذر متابعة تغيير السلة بعد تغير قدرة الحفظ، ولم يتم تنفيذ التعديل.');
        $reason = 'cart_recovery_capability_lost';
        $observed = $this->evidence->captureOptional();
        try {
            /** @var array{reason:string,safe:string,changed:bool} $outcome */
            $outcome = $this->scope->guarded($context->lease(), $commerceLease, function () use (
                $operation,
                $context,
                $commerceLease,
                $safe,
                $noEffectSafe,
                $reason,
                $observed
            ): array {
                $operation = $this->operations->adopt(
                    $operation,
                    $context->lease()->fence(),
                    $commerceLease->resourceHash(),
                    $commerceLease->fence()
                );
                $steps = $this->steps->findByOperation($operation->id());
                if (count($steps) === 1 && $steps[0]->status() === CartStepStatus::REJECTED) {
                    $rejected = $steps[0];
                    $this->operations->markRejected(
                        $operation->id(),
                        $context->lease()->fence(),
                        $commerceLease->fence(),
                        $rejected->failureCode(),
                        $rejected->safeMessage(),
                        $observed
                    );
                    return array(
                        'reason' => $rejected->failureCode(),
                        'safe' => $rejected->safeMessage(),
                        'changed' => false,
                    );
                }
                $latestAttempts = array();
                foreach ($steps as $step) {
                    $latestAttempts[$step->id()] = $this->attempts->latestForStep($step->id(), true);
                }
                if ($this->lossPolicy->provesNoEffect($operation, $steps, $latestAttempts)) {
                    foreach ($steps as $step) {
                        $step = $this->steps->adopt(
                            $step,
                            $context->lease()->fence(),
                            $commerceLease->resourceHash(),
                            $commerceLease->fence()
                        );
                        $this->steps->markFailure(
                            $step->id(),
                            $context->lease()->fence(),
                            $commerceLease->fence(),
                            CartStepStatus::REJECTED,
                            $reason,
                            $noEffectSafe
                        );
                    }
                    $this->operations->markRejected(
                        $operation->id(),
                        $context->lease()->fence(),
                        $commerceLease->fence(),
                        $reason,
                        $noEffectSafe,
                        $observed
                    );
                    return array('reason' => $reason, 'safe' => $noEffectSafe, 'changed' => false);
                }
                foreach ($steps as $step) {
                    if ($step->isTerminal()) {
                        continue;
                    }
                    $step = $this->steps->adopt(
                        $step,
                        $context->lease()->fence(),
                        $commerceLease->resourceHash(),
                        $commerceLease->fence()
                    );
                    $attempt = $latestAttempts[$step->id()] ?? null;
                    if ($attempt !== null && !$attempt->isTerminal()) {
                        $this->attempts->markFailed(
                            $attempt->id(),
                            CartStepAttemptStatus::UNCERTAIN,
                            $reason,
                            $safe
                        );
                    }
                    $this->steps->markFailure(
                        $step->id(),
                        $context->lease()->fence(),
                        $commerceLease->fence(),
                        CartStepStatus::UNCERTAIN,
                        $reason,
                        $safe,
                        $attempt !== null ? $attempt->candidateEffect() : null,
                        $observed,
                        $attempt !== null ? $attempt->markerDigest() : ''
                    );
                }
                $this->operations->markUncertain(
                    $operation->id(),
                    $context->lease()->fence(),
                    $commerceLease->fence(),
                    $reason,
                    $safe,
                    $observed
                );
                return array('reason' => $reason, 'safe' => $safe, 'changed' => true);
            });
        } catch (Throwable $exception) {
            throw $this->messages->pending('cart_recovery_quarantine_pending', $exception->getMessage());
        }
        throw new SafeCommerceException(
            $outcome['reason'],
            $outcome['safe'],
            '',
            $outcome['changed']
        );
    }
}
