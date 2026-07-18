<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use YassinStore\AiAssistant\Application\Ai\ImageAttachment;
use YassinStore\AiAssistant\Application\Contract\PublicApiContract;
use YassinStore\AiAssistant\Domain\Exception\InvalidRequest;
use YassinStore\AiAssistant\Infrastructure\Runtime\ImageRuntimeCapability;
use YassinStore\AiAssistant\Support\Arr;
use YassinStore\AiAssistant\Support\CanonicalBase64;

/** Streams strict canonical base64 into a temporary file for image inspection. */
final class ImageAttachmentDecoder
{
    private const CHUNK_BYTES = 32768; // Multiple of four for independent base64 chunks.

    /** @var PublicApiContract */ private $contract;
    /** @var ImageRuntimeCapability */ private $runtime;

    public function __construct(PublicApiContract $contract, ImageRuntimeCapability $runtime)
    {
        $this->contract = $contract;
        $this->runtime = $runtime;
    }

    /**
     * @param array<mixed> $attachments
     * @return array<int,ImageAttachment>
     */
    public function decode(array $attachments, bool $enabled): array
    {
        if ($attachments === array()) {
            return array();
        }
        if (!Arr::isList($attachments)) {
            throw $this->invalid('attachments_contract_invalid', 'يجب أن تكون الصور قائمة مرتبة.');
        }
        if (!$enabled || count($attachments) > $this->contract->attachmentMaxItems()) {
            throw $this->invalid('images_not_allowed', 'إرفاق الصور غير متاح.');
        }

        $normalized = array();
        $totalEncoded = 0;
        $totalDecoded = 0;
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || ($attachment !== array() && Arr::isList($attachment))) {
                throw $this->invalid('image_contract_invalid', 'بيانات الصورة غير صالحة.');
            }
            $this->assertOnlyFields($attachment);
            $mime = isset($attachment['mime_type']) && is_string($attachment['mime_type'])
                ? strtolower(trim($attachment['mime_type']))
                : '';
            $data = isset($attachment['data']) && is_string($attachment['data'])
                ? $attachment['data']
                : '';

            // First-release public input is one canonical raw base64 form.
            if (strpos($data, 'data:') === 0 || !CanonicalBase64::isValid($data)) {
                throw $this->invalid('image_encoding_invalid', 'ترميز الصورة غير صالح.');
            }
            $encodedBytes = strlen($data);
            $decodedBytes = CanonicalBase64::decodedLength($data);
            if (!in_array($mime, $this->contract->attachmentMimeTypes(), true)) {
                throw $this->invalid('image_type_unsupported', 'نوع الصورة غير مدعوم.');
            }
            if (
                $encodedBytes > $this->contract->attachmentMaxEncodedBytes()
                || $decodedBytes < $this->contract->attachmentMinDecodedBytes()
                || $decodedBytes > $this->contract->attachmentMaxDecodedBytes()
            ) {
                throw $this->invalid('image_size_invalid', 'الصورة أكبر من الحد المسموح.');
            }

            $totalEncoded += $encodedBytes;
            $totalDecoded += $decodedBytes;
            if (
                $totalEncoded > $this->contract->attachmentMaxTotalEncodedBytes()
                || $totalDecoded > $this->contract->attachmentMaxTotalDecodedBytes()
            ) {
                throw $this->invalid('images_total_size_invalid', 'إجمالي حجم الصور أكبر من الحد المسموح.');
            }
            $normalized[] = array('mime_type' => $mime, 'data' => $data, 'decoded_bytes' => $decodedBytes);
        }

        if (!$this->runtime->canProcess()) {
            throw new InvalidRequest(
                'images_memory_unavailable',
                ('إرفاق الصور غير متاح مؤقتاً بسبب حدود ذاكرة الخادم.'),
                'The runtime did not have the reserved memory headroom for a bounded image request.',
                503
            );
        }

        $result = array();
        foreach ($normalized as $row) {
            $sha256 = $this->inspect(
                (string) $row['mime_type'],
                (string) $row['data'],
                (int) $row['decoded_bytes']
            );
            $result[] = new ImageAttachment(
                (string) $row['mime_type'],
                (string) $row['data'],
                (int) $row['decoded_bytes'],
                $sha256
            );
        }
        return $result;
    }

    /** @param array<mixed> $attachment */
    private function assertOnlyFields(array $attachment): void
    {
        foreach (array_keys($attachment) as $field) {
            if (!is_string($field) || !in_array($field, $this->contract->attachmentFields(), true)) {
                throw $this->invalid('image_field_unknown', 'تحتوي الصورة على حقل غير مدعوم.');
            }
        }
    }

    private function inspect(string $mime, string $data, int $expectedBytes): string
    {
        $stream = tmpfile();
        if (!is_resource($stream)) {
            throw new InvalidRequest(
                'image_processing_unavailable',
                ('تعذر تجهيز الصورة على الخادم.'),
                'Unable to create a bounded temporary image stream.',
                503
            );
        }

        try {
            $hash = hash_init('sha256');
            $decodedTotal = 0;
            $length = strlen($data);
            for ($offset = 0; $offset < $length; $offset += self::CHUNK_BYTES) {
                $encodedChunk = substr($data, $offset, self::CHUNK_BYTES);
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- strict decoding of a validated image data URI, never executable code.
                $binaryChunk = base64_decode($encodedChunk, true);
                if (!is_string($binaryChunk)) {
                    throw $this->invalid('image_encoding_invalid', 'ترميز الصورة غير صالح.');
                }
                $chunkLength = strlen($binaryChunk);
                $decodedTotal += $chunkLength;
                if ($decodedTotal > $expectedBytes) {
                    throw $this->invalid('image_size_invalid', 'الصورة أكبر من الحد المسموح.');
                }
                hash_update($hash, $binaryChunk);
                $written = 0;
                while ($written < $chunkLength) {
                    $count = fwrite($stream, substr($binaryChunk, $written));
                    if (!is_int($count) || $count <= 0) {
                        throw new InvalidRequest(
                            'image_processing_unavailable',
                            ('تعذر تجهيز الصورة على الخادم.'),
                            'Unable to write the bounded temporary image stream.',
                            503
                        );
                    }
                    $written += $count;
                }
                unset($binaryChunk, $encodedChunk);
            }
            if ($decodedTotal !== $expectedBytes || fflush($stream) !== true) {
                throw $this->invalid('image_size_invalid', 'الصورة غير صالحة.');
            }

            $metadata = stream_get_meta_data($stream);
            $uri = isset($metadata['uri']) && is_string($metadata['uri']) ? $metadata['uri'] : '';
            $imageInfo = $uri !== '' ? @getimagesize($uri) : false;
            if (
                !is_array($imageInfo)
                || strtolower((string) $imageInfo['mime']) !== $mime
            ) {
                throw $this->invalid('image_mime_mismatch', 'محتوى الصورة لا يطابق نوعها.');
            }
            $width = (int) $imageInfo[0];
            $height = (int) $imageInfo[1];
            if (
                $width <= 0
                || $height <= 0
                || $width > $this->contract->attachmentMaxWidth()
                || $height > $this->contract->attachmentMaxHeight()
                || ($width * $height) > $this->contract->attachmentMaxPixels()
            ) {
                throw $this->invalid('image_dimensions_invalid', 'أبعاد الصورة أكبر من الحد المسموح.');
            }
            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private function invalid(string $code, string $arabic): InvalidRequest
    {
        return new InvalidRequest($code, ($arabic));
    }
}
