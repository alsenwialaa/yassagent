<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Turn;

use InvalidArgumentException;
use YassinStore\AiAssistant\Application\Ai\ImageAttachment;
use YassinStore\AiAssistant\Application\Ai\ImageAttachmentPolicy;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\Utf8;

/** Bounded durable presentation metadata for a canonical customer message. */
final class UserMessagePresentation
{
    public const IMAGE_SCOPE_NONE = 'none';
    public const IMAGE_SCOPE_TURN_ONLY = 'turn_only';

    /** @var array<int,array{kind:string,mime_type:string,byte_length:int}> */
    private $images;
    /** @var string */ private $replyQuote;

    /**
     * @param array<int,array{kind:string,mime_type:string,byte_length:int}> $images
     */
    public function __construct(array $images, string $replyQuote = '')
    {
        if (!Arr::isList($images) || count($images) > ImageAttachmentPolicy::MAX_ITEMS) {
            throw new InvalidArgumentException('User-message image presentation is invalid.');
        }
        if (
            $replyQuote !== '' && (
            Utf8::isWhitespaceOnly($replyQuote)
            || !Utf8::isPlainText($replyQuote)
            || !Utf8::isBounded($replyQuote, 280, 1120)
            )
        ) {
            throw new InvalidArgumentException('User-message reply presentation is invalid.');
        }

        $normalized = array();
        foreach ($images as $image) {
            if (!is_array($image) || ($image !== array() && Arr::isList($image))) {
                throw new InvalidArgumentException('User-message image presentation row is invalid.');
            }
            $keys = array_keys($image);
            sort($keys, SORT_STRING);
            $kind = isset($image['kind']) && is_string($image['kind']) ? $image['kind'] : '';
            $mime = isset($image['mime_type']) && is_string($image['mime_type'])
                ? strtolower(trim($image['mime_type']))
                : '';
            $bytes = isset($image['byte_length']) && is_int($image['byte_length'])
                ? $image['byte_length']
                : 0;
            if (
                $keys !== array('byte_length', 'kind', 'mime_type')
                || $kind !== 'image'
                || !in_array($mime, ImageAttachmentPolicy::mimeTypes(), true)
                || $bytes < ImageAttachmentPolicy::MIN_DECODED_BYTES
                || $bytes > ImageAttachmentPolicy::MAX_DECODED_BYTES
            ) {
                throw new InvalidArgumentException('User-message image presentation fields are invalid.');
            }
            $normalized[] = array(
                'kind' => 'image',
                'mime_type' => $mime,
                'byte_length' => $bytes,
            );
        }
        $this->images = $normalized;
        $this->replyQuote = $replyQuote;
    }

    /** @param array<int,ImageAttachment> $attachments */
    public static function fromAttachments(array $attachments, string $replyQuote = ''): self
    {
        if (!Arr::isList($attachments)) {
            throw new InvalidArgumentException('User-message attachments are invalid.');
        }
        $images = array();
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof ImageAttachment) {
                throw new InvalidArgumentException('User-message attachment is invalid.');
            }
            $images[] = array(
                'kind' => 'image',
                'mime_type' => $attachment->mimeType(),
                'byte_length' => $attachment->decodedBytes(),
            );
        }
        return new self($images, $replyQuote);
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if (
            $keys !== array('image_scope', 'images', 'reply_quote')
            || !is_string($value['image_scope'] ?? null)
            || !is_array($value['images'] ?? null)
            || !is_string($value['reply_quote'] ?? null)
        ) {
            throw new InvalidArgumentException('Canonical user-message presentation is invalid.');
        }
        $presentation = new self($value['images'], $value['reply_quote']);
        if (!hash_equals($presentation->imageScope(), (string) $value['image_scope'])) {
            throw new InvalidArgumentException('Canonical user-message image scope is inconsistent.');
        }
        return $presentation;
    }

    public function imageCount(): int
    {
        return count($this->images);
    }
    public function replyQuote(): string
    {
        return $this->replyQuote;
    }

    public function imageScope(): string
    {
        return $this->images === array() ? self::IMAGE_SCOPE_NONE : self::IMAGE_SCOPE_TURN_ONLY;
    }

    /** @return array{image_scope:string,images:array<int,array{kind:string,mime_type:string,byte_length:int}>,reply_quote:string} */
    public function forClient(): array
    {
        return array(
            'image_scope' => $this->imageScope(),
            'images' => $this->images,
            'reply_quote' => $this->replyQuote,
        );
    }
}
