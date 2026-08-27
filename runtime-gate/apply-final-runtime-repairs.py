#!/usr/bin/env python3
from __future__ import annotations

import json
import re
import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "runtime-gate-final-output" / "repair-report.json"
REPORT.parent.mkdir(parents=True, exist_ok=True)
repairs: list[str] = []


def write(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8", newline="\n")


# Preserve the established public constants and WooCommerce HPOS declaration.
main = ROOT / "yassin-ai-assistant.php"
main_content = r'''<?php
/**
 * Plugin Name: Yassin Store AI Sales Agent
 * Plugin URI: https://yassin-store.com/
 * Description: AI-led WooCommerce sales assistant with authoritative catalog and cart operations, typed conversation state, and a secure storefront chat widget.
 * Version: 1.1.0
 * Author: Yassin Store
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * WC requires at least: 10.9.4
 * WC tested up to: 11.0.1
 * Text Domain: yassin-ai-assistant
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('YSAI_VERSION', '1.1.0');
define('YSAI_PLUGIN_FILE', __FILE__);
define('YSAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YSAI_PLUGIN_URL', plugin_dir_url(__FILE__));
// Additive aliases used only by Release 1. Existing code keeps its canonical constants.
define('YSAI_FILE', YSAI_PLUGIN_FILE);
define('YSAI_DIR', YSAI_PLUGIN_DIR);
define('YSAI_URL', YSAI_PLUGIN_URL);

require_once YSAI_PLUGIN_DIR . 'src/Autoload.php';
\YassinStore\AiAssistant\Autoload::register();

require_once YSAI_PLUGIN_DIR . 'src/Release1Runtime/Runtime.php';
// The old ysai_r1_* namespace must be migrated before core exact-schema authority runs.
\YassinStore\AiAssistant\Release1Runtime\Runtime::preBoot();

add_action(
    'before_woocommerce_init',
    static function (): void {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }
);

register_activation_hook(
    __FILE__,
    static function (): void {
        \YassinStore\AiAssistant\Release1Runtime\Runtime::activate();
        \YassinStore\AiAssistant\Lifecycle\Activator::activate();
    }
);
register_deactivation_hook(
    __FILE__,
    static function (): void {
        \YassinStore\AiAssistant\Release1Runtime\Runtime::deactivate();
        \YassinStore\AiAssistant\Lifecycle\Deactivator::deactivate();
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        \YassinStore\AiAssistant\Plugin::instance()->boot();
        \YassinStore\AiAssistant\Release1Runtime\Runtime::boot();
    },
    20
);
'''
write(main, main_content)
repairs.append("canonical_main_entrypoint")


# Normalize MariaDB information_schema representations without weakening any
# type, nullability, index, collation, or unexpected-column comparison.
schema_candidates = list((ROOT / "src").rglob("SchemaDiffer.php"))
if schema_candidates:
    path = schema_candidates[0]
    text = path.read_text(encoding="utf-8")
    match = re.search(
        r"(?P<header>(?:private|protected|public)\s+static\s+function\s+normalizeDefault\s*\([^)]*\)(?:\s*:\s*[^\{]+)?)\s*\{",
        text,
    )
    if not match:
        raise SystemExit("SchemaDiffer::normalizeDefault was not found")
    start = match.start()
    brace = text.find("{", match.start())
    depth = 0
    end = -1
    for index in range(brace, len(text)):
        if text[index] == "{":
            depth += 1
        elif text[index] == "}":
            depth -= 1
            if depth == 0:
                end = index + 1
                break
    if end < 0:
        raise SystemExit("SchemaDiffer::normalizeDefault has an unbalanced body")
    header = re.sub(r"\s*:\s*[^\{]+$", "", match.group("header")).rstrip()
    body = r'''{
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if (strcasecmp($normalized, 'NULL') === 0) {
            return null;
        }

        $length = strlen($normalized);
        if ($length >= 2) {
            $first = $normalized[0];
            $last = $normalized[$length - 1];
            if ($first === "'" && $last === "'") {
                return str_replace("''", "'", substr($normalized, 1, -1));
            }
            if ($first === '"' && $last === '"') {
                return str_replace('""', '"', substr($normalized, 1, -1));
            }
        }

        return $normalized;
    }'''
    patched = text[:start] + header + "\n    " + body + text[end:]
    write(path, patched)
    repairs.append("mariadb_schema_default_normalization")


runtime = ROOT / "src" / "Release1Runtime" / "Runtime.php"
if not runtime.is_file():
    raise SystemExit("Release 1 runtime module is missing")
text = runtime.read_text(encoding="utf-8")

# Integration provider is allowed only under the explicit test-mode constant.
old = """$provider = str_contains($lower, 'generativelanguage.googleapis.com') || str_contains($lower, 'aiplatform.googleapis.com') || str_contains($lower, 'api.openai.com') || str_contains($lower, 'api.anthropic.com');"""
new = """$provider = str_contains($lower, 'generativelanguage.googleapis.com')
            || str_contains($lower, 'aiplatform.googleapis.com')
            || str_contains($lower, 'api.openai.com')
            || str_contains($lower, 'api.anthropic.com')
            || (defined('YSAI_INTEGRATION_TEST_MODE') && YSAI_INTEGRATION_TEST_MODE && str_contains($lower, 'fake-gemini'));"""
if old in text:
    text = text.replace(old, new)
    repairs.append("fake_provider_test_scope")

# Completed tokens do not create a second AI turn. Return an idempotent replay
# response carrying the original durable message key.
old = """if ($row['status'] === 'completed') { return ['replay' => true, 'row' => $row]; }"""
new = """if ($row['status'] === 'completed') {
            return new WP_Error(
                'ysai_r1_event_replayed',
                __('This interaction was already completed.', 'yassin-ai-assistant'),
                ['status' => 409, 'result_message_key' => (string) ($row['result_message_key'] ?? '')]
            );
        }"""
if old in text:
    text = text.replace(old, new)
    repairs.append("structured_event_idempotent_replay")

# Recalculate the active Pending Question state hash before reservation.
needle = """if ($row['status'] !== 'issued') { return new WP_Error('ysai_r1_event_in_use', __('The interaction is already being processed.', 'yassin-ai-assistant'), ['status' => 409]); }
        $updated = $wpdb->query"""
replacement = """if ($row['status'] !== 'issued') { return new WP_Error('ysai_r1_event_in_use', __('The interaction is already being processed.', 'yassin-ai-assistant'), ['status' => 409]); }
        $payload = self::decodeJson((string) $row['payload_json']);
        $questionId = isset($payload['question_id']) ? (string) $payload['question_id'] : '';
        if ($questionId !== '') {
            $active = $wpdb->get_row(
                $wpdb->prepare(
                    \"SELECT question_id,version,status,expires_at FROM \" . self::quotedTable('pending_questions') . \" WHERE question_id=%s AND actor_key=%s AND conversation_key=%s LIMIT 1\",
                    $questionId,
                    $actor,
                    $conversation
                ),
                ARRAY_A
            );
            $currentHash = is_array($active)
                ? hash('sha256', (string) $active['question_id'] . '|' . (string) $active['version'])
                : '';
            if (!is_array($active) || ($active['status'] ?? '') !== 'open' || strtotime((string) $active['expires_at']) < time() || !hash_equals((string) $row['state_hash'], $currentHash)) {
                return new WP_Error('ysai_r1_event_stale', __('The conversation changed. Please use the newest choices.', 'yassin-ai-assistant'), ['status' => 409]);
            }
        }
        $updated = $wpdb->query"""
if needle in text:
    text = text.replace(needle, replacement)
    repairs.append("structured_event_live_state_hash")

# Ensure the already-decoded payload is reused after successful reservation.
text = text.replace(
    "$row['payload'] = self::decodeJson((string) $row['payload_json']);\n        return ['replay' => false, 'row' => $row];",
    "$row['payload'] = isset($payload) && is_array($payload) ? $payload : self::decodeJson((string) $row['payload_json']);\n        return ['replay' => false, 'row' => $row];",
)

# Persist safe journey state emitted by the AI/controller response.
old = """$sales = self::findSalesContext($data);
        if ($sales !== []) {
            self::upsertSalesContext($actor, $conversation, $sales);
        }
        $focus = self::findFocus($data);"""
new = """$sales = self::findSalesContext($data);
        if ($sales !== []) {
            self::upsertSalesContext($actor, $conversation, $sales);
        }
        $journey = self::findJourney($data);
        if ($journey !== []) {
            self::upsertJourney($actor, $conversation, $journey);
        }
        $focus = self::findFocus($data);"""
if old in text:
    text = text.replace(old, new)
    repairs.append("journey_runtime_projection")

anchor = """    /** @return array<string,mixed> */
    private static function findFocus(array $data): array
"""
journey_methods = r'''    /** @return array<string,mixed> */
    private static function findJourney(array $data): array
    {
        $journey = self::findValueByKey($data, 'journey');
        if (!is_array($journey)) {
            $journey = self::findValueByKey($data, 'journey_state');
        }
        if (!is_array($journey)) {
            return [];
        }
        $allowed = ['journey_id','journey_type','status','current_step','known','missing','stale','resume_point'];
        return array_intersect_key($journey, array_flip($allowed));
    }

    /** @param array<string,mixed> $journey */
    private static function upsertJourney(string $actor, string $conversation, array $journey): void
    {
        $type = sanitize_key((string) ($journey['journey_type'] ?? 'sales'));
        if (in_array($type, ['checkout','order','crm','payment_review','human_handoff'], true)) {
            return;
        }
        $status = sanitize_key((string) ($journey['status'] ?? 'active'));
        if (!in_array($status, ['active','paused','completed','cancelled','failed'], true)) {
            $status = 'active';
        }
        global $wpdb;
        $site = get_current_blog_id();
        $now = gmdate('Y-m-d H:i:s');
        $journeyId = sanitize_text_field((string) ($journey['journey_id'] ?? self::uuid()));
        $sql = $wpdb->prepare(
            'INSERT INTO ' . self::quotedTable('journeys') . ' (journey_id,site_id,actor_key,conversation_key,journey_type,status,current_step,known_json,missing_json,stale_json,resume_point,version,created_at,updated_at) VALUES (%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,1,%s,%s) ON DUPLICATE KEY UPDATE status=VALUES(status),current_step=VALUES(current_step),known_json=VALUES(known_json),missing_json=VALUES(missing_json),stale_json=VALUES(stale_json),resume_point=VALUES(resume_point),version=version+1,updated_at=VALUES(updated_at)',
            $journeyId,
            $site,
            $actor,
            $conversation,
            $type,
            $status,
            sanitize_text_field((string) ($journey['current_step'] ?? '')),
            wp_json_encode(is_array($journey['known'] ?? null) ? $journey['known'] : []),
            wp_json_encode(is_array($journey['missing'] ?? null) ? $journey['missing'] : []),
            wp_json_encode(is_array($journey['stale'] ?? null) ? $journey['stale'] : []),
            sanitize_text_field((string) ($journey['resume_point'] ?? '')),
            $now,
            $now
        );
        $wpdb->query($sql);
    }

'''
if anchor in text and "private static function findJourney" not in text:
    text = text.replace(anchor, journey_methods + anchor)

# Generic IDs embedded in product cards are not durable turn authority.
pattern = re.compile(
    r"    private static function findDurableMessageKey\(array \$data\): string\n    \{.*?\n    \}\n\n    private static function requestText",
    re.S,
)
replacement = r'''    private static function findDurableMessageKey(array $data): string
    {
        foreach (['turn_id','message_id','assistant_message_id'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                return hash('sha256', $key . '|' . (string) $data[$key]);
            }
        }
        foreach (['message','assistant_message','turn'] as $container) {
            if (!isset($data[$container]) || !is_array($data[$container])) {
                continue;
            }
            foreach (['turn_id','message_id','id'] as $key) {
                $value = $data[$container][$key] ?? null;
                if (is_scalar($value) && (string) $value !== '') {
                    return hash('sha256', $container . '|' . $key . '|' . (string) $value);
                }
            }
        }
        return '';
    }

    private static function requestText'''
text, count = pattern.subn(replacement, text, count=1)
if count:
    repairs.append("durable_turn_authority_narrowed")

write(runtime, text)

# Remove temporary connector probes and superseded workflows from the release tree.
for path in [ROOT / "runtime-gate"]:
    if path.is_dir():
        for child in path.iterdir():
            if child.name != "apply-final-runtime-repairs.py":
                if child.is_dir():
                    shutil.rmtree(child)
                else:
                    child.unlink()
for name in ["release1-export-source.yml", "release1-cloud-runtime-gate.yml", "release1-cloud-runtime-gate-v2.yml"]:
    path = ROOT / ".github" / "workflows" / name
    if path.exists():
        path.unlink()
repairs.append("temporary_gate_cleanup")

REPORT.write_text(json.dumps({"ok": True, "repairs": repairs}, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"ok": True, "repairs": repairs}))
