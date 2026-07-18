<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Shopping;

/** Canonical non-negative price range projected from live price facts. */
final class ProductPriceRange
{
    private const EPSILON = 0.000001;

    /**
     * @param array<string,mixed> $product
     * @return array{min:?float,max:?float,known:bool,is_range:bool}
     */
    public static function fromSnapshot(array $product): array
    {
        return self::fromValues(
            $product['price_min'] ?? ($product['price'] ?? null),
            $product['price_max'] ?? ($product['price'] ?? null)
        );
    }

    /**
     * @param mixed $minimum
     * @param mixed $maximum
     * @return array{min:?float,max:?float,known:bool,is_range:bool}
     */
    public static function fromValues($minimum, $maximum): array
    {
        $minimum = self::numeric($minimum);
        $maximum = self::numeric($maximum);
        if ($minimum === null || $maximum === null) {
            return array('min' => null, 'max' => null, 'known' => false, 'is_range' => false);
        }
        if ($minimum > $maximum) {
            $swap = $minimum;
            $minimum = $maximum;
            $maximum = $swap;
        }
        return array(
            'min' => $minimum,
            'max' => $maximum,
            'known' => true,
            'is_range' => abs($maximum - $minimum) > self::EPSILON,
        );
    }

    /** @param mixed $value */
    private static function numeric($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) && $number >= 0.0 ? $number : null;
    }
}
