<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Support;

/** Canonical 256-bit browser-held bearer credential used only at boot. */
final class BrowserContinuitySecret
{
    public static function isValid(string $value): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $value) !== 1) {
            return false;
        }
        try {
            return strlen(Base64Url::decode($value)) === 32;
        } catch (\InvalidArgumentException $exception) {
            return false;
        }
    }
}
