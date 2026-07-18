<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use RuntimeException;
use Throwable;
use YassinStore\AiAssistant\Application\Agent\AgentRunner;
use YassinStore\AiAssistant\Application\Chat\ConversationContextWindow;
use YassinStore\AiAssistant\Application\Port\ConversationStorePort;
use YassinStore\AiAssistant\Application\Port\LoggerPort;
use YassinStore\AiAssistant\Application\Port\MessageStorePort;
use YassinStore\AiAssistant\Application\Port\RuntimeReadinessPort;
use YassinStore\AiAssistant\Application\Port\ProductCatalogPort;
use YassinStore\AiAssistant\Application\Port\TurnStorePort;
use YassinStore\AiAssistant\Domain\Chat\AssistantResponse;
use YassinStore\AiAssistant\Domain\Chat\TurnRecord;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\LeaseLostException;
use YassinStore\AiAssistant\Domain\Exception\OperationPendingException;
use YassinStore\AiAssistant\Domain\Exception\SafeCommerceException;
use YassinStore\AiAssistant\Domain\Exception\TurnUnavailableException;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;
use YassinStore\AiAssistant\Application\Execution\ExecutionBoundary;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;
use YassinStore\AiAssistant\Domain\Exception\ExecutionBudgetException;

/** Explicit admitted-turn state machine from reservation to atomic terminal commit. */
final class TurnWorkflow
{
    /** @var TurnAdmission */ private $admission;
    /** @var TurnStorePort */ private $turns;
    /** @var ConversationStorePort */ private $conversations;
    /** @var MessageStorePort */ private $messages;
    /** @var CommerceTurnRecovery */ private $commerce;
    /** @var AgentRunner */ private $agent;
    /** @var TurnCommitter */ private $committer;
    /** @var RuntimeReadinessPort */ private $readiness;
    /** @var LoggerPort */ private $logger;
    /** @var TextLocalizerPort */ private $text;
    /** @var ConversationContextWindow */ private $contextWindow;
    /** @var ProductCatalogPort */ private $catalog;

    public function __construct(
        TurnAdmission $admission,
        TurnStorePort $turns,
        ConversationStorePort $conversations,
        MessageStorePort $messages,
        CommerceTurnRecovery $commerce,
        AgentRunner $agent,
        TurnCommitter $committer,
        RuntimeReadinessPort $readiness,
        LoggerPort $logger,
        TextLocalizerPort $text,
        ConversationContextWindow $contextWindow,
        ProductCatalogPort $catalog
    ) {
        $this->admission = $admission;
        $this->turns = $turns;
        $this->conversations = $conversations;
        $this->messages = $messages;
        $this->commerce = $commerce;
        $this->agent = $agent;
        $this->committer = $committer;
        $this->readiness = $readiness;
        $this->logger = $logger;
        $this->text = $text;
        $this->contextWindow = $contextWindow;
        $this->catalog = $catalog;
    }

    /** @param array<string,mixed> $conversation */
    public function execute(
        array $conversation,
        TurnRequest $request,
        string $sessionHash,
        string $remoteIp,
        TurnLease $lease,
        ?TurnExecutionSupervisor $supervisor = null
    ): TurnResult {
        $turn = null;
        $lease = $supervisor !== null ? $supervisor->lease() : $lease;
        try {
            $admission = $this->admission->admit(
                $conversation,
                $request,
                $sessionHash,
                $remoteIp,
                $lease
            );
            $turn = $admission['turn'];
            if ($admission['result'] instanceof TurnResult) {
                return $admission['result'];
            }
            if (!($turn instanceof TurnRecord)) {
                throw new RuntimeException('Turn admission returned no executable turn.');
            }

            $lease = $supervisor !== null ? $supervisor->lease() : $lease;
            $turn = $this->turns->claim($turn, $lease->fence());
            if ($turn->isTerminal()) {
                return $this->committer->replay($turn);
            }

            $canonical = $this->conversations->reload($turn->conversationId());
            if ($canonical === null) {
                return $this->commitResponse(
                    $turn,
                    $lease,
                    AssistantResponse::safeFailure(
                        $this->text->text('انتهت المحادثة ولم يعد ممكناً إكمال الطلب.'),
                        'conversation_expired'
                    ),
                    $supervisor
                );
            }

            $recovery = $this->commerce->recover(
                $canonical,
                $turn,
                $sessionHash,
                $supervisor !== null ? $supervisor->lease() : $lease,
                $supervisor
            );
            if ($recovery !== null) {
                return $this->commitResponse($turn, $lease, $recovery, $supervisor);
            }

            if (!$this->readiness->isReady()) {
                return $this->commitResponse(
                    $turn,
                    $lease,
                    AssistantResponse::safeFailure(
                        $this->text->text('خدمة المساعد غير متاحة حالياً. راجع إعدادات الإضافة واختبار الاتصال.'),
                        'assistant_not_ready'
                    ),
                    $supervisor
                );
            }

            $history = $this->messages->modelHistory(
                $turn->conversationId(),
                $this->contextWindow->terminalTurnLimit(),
                $turn->turnId()
            );
            $quotedProduct = null;
            if ($request->hasProductReply()) {
                $quoted = $this->messages->quotedProduct(
                    $turn->conversationId(),
                    $request->replyMessageId(),
                    (int) $request->replyProductIndex(),
                    $request->replyContext()
                );
                if ($quoted === null) {
                    throw new SafeCommerceException(
                        'reply_product_context_invalid',
                        $this->text->text('تعذر التحقق من المنتج المقتبس. اختر بطاقة المنتج من المحادثة الحالية وحاول مجدداً.')
                    );
                }
                $quotedProduct = $this->catalog->get($quoted['id']);
            }
            $response = $this->agent->handle(
                $canonical,
                $history,
                $request->message(),
                $request->replyContext(),
                $request->attachments(),
                $sessionHash,
                $turn->turnId(),
                $supervisor !== null ? $supervisor->lease() : $lease,
                $supervisor,
                $quotedProduct
            );

            return $this->commitResponse($turn, $lease, $response, $supervisor);
        } catch (ExecutionBudgetException $exception) {
            throw $exception;
        } catch (OperationPendingException $exception) {
            throw $exception;
        } catch (LeaseLostException $exception) {
            throw $exception;
        } catch (SafeCommerceException $exception) {
            if (!($turn instanceof TurnRecord)) {
                throw new TurnUnavailableException(
                    $exception->reasonCode(),
                    $exception->safeMessage(),
                    409,
                    2,
                    $exception->getMessage()
                );
            }
            return $this->commitCommerceFailure(
                $turn,
                $lease,
                $exception,
                $supervisor
            );
        } catch (TurnUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->handleUnexpected(
                $conversation,
                $request,
                $sessionHash,
                $turn,
                $lease,
                $exception,
                $supervisor
            );
        }
    }

