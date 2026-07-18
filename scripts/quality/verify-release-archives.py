#!/usr/bin/env python3
"""Audit deterministic installable/source archives and prove byte identity."""

from __future__ import annotations

import argparse
import hashlib
import json
import stat
import sys
import zipfile
from pathlib import Path, PurePosixPath
from typing import Any

ROOT_NAME = "yassin-ai-assistant"
EXPECTED_TIMESTAMP = (2000, 1, 1, 0, 0, 0)
MAX_ARCHIVE_UNCOMPRESSED = 128 * 1024 * 1024
MAX_MEMBER_UNCOMPRESSED = 16 * 1024 * 1024
ALLOWED_PRODUCTION = {
    "ARCHITECTURE.md", "CHANGELOG.md", "LICENSE", "PRIVACY.md", "README.md",
    "REST-CONTRACT.md", "SECURITY.md", "assets", "config", "readme.txt", "src",
    "uninstall.php", "yassin-ai-assistant.php",
}
ALLOWED_SOURCE = ALLOWED_PRODUCTION | {
    ".dockerignore", ".github", ".gitignore", "DEVELOPMENT.md", "HARDENING.md", "integration", "package-lock.json",
    "package.json", "composer.json", "composer.lock", "phpunit.xml.dist", "phpstan.neon.dist",
    "phpstan-adapters.neon.dist", "phpcs.xml.dist", "phpcompatibility.xml.dist",
    "eslint.config.js", "scripts", "tests",
}
REQUIRED_PRODUCTION = {
    "yassin-ai-assistant.php",
    "uninstall.php",
    "assets/js/widget.js",
    "config/public-api-contract.json",
    "config/woocommerce-compatibility.json",
}
REQUIRED_SOURCE = {
    ".github/workflows/runtime-tests.yml",
    "composer.json", "composer.lock", "package.json", "package-lock.json",
    "phpunit.xml.dist", "phpstan.neon.dist", "phpstan-adapters.neon.dist",
    "phpcs.xml.dist", "phpcompatibility.xml.dist", "eslint.config.js",
    "config/quality/static-analysis-policy.json",
    "config/quality/release-hardening-policy.json",
    "config/quality/release-hardening-findings.json",
    "scripts/run-stage-h-gate.py",
    "scripts/quality/verify-release-hardening.py",
    "scripts/quality/self-test-release-hardening.py",
    "scripts/quality/verify-composer-install.py",
    "scripts/quality/verify-node-install.py",
    "scripts/quality/self-test-node-install.py",
    "scripts/quality/self-test-stage-h-runner.py",
    "scripts/quality/verify-release-archives.py",
    "scripts/quality/self-test-release-archives.py",
    "scripts/quality/smoke-production-archive.py",
    "scripts/quality/self-test-production-archive.py",
    "scripts/quality/verify-composer-audit.py",
    "scripts/quality/self-test-composer-audit.py",
    "HARDENING.md",
    "tests/php/Integration/LegacyRegressionSuiteTest.php",
}
FORBIDDEN_PARTS = {
    "__pycache__", "vendor", "node_modules", ".git", ".cache", "release",
    "test-results", "playwright-report", "coverage",
}
FORBIDDEN_PREFIXES = (
    "integration/promotion/runtime/",
    "integration/promotion/artifacts/",
)


