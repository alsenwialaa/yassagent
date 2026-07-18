#!/usr/bin/env python3
"""Verify that explicit static-analysis debt ledgers cannot grow or become broad ignores."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
POLICY = ROOT / "config/quality/static-analysis-policy.json"


def read_json(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise SystemExit(f"Expected one JSON object: {path.relative_to(ROOT)}")
    return data


def phpstan_count(path: Path) -> int:
    source = path.read_text(encoding="utf-8")
    if "ignoreErrors:" not in source:
        raise SystemExit(f"PHPStan baseline is empty or malformed: {path.relative_to(ROOT)}")
    blocks = source.split("\n\t\t-\n")[1:]
    total = 0
    for block in blocks:
        if "message:" not in block or "identifier:" not in block or "path:" not in block:
            raise SystemExit(f"Broad PHPStan ignore without message/identifier/path: {path.relative_to(ROOT)}")
        match = re.search(r"^\s*count:\s*([0-9]+)\s*$", block, re.MULTILINE)
        if not match or int(match.group(1)) < 1:
            raise SystemExit(f"PHPStan ignore lacks an exact positive count: {path.relative_to(ROOT)}")
        total += int(match.group(1))
    return total


def main() -> int:
    policy = read_json(POLICY)
    if set(policy) != {"schema", "policy", "phpstan", "phpcs", "php_compatibility"} or policy["schema"] != 1:
        raise SystemExit("Static-analysis policy schema is not exact.")

    configs = {
        "core": ROOT / "phpstan.neon.dist",
        "adapters": ROOT / "phpstan-adapters.neon.dist",
    }
    baselines = {
        "core": ROOT / "config/quality/phpstan-core-baseline.neon",
        "adapters": ROOT / "config/quality/phpstan-adapters-baseline.neon",
    }
    for name, config in configs.items():
        source = config.read_text(encoding="utf-8")
        if "ignoreErrors:" in source or "excludePaths:" in source:
            raise SystemExit(f"PHPStan {name} config contains a broad local suppression.")
        level = int(policy["phpstan"][name]["level"])
        if f"level: {level}" not in source:
            raise SystemExit(f"PHPStan {name} level drifted from the reviewed policy.")
        total = phpstan_count(baselines[name])
        expected = int(policy["phpstan"][name]["baseline_errors"])
        if total > expected:
            raise SystemExit(f"PHPStan {name} debt grew from {expected} to {total}.")

    phpcs = read_json(ROOT / "config/quality/phpcs-baseline.json")
    compatibility = read_json(ROOT / "config/quality/phpcompatibility-baseline.json")
    if int(phpcs["totals"]["violations"]) > int(policy["phpcs"]["baseline_violations"]):
        raise SystemExit("PHPCS debt grew beyond the reviewed Stage G ledger.")
    if int(compatibility["totals"]["violations"]) != int(policy["php_compatibility"]["baseline_violations"]):
        raise SystemExit("PHP compatibility debt drifted from the zero-debt policy.")

    print(
        "Static baselines verified: "
        f"PHPStan core {phpstan_count(baselines['core'])}, "
        f"adapters {phpstan_count(baselines['adapters'])}, "
        f"PHPCS {phpcs['totals']['violations']}, PHP compatibility 0."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
