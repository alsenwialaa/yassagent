<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use RuntimeException;
use YassinStore\AiAssistant\Application\Chat\ConversationContextWindow;
use YassinStore\AiAssistant\Infrastructure\Database\MessageRepository;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Uuid;

/** Server-authoritative bounded transcript for every storefront response. */
final class ClientTranscriptProjector
{
    /** @var MessageRepository */ private $messages;
    /** @var ConversationContextWindow */ private $contextWindow;

    public function __construct(
        MessageRepository $messages,
        ConversationContextWindow $contextWindow
    ) {
        $this->messages = $messages;
        $this->contextWindow = $contextWindow;
    }

    /** @return array<int,array<string,mixed>> */
    public function messages(int $conversationId): array
    {
        return $this->messages->clientMessages(
            $conversationId,
            $this->contextWindow->terminalTurnLimit()
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function messagesIncludingTurn(int $conversationId, string $turnId): array
    {
        if ($conversationId < 1 || !Uuid::isV4($turnId)) {
            throw new RuntimeException('Requested transcript turn identity is invalid.');
        }
        $messages = $this->messages->clientMessages(
            $conversationId,
            $this->contextWindow->terminalTurnLimit()
        );
        $positions = $this->turnPositions($messages, $turnId);
        if ($positions['user'] === null && $positions['assistant'] === null) {
            $turnLimit = $this->contextWindow->terminalTurnLimit();
            $messages = array_merge(
                $this->messages->clientTurnMessages($conversationId, $turnId),
                $messages
            );
            if (count($messages) > $turnLimit * 2) {
                $messages = array_merge(
                    array_slice($messages, 0, 2),
                    array_slice($messages, -(($turnLimit - 1) * 2))
                );
            }
            $positions = $this->turnPositions($messages, $turnId);
        }
        if ($positions['user'] === null || $positions['assistant'] === null) {
            throw new RuntimeException('A terminal requested turn has no complete canonical client pair.');
        }
        return $messages;
    }

    /**
     * Projects the normal bounded transcript while guaranteeing that the exact
     * committed turn returned by the durable workflow is present. An exact
     * replay or many intervening commits may place that turn outside the normal
     * display window, but they cannot invalidate an already committed result.
     *
     * @param array<string,mixed> $committedAssistant
     * @return array{message:array<string,mixed>,messages:array<int,array<string,mixed>>}
     */
    public function committedTurn(int $conversationId, array $committedAssistant): array
    {
        $turnId = strtolower((string) ($committedAssistant['turn_id'] ?? ''));
        if (
            $conversationId < 1
            || !Uuid::isV4($turnId)
            || ($committedAssistant['role'] ?? '') !== 'assistant'
            || trim((string) ($committedAssistant['text'] ?? '')) === ''
        ) {
            throw new RuntimeException('Committed assistant projection is invalid.');
        }

        $messages = $this->messagesIncludingTurn($conversationId, $turnId);
        $positions = $this->turnPositions($messages, $turnId);

        $storedAssistant = $messages[$positions['assistant']];
        if (!hash_equals(Json::canonical($storedAssistant), Json::canonical($committedAssistant))) {
            throw new RuntimeException('Committed turn payload contradicts canonical message storage.');
        }
        $messages[$positions['assistant']] = $committedAssistant;
        $positions = $this->turnPositions($messages, $turnId);
        if ($positions['assistant'] === null) {
            throw new RuntimeException('Committed assistant projection disappeared unexpectedly.');
        }
        return array(
            'message' => $messages[$positions['assistant']],
            'messages' => $messages,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array{user:?int,assistant:?int}
     */
    private function turnPositions(array $messages, string $turnId): array
    {
        $positions = array('user' => null, 'assistant' => null);
        foreach ($messages as $index => $message) {
            if (
                !is_array($message)
                || !hash_equals((string) ($message['turn_id'] ?? ''), $turnId)
            ) {
                continue;
            }
            $role = (string) ($message['role'] ?? '');
            if (!array_key_exists($role, $positions) || $positions[$role] !== null) {
                throw new RuntimeException('Committed turn projection contains duplicate or invalid roles.');
            }
            $positions[$role] = $index;
        }
        return $positions;
    }
}
