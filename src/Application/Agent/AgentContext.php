<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Ai\ImageAttachment;
use YassinStore\AiAssistant\Application\Ai\ImageAttachmentPolicy;
use YassinStore\AiAssistant\Application\Authority\AuthorityRegistry;
use YassinStore\AiAssistant\Application\Commerce\CommerceExecutionContext;
use YassinStore\AiAssistant\Application\Execution\TurnExecutionSupervisor;
use YassinStore\AiAssistant\Domain\Concurrency\TurnLease;
use YassinStore\AiAssistant\Domain\Commerce\PendingCartIntent;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

final class AgentContext
{
    /** @var array<string,mixed> */ private $conversation;
    /** @var string */ private $turnId;
    /** @var string */ private $sessionHash;
    /** @var AuthorityRegistry */ private $authority;
    /** @var TurnEffects */ private $effects;
    /** @var TurnLease */ private $lease;
    /** @var TurnExecutionSupervisor|null */ private $supervisor;
    /** @var string */ private $currentUserMessage;
    /** @var PendingCartIntent|null */ private $pendingCartIntent;
    /** @var array<int,array{role:string,text:string}> */ private $cartIntentHistory;
    /** @var array<int,ImageAttachment> */ private $currentAttachments;
    /** @var string */ private $currentReplyContext;

    /** @param array<string,mixed> $conversation */
    public function __construct(
        array $conversation,
        string $turnId,
        string $sessionHash,
        AuthorityRegistry $authority,
        TurnEffects $effects,
        TurnLease $lease,
        ?TurnExecutionSupervisor $supervisor = null,
        string $currentUserMessage = '',
        ?PendingCartIntent $pendingCartIntent = null,
        array $cartIntentHistory = array(),
        string $currentReplyContext = '',
        array $currentAttachments = array()
    ) {
        $conversationId = isset($conversation['id']) && is_int($conversation['id']) ? $conversation['id'] : 0;
        $publicId = isset($conversation['public_id']) && is_string($conversation['public_id'])
            ? strtolower($conversation['public_id']) : '';
        if (
            $conversationId < 1 || !Uuid::isV4($publicId) || !Uuid::isV4($turnId)
            || preg_match('/^[a-f0-9]{64}$/', $sessionHash) !== 1
            || !hash_equals('conversation|' . $publicId, $lease->resource())
        ) {
            throw new InvalidArgumentException('Agent execution authority is invalid.');
        }
        if (
            $pendingCartIntent instanceof PendingCartIntent
            && !hash_equals(
                $publicId,
                $pendingCartIntent->modelAuthoredQuestion()->conversationId()
            )
        ) {
            throw new InvalidArgumentException(
                'Pending cart clarification authority belongs to another conversation.'
            );
        }
        if (strlen($currentUserMessage) > 131072) {
            throw new InvalidArgumentException('Current-turn cart intent context is invalid.');
        }
        if (
            !Arr::isList($cartIntentHistory) || count($cartIntentHistory) > 12
            || count($cartIntentHistory) % 2 !== 0
        ) {
            throw new InvalidArgumentException('Recent cart-intent history is invalid.');
        }
        $historyBytes = 0;
        foreach ($cartIntentHistory as $index => $row) {
            $keys = is_array($row) ? array_keys($row) : array();
            sort($keys, SORT_STRING);
            $expectedRole = $index % 2 === 0 ? 'user' : 'assistant';
            if (
                $keys !== array('role', 'text')
                || !is_string($row['role']) || $row['role'] !== $expectedRole
                || !is_string($row['text']) || trim($row['text']) === ''
                || !Utf8::isPlainText($row['text'])
            ) {
                throw new InvalidArgumentException('Recent cart-intent history is malformed.');
            }
            $historyBytes += strlen($row['text']);
        }
        if ($historyBytes > 32768) {
            throw new InvalidArgumentException('Recent cart-intent history exceeds its bound.');
        }
        if (
            $currentReplyContext !== '' && (
            Utf8::isWhitespaceOnly($currentReplyContext)
            || !Utf8::isPlainText($currentReplyContext)
            || !Utf8::isBounded($currentReplyContext, 280, 1120)
            )
        ) {
            throw new InvalidArgumentException('Current-turn reply context is invalid.');
        }
        if (
            !Arr::isList($currentAttachments)
            || count($currentAttachments) > ImageAttachmentPolicy::MAX_ITEMS
        ) {
            throw new InvalidArgumentException('Current-turn image context is invalid.');
        }
        $attachmentBytes = 0;
        foreach ($currentAttachments as $attachment) {
            if (!$attachment instanceof ImageAttachment) {
                throw new InvalidArgumentException('Current-turn image context is invalid.');
            }
            $attachmentBytes += $attachment->decodedBytes();
        }
        if ($attachmentBytes > ImageAttachmentPolicy::MAX_TOTAL_DECODED_BYTES) {
            throw new InvalidArgumentException('Current-turn image context exceeds its bound.');
        }
        $conversation['public_id'] = $publicId;
        $this->conversation = $conversation;
        $this->turnId = $turnId;
        $this->sessionHash = $sessionHash;
        $this->authority = $authority;
        $this->effects = $effects;
        $this->lease = $lease;
        $this->supervisor = $supervisor;
        $this->currentUserMessage = $currentUserMessage;
        $this->pendingCartIntent = $pendingCartIntent;
        $this->cartIntentHistory = $cartIntentHistory;
        $this->currentReplyContext = $currentReplyContext;
        $this->currentAttachments = array_values($currentAttachments);
    }

