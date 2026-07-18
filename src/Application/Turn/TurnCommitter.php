<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use RuntimeException;
use YassinStore\AiAssistant\Domain\Chat\AssistantResponse;
use YassinStore\AiAssistant\Domain\Chat\ConversationState;
use YassinStore\AiAssistant\Domain\Chat\ModelAuthoredQuestion;
use YassinStore\AiAssistant\Domain\Chat\Outcome;
use YassinStore\AiAssistant\Domain\Chat\StoredModelQuestionEvidence;
use YassinStore\AiAssistant\Domain\Chat\TurnRecord;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Application\Port\TurnLeasePort;
use YassinStore\AiAssistant\Application\Port\ConversationStorePort;
use YassinStore\AiAssistant\Application\Port\MessageStorePort;
use YassinStore\AiAssistant\Application\Port\TransactionPort;
use YassinStore\AiAssistant\Application\Port\TurnStorePort;
use YassinStore\AiAssistant\Application\Port\ClockPort;

final class TurnCommitter
{
    /** @var TransactionPort */ private $transactions;
    /** @var TurnLeasePort */ private $leases;
    /** @var TurnStorePort */ private $turns;
    /** @var ConversationStorePort */ private $conversations;
    /** @var MessageStorePort */ private $messages;
    /** @var ClockPort */ private $clock;

    public function __construct(
        TransactionPort $transactions,
        TurnLeasePort $leases,
        TurnStorePort $turns,
        ConversationStorePort $conversations,
        MessageStorePort $messages,
        ClockPort $clock
    ) {
        $this->transactions = $transactions;
        $this->leases = $leases;
        $this->turns = $turns;
        $this->conversations = $conversations;
        $this->messages = $messages;
        $this->clock = $clock;
    }

    public function commit(TurnRecord $turn, TurnLease $lease, AssistantResponse $response): TurnResult
    {
        /** @var TurnResult $result */
        $result = $this->transactions->run(function () use ($turn, $lease, $response): TurnResult {
            $this->leases->assertCurrentForUpdate($lease);
            $claimed = $this->turns->assertClaimedForUpdate($turn->id(), $lease->fence());
            $conversation = $this->conversations->reloadForUpdate($claimed->conversationId());
            if ($conversation === null) {
                throw new RuntimeException('The canonical conversation no longer exists.');
            }

            $message = $response->forClient();
            $message['turn_id'] = $claimed->turnId();

            $previousState = ConversationState::fromArray(
                is_array($conversation['state'] ?? null) ? $conversation['state'] : array()
            );
            $state = $previousState->after($response, $this->clock->now());
            $this->conversations->writeState($claimed->conversationId(), $state->toArray());
            $assistantPayload = array('message' => $message);
            $modelQuestion = $response->modelAuthoredQuestion();
            if ($modelQuestion instanceof ModelAuthoredQuestion) {
                $conversationPublicId = is_string($conversation['public_id'] ?? null)
                    ? strtolower((string) $conversation['public_id'])
                    : '';
                if (
                    !hash_equals($claimed->turnId(), $modelQuestion->clientTurnId())
                    || !hash_equals($conversationPublicId, $modelQuestion->conversationId())
                    || $modelQuestion->acceptedAt() > $this->clock->now()
                ) {
                    throw new RuntimeException(
                        'Model-question provenance does not belong to the committed turn.'
                    );
                }
                $assistantPayload['model_question'] = $modelQuestion->toArray();
            }
            $storedMessage = $this->messages->appendAssistantMessage(
                $conversation,
                $claimed->turnId(),
                $response->outcome(),
                $response->text(),
                $assistantPayload
            );
            $storedPayload = is_array($storedMessage['payload'] ?? null)
                ? $storedMessage['payload']
                : array();
            $canonicalMessage = is_array($storedPayload['message'] ?? null)
                ? $storedPayload['message']
                : array();
            if ($canonicalMessage === array()) {
                throw new RuntimeException('The canonical assistant message payload is missing.');
            }

            $turnPayload = array('message' => $canonicalMessage);
            if ($modelQuestion instanceof ModelAuthoredQuestion) {
                $storedQuestion = is_array($storedPayload['model_question'] ?? null)
                    ? $storedPayload['model_question']
                    : array();
                try {
                    $restoredQuestion = ModelAuthoredQuestion::restore(StoredModelQuestionEvidence::fromArray($storedQuestion));
                } catch (\InvalidArgumentException $exception) {
                    throw new RuntimeException(
                        'The canonical model-question provenance is missing or invalid.'
                    );
                }
                if ($restoredQuestion->toArray() !== $modelQuestion->toArray()) {
                    throw new RuntimeException(
                        'The canonical model-question provenance changed during persistence.'
                    );
                }
                $turnPayload['model_question'] = $restoredQuestion->toArray();
            } elseif (array_key_exists('model_question', $storedPayload)) {
                throw new RuntimeException(
                    'A non-follow-up assistant message contains model-question provenance.'
                );
            }

            $this->turns->complete(
                $claimed->id(),
                $lease->fence(),
                $response->turnStatus(),
                $turnPayload,
                $response->failureCode()
            );

            return new TurnResult($canonicalMessage, true);
        });

        return $result;
    }

    public function replay(TurnRecord $turn): TurnResult
    {
        $payload = $turn->response();
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : array();
        if ($message === array()) {
            throw new RuntimeException('A terminal turn has no canonical replay payload.');
        }
        $isFollowUp = (string) ($message['outcome'] ?? '') === Outcome::FOLLOW_UP;
        $questionRow = $payload['model_question'] ?? null;
        if ($isFollowUp) {
            if (!is_array($questionRow)) {
                throw new RuntimeException(
                    'A committed follow-up has no durable model-question provenance.'
                );
            }
            try {
                $question = ModelAuthoredQuestion::restore(StoredModelQuestionEvidence::fromArray($questionRow));
            } catch (\InvalidArgumentException $exception) {
                throw new RuntimeException(
                    'Committed model-question provenance is invalid.'
                );
            }
            if (
                !hash_equals((string) ($message['text'] ?? ''), $question->text())
                || !hash_equals($turn->turnId(), $question->clientTurnId())
            ) {
                throw new RuntimeException(
                    'Committed model-question provenance contradicts the replay payload.'
                );
            }
        } elseif ($questionRow !== null) {
            throw new RuntimeException(
                'A committed non-follow-up contains model-question provenance.'
            );
        }
        return new TurnResult($message, true);
    }
}
