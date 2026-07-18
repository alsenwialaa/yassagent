<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Agent;

use YassinStore\AiAssistant\Domain\Exception\ContractViolation;
use YassinStore\AiAssistant\Support\Utf8;

/**
 * First-release language boundary for customer-facing model output.
 *
 * English product names, brands, URLs, and identifiers may appear inside an
 * Arabic response, but pure or predominantly English terminal prose is not a
 * valid customer response.
 */
final class ArabicCustomerText
{
    private const ARABIC_PATTERN = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

    public function assertValidModelText(string $text): void
    {
        if ($text === '' || Utf8::hasOuterWhitespace($text)) {
            throw new ContractViolation(
                'customer_text_outer_whitespace',
                'Customer-facing model text must be nonblank and contain no outer whitespace.'
            );
        }
        if ($this->containsMarkup($text)) {
            throw new ContractViolation(
                'customer_text_not_plain',
                'Customer-facing terminal text must be plain text without Markdown or HTML.'
            );
        }
        if (!$this->accepts($text)) {
            throw new ContractViolation(
                'customer_text_not_arabic',
                'Customer-facing terminal text must be predominantly Arabic.'
            );
        }
    }

    public function accepts(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if ($this->containsMarkup($text)) {
            return false;
        }

        $comparison = preg_replace('~https?://\S+~iu', '', $text);
        if (!is_string($comparison)) {
            return false;
        }
        // SKU-like identifiers do not determine the language of the prose.
        $comparison = preg_replace('/\b[A-Za-z0-9_-]*\d[A-Za-z0-9_-]*\b/u', '', $comparison);
        if (!is_string($comparison)) {
            return false;
        }

        $arabic = preg_match_all(self::ARABIC_PATTERN, $comparison, $unused);
        $latin = preg_match_all('/[A-Za-z]/', $comparison, $unusedLatin);
        if (!is_int($arabic) || !is_int($latin) || $arabic < 2) {
            return false;
        }

        // Permit English product/brand spelling inside an Arabic sentence, but
        // reject a long English response with a token Arabic word appended.
        return $latin === 0 || ($arabic * 4) >= $latin;
    }

    private function containsMarkup(string $text): bool
    {
        return preg_match(
            '/(?:\*\*|__|~~|`{1,3}|!?\[[^\]\r\n]{1,200}\]\([^\)\r\n]{1,2048}\)|'
                . '(?:^|\n)\s{0,3}(?:#{1,6}\s|>\s?|[-*+]\s+|\d{1,3}[.)]\s+)|'
                . '(?<!\*)\*[^*\r\n]{1,500}\*(?!\*)|(?<!_)_[^_\r\n]{1,500}_(?!_)|'
                . '<\/?[A-Za-z!][^>\r\n]{0,200}>)/u',
            $text
        ) === 1;
    }
}
