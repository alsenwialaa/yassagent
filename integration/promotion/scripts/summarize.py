#!/usr/bin/env python3
"""Create closed promotion evidence and return non-zero on any failed gate."""
from __future__ import annotations

import argparse
import json
import re
import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any, Iterable

REQUIRED = (
    "installed-package.sha256", "package-manifest.json", "legacy-package.sha256",
    "installed-plugin-tree.json", "installed-plugin-tree-clean.json",
    "installed-plugin-tree-upgrade.json", "environment-host.json",
    "environment-wordpress.json", "environment-wordpress-upgrade.json",
    "environment-browser.json",
    "container-runtime.txt", "compose-definition.sha256", "compose-images.txt",
    "compose-images-runtime.txt", "junit.xml", "playwright-results.json",
    "clean-install.json", "clean-boot.json", "clean-readiness-hardening.json", "collection-main.json",
    "database-schema.json", "woocommerce-status.json",
    "woocommerce-critical-logs.json", "wordpress-debug.log",
    "uninstall-retain.json", "uninstall-delete.json", "upgrade-before.json",
    "upgrade-install.json", "upgrade-boot.json", "upgrade-readiness-hardening.json", "upgrade-result.json",
    "collection-upgrade.json", "database-schema-upgrade.json",
    "woocommerce-status-upgrade.json", "woocommerce-critical-logs-upgrade.json",
    "wordpress-debug-upgrade.log",
)


def read_json(path: Path, default: Any = None) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return default


def json_ok(path: Path) -> bool:
    data = read_json(path)
    return isinstance(data, dict) and data.get("ok") is True


def readiness_hardening_ok(path: Path) -> bool:
    data = read_json(path)
    expected = {
        "ok": True,
        "state_schema": 2,
        "proof_ttl_seconds": 2592000,
        "transient_http_status": 503,
        "transient_code": "runtime_probe_upstream_unavailable",
        "proof_preserved": True,
        "proof_checked_at_unchanged": True,
        "proof_expires_at_unchanged": True,
        "deterministic_http_status": 422,
        "deterministic_code": "authentication_error",
        "proof_revoked": True,
        "recovery_http_status": 200,
        "recovery_provider_requests": 2,
        "ready_after_recovery": True,
    }
    return isinstance(data, dict) and data == expected


def iter_specs(suites: Iterable[dict[str, Any]]) -> Iterable[dict[str, Any]]:
    for suite in suites:
        if not isinstance(suite, dict):
            continue
        for spec in suite.get("specs", []):
            if isinstance(spec, dict):
                yield spec
        nested = suite.get("suites", [])
        if isinstance(nested, list):
            yield from iter_specs(nested)


def playwright_summary(payload: Any) -> tuple[list[dict[str, Any]], dict[str, int]]:
    scenarios: list[dict[str, Any]] = []
    counts = {"total": 0, "passed": 0, "failed": 0, "skipped": 0, "flaky": 0}
    suites = payload.get("suites", []) if isinstance(payload, dict) else []
    for spec in iter_specs(suites if isinstance(suites, list) else []):
        title = str(spec.get("title", ""))
        file_name = str(spec.get("file", ""))
        tests = spec.get("tests", [])
        if not isinstance(tests, list):
            continue
        for test in tests:
            if not isinstance(test, dict):
                continue
            expected = str(test.get("expectedStatus", "passed"))
            results = test.get("results", [])
            statuses = [str(row.get("status", "")) for row in results if isinstance(row, dict)]
            status = statuses[-1] if statuses else "skipped"
            if expected == "skipped" or status == "skipped":
                category = "skipped"
            elif status == "passed" and len(statuses) == 1:
                category = "passed"
            elif status == "passed":
                category = "flaky"
            else:
                category = "failed"
            counts["total"] += 1
            counts[category] += 1
            scenarios.append({
                "title": title,
                "file": file_name,
                "status": category,
                "attempt_statuses": statuses,
            })
    return scenarios, counts


def junit_summary(path: Path) -> dict[str, int]:
    counts = {"total": 0, "failed": 0, "errors": 0, "skipped": 0}
    try:
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError):
        return counts

    def integer(element: ET.Element, name: str) -> int:
        try:
            return int(element.attrib.get(name, "0"))
        except (TypeError, ValueError):
            return 0

    if root.tag.endswith("testsuite"):
        suites = [root]
    else:
        suites = [element for element in root if element.tag.endswith("testsuite")]
        if root.attrib.get("tests") is not None:
            return {
                "total": integer(root, "tests"),
                "failed": integer(root, "failures"),
                "errors": integer(root, "errors"),
                "skipped": integer(root, "skipped"),
            }
    for suite in suites:
        counts["total"] += integer(suite, "tests")
        counts["failed"] += integer(suite, "failures")
        counts["errors"] += integer(suite, "errors")
        counts["skipped"] += integer(suite, "skipped")
    return counts


