<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use Throwable;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationStep;
use YassinStore\AiAssistant\Domain\Commerce\CartPrimitive;
use YassinStore\AiAssistant\Domain\Commerce\OperationRecord;
use YassinStore\AiAssistant\Domain\Commerce\OperationStatus;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepRepository;

/** Terminalizes deterministic planning failure before any prepared operation can execute. */
final class CartOperationPlanningGuard
{
    /** @var CartStepPlanner */ private $planner;
    /** @var CartStepRepository */ private $steps;
    /** @var CartOperationTerminalizer */ private $terminalizer;

    public function __construct(
        CartStepPlanner $planner,
        CartStepRepository $steps,
        CartOperationTerminalizer $terminalizer
    ) {
        $this->planner = $planner;
        $this->steps = $steps;
        $this->terminalizer = $terminalizer;
    }

    /**
     * @return array{primitives:array<int,CartPrimitive>,steps:array<int,CartOperationStep>}
     */
    public function resolve(
        OperationRecord $operation,
        TurnLease $conversationLease,
        TurnLease $commerceLease
    ): array {
        try {
            return array(
                'primitives' => $this->planner->plan($operation->plan(), $operation->preState()),
                'steps' => $this->steps->findByOperation($operation->id()),
            );
        } catch (Throwable $exception) {
            if ($operation->status() !== OperationStatus::PREPARED) {
                $this->terminalizer->uncertain(
                    $operation,
                    $conversationLease,
                    $commerceLease,
                    'cart_step_planning_failed',
                    $exception->getMessage()
                );
            } else {
                $this->terminalizer->failure(
                    $operation,
                    $conversationLease,
                    $commerceLease,
                    array(),
                    new SafeCommerceException(
                        'cart_step_planning_failed',
                        ('تعذر تجهيز تغيير السلة، ولم يتم تنفيذ التعديل.'),
                        $exception->getMessage(),
                        false
                    )
                );
            }
        }
    }
}
