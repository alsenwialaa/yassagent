<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class SourceArchitectureTest extends TestCase
{
    /** @dataProvider architectureCommands */
    public function testArchitectureCommandPasses(string $script, string $summary): void
    {
        $command = 'python3 ' . escapeshellarg(YSAI_TEST_PROJECT_ROOT . '/' . $script) . ' 2>&1';
        $lines = array();
        $status = 0;
        exec($command, $lines, $status);
        $output = implode("\n", $lines);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString($summary, $output);
    }

    /** @return array<string,array{string,string}> */
    public function architectureCommands(): array
    {
        return array(
            'layer ownership' => array(
                'scripts/quality/verify-architecture.py',
                'Architecture verified:',
            ),
            'dead and duplicate code' => array(
                'scripts/quality/verify-code-health.py',
                'Code health verified',
            ),
            'static debt ledgers' => array(
                'scripts/quality/verify-static-baselines.py',
                'Static baselines verified:',
            ),
            'development metadata' => array(
                'scripts/quality/verify-development-metadata.py',
                'Development metadata verified:',
            ),
            'composer lock installation' => array(
                'scripts/quality/verify-composer-install.py',
                'Installed Composer metadata matches the lock:',
            ),
            'node lock installation' => array(
                'scripts/quality/verify-node-install.py',
                'Installed Node metadata matches the lock:',
            ),
            'release hardening authority' => array(
                'scripts/quality/verify-release-hardening.py',
                'Release hardening verified:',
            ),
            'release hardening self-test' => array(
                'scripts/quality/self-test-release-hardening.py',
                'Stage H self-test passed:',
            ),
            'release archive self-test' => array(
                'scripts/quality/self-test-release-archives.py',
                'Stage H archive self-test passed:',
            ),
            'node installation self-test' => array(
                'scripts/quality/self-test-node-install.py',
                'Node install self-test passed:',
            ),
            'stage-h runner self-test' => array(
                'scripts/quality/self-test-stage-h-runner.py',
                'Stage H runner self-test passed:',
            ),
        );
    }
}
