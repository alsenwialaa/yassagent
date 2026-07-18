#!/usr/bin/env python3
"""Verify that the installed Node package tree matches package-lock.json metadata."""

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


def read_object(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise SystemExit(f"Invalid Node package metadata {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise SystemExit(f"Expected one JSON object: {path}")
    return value


def package_name(relative: str) -> str:
    parts = Path(relative).as_posix().split("/")
    indices = [index for index, part in enumerate(parts) if part == "node_modules"]
    if not indices:
        raise SystemExit(f"Invalid package-lock path: {relative}")
    tail = parts[indices[-1] + 1 :]
    if len(tail) == 1 and tail[0] and not tail[0].startswith("@"):
        return tail[0]
    if len(tail) == 2 and tail[0].startswith("@") and len(tail[0]) > 1 and tail[1]:
        return tail[0] + "/" + tail[1]
    raise SystemExit(f"Invalid package root in package-lock.json: {relative}")


def locked(root: Path) -> tuple[dict[str, dict[str, Any]], str, str]:
    lock_path = root / "package-lock.json"
    lock = read_object(lock_path)
    if lock.get("lockfileVersion") != 3 or not isinstance(lock.get("packages"), dict):
        raise SystemExit("package-lock.json must use the exact v3 packages authority")
    package_json = read_object(root / "package.json")
    root_row = lock["packages"].get("")
    if not isinstance(root_row, dict):
        raise SystemExit("package-lock.json omits its root package")
    for field in ("name", "version"):
        if root_row.get(field) != package_json.get(field):
            raise SystemExit(f"package.json and package-lock.json disagree on root {field}")

    result: dict[str, dict[str, Any]] = {}
    for relative, raw in lock["packages"].items():
        if relative == "":
            continue
        if not isinstance(relative, str) or not relative.startswith("node_modules/") or not isinstance(raw, dict):
            raise SystemExit("package-lock.json contains a malformed package entry")
        if raw.get("link") is True:
            raise SystemExit(f"Linked Node dependency is forbidden in the release lock: {relative}")
        version = raw.get("version")
        if not isinstance(version, str) or version == "":
            raise SystemExit(f"Locked Node package has no exact version: {relative}")
        name = raw.get("name") if isinstance(raw.get("name"), str) else package_name(relative)
        if not name or re.fullmatch(r"(?:@[a-z0-9._~-]+/)?[a-z0-9._~-]+", name, re.IGNORECASE) is None:
            raise SystemExit(f"Locked Node package has an invalid name: {relative}")
        integrity = raw.get("integrity", "")
        if integrity != "" and not isinstance(integrity, str):
            raise SystemExit(f"Locked Node package integrity is malformed: {relative}")
        result[relative] = {
            "name": name,
            "version": version,
            "optional": raw.get("optional") is True,
            "integrity": integrity,
        }
    if not result:
        raise SystemExit("package-lock.json contains no installed packages")
    return result, hashlib.sha256(lock_path.read_bytes()).hexdigest(), str(package_json["version"])


def actual_package_roots(root: Path) -> set[str]:
    node_modules = root / "node_modules"
    if not node_modules.is_dir() or node_modules.is_symlink():
        raise SystemExit("node_modules is missing or is not a real directory")
    actual: set[str] = set()
    for manifest in node_modules.rglob("package.json"):
        parent = manifest.parent
        relative = parent.relative_to(root).as_posix()
        parts = relative.split("/")
        indices = [index for index, part in enumerate(parts) if part == "node_modules"]
        if not indices:
            continue
        tail = parts[indices[-1] + 1 :]
        is_root = len(tail) == 1 or (len(tail) == 2 and tail[0].startswith("@"))
        if is_root:
            actual.add(relative)
    return actual


def main() -> int:
    args = parse_args()
    root = args.root.resolve()
    expected, lock_hash, project_version = locked(root)
    actual = actual_package_roots(root)
    unexpected = sorted(actual - set(expected))
    if unexpected:
        raise SystemExit("Unexpected installed Node packages: " + ", ".join(unexpected))

    missing_required: list[str] = []
    omitted_optional: list[str] = []
    mismatches: list[str] = []
    installed_count = 0
    for relative, row in sorted(expected.items()):
        package_root = root / relative
        if relative not in actual:
            if row["optional"]:
                omitted_optional.append(relative)
            else:
                missing_required.append(relative)
            continue
        if package_root.is_symlink() or not package_root.is_dir():
            mismatches.append(relative + ": package root is not a real directory")
            continue
        manifest = read_object(package_root / "package.json")
        if manifest.get("name") != row["name"] or manifest.get("version") != row["version"]:
            mismatches.append(
                f"{relative}: locked {row['name']}@{row['version']}, "
                f"installed {manifest.get('name')}@{manifest.get('version')}"
            )
            continue
        installed_count += 1
    if missing_required or mismatches:
        payload = {
            "missing_required": missing_required,
            "mismatches": mismatches,
            "omitted_optional": omitted_optional,
        }
        print(json.dumps(payload, indent=2, sort_keys=True), file=sys.stderr)
        return 1

    package_set = "\n".join(
        f"{path}|{row['name']}|{row['version']}|{int(row['optional'])}|{row['integrity']}"
        for path, row in sorted(expected.items())
    ) + "\n"
    evidence = {
        "schema": 1,
        "status": "passed",
        "method": args.method,
        "package_lock_sha256": lock_hash,
        "project_version": project_version,
        "locked_package_count": len(expected),
        "installed_package_count": installed_count,
        "omitted_optional_count": len(omitted_optional),
        "package_set_sha256": hashlib.sha256(package_set.encode("utf-8")).hexdigest(),
    }
    if args.json_output:
        output = args.json_output.resolve()
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(
        "Installed Node metadata matches the lock: "
        f"{installed_count}/{len(expected)} packages; {len(omitted_optional)} optional omitted."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
