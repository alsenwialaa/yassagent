<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

/** Exact web-origin comparison: scheme, host, and normalized port. */
final class OriginPolicy
{
    public function allows(string $requestOriginOrReferer, string $homeUrl): bool
    {
        $requestOriginOrReferer = trim($requestOriginOrReferer);
        if ($requestOriginOrReferer === '') {
            // Non-browser clients may omit both Origin and Referer; session
            // credentials and request contracts remain required separately.
            return true;
        }

        $request = $this->normalize($requestOriginOrReferer);
        $home = $this->normalize($homeUrl);
        return $request !== '' && $home !== '' && hash_equals($home, $request);
    }

    private function normalize(string $url): string
    {
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(rtrim((string) wp_parse_url($url, PHP_URL_HOST), '.'));
        $port = wp_parse_url($url, PHP_URL_PORT);
        if (!in_array($scheme, array('http', 'https'), true) || $host === '') {
            return '';
        }
        if ($port === null || $port === false) {
            $port = $scheme === 'https' ? 443 : 80;
        }
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            return '';
        }
        return $scheme . '://' . $host . ':' . $port;
    }
}
