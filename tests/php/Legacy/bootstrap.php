<?php

declare(strict_types=1);

if (!defined('YSAI_TEST_ROOT')) {
    define('YSAI_TEST_ROOT', dirname(__DIR__, 2));
}
if (!defined('YSAI_PROJECT_ROOT')) {
    define('YSAI_PROJECT_ROOT', dirname(YSAI_TEST_ROOT));
}

require_once YSAI_TEST_ROOT . '/bootstrap.php';
require_once __DIR__ . '/support.php';
