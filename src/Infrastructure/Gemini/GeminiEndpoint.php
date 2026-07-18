<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use InvalidArgumentException;

/** Resolves the immutable provider endpoint, with an explicit integration-test override. */
final class GeminiEndpoint
{
    private const PRODUCTION_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    /** @var string */ private $baseUrl;

    public function __construct(string $baseUrl)
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = wp_parse_url($baseUrl);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Gemini API base URL is invalid.');
        }
        if (strtolower((string) $parts['scheme']) !== 'https' && !self::integrationMode()) {
            throw new InvalidArgumentException('Gemini API base URL must use HTTPS outside integration tests.');
        }
        $this->baseUrl = $baseUrl;
    }

    public static function configured(): self
    {
        if (
            self::integrationMode()
            && defined('YSAI_GEMINI_API_BASE_URL')
            && is_string(YSAI_GEMINI_API_BASE_URL)
            && trim(YSAI_GEMINI_API_BASE_URL) !== ''
        ) {
            return new self((string) YSAI_GEMINI_API_BASE_URL);
        }
        return new self(self::PRODUCTION_BASE);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->baseUrl);
    }

    public function generateContent(string $model): string
    {
        if ($model === '' || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $model) !== 1) {
            throw new InvalidArgumentException('Gemini model identifier is invalid.');
        }
        return $this->baseUrl . '/' . rawurlencode($model) . ':generateContent';
    }

    private static function integrationMode(): bool
    {
        return defined('YSAI_INTEGRATION_TEST_MODE') && YSAI_INTEGRATION_TEST_MODE === true;
    }
}
