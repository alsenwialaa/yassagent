<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Commerce;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Ai\ImageAttachment;
use YassinStore\AiAssistant\Application\Ai\ImageAttachmentPolicy;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Json;
use YassinStore\AiAssistant\Support\Utf8;

/** Immutable, bounded evidence for one isolated semantic cart-intent decision. */
final class CartIntentVerificationRequest
{
    /** @var string */ private $currentCustomerText;
    /** @var string */ private $quotedContext;
    /** @var string */ private $intentText;
    /** @var array<int,array{role:string,text:string}> */ private $history;
    /** @var array<string,mixed> */ private $proposal;
    /** @var string */ private $fingerprint;
    /** @var array<int,ImageAttachment> */ private $attachments;

    /**
     * @param array<int,array{role:string,text:string}> $history
     * @param array<string,mixed> $proposal
     * @param array<int,ImageAttachment> $attachments
     */
    public function __construct(
        string $currentCustomerText,
        string $quotedContext,
        string $intentText,
        array $history,
        array $proposal,
        array $attachments = array()
    ) {
        if (
            $currentCustomerText === '' || Utf8::isWhitespaceOnly($currentCustomerText)
            || !Utf8::isPlainText($currentCustomerText)
            || !Utf8::isBounded($currentCustomerText, 1200, 4800)
            || ($quotedContext !== '' && (
                Utf8::isWhitespaceOnly($quotedContext)
                || !Utf8::isPlainText($quotedContext)
                || !Utf8::isBounded($quotedContext, 280, 1120)
            ))
            || $intentText === '' || Utf8::isWhitespaceOnly($intentText)
            || !Utf8::isPlainText($intentText)
            || !Utf8::isBounded($intentText, 320, 1280)
            || !Arr::isList($history) || count($history) > 12
            || $proposal === array() || Arr::isList($proposal)
            || !Arr::isList($attachments)
            || count($attachments) > ImageAttachmentPolicy::MAX_ITEMS
        ) {
            throw new InvalidArgumentException('Cart-intent verification evidence is invalid.');
        }

        $historyBytes = 0;
        $normalizedHistory = array();
        foreach ($history as $index => $row) {
            if (!is_array($row) || ($row !== array() && Arr::isList($row))) {
                throw new InvalidArgumentException('Cart-intent history contains a malformed row.');
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            $expectedRole = $index % 2 === 0 ? 'user' : 'assistant';
            if (
                $keys !== array('role', 'text')
                || !is_string($row['role']) || $row['role'] !== $expectedRole
                || !is_string($row['text']) || trim($row['text']) === ''
                || !Utf8::isPlainText($row['text'])
            ) {
                throw new InvalidArgumentException('Cart-intent history is not a complete canonical exchange.');
            }
            $historyBytes += strlen($row['text']);
            if ($historyBytes > 32768) {
                throw new InvalidArgumentException('Cart-intent history exceeds its bounded context.');
            }
            $normalizedHistory[] = array(
                'role' => $row['role'],
                'text' => $row['text'],
            );
        }
        if (count($normalizedHistory) % 2 !== 0) {
            throw new InvalidArgumentException('Cart-intent history must contain complete turns.');
        }

        $proposalJson = Json::canonicalObject($proposal);
        if (strlen($proposalJson) > 32768) {
            throw new InvalidArgumentException('Cart-intent proposal exceeds its bounded context.');
        }

        $imageEvidence = array();
        $imageBytes = 0;
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof ImageAttachment) {
                throw new InvalidArgumentException('Cart-intent image evidence is invalid.');
            }
            $imageBytes += $attachment->decodedBytes();
            if ($imageBytes > ImageAttachmentPolicy::MAX_TOTAL_DECODED_BYTES) {
                throw new InvalidArgumentException('Cart-intent image evidence exceeds its bound.');
            }
            $imageEvidence[] = array(
                'mime_type' => $attachment->mimeType(),
                'decoded_bytes' => $attachment->decodedBytes(),
                'content_sha256' => $attachment->contentSha256(),
            );
        }

        $this->currentCustomerText = $currentCustomerText;
        $this->quotedContext = $quotedContext;
        $this->intentText = $intentText;
        $this->history = $normalizedHistory;
        $this->proposal = $proposal;
        $this->attachments = array_values($attachments);
        $this->fingerprint = hash('sha256', Json::canonical(array(
            'current_customer_text' => $currentCustomerText,
            'quoted_context' => $quotedContext,
            'intent_text' => $intentText,
            'history' => $normalizedHistory,
            'proposal' => $proposal,
            'current_images' => $imageEvidence,
        )));
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }
    /** @return array<int,ImageAttachment> */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /** @return array<string,mixed> */
    public function forModel(): array
    {
        return array(
            'recent_conversation' => $this->history,
            'quoted_context' => $this->quotedContext,
            'exact_current_customer_text' => $this->currentCustomerText,
            'exact_current_evidence_excerpt' => $this->intentText,
            'server_resolved_cart_proposal' => $this->proposal,
            'current_image_count' => count($this->attachments),
            'evidence_fingerprint' => $this->fingerprint,
        );
    }
}
