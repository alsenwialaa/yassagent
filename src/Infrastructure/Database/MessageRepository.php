<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use YassinStore\AiAssistant\Application\Port\MessageStorePort;
use YassinStore\AiAssistant\Application\Turn\UserMessagePresentation;
use RuntimeException;
use YassinStore\AiAssistant\Domain\Chat\Outcome;
use YassinStore\AiAssistant\Domain\Chat\TurnStatus;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Uuid;

/** Canonical exact transcript and terminal-only model-history store. */
final class MessageRepository implements MessageStorePort
{
    /** @param array<string,mixed> $conversation @return array<string,mixed> */
    public function appendUserMessage(array $conversation, string $turnId, string $content, array $payload = array()): array
    {
        $presentation = is_array($payload['presentation'] ?? null)
            ? $payload['presentation']
            : array();
        $payload['presentation'] = UserMessagePresentation::fromArray($presentation)->forClient();
        return $this->appendMessage((int) $conversation['id'], $turnId, 'user', '', $content, $payload);
    }

    /** @param array<string,mixed> $conversation @param array<string,mixed> $payload @return array<string,mixed> */
    public function appendAssistantMessage(
        array $conversation,
        string $turnId,
        string $outcome,
        string $content,
        array $payload
    ): array {
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : array();
        $messageId = isset($message['id']) && is_string($message['id'])
            ? strtolower($message['id']) : '';
        if (!Uuid::isV4($messageId)) {
            throw new RuntimeException('Canonical assistant message identity is missing.');
        }
        return $this->appendMessage(
            (int) $conversation['id'],
            $turnId,
            'assistant',
            $outcome,
            $content,
            $payload,
            $messageId
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function modelHistory(int $conversationId, int $turnLimit, string $excludeTurnId = ''): array
    {
        $turns = $this->terminalTurns($conversationId, max(1, min(24, $turnLimit)), $excludeTurnId);
        $messages = $this->messagesByTurn($conversationId, array_keys($turns));
        $history = array();

        foreach ($turns as $turnId => $status) {
            $pair = $messages[$turnId] ?? array();
            if (!isset($pair['user'], $pair['assistant']) || count($pair) !== 2) {
                throw new RuntimeException('A terminal turn has an incomplete canonical message pair.');
            }
            $this->assertTurnOutcome($status, (string) $pair['assistant']['outcome']);
            $history[] = array('role' => 'user', 'content' => (string) $pair['user']['content'], 'outcome' => '');
            $history[] = array(
                'role' => 'assistant',
                'content' => (string) $pair['assistant']['content'],
                'outcome' => (string) $pair['assistant']['outcome'],
            );
        }
        return $history;
    }

    /** @return array<int,array<string,mixed>> */
    public function clientMessages(int $conversationId, int $turnLimit): array
    {
        $turns = $this->terminalTurns($conversationId, max(1, min(50, $turnLimit)), '');
        $rows = $this->messagesByTurn($conversationId, array_keys($turns));
        return $this->clientMessagesFromTurns($turns, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function clientTurnMessages(int $conversationId, string $turnId): array
    {
        if ($conversationId < 1 || !Uuid::isV4($turnId)) {
            throw new RuntimeException('Canonical client turn identity is invalid.');
        }
        $turnId = strtolower($turnId);
        $status = $this->terminalTurnStatus($conversationId, $turnId);
        $rows = $this->messagesByTurn($conversationId, array($turnId));
        return $this->clientMessagesFromTurns(array($turnId => $status), $rows);
    }

    /** @return array{id:int,quote:string}|null */
    public function quotedProduct(
        int $conversationId,
        string $messageId,
        int $productIndex,
        string $quote
    ): ?array {
        $messageId = strtolower(trim($messageId));
        if (
            $conversationId < 1 || !Uuid::isV4($messageId)
            || $productIndex < 0 || $productIndex > 7
        ) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . SchemaRegistry::messages()
                . ' WHERE conversation_id = %d AND public_id = %s AND role = %s LIMIT 1',
                $conversationId,
                $messageId,
                'assistant'
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to resolve a quoted product message.');
        }
        if (!is_array($row)) {
            return null;
        }

        $stored = $this->hydrateMessage($row);
        $canonical = $stored['payload']['message'] ?? null;
        $products = is_array($canonical) && is_array($canonical['products'] ?? null)
            ? $canonical['products'] : array();
        $product = $products[$productIndex] ?? null;
        if (
            !is_array($canonical)
            || !hash_equals($messageId, strtolower((string) ($canonical['id'] ?? '')))
            || !is_array($product)
        ) {
            return null;
        }
        $productId = isset($product['id']) && is_int($product['id']) ? $product['id'] : 0;
        $name = isset($product['name']) && is_string($product['name']) ? $product['name'] : '';
        $price = isset($product['formatted_price']) && is_string($product['formatted_price'])
            ? $product['formatted_price'] : '';
        $canonicalQuote = trim($name . ($price !== '' ? ' — ' . $price : ''));
        if ($productId < 1 || $canonicalQuote === '' || !hash_equals($canonicalQuote, $quote)) {
            return null;
        }
        return array('id' => $productId, 'quote' => $canonicalQuote);
    }

    /**
     * @param array<string,string> $turns
     * @param array<string,array<string,array<string,mixed>>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function clientMessagesFromTurns(array $turns, array $rows): array
    {
        $messages = array();

        foreach ($turns as $turnId => $status) {
            $pair = $rows[$turnId] ?? array();
            if (!isset($pair['user'], $pair['assistant']) || count($pair) !== 2) {
                throw new RuntimeException('A terminal turn has an incomplete canonical message pair.');
            }
            $this->assertTurnOutcome($status, (string) $pair['assistant']['outcome']);
            $user = $pair['user'];
            $assistant = $pair['assistant'];
            $messages[] = array(
                'id' => (string) $user['public_id'],
                'turn_id' => $turnId,
                'role' => 'user',
                'outcome' => '',
                'text' => (string) $user['content'],
                'products' => array(),
                'receipts' => array(),
                'presentation' => UserMessagePresentation::fromArray(
                    is_array($user['payload']['presentation'] ?? null)
                        ? $user['payload']['presentation']
                        : array()
                )->forClient(),
                'created_at' => (int) $user['created_at'],
            );

            $canonical = $assistant['payload']['message'] ?? null;
            if (!is_array($canonical) || ($canonical !== array() && Arr::isList($canonical))) {
                throw new RuntimeException('A terminal assistant message has no canonical client payload.');
            }
            $messages[] = $canonical;
        }
        return $messages;
    }

    private function terminalTurnStatus(int $conversationId, string $turnId): string
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT turn_id,status FROM ' . SchemaRegistry::turns()
                . ' WHERE conversation_id = %d AND turn_id = %s LIMIT 1',
                $conversationId,
                $turnId
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to read committed conversation turn.');
        }
        if (
            !is_array($row)
            || !hash_equals($turnId, strtolower((string) ($row['turn_id'] ?? '')))
            || !TurnStatus::isTerminal((string) ($row['status'] ?? ''))
        ) {
            throw new RuntimeException('Committed turn evidence is missing or nonterminal.');
        }
        return (string) $row['status'];
    }

    /** @return array<string,string> turn_id => status in chronological order */
    private function terminalTurns(int $conversationId, int $limit, string $excludeTurnId): array
    {
        if ($conversationId < 1 || ($excludeTurnId !== '' && !Uuid::isV4($excludeTurnId))) {
            throw new RuntimeException('Message-history turn identity is invalid.');
        }
        global $wpdb;
        $where = 'conversation_id = %d AND status IN (%s,%s,%s)';
        $params = array(
            $conversationId,
            TurnStatus::COMPLETED,
            TurnStatus::SAFE_FAILED,
            TurnStatus::UNCERTAIN,
        );
        if ($excludeTurnId !== '') {
            $where .= ' AND turn_id <> %s';
            $params[] = strtolower($excludeTurnId);
        }
        $params[] = max(1, min(50, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT turn_id,status FROM ' . SchemaRegistry::turns()
                . " WHERE {$where} ORDER BY id DESC LIMIT %d",
                $params
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to read terminal conversation turns.');
        }
        $rows = is_array($rows) ? array_reverse($rows) : array();
        $turns = array();
        foreach ($rows as $row) {
            $turnId = (string) ($row['turn_id'] ?? '');
            $status = (string) ($row['status'] ?? '');
            if (!Uuid::isV4($turnId) || !TurnStatus::isTerminal($status) || isset($turns[$turnId])) {
                throw new RuntimeException('Terminal turn history contains invalid durable evidence.');
            }
            $turns[strtolower($turnId)] = $status;
        }
        return $turns;
    }

    /** @param array<int,string> $turnIds @return array<string,array<string,array<string,mixed>>> */
    private function messagesByTurn(int $conversationId, array $turnIds): array
    {
        if ($turnIds === array()) {
            return array();
        }
        foreach ($turnIds as $turnId) {
            if (!Uuid::isV4($turnId)) {
                throw new RuntimeException('Canonical message turn identity is invalid.');
            }
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($turnIds), '%s'));
        $params = array_merge(array($conversationId), array_values($turnIds));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . SchemaRegistry::messages()
                . " WHERE conversation_id = %d AND turn_id IN ({$placeholders}) ORDER BY id ASC",
                $params
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to read canonical conversation messages.');
        }

        $grouped = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $message = $this->hydrateMessage($row);
            $turnId = (string) $message['turn_id'];
            $role = (string) $message['role'];
            if (isset($grouped[$turnId][$role])) {
                throw new RuntimeException('A turn contains duplicate canonical message roles.');
            }
            $grouped[$turnId][$role] = $message;
        }
        return $grouped;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function appendMessage(
        int $conversationId,
        string $turnId,
        string $role,
        string $outcome,
        string $content,
        array $payload,
        string $requestedPublicId = ''
    ): array {
        if (
            $conversationId < 1 || !Uuid::isV4($turnId)
            || !in_array($role, array('user', 'assistant'), true)
            || ($role === 'user' && $outcome !== '')
            || ($role === 'assistant' && !in_array($outcome, Outcome::all(), true))
            || trim($content) === ''
            || ($payload !== array() && Arr::isList($payload))
        ) {
            throw new RuntimeException('Canonical message contract is invalid.');
        }

        global $wpdb;
        $turnId = strtolower($turnId);
        $publicId = $requestedPublicId !== '' ? strtolower($requestedPublicId) : Uuid::v4();
        if (!Uuid::isV4($publicId)) {
            throw new RuntimeException('Canonical message public identity is invalid.');
        }
        // Accepted customer text is canonical authority. It must remain byte
        // identical across hashing, the current model request, persistence,
        // transcript display, and later model history. Presentation payloads
        // are canonicalized without rewriting transcript content.
        $payload = $this->canonicalPayload($payload, $content);
        $createdAt = time();

        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . SchemaRegistry::messages()
                . ' (public_id,conversation_id,turn_id,role,outcome,content,payload,created_at)'
                . ' VALUES (%s,%d,%s,%s,%s,%s,%s,%s)',
                $publicId,
                $conversationId,
                $turnId,
                $role,
                $outcome,
                $content,
                Json::encodeObject($payload),
                gmdate('Y-m-d H:i:s', $createdAt)
            )
        );
        if ($inserted === false || WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to persist conversation message.');
        }

        if ((int) $inserted !== 1) {
            $existing = $this->findMessageByTurn($conversationId, $turnId, $role);
            if (
                $existing === null
                || !hash_equals((string) $existing['content'], $content)
                || !hash_equals(Json::canonical((array) $existing['payload']), Json::canonical($payload))
                || !hash_equals((string) $existing['outcome'], $outcome)
            ) {
                throw new RuntimeException('A conflicting canonical message already exists for this turn.');
            }
            return $existing;
        }

        return array(
            'id' => (int) $wpdb->insert_id,
            'public_id' => $publicId,
            'conversation_id' => $conversationId,
            'turn_id' => $turnId,
            'role' => $role,
            'outcome' => $outcome,
            'content' => $content,
            'payload' => $payload,
            'created_at' => $createdAt,
        );
    }

