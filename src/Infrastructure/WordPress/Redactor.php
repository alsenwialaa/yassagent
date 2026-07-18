<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

final class Redactor
{
    public static function contacts(string $text): string
    {
        $text = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[redacted-email]', $text);
        $text = (string) preg_replace_callback(
            '/(?<![\p{N}])(?:\+?[\p{N}][\p{N}\s().\/-]{7,40}[\p{N}])(?![\p{N}])/u',
            static function (array $match): string {
                $digits = array();
                $count = preg_match_all('/\p{N}/u', (string) ($match[0] ?? ''), $digits);
                return is_int($count) && $count >= 9
                    ? '[redacted-phone]'
                    : (string) ($match[0] ?? '');
            },
            $text
        );
        return $text;
    }
}
