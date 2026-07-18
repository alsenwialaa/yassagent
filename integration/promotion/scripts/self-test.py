#!/usr/bin/env python3
"""Exercise the promotion evidence closer without requiring a container runtime."""
from __future__ import annotations

import hashlib
import json
import re
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[3]
PROMOTION = ROOT / "integration" / "promotion"
SUMMARIZER = PROMOTION / "scripts" / "summarize.py"


def write_json(path: Path, payload: Any) -> None:
    path.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )


def scenario_count() -> int:
    return sum(
        len(re.findall(r"^\s*test\(", path.read_text(encoding="utf-8"), flags=re.MULTILINE))
        for path in sorted((ROOT / "integration" / "tests" / "specs").glob("*.spec.js"))
    )


def build_evidence(artifacts: Path) -> int:
    count = scenario_count()
    if count <= 0:
        raise RuntimeError("Promotion self-test discovered no Playwright scenarios.")

    version_lock = json.loads((ROOT / "integration" / "version-lock.json").read_text(encoding="utf-8"))
    package_sha = "a" * 64
    plugin_version = "1.0.0-self-test"
    members = {
        "yassin-ai-assistant.php": {
            "sha256": "b" * 64,
            "bytes": 123,
            "executable": False,
        }
    }
    package_manifest = {
        "manifest_version": 1,
        "plugin": {
            "kind": "plugin",
            "file": "yassin-ai-assistant.zip",
            "sha256": package_sha,
            "version": plugin_version,
            "files": len(members),
            "root": "yassin-ai-assistant",
            "woocommerce_compatibility": {
                "schema_version": 1,
                "minimum": "10.9.4",
                "maximum_exclusive": "11.0.0",
                "tested_up_to": version_lock["woocommerce"],
                "promotion_tested": [version_lock["woocommerce"]],
                "wordpress_minimum": version_lock["wordpress"],
                "runtime_contract": "woocommerce-10.9-core-session-v1",
            },
            "members": members,
        },
        "woocommerce": {
            "kind": "woocommerce",
            "version": version_lock["woocommerce"],
            "sha256": "d" * 64,
            "file": "woocommerce.zip",
            "files": 1,
            "root": "woocommerce",
        },
    }
    write_json(artifacts / "package-manifest.json", package_manifest)
    (artifacts / "installed-package.sha256").write_text(
        f"{package_sha}  yassin-ai-assistant.zip\n", encoding="utf-8"
    )
    (artifacts / "legacy-package.sha256").write_text(
        f"{'c' * 64}  yassin-ai-assistant-legacy.zip\n", encoding="utf-8"
    )

    tree = {"ok": True, "files": len(members), "members": members}
    for name in (
        "installed-plugin-tree.json",
        "installed-plugin-tree-clean.json",
        "installed-plugin-tree-upgrade.json",
    ):
        write_json(artifacts / name, tree)

    write_json(
        artifacts / "environment-host.json",
        {"plugin_package_sha256": package_sha, "plugin_version": plugin_version},
    )
    wordpress = {
        "wordpress_version": str(version_lock["wordpress"]),
        "woocommerce_version": str(version_lock["woocommerce"]),
        "plugin_version": plugin_version,
        "php_version": f"{version_lock['php']}.99",
        "database_server": f"{version_lock['mariadb']}-MariaDB",
        "plugin_active": True,
        "multisite": False,
    }
    write_json(artifacts / "environment-wordpress.json", wordpress)
    write_json(artifacts / "environment-wordpress-upgrade.json", wordpress)
    write_json(
        artifacts / "environment-browser.json",
        {
            "playwright_version": str(version_lock["playwright"]),
            "browser_version": "Chromium self-test",
            "plugin_package_sha256": package_sha,
            "plugin_version": plugin_version,
        },
    )

    (artifacts / "container-runtime.txt").write_text("self-test compose\n", encoding="utf-8")
    compose_payload = (PROMOTION / "compose.yaml").read_bytes()
    (artifacts / "compose-definition.sha256").write_text(
        hashlib.sha256(compose_payload).hexdigest() + "  compose.yaml\n",
        encoding="utf-8",
    )
    (artifacts / "compose-images.txt").write_text(
        "\n".join(
            (
                f"mariadb:{version_lock['mariadb']}",
                f"wordpress:{version_lock['wordpress']}-php{version_lock['php']}-apache",
                f"wordpress:cli-{version_lock['wp_cli']}-php{version_lock['php']}",
            )
        )
        + "\n",
        encoding="utf-8",
    )
    (artifacts / "compose-images-runtime.txt").write_text(
        "self-test runtime image inventory\n", encoding="utf-8"
    )

    specs = []
    for index in range(1, count + 1):
        specs.append(
            {
                "title": f"promotion self-test scenario {index:03d}",
                "file": f"integration/tests/specs/self-test-{index:03d}.spec.js",
                "tests": [
                    {
                        "expectedStatus": "passed",
                        "results": [{"status": "passed"}],
                    }
                ],
            }
        )
        trace_dir = artifacts / "test-results" / f"scenario-{index:03d}"
        trace_dir.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(trace_dir / "trace.zip", "w") as archive:
            archive.writestr("trace.txt", f"scenario {index}\n")
    write_json(artifacts / "playwright-results.json", {"suites": [{"specs": specs}]})
    (artifacts / "junit.xml").write_text(
        f'<testsuites tests="{count}" failures="0" errors="0" skipped="0"></testsuites>\n',
        encoding="utf-8",
    )

    regular_ok = (
        "clean-install.json",
        "clean-boot.json",
        "collection-main.json",
        "database-schema.json",
        "woocommerce-status.json",
        "uninstall-retain.json",
        "uninstall-delete.json",
        "upgrade-before.json",
        "upgrade-install.json",
        "upgrade-boot.json",
        "upgrade-result.json",
        "collection-upgrade.json",
        "database-schema-upgrade.json",
        "woocommerce-status-upgrade.json",
    )
    for name in regular_ok:
        write_json(artifacts / name, {"ok": True})
    readiness_hardening = {
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
    write_json(artifacts / "clean-readiness-hardening.json", readiness_hardening)
    write_json(artifacts / "upgrade-readiness-hardening.json", readiness_hardening)
    for name in (
        "woocommerce-critical-logs.json",
        "woocommerce-critical-logs-upgrade.json",
    ):
        write_json(artifacts / name, {"entries": []})
    for name in (
        "wordpress-debug.log",
        "wordpress-debug-upgrade.log",
        "wordpress-debug-after-uninstall.log",
    ):
        (artifacts / name).write_text("", encoding="utf-8")
    return count


def invoke(artifacts: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        (
            sys.executable,
            str(SUMMARIZER),
            "--artifacts",
            str(artifacts),
            "--source-root",
            str(ROOT),
            "--build-status",
            "0",
            "--runner-status",
            "0",
            "--main-status",
            "0",
            "--upgrade-status",
            "0",
        ),
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="ysai-promotion-self-test-") as temporary:
        artifacts = Path(temporary)
        count = build_evidence(artifacts)

        accepted = invoke(artifacts)
        accepted_status = json.loads((artifacts / "promotion-status.json").read_text(encoding="utf-8"))
        if accepted.returncode != 0 or accepted_status.get("status") != "passed":
            raise SystemExit("Promotion summarizer rejected complete evidence:\n" + accepted.stdout)
        if accepted_status.get("browser_traces") != count:
            raise SystemExit("Promotion summarizer did not retain one trace per scenario.")

        clean_readiness = artifacts / "clean-readiness-hardening.json"
        valid_readiness = json.loads(clean_readiness.read_text(encoding="utf-8"))
        invalid_readiness = dict(valid_readiness)
        invalid_readiness["proof_preserved"] = False
        write_json(clean_readiness, invalid_readiness)
        rejected_readiness = invoke(artifacts)
        readiness_status = json.loads((artifacts / "promotion-status.json").read_text(encoding="utf-8"))
        readiness_failures = readiness_status.get("failures", [])
        if rejected_readiness.returncode == 0 or readiness_status.get("status") != "failed":
            raise SystemExit("Promotion summarizer accepted invalid readiness-hardening evidence.")
        if not any("clean_readiness_hardening" in str(failure) for failure in readiness_failures):
            raise SystemExit("Promotion summarizer rejected readiness evidence for the wrong reason:\n" + rejected_readiness.stdout)
            raise SystemExit("Promotion summarizer rejected readiness evidence for the wrong reason:\n" + rejected_readiness.stdout)
        write_json(clean_readiness, valid_readiness)

        final_trace = artifacts / "test-results" / f"scenario-{count:03d}" / "trace.zip"
        final_trace.unlink()
        rejected = invoke(artifacts)
        rejected_status = json.loads((artifacts / "promotion-status.json").read_text(encoding="utf-8"))
        failures = rejected_status.get("failures", [])
        if rejected.returncode == 0 or rejected_status.get("status") != "failed":
            raise SystemExit("Promotion summarizer accepted incomplete trace evidence.")
        if not any("browser trace coverage is incomplete" in str(failure) for failure in failures):
            raise SystemExit("Promotion summarizer failed for the wrong reason:\n" + rejected.stdout)

    print(
        "PROMOTION EVIDENCE SELF-TEST PASSED: complete evidence accepted; "
        "invalid readiness evidence and incomplete trace coverage rejected."
    )


if __name__ == "__main__":
    main()
