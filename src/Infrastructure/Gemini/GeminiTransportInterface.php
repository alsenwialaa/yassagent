<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

interface GeminiTransportInterface
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function generate(array $payload): array;
}
