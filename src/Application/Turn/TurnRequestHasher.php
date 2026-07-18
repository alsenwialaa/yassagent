<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use YassinStore\AiAssistant\Application\Port\FingerprintPort;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;

/**
 * Separates exact replay identity from durable observability.
 *
 * Raw customer text and image bytes exist only in the decoded
 * request. Durable turn input contains purpose-separated HMAC fingerprints and
 * bounded metadata. This prevents offline dictionary checks against stored
 * plain SHA-256 message digests.
 */
final class TurnRequestHasher
{
    /** @var FingerprintPort */ private $fingerprints;

    public function __construct(FingerprintPort $fingerprints)
    {
        $this->fingerprints = $fingerprints;
    }

    public function hash(TurnRequest $request): string
    {
        return $this->fingerprints->digest(
            'turn-request-v1',
            Json::canonicalObject($this->canonicalInput($request))
        );
    }

    /** @return array<string,mixed> */
    public function storageInput(TurnRequest $request): array
    {
        $message = $request->message();
        return array(
            'schema' => 1,
            'message_present' => $message !== '',
            'message_length' => Utf8::codePointLength($message),
            'message_fingerprint' => $message !== ''
                ? $this->fingerprints->digest('turn-message-v1', $message)
                : '',
            'reply_context_present' => $request->replyContext() !== '',
            'reply_context_length' => Utf8::codePointLength($request->replyContext()),
            'reply_context_fingerprint' => $request->replyContext() !== ''
                ? $this->fingerprints->digest('turn-reply-context-v1', $request->replyContext())
                : '',
            'attachments' => $this->attachmentEvidence($request),
        );
    }

    /** @return array<string,mixed> */
    private function canonicalInput(TurnRequest $request): array
    {
        return array(
            'message_fingerprint' => $request->message() !== ''
                ? $this->fingerprints->digest('turn-message-v1', $request->message())
                : '',
            'reply_context_fingerprint' => $request->replyContext() !== ''
                ? $this->fingerprints->digest('turn-reply-context-v1', $request->replyContext())
                : '',
            'reply_product_source' => $request->hasProductReply() ? array(
                'message_id' => $request->replyMessageId(),
                'product_index' => $request->replyProductIndex(),
            ) : null,
            'attachments' => $this->attachmentEvidence($request),
        );
    }

    /** @return array<int,array{mime_type:string,fingerprint:string,bytes:int}> */
    private function attachmentEvidence(TurnRequest $request): array
    {
        $attachments = array();
        foreach ($request->attachments() as $attachment) {
            $attachments[] = array(
                'mime_type' => $attachment->mimeType(),
                'fingerprint' => $this->fingerprints->digest(
                    'turn-attachment-v1',
                    $attachment->mimeType() . "\0" . $attachment->contentSha256()
                ),
                'bytes' => $attachment->decodedBytes(),
            );
        }
        return $attachments;
    }
}
