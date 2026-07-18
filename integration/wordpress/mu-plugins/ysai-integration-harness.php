<?php
/**
 * Plugin Name: YSAI Integration Harness
 * Description: Test-only controls for the containerized end-to-end suite.
 */

declare(strict_types=1);

if (!defined('YSAI_INTEGRATION_TEST_MODE') || YSAI_INTEGRATION_TEST_MODE !== true) {
    return;
}

const YSAI_INTEGRATION_FAULT_OPTION = 'ysai_integration_fault';
const YSAI_INTEGRATION_PRODUCTS_OPTION = 'ysai_integration_products';


// WordPress rejects private/reserved HTTP targets when reject_unsafe_urls is
// enabled. Permit only the fixed Docker-internal Gemini peer in explicit test
// mode; all other hosts retain WordPress's normal SSRF protection.
add_filter('http_request_host_is_external', static function ($external, $host, $url) {
    $candidate = (string) $url;
    $allowed = strpos($candidate, 'http://fake-gemini:8787/v1beta/models/') === 0
        || $candidate === 'http://fake-gemini:8787/control/state';
    if ((string) $host === 'fake-gemini' && $allowed) {
        return true;
    }
    return (bool) $external;
}, 10, 3);

/** @return string */
function ysai_integration_control_token()
{
    return defined('YSAI_INTEGRATION_CONTROL_TOKEN') && is_string(YSAI_INTEGRATION_CONTROL_TOKEN)
        ? YSAI_INTEGRATION_CONTROL_TOKEN
        : '';
}

/** @return bool */
function ysai_integration_authorized(WP_REST_Request $request)
{
    $expected = ysai_integration_control_token();
    $provided = (string) $request->get_header('x-ysai-test-token');
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

/** @return array<string,mixed> */
function ysai_integration_fault()
{
    $value = get_option(YSAI_INTEGRATION_FAULT_OPTION, array());
    return is_array($value) ? $value : array();
}

/** @param array<string,mixed> $fault */
function ysai_integration_set_fault(array $fault)
{
    update_option(YSAI_INTEGRATION_FAULT_OPTION, $fault, false);
}

function ysai_integration_clear_fault()
{
    delete_option(YSAI_INTEGRATION_FAULT_OPTION);
}

function ysai_integration_ensure_cart()
{
    if (!function_exists('WC') || !class_exists('WooCommerce')) {
        throw new RuntimeException('WooCommerce is unavailable in the integration harness.');
    }
    if (function_exists('wc_load_cart') && (!WC()->session || !WC()->cart)) {
        wc_load_cart();
    }
    if (!WC()->session || !WC()->cart) {
        throw new RuntimeException('WooCommerce cart session could not be initialized.');
    }
}

function ysai_integration_persist_cart()
{
    ysai_integration_ensure_cart();
    WC()->cart->calculate_totals();
    if (method_exists(WC()->cart, 'set_session')) {
        WC()->cart->set_session();
    }
    if (method_exists(WC()->session, 'save_data')) {
        WC()->session->save_data();
    }
}

/** @return string */
function ysai_integration_expired_session_token()
{
    // Expiry is rejected from the signed transport payload before active
    // browser-continuity authority is consulted. Keep this probe independent
    // from WooCommerce: assistant boot and renewal no longer hydrate a cart or
    // borrow identity from the shopper session.
    $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    if (strlen($nonce) !== 43 || preg_match('/^[A-Za-z0-9_-]{43}$/D', $nonce) !== 1) {
        throw new RuntimeException('Unable to create the expired session-token probe nonce.');
    }
    $issued = time() - 7200;
    $payload = wp_json_encode(array(
        'v' => 1,
        'iat' => $issued,
        'exp' => time() - 1,
        'site' => get_current_blog_id(),
        'nonce' => $nonce,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload) || $payload === '') {
        throw new RuntimeException('Unable to encode the expired assistant session token.');
    }
    $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    return $encoded . '.' . hash_hmac('sha256', $encoded, wp_salt('nonce'));
}

/** @return array<int,string> */
function ysai_integration_tables()
{
    $registry = \YassinStore\AiAssistant\Infrastructure\Database\SchemaRegistry::current();
    return array_reverse($registry->tableNames());
}

function ysai_integration_restore_products()
{
    $fixtures = get_option(YSAI_INTEGRATION_PRODUCTS_OPTION, array());
    if (!is_array($fixtures)) {
        return;
    }
    $ids = array();
    array_walk_recursive($fixtures, static function ($value) use (&$ids) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    });
    foreach ($ids as $productId) {
        if (!function_exists('wc_get_product')) {
            continue;
        }
        $product = wc_get_product($productId);
        if (!$product instanceof WC_Product) {
            continue;
        }
        if ((string) $product->get_status() !== 'publish') {
            $product->set_status('publish');
        }
        if ($product->is_type('simple') || $product->is_type('variation')) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($product->is_type('variation') ? 20 : 50);
            $product->set_stock_status('instock');
        }
        $product->save();
    }
    if (isset($fixtures['variable'])) {
        WC_Product_Variable::sync((int) $fixtures['variable']);
    }
}