    public function conversationId(): int
    {
        return (int) ($this->conversation['id'] ?? 0);
    }
    public function conversationPublicId(): string
    {
        return (string) ($this->conversation['public_id'] ?? '');
    }
    public function turnId(): string
    {
        return $this->turnId;
    }
    public function sessionHash(): string
    {
        return $this->sessionHash;
    }
    public function authority(): AuthorityRegistry
    {
        return $this->authority;
    }
    public function effects(): TurnEffects
    {
        return $this->effects;
    }
    public function lease(): TurnLease
    {
        return $this->supervisor !== null ? $this->supervisor->lease() : $this->lease;
    }
    public function supervisor(): ?TurnExecutionSupervisor
    {
        return $this->supervisor;
    }
    public function currentUserMessage(): string
    {
        return $this->currentUserMessage;
    }
    public function currentReplyContext(): string
    {
        return $this->currentReplyContext;
    }
    /** @return array<int,ImageAttachment> */
    public function currentAttachments(): array
    {
        return $this->currentAttachments;
    }
    public function pendingCartIntentAt(int $now): ?PendingCartIntent
    {
        return $this->pendingCartIntent !== null && $this->pendingCartIntent->isActive($now)
            ? $this->pendingCartIntent
            : null;
    }
    /** @return array<int,array{role:string,text:string}> */
    public function cartIntentHistory(): array
    {
        return $this->cartIntentHistory;
    }

    /** Stable digest of the exact customer-turn envelope; no image bytes are retained. */
    public function currentTurnEvidenceDigest(): string
    {
        $attachments = array();
        foreach ($this->currentAttachments as $attachment) {
            $attachments[] = array(
                'mime_type' => $attachment->mimeType(),
                'decoded_bytes' => $attachment->decodedBytes(),
                'content_sha256' => $attachment->contentSha256(),
            );
        }

        return hash('sha256', Json::canonicalObject(array(
            'schema' => 1,
            'conversation_id' => $this->conversationPublicId(),
            'client_turn_id' => $this->turnId,
            'session_hash' => $this->sessionHash,
            'customer_message' => $this->currentUserMessage,
            'reply_context' => $this->currentReplyContext,
            'attachments' => $attachments,
            'cart_intent_history' => $this->cartIntentHistory,
            'pending_cart_intent' => $this->pendingCartIntent !== null
                ? $this->pendingCartIntent->toArray()
                : null,
        )));
    }

    public function commerce(): CommerceExecutionContext
    {
        return new CommerceExecutionContext(
            $this->conversationId(),
            $this->conversationPublicId(),
            $this->turnId,
            $this->lease(),
            $this->supervisor
        );
    }
}
