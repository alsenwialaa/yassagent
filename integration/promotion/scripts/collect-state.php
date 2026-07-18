<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaRegistry;

if (!defined('WP_CLI') || WP_CLI !== true) {
    throw new RuntimeException('Collection must run through WP-CLI.');
}

$phase = isset($args[0]) && is_string($args[0]) ? sanitize_key($args[0]) : 'main';
if ($phase === '') {
    $phase = 'main';
}
$artifactRoot = '/artifacts';
if (!is_dir($artifactRoot) || !is_writable($artifactRoot)) {
    WP_CLI::error('Promotion artifact directory is not writable.');
}

$writeJson = static function (string $name, array $payload) use ($artifactRoot): void {
    $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || file_put_contents($artifactRoot . '/' . $name, $json . PHP_EOL) === false) {
        WP_CLI::error('Unable to write promotion artifact: ' . $name);
    }
};

if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

global $wpdb;
$environment = array(
    'phase' => $phase,
    'wordpress_version' => get_bloginfo('version'),
    'php_version' => PHP_VERSION,
    'database_server' => method_exists($wpdb, 'db_version') ? $wpdb->db_version() : '',
    'database_client' => function_exists('mysqli_get_client_info') ? mysqli_get_client_info() : '',
    'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : '',
    'plugin_version' => defined('YSAI_VERSION') ? YSAI_VERSION : '',
    'plugin_active' => is_plugin_active('yassin-ai-assistant/yassin-ai-assistant.php'),
    'plugin_root' => defined('YSAI_PLUGIN_DIR') ? realpath(YSAI_PLUGIN_DIR) : false,
    'schema_version' => SchemaLifecycle::SCHEMA_VERSION,
    'installed_schema_version' => get_option(SchemaLifecycle::SCHEMA_OPTION, ''),
    'schema_status' => get_option(SchemaLifecycle::SCHEMA_STATUS_OPTION, array()),
    'site_url' => site_url(),
    'home_url' => home_url(),
    'multisite' => is_multisite(),
    'hpos_enabled' => class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
        ? \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        : null,
    'captured_at' => gmdate('c'),
);
$writeJson('environment-wordpress-' . $phase . '.json', $environment);
if ($phase === 'main') {
    $writeJson('environment-wordpress.json', $environment);
}

$schema = array(
    'phase' => $phase,
    'schema_version' => SchemaLifecycle::SCHEMA_VERSION,
    'installed_version' => get_option(SchemaLifecycle::SCHEMA_OPTION, ''),
    'tables' => array(),
);
foreach (SchemaRegistry::current()->tableNames() as $table) {
    $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
    $columns = $wpdb->get_results("SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A);
    $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
    if (trim((string) $wpdb->last_error) !== '') {
        WP_CLI::error('Unable to inspect assistant table: ' . $table . ' ' . $wpdb->last_error);
    }
    $schema['tables'][$table] = array(
        'create_sql' => is_array($create) ? (string) ($create[1] ?? '') : '',
        'columns' => is_array($columns) ? $columns : array(),
        'indexes' => is_array($indexes) ? $indexes : array(),
        'row_count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"),
    );
}
$writeJson('database-schema-' . $phase . '.json', $schema);
if ($phase === 'main') {
    $writeJson('database-schema.json', $schema);
}

$wooStatus = array(
    'phase' => $phase,
    'version' => defined('WC_VERSION') ? WC_VERSION : '',
    'active' => is_plugin_active('woocommerce/woocommerce.php'),
    'session_handler' => function_exists('WC') && WC()->session ? get_class(WC()->session) : '',
    'cart_class' => function_exists('WC') && WC()->cart ? get_class(WC()->cart) : '',
    'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
    'uploads_directory' => function_exists('wp_upload_dir') ? wp_upload_dir()['basedir'] : '',
    'critical_entries' => array(),
    'log_files' => array(),
);
$logRoot = trailingslashit(wp_upload_dir()['basedir']) . 'wc-logs';
if (is_dir($logRoot)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($logRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $relative = ltrim(str_replace($logRoot, '', $path), '/\\');
        $wooStatus['log_files'][] = array('path' => $relative, 'bytes' => $file->getSize());
        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            continue;
        }
        foreach (preg_split('/\R/u', $contents) ?: array() as $line) {
            if (preg_match('/\b(EMERGENCY|ALERT|CRITICAL)\b/i', $line) === 1) {
                $wooStatus['critical_entries'][] = array(
                    'file' => $relative,
                    'line' => function_exists('mb_substr')
                        ? mb_substr($line, 0, 2000, 'UTF-8')
                        : substr($line, 0, 2000),
                );
            }
        }
    }
}
$writeJson('woocommerce-status-' . $phase . '.json', $wooStatus);
$writeJson('woocommerce-critical-logs-' . $phase . '.json', array(
    'phase' => $phase,
    'entries' => $wooStatus['critical_entries'],
));
if ($phase === 'main') {
    $writeJson('woocommerce-status.json', $wooStatus);
    $writeJson('woocommerce-critical-logs.json', array(
        'phase' => $phase,
        'entries' => $wooStatus['critical_entries'],
    ));
}

$debugLog = WP_CONTENT_DIR . '/debug.log';
$debugContents = is_file($debugLog) ? file_get_contents($debugLog) : '';
if (!is_string($debugContents)) {
    $debugContents = '';
}
$debugTarget = $artifactRoot . '/wordpress-debug-' . $phase . '.log';
if (file_put_contents($debugTarget, $debugContents) === false) {
    WP_CLI::error('Unable to copy WordPress debug log.');
}
if ($phase === 'main' && file_put_contents($artifactRoot . '/wordpress-debug.log', $debugContents) === false) {
    WP_CLI::error('Unable to create canonical WordPress debug log artifact.');
}

echo wp_json_encode(array(
    'ok' => true,
    'phase' => $phase,
    'environment' => 'environment-wordpress-' . $phase . '.json',
    'schema' => 'database-schema-' . $phase . '.json',
    'woocommerce' => 'woocommerce-status-' . $phase . '.json',
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
