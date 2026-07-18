<?php

declare(strict_types=1);

require_once __DIR__ . '/php/Legacy/bootstrap.php';
$caseFiles = require __DIR__ . '/php/Legacy/manifest.php';
foreach ($caseFiles as $caseFile) {
    require $caseFile;
}
unset($caseFiles, $caseFile);
require_once __DIR__ . '/php/Legacy/runner.php';
exit(ysai_run_legacy_suite());
