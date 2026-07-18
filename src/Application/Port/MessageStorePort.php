<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface MessageStorePort
{
    /** @param array<string,mixed> $conversation @param array<string,mixed> $payload @return array<string,mixed> */
    public function appendUserMessage(array $conversation, string $turnId, string $content, array $payload = array()): array;
    /** @param array<string,mixed> $conversation @param array<string,mixed> $payload @return array<string,mixed> */
    public function appendAssistantMessage(array $conversation, string $turnId, string $outcome, string $content, array $payload): array;
    /** @return array<int,array<string,mixed>> */
    public function modelHistory(int $conversationId, int $turnLimit, string $excludeTurnId = ''): array;
    /** @return array{id:int,quote:string}|null */
    public function quotedProduct(int $conversationId, string $messageId, int $productIndex, string $quote): ?array;
}
