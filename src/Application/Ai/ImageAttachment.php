<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

use InvalidArgumentException;
use YassinStore\AiAssistant\Support\CanonicalBase64;

/** Immutable validated inline image without an in-memory decoded binary copy. */
final class ImageAttachment
{
    /** @var string */ private $mimeType;
    /** @var string */ private $base64Data;
    /** @var int */ private $decodedBytes;
    /** @var string */ private $contentSha256;

    public function __construct(
        string $mimeType,
        string $base64Data,
        int $decodedBytes,
        string $contentSha256
    ) {
        $mimeType = strtolower(trim($mimeType));
        $calculatedBytes = CanonicalBase64::decodedLength($base64Data);
        if (
            !in_array($mimeType, ImageAttachmentPolicy::mimeTypes(), true)
            || strlen($base64Data) > ImageAttachmentPolicy::MAX_ENCODED_BYTES
            || $calculatedBytes < ImageAttachmentPolicy::MIN_DECODED_BYTES
            || $calculatedBytes > ImageAttachmentPolicy::MAX_DECODED_BYTES
            || $decodedBytes !== $calculatedBytes
            || preg_match('/^[a-f0-9]{64}$/', $contentSha256) !== 1
        ) {
            throw new InvalidArgumentException('Image attachment is outside the canonical bounded envelope.');
        }

        $this->mimeType = $mimeType;
        $this->base64Data = $base64Data;
        $this->decodedBytes = $decodedBytes;
        $this->contentSha256 = $contentSha256;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }
    public function base64Data(): string
    {
        return $this->base64Data;
    }
    public function decodedBytes(): int
    {
        return $this->decodedBytes;
    }
    public function contentSha256(): string
    {
        return $this->contentSha256;
    }
}
