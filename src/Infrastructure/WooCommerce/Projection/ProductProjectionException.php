<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce\Projection;

use RuntimeException;

/** A live product cannot be projected into safe model/storefront display facts. */
final class ProductProjectionException extends RuntimeException
{
}
