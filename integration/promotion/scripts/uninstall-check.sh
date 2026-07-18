#!/usr/bin/env sh
set -eu
. /promotion/scripts/common.sh

wait_for_wordpress_files
wait_for_database
$WP plugin is-active "$PLUGIN_SLUG" || fail 'The plugin must be active before uninstall verification.'

prefix="$($WP db prefix)"
$WP eval '
$tables = \\YassinStore\\AiAssistant\\Infrastructure\\Database\\SchemaRegistry::current()->tableNames();
echo implode("\n", $tables) . "\n";
' > /artifacts/uninstall-table-list.txt

seed_uninstall_evidence() {
  policy="$1"
  $WP eval '
$policy = (string) $args[0];
$settings = get_option("ysai_options", array());
if (!is_array($settings)) { $settings = array(); }
$settings["delete_data_on_uninstall"] = $policy === "delete" ? 1 : 0;
update_option("ysai_options", $settings, false);
update_option("ysai_runtime_readiness", array("promotion_fixture" => $policy), false);
update_option("_ysai_ingress_promotion_" . $policy, "1:1:" . time(), false);
' "$policy"
}

seed_uninstall_evidence retain
$WP plugin deactivate "$PLUGIN_SLUG"
$WP plugin uninstall "$PLUGIN_SLUG"

retained=0
while IFS= read -r table; do
  [ -n "$table" ] || continue
  found="$($WP db query "SHOW TABLES LIKE '${table}'" --skip-column-names 2>/dev/null || true)"
  [ "$found" = "$table" ] || fail "Retention uninstall removed assistant table unexpectedly: $table"
  retained=$((retained + 1))
done < /artifacts/uninstall-table-list.txt

$WP eval '
$sentinel = "__ysai_promotion_missing__";
$required = array("ysai_options", "ysai_schema_version", "ysai_schema_status", "ysai_recovery_key", "_ysai_ingress_promotion_retain");
$removed = array("ysai_runtime_readiness");
$present = array(); $missing = array();
foreach ($required as $option) {
    if (get_option($option, $sentinel) === $sentinel) { $missing[] = $option; } else { $present[] = $option; }
}
$unexpected = array();
foreach ($removed as $option) {
    if (get_option($option, $sentinel) !== $sentinel) { $unexpected[] = $option; }
}
if ($missing !== array() || $unexpected !== array()) {
    WP_CLI::error("Retention uninstall option mismatch: " . wp_json_encode(array("missing" => $missing, "unexpected" => $unexpected)));
}
echo wp_json_encode(array("ok" => true, "policy" => "retain", "required_options_present" => $present, "transient_options_removed" => $removed), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
' > /artifacts/uninstall-retain-options.json

python3 - "$retained" /artifacts/uninstall-retain-options.json /artifacts/uninstall-retain.json <<'PY'
import json, sys
retained = int(sys.argv[1])
options = json.load(open(sys.argv[2], encoding='utf-8'))
payload = {
    'ok': bool(options.get('ok')) and retained > 0,
    'policy': 'retain',
    'retained_tables': retained,
    'required_options_present': options.get('required_options_present', []),
    'transient_options_removed': options.get('transient_options_removed', []),
}
with open(sys.argv[3], 'w', encoding='utf-8') as stream:
    json.dump(payload, stream, indent=2, sort_keys=True); stream.write('\n')
PY

install_current_plugin
configure_plugin
seed_uninstall_evidence delete
$WP plugin deactivate "$PLUGIN_SLUG"
$WP plugin uninstall "$PLUGIN_SLUG"

remaining="$($WP db query "SHOW TABLES LIKE '${prefix}ysai_%'" --skip-column-names 2>/dev/null || true)"
[ -z "$remaining" ] || fail "Destructive uninstall left assistant tables: $remaining"

$WP eval '
$sentinel = "__ysai_promotion_missing__";
$owned = array("ysai_options", "ysai_schema_version", "ysai_schema_status", "ysai_runtime_readiness", "ysai_recovery_key");
$remaining = array();
foreach ($owned as $option) {
    if (get_option($option, $sentinel) !== $sentinel) { $remaining[] = $option; }
}
global $wpdb;
$ingress = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE LEFT(option_name, 14) = %s ORDER BY option_name ASC", "_ysai_ingress_"));
if (!is_array($ingress)) { WP_CLI::error("Unable to inspect ingress options after destructive uninstall."); }
if ($remaining !== array() || $ingress !== array()) {
    WP_CLI::error("Destructive uninstall left plugin options: " . wp_json_encode(array("options" => $remaining, "ingress" => $ingress)));
}
echo wp_json_encode(array("ok" => true, "policy" => "delete", "removed_options" => $owned, "remaining_options" => $remaining, "remaining_ingress_options" => $ingress), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
' > /artifacts/uninstall-delete-options.json

python3 - /artifacts/uninstall-delete-options.json /artifacts/uninstall-delete.json <<'PY'
import json, sys
options = json.load(open(sys.argv[1], encoding='utf-8'))
payload = {
    'ok': bool(options.get('ok')),
    'policy': 'delete',
    'remaining_tables': 0,
    'removed_options': options.get('removed_options', []),
    'remaining_options': options.get('remaining_options', []),
    'remaining_ingress_options': options.get('remaining_ingress_options', []),
}
with open(sys.argv[2], 'w', encoding='utf-8') as stream:
    json.dump(payload, stream, indent=2, sort_keys=True); stream.write('\n')
PY

if [ -f /var/www/html/wp-content/debug.log ]; then
  cp /var/www/html/wp-content/debug.log /artifacts/wordpress-debug-after-uninstall.log
else
  : > /artifacts/wordpress-debug-after-uninstall.log
fi
printf '%s\n' 'Both uninstall data-retention policies passed.'
