<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Database\SchemaLifecycle;
use YassinStore\AiAssistant\Infrastructure\Database\SchemaRegistry;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCommerceCompatibility;

if (!defined('WP_CLI') || WP_CLI !== true) {
    throw new RuntimeException('This assertion must run through WP-CLI.');
}

$fail = static function (string $message): void {
    WP_CLI::error($message);
};

$phase = isset($args[0]) && is_string($args[0]) ? $args[0] : 'unknown';
if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $phase) !== 1) {
    $fail('Installed-tree evidence phase is invalid.');
}
if (!defined('YSAI_VERSION') || !is_string(YSAI_VERSION) || YSAI_VERSION === '') {
    $fail('YSAI_VERSION is unavailable after activation.');
}
if (!defined('YSAI_PLUGIN_DIR') || !is_string(YSAI_PLUGIN_DIR)) {
    $fail('YSAI_PLUGIN_DIR is unavailable after activation.');
}
$pluginRoot = realpath(YSAI_PLUGIN_DIR);
$expectedRoot = realpath(WP_PLUGIN_DIR . '/yassin-ai-assistant');
if (!is_string($pluginRoot) || !is_string($expectedRoot) || $pluginRoot !== $expectedRoot
    || strpos($pluginRoot, '/workspace/') !== false
) {
    $fail('WordPress is not executing the installed plugin directory.');
}

$manifestPath = '/artifacts/package-manifest.json';
$manifestContents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
$manifest = is_string($manifestContents) ? json_decode($manifestContents, true) : null;
$expectedMembers = is_array($manifest)
    && (int) ($manifest['manifest_version'] ?? 0) === 1
    && is_array($manifest['plugin'] ?? null)
    && is_array($manifest['plugin']['members'] ?? null)
        ? $manifest['plugin']['members']
        : null;
