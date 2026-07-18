#!/usr/bin/env sh
set -eu

WP='wp --allow-root --path=/var/www/html'
PLUGIN_SLUG='yassin-ai-assistant'
PLUGIN_ZIP='/package/yassin-ai-assistant.zip'
WOO_ZIP='/package/woocommerce.zip'
LEGACY_ZIP='/package/yassin-ai-assistant-legacy.zip'
PLUGIN_VERSION="${YSAI_PROMOTION_PLUGIN_VERSION:-1.0.0}"
PLUGIN_SHA256="${YSAI_PROMOTION_PLUGIN_SHA256:-}"
WOO_VERSION="${YSAI_PROMOTION_WOOCOMMERCE_VERSION:-10.9.4}"

fail() { printf '%s\n' "$1" >&2; exit 1; }

wait_for_wordpress_files() {
  attempt=0
  while [ ! -f /var/www/html/wp-load.php ]; do
    attempt=$((attempt + 1)); [ "$attempt" -le 120 ] || fail 'WordPress files were not initialized.'; sleep 2
  done
}

wait_for_database() {
  attempt=0
  until $WP db check >/dev/null 2>&1; do
    attempt=$((attempt + 1)); [ "$attempt" -le 120 ] || fail 'Database did not become ready.'; sleep 2
  done
}

install_wordpress_core() {
  if ! $WP core is-installed >/dev/null 2>&1; then
    $WP core install --url=http://wordpress --title='YSAI Promotion Store' \
      --admin_user=admin --admin_password=promotion-password \
      --admin_email=promotion@example.test --skip-email
  fi
  $WP option update home 'http://wordpress'
  $WP option update siteurl 'http://wordpress'
  $WP option update permalink_structure '/%postname%/'
}

install_woocommerce() {
  [ -f "$WOO_ZIP" ] || fail 'The staged exact WooCommerce ZIP is missing.'
  $WP plugin install "$WOO_ZIP" --activate --force
  actual="$($WP plugin get woocommerce --field=version)"
  [ "$actual" = "$WOO_VERSION" ] || fail "WooCommerce version mismatch: expected $WOO_VERSION, got $actual"
  $WP plugin is-active woocommerce || fail 'WooCommerce is not active.'
}

configure_store() {
  $WP option update woocommerce_currency 'USD'
  $WP option update woocommerce_store_address 'Promotion Street'
  $WP option update woocommerce_store_city 'Test City'
  $WP option update woocommerce_default_country 'US:CA'
  $WP option update woocommerce_calc_taxes 'no'
}

configure_plugin() {
  $WP option update ysai_options '{"enabled":1,"http_timeout_seconds":10,"max_tool_rounds":6,"gemini_thinking_level":"low","max_output_tokens":2048,"widget_enabled":1,"widget_auto_insert":1,"allow_images":1,"rate_limit_turns":500,"rate_window_seconds":60,"daily_ai_turn_limit":100000,"diagnostic_logging":1,"delete_data_on_uninstall":0,"widget_title":"Promotion Sales Agent","widget_subtitle":"Packaged WordPress and WooCommerce","widget_button_text":"Open promotion assistant","empty_state_hint":"Promotion assistant ready"}' --format=json
}

install_current_plugin() {
  [ -f "$PLUGIN_ZIP" ] || fail 'The staged plugin ZIP is missing.'
  $WP plugin install "$PLUGIN_ZIP" --force
  $WP plugin activate "$PLUGIN_SLUG"
  actual="$($WP plugin get "$PLUGIN_SLUG" --field=version)"
  [ "$actual" = "$PLUGIN_VERSION" ] || fail "Plugin version mismatch: expected $PLUGIN_VERSION, got $actual"
  $WP plugin is-active "$PLUGIN_SLUG" || fail 'The packaged plugin is not active.'
}

prove_provider_readiness() {
  $WP --user=admin eval '
$request = new WP_REST_Request("POST", "/yassin-ai/v1/admin/test");
$request->set_header("Content-Type", "application/json");
$request->set_body("{}");
$response = rest_do_request($request);
$status = $response->get_status();
$data = $response->get_data();
if ($status !== 200 || !is_array($data) || ($data["ok"] ?? false) !== true
    || !isset($data["result"]) || !is_array($data["result"])
    || ($data["result"]["reply"] ?? "") !== "جاهز"
    || (int) ($data["result"]["provider_requests"] ?? 0) !== 2
    || ($data["result"]["checks"]["provider_access"] ?? "") !== "passed"
    || ($data["result"]["checks"]["structured_tool"] ?? "") !== "passed") {
    WP_CLI::error("Provider readiness failed: HTTP " . (string) $status . " " . wp_json_encode($data));
}
WP_CLI::log("Two-request provider runtime readiness verified.");
'
}

flush_runtime() {
  $WP rewrite flush --hard
  $WP cache flush
  $WP cron event run --due-now >/dev/null 2>&1 || true
}
