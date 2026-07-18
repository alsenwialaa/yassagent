<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

use InvalidArgumentException;

/** Closed generation policy for the single tested Gemini model. */
final class GeminiGenerationPolicy
{
    private const LEVELS = array('minimal', 'low', 'medium', 'high');

    /** @var string */ private $thinkingLevel;

    public function __construct(string $thinkingLevel)
    {
        $thinkingLevel = strtolower(trim($thinkingLevel));
        $this->thinkingLevel = in_array($thinkingLevel, self::LEVELS, true)
            ? $thinkingLevel
            : 'low';
    }

    /** @return array<string,mixed> */
    public function initialConfig(int $maxOutputTokens): array
    {
        if ($maxOutputTokens < 256 || $maxOutputTokens > 8192) {
            throw new InvalidArgumentException('Gemini output token limit is invalid.');
        }
        $config = array('maxOutputTokens' => $maxOutputTokens);
        $config['thinkingConfig'] = array('thinkingLevel' => $this->thinkingLevel);
        return $config;
    }
}