if (!is_array($expectedMembers) || $expectedMembers === array()) {
    $fail('The byte-level package manifest is missing or invalid.');
}
$installedMembers = array();
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
        continue;
    }
    $path = $file->getPathname();
    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($pluginRoot))), '/');
    if ($relative === '' || strpos($relative, '../') !== false) {
        $fail('The installed plugin contains an unsafe file path.');
    }
    $installedMembers[$relative] = array(
        'sha256' => hash_file('sha256', $path),
        'bytes' => $file->getSize(),
        'executable' => (bool) ($file->getPerms() & 0100),
    );
}
ksort($installedMembers, SORT_STRING);
ksort($expectedMembers, SORT_STRING);
if (array_keys($installedMembers) !== array_keys($expectedMembers)) {
    $missing = array_values(array_diff(array_keys($expectedMembers), array_keys($installedMembers)));
    $unexpected = array_values(array_diff(array_keys($installedMembers), array_keys($expectedMembers)));
    $fail('Installed plugin file set differs from the ZIP manifest. Missing: '
        . implode(', ', $missing) . '; unexpected: ' . implode(', ', $unexpected));
}
foreach ($expectedMembers as $relative => $expected) {
    $actual = $installedMembers[$relative] ?? null;
    if (!is_array($expected) || !is_array($actual)
        || !is_string($expected['sha256'] ?? null)
        || !is_string($actual['sha256'] ?? null)
        || !hash_equals($expected['sha256'], $actual['sha256'])
        || (int) ($expected['bytes'] ?? -1) !== (int) ($actual['bytes'] ?? -2)
        || (bool) ($expected['executable'] ?? false) !== (bool) ($actual['executable'] ?? false)
    ) {
        $fail('Installed plugin bytes or mode differ from the ZIP manifest: ' . $relative);
    }
}
$installedTree = array(
    'ok' => true,
    'root' => $pluginRoot,
    'files' => count($installedMembers),
    'members' => $installedMembers,
);
$installedTreeJson = wp_json_encode(
    $installedTree,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
if (!is_string($installedTreeJson)) {
    $fail('Unable to encode the installed plugin tree evidence.');
}
foreach (array(
    '/artifacts/installed-plugin-tree.json',
    '/artifacts/installed-plugin-tree-' . $phase . '.json',
) as $target) {
    if (file_put_contents($target, $installedTreeJson . PHP_EOL) === false) {
        $fail('Unable to write the installed plugin tree evidence: ' . $target);
    }
}

if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if (!is_plugin_active('yassin-ai-assistant/yassin-ai-assistant.php')) {
    $fail('The packaged plugin is not active.');
}
if (!class_exists('WooCommerce') || !defined('WC_VERSION')) {
    $fail('WooCommerce is unavailable after activation.');
}
$compatibility = WooCommerceCompatibility::fromPluginContract();
$compatibilityStatus = $compatibility->statusForInstalledVersion();
if ($compatibilityStatus !== WooCommerceCompatibility::PROMOTION_TESTED
    || !$compatibility->isInstalledVersionPromotionTested()
) {
    $fail('Installed WooCommerce is not authorized by the promotion-tested contract.');
}
$manifestCompatibility = is_array($manifest['plugin']['woocommerce_compatibility'] ?? null)
    ? $manifest['plugin']['woocommerce_compatibility']
    : null;
$expectedCompatibility = array(
    'schema_version' => 1,
    'minimum' => $compatibility->minimum(),
    'maximum_exclusive' => $compatibility->maximumExclusive(),
    'tested_up_to' => $compatibility->testedUpTo(),
    'promotion_tested' => $compatibility->promotionTestedVersions(),
    'wordpress_minimum' => $compatibility->wordpressMinimum(),
    'runtime_contract' => $compatibility->runtimeContract(),
);
if (!is_array($manifestCompatibility) || $manifestCompatibility !== $expectedCompatibility) {
    $fail('Installed runtime compatibility policy differs from the verified plugin package manifest.');
}
if (!SchemaLifecycle::verifyRuntime() || !SchemaLifecycle::isReady()) {
    $fail('The current assistant schema is not runtime-ready.');
}

$defaults = Settings::defaults();
$runtimeSettings = (new Settings())->all();
if (array_key_exists('welcome_message', $defaults)
    || array_key_exists('welcome_message', $runtimeSettings)
) {
    $fail('A server-authored welcome message remains in runtime settings.');
}

$routes = rest_get_server()->get_routes();
foreach (array('/yassin-ai/v1/boot', '/yassin-ai/v1/chat', '/yassin-ai/v1/health') as $route) {
    if (!isset($routes[$route])) {
        $fail('Required REST route is missing: ' . $route);
    }
}

global $wpdb;
$tables = SchemaRegistry::current()->tableNames();
foreach ($tables as $table) {
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if (!is_string($found) || $found !== $table || trim((string) $wpdb->last_error) !== '') {
        $fail('Required assistant table is missing: ' . $table);
    }
}

$result = array(
    'ok' => true,
    'plugin_version' => YSAI_VERSION,
    'plugin_root' => $pluginRoot,
    'wordpress_version' => get_bloginfo('version'),
    'woocommerce_version' => WC_VERSION,
    'woocommerce_compatibility_status' => $compatibilityStatus,
    'woocommerce_promotion_tested' => $compatibility->promotionTestedVersions(),
    'woocommerce_runtime_contract' => $compatibility->runtimeContract(),
    'php_version' => PHP_VERSION,
    'schema_version' => SchemaLifecycle::SCHEMA_VERSION,
    'schema_status' => SchemaLifecycle::status(),
    'tables' => array_values($tables),
    'routes' => array('/yassin-ai/v1/boot', '/yassin-ai/v1/chat', '/yassin-ai/v1/health'),
    'welcome_message_present' => false,
    'installed_files_verified' => count($installedMembers),
    'evidence_phase' => $phase,
);

echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
