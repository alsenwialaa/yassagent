#!/usr/bin/env sh
set -eu
. /promotion/scripts/common.sh

wait_for_wordpress_files
wait_for_database
install_wordpress_core
install_woocommerce
configure_store

[ -f "$LEGACY_ZIP" ] || fail 'The deterministic legacy fixture ZIP is missing.'
$WP plugin install "$LEGACY_ZIP" --activate --force
legacy_version="$($WP plugin get "$PLUGIN_SLUG" --field=version)"
[ "$legacy_version" = '0.9.0-internal' ] || fail "Legacy fixture version mismatch: $legacy_version"
$WP plugin is-active "$PLUGIN_SLUG" || fail 'Legacy fixture did not activate.'

$WP eval '
global $wpdb;
$table = $wpdb->prefix . "ysai_conversations";
$row = $wpdb->get_row("SELECT public_id,state_json FROM `{$table}` LIMIT 1", ARRAY_A);
$fixture = get_option("ysai_promotion_legacy_fixture", array());
if (!is_array($row) || ($row["public_id"] ?? "") !== "00000000-0000-4000-8000-000000000001"
    || !is_array($fixture) || ($fixture["version"] ?? "") !== "0.9.0-internal") {
    WP_CLI::error("Legacy upgrade fixture was not seeded correctly.");
}
echo wp_json_encode(array("ok" => true, "legacy_row" => $row, "fixture" => $fixture), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
' > /artifacts/upgrade-before.json

$WP plugin deactivate "$PLUGIN_SLUG"
$WP plugin install "$PLUGIN_ZIP" --force
$WP plugin activate "$PLUGIN_SLUG"
actual="$($WP plugin get "$PLUGIN_SLUG" --field=version)"
[ "$actual" = "$PLUGIN_VERSION" ] || fail "Updated plugin version mismatch: expected $PLUGIN_VERSION, got $actual"

configure_plugin
$WP eval-file /promotion/seed.php
flush_runtime
prove_provider_readiness
$WP eval-file /promotion/scripts/readiness-hardening.php > /artifacts/upgrade-readiness-hardening.json
$WP eval-file /promotion/scripts/assert-current-install.php upgrade > /artifacts/upgrade-install.json
$WP eval-file /promotion/scripts/boot-probe.php > /artifacts/upgrade-boot.json

$WP eval '
global $wpdb;
$table = $wpdb->prefix . "ysai_conversations";
$legacy = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE public_id = %s", "00000000-0000-4000-8000-000000000001"));
$state = $wpdb->get_var("SELECT state_json FROM `{$table}` ORDER BY id DESC LIMIT 1");
if ((int) $legacy !== 0) {
    WP_CLI::error("Legacy conversation authority survived the schema upgrade.");
}
if (is_string($state) && strpos($state, "سؤال قديم يجب ألا ينجو من الترقية؟") !== false) {
    WP_CLI::error("Legacy pending-question text survived the schema upgrade.");
}
$settings = (new \\YassinStore\\AiAssistant\\Infrastructure\\WordPress\\Settings())->all();
if (array_key_exists("welcome_message", $settings)) {
    WP_CLI::error("Legacy server-authored welcome text entered current runtime settings.");
}
echo wp_json_encode(array(
    "ok" => true,
    "legacy_conversation_rows" => (int) $legacy,
    "legacy_pending_question_present" => false,
    "runtime_welcome_message_present" => false,
    "installed_schema_version" => get_option("ysai_schema_version", ""),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
' > /artifacts/upgrade-result.json

$WP eval-file /promotion/scripts/collect-state.php upgrade > /artifacts/collection-upgrade.json
printf '%s\n' 'Packaged plugin upgrade and stale-authority invalidation passed.'
