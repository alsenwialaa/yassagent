#!/usr/bin/env python3
"""Lint the exact installable ZIP with the declared PHP and JavaScript runtimes."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path, PurePosixPath
from typing import Any

ROOT_NAME = "yassin-ai-assistant"
MAX_ARCHIVE_UNCOMPRESSED = 128 * 1024 * 1024
MAX_MEMBER_UNCOMPRESSED = 16 * 1024 * 1024
FORBIDDEN_PARTS = {
    ".git", ".cache", "__pycache__", "vendor", "node_modules", "tests", "scripts",
    "integration", "test-results", "playwright-report", "coverage", "release",
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--archive", type=Path, required=True)
    parser.add_argument("--php", default="php")
    parser.add_argument("--node", default="node")
    parser.add_argument("--json-output", type=Path)
    return parser.parse_args()


def command_path(value: str) -> str:
    if os.sep in value:
        path = Path(value).resolve()
        if not path.is_file() or not os.access(path, os.X_OK):
            raise SystemExit(f"Runtime is not executable: {value}")
        return str(path)
    found = shutil.which(value)
    if not found:
        raise SystemExit(f"Runtime is unavailable: {value}")
    return found


def safe_extract(archive_path: Path, destination: Path) -> Path:
    with zipfile.ZipFile(archive_path) as archive:
        if archive.testzip() is not None:
            raise SystemExit("Installable ZIP failed CRC verification")
        seen: set[str] = set()
        seen_casefold: set[str] = set()
        total_uncompressed = 0
        for info in archive.infolist():
            name = info.filename
            pure = PurePosixPath(name)
            if (
                name in seen
                or name.casefold() in seen_casefold
                or pure.is_absolute()
                or ".." in pure.parts
                or len(pure.parts) < 2
                or pure.parts[0] != ROOT_NAME
                or info.is_dir()
            ):
                raise SystemExit(f"Unsafe or duplicate installable member: {name}")
            seen.add(name)
            seen_casefold.add(name.casefold())
            mode = info.external_attr >> 16
            permissions = stat.S_IMODE(mode)
            if (
                stat.S_ISLNK(mode)
                or (mode and not stat.S_ISREG(mode))
                or permissions not in {0o644, 0o755}
                or (info.flag_bits & 0x1)
            ):
                raise SystemExit(f"Unsupported installable member: {name}")
            if info.file_size > MAX_MEMBER_UNCOMPRESSED:
                raise SystemExit(f"Oversized installable member: {name}")
            total_uncompressed += info.file_size
            if total_uncompressed > MAX_ARCHIVE_UNCOMPRESSED:
                raise SystemExit("Installable ZIP exceeds the reviewed expansion bound")
            relative = PurePosixPath(*pure.parts[1:])
            if any(part in FORBIDDEN_PARTS for part in relative.parts):
                raise SystemExit(f"Development/runtime path leaked into installable ZIP: {relative}")
            if relative.suffix == ".pyc":
                raise SystemExit(f"Python bytecode leaked into installable ZIP: {relative}")
            target = destination.joinpath(*pure.parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(archive.read(info))
    root = destination / ROOT_NAME
    if not root.is_dir():
        raise SystemExit("Installable ZIP has no canonical plugin root")
    return root


def run_checked(command: list[str], timeout: int = 20) -> None:
    result = subprocess.run(
        command,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        check=False,
        timeout=timeout,
    )
    if result.returncode != 0:
        raise SystemExit(
            "Packaged syntax smoke failed: " + " ".join(command) + "\n" + result.stdout
        )


def plugin_version(path: Path) -> str:
    source = path.read_text(encoding="utf-8")
    header = re.search(r"^\s*\*?\s*Version:\s*([^\r\n]+)", source, re.MULTILINE)
    constant = re.search(r"define\('YSAI_VERSION',\s*'([^']+)'\)", source)
    if header is None or constant is None:
        raise SystemExit("Packaged plugin version authority is missing")
    header_value = header.group(1).strip()
    constant_value = constant.group(1)
    if header_value != constant_value or re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", constant_value) is None:
        raise SystemExit("Packaged plugin version authorities disagree or are malformed")
    return constant_value


def main() -> int:
    args = parse_args()
    archive = args.archive.resolve()
    if not archive.is_file():
        raise SystemExit("Installable ZIP is missing")
    php = command_path(args.php)
    node = command_path(args.node)
    with tempfile.TemporaryDirectory(prefix="ysai-production-smoke-") as directory:
        root = safe_extract(archive, Path(directory))
        main_plugin = root / "yassin-ai-assistant.php"
        uninstall = root / "uninstall.php"
        if not main_plugin.is_file() or not uninstall.is_file():
            raise SystemExit("Packaged plugin entry points are missing")
        version = plugin_version(main_plugin)
        php_files = sorted(root.rglob("*.php"))
        js_files = sorted(root.rglob("*.js"))
        if not php_files or not js_files:
            raise SystemExit("Installable ZIP omits PHP or JavaScript runtime files")
        for path in php_files:
            run_checked([php, "-l", str(path)])
        for path in js_files:
            run_checked([node, "--check", str(path)])
        result: dict[str, Any] = {
            "schema": 1,
            "status": "passed",
            "archive": str(archive),
            "archive_sha256": hashlib.sha256(archive.read_bytes()).hexdigest(),
            "plugin_version": version,
            "php_files": len(php_files),
            "javascript_files": len(js_files),
            "php_runtime": subprocess.run([php, "-r", "echo PHP_VERSION;"], stdout=subprocess.PIPE, text=True, check=True).stdout,
            "node_runtime": subprocess.run([node, "--version"], stdout=subprocess.PIPE, text=True, check=True).stdout.strip(),
        }
    if args.json_output:
        output = args.json_output.resolve()
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(
        f"PACKAGED PRODUCTION SMOKE PASSED: {result['php_files']} PHP files; "
        f"{result['javascript_files']} JavaScript files; plugin {result['plugin_version']}."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
