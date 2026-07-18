<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Cart;

use RuntimeException;

/** Deterministic disagreement between verified core and persistent cart projections. */
final class PersistentCartMismatchException extends RuntimeException
{
}
