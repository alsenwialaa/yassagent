<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Port;

interface ProductCatalogPort
{
    /** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
    public function discover(array $args): array;
    /** @return array{product:array<string,mixed>,variation:array<string,mixed>|null} */
    public function getBySku(string $sku): array;
    /** @return array<string,mixed> */
    public function get(int $productId): array;
    /** @return array<string,mixed> */
    public function getVariation(int $variationId, int $expectedParentId = 0): array;
    /** @return array{items:array<int,array<string,mixed>>,total:int,authority_epoch:string} */
    public function variationCatalog(int $productId): array;
    /** @return array<int,array<string,mixed>> */
    public function related(int $productId, int $limit = 6): array;
    /** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
    public function alternatives(int $productId, array $args): array;
    /** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
    public function categories(array $args): array;
}
