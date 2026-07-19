#!/usr/bin/env python3
"""Dependency-free static integrity checks for the source-only integration harness."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
INTEGRATION = ROOT / "integration"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(message)


lock = json.loads((INTEGRATION / "version-lock.json").read_text(encoding="utf-8"))
compose_text = (INTEGRATION / "docker-compose.yml").read_text(encoding="utf-8")

require(lock["wordpress"] == "6.9.4", "Real-stack lane must execute the exact WordPress 6.9.4 runtime.")
require(lock["wordpress_minimum"] == "6.9", "WordPress compatibility floor must remain 6.9.")
require(lock["php"] == "8.3", "Real-stack lane must execute the declared PHP 8.3 runtime.")
require(lock["php_minimum"] == "7.4", "PHP compatibility floor must remain 7.4.")
require(lock["woocommerce"] == "10.9.4", "Real-stack lane must execute the promotion-tested WooCommerce 10.9.4 release.")

compatibility_contract = json.loads((ROOT / "config/woocommerce-compatibility.json").read_text(encoding="utf-8"))
require(
    set(compatibility_contract) == {
        "schema_version", "minimum", "maximum_exclusive", "tested_up_to",
        "promotion_tested", "wordpress_minimum", "runtime_contract",
    },
    "WooCommerce compatibility contract must be closed.",
)
require(compatibility_contract["schema_version"] == 1, "Unexpected WooCommerce compatibility schema version.")
require(compatibility_contract["minimum"] == "10.9.4", "WooCommerce accepted floor drifted from promotion evidence.")
require(compatibility_contract["maximum_exclusive"] == "11.0.0", "WooCommerce major-version boundary is not closed.")
require(compatibility_contract["tested_up_to"] == lock["woocommerce"], "Integration pin differs from tested-up-to evidence.")
require(lock["woocommerce"] in compatibility_contract["promotion_tested"], "Source-mounted integration pin must be promotion-tested.")
require(compatibility_contract["promotion_tested"][-1] == compatibility_contract["tested_up_to"], "Tested-up-to must equal the highest promotion-tested release.")
require(compatibility_contract["wordpress_minimum"] == lock["wordpress_minimum"], "WordPress compatibility floor differs from the runtime lock.")
require(compatibility_contract["runtime_contract"] == "woocommerce-10.9-core-session-v1", "Unexpected WooCommerce core-session contract.")

plugin_entry = (ROOT / "yassin-ai-assistant.php").read_text(encoding="utf-8")
require(f"Requires at least: {compatibility_contract['wordpress_minimum']}" in plugin_entry, "Plugin WordPress metadata differs from the compatibility contract.")
require(f"WC requires at least: {compatibility_contract['minimum']}" in plugin_entry, "Plugin WooCommerce minimum metadata differs from the contract.")
require(f"WC tested up to: {compatibility_contract['tested_up_to']}" in plugin_entry, "Plugin WooCommerce tested metadata differs from promotion evidence.")
require("YSAI_MIN_WOOCOMMERCE_VERSION" not in plugin_entry, "Retired exact-version constant remains in the plugin entry point.")

compatibility_source = (ROOT / "src/Infrastructure/WooCommerce/WooCommerceCompatibility.php").read_text(encoding="utf-8")
adapter_path = ROOT / "src/Infrastructure/WooCommerce/WooSessionInternalsAdapter.php"
adapter_source = adapter_path.read_text(encoding="utf-8")
private_root = ROOT / "src/Infrastructure/WooCommerce/Internals"
private_files = {
    "WooCoreStructureProbe.php": (
        "ReflectionProperty", "'_session_expiration'", "'_table'",
        "'WC_Session_Handler'", "'WC_Cart_Session'",
        "getNumberOfRequiredParameters", "getNumberOfParameters", "class_parents",
    ),
    "WooSessionStorageInternals.php": (
        "WC_SESSION_CACHE_GROUP", "'woocommerce_sessions'", "storedSessionMap",
    ),
    "WooCartHookTopology.php": (
        "assertMutationRuntime", "sideWriterHooks", "suppressAutomaticSave",
    ),
    "WooCartIdentityInternals.php": (
        "CartTokenUtils", "'previous_customer_id'", "guardClonedOperationAuthority",
    ),
    "WooPersistentCartInternals.php": (
        "persistentCartMetaKey", "persistentCartProjection",
    ),
}
require(
    {path.name for path in private_root.glob("*.php")} == set(private_files),
    "WooCommerce private collaborator set is not exact.",
)
for file_name, required_tokens in private_files.items():
    source = (private_root / file_name).read_text(encoding="utf-8")
    require(source.count("\n") < 320, f"WooCommerce private collaborator is oversized: {file_name}")
    for required in required_tokens:
        require(required in source, f"WooCommerce private collaborator omits: {file_name} -> {required}")

collaborator_names = tuple(Path(file_name).stem for file_name in private_files)
for collaborator in collaborator_names:
    require(collaborator in adapter_source, f"WooCommerce adapter does not compose: {collaborator}")
for required in (
    "assertStaticCoreCapabilities", "assertMutationRuntime",
    "allowsVerifiedCartMutation", "assertVerifiedCartMutationVersion",
    "$this->hooks->assertMutationRuntime", "$this->identity->guardClonedOperationAuthority",
):
    require(required in adapter_source, f"WooCommerce application-facing adapter omits: {required}")
require(adapter_source.count("\n") < 280, "WooCommerce application-facing adapter is oversized.")
for forbidden in (
    "ReflectionProperty", "_session_expiration", "'_table'", "WC_SESSION_CACHE_GROUP",
    "woocommerce_sessions", "previous_customer_id", "CartTokenUtils",
    "WC_Session_Handler", "WC_Cart_Session",
):
    require(forbidden not in adapter_source, f"WooCommerce private mechanics returned to adapter: {forbidden}")

for path in (ROOT / "src").rglob("*.php"):
    if path == adapter_path or private_root in path.parents:
        continue
    source = path.read_text(encoding="utf-8")
    for collaborator in collaborator_names:
        require(collaborator not in source, f"Production code depends on private Woo collaborator: {path}")

plugin_boot_source = (ROOT / "src/Plugin.php").read_text(encoding="utf-8")
activator_source = (ROOT / "src/Lifecycle/Activator.php").read_text(encoding="utf-8")
for required in (
    "PROMOTION_TESTED", "ADMITTED_UNPROMOTED", "maximumExclusive",
    "assertInstalledVersionAdmitted", "isInstalledVersionPromotionTested",
):
    require(required in compatibility_source, f"WooCommerce version policy omits: {required}")
for source_name, source in (("boot", plugin_boot_source), ("activation", activator_source)):
    require("WooCommerceCompatibility::fromPluginContract()" in source, f"WooCommerce policy is not enforced during {source_name}.")
    require("assertStaticCoreCapabilities()" in source, f"WooCommerce structural proof is not enforced during {source_name}.")
require("version_compare((string) WC_VERSION" not in plugin_boot_source + activator_source, "Exact WooCommerce patch equality remains in boot/activation.")
commerce_stack_source = (ROOT / "src/Infrastructure/Composition/CommerceStack.php").read_text(encoding="utf-8")
capability_source = (ROOT / "src/Domain/Commerce/CartMutationCapability.php").read_text(encoding="utf-8")
require("if ($wooInternals->allowsVerifiedCartMutation())" in commerce_stack_source, "Unpromoted WooCommerce releases still register the direct cart fence.")
promotion_gate = commerce_stack_source.find("if ($wooInternals->allowsVerifiedCartMutation())")
fence_registration = commerce_stack_source.find("$requestFence->register();", promotion_gate)
boot_projection = commerce_stack_source.find("$this->bootCart =", fence_registration)
require(
    promotion_gate >= 0 and fence_registration >= 0 and boot_projection >= 0
    and promotion_gate < fence_registration < boot_projection
    and commerce_stack_source.count("$requestFence->register();") == 1,
    "Read-only admitted WooCommerce releases can install mutation hooks.",
)
require("VERSION_NOT_PROMOTION_TESTED = 'version_not_promotion_tested'" in capability_source, "Read-only unpromoted-version capability is missing.")

woo_session_source = (ROOT / "src/Infrastructure/WooCommerce/WooSession.php").read_text(encoding="utf-8")
require("private function seedCartOperationNonce(): void" in woo_session_source, "Cart nonce seeding is not private.")
require("public function seedCartOperationNonce(): void" not in woo_session_source, "Cart nonce seeding remains publicly callable.")
publish_start = woo_session_source.find("public function publishCartOperationAuthority(): void")
publish_version = woo_session_source.find("$this->internals->assertVerifiedCartMutationVersion();", publish_start)
publish_ensure = woo_session_source.find("$this->ensure();", publish_start)
publish_seed = woo_session_source.find("$this->seedCartOperationNonce();", publish_start)
publish_save = woo_session_source.find("$this->internals->saveSession($handler);", publish_start)
require(
    publish_start >= 0 and publish_version >= 0 and publish_ensure >= 0
    and publish_seed >= 0 and publish_save >= 0
    and publish_start < publish_version < publish_ensure < publish_seed < publish_save,
    "Cart operation authority can be published before promotion proof.",
)

capability_proof_source = (ROOT / "src/Infrastructure/WooCommerce/Cart/CartMutationCapabilityProof.php").read_text(encoding="utf-8")
begin_start = capability_proof_source.find("public function beginProtectedMutation(): void")
mutation_tokens = (
    "$this->assertAvailable();",
    "$this->requestFence->reacquireForMutation();",
    "$this->store->beginAuthoritativeMutation();",
    "$this->store->refreshWorkingFromDurable();",
    "$this->assertSupported();",
)
mutation_positions = [capability_proof_source.find(token, begin_start) for token in mutation_tokens]
require(
    begin_start >= 0 and all(position >= 0 for position in mutation_positions)
    and mutation_positions == sorted(mutation_positions),
    "Mutation capability is not proved before fence and session changes.",
)

probe_source = (ROOT / "tests/woocommerce-compatibility-probe.php").read_text(encoding="utf-8")
require("future_drift" in probe_source, "Structural drift is not tested independently on an admitted future patch.")

services = re.findall(r"^  ([a-z][a-z0-9-]*):\n", compose_text, flags=re.MULTILINE)
require(set(services) == {"db", "fake-gemini", "wordpress", "wpcli", "runner"}, "Unexpected integration services.")
required_images = (
    f"image: mariadb:{lock['mariadb']}",
    f"image: wordpress:{lock['wordpress']}-php{lock['php']}-apache",
    f"image: wordpress:cli-{lock['wp_cli']}-php{lock['php']}",
)
for image in required_images:
    require(image in compose_text, f"Pinned container image is missing: {image}")
require(":latest" not in compose_text, "Source-mounted integration contains a floating container tag.")
require(f"node:{lock['node']}-bookworm-slim" in (INTEGRATION / "docker/fake-gemini/Dockerfile").read_text(), "Fake Gemini Node image is not pinned.")
require(f"mcr.microsoft.com/playwright:v{lock['playwright']}-noble" in (INTEGRATION / "docker/runner/Dockerfile").read_text(), "Playwright image is not pinned.")
require(f"woocommerce --version={lock['woocommerce']}" in (INTEGRATION / "scripts/bootstrap.sh").read_text(), "WooCommerce version is not pinned in bootstrap.")
bootstrap = (INTEGRATION / "scripts/bootstrap.sh").read_text(encoding="utf-8")
require("/yassin-ai/v1/admin/test" in bootstrap, "Bootstrap must execute the production admin readiness route.")
require("rest_do_request($request)" in bootstrap, "Bootstrap readiness must pass through the real REST dispatcher.")
require("--user=admin eval" in bootstrap, "Bootstrap readiness must execute as an authorized administrator.")
require('($data["result"]["reply"] ?? "") !== "جاهز"' in bootstrap, "Bootstrap must verify the runtime-readiness result.")
require('($data["result"]["provider_requests"] ?? 0) !== 2' in bootstrap, "Bootstrap must require exactly two readiness requests.")
require('provider_access' in bootstrap and 'structured_tool' in bootstrap, "Bootstrap must verify both bounded runtime checks.")
require("privileged: true" not in compose_text, "Integration services must not be privileged.")
require("network_mode: host" not in compose_text, "Integration services must not use host networking.")
require('127.0.0.1:${YSAI_TEST_HOST_PORT:-8080}:80' in compose_text, "WordPress test port must bind to loopback only.")
require("..:/var/www/html/wp-content/plugins/yassin-ai-assistant:ro" in compose_text, "Plugin source must be mounted read-only into WordPress.")
require("..:/workspace/plugin:ro" in compose_text, "Plugin source must be mounted read-only into the runner.")
require("./artifacts:/artifacts" in compose_text, "Runner artifacts need a writable mount outside the read-only source tree.")
require("YSAI_TEST_OUTPUT_DIR: /artifacts/test-results" in compose_text, "Runner output directory is not configured.")
for constant in ("YSAI_INTEGRATION_TEST_MODE", "YSAI_GEMINI_API_BASE_URL", "YSAI_INTEGRATION_CONTROL_TOKEN"):
    require(constant in compose_text, f"Missing test configuration constant: {constant}")

package_source = (ROOT / "scripts/package.py").read_text(encoding="utf-8")
require("PRODUCTION_ROOTS" in package_source and "SOURCE_ROOTS" in package_source, "Release package roots must be closed.")
production_roots = package_source.split("PRODUCTION_ROOTS =", 1)[1].split("SOURCE_ROOTS =", 1)[0]
require('"integration"' not in production_roots, "Installable package must exclude the integration harness.")
require('"composer.json"' in package_source and '"phpunit.xml.dist"' in package_source,
        "Source package must retain the standard development-tool metadata.")
require('"vendor"' in package_source and '("config", "quality")' in package_source,
        "Installable packaging must exclude Composer vendor and development quality ledgers.")

endpoint = (ROOT / "src/Infrastructure/Gemini/GeminiEndpoint.php").read_text(encoding="utf-8")
require("YSAI_INTEGRATION_TEST_MODE" in endpoint and "YSAI_GEMINI_API_BASE_URL" in endpoint, "Integration endpoint seam is missing.")
require("must use HTTPS outside integration tests" in endpoint, "Non-test HTTP endpoint guard is missing.")

production_php = "\n".join(path.read_text(encoding="utf-8") for path in (ROOT / "src").rglob("*.php"))
require("provider_precondition_failed" not in production_php, "Retired broad provider precondition invalidation remains.")
require("RuntimeReadinessFailurePolicy::contradictsProof($code)" in (ROOT / "src/Application/Agent/AgentRunner.php").read_text(encoding="utf-8"),
        "Shopper runtime bypasses the closed readiness contradiction policy.")

server = (INTEGRATION / "fake-gemini/server.js").read_text(encoding="utf-8")

self_test = (INTEGRATION / "fake-gemini/self-test.js").read_text(encoding="utf-8")
for token in (
    "const RUNTIME_READINESS_TOOL_NAMES = ['readiness_echo'];",
    "This is an administrative model-access check.",
    "Call readiness_echo exactly once",
    "exactNames(declarationNames(payload), RUNTIME_READINESS_TOOL_NAMES)",
):
    require(token in server, f"Fake Gemini runtime readiness omits: {token}")
require("result.body.calls.length, 2" in self_test, "Fake Gemini self-test must prove exactly two readiness requests.")
for scenario in (
    "runtime_access_unavailable", "runtime_access_authentication",
    "runtime_access_service_disabled", "runtime_access_billing_disabled",
    "runtime_access_contract_rejected", "runtime_access_precondition_rejected",
    "runtime_structured_invalid", "runtime_structured_contract_rejected",
    "runtime_structured_precondition_rejected",
):
    require(f"case '{scenario}'" in server, f"Fake Gemini readiness scenario is missing: {scenario}")
    require(f"reset('{scenario}')" in self_test, f"Fake Gemini self-test omits readiness scenario: {scenario}")
require("providerError" in server and "runtimeReadinessResponse" in server, "Fake Gemini readiness taxonomy is not explicit.")
require("ثلاث جولات" not in server + self_test, "Retired three-round runtime probe remains in integration fixtures.")

# Keep the fake provider's accepted production contract byte-for-byte aligned
# with the live ToolStack. Handler order matters because it is the order sent
# to Gemini, and an omitted or stale fake-only name would make the real-stack
# lane test a protocol that production never emits.
tool_stack_path = ROOT / "src/Infrastructure/Composition/ToolStack.php"
tool_stack = tool_stack_path.read_text(encoding="utf-8")
imports = {
    short_name: qualified_name + "\\" + short_name
    for qualified_name, short_name in re.findall(
        r"^use\s+([^;\\]+(?:\\[^;\\]+)*)\\([A-Za-z_][A-Za-z0-9_]*Handler);$",
        tool_stack,
        flags=re.MULTILINE,
    )
}
handler_classes = re.findall(r"new\s+([A-Za-z_][A-Za-z0-9_]*Handler)\s*\(", tool_stack)
require(handler_classes, "ToolStack production handler list could not be derived.")
require(len(handler_classes) == len(set(handler_classes)), "ToolStack contains a duplicate production handler.")
tool_names = []
for handler_class in handler_classes:
    require(handler_class in imports, f"ToolStack handler import is missing: {handler_class}")
    project_namespace = "YassinStore\\AiAssistant\\"
    qualified_handler = imports[handler_class]
    require(qualified_handler.startswith(project_namespace), f"ToolStack handler is outside the plugin namespace: {handler_class}")
    handler_path = ROOT / "src" / (qualified_handler[len(project_namespace):].replace("\\", "/") + ".php")
    require(handler_path.is_file(), f"ToolStack handler source is missing: {handler_path.relative_to(ROOT)}")
    handler_source = handler_path.read_text(encoding="utf-8")
    name_match = re.search(r"new\s+ToolContract\s*\(\s*'([^']+)'", handler_source)
    require(name_match is not None, f"Tool contract name could not be derived from {handler_path.relative_to(ROOT)}")
    tool_names.append(name_match.group(1))
require(len(tool_names) == len(set(tool_names)), "ToolStack contains a duplicate production tool contract name.")
require(len(tool_names) == 20, "Production must expose exactly twenty tools in one catalog.")

model_request = (ROOT / "src/Application/Ai/ModelRequest.php").read_text(encoding="utf-8")
tool_limit_match = re.search(r"MAX_TOOL_DECLARATIONS\s*=\s*(\d+)", model_request)
require(tool_limit_match is not None, "ModelRequest tool-declaration limit could not be derived.")
require(len(tool_names) <= int(tool_limit_match.group(1)), "ToolStack exceeds ModelRequest's declaration limit.")

fake_names_match = re.search(r"const\s+EXPECTED_TOOL_NAMES\s*=\s*\[(.*?)\];", server, flags=re.DOTALL)
require(fake_names_match is not None, "Fake Gemini expected tool list could not be derived.")
fake_tool_names = re.findall(r"'([^']+)'", fake_names_match.group(1))
require(fake_tool_names == tool_names, "Fake Gemini tool list/order differs from the production ToolStack.")
require("const validated = productionCatalog" in server
        and "functionConfig.mode === 'VALIDATED'" in server,
        "Fake Gemini must accept only the production AI-led VALIDATED contract.")
require("const forced = constrainedCatalog" in server
        and "functionConfig.mode === 'ANY'" in server
        and "functionConfig.allowedFunctionNames.length === 1" in server,
        "Fake Gemini must enforce the production one-function forced-step contract.")
require("const CART_INTENT_TOOL_NAMES = ['verify_current_cart_intent'];" in server,
        "Fake Gemini must expose the isolated cart-intent verification contract.")
require("const RUNTIME_READINESS_TOOL_NAMES = ['readiness_echo'];" in server,
        "Fake Gemini must expose the one-tool runtime-readiness contract.")
require("function isRuntimeAccessRequest" in server and "function runtimeEchoToken" in server,
        "Fake Gemini must exercise both runtime-readiness requests.")
require("function cartIntentResponse" in server,
        "Fake Gemini must exercise positive and negative semantic cart-intent decisions.")

for scenario in (
    "answer", "search_answer", "recommendation_answer", "add_simple", "add_variable", "update_first_cart_item",
    "update_during_concurrent_cart_request", "newest_answer", "best_selling_budget_answer",
    "remove_first_cart_item", "clear_cart", "plain_then_terminal", "english_terminal_then_arabic", "mutation_with_sibling",
    "invalid_tool_arguments", "mixed_output", "malformed_success", "empty_candidate",
    "upstream_500", "upstream_429", "delay_answer", "update_during_concurrent_cart_request",
    "missing_required_tool_field", "follow_up_exact", "clarify_quantity_then_update",
    "follow_up_outer_whitespace"
):
    require(f"case '{scenario}'" in server, f"Fake Gemini scenario is missing: {scenario}")
require("function allFeedback" in server, "Fake Gemini must retain earlier tool feedback across model rounds.")
require("validateGenerateContentRequest" in server and "tool_catalog_mismatch" in server, "Fake Gemini must reject malformed production request catalogs.")

specs = sorted((INTEGRATION / "tests/specs").glob("*.spec.js"))
require(len(specs) >= 6, "The integration suite must contain at least six focused spec files.")
scenario_count = sum(len(re.findall(r"^\s*test\(", path.read_text(encoding="utf-8"), flags=re.MULTILINE)) for path in specs)
require(scenario_count >= 35, "The integration suite must define at least thirty-five real-stack scenarios.")
combined = "\n".join(path.read_text(encoding="utf-8") for path in specs)
for behavior in (
    "same serialized turn replays", "simple add executes once", "termination after WooCommerce persistence",
    "lease loss", "typed product request", "typed variation request", "malformed provider success",
    "expired short-lived session token", "expired conversation", "quantity update", "sole clear after live cart view",
    "same serialized mutation turn replays", "concurrent duplicate mutation waits behind the request fence",
    "same-session cart request waits behind the whole chat request", "missing a required field", "WooCommerce exception",
    "changes quantity", "hook-driven metadata on the targeted cart line", "one final calculation before staging the Woo session", "catalog search renders grounded", "AI-led recommendation persists typed memory",
    "visible canonical history is exactly the raw model context window",
    "typed customer text persists byte-for-byte",
    "boot discloses chat cart mutation capability",
    "English terminal prose is rejected and corrected to Arabic before publication",
    "natural multi-turn pronoun add re-resolves the live variation",
    "natural quantity update and removal resolve the sole live cart line",
    "natural whole-cart clear executes after the authoritative live view",
    "WooCommerce add rejection is visible without another provider request",
):
    require(behavior in combined, f"Missing integration behavior coverage: {behavior}")
for retired in (
    "selectSigned" + "Cho" + "ice",
    "selectProduct" + "Cho" + "ice",
    "selectVariation" + "Cho" + "ice",
    "selectCartItem" + "Cho" + "ice",
    ".ysai-" + "cho" + "ice",
):
    require(retired not in combined, f"Retired browser-selection path remains in integration specs: {retired}")

mu_plugin = (INTEGRATION / "wordpress/mu-plugins/ysai-integration-harness.php").read_text(encoding="utf-8")
require("YSAI_INTEGRATION_TEST_MODE" in mu_plugin, "Test controls are not gated by integration mode.")
require("ysai_integration_restore_products" in mu_plugin, "Fixture restoration is missing from isolated scenario reset.")
tables_function_match = re.search(
    r"function\s+ysai_integration_tables\s*\(\s*\)\s*\{(.*?)\n\}",
    mu_plugin,
    flags=re.DOTALL,
)
require(tables_function_match is not None, "Integration table-reset authority is missing.")
tables_function = tables_function_match.group(1)
require("SchemaRegistry::current()" in tables_function and "->tableNames()" in tables_function, "Integration reset must derive every plugin table from SchemaRegistry.")
require("ysai_" not in tables_function, "Integration reset must not duplicate physical schema table names.")
for fault in ("reject_add", "throw_add", "delay_add_validation", "terminate_after_add", "lose_lease_after_add", "diverge_after_add", "change_quantity_after_add", "mutate_metadata_after_quantity", "mutate_metadata_each_calculation"):
    require(fault in mu_plugin, f"Fault injector is missing: {fault}")
require("http_request_host_is_external" in mu_plugin and "fake-gemini:8787/v1beta/models/" in mu_plugin, "Docker-internal Gemini host is not narrowly allowed through WordPress URL safety.")
playwright_config = (INTEGRATION / "tests/playwright.config.js").read_text(encoding="utf-8")
require("YSAI_TEST_OUTPUT_DIR" in playwright_config, "Playwright output is still directed at the read-only plugin mount.")


# Artifact-first packaged-plugin promotion is a separate authority from the
# fast source-mounted development lane.
promotion = INTEGRATION / "promotion"
promotion_compose = (promotion / "compose.yaml").read_text(encoding="utf-8")
require(":latest" not in promotion_compose, "Promotion compose contains a floating container tag.")
require("wordpress:6.9.4-php8.3-apache" in promotion_compose, "Promotion WordPress image differs from the exact runtime pin.")
require("../..:/var/www/html/wp-content/plugins" not in promotion_compose, "Promotion WordPress still mounts plugin source.")
require("/package/yassin-ai-assistant.zip" in (promotion / "scripts/common.sh").read_text(encoding="utf-8"), "Promotion lane does not install the tested ZIP.")
require("/package/woocommerce.zip" in (promotion / "scripts/common.sh").read_text(encoding="utf-8"), "Promotion lane does not install an exact WooCommerce ZIP.")
require("$WP plugin install \"$PLUGIN_ZIP\" --force" in (promotion / "scripts/common.sh").read_text(encoding="utf-8"), "Promotion lane bypasses normal WordPress plugin installation.")
require("YSAI_FAKE_GEMINI_URL: http://fake-gemini:8787" in promotion_compose, "Promotion WP-CLI cannot control the deterministic provider.")
readiness_hardening = (promotion / "scripts/readiness-hardening.php").read_text(encoding="utf-8")
for token in (
    "runtime_access_unavailable", "runtime_access_authentication",
    "runtime_probe_upstream_unavailable", "authentication_error",
    "proof_checked_at", "proof_expires_at", "providerCalls !== 2",
):
    require(token in readiness_hardening, f"Promotion readiness-hardening probe omits: {token}")
for bootstrap_name, artifact in (
    ("bootstrap-clean.sh", "clean-readiness-hardening.json"),
    ("bootstrap-upgrade.sh", "upgrade-readiness-hardening.json"),
):
    bootstrap_source = (promotion / "scripts" / bootstrap_name).read_text(encoding="utf-8")
    require("readiness-hardening.php" in bootstrap_source and artifact in bootstrap_source,
            f"Promotion {bootstrap_name} does not capture readiness-hardening evidence.")
summarizer_source = (promotion / "scripts/summarize.py").read_text(encoding="utf-8")
for token in ("readiness_hardening_ok", "clean-readiness-hardening.json", "upgrade-readiness-hardening.json"):
    require(token in summarizer_source, f"Promotion evidence closer omits readiness hardening: {token}")
promotion_self_test = (promotion / "scripts/self-test.py").read_text(encoding="utf-8")
require('invalid_readiness["proof_preserved"] = False' in promotion_self_test,
        "Promotion evidence self-test does not reject forged readiness preservation.")

promotion_runner = (ROOT / "scripts/run-woocommerce-promotion-gate.sh").read_text(encoding="utf-8")
release_runner = (ROOT / "scripts/run-release-gate.sh").read_text(encoding="utf-8")
stage_h_runner = (ROOT / "scripts/run-stage-h-gate.py").read_text(encoding="utf-8")
matrix_runner = (ROOT / "scripts/run-woocommerce-compatibility-matrix.sh").read_text(encoding="utf-8")
for token in (
    "--woocommerce-version", "--woocommerce-zip", "promotion_tested",
    "container_runtime_unavailable", "exit \"$EX_UNAVAILABLE\"",
    "bootstrap-clean.sh", "bootstrap-upgrade.sh", "verify-package.py",
):
    require(token in promotion_runner, f"Packaged promotion runner omits: {token}")
require("source: \"wordpress.org" not in (promotion / "scripts/verify-package.py").read_text(encoding="utf-8"), "Promotion verification still permits a floating WooCommerce download.")
for token in ("run-stage-h-gate.py", "--mode", "publication"):
    require(token in release_runner, f"One-command release gate does not delegate publication authority: {token}")
for token in ("--woocommerce-zip", "scripts/quality-gate.sh", "compare_archives(", "run-woocommerce-promotion-gate.sh"):
    require(token in stage_h_runner, f"Stage H release authority omits: {token}")
for token in ("promotion_tested", "VERSION=PATH", "matrix-status.json", "woocommerce_package_sha256"):
    require(token in matrix_runner, f"WooCommerce compatibility matrix omits: {token}")

verify_package = (promotion / "scripts/verify-package.py").read_text(encoding="utf-8")
for token in (
    "COMPATIBILITY_MEMBER", "woocommerce_compatibility", "promotion_tested",
    "WC requires at least", "WC tested up to", "sha256",
):
    require(token in verify_package, f"Package verifier omits compatibility authority: {token}")
installed_assertion = (promotion / "scripts/assert-current-install.php").read_text(encoding="utf-8")
for token in (
    "WooCommerceCompatibility::fromPluginContract()",
    "isInstalledVersionPromotionTested()",
    "woocommerce_compatibility_status",
    "woocommerce_runtime_contract",
):
    require(token in installed_assertion, f"Installed-package assertion omits: {token}")

promotion_spec = (INTEGRATION / "tests/specs/promotion.spec.js").read_text(encoding="utf-8")
for token in (
    "installed artifact boots with no fabricated assistant turn",
    "exact model clarification survives refresh and resolves one idempotent Woo mutation",
    "invalid model question fails closed without a server-authored replacement",
    "model_step_id", "tool_call_id", "client_turn_id", "accepted_at",
):
    require(token in promotion_spec, f"Promotion scenario coverage omits: {token}")

promotion_surface = "\n".join(
    path.read_text(encoding="utf-8", errors="replace")
    for path in promotion.rglob("*")
    if path.is_file()
    and "runtime" not in path.relative_to(promotion).parts
    and "artifacts" not in path.relative_to(promotion).parts
    and "__pycache__" not in path.relative_to(promotion).parts
    and path.suffix != ".pyc"
) + "\n" + promotion_runner + "\n" + release_runner + "\n" + matrix_runner
for retired in (
    "GeminiConnectionProbe", "ProtocolReadiness", "ProtocolProbeTiming",
    "ProtocolVerificationSuperseded", "BootRuntimeProof",
    "ysai_protocol_readiness", "ysai_boot_runtime_proof",
):
    require(retired not in promotion_surface, f"Retired readiness authority returned through promotion infrastructure: {retired}")
for required in (
    "src/Infrastructure/Gemini/GeminiRuntimeProbe.php",
    "src/Infrastructure/Gemini/GeminiRuntimeReadiness.php",
    "src/Infrastructure/Gemini/RuntimeProbeContract.php",
    "src/Infrastructure/Gemini/RuntimeProbeTiming.php",
    "src/Infrastructure/Gemini/RuntimeProbeFailureMapper.php",
    "src/Infrastructure/Gemini/RuntimeReadinessPolicy.php",
    "src/Infrastructure/Gemini/RuntimeReadinessStateStore.php",
    "src/Application/Readiness/RuntimeReadinessFailurePolicy.php",
    "src/Infrastructure/WooCommerce/WooSessionInternalsAdapter.php",
    "src/Infrastructure/WooCommerce/Internals/WooCoreStructureProbe.php",
    "src/Infrastructure/WooCommerce/Internals/WooSessionStorageInternals.php",
    "src/Infrastructure/WooCommerce/Internals/WooCartHookTopology.php",
    "src/Infrastructure/WooCommerce/Internals/WooCartIdentityInternals.php",
    "src/Infrastructure/WooCommerce/Internals/WooPersistentCartInternals.php",
    "src/Infrastructure/WooCommerce/WooCommerceCompatibility.php",
):
    require((ROOT / required).is_file(), f"Current cumulative architecture component is missing: {required}")
require("provider_requests" in (promotion / "scripts/common.sh").read_text(encoding="utf-8"), "Promotion readiness does not verify the two-request proof.")
require("provider_access" in (promotion / "scripts/common.sh").read_text(encoding="utf-8"), "Promotion readiness omits provider access evidence.")
require("structured_tool" in (promotion / "scripts/common.sh").read_text(encoding="utf-8"), "Promotion readiness omits structured-tool evidence.")

print(f"INTEGRATION SOURCE VERIFIED: {len(specs)} spec files, {scenario_count} scenarios; source-mounted and artifact-first authorities are complete.")
