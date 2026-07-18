<?php

declare(strict_types=1);

foreach (array(
    'ABSPATH' => __DIR__ . '/',
    'ARRAY_A' => 'ARRAY_A',
    'MINUTE_IN_SECONDS' => 60,
    'HOUR_IN_SECONDS' => 3600,
    'DAY_IN_SECONDS' => 86400,
    'YSAI_VERSION' => '1.0.0',
    'YSAI_PLUGIN_FILE' => dirname(__DIR__) . '/yassin-ai-assistant.php',
    'YSAI_PLUGIN_DIR' => dirname(__DIR__) . '/',
    'YSAI_PLUGIN_URL' => 'https://example.invalid/wp-content/plugins/yassin-ai-assistant/',
) as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
