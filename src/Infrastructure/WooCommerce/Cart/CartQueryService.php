<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use YassinStore\AiAssistant\Application\Port\CartQueryPort;
use YassinStore\AiAssistant\Application\Port\CartSnapshotProviderPort;

final class CartQueryService implements CartQueryPort
{
    /** @var CartSnapshotProviderPort */ private $snapshots;

    public function __construct(CartSnapshotProviderPort $snapshots)
    {
        $this->snapshots = $snapshots;
    }

    /** @return array<string,mixed> */
    public function snapshot(bool $includeAuthority = false): array
    {
        return $this->snapshots->capture()->forClient($includeAuthority);
    }

    /** @return array{item_count:int,formatted_total:string,cart_url:string,checkout_url:string} */
    public function displaySummary(): array
    {
        $snapshot = $this->snapshot(false);
        return array(
            'item_count' => (int) $snapshot['item_count'],
            'formatted_total' => (string) $snapshot['formatted_total'],
            'cart_url' => (string) $snapshot['cart_url'],
            'checkout_url' => (string) $snapshot['checkout_url'],
        );
    }
}
