#!/usr/bin/env python3
"""Enforce a shrinking PHPCS debt ledger without concealing new violations."""

from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import sys
from collections import Counter
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
SCHEMA = 1


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def phpcs_version() -> str:
    result = subprocess.run(
        [str(ROOT / "vendor/bin/phpcs"), "--version"],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return result.stdout.strip()


def run_phpcs(standard: Path) -> dict[str, Any]:
    command = [
        str(ROOT / "vendor/bin/phpcs"),
        "--standard=" + str(standard),
        "--report=json",
        "--basepath=" + str(ROOT),
        "--no-cache",
    ]
    result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True)
    if result.returncode not in (0, 1, 2):
        raise RuntimeError(result.stderr.strip() or "PHPCS failed without a report.")
    try:
        report = json.loads(result.stdout)
    except json.JSONDecodeError as exception:
        detail = result.stderr.strip() or result.stdout[:1000]
        raise RuntimeError("PHPCS did not produce valid JSON: " + detail) from exception
    if not isinstance(report, dict) or not isinstance(report.get("files"), dict):
        raise RuntimeError("PHPCS report has an invalid structure.")
    return report


def ledger(report: dict[str, Any]) -> tuple[Counter[str], dict[str, int]]:
    counts: Counter[str] = Counter()
    errors = 0
    warnings = 0
    for filename, file_report in sorted(report["files"].items()):
        messages = file_report.get("messages", []) if isinstance(file_report, dict) else []
        if not isinstance(messages, list):
            raise RuntimeError("PHPCS file report has invalid messages: " + str(filename))
        relative = Path(str(filename)).as_posix()
        for message in messages:
            if not isinstance(message, dict):
                raise RuntimeError("PHPCS message is invalid: " + relative)
            source = str(message.get("source", ""))
            kind = str(message.get("type", ""))
            if source == "" or kind not in {"ERROR", "WARNING"}:
                raise RuntimeError("PHPCS message has no closed source/type: " + relative)
            counts[relative + "|" + source + "|" + kind] += 1
            if kind == "ERROR":
                errors += 1
            else:
                warnings += 1
    return counts, {"errors": errors, "warnings": warnings, "violations": errors + warnings}


def write_baseline(path: Path, standard: Path, counts: Counter[str], totals: dict[str, int]) -> None:
    payload = {
        "schema": SCHEMA,
        "standard": standard.relative_to(ROOT).as_posix(),
        "standard_sha256": sha256(standard),
        "phpcs": phpcs_version(),
        "totals": totals,
        "violations": dict(sorted(counts.items())),
    }
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def read_baseline(path: Path, standard: Path) -> tuple[Counter[str], dict[str, Any]]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exception:
        raise RuntimeError("PHPCS baseline is missing or invalid: " + str(path)) from exception
    required = {"schema", "standard", "standard_sha256", "phpcs", "totals", "violations"}
    if not isinstance(payload, dict) or set(payload) != required or payload["schema"] != SCHEMA:
        raise RuntimeError("PHPCS baseline schema is not exact: " + str(path))
    expected_standard = standard.relative_to(ROOT).as_posix()
    if payload["standard"] != expected_standard or payload["standard_sha256"] != sha256(standard):
        raise RuntimeError("PHPCS ruleset changed; regenerate and review the debt ledger explicitly.")
    raw = payload["violations"]
    if not isinstance(raw, dict) or any(not isinstance(k, str) or not isinstance(v, int) or v < 1 for k, v in raw.items()):
        raise RuntimeError("PHPCS baseline violation ledger is invalid.")
    return Counter(raw), payload


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--standard", required=True, type=Path)
    parser.add_argument("--baseline", required=True, type=Path)
    parser.add_argument("--generate", action="store_true")
    args = parser.parse_args()

    standard = (ROOT / args.standard).resolve() if not args.standard.is_absolute() else args.standard.resolve()
    baseline = (ROOT / args.baseline).resolve() if not args.baseline.is_absolute() else args.baseline.resolve()
    if not standard.is_file():
        raise SystemExit("PHPCS standard is missing: " + str(standard))

    report = run_phpcs(standard)
    current, totals = ledger(report)
    if args.generate:
        write_baseline(baseline, standard, current, totals)
        print(
            f"Generated {baseline.relative_to(ROOT)}: "
            f"{totals['errors']} errors, {totals['warnings']} warnings, {len(current)} debt keys."
        )
        return 0

    allowed, payload = read_baseline(baseline, standard)
    regressions: list[str] = []
    for key, count in sorted(current.items()):
        permitted = allowed.get(key, 0)
        if count > permitted:
            regressions.append(f"{key}: current {count}, baseline {permitted}")
    if regressions:
        raise SystemExit("New PHPCS debt is forbidden:\n" + "\n".join(regressions))

    removed = sum(max(0, count - current.get(key, 0)) for key, count in allowed.items())
    baseline_total = int(payload["totals"]["violations"])
    print(
        f"PHPCS baseline passed: {totals['violations']} current violations "
        f"(baseline {baseline_total}, improved by {removed})."
    )
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except RuntimeError as exception:
        raise SystemExit(str(exception)) from exception
