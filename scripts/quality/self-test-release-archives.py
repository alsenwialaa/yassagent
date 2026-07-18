#!/usr/bin/env python3
"""Prove the Stage H archive verifier rejects unsafe or divergent packages."""

from __future__ import annotations

import importlib.util
import subprocess
import sys
import tempfile
import warnings
import zipfile
from pathlib import Path
from types import ModuleType

ROOT = Path(__file__).resolve().parents[2]
VERIFIER = ROOT / "scripts/quality/verify-release-archives.py"


def load_verifier() -> ModuleType:
    spec = importlib.util.spec_from_file_location("ysai_release_archive_verifier", VERIFIER)
    if spec is None or spec.loader is None:
        raise SystemExit("Unable to load archive verifier")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def write_zip(
    path: Path,
    files: dict[str, bytes],
    timestamp: tuple[int, int, int, int, int, int],
    extra_members: list[tuple[str, bytes]] | None = None,
    mode: int = 0o100644,
) -> None:
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        rows = list(files.items()) + list(extra_members or [])
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            for relative, payload in rows:
                info = zipfile.ZipInfo(f"yassin-ai-assistant/{relative}", date_time=timestamp)
                info.create_system = 3
                info.external_attr = mode << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(info, payload)


def run(production: Path, source: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [
            sys.executable,
            str(VERIFIER),
            "--production",
            str(production),
            "--source",
            str(source),
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
        timeout=30,
    )


def expect(result: subprocess.CompletedProcess[str], expected: int, label: str) -> None:
    if result.returncode != expected:
        raise SystemExit(
            f"{label}: expected exit {expected}, got {result.returncode}\n"
            f"stdout:\n{result.stdout}\nstderr:\n{result.stderr}"
        )


def main() -> int:
    verifier = load_verifier()
    timestamp = tuple(verifier.EXPECTED_TIMESTAMP)
    production_payload = {
        relative: ("production:" + relative).encode("utf-8")
        for relative in verifier.REQUIRED_PRODUCTION
    }
    source_payload = dict(production_payload)
    source_payload.update(
        {
            relative: ("source:" + relative).encode("utf-8")
            for relative in verifier.REQUIRED_SOURCE
            if relative not in source_payload
        }
    )

    with tempfile.TemporaryDirectory(prefix="ysai-archive-self-test-") as temporary:
        temp = Path(temporary)
        production = temp / "plugin.zip"
        source = temp / "source.zip"
        write_zip(production, production_payload, timestamp)
        write_zip(source, source_payload, timestamp)
        expect(run(production, source), 0, "valid deterministic archives")

        bad_time = temp / "bad-time.zip"
        write_zip(bad_time, production_payload, (2026, 7, 18, 0, 0, 0))
        expect(run(bad_time, source), 1, "timestamp drift")

        divergent = dict(source_payload)
        divergent["yassin-ai-assistant.php"] = b"divergent"
        bad_source = temp / "bad-source.zip"
        write_zip(bad_source, divergent, timestamp)
        expect(run(production, bad_source), 1, "production/source divergence")

        leaked = dict(production_payload)
        leaked["config/quality/release-hardening-policy.json"] = b"{}"
        bad_production = temp / "bad-production.zip"
        write_zip(bad_production, leaked, timestamp)
        expect(run(bad_production, source), 1, "development authority leaked to production")

        unsafe = temp / "unsafe.zip"
        write_zip(
            unsafe,
            production_payload,
            timestamp,
            extra_members=[("../escape.php", b"unsafe")],
        )
        expect(run(unsafe, source), 1, "unsafe archive member")

        duplicate = temp / "duplicate.zip"
        duplicate_member = next(iter(production_payload.items()))
        write_zip(
            duplicate,
            production_payload,
            timestamp,
            extra_members=[duplicate_member],
        )
        expect(run(duplicate, source), 1, "duplicate archive member")

        nested_cache_payload = dict(source_payload)
        nested_cache_payload["tests/fixtures/__pycache__/poison.pyc"] = b"cache"
        nested_cache = temp / "nested-cache.zip"
        write_zip(nested_cache, nested_cache_payload, timestamp)
        expect(run(production, nested_cache), 1, "nested cache leakage")

        unsafe_mode = temp / "unsafe-mode.zip"
        write_zip(unsafe_mode, production_payload, timestamp, mode=0o100666)
        expect(run(unsafe_mode, source), 1, "world-writable archive mode")

    print(
        "Stage H archive self-test passed: deterministic identity accepted; timestamp, path, "
        "duplicate, nested-cache leakage, unsafe modes, and byte divergence rejected."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
