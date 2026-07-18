<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Tests\Unit\Infrastructure\Database;

use PHPUnit\Framework\TestCase;
use YassinStore\AiAssistant\Infrastructure\Database\WpdbError;

final class WpdbErrorTest extends TestCase
{
    public function testRuntimeDatabaseErrorIsReadWithoutTrustingStubDefaults(): void
    {
        $wpdb = new class {
            /** @var mixed */
            public $last_error = '  database failed  ';
        };

        self::assertSame('database failed', WpdbError::message($wpdb));
        self::assertTrue(WpdbError::has($wpdb));

        $wpdb->last_error = '';
        self::assertSame('', WpdbError::message($wpdb));
        self::assertFalse(WpdbError::has($wpdb));
    }

    public function testMalformedOrMissingErrorStateIsTreatedAsNoReadableError(): void
    {
        $missing = new class {
        };
        $malformed = new class {
            /** @var mixed */
            public $last_error = array('not', 'scalar');
        };

        self::assertSame('', WpdbError::message($missing));
        self::assertFalse(WpdbError::has($malformed));
    }
}