function ysai_integration_reset_storage()
{
    global $wpdb;
    foreach (ysai_integration_tables() as $table) {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Unsafe integration table name.');
        }
        $result = $wpdb->query("TRUNCATE TABLE `{$table}`");
        if ($result === false || trim((string) $wpdb->last_error) !== '') {
            throw new RuntimeException('Unable to reset integration table ' . $table . ': ' . $wpdb->last_error);
        }
    }
}

/** @return array<string,mixed> */
function ysai_integration_cart_state()
{
    ysai_integration_ensure_cart();
    $items = array();
    foreach ((array) WC()->cart->get_cart() as $key => $item) {
        if (!is_array($item)) {
            continue;
        }
        $items[] = array(
            'key' => (string) $key,
            'product_id' => (int) ($item['product_id'] ?? 0),
            'variation_id' => (int) ($item['variation_id'] ?? 0),
            'quantity' => (float) ($item['quantity'] ?? 0),
            'test_custom' => (string) ($item['ysai_test_custom'] ?? ''),
        );
    }
    return array(
        'item_count' => (int) WC()->cart->get_cart_contents_count(),
        'line_count' => count($items),
        'items' => $items,
        'hash' => (string) WC()->cart->get_cart_hash(),
    );
}

/** @return array<string,mixed> */
function ysai_integration_database_state()
{
    global $wpdb;
    $tables = array(
        'conversations' => $wpdb->prefix . 'ysai_conversations',
        'messages' => $wpdb->prefix . 'ysai_messages',
        'turns' => $wpdb->prefix . 'ysai_turns',
        'operations' => $wpdb->prefix . 'ysai_operations',
        'leases' => $wpdb->prefix . 'ysai_leases',
    );
    $counts = array();
    foreach ($tables as $name => $table) {
        $counts[$name] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
    $operations = $wpdb->get_results(
        "SELECT public_id,turn_id,status,failure_code,lease_fence,applied_effects IS NOT NULL AS has_effects,receipt IS NOT NULL AS has_receipt,receipt"
        . " FROM `{$tables['operations']}` ORDER BY id ASC",
        ARRAY_A
    );
    $turns = $wpdb->get_results(
        "SELECT turn_id,status,failure_code,lease_fence,response_payload IS NOT NULL AS has_response"
        . " FROM `{$tables['turns']}` ORDER BY id ASC",
        ARRAY_A
    );
    $messageRows = $wpdb->get_results(
        "SELECT public_id,turn_id,role,outcome,content,payload FROM `{$tables['messages']}` ORDER BY id ASC",
        ARRAY_A
    );
    $messages = array();
    foreach (is_array($messageRows) ? $messageRows : array() as $messageRow) {
        $payload = isset($messageRow['payload']) && is_string($messageRow['payload'])
            ? json_decode($messageRow['payload'], true)
            : null;
        $payload = is_array($payload) ? $payload : array();
        $publicMessage = is_array($payload['message'] ?? null) ? $payload['message'] : array();
        $question = is_array($payload['model_question'] ?? null) ? $payload['model_question'] : array();
        $messages[] = array(
            'public_id' => (string) ($messageRow['public_id'] ?? ''),
            'turn_id' => (string) ($messageRow['turn_id'] ?? ''),
            'role' => (string) ($messageRow['role'] ?? ''),
            'outcome' => (string) ($messageRow['outcome'] ?? ''),
            'content' => (string) ($messageRow['content'] ?? ''),
            'public_text' => is_string($publicMessage['text'] ?? null) ? $publicMessage['text'] : '',
            'public_outcome' => is_string($publicMessage['outcome'] ?? null) ? $publicMessage['outcome'] : '',
            'public_contains_private_question' => array_key_exists('model_question', $publicMessage),
            'model_question' => $question === array() ? null : array(
                'text' => (string) ($question['text'] ?? ''),
                'model_step_id' => (string) ($question['model_step_id'] ?? ''),
                'tool_call_id' => (string) ($question['tool_call_id'] ?? ''),
                'provider_call_id' => (string) ($question['provider_call_id'] ?? ''),
                'client_turn_id' => (string) ($question['client_turn_id'] ?? ''),
                'conversation_id' => (string) ($question['conversation_id'] ?? ''),
                'purpose' => (string) ($question['purpose'] ?? ''),
                'accepted_at' => (int) ($question['accepted_at'] ?? 0),
            ),
        );
    }
    $conversation = $wpdb->get_row(
        "SELECT public_id,updated_at,expires_at FROM `{$tables['conversations']}` ORDER BY id DESC LIMIT 1",
        ARRAY_A
    );
    $stateJson = $wpdb->get_var(
        "SELECT state_json FROM `{$tables['conversations']}` ORDER BY id DESC LIMIT 1"
    );
    $durableState = is_string($stateJson) && $stateJson !== '' ? json_decode($stateJson, true) : null;
    $operationRows = is_array($operations) ? $operations : array();
    foreach ($operationRows as &$operationRow) {
        $receipt = isset($operationRow['receipt']) && is_string($operationRow['receipt'])
            ? json_decode($operationRow['receipt'], true)
            : null;
        $operationRow['receipt_message'] = is_array($receipt) && isset($receipt['message']) && is_string($receipt['message'])
            ? $receipt['message']
            : '';
        unset($operationRow['receipt']);
    }
    unset($operationRow);
    return array(
        'counts' => $counts,
        'operations' => $operationRows,
        'turns' => is_array($turns) ? $turns : array(),
        'messages' => $messages,
        'conversation' => is_array($conversation) ? $conversation : array(),
        'durable_state' => is_array($durableState) ? $durableState : array(),
    );
}

/** @return array<string,mixed> */
function ysai_integration_provider_state()
{
    $response = wp_remote_get('http://fake-gemini:8787/control/state', array(
        'timeout' => 5,
        'redirection' => 0,
        'headers' => array(
            'X-YSAI-Test-Token' => defined('YSAI_INTEGRATION_CONTROL_TOKEN')
                ? (string) YSAI_INTEGRATION_CONTROL_TOKEN
                : '',
        ),
    ));
    if (is_wp_error($response)) {
        throw new RuntimeException('Unable to inspect the deterministic provider state.');
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status !== 200 || !is_array($body) || !is_array($body['calls'] ?? null)) {
        throw new RuntimeException('Deterministic provider state is invalid.');
    }
    return array(
        'scenario' => (string) ($body['scenario'] ?? ''),
        'calls' => count($body['calls']),
    );
}

/** @return array<string,mixed> */
function ysai_integration_plugin_state()
{
    $pluginFile = defined('YSAI_PLUGIN_FILE') ? (string) YSAI_PLUGIN_FILE : '';
    $pluginRoot = defined('YSAI_PLUGIN_DIR') ? (string) YSAI_PLUGIN_DIR : '';
    $normalizedRoot = str_replace('\\', '/', $pluginRoot);
    return array(
        'version' => defined('YSAI_VERSION') ? (string) YSAI_VERSION : '',
        'plugin_file' => $pluginFile,
        'plugin_root' => $pluginRoot,
        'installed_under_wp_plugins' => $normalizedRoot !== ''
            && strpos($normalizedRoot, '/wp-content/plugins/yassin-ai-assistant/') !== false,
        'source_workspace_mount' => strpos($normalizedRoot, '/workspace/') !== false,
    );
}

add_action('rest_api_init', static function () {
    $permission = 'ysai_integration_authorized';
    register_rest_route('ysai-test/v1', '/reset', array(
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => static function () {
            ysai_integration_clear_fault();
            ysai_integration_restore_products();
            ysai_integration_ensure_cart();
            WC()->cart->empty_cart(true);
            ysai_integration_persist_cart();
            ysai_integration_reset_storage();
            return rest_ensure_response(array('ok' => true));
        },
    ));
    register_rest_route('ysai-test/v1', '/fault', array(
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => static function (WP_REST_Request $request) {
            $body = $request->get_json_params();
            $name = is_array($body) && isset($body['name']) && is_string($body['name'])
                ? trim($body['name'])
                : '';
            $allowed = array('reject_add', 'throw_add', 'delay_add_validation', 'terminate_after_add', 'lose_lease_after_add', 'diverge_after_add', 'change_quantity_after_add', 'mutate_metadata_after_quantity', 'mutate_metadata_each_calculation');
            if ($name !== '' && !in_array($name, $allowed, true)) {
                return new WP_Error('fault_invalid', 'Unknown integration fault.', array('status' => 400));
            }
            if ($name === '') {
                ysai_integration_clear_fault();
            } else {
                ysai_integration_set_fault(array('name' => $name, 'remaining' => 1));
            }
            return rest_ensure_response(array('ok' => true, 'fault' => $name));
        },
    ));
    register_rest_route('ysai-test/v1', '/state', array(
        'methods' => 'GET',
        'permission_callback' => $permission,
        'callback' => static function () {
            return rest_ensure_response(array(
                'ok' => true,
                'cart' => ysai_integration_cart_state(),
                'database' => ysai_integration_database_state(),
                'fault' => ysai_integration_fault(),
                'products' => get_option(YSAI_INTEGRATION_PRODUCTS_OPTION, array()),
                'plugin' => ysai_integration_plugin_state(),
                'provider' => ysai_integration_provider_state(),
            ));
        },
    ));
    register_rest_route('ysai-test/v1', '/product', array(
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => static function (WP_REST_Request $request) {
            $body = $request->get_json_params();
            $id = is_array($body) ? (int) ($body['id'] ?? 0) : 0;
            $action = is_array($body) ? (string) ($body['action'] ?? '') : '';
            $product = $id > 0 && function_exists('wc_get_product') ? wc_get_product($id) : null;
            if (!$product instanceof WC_Product) {
                return new WP_Error('product_missing', 'Integration product was not found.', array('status' => 404));
            }
            if ($action === 'out_of_stock') {
                $product->set_manage_stock(true);
                $product->set_stock_quantity(0);
                $product->set_stock_status('outofstock');
                $product->save();
            } elseif ($action === 'in_stock') {
                $product->set_manage_stock(true);
                $product->set_stock_quantity(50);
                $product->set_stock_status('instock');
                $product->save();
            } elseif ($action === 'delete') {
                $product->set_status('draft');
                $product->save();
            } else {
                return new WP_Error('product_action_invalid', 'Unknown product action.', array('status' => 400));
            }
            return rest_ensure_response(array('ok' => true, 'id' => $id, 'action' => $action));
        },
    ));
    register_rest_route('ysai-test/v1', '/cart/remove-first', array(
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => static function () {
            ysai_integration_ensure_cart();
            $cart = WC()->cart->get_cart();
            $key = is_array($cart) ? (string) array_key_first($cart) : '';
            if ($key !== '') {
                WC()->cart->remove_cart_item($key);
                ysai_integration_persist_cart();
            }
            return rest_ensure_response(array('ok' => true, 'removed' => $key !== ''));
        },
    ));
    register_rest_route('ysai-test/v1', '/conversation/expire', array(
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => static function (WP_REST_Request $request) {
            global $wpdb;
            $body = $request->get_json_params();
            $publicId = is_array($body) ? (string) ($body['conversation_id'] ?? '') : '';
            $table = $wpdb->prefix . 'ysai_conversations';
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET expires_at = %s WHERE public_id = %s",
                gmdate('Y-m-d H:i:s', time() - 60),
                $publicId
            ));
            return rest_ensure_response(array('ok' => true, 'updated' => (int) $updated));
        },
    ));
    register_rest_route('ysai-test/v1', '/session/expired-token', array(
        'methods' => 'GET',
        'permission_callback' => $permission,
        'callback' => static function () {
            return rest_ensure_response(array(
                'ok' => true,
                'token' => ysai_integration_expired_session_token(),
            ));
        },
    ));
});

