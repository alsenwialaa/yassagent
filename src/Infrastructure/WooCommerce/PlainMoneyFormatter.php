<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use YassinStore\AiAssistant\Domain\Shopping\ProductPriceRange;
use YassinStore\AiAssistant\Support\TrustedCommerceText;

/**
 * Produces safe plain-text WooCommerce money for JSON and mixed RTL/LTR UI.
 *
 * WooCommerce returns HTML with entities and currency spans. Sending that
 * markup through textContent exposes entities such as &nbsp; literally. This
 * formatter keeps WooCommerce's configured separators, decimal precision,
 * symbol, and currency position while returning one isolated text value.
 */
final class PlainMoneyFormatter
{
    private const FSI = "\xE2\x81\xA8";
    private const PDI = "\xE2\x81\xA9";

    /** @param mixed $amount */
    public function amount($amount, string $currency = ''): string
    {
        $number = $this->number($amount);
        if ($number === null) {
            return '';
        }

        $arguments = $currency !== '' ? array('currency' => $currency) : array();
        return $this->isolate($this->plain((string) wc_price($number, $arguments)));
    }

    /** @param mixed $minimum @param mixed $maximum */
    public function range($minimum, $maximum, string $currency = ''): string
    {
        $range = ProductPriceRange::fromValues($minimum, $maximum);
        if (!$range['known'] || $range['min'] === null || $range['max'] === null) {
            return '';
        }

        $first = $this->unisolatedAmount($range['min'], $currency);
        if (!$range['is_range']) {
            return $this->isolate($first);
        }
        $second = $this->unisolatedAmount($range['max'], $currency);
        return $this->isolate($first . ' – ' . $second);
    }

    /** @param mixed $amount */
    private function unisolatedAmount($amount, string $currency): string
    {
        $number = $this->number($amount);
        if ($number === null) {
            return '';
        }
        $arguments = $currency !== '' ? array('currency' => $currency) : array();
        return $this->plain((string) wc_price($number, $arguments));
    }

    private function plain(string $html): string
    {
        $text = (string) wp_strip_all_tags($html);
        $text = TrustedCommerceText::decodeEntities($text);
        $text = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
        $text = preg_replace('/[\x{00A0}\x{202F}\s]+/u', ' ', (string) $text);
        return trim((string) $text);
    }

    private function isolate(string $text): string
    {
        $text = trim($text);
        return $text === '' ? '' : self::FSI . $text . self::PDI;
    }

    /** @param mixed $value */
    private function number($value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) && $number >= 0 ? $number : null;
    }
}
