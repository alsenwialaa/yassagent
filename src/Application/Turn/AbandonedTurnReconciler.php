<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use RuntimeException;
use YassinStore\AiAssistant\Application\Port\ConversationStorePort;
use YassinStore\AiAssistant\Application\Port\TurnStorePort;
use YassinStore\AiAssistant\Domain\Chat\AssistantResponse;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Exception\TurnUnavailableException;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;
use YassinStore\AiAssistant\Application\Execution\ExecutionBoundary;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;

/** Closes interrupted turns before a different request may enter the conversation. */
final class AbandonedTurnReconciler
{
    /** @var TurnStorePort */ private $turns;
    /** @var ConversationStorePort */ private $conversations;
    /** @var CommerceTurnRecovery */ private $commerce;
    /** @var TurnCommitter */ private $committer;
    /** @var TextLocalizerPort */ private $text;

    public function __construct(
        TurnStorePort $turns,
        ConversationStorePort $conversations,
        CommerceTurnRecovery $commerce,
        TurnCommitter $committer,
        TextLocalizerPort $text
    ) {
        $this->turns = $turns;
        $this->conversations = $conversations;
        $this->commerce = $commerce;
        $this->committer = $committer;
        $this->text = $text;
    }

    /** @param array<string,mixed> $conversation */
    public function reconcile(
        array $conversation,
        string $incomingTurnId,
        string $sessionHash,
        TurnLease $lease,
        ?TurnExecutionSupervisor $supervisor = null
    ): void {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $active = $this->turns->findActive((int) $conversation['id']);
            if ($active === null || hash_equals($active->turnId(), $incomingTurnId)) {
                return;
            }

            $currentLease = $supervisor !== null ? $supervisor->lease() : $lease;
            $active = $this->turns->claim($active, $currentLease->fence());
            if ($active->isTerminal()) {
                continue;
            }
            $canonical = $this->conversations->reload($active->conversationId());
            if ($canonical === null) {
                throw new RuntimeException('An abandoned turn has no canonical conversation.');
            }

            $response = $this->commerce->recover(
                $canonical,
                $active,
                $sessionHash,
                $currentLease,
                $supervisor
            );
            if ($response === null) {
                $response = AssistantResponse::safeFailure(
                    $this->text->text('انقطع الطلب السابق قبل نتيجة نهائية، لذلك أُغلق دون تأكيد أي إجراء على السلة.'),
                    'abandoned_turn_interrupted'
                );
            }
            if ($supervisor !== null) {
                $currentLease = $supervisor->before(ExecutionBoundary::TERMINAL_COMMIT);
            }
            $this->committer->commit($active, $currentLease, $response);
        }

        throw new TurnUnavailableException(
            'abandoned_turn_backlog',
            $this->text->text('هناك طلب آخر قيد التنفيذ أو التحقق. أعد إرسال الطلب نفسه بعد لحظة.'),
            409,
            2,
            'Too many unresolved turns were found for one conversation.'
        );
    }
}
