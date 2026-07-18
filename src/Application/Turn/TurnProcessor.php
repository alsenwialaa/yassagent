<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use Throwable;
use YassinStore\AiAssistant\Application\Port\LoggerPort;
use YassinStore\AiAssistant\Application\Port\RuntimeSettingsPort;
use YassinStore\AiAssistant\Application\Port\TurnLeasePort;
use YassinStore\AiAssistant\Domain\Exception\LeaseLostException;
use YassinStore\AiAssistant\Domain\Exception\OperationPendingException;
use YassinStore\AiAssistant\Domain\Exception\TurnUnavailableException;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;
use YassinStore\AiAssistant\Application\Execution\ExecutionBoundary;
use YassinStore\AiAssistant\Application\Execution\ExecutionDeadline;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionPolicy;
use YassinStore\AiAssistant\Domain\Exception\ExecutionBudgetException;

/** Conversation-lease boundary around the admitted-turn state machine. */
final class TurnProcessor
{
    /** @var TurnLeasePort */ private $leases;
    /** @var AbandonedTurnReconciler */ private $abandoned;
    /** @var TurnWorkflow */ private $workflow;
    /** @var RuntimeSettingsPort */ private $settings;
    /** @var LoggerPort */ private $logger;
    /** @var TextLocalizerPort */ private $text;

    public function __construct(
        TurnLeasePort $leases,
        AbandonedTurnReconciler $abandoned,
        TurnWorkflow $workflow,
        RuntimeSettingsPort $settings,
        LoggerPort $logger,
        TextLocalizerPort $text
    ) {
        $this->leases = $leases;
        $this->abandoned = $abandoned;
        $this->workflow = $workflow;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->text = $text;
    }

    /** @param array<string,mixed> $conversation */
    public function process(
        array $conversation,
        TurnRequest $request,
        string $sessionHash,
        string $remoteIp
    ): TurnResult {
        $lease = $this->leases->acquire(
            'conversation|' . (string) $conversation['public_id'],
            $this->leaseTtl()
        );
        if ($lease === null) {
            throw $this->busy('turn_in_progress');
        }

        $supervisor = new TurnExecutionSupervisor(
            $this->leases,
            $lease,
            new ExecutionDeadline($this->executionBudget()),
            $this->leaseTtl(),
            TurnExecutionPolicy::maxProviderRequests(
                (int) $this->settings->get('max_tool_rounds', 6)
            )
        );

        try {
            $supervisor->before(ExecutionBoundary::RECONCILIATION);
            $this->abandoned->reconcile(
                $conversation,
                $request->turnId(),
                $sessionHash,
                $supervisor->lease(),
                $supervisor
            );
            $supervisor->after(ExecutionBoundary::RECONCILIATION);
            return $this->workflow->execute(
                $conversation,
                $request,
                $sessionHash,
                $remoteIp,
                $supervisor->lease(),
                $supervisor
            );
        } catch (ExecutionBudgetException $exception) {
            $this->logger->error('Turn execution budget was exhausted.', array(
                'conversation' => (string) ($conversation['public_id'] ?? ''),
                'turn' => $request->turnId(),
                'boundary' => $exception->boundary(),
                'message' => $exception->getMessage(),
            ));
            throw new TurnUnavailableException(
                'turn_execution_budget_exhausted',
                $this->text->text('استغرق الطلب وقتاً أطول من الحد الآمن. أعد إرسال الطلب نفسه لإكمال التحقق دون تكرار أي إجراء.'),
                503,
                2,
                $exception->getMessage()
            );
        } catch (OperationPendingException $exception) {
            throw new TurnUnavailableException(
                $exception->reasonCode(),
                $exception->safeMessage(),
                409,
                2,
                $exception->getMessage()
            );
        } catch (LeaseLostException $exception) {
            throw $this->busy('turn_lease_lost', $exception->getMessage());
        } catch (TurnUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error('Turn orchestration failed before a terminal result.', array(
                'conversation' => (string) ($conversation['public_id'] ?? ''),
                'turn' => $request->turnId(),
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ));
            throw new TurnUnavailableException(
                'turn_reconciliation_required',
                $this->text->text('تعذر تثبيت النتيجة. أعد إرسال الطلب نفسه قبل بدء طلب آخر.'),
                503,
                2,
                $exception->getMessage()
            );
        } finally {
            try {
                $this->leases->release($supervisor->lease());
            } catch (Throwable $exception) {
                $this->logger->error('Turn lease release failed.', array(
                    'resource' => $supervisor->lease()->resourceHash(),
                    'fence' => $supervisor->lease()->fence(),
                    'message' => $exception->getMessage(),
                ));
            }
        }
    }

    private function executionBudget(): float
    {
        return (float) TurnExecutionPolicy::executionSeconds(
            (int) $this->settings->get('http_timeout_seconds', 30),
            (int) $this->settings->get('max_tool_rounds', 6)
        );
    }

    private function leaseTtl(): int
    {
        return max(120, min(
            2400,
            (int) ceil($this->executionBudget()) + 60
        ));
    }

    private function busy(string $reason, string $internal = ''): TurnUnavailableException
    {
        return new TurnUnavailableException(
            $reason,
            $this->text->text('هناك طلب آخر قيد التنفيذ أو التحقق. أعد إرسال الطلب نفسه بعد لحظة.'),
            409,
            2,
            $internal
        );
    }
}