def forbidden_source_path(name: str) -> bool:
    pure = PurePosixPath(name)
    return (
        pure.suffix == ".pyc"
        or any(part in FORBIDDEN_PARTS for part in pure.parts)
        or any(name.startswith(prefix) for prefix in FORBIDDEN_PREFIXES)
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--production", type=Path, required=True)
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--manifest", type=Path)
    return parser.parse_args()


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def audit(path: Path, allowed: set[str]) -> tuple[dict[str, bytes], dict[str, Any]]:
    files: dict[str, bytes] = {}
    timestamps: set[tuple[int, int, int, int, int, int]] = set()
    total_uncompressed = 0
    executable_files = 0
    with zipfile.ZipFile(path) as archive:
        bad = archive.testzip()
        if bad is not None:
            raise SystemExit(f"CRC failure in {path.name}: {bad}")
        seen: set[str] = set()
        seen_casefold: set[str] = set()
        for info in archive.infolist():
            name = info.filename
            if "\0" in name:
                raise SystemExit(f"NUL byte in archive member name: {path.name}: {name!r}")
            if name in seen or name.casefold() in seen_casefold:
                raise SystemExit(f"Duplicate or case-colliding member in {path.name}: {name}")
            seen.add(name)
            seen_casefold.add(name.casefold())
            pure = PurePosixPath(name)
            if pure.is_absolute() or ".." in pure.parts or len(pure.parts) < 2 or pure.parts[0] != ROOT_NAME:
                raise SystemExit(f"Unsafe archive member in {path.name}: {name}")
            mode = info.external_attr >> 16
            if info.is_dir():
                raise SystemExit(f"Explicit directory member in {path.name}: {name}")
            if stat.S_ISLNK(mode):
                raise SystemExit(f"Symbolic link in {path.name}: {name}")
            if info.flag_bits & 0x1:
                raise SystemExit(f"Encrypted archive member in {path.name}: {name}")
            if mode and not stat.S_ISREG(mode):
                raise SystemExit(f"Non-regular file in {path.name}: {name}")
            permissions = stat.S_IMODE(mode)
            if permissions not in {0o644, 0o755}:
                raise SystemExit(f"Unsafe or non-deterministic mode in {path.name}: {name}: {permissions:o}")
            if permissions == 0o755:
                executable_files += 1
            relative = PurePosixPath(*pure.parts[1:])
            if relative.parts[0] not in allowed:
                raise SystemExit(f"Unexpected top-level member in {path.name}: {relative.parts[0]}")
            if any(
                part == ".env" or (part.startswith(".env.") and part != ".env.example")
                for part in relative.parts
            ):
                raise SystemExit(f"Sensitive environment file leaked into {path.name}: {relative}")
            if info.file_size > MAX_MEMBER_UNCOMPRESSED:
                raise SystemExit(f"Oversized archive member in {path.name}: {relative}")
            total_uncompressed += info.file_size
            if total_uncompressed > MAX_ARCHIVE_UNCOMPRESSED:
                raise SystemExit(f"Archive expands beyond the reviewed bound: {path.name}")
            timestamps.add(info.date_time)
            files[relative.as_posix()] = archive.read(info)
    if timestamps != {EXPECTED_TIMESTAMP}:
        raise SystemExit(
            f"Archive timestamps are not the exact deterministic authority in {path.name}: "
            f"{sorted(timestamps)}"
        )
    return files, {
        "path": str(path),
        "sha256": sha256(path),
        "files": len(files),
        "uncompressed_bytes": total_uncompressed,
        "timestamp": list(EXPECTED_TIMESTAMP),
        "executable_files": executable_files,
    }


def main() -> int:
    args = parse_args()
    production = args.production.resolve()
    source = args.source.resolve()
    if not production.is_file() or not source.is_file():
        raise SystemExit("Both production and source archives are required")
    production_files, production_meta = audit(production, ALLOWED_PRODUCTION)
    source_files, source_meta = audit(source, ALLOWED_SOURCE)
    missing_production = sorted(REQUIRED_PRODUCTION - set(production_files))
    if missing_production:
        raise SystemExit("Installable archive omits production authority: " + ", ".join(missing_production))
    for name, payload in production_files.items():
        if source_files.get(name) != payload:
            raise SystemExit(f"Production/source byte mismatch: {name}")
    if not production_files or len(source_files) <= len(production_files):
        raise SystemExit("Source archive does not contain required development-only files")
    leaked = sorted(name for name in source_files if forbidden_source_path(name))
    if leaked:
        raise SystemExit("Runtime/cache artifact leaked into source package: " + ", ".join(leaked[:10]))
    if any(name.startswith("config/quality/") for name in production_files):
        raise SystemExit("Development quality ledgers leaked into the installable package")
    missing_source = sorted(REQUIRED_SOURCE - set(source_files))
    if missing_source:
        raise SystemExit("Source archive omits development authority: " + ", ".join(missing_source))
    result = {
        "schema": 1,
        "status": "passed",
        "production": production_meta,
        "source": source_meta,
        "production_source_byte_identity": True,
        "missing_required_production": [],
        "missing_required_source": [],
    }
    if args.manifest:
        args.manifest.parent.mkdir(parents=True, exist_ok=True)
        args.manifest.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(
        f"PACKAGE AUDIT PASSED: {len(production_files)} production files, "
        f"{len(source_files)} source files."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