add_filter('woocommerce_add_to_cart_validation', static function ($valid) {
    $fault = ysai_integration_fault();
    $name = (string) ($fault['name'] ?? '');
    if ((int) ($fault['remaining'] ?? 0) < 1 || !in_array($name, array('reject_add', 'throw_add', 'delay_add_validation'), true)) {
        return $valid;
    }
    ysai_integration_clear_fault();
    if ($name === 'delay_add_validation') {
        usleep(800000);
        return $valid;
    }
    if ($name === 'throw_add') {
        throw new RuntimeException('Injected WooCommerce add-to-cart exception.');
    }
    if (function_exists('wc_add_notice')) {
        wc_add_notice('Injected integration add-to-cart rejection.', 'error');
    }
    return false;
}, PHP_INT_MAX);


add_action('woocommerce_after_cart_item_quantity_update', static function ($cartItemKey, $quantity, $cart) {
    $fault = ysai_integration_fault();
    if ((string) ($fault['name'] ?? '') !== 'mutate_metadata_after_quantity'
        || (int) ($fault['remaining'] ?? 0) < 1
    ) {
        return;
    }
    ysai_integration_clear_fault();
    if (!$cart instanceof WC_Cart
        || !isset($cart->cart_contents[$cartItemKey])
        || !is_array($cart->cart_contents[$cartItemKey])
    ) {
        throw new RuntimeException('Injected quantity metadata target is unavailable.');
    }
    $cart->cart_contents[$cartItemKey]['ysai_test_custom'] = 'quantity-' . (string) $quantity;
}, PHP_INT_MAX, 3);


