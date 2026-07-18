<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Gemini;

interface GeminiTimeoutTransportInterface extends GeminiTransportInterface
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function generateWithTimeout(array $payload, int $timeoutSeconds): array;
}
