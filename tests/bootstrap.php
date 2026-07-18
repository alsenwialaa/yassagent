<?php

declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('DB_NAME')) { define('DB_NAME', 'wordpress'); }
if (!defined('YSAI_VERSION')) { define('YSAI_VERSION', '1.0.0'); }
if (!defined('YSAI_PLUGIN_DIR')) { define('YSAI_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR); }

$GLOBALS['ysai_test_options'] = array();
$GLOBALS['ysai_test_option_write_failures'] = array();
$GLOBALS['ysai_test_option_delete_failures'] = array();
$GLOBALS['ysai_test_option_writes'] = array();
$GLOBALS['ysai_test_blog_id'] = 1;
$GLOBALS['ysai_test_actions'] = array();
$GLOBALS['ysai_test_filters'] = array();
$GLOBALS['ysai_test_current_user_capabilities'] = array('manage_options' => true);
$GLOBALS['ysai_test_cache'] = array();
$GLOBALS['ysai_test_cache_option_reads'] = false;
$GLOBALS['ysai_test_cache_deletes'] = array();
$GLOBALS['ysai_test_scheduled_events'] = array();
$GLOBALS['ysai_test_clear_scheduled_results'] = array();
$GLOBALS['ysai_test_clear_scheduled_calls'] = array();

final class YsaiTestAdvisoryLockDatabase
{
    public $prefix = 'wp_';
    public $last_error = '';
    public function prepare(string $sql, ...$args): string { return $sql; }
    public function get_var(string $sql) { return '1'; }
}
$GLOBALS['wpdb'] = new YsaiTestAdvisoryLockDatabase();

if (!class_exists('WP_Error')) {
    class WP_Error {}
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $body;
        private $json;
        private $headers;
        public function __construct(string $body = '', $json = null, array $headers = array()) {
            $this->body = $body; $this->json = $json; $this->headers = $headers;
        }
        public function get_body(): string { return $this->body; }
        public function get_json_params() { return $this->json; }
        public function set_body($body): void { $this->body = (string) $body; }
        public function get_header(string $name): string { return (string) ($this->headers[$name] ?? ''); }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data; public $status; public $headers=array();
        public function __construct($data = null, int $status = 200) { $this->data = $data; $this->status = $status; }
        public function header(string $name,string $value): void { $this->headers[$name]=$value; }
    }
}

function wp_json_encode($value, int $flags = 0, int $depth = 512) { return json_encode($value, $flags, $depth); }
function wp_salt($scheme = 'auth') { return 'ysai-test-salt-' . (string) $scheme; }
function wp_generate_uuid4() {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex,0,8), substr($hex,8,4), substr($hex,12,4), substr($hex,16,4), substr($hex,20));
}
function wp_parse_url($url) { return parse_url((string) $url); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function sanitize_hex_color($value) { return preg_match('/^#[a-fA-F0-9]{6}$/', (string) $value) ? strtolower((string) $value) : false; }
function esc_url_raw($value) { return filter_var((string) $value, FILTER_VALIDATE_URL) ? (string) $value : ''; }
function get_bloginfo($show = '') { return $show === 'name' ? 'Yassin Test Store' : ''; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function get_woocommerce_currency() { return (string) ($GLOBALS['ysai_test_currency'] ?? 'USD'); }
function wc_price($amount, array $arguments = array()) {
    $GLOBALS['ysai_test_wc_price_calls'] = (int) ($GLOBALS['ysai_test_wc_price_calls'] ?? 0) + 1;
    if (array_key_exists('ysai_test_wc_price_html', $GLOBALS)) {
        return (string) $GLOBALS['ysai_test_wc_price_html'];
    }
    $number = rtrim(rtrim(number_format((float) $amount, 6, '.', ''), '0'), '.');
    $currency = (string) ($arguments['currency'] ?? get_woocommerce_currency());
    return '<span class="amount">' . ($number === '' ? '0' : $number)
        . ($currency !== '' ? ' ' . $currency : '') . '</span>';
}
function wc_get_price_to_display($product) { return $product->get_price(); }
function wc_tax_enabled() { return !empty($GLOBALS['ysai_test_wc_tax_enabled']); }
function wc_get_product_visibility_term_ids() { return $GLOBALS['ysai_test_visibility_terms'] ?? array(); }
function wc_get_product_id_by_sku($sku) { return (int) ($GLOBALS['ysai_test_sku_ids'][(string) $sku] ?? 0); }
function wc_get_related_products($productId, $limit) {
    return array_slice((array) ($GLOBALS['ysai_test_related_products'][(int) $productId] ?? array()), 0, (int) $limit);
}
function get_ancestors($objectId, $objectType = '', $resourceType = '') {
    return (array) ($GLOBALS['ysai_test_ancestors'][(int) $objectId] ?? array());
}
function get_term($termId, $taxonomy = '') { return $GLOBALS['ysai_test_terms'][(int) $termId] ?? null; }
function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1) {
    $GLOBALS['ysai_test_actions'][(string) $hook][] = array($callback, (int) $priority, (int) $acceptedArgs);
    return true;
}
function has_action($hook, $callback = false) {
    $entries = $GLOBALS['ysai_test_actions'][(string) $hook] ?? array();
    if ($callback === false) { return $entries === array() ? false : true; }
    foreach ($entries as $entry) {
        if ($entry[0] === $callback) { return (int) $entry[1]; }
    }
    return false;
}
function remove_action($hook, $callback, $priority = 10) {
    $hook = (string) $hook;
    $entries = $GLOBALS['ysai_test_actions'][$hook] ?? array();
    foreach ($entries as $index => $entry) {
        if ($entry[0] === $callback && (int) $entry[1] === (int) $priority) {
            unset($entries[$index]);
            $GLOBALS['ysai_test_actions'][$hook] = array_values($entries);
            return true;
        }
    }
    return false;
}
function do_action($hook, ...$args): void {
    $entries = $GLOBALS['ysai_test_actions'][(string) $hook] ?? array();
    usort($entries, static function (array $left, array $right): int {
        return $left[1] <=> $right[1];
    });
    foreach ($entries as $entry) {
        call_user_func_array($entry[0], array_slice($args, 0, max(0, (int) $entry[2])));
    }
}
function wp_next_scheduled($hook) {
    $events = $GLOBALS['ysai_test_scheduled_events'][(string) $hook] ?? array();
    if (!is_array($events) || $events === array()) { return false; }
    sort($events, SORT_NUMERIC);
    return (int) $events[0];
}
function wp_clear_scheduled_hook($hook, $args = array(), $wpError = false) {
    unset($args, $wpError);
    $hook = (string) $hook;
    $GLOBALS['ysai_test_clear_scheduled_calls'][] = $hook;
    if (array_key_exists($hook, $GLOBALS['ysai_test_clear_scheduled_results'])) {
        return $GLOBALS['ysai_test_clear_scheduled_results'][$hook];
    }
    $events = $GLOBALS['ysai_test_scheduled_events'][$hook] ?? array();
    $count = is_array($events) ? count($events) : 0;
    unset($GLOBALS['ysai_test_scheduled_events'][$hook]);
    return $count;
}
function add_shortcode($tag, $callback) { $GLOBALS['ysai_test_shortcodes'][(string) $tag] = $callback; return true; }
function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1) {
    $GLOBALS['ysai_test_filters'][(string) $hook][] = array($callback, (int) $priority, (int) $acceptedArgs);
    return true;
}
function apply_filters($hook, $value, ...$args) {
    $callbacks = $GLOBALS['ysai_test_filters'][(string) $hook] ?? array();
    usort($callbacks, static function (array $left, array $right): int {
        return $left[1] <=> $right[1];
    });
    foreach ($callbacks as $entry) {
        $arguments = array_merge(array($value), $args);
        $value = call_user_func_array($entry[0], array_slice($arguments, 0, max(1, $entry[2])));
    }
    return $value;
}
function get_option($key, $default = false) {
    if (!empty($GLOBALS['ysai_test_cache_option_reads'])) {
        $alloptions = $GLOBALS['ysai_test_cache']['options']['alloptions'] ?? array();
        if (is_array($alloptions) && array_key_exists($key, $alloptions)) {
            return $alloptions[$key];
        }
        if (array_key_exists($key, $GLOBALS['ysai_test_cache']['options'] ?? array())) {
            return $GLOBALS['ysai_test_cache']['options'][$key];
        }
        $notoptions = $GLOBALS['ysai_test_cache']['options']['notoptions'] ?? array();
        if (is_array($notoptions) && array_key_exists($key, $notoptions)) {
            return $default;
        }
    }
    return $GLOBALS['ysai_test_options'][$key] ?? $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['ysai_test_option_writes'][$key] = (int) ($GLOBALS['ysai_test_option_writes'][$key] ?? 0) + 1;
    if (!empty($GLOBALS['ysai_test_option_write_failures'][$key])) { return false; }
    $GLOBALS['ysai_test_options'][$key] = $value; return true;
}
function delete_option($key) {
    if (!empty($GLOBALS['ysai_test_option_delete_failures'][$key])) { return false; }
    unset($GLOBALS['ysai_test_options'][$key]); return true;
}
function wp_cache_delete($key, $group = '') {
    $GLOBALS['ysai_test_cache_deletes'][] = array((string) $group, (string) $key);
    unset($GLOBALS['ysai_test_cache'][(string) $group][(string) $key]);
    return true;
}
function wp_cache_get($key, $group = '') {
    return $GLOBALS['ysai_test_cache'][(string) $group][(string) $key] ?? false;
}
function wp_cache_set($key, $value, $group = '') {
    $GLOBALS['ysai_test_cache'][(string) $group][(string) $key] = $value;
    return true;
}
function __($text, $domain = null) { return $text; }
function add_settings_error($setting, $code, $message, $type = 'error') { return null; }
function current_user_can($capability) { return !empty($GLOBALS['ysai_test_current_user_capabilities'][(string) $capability]); }

function get_current_blog_id() { return (int) $GLOBALS['ysai_test_blog_id']; }
function wp_using_ext_object_cache() { return false; }
function wc_notice_count($noticeType = '') { return 0; }

require_once dirname(__DIR__) . '/src/Autoload.php';
\YassinStore\AiAssistant\Autoload::register();

/** @param object $target @return mixed */
function ysai_test_private_property($target, string $property)
{
    $reflection = new ReflectionProperty(get_class($target), $property);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    return $reflection->getValue($target);
}
