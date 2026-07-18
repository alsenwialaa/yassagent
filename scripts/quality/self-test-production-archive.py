#!/usr/bin/env python3
"""Prove the installable-package smoke rejects syntax and development leakage."""

from __future__ import annotations

import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SMOKE = ROOT / "scripts/quality/smoke-production-archive.py"
TIMESTAMP = (2000, 1, 1, 0, 0, 0)


def write(path: Path, files: dict[str, bytes], mode: int = 0o100644) -> None:
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for relative, payload in files.items():
            info = zipfile.ZipInfo("yassin-ai-assistant/" + relative, date_time=TIMESTAMP)
            info.create_system = 3
            info.external_attr = mode << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, payload)


def run(path: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, str(SMOKE), "--archive", str(path)],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
        timeout=60,
    )


def expect(path: Path, expected: int, label: str) -> None:
    result = run(path)
    if result.returncode != expected:
        raise SystemExit(
            f"{label}: expected exit {expected}, got {result.returncode}\n"
            f"stdout:\n{result.stdout}\nstderr:\n{result.stderr}"
        )


def payload(plugin_php: bytes = b"<?php\n/*\nPlugin Name: Test\nVersion: 1.0.0\n*/\ndefine('YSAI_VERSION', '1.0.0');\n") -> dict[str, bytes]:
    return {
        "yassin-ai-assistant.php": plugin_php,
        "uninstall.php": b"<?php\n",
        "assets/js/widget.js": b"'use strict';\n",
    }


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="ysai-production-smoke-self-test-") as directory:
        temp = Path(directory)
        valid = temp / "valid.zip"
        write(valid, payload())
        expect(valid, 0, "valid package")

        invalid_php = temp / "invalid-php.zip"
        write(invalid_php, payload(b"<?php this is invalid\n/* Version: 1.0.0 */\ndefine('YSAI_VERSION', '1.0.0');"))
        expect(invalid_php, 1, "invalid PHP")

        leaked = temp / "leaked.zip"
        rows = payload(); rows["vendor/autoload.php"] = b"<?php\n"
        write(leaked, rows)
        expect(leaked, 1, "development dependency leakage")

        unsafe_mode = temp / "unsafe-mode.zip"
        write(unsafe_mode, payload(), mode=0o100666)
        expect(unsafe_mode, 1, "world-writable package member")

    print("Packaged production smoke self-test passed: valid package accepted; invalid PHP, development leakage, and unsafe modes rejected.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
