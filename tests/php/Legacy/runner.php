<?php

declare(strict_types=1);

function ysai_run_legacy_suite(): int
{
    global $tests, $assertions;

    $failed = 0;
    foreach ($tests as $index => $test) {
        [$name, $fn] = $test;
        try { $fn(); echo 'ok ' . ($index + 1) . ' - ' . $name . PHP_EOL; }
        catch (Throwable $e) { ++$failed; echo 'not ok ' . ($index + 1) . ' - ' . $name . PHP_EOL; echo '  ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL; }
    }
    echo PHP_EOL . count($tests) . ' tests, ' . $assertions . ' assertions, ' . $failed . ' failures' . PHP_EOL;
    return $failed === 0 ? 0 : 1;
}
