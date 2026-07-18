<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

/** Safely decodes WooCommerce persistent-cart arrays without object creation. */
final class SafePersistentCartDecoder
{
    /** @var SafeSerializedArrayDecoder */ private $decoder;

    public function __construct(?SafeSerializedArrayDecoder $decoder = null)
    {
        $this->decoder = $decoder !== null ? $decoder : new SafeSerializedArrayDecoder();
    }

    /** @return array<string|int,mixed> */
    public function decode(string $serialized): array
    {
        return $this->decoder->decode($serialized, 'Logged-in persistent-cart data');
    }
}
