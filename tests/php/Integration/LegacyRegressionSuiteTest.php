<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class LegacyRegressionSuiteTest extends TestCase
{
    public function testDecomposedLegacySuiteRetainsEveryAssertion(): void
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(YSAI_TEST_PROJECT_ROOT . '/tests/run.php') . ' 2>&1';
        $lines = array();
        $status = 0;
        exec($command, $lines, $status);
        $output = implode("\n", $lines);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('378 tests, 8098 assertions, 0 failures', $output);
        self::assertSame(378, preg_match_all('/^ok [0-9]+ - /m', $output));
        self::assertStringNotContainsString("\nnot ok ", "\n" . $output);
    }
}
