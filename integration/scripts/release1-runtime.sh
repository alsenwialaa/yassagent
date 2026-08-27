#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE="docker compose -f ${ROOT}/integration/docker-compose.yml"
ARTIFACTS="${ROOT}/integration/artifacts/release1"
mkdir -p "${ARTIFACTS}"

cleanup() {
  ${COMPOSE} down -v --remove-orphans >"${ARTIFACTS}/compose-down.log" 2>&1 || true
}
trap cleanup EXIT

${COMPOSE} down -v --remove-orphans >/dev/null 2>&1 || true
${COMPOSE} up -d db fake-gemini wordpress >"${ARTIFACTS}/compose-up.log" 2>&1

for attempt in $(seq 1 90); do
  if ${COMPOSE} run --rm wpcli core is-installed --allow-root >/dev/null 2>&1; then
    break
  fi
  if ${COMPOSE} run --rm wpcli core install \
      --url=http://localhost:8080 \
      --title='YSAI Release 1 Runtime' \
      --admin_user=admin \
      --admin_password='runtime-admin-password' \
      --admin_email=runtime@example.test \
      --skip-email --allow-root >"${ARTIFACTS}/wp-install.log" 2>&1; then
    break
  fi
  sleep 2
done

${COMPOSE} run --rm wpcli core is-installed --allow-root
${COMPOSE} run --rm wpcli plugin install woocommerce --version=10.9.4 --activate --force --allow-root >"${ARTIFACTS}/woocommerce-install.log" 2>&1
${COMPOSE} run --rm wpcli plugin activate yassin-ai-assistant --allow-root >"${ARTIFACTS}/plugin-activate-first.log" 2>&1

${COMPOSE} run --rm wpcli eval '
$required = array("focus","pending_questions","journeys","sales_context","interaction_events","agent_policy_versions","knowledge_sources","audit");
global $wpdb;
foreach ($required as $suffix) {
    $table = $wpdb->prefix . "ysr1_" . $suffix;
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table)));
    if (!is_string($found) || $found !== $table) {
        throw new RuntimeException("Missing canonical Release 1 table: " . $table);
    }
}
if ((int) get_option("ysai_release1_runtime_schema", 0) !== 1 && (int) get_option("ysai_release1_schema_version", 0) < 1) {
    throw new RuntimeException("Release 1 schema version was not published.");
}
echo "fresh activation tables verified\n";
' --allow-root >"${ARTIFACTS}/fresh-activation-probe.log" 2>&1

# Recreate the early candidate namespace, then prove the next activation migrates
# the entire set atomically before the core exact ysai_* schema authority runs.
${COMPOSE} run --rm wpcli plugin deactivate yassin-ai-assistant --allow-root >"${ARTIFACTS}/plugin-deactivate.log" 2>&1
${COMPOSE} run --rm wpcli eval '
$required = array("focus","pending_questions","journeys","sales_context","interaction_events","agent_policy_versions","knowledge_sources","audit");
global $wpdb;
$clauses = array();
foreach ($required as $suffix) {
    $new = $wpdb->prefix . "ysr1_" . $suffix;
    $old = $wpdb->prefix . "ysai_r1_" . $suffix;
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($new)));
    if (is_string($found) && $found === $new) {
        $clauses[] = "`" . $new . "` TO `" . $old . "`";
    }
}
if (count($clauses) !== count($required)) {
    throw new RuntimeException("Could not prepare complete legacy namespace fixture.");
}
if ($wpdb->query("RENAME TABLE " . implode(", ", $clauses)) === false) {
    throw new RuntimeException($wpdb->last_error);
}
echo "legacy namespace fixture created\n";
' --allow-root >"${ARTIFACTS}/legacy-fixture.log" 2>&1

${COMPOSE} run --rm wpcli plugin activate yassin-ai-assistant --allow-root >"${ARTIFACTS}/plugin-reactivate-migration.log" 2>&1
${COMPOSE} run --rm wpcli eval '
$required = array("focus","pending_questions","journeys","sales_context","interaction_events","agent_policy_versions","knowledge_sources","audit");
global $wpdb;
foreach ($required as $suffix) {
    $old = $wpdb->prefix . "ysai_r1_" . $suffix;
    $new = $wpdb->prefix . "ysr1_" . $suffix;
    $oldFound = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($old)));
    $newFound = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($new)));
    if (is_string($oldFound) && $oldFound === $old) {
        throw new RuntimeException("Legacy table remains: " . $old);
    }
    if (!is_string($newFound) || $newFound !== $new) {
        throw new RuntimeException("Migrated table missing: " . $new);
    }
}
$status = get_option("ysai_release1_table_namespace_migration", array());
if (!is_array($status) || ($status["state"] ?? "") !== "migrated") {
    throw new RuntimeException("Migration receipt missing or invalid.");
}
echo "legacy namespace migration verified\n";
' --allow-root >"${ARTIFACTS}/migration-verification.log" 2>&1

${COMPOSE} run --rm wpcli eval '
if (!has_filter("wp_privacy_personal_data_exporters")) { throw new RuntimeException("Privacy exporter hook missing."); }
if (!has_filter("wp_privacy_personal_data_erasers")) { throw new RuntimeException("Privacy eraser hook missing."); }
if (!wp_next_scheduled("ysai_release1_runtime_retention") && !wp_next_scheduled("ysai_release1_retention")) { throw new RuntimeException("Retention job missing."); }
global $wpdb;
$table = $wpdb->prefix . "ysr1_agent_policy_versions";
$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . $table . "` WHERE status = \"published\"");
if ($count < 1) { throw new RuntimeException("Published Asala policy missing."); }
echo "privacy, retention, and policy verified\n";
' --allow-root >"${ARTIFACTS}/privacy-retention-policy.log" 2>&1

# Deactivation/reactivation must be non-destructive and idempotent.
${COMPOSE} run --rm wpcli plugin deactivate yassin-ai-assistant --allow-root >"${ARTIFACTS}/plugin-deactivate-second.log" 2>&1
${COMPOSE} run --rm wpcli plugin activate yassin-ai-assistant --allow-root >"${ARTIFACTS}/plugin-reactivate-second.log" 2>&1
${COMPOSE} run --rm wpcli plugin status yassin-ai-assistant --allow-root >"${ARTIFACTS}/plugin-final-status.log" 2>&1

echo "Release 1 activation, migration, privacy, retention, and rollback runtime gate passed."
