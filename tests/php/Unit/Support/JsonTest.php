<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use YassinStore\AiAssistant\Support\Json;

final class JsonTest extends TestCase
{
    public function testCanonicalObjectIsStableAcrossKeyOrder(): void
    {
        $left = array('z' => 1, 'nested' => array('b' => 2, 'a' => 1));
        $right = array('nested' => array('a' => 1, 'b' => 2), 'z' => 1);

        self::assertSame(Json::canonicalObject($left), Json::canonicalObject($right));
        self::assertSame('{"nested":{"a":1,"b":2},"z":1}', Json::canonicalObject($left));
    }

    public function testRequiredObjectRejectsListsAndMalformedJson(): void
    {
        foreach (array('[]', 'null', '{') as $invalid) {
            try {
                Json::decodeRequiredObject($invalid, 'Fixture');
                self::fail('Invalid JSON was accepted: ' . $invalid);
            } catch (RuntimeException $exception) {
                self::assertStringStartsWith('Fixture ', $exception->getMessage());
            }
        }
    }

    public function testOptionalObjectTreatsOnlyEmptyStorageAsAbsent(): void
    {
        self::assertSame(array(), Json::decodeOptionalObject(''));
        self::assertSame(array('enabled' => true), Json::decodeOptionalObject('{"enabled":true}'));

        $this->expectException(RuntimeException::class);
        Json::decodeOptionalObject('null');
    }
}
