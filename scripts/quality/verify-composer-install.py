#!/usr/bin/env python3
"""Verify that installed Composer package metadata matches composer.lock."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--json-output", type=Path)
    parser.add_argument(
        "--method",
        choices=("current", "clean", "offline_lock_match"),
        default="current",
    )
    return parser.parse_args()


def load_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise SystemExit(f"Expected one JSON object: {path}")
    return value


def locked(root: Path) -> dict[str, str]:
    data = load_json(root / "composer.lock")
    packages = data.get("packages", [])
    packages_dev = data.get("packages-dev", [])
    if not isinstance(packages, list) or not isinstance(packages_dev, list):
        raise SystemExit("composer.lock package lists are malformed")
    rows = packages + packages_dev
    result: dict[str, str] = {}
    for row in rows:
        if not isinstance(row, dict) or not isinstance(row.get("name"), str) or not isinstance(row.get("version"), str):
            raise SystemExit("composer.lock contains a malformed package row")
        name = row["name"]
        if name in result:
            raise SystemExit(f"composer.lock contains a duplicate package: {name}")
        result[name] = row["version"]
    return result


def installed(root: Path) -> dict[str, str]:
    installed_json = root / "vendor/composer/installed.json"
    if installed_json.is_file():
        raw = json.loads(installed_json.read_text(encoding="utf-8"))
        rows = raw.get("packages", []) if isinstance(raw, dict) else raw
        if not isinstance(rows, list):
            raise SystemExit("vendor/composer/installed.json is malformed")
        result: dict[str, str] = {}
        for row in rows:
            if not isinstance(row, dict) or not isinstance(row.get("name"), str) or not isinstance(row.get("version"), str):
                raise SystemExit("Installed Composer metadata contains a malformed package row")
            name = row["name"]
            if name in result:
                raise SystemExit(f"Installed Composer metadata contains a duplicate package: {name}")
            result[name] = row["version"]
        return result

    installed_php = root / "vendor/composer/installed.php"
    if not installed_php.is_file():
        raise SystemExit("Composer installed metadata is missing")
    source = installed_php.read_text(encoding="utf-8")
    result = {}
    for name, version in re.findall(
        r"'([^']+/[^']+)'\s*=>\s*array\s*\(.*?'pretty_version'\s*=>\s*'([^']+)'",
        source,
        flags=re.S,
    ):
        result[name] = version
    if not result:
        raise SystemExit("Unable to parse Composer installed metadata")
    return result


def main() -> int:
    args = parse_args()
    root = args.root.resolve()
    expected = locked(root)
    actual = installed(root)
    missing = sorted(set(expected) - set(actual))
    unexpected = sorted(set(actual) - set(expected))
    mismatches = sorted(
        name for name in set(expected) & set(actual) if expected[name] != actual[name]
    )
    if missing or unexpected or mismatches:
        details = {
            "missing": missing,
            "unexpected": unexpected,
            "version_mismatches": {
                name: {"locked": expected[name], "installed": actual[name]}
                for name in mismatches
            },
        }
        print(json.dumps(details, indent=2, sort_keys=True), file=sys.stderr)
        return 1
    package_set = "\n".join(f"{name}={expected[name]}" for name in sorted(expected)) + "\n"
    evidence = {
        "schema": 1,
        "status": "passed",
        "method": args.method,
        "composer_lock_sha256": hashlib.sha256((root / "composer.lock").read_bytes()).hexdigest(),
        "package_count": len(expected),
        "package_set_sha256": hashlib.sha256(package_set.encode("utf-8")).hexdigest(),
    }
    if args.json_output:
        output = args.json_output.resolve()
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"Installed Composer metadata matches the lock: {len(expected)} packages.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