def zip_traces(root: Path, target: Path) -> int:
    traces = sorted((root / "test-results").rglob("trace.zip")) if (root / "test-results").exists() else []
    target.unlink(missing_ok=True)
    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        if traces:
            for index, trace in enumerate(traces, 1):
                archive.write(trace, f"trace-{index:03d}.zip")
        else:
            archive.writestr("README.txt", "No trace was produced; completed promotion treats this as failure.\n")
    return len(traces)


def debug_violations(text: str) -> list[str]:
    patterns = (
        r"PHP Fatal error", r"Uncaught (?:Error|Exception|Throwable)",
        r"WordPress database error", r"_doing_it_wrong.*(?:yassin-ai-assistant|YassinStore)",
        r"(?:yassin-ai-assistant|YassinStore).*_doing_it_wrong",
    )
    return [line[:2000] for line in text.splitlines()
            if any(re.search(pattern, line, flags=re.IGNORECASE) for pattern in patterns)]


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--artifacts", type=Path, required=True)
    parser.add_argument("--source-root", type=Path, required=True)
    parser.add_argument("--build-status", type=int, required=True)
    parser.add_argument("--runner-status", type=int, required=True)
    parser.add_argument("--main-status", type=int, required=True)
    parser.add_argument("--upgrade-status", type=int, required=True)
    args = parser.parse_args()

    artifacts = args.artifacts.resolve()
    source_root = args.source_root.resolve()
    missing = [name for name in REQUIRED if not (artifacts / name).is_file()]

    playwright = read_json(artifacts / "playwright-results.json", {})
    scenarios, counts = playwright_summary(playwright)
    junit = junit_summary(artifacts / "junit.xml")
    trace_count = zip_traces(artifacts, artifacts / "browser-trace.zip")
    (artifacts / "scenario-results.json").write_text(
        json.dumps({"counts": counts, "scenarios": scenarios}, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )

    package_manifest = read_json(artifacts / "package-manifest.json", {})
    image_file = artifacts / "compose-images.txt"
    runtime_image_file = artifacts / "compose-images-runtime.txt"
    environment = {
        "package": package_manifest,
        "version_lock": read_json(source_root / "integration/version-lock.json", {}),
        "host": read_json(artifacts / "environment-host.json", {}),
        "wordpress_main": read_json(artifacts / "environment-wordpress.json", {}),
        "wordpress_upgrade": read_json(artifacts / "environment-wordpress-upgrade.json", {}),
        "browser": read_json(artifacts / "environment-browser.json", {}),
        "configured_images": image_file.read_text(encoding="utf-8", errors="replace").splitlines() if image_file.is_file() else [],
        "runtime_image_inventory": runtime_image_file.read_text(encoding="utf-8", errors="replace").splitlines() if runtime_image_file.is_file() else [],
    }
    (artifacts / "environment.json").write_text(
        json.dumps(environment, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )

    critical_entries: list[Any] = []
    for name in ("woocommerce-critical-logs.json", "woocommerce-critical-logs-upgrade.json"):
        payload = read_json(artifacts / name, {})
        if isinstance(payload, dict) and isinstance(payload.get("entries"), list):
            critical_entries.extend(payload["entries"])
    debug_text = ""
    for name in ("wordpress-debug.log", "wordpress-debug-upgrade.log", "wordpress-debug-after-uninstall.log"):
        path = artifacts / name
        if path.is_file():
            debug_text += path.read_text(encoding="utf-8", errors="replace") + "\n"
    debug_errors = debug_violations(debug_text)

    spec_files = sorted((source_root / "integration/tests/specs").glob("*.spec.js"))
    expected_scenarios = sum(
        len(re.findall(r"^\s*test\(", path.read_text(encoding="utf-8"), flags=re.MULTILINE))
        for path in spec_files
    )

    plugin_manifest = package_manifest.get("plugin", {}) if isinstance(package_manifest, dict) else {}
    manifest_members = plugin_manifest.get("members", {}) if isinstance(plugin_manifest, dict) else {}
    compatibility = plugin_manifest.get("woocommerce_compatibility", {}) if isinstance(plugin_manifest, dict) else {}
    woo_manifest = package_manifest.get("woocommerce", {}) if isinstance(package_manifest, dict) else {}
    selected_woocommerce = str(woo_manifest.get("version", "")) if isinstance(woo_manifest, dict) else ""
    selected_woocommerce_sha = str(woo_manifest.get("sha256", "")) if isinstance(woo_manifest, dict) else ""

    def tree_matches(name: str) -> bool:
        tree = read_json(artifacts / name, {})
        members = tree.get("members", {}) if isinstance(tree, dict) else {}
        return (isinstance(manifest_members, dict) and bool(manifest_members)
                and isinstance(members, dict) and members == manifest_members
                and tree.get("ok") is True and int(tree.get("files", -1)) == len(manifest_members))

    installed_tree_matches = tree_matches("installed-plugin-tree.json")
    clean_tree_matches = tree_matches("installed-plugin-tree-clean.json")
    upgrade_tree_matches = tree_matches("installed-plugin-tree-upgrade.json")
    checksum_matches = False
    checksum_path = artifacts / "installed-package.sha256"
    if checksum_path.is_file() and isinstance(plugin_manifest, dict):
        tokens = checksum_path.read_text(encoding="utf-8", errors="replace").split()
        checksum_matches = bool(tokens) and tokens[0] == plugin_manifest.get("sha256")

    version_lock = environment.get("version_lock", {}) if isinstance(environment, dict) else {}
    host_environment = environment.get("host", {}) if isinstance(environment, dict) else {}
    wordpress_main = environment.get("wordpress_main", {}) if isinstance(environment, dict) else {}
    wordpress_upgrade = environment.get("wordpress_upgrade", {}) if isinstance(environment, dict) else {}
    browser_environment = environment.get("browser", {}) if isinstance(environment, dict) else {}
    configured_images = environment.get("configured_images", []) if isinstance(environment, dict) else []
    runtime_images = environment.get("runtime_image_inventory", []) if isinstance(environment, dict) else []

    lifecycle = {
        "clean_install": json_ok(artifacts / "clean-install.json"),
        "clean_boot": json_ok(artifacts / "clean-boot.json"),
        "clean_readiness_hardening": readiness_hardening_ok(artifacts / "clean-readiness-hardening.json"),
        "uninstall_retain": json_ok(artifacts / "uninstall-retain.json"),
        "uninstall_delete": json_ok(artifacts / "uninstall-delete.json"),
        "upgrade_install": json_ok(artifacts / "upgrade-install.json"),
        "upgrade_boot": json_ok(artifacts / "upgrade-boot.json"),
        "upgrade_readiness_hardening": readiness_hardening_ok(artifacts / "upgrade-readiness-hardening.json"),
        "upgrade_result": json_ok(artifacts / "upgrade-result.json"),
    }

    failures: list[str] = []
    if missing: failures.append("missing artifacts: " + ", ".join(missing))
    expected_contract_keys = {
        "schema_version", "minimum", "maximum_exclusive", "tested_up_to",
        "promotion_tested", "wordpress_minimum", "runtime_contract",
    }
    if package_manifest.get("manifest_version") != 1:
        failures.append("package manifest version is invalid")
    if not isinstance(compatibility, dict) or set(compatibility) != expected_contract_keys:
        failures.append("plugin package compatibility contract is missing or not closed")
    else:
        promoted = compatibility.get("promotion_tested", [])
        if not isinstance(promoted, list) or selected_woocommerce not in promoted:
            failures.append("selected WooCommerce package is not in the promotion-tested contract")
        if compatibility.get("wordpress_minimum") != version_lock.get("wordpress"):
            failures.append("promotion WordPress pin differs from the plugin compatibility contract")
        if compatibility.get("tested_up_to") != (promoted[-1] if promoted else None):
            failures.append("tested-up-to evidence differs from the promotion-tested set")
    if re.fullmatch(r"[a-f0-9]{64}", selected_woocommerce_sha) is None:
        failures.append("exact WooCommerce package checksum is missing or malformed")
    for name, status in (("container image build", args.build_status), ("main lifecycle", args.main_status),
                         ("Playwright runner", args.runner_status), ("upgrade", args.upgrade_status)):
        if status != 0: failures.append(f"{name} phase exited {status}")
    if counts["total"] == 0: failures.append("no Playwright scenarios were recorded")
    if expected_scenarios == 0: failures.append("no Playwright scenarios were discovered from source")
    elif counts["total"] != expected_scenarios:
        failures.append(f"Playwright execution was incomplete: expected {expected_scenarios}, recorded {counts['total']}")
    if counts["failed"] or counts["skipped"] or counts["flaky"]:
        failures.append(f"Playwright results are not clean: failed={counts['failed']} skipped={counts['skipped']} flaky={counts['flaky']}")
    scenario_ids = [f"{row.get('file', '')}::{row.get('title', '')}" for row in scenarios]
    if len(set(scenario_ids)) != len(scenario_ids):
        failures.append("Playwright JSON contains duplicate scenario identities")
    if junit["total"] != counts["total"] or junit["failed"] or junit["errors"] or junit["skipped"]:
        failures.append(
            "JUnit and Playwright JSON disagree or JUnit is not clean: "
            f"junit={junit} playwright_total={counts['total']}"
        )
    if trace_count == 0: failures.append("no browser trace was produced")
    elif counts["total"] > 0 and trace_count != counts["total"]:
        failures.append(f"browser trace coverage is incomplete: expected {counts['total']}, found {trace_count}")
    if not installed_tree_matches: failures.append("canonical installed plugin bytes do not exactly match the ZIP manifest")
    if not clean_tree_matches: failures.append("clean-install plugin bytes do not exactly match the ZIP manifest")
    if not upgrade_tree_matches: failures.append("upgraded plugin bytes do not exactly match the ZIP manifest")
    if not checksum_matches: failures.append("installed checksum differs from the ZIP manifest")

    compose_hash_file = artifacts / "compose-definition.sha256"
    compose_hash_tokens = compose_hash_file.read_text(encoding="utf-8", errors="replace").split() if compose_hash_file.is_file() else []
    expected_compose_hash = __import__("hashlib").sha256(
        (source_root / "integration/promotion/compose.yaml").read_bytes()
    ).hexdigest()
    if not compose_hash_tokens or compose_hash_tokens[0] != expected_compose_hash:
        failures.append("recorded compose definition checksum differs from source")

    configured_text = "\n".join(str(row) for row in configured_images)
    for expected_image in (
        f"mariadb:{version_lock.get('mariadb', '')}",
        f"wordpress:{version_lock.get('wordpress', '')}-php{version_lock.get('php', '')}-apache",
        f"wordpress:cli-{version_lock.get('wp_cli', '')}-php{version_lock.get('php', '')}",
    ):
        if expected_image.endswith(":") or expected_image not in configured_text:
            failures.append(f"configured compose image evidence is missing {expected_image}")
    if not runtime_images:
        failures.append("runtime container image inventory is empty")

    plugin_sha = plugin_manifest.get("sha256") if isinstance(plugin_manifest, dict) else None
    plugin_version = plugin_manifest.get("version") if isinstance(plugin_manifest, dict) else None
    if host_environment.get("plugin_package_sha256") != plugin_sha or host_environment.get("plugin_version") != plugin_version:
        failures.append("host environment package identity differs from the verified manifest")
    for phase_name, wp_environment in (("clean", wordpress_main), ("upgrade", wordpress_upgrade)):
        if not isinstance(wp_environment, dict):
            failures.append(f"{phase_name} WordPress environment evidence is malformed")
            continue
        wordpress_expected = str(version_lock.get("wordpress", ""))
        wordpress_actual = str(wp_environment.get("wordpress_version", ""))
        if (not wordpress_expected
                or not (wordpress_actual == wordpress_expected
                        or wordpress_actual.startswith(wordpress_expected + "."))):
            failures.append(f"{phase_name} environment WordPress version differs from the pinned line")
        expected_values = {
            "woocommerce_version": selected_woocommerce,
            "plugin_version": plugin_version,
        }
        for key, expected in expected_values.items():
            if not expected or str(wp_environment.get(key, "")) != str(expected):
                failures.append(f"{phase_name} environment {key} differs from the pinned value")
        php_version = str(wp_environment.get("php_version", ""))
        if not php_version.startswith(str(version_lock.get("php", "")) + "."):
            failures.append(f"{phase_name} environment PHP version differs from the pinned line")
        database_server = str(wp_environment.get("database_server", ""))
        if not database_server.startswith(str(version_lock.get("mariadb", ""))):
            failures.append(f"{phase_name} database version differs from the pinned release")
        if wp_environment.get("plugin_active") is not True or wp_environment.get("multisite") is not False:
            failures.append(f"{phase_name} environment plugin activation or single-site assertion failed")

    if browser_environment.get("playwright_version") != version_lock.get("playwright"):
        failures.append("browser environment Playwright version differs from the pinned release")
    if not str(browser_environment.get("browser_version", "")):
        failures.append("browser environment did not record the Chromium version")
    if browser_environment.get("plugin_package_sha256") != plugin_sha or browser_environment.get("plugin_version") != plugin_version:
        failures.append("browser environment package identity differs from the verified manifest")

    for name, passed in lifecycle.items():
        if not passed: failures.append(f"lifecycle assertion failed or is missing: {name}")
    if critical_entries: failures.append(f"WooCommerce produced {len(critical_entries)} critical log entries")
    if debug_errors: failures.append(f"WordPress debug log contains {len(debug_errors)} fatal/database/plugin misuse entries")

    status = "passed" if not failures else "failed"
    result = {
        "status": status, "failures": failures, "playwright": counts, "junit": junit,
        "expected_playwright_scenarios": expected_scenarios, "browser_traces": trace_count,
        "installed_tree_matches_package": installed_tree_matches,
        "clean_installed_tree_matches_package": clean_tree_matches,
        "upgrade_installed_tree_matches_package": upgrade_tree_matches,
        "installed_checksum_matches_package": checksum_matches,
        "woocommerce_promotion_contract": compatibility,
        "woocommerce_package_version": selected_woocommerce,
        "woocommerce_package_sha256": selected_woocommerce_sha,
        "environment_identity": {
            "host_package_matches": host_environment.get("plugin_package_sha256") == plugin_sha,
            "playwright_version": browser_environment.get("playwright_version", ""),
            "browser_version": browser_environment.get("browser_version", ""),
        },
        "lifecycle": lifecycle, "woocommerce_critical_entries": critical_entries,
        "wordpress_debug_violations": debug_errors,
    }
    (artifacts / "promotion-status.json").write_text(
        json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    lines = ["# WooCommerce promotion summary", "", f"**Status:** {status.upper()}", "",
             "## Browser scenarios", "", f"- Discovered: {expected_scenarios}", f"- Total: {counts['total']}",
             f"- Passed: {counts['passed']}", f"- Failed: {counts['failed']}", f"- Skipped: {counts['skipped']}",
             f"- Flaky: {counts['flaky']}", f"- JUnit total: {junit['total']}",
             f"- JUnit failures/errors/skips: {junit['failed']}/{junit['errors']}/{junit['skipped']}",
             f"- Trace files: {trace_count}", "", "## Lifecycle", ""]
    lines.extend(f"- {name.replace('_', ' ').title()}: {'PASS' if passed else 'FAIL'}" for name, passed in lifecycle.items())
    lines += ["", "## Package identity", "",
              f"- Canonical installed file tree matches ZIP: {'PASS' if installed_tree_matches else 'FAIL'}",
              f"- Clean-install file tree matches ZIP: {'PASS' if clean_tree_matches else 'FAIL'}",
              f"- Upgrade file tree matches ZIP: {'PASS' if upgrade_tree_matches else 'FAIL'}",
              f"- Installed checksum matches ZIP: {'PASS' if checksum_matches else 'FAIL'}",
              f"- Exact WooCommerce package: {selected_woocommerce} ({selected_woocommerce_sha})", "",
              "## Environment", "",
              f"- Playwright: {browser_environment.get('playwright_version', '')}",
              f"- Chromium: {browser_environment.get('browser_version', '')}",
              f"- Clean WordPress/WooCommerce: {wordpress_main.get('wordpress_version', '')}/{wordpress_main.get('woocommerce_version', '')}",
              f"- Upgrade WordPress/WooCommerce: {wordpress_upgrade.get('wordpress_version', '')}/{wordpress_upgrade.get('woocommerce_version', '')}",
              "", "## Diagnostics", "",
              f"- WooCommerce critical entries: {len(critical_entries)}",
              f"- WordPress fatal/database/plugin-misuse entries: {len(debug_errors)}"]
    if failures:
        lines += ["", "## Failures", ""] + [f"- {failure}" for failure in failures]
    lines.append("")
    (artifacts / "promotion-summary.md").write_text("\n".join(lines), encoding="utf-8")
    if failures: raise SystemExit(1)


if __name__ == "__main__":
    main()
