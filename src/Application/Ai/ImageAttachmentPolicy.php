<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Ai;

/**
 * Single first-release authority for bounded inline image input.
 *
 * The browser may resize a larger source file, but the public REST payload and
 * every internal model boundary use these final encoded/decoded limits.
 */
final class ImageAttachmentPolicy
{
    public const MAX_ITEMS = 2;
    public const MIN_DECODED_BYTES = 16;
    public const MAX_DECODED_BYTES = 524288; // 512 KiB per image.
    public const MAX_TOTAL_DECODED_BYTES = 1048576; // 1 MiB aggregate.
    public const MAX_ENCODED_BYTES = 699052; // 4 * ceil(512 KiB / 3).
    public const MAX_TOTAL_ENCODED_BYTES = 1398104;
    public const MAX_WIDTH = 4096;
    public const MAX_HEIGHT = 4096;
    public const MAX_PIXELS = 12000000;

    // The widget may resize a larger local file before it becomes request data.
    public const MAX_SOURCE_BYTES = 8388608;
    public const MAX_SOURCE_HEADER_BYTES = 262144;
    public const MAX_SOURCE_WIDTH = 4096;
    public const MAX_SOURCE_HEIGHT = 4096;
    public const MAX_SOURCE_PIXELS = 12582912; // 12 Mi pixels; at most 48 MiB RGBA before decode-time resize.

    // Boot advertises image support only with enough room for the future body.
    public const ADVERTISE_HEADROOM_BYTES = 20971520;
    // The decoder rechecks after the JSON body has already been materialized.
    public const PROCESS_HEADROOM_BYTES = 12582912;

    /** @return array<int,string> */
    public static function mimeTypes(): array
    {
        return array('image/jpeg', 'image/png', 'image/webp');
    }

    private function __construct()
    {
    }
}
