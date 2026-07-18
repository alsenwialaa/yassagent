<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';
require_once $root . '/src/Autoload.php';

\YassinStore\AiAssistant\Autoload::register();

if (!defined('YSAI_TEST_PROJECT_ROOT')) {
    define('YSAI_TEST_PROJECT_ROOT', $root);
}