add_action('woocommerce_after_cart_item_quantity_update', static function ($cartItemKey, $quantity, $cart) {
    $fault = ysai_integration_fault();
    if ((string) ($fault['name'] ?? '') !== 'mutate_metadata_each_calculation'
        || (int) ($fault['remaining'] ?? 0) < 1
    ) {
        return;
    }
    if (!$cart instanceof WC_Cart
        || !isset($cart->cart_contents[$cartItemKey])
        || !is_array($cart->cart_contents[$cartItemKey])
    ) {
        throw new RuntimeException('Injected repeated-calculation metadata target is unavailable.');
    }
    $fault['target_key'] = (string) $cartItemKey;
    $fault['calculation_count'] = 0;
    ysai_integration_set_fault($fault);
}, PHP_INT_MAX, 3);

add_action('woocommerce_before_calculate_totals', static function ($cart) {
    $fault = ysai_integration_fault();
    $target = (string) ($fault['target_key'] ?? '');
    if ((string) ($fault['name'] ?? '') !== 'mutate_metadata_each_calculation'
        || (int) ($fault['remaining'] ?? 0) < 1
        || $target === ''
    ) {
        return;
    }
    if (!$cart instanceof WC_Cart
        || !isset($cart->cart_contents[$target])
        || !is_array($cart->cart_contents[$target])
    ) {
        throw new RuntimeException('Injected repeated-calculation cart target is unavailable.');
    }
    $count = (int) ($fault['calculation_count'] ?? 0) + 1;
    $cart->cart_contents[$target]['ysai_test_custom'] = 'calculation-' . (string) $count;
    $fault['calculation_count'] = $count;
    ysai_integration_set_fault($fault);
}, PHP_INT_MAX, 1);

