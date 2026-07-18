#!/usr/bin/env python3
"""Normalize Composer audit JSON into closed Stage H evidence."""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
from pathlib import Path
from typing import Any


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--output", type=Path, required=True)
    return parser.parse_args()


def read_object(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise SystemExit(f"Composer audit JSON is unreadable or malformed: {exc}") from exc
    if not isinstance(value, dict):
        raise SystemExit("Composer audit output must be one JSON object")
    return value


def advisory_count(value: Any) -> int:
    if not isinstance(value, dict):
        raise SystemExit("Composer audit advisories must be an object")
    count = 0
    for package, rows in value.items():
        if not isinstance(package, str) or package == "" or not isinstance(rows, list):
            raise SystemExit("Composer audit advisory rows are malformed")
        for row in rows:
            if not isinstance(row, dict):
                raise SystemExit("Composer audit advisory entry is malformed")
            count += 1
    return count


def abandoned_count(value: Any) -> int:
    if value is None:
        return 0
    if isinstance(value, list):
        if any(not isinstance(row, (str, dict)) for row in value):
            raise SystemExit("Composer abandoned-package rows are malformed")
        return len(value)
    if isinstance(value, dict):
        if any(not isinstance(name, str) for name in value):
            raise SystemExit("Composer abandoned-package names are malformed")
        return len(value)
    raise SystemExit("Composer audit abandoned packages must be an object or array")


def main() -> int:
    args = parse_args()
    root = args.root.resolve()
    raw = read_object(args.input.resolve())
    if "advisories" not in raw:
        raise SystemExit("Composer audit output omits advisories")
    advisories = advisory_count(raw["advisories"])
    abandoned = abandoned_count(raw.get("abandoned"))
    if advisories != 0 or abandoned != 0:
        print(
            json.dumps(
                {"advisory_count": advisories, "abandoned_count": abandoned},
                indent=2,
                sort_keys=True,
            ),
            file=sys.stderr,
        )
        return 1
    lock = root / "composer.lock"
    if not lock.is_file():
        raise SystemExit("composer.lock is missing")
    evidence = {
        "schema": 1,
        "status": "passed",
        "composer_lock_sha256": hashlib.sha256(lock.read_bytes()).hexdigest(),
        "advisory_count": 0,
        "abandoned_count": 0,
    }
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print("Composer advisory evidence verified: 0 advisories, 0 abandoned packages.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
