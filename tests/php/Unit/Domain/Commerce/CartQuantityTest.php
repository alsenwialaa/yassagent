<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Tests\Unit\Domain\Commerce;

use PHPUnit\Framework\TestCase;
use YassinStore\AiAssistant\Domain\Commerce\CartQuantity;

final class CartQuantityTest extends TestCase
{
    public function testPositiveIntegerRangeIsClosedAndShared(): void
    {
        self::assertTrue(CartQuantity::isPositiveInteger(CartQuantity::MIN));
        self::assertTrue(CartQuantity::isPositiveInteger((float) CartQuantity::MAX));
        self::assertTrue(CartQuantity::isStrictPositiveInteger(CartQuantity::MIN));
        self::assertFalse(CartQuantity::isStrictPositiveInteger((float) CartQuantity::MIN));
        self::assertFalse(CartQuantity::isPositiveInteger(0));
        self::assertFalse(CartQuantity::isPositiveInteger(CartQuantity::MAX + 1));
        self::assertFalse(CartQuantity::isPositiveInteger(1.5));
        self::assertFalse(CartQuantity::isPositiveInteger('1'));
    }

    public function testNonNegativeRangeAllowsOnlyIntegralZeroThroughMaximum(): void
    {
        self::assertTrue(CartQuantity::isNonNegativeInteger(0));
        self::assertTrue(CartQuantity::isNonNegativeInteger(CartQuantity::MAX));
        self::assertTrue(CartQuantity::isStrictNonNegativeInteger(0));
        self::assertFalse(CartQuantity::isStrictNonNegativeInteger(0.0));
        self::assertFalse(CartQuantity::isNonNegativeInteger(-1));
        self::assertFalse(CartQuantity::isNonNegativeInteger(CartQuantity::MAX + 1));
    }

    public function testEqualityRejectsNonFiniteValuesAndUsesOneTolerance(): void
    {
        self::assertTrue(CartQuantity::equals(2.0, 2.0000001));
        self::assertFalse(CartQuantity::equals(2.0, 2.001));
        self::assertFalse(CartQuantity::equals(INF, INF));
    }
}
