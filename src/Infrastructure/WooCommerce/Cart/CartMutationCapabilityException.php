<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;
use Throwable;

/** Internal typed failure from one stage of the strongest cart-write proof. */
final class CartMutationCapabilityException extends RuntimeException
{
    /** @var string */ private $capabilityCode;

    public function __construct(string $capabilityCode, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
        $this->capabilityCode = $capabilityCode;
    }

    public function capabilityCode(): string
    {
        return $this->capabilityCode;
    }
}