add_action('woocommerce_add_to_cart', static function ($cartItemKey) {
    $fault = ysai_integration_fault();
    $name = (string) ($fault['name'] ?? '');
    if ((int) ($fault['remaining'] ?? 0) < 1
        || !in_array($name, array('terminate_after_add', 'lose_lease_after_add', 'diverge_after_add', 'change_quantity_after_add'), true)
    ) {
        return;
    }
    ysai_integration_clear_fault();

    if ($name === 'diverge_after_add') {
        ysai_integration_ensure_cart();
        if (isset(WC()->cart->cart_contents[$cartItemKey]) && is_array(WC()->cart->cart_contents[$cartItemKey])) {
            WC()->cart->cart_contents[$cartItemKey]['ysai_test_custom'] = 'injected-divergence';
        }
        ysai_integration_persist_cart();
        return;
    }
    if ($name === 'change_quantity_after_add') {
        ysai_integration_ensure_cart();
        WC()->cart->set_quantity((string) $cartItemKey, 2, false);
        return;
    }

    ysai_integration_persist_cart();
    if ($name === 'lose_lease_after_add') {
        global $wpdb;
        $table = $wpdb->prefix . 'ysai_leases';
        $wpdb->query(
            "UPDATE `{$table}` SET owner = REPEAT('f', 32), fence = fence + 1,"
            . " updated_at = UTC_TIMESTAMP() WHERE lease_until > UTC_TIMESTAMP()"
        );
        return;
    }

    status_header(503);
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(array(
        'ok' => false,
        'code' => 'injected_process_termination',
        'safe_message' => 'Injected termination after WooCommerce persistence and before durable effect evidence.',
        'retry_after' => 1,
    ));
    exit;
}, PHP_INT_MAX, 1);
