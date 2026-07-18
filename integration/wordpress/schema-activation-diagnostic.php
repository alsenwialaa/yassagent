<?php

declare(strict_types=1);

global $wpdb;

$ownedPrefix = (string) $wpdb->prefix . 'ysai_';
$like = $wpdb->esc_like($ownedPrefix) . '%';
$tableNames = $wpdb->get_col(
    $wpdb->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s'
        . ' ORDER BY TABLE_NAME',
        $like
    )
);

$tables = array();
foreach (is_array($tableNames) ? $tableNames : array() as $tableName) {
    if (!is_string($tableName) || strpos($tableName, $ownedPrefix) !== 0) {
        continue;
    }
    if (preg_match('/^[A-Za-z0-9_]+$/D', $tableName) !== 1) {
        continue;
    }
    $row = $wpdb->get_row('SHOW CREATE TABLE `' . $tableName . '`', ARRAY_N);
    $tables[$tableName] = is_array($row) && isset($row[1]) ? (string) $row[1] : '';
}

$payload = array(
    'wordpress_version' => get_bloginfo('version'),
    'php_version' => PHP_VERSION,
    'database_version' => method_exists($wpdb, 'db_version') ? $wpdb->db_version() : '',
    'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : '',
    'schema_version' => get_option('ysai_schema_version', null),
    'schema_status' => get_option('ysai_schema_status', null),
    'owned_tables' => $tables,
    'diagnostic_query_error' => trim((string) $wpdb->last_error),
);

echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
