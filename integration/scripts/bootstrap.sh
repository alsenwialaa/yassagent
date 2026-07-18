#!/usr/bin/env sh
set -eu

WP='wp --allow-root --path=/var/www/html'

attempt=0
while [ ! -f /var/www/html/wp-load.php ]; do
  attempt=$((attempt + 1))
  if [ "$attempt" -gt 90 ]; then
    echo 'WordPress files were not initialized.' >&2
    exit 1
  fi
  sleep 2
done

attempt=0
until $WP db check >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -gt 90 ]; then
    echo 'Database did not become ready.' >&2
    exit 1
  fi
  sleep 2
done

if ! $WP core is-installed >/dev/null 2>&1; then
  $WP core install \
    --url=http://wordpress \
    --title='YSAI Integration Store' \
    --admin_user=admin \
    --admin_password=integration-password \
    --admin_email=integration@example.test \
    --skip-email
fi

$WP option update home 'http://wordpress'
$WP option update siteurl 'http://wordpress'
$WP option update permalink_structure '/%postname%/'
$WP plugin install woocommerce --version=10.9.4 --activate --force
$WP plugin activate yassin-ai-assistant
$WP option update woocommerce_currency 'USD'
$WP option update woocommerce_store_address 'Integration Street'
$WP option update woocommerce_store_city 'Test City'
$WP option update woocommerce_default_country 'US:CA'
$WP option update woocommerce_calc_taxes 'no'
$WP option update ysai_options '{"enabled":1,"http_timeout_seconds":10,"max_tool_rounds":6,"gemini_thinking_level":"low","max_output_tokens":2048,"widget_enabled":1,"widget_auto_insert":1,"allow_images":1,"rate_limit_turns":500,"rate_window_seconds":60,"daily_ai_turn_limit":100000,"diagnostic_logging":1,"widget_title":"Integration Sales Agent","widget_subtitle":"Real WordPress and WooCommerce","widget_button_text":"Open integration assistant","empty_state_hint":"Integration assistant ready"}' --format=json
$WP eval-file /workspace/plugin/integration/wordpress/seed.php
$WP rewrite flush --hard
$WP cache flush
$WP cron event run --due-now >/dev/null 2>&1 || true

# Prove and persist the two-request provider runtime check through the same
# authenticated REST controller used by the production admin screen. The deep
# shopping, cart, clarification, and recovery contracts remain separate tests.
$WP --user=admin eval '
$request = new WP_REST_Request("POST", "/yassin-ai/v1/admin/test");
$request->set_header("Content-Type", "application/json");
$request->set_body("{}");
$response = rest_do_request($request);
$status = $response->get_status();
$data = $response->get_data();
if ($status !== 200
    || !is_array($data)
    || ($data["ok"] ?? false) !== true
    || !isset($data["result"])
    || !is_array($data["result"])
    || ($data["result"]["reply"] ?? "") !== "جاهز"
    || ($data["result"]["provider_requests"] ?? 0) !== 2
    || ($data["result"]["checks"]["provider_access"] ?? "") !== "passed"
    || ($data["result"]["checks"]["structured_tool"] ?? "") !== "passed"
) {
    WP_CLI::error(
        "YSAI two-request provider runtime check failed: HTTP "
        . (string) $status . " " . wp_json_encode($data)
    );
}
WP_CLI::log("YSAI two-request provider runtime readiness verified.");
'

echo 'YSAI integration environment initialized.'
