<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Ai\ImageAttachment;
use YassinStore\AiAssistant\Application\Ai\ImageAttachmentPolicy;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Utf8;
use YassinStore\AiAssistant\Support\Uuid;

/** Immutable public turn command with stream-validated image attachments. */
final class TurnRequest
{
    /** @var string */ private $conversationPublicId;
    /** @var string */ private $conversationToken;
    /** @var string */ private $turnId;
    /** @var string */ private $message;
    /** @var array<int,ImageAttachment> */ private $attachments;
    /** @var string */ private $replyContext;
    /** @var string */ private $replyMessageId;
    /** @var int|null */ private $replyProductIndex;

    /** @param array<int,ImageAttachment> $attachments */
    public function __construct(
        string $conversationPublicId,
        string $conversationToken,
        string $turnId,
        string $message,
        array $attachments,
        string $replyContext = '',
        string $replyMessageId = '',
        ?int $replyProductIndex = null
    ) {
        $conversationPublicId = strtolower(trim($conversationPublicId));
        $conversationToken = trim($conversationToken);
        $turnId = strtolower(trim($turnId));
        if (
            !Uuid::isV4($conversationPublicId)
            || !Uuid::isV4($turnId)
            || strlen($conversationToken) < 24
            || strlen($conversationToken) > 180
            || preg_match('/^[A-Za-z0-9_-]+$/', $conversationToken) !== 1
            || !Arr::isList($attachments)
            || count($attachments) > ImageAttachmentPolicy::MAX_ITEMS
        ) {
            throw new InvalidArgumentException('Turn request identity or envelope is invalid.');
        }
        if (!Utf8::isPlainText($message)) {
            throw new InvalidArgumentException('Turn request message is not valid plain text.');
        }
        $messageLength = Utf8::codePointLength($message);
        if ($messageLength > 1200) {
            throw new InvalidArgumentException('Turn request message is too long.');
        }
        if (
            $replyContext !== '' && (
            Utf8::isWhitespaceOnly($replyContext)
            || !Utf8::isPlainText($replyContext)
            || !Utf8::isBounded($replyContext, 280, 1120)
            )
        ) {
            throw new InvalidArgumentException('Turn request reply context is invalid.');
        }
        $replyMessageId = strtolower(trim($replyMessageId));
        if (
            ($replyMessageId === '') !== ($replyProductIndex === null)
            || ($replyMessageId !== '' && (
                !Uuid::isV4($replyMessageId)
                || $replyProductIndex < 0
                || $replyProductIndex > 7
                || $replyContext === ''
            ))
        ) {
            throw new InvalidArgumentException('Turn request product-reply authority is invalid.');
        }

        $normalizedAttachments = array();
        $totalDecodedBytes = 0;
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof ImageAttachment) {
                throw new InvalidArgumentException('Turn request attachment is invalid.');
            }
            $totalDecodedBytes += $attachment->decodedBytes();
            if ($totalDecodedBytes > ImageAttachmentPolicy::MAX_TOTAL_DECODED_BYTES) {
                throw new InvalidArgumentException('Turn request attachments exceed the aggregate image limit.');
            }
            $normalizedAttachments[] = $attachment;
        }
        if (Utf8::isWhitespaceOnly($message) && $normalizedAttachments === array()) {
            throw new InvalidArgumentException('Turn request has no user input.');
        }

        $this->conversationPublicId = $conversationPublicId;
        $this->conversationToken = $conversationToken;
        $this->turnId = $turnId;
        $this->message = $message;
        $this->attachments = $normalizedAttachments;
        $this->replyContext = $replyContext;
        $this->replyMessageId = $replyMessageId;
        $this->replyProductIndex = $replyProductIndex;
    }

    public function conversationPublicId(): string
    {
        return $this->conversationPublicId;
    }
    public function conversationToken(): string
    {
        return $this->conversationToken;
    }
    public function turnId(): string
    {
        return $this->turnId;
    }
    public function message(): string
    {
        return $this->message;
    }
    public function replyContext(): string
    {
        return $this->replyContext;
    }
    public function hasProductReply(): bool
    {
        return $this->replyMessageId !== '';
    }
    public function replyMessageId(): string
    {
        return $this->replyMessageId;
    }
    public function replyProductIndex(): ?int
    {
        return $this->replyProductIndex;
    }
    /** @return array<int,ImageAttachment> */ public function attachments(): array
    {
        return $this->attachments;
    }
    public function attachmentCount(): int
    {
        return count($this->attachments);
    }
}
