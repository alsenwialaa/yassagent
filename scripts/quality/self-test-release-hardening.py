#!/usr/bin/env python3
"""Prove the Stage H ledger rejects blockers, authority drift, and forged evidence."""

from __future__ import annotations

import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VERIFIER = ROOT / "scripts/quality/verify-release-hardening.py"
POLICY = ROOT / "config/quality/release-hardening-policy.json"
LEDGER = ROOT / "config/quality/release-hardening-findings.json"


def run(
    policy: Path,
    ledger: Path,
    mode: str,
    evidence: Path | None = None,
) -> subprocess.CompletedProcess[str]:
    command = [
        sys.executable,
        str(VERIFIER),
        "--root",
        str(ROOT),
        "--policy",
        str(policy),
        "--ledger",
        str(ledger),
        "--mode",
        mode,
    ]
    if evidence is not None:
        command += ["--evidence-dir", str(evidence)]
    return subprocess.run(
        command,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
        timeout=60,
    )


def expect(result: subprocess.CompletedProcess[str], expected: int, label: str) -> None:
    if result.returncode != expected:
        raise SystemExit(
            f"{label}: expected exit {expected}, got {result.returncode}\n"
            f"stdout:\n{result.stdout}\nstderr:\n{result.stderr}"
        )


def write(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def publication_ledger() -> dict[str, object]:
    ledger = json.loads(LEDGER.read_text(encoding="utf-8"))
    for finding in ledger["findings"]:
        if finding["id"] == "H-104":
            finding["status"] = "resolved"
            finding["review_by"] = None
    return ledger


def valid_evidence(policy: dict[str, object], directory: Path) -> None:
    lock_hash = policy["dependency_locks"]["composer.lock"]
    write(
        directory / policy["external_evidence"]["clean_composer_install"],
        {
            "schema": 1,
            "status": "passed",
            "method": "clean",
            "composer_lock_sha256": lock_hash,
            "package_count": 41,
            "package_set_sha256": "a" * 64,
        },
    )
    write(
        directory / policy["external_evidence"]["composer_advisory_audit"],
        {
            "schema": 1,
            "status": "passed",
            "composer_lock_sha256": lock_hash,
            "advisory_count": 0,
            "abandoned_count": 0,
        },
    )
    write(
        directory / policy["external_evidence"]["woocommerce_promotion"],
        {
            "status": "passed",
            "failures": [],
            "playwright": {"total": 2, "passed": 2, "failed": 0, "skipped": 0, "flaky": 0},
            "junit": {"total": 2, "failed": 0, "errors": 0, "skipped": 0},
            "expected_playwright_scenarios": 2,
            "browser_traces": 2,
            "installed_tree_matches_package": True,
            "clean_installed_tree_matches_package": True,
            "upgrade_installed_tree_matches_package": True,
            "installed_checksum_matches_package": True,
            "woocommerce_promotion_contract": {
                "promotion_tested": ["10.9.4"],
                "runtime_contract": "woocommerce-10.9-core-session-v1",
            },
            "woocommerce_package_version": "10.9.4",
            "woocommerce_package_sha256": "b" * 64,
            "environment_identity": {
                "host_package_matches": True,
                "playwright_version": "1.61.1",
                "browser_version": "Chromium 140",
            },
            "lifecycle": {"install": True, "activation": True, "mutation": True},
            "woocommerce_critical_entries": [],
            "wordpress_debug_violations": [],
        },
    )


def main() -> int:
    expect(run(POLICY, LEDGER, "item9"), 0, "valid item-9 ledger")
    expect(run(POLICY, LEDGER, "publication"), 1, "publication blocked by planned item 9")

    with tempfile.TemporaryDirectory(prefix="ysai-stage-h-self-test-") as temporary:
        temp = Path(temporary)
        policy_path = temp / "policy.json"
        ledger_path = temp / "ledger.json"
        evidence = temp / "evidence"
        policy = json.loads(POLICY.read_text(encoding="utf-8"))
        write(policy_path, policy)

        write(ledger_path, publication_ledger())
        expect(run(policy_path, ledger_path, "publication"), 69, "external-only publication blockers")
        valid_evidence(policy, evidence)
        expect(run(policy_path, ledger_path, "publication", evidence), 0, "strict complete publication evidence")

        forged = json.loads((evidence / policy["external_evidence"]["woocommerce_promotion"]).read_text(encoding="utf-8"))
        forged["browser_traces"] = 1
        write(evidence / policy["external_evidence"]["woocommerce_promotion"], forged)
        expect(run(policy_path, ledger_path, "publication", evidence), 69, "forged promotion trace coverage")

        ledger = json.loads(LEDGER.read_text(encoding="utf-8"))
        for finding in ledger["findings"]:
            if finding["id"] == "H-101":
                finding["severity"] = "high"
                finding["scopes"] = ["item9", "maintenance"]
        write(ledger_path, ledger)
        expect(run(policy_path, ledger_path, "item9"), 1, "existing accepted debt elevated to item-9 blocker")

        policy = json.loads(POLICY.read_text(encoding="utf-8"))
        policy["maintainability"]["budgets"][0]["maximum_lines"] = 501
        write(policy_path, policy)
        shutil.copy2(LEDGER, ledger_path)
        expect(run(policy_path, ledger_path, "source"), 1, "maintainability budget drift")

        policy = json.loads(POLICY.read_text(encoding="utf-8"))
        policy["static_debt"]["phpstan_core"]["maximum"] += 1
        write(policy_path, policy)
        expect(run(policy_path, ledger_path, "source"), 1, "static debt authority drift")

        policy = json.loads(POLICY.read_text(encoding="utf-8"))
        policy["inline_suppressions"][0]["count"] += 1
        write(policy_path, policy)
        expect(run(policy_path, ledger_path, "source"), 1, "inline suppression drift")

        policy = json.loads(POLICY.read_text(encoding="utf-8"))
        if (ROOT / ".git").exists():
            # A repository candidate can prove that the recorded Stage G commit
            # resolves to the reviewed tree. Source archives deliberately omit
            # Git internals, so their self-test exercises malformed authority
            # rather than pretending history is available.
            policy["stage_g_authority"]["tree"] = "0" * 40
            label = "Stage G tree authority drift"
        else:
            policy["stage_g_authority"]["tree"] = "0" * 39
            label = "Stage G tree authority malformed without Git metadata"
        write(policy_path, policy)
        expect(run(policy_path, ledger_path, "source"), 1, label)

    print(
        "Stage H self-test passed: item-9 authority accepted; code blockers, Stage G drift, "
        "forged promotion evidence, maintainability growth, static debt growth, and suppression drift rejected."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
