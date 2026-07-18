<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface ContentRepositoryPort
{
    /** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
    public function search(array $args): array;
    /** @return array<string,mixed> */
    public function get(int $postId): array;
    /** @return array<string,mixed> */
    public function policy(string $section): array;
    /** @return array<string,mixed> */
    public function storeInfo(): array;
}
