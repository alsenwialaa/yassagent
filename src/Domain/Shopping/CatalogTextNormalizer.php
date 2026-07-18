<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Shopping;

use YassinStore\AiAssistant\Support\Utf8;

/** Pure language-tolerant normalization for catalog matching and grounded ranking. */
final class CatalogTextNormalizer
{
    public function normalize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = strtr($text, array(
            '٠' => '0','١' => '1','٢' => '2','٣' => '3','٤' => '4','٥' => '5','٦' => '6','٧' => '7','٨' => '8','٩' => '9',
            '۰' => '0','۱' => '1','۲' => '2','۳' => '3','۴' => '4','۵' => '5','۶' => '6','۷' => '7','۸' => '8','۹' => '9',
            'أ' => 'ا','إ' => 'ا','آ' => 'ا','ٱ' => 'ا','ى' => 'ي','ئ' => 'ي','ؤ' => 'و','ة' => 'ه','ـ' => '',
        ));
        $withoutMarks = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text);
        if (is_string($withoutMarks)) {
            $text = $withoutMarks;
        }
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        if (!is_string($text)) {
            return '';
        }
        $collapsed = preg_replace('/\s+/u', ' ', $text);
        return is_string($collapsed) ? trim($collapsed) : '';
    }

    /**
     * Bounded retrieval variants for common Arabic orthographic differences.
     * These variants only expand candidate recall; final relevance is always
     * decided against normalized live product facts.
     *
     * @return array<int,string>
     */
    public function searchVariants(string $text): array
    {
        $original = trim($text);
        $normalized = $this->normalize($text);
        $rows = array();
        foreach (array($original, $normalized) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $rows[$candidate] = true;
            }
        }
        if ($normalized !== '') {
            $taa = preg_replace('/ه(?=\s|$)/u', 'ة', $normalized);
            $alifMaqsura = preg_replace('/ي(?=\s|$)/u', 'ى', $normalized);
            $both = is_string($taa) ? preg_replace('/ي(?=\s|$)/u', 'ى', $taa) : null;
            foreach (array($taa, $alifMaqsura, $both) as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $rows[$candidate] = true;
                }
            }
        }
        return array_slice(array_keys($rows), 0, 5);
    }

    /** @return array<int,string> */
    public function tokens(string $text): array
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return array();
        }
        $parts = preg_split('/\s+/u', $normalized) ?: array();
        $rows = array();
        foreach ($parts as $part) {
            $length = Utf8::codePointLength($part);
            if ($length < 2 || isset($rows[$part])) {
                continue;
            }
            $rows[$part] = true;
        }
        return array_keys($rows);
    }

    public function contains(string $haystack, string $needle): bool
    {
        $haystack = $this->normalize($haystack);
        $needle = $this->normalize($needle);
        return $needle !== '' && $haystack !== '' && strpos(' ' . $haystack . ' ', ' ' . $needle . ' ') !== false;
    }
}