    /** @return array<string,mixed>|null */
    private function findMessageByTurn(int $conversationId, string $turnId, string $role): ?array
    {
        if (!Uuid::isV4($turnId)) {
            throw new RuntimeException('Canonical message turn identity is invalid.');
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . SchemaRegistry::messages()
                . ' WHERE conversation_id = %d AND turn_id = %s AND role = %s LIMIT 1',
                $conversationId,
                strtolower($turnId),
                $role
            ),
            ARRAY_A
        );
        if (WpdbError::has($wpdb)) {
            throw new RuntimeException('Unable to read canonical conversation message.');
        }
        return is_array($row) ? $this->hydrateMessage($row) : null;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function canonicalPayload(array $payload, string $canonicalContent): array
    {
        unset($payload['attachments']);
        if (isset($payload['message']) && is_array($payload['message']) && isset($payload['message']['text'])) {
            // The independently stored content column and replay payload must
            // be byte-identical. In particular, a verified
            // action's receipt message is contractually identical to the
            // assistant text; leaving the receipt stale would make the
            // browser reject and retry an already committed cart operation.
            $payload['message']['text'] = $canonicalContent;
            if (
                ($payload['message']['outcome'] ?? '') === Outcome::ACTION_VERIFIED
                && is_array($payload['message']['receipts'] ?? null)
            ) {
                foreach ($payload['message']['receipts'] as &$receipt) {
                    if (is_array($receipt) && array_key_exists('message', $receipt)) {
                        $receipt['message'] = $canonicalContent;
                    }
                }
                unset($receipt);
            }
        }
        return $payload;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateMessage(array $row): array
    {
        $publicId = (string) ($row['public_id'] ?? '');
        $turnId = (string) ($row['turn_id'] ?? '');
        $role = (string) ($row['role'] ?? '');
        $outcome = (string) ($row['outcome'] ?? '');
        $content = (string) ($row['content'] ?? '');
        if (
            (int) ($row['id'] ?? 0) < 1 || (int) ($row['conversation_id'] ?? 0) < 1
            || !Uuid::isV4($publicId) || !Uuid::isV4($turnId)
            || !in_array($role, array('user', 'assistant'), true)
            || ($role === 'user' && $outcome !== '')
            || ($role === 'assistant' && !in_array($outcome, Outcome::all(), true))
            || trim($content) === ''
        ) {
            throw new RuntimeException('Durable canonical message evidence is invalid.');
        }
        return array(
            'id' => (int) $row['id'],
            'public_id' => strtolower($publicId),
            'conversation_id' => (int) $row['conversation_id'],
            'turn_id' => strtolower($turnId),
            'role' => $role,
            'outcome' => $outcome,
            'content' => $content,
            'payload' => Json::decodeRequiredObject((string) ($row['payload'] ?? ''), 'Message payload'),
            'created_at' => $this->utcTimestamp((string) ($row['created_at'] ?? '')),
        );
    }

    private function assertTurnOutcome(string $status, string $outcome): void
    {
        if ($status === TurnStatus::COMPLETED) {
            if (!in_array($outcome, array(Outcome::ANSWER, Outcome::FOLLOW_UP, Outcome::ACTION_VERIFIED), true)) {
                throw new RuntimeException('Completed turn outcome is inconsistent.');
            }
            return;
        }
        if (
            !in_array($status, array(TurnStatus::SAFE_FAILED, TurnStatus::UNCERTAIN), true)
            || $outcome !== Outcome::SAFE_FAILURE
        ) {
            throw new RuntimeException('Failed turn outcome is inconsistent.');
        }
    }

    private function utcTimestamp(string $value): int
    {
        if ($value === '') {
            throw new RuntimeException('Message timestamp is missing.');
        }
        $timestamp = strtotime($value . ' UTC');
        if ($timestamp === false || $timestamp < 1) {
            throw new RuntimeException('Message timestamp is invalid.');
        }
        return $timestamp;
    }
}