    private function commitCommerceFailure(
        TurnRecord $turn,
        TurnLease $lease,
        SafeCommerceException $exception,
        ?TurnExecutionSupervisor $supervisor = null
    ): TurnResult {
        return $this->commitResponse(
            $turn,
            $lease,
            AssistantResponse::safeFailure(
                $exception->safeMessage(),
                $exception->reasonCode(),
                $exception->stateMayHaveChanged()
            ),
            $supervisor
        );
    }

    private function commitResponse(
        TurnRecord $turn,
        TurnLease $lease,
        AssistantResponse $response,
        ?TurnExecutionSupervisor $supervisor = null
    ): TurnResult {
        if ($supervisor !== null) {
            $lease = $supervisor->before(ExecutionBoundary::TERMINAL_COMMIT);
        }
        return $this->committer->commit($turn, $lease, $response);
    }

    /** @param array<string,mixed> $conversation */
    private function handleUnexpected(
        array $conversation,
        TurnRequest $request,
        string $sessionHash,
        ?TurnRecord $turn,
        TurnLease $lease,
        Throwable $exception,
        ?TurnExecutionSupervisor $supervisor = null
    ): TurnResult {
        $this->logger->error('Turn processing failed.', array(
            'conversation' => (string) ($conversation['public_id'] ?? ''),
            'turn' => $request->turnId(),
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
        ));

        if ($turn instanceof TurnRecord && !$turn->isTerminal()) {
            // An unexpected exception can occur after the cart coordinator has
            // already durably classified and verified an operation (for
            // example while assembling or committing its customer response).
            // Reconciliation therefore has priority over any generic failure.
            // Once an operation exists, a pre-effect safe failure must never
            // overwrite its receipt or uncertain terminal evidence.
            try {
                $recovered = $this->commerce->recover(
                    $conversation,
                    $turn,
                    $sessionHash,
                    $supervisor !== null ? $supervisor->lease() : $lease,
                    $supervisor
                );
            } catch (Throwable $reconciliationFailure) {
                $this->logger->error('Unexpected turn commerce reconciliation failed.', array(
                    'turn' => $request->turnId(),
                    'type' => get_class($reconciliationFailure),
                    'message' => $reconciliationFailure->getMessage(),
                ));
                throw new TurnUnavailableException(
                    'turn_reconciliation_required',
                    $this->text->text('تعذر تثبيت النتيجة. أعد إرسال الطلب نفسه قبل بدء طلب آخر.'),
                    503,
                    2,
                    $reconciliationFailure->getMessage()
                );
            }

            if ($recovered instanceof AssistantResponse) {
                try {
                    return $this->commitResponse(
                        $turn,
                        $supervisor !== null ? $supervisor->lease() : $lease,
                        $recovered,
                        $supervisor
                    );
                } catch (Throwable $recoveredCommitFailure) {
                    $this->logger->error('Recovered commerce result could not be committed.', array(
                        'turn' => $request->turnId(),
                        'type' => get_class($recoveredCommitFailure),
                        'message' => $recoveredCommitFailure->getMessage(),
                    ));
                    throw new TurnUnavailableException(
                        'turn_reconciliation_required',
                        $this->text->text('تعذر تثبيت النتيجة. أعد إرسال الطلب نفسه قبل بدء طلب آخر.'),
                        503,
                        2,
                        $recoveredCommitFailure->getMessage()
                    );
                }
            }

            try {
                $response = AssistantResponse::safeFailure(
                    $this->text->text('حدث خطأ داخلي قبل تثبيت نتيجة الطلب. لم يتم تأكيد أي تغيير غير موثّق.'),
                    'turn_processing_failed'
                );
                return $this->commitResponse(
                    $turn,
                    $lease,
                    $response,
                    $supervisor
                );
            } catch (Throwable $commitFailure) {
                $this->logger->error('Turn failure could not be committed.', array(
                    'turn' => $request->turnId(),
                    'type' => get_class($commitFailure),
                    'message' => $commitFailure->getMessage(),
                ));
            }
        }

        throw new TurnUnavailableException(
            'turn_reconciliation_required',
            $this->text->text('تعذر تثبيت النتيجة. أعد إرسال الطلب نفسه قبل بدء طلب آخر.'),
            503,
            2,
            $exception->getMessage()
        );
    }
}
