<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use RuntimeException;
use YassinStore\AiAssistant\Domain\Exception\InvalidRequest;
use YassinStore\AiAssistant\Domain\Chat\AssistantResponse;
use YassinStore\AiAssistant\Domain\Chat\TurnRecord;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Application\Port\TurnLeasePort;
use YassinStore\AiAssistant\Application\Port\ConversationStorePort;
use YassinStore\AiAssistant\Application\Port\MessageStorePort;
use YassinStore\AiAssistant\Application\Port\MaintenanceGatePort;
use YassinStore\AiAssistant\Application\Port\TransactionPort;
use YassinStore\AiAssistant\Application\Port\TurnStorePort;
use YassinStore\AiAssistant\Application\Port\RateLimiterPort;
use YassinStore\AiAssistant\Application\Port\TextLocalizerPort;

final class TurnAdmission
{
    /** @var TransactionPort */ private $transactions;
    /** @var TurnLeasePort */ private $leases;
    /** @var ConversationStorePort */ private $conversations;
    /** @var TurnStorePort */ private $turns;
    /** @var MessageStorePort */ private $messages;
    /** @var RateLimiterPort */ private $rateLimiter;
    /** @var TurnRequestHasher */ private $hasher;
    /** @var TurnCommitter */ private $committer;
    /** @var CanonicalUserMessageFactory */ private $userMessages;
    /** @var TextLocalizerPort */ private $text;
    /** @var MaintenanceGatePort */ private $maintenanceGate;

    public function __construct(
        TransactionPort $transactions,
        TurnLeasePort $leases,
        ConversationStorePort $conversations,
        TurnStorePort $turns,
        MessageStorePort $messages,
        RateLimiterPort $rateLimiter,
        TurnRequestHasher $hasher,
        TurnCommitter $committer,
        CanonicalUserMessageFactory $userMessages,
        MaintenanceGatePort $maintenanceGate,
        TextLocalizerPort $text
    ) {
        $this->transactions = $transactions;
        $this->leases = $leases;
        $this->conversations = $conversations;
        $this->turns = $turns;
        $this->messages = $messages;
        $this->rateLimiter = $rateLimiter;
        $this->hasher = $hasher;
        $this->committer = $committer;
        $this->userMessages = $userMessages;
        $this->maintenanceGate = $maintenanceGate;
        $this->text = $text;
    }

    /**
     * Admission is serialized by the same fenced conversation lease used for
     * execution. Reserving a turn and appending its user message before lease
     * ownership would allow concurrent turns to interleave canonical history.
     *
     * @param array<string,mixed> $conversation
     * @return array{turn:TurnRecord|null,result:TurnResult|null}
     */
    public function admit(
        array $conversation,
        TurnRequest $request,
        string $sessionHash,
        string $remoteIp,
        TurnLease $lease
    ): array {
        /** @var array{turn:TurnRecord|null,result:TurnResult|null} $admission */
        $admission = $this->maintenanceGate->run(function () use (
            $conversation,
            $request,
            $sessionHash,
            $remoteIp,
            $lease
        ): array {
            return $this->transactions->run(function () use (
                $conversation,
                $request,
                $sessionHash,
                $remoteIp,
                $lease
            ): array {
                $this->leases->assertCurrentForUpdate($lease);
                $canonical = $this->conversations->reloadForUpdate((int) $conversation['id']);
                if (
                    $canonical === null
                    || !hash_equals((string) ($canonical['public_id'] ?? ''), (string) ($conversation['public_id'] ?? ''))
                    || !hash_equals((string) ($canonical['session_hash'] ?? ''), $sessionHash)
                ) {
                    throw new RuntimeException('Conversation authority changed before turn admission.');
                }

                $storageInput = $this->hasher->storageInput($request);
                $requestHash = $this->hasher->hash($request);

            // Exact retries are canonical reads, not new AI admissions. Check
            // them before consuming any rate-limit capacity. The conversation
            // row lock serializes legitimate admissions for this conversation.
                $existing = $this->turns->find((int) $canonical['id'], $request->turnId());
                if ($existing !== null) {
                    if (!hash_equals($existing->requestHash(), $requestHash)) {
                        throw new InvalidRequest(
                            'turn_id_conflict',
                            $this->text->text('تم استخدام معرّف هذا الطلب لمحتوى مختلف.'),
                            'The client turn ID was reused with a different canonical request hash.',
                            409
                        );
                    }
                    return array(
                    'turn' => $existing,
                    'result' => $existing->isTerminal() ? $this->committer->replay($existing) : null,
                    );
                }

            // A denied new request must not create turn or message rows. This
            // makes the limiter an actual database-abuse boundary, not merely
            // an AI-provider limiter.
                $rate = $this->rateLimiter->consume($sessionHash, $remoteIp);
                if (!$rate['allowed']) {
                    $response = AssistantResponse::safeFailure(
                        $this->text->text('تم بلوغ حد الطلبات مؤقتاً. أعد المحاولة لاحقاً في طلب جديد.'),
                        'rate_limited'
                    );
                    $message = $response->forClient();
                    $message['turn_id'] = $request->turnId();
                    return array('turn' => null, 'result' => new TurnResult($message, false));
                }

                $reservation = $this->turns->reserve(
                    (int) $canonical['id'],
                    $request->turnId(),
                    $requestHash,
                    $storageInput
                );
                $turn = $reservation->turn();
                if (!$reservation->created()) {
                    throw new RuntimeException('Turn admission was unexpectedly raced after canonical locking.');
                }

                $userMessage = $this->userMessages->create($request);
                $this->messages->appendUserMessage(
                    $canonical,
                    $request->turnId(),
                    $userMessage->text(),
                    array(
                    'request_hash' => $requestHash,
                    'attachment_count' => $request->attachmentCount(),
                    'presentation' => $userMessage->presentation()->forClient(),
                    )
                );

                return array('turn' => $turn, 'result' => null);
            });
        });

        return $admission;
    }
}
