<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

/** One authoritative quantity range and comparison policy for cart semantics. */
final class CartQuantity
{
    public const MIN = 1;
    public const MAX = 999;

    private const EPSILON = 0.000001;

    /** @param mixed $value */
    public static function isPositiveInteger($value): bool
    {
        return self::isIntegerWithin($value, self::MIN, self::MAX);
    }

    /** @param mixed $value */
    public static function isNonNegativeInteger($value): bool
    {
        return self::isIntegerWithin($value, 0, self::MAX);
    }

    /** @param mixed $value */
    public static function isStrictPositiveInteger($value): bool
    {
        return is_int($value) && self::isPositiveInteger($value);
    }

    /** @param mixed $value */
    public static function isStrictNonNegativeInteger($value): bool
    {
        return is_int($value) && self::isNonNegativeInteger($value);
    }

    public static function equals(float $left, float $right): bool
    {
        return is_finite($left)
            && is_finite($right)
            && abs($left - $right) < self::EPSILON;
    }

    /** @param mixed $value */
    private static function isIntegerWithin($value, int $minimum, int $maximum): bool
    {
        if (!is_int($value) && !is_float($value)) {
            return false;
        }
        $quantity = (float) $value;
        return is_finite($quantity)
            && $quantity >= (float) $minimum
            && $quantity <= (float) $maximum
            && floor($quantity) === $quantity;
    }
}
