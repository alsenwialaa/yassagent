<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Tests\Unit\Domain\Shopping;

use PHPUnit\Framework\TestCase;
use YassinStore\AiAssistant\Domain\Shopping\ProductPriceRange;

final class ProductPriceRangeTest extends TestCase
{
    public function testExactAndVariableRangesUseOneCanonicalProjection(): void
    {
        self::assertSame(
            array('min' => 10.0, 'max' => 10.0, 'known' => true, 'is_range' => false),
            ProductPriceRange::fromSnapshot(array('price' => '10'))
        );
        self::assertSame(
            array('min' => 10.0, 'max' => 20.0, 'known' => true, 'is_range' => true),
            ProductPriceRange::fromSnapshot(array('price_min' => '20', 'price_max' => '10'))
        );
    }

    public function testRawValuesShareOrderingAndTolerance(): void
    {
        self::assertSame(
            array('min' => 10.0, 'max' => 20.0, 'known' => true, 'is_range' => true),
            ProductPriceRange::fromValues('20', '10')
        );
        self::assertSame(
            array('min' => 10.0, 'max' => 10.0000001, 'known' => true, 'is_range' => false),
            ProductPriceRange::fromValues(10, 10.0000001)
        );
    }

    public function testUnknownOrNegativePriceFactsFailClosed(): void
    {
        $unknown = array('min' => null, 'max' => null, 'known' => false, 'is_range' => false);
        self::assertSame($unknown, ProductPriceRange::fromSnapshot(array()));
        self::assertSame($unknown, ProductPriceRange::fromSnapshot(array('price' => '-1')));
        self::assertSame($unknown, ProductPriceRange::fromSnapshot(array('price_min' => '10')));
    }
}
