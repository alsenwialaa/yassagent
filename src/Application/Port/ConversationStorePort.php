<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface ConversationStorePort
{
    /** @return array<string,mixed>|null */
    public function reload(int $conversationId): ?array;
    /** @return array<string,mixed>|null */
    public function reloadForUpdate(int $conversationId): ?array;
    /** @param array<string,mixed> $state */
    public function writeState(int $conversationId, array $state): void;
}
