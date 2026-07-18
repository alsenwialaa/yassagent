<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use YassinStore\AiAssistant\Support\Base64Url;

final class Base64UrlTest extends TestCase
{
    public function testRoundTripUsesCanonicalUnpaddedEncoding(): void
    {
        $binary = hash('sha256', 'stage-h-base64url-fixture', true);
        $encoded = Base64Url::encode($binary);

        self::assertSame(43, strlen($encoded));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $encoded);
        self::assertSame($binary, Base64Url::decode($encoded));
    }

    /** @dataProvider malformedValues */
    public function testMalformedAndNonCanonicalValuesAreRejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base64Url::decode($value);
    }

    /** @return array<string,array{string}> */
    public function malformedValues(): array
    {
        return array(
            'empty' => array(''),
            'padding is forbidden' => array('Zg=='),
            'invalid alphabet' => array('Zg+'),
            'invalid remainder' => array('a'),
            'non-canonical unused bits' => array('Zh'),
        );
    }
}
