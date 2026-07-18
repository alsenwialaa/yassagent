#!/usr/bin/env python3
"""Prove Node lock verification rejects missing, unexpected, and mismatched packages."""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VERIFIER = ROOT / "scripts/quality/verify-node-install.py"


def write_json(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2) + "\n", encoding="utf-8")


def package(root: Path, relative: str, name: str, version: str) -> None:
    write_json(root / relative / "package.json", {"name": name, "version": version})


def run(root: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, str(VERIFIER), "--root", str(root)],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
        timeout=30,
    )


def expect(root: Path, expected: int, label: str) -> None:
    result = run(root)
    if result.returncode != expected:
        raise SystemExit(
            f"{label}: expected exit {expected}, got {result.returncode}\n"
            f"stdout:\n{result.stdout}\nstderr:\n{result.stderr}"
        )


def fixture(root: Path) -> None:
    write_json(root / "package.json", {"name": "fixture", "version": "1.0.0"})
    write_json(
        root / "package-lock.json",
        {
            "name": "fixture",
            "version": "1.0.0",
            "lockfileVersion": 3,
            "packages": {
                "": {"name": "fixture", "version": "1.0.0"},
                "node_modules/alpha": {"version": "2.0.0", "integrity": "sha512-test"},
                "node_modules/optional-platform": {"version": "3.0.0", "optional": True},
            },
        },
    )
    package(root, "node_modules/alpha", "alpha", "2.0.0")
    # A package.json inside package content must not be mistaken for another installed package root.
    write_json(root / "node_modules/alpha/examples/package.json", {"name": "example", "version": "1.0.0"})


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="ysai-node-install-self-test-") as directory:
        root = Path(directory)
        fixture(root)
        expect(root, 0, "valid lock-matched tree")

        package(root, "node_modules/alpha", "alpha", "9.0.0")
        expect(root, 1, "version mismatch")

        package(root, "node_modules/alpha", "alpha", "2.0.0")
        package(root, "node_modules/unexpected", "unexpected", "1.0.0")
        expect(root, 1, "unexpected package")

        for path in sorted((root / "node_modules/unexpected").rglob("*"), reverse=True):
            if path.is_file():
                path.unlink()
            else:
                path.rmdir()
        (root / "node_modules/unexpected").rmdir()
        (root / "node_modules/alpha/package.json").unlink()
        expect(root, 1, "missing required package")

    print("Node install self-test passed: exact and optional lock state accepted; missing, unexpected, and mismatched packages rejected.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
