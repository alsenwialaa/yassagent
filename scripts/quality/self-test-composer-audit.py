#!/usr/bin/env python3
"""Prove Composer audit normalization rejects unsafe evidence."""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VERIFIER = ROOT / "scripts/quality/verify-composer-audit.py"


def run(payload: object, temp: Path) -> subprocess.CompletedProcess[str]:
    raw = temp / "raw.json"
    out = temp / "normalized.json"
    raw.write_text(json.dumps(payload) + "\n", encoding="utf-8")
    return subprocess.run(
        [sys.executable, str(VERIFIER), "--input", str(raw), "--root", str(ROOT), "--output", str(out)],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
        timeout=30,
    )


def expect(payload: object, expected: int, label: str, temp: Path) -> None:
    result = run(payload, temp)
    if result.returncode != expected:
        raise SystemExit(
            f"{label}: expected exit {expected}, got {result.returncode}\n"
            f"stdout:\n{result.stdout}\nstderr:\n{result.stderr}"
        )


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="ysai-composer-audit-self-test-") as directory:
        temp = Path(directory)
        expect({"advisories": {}, "abandoned": []}, 0, "clean audit", temp)
        expect(
            {"advisories": {"vendor/package": [{"advisoryId": "CVE-TEST"}]}, "abandoned": []},
            1,
            "advisory rejected",
            temp,
        )
        expect({"advisories": {}, "abandoned": ["vendor/package"]}, 1, "abandoned package rejected", temp)
        expect({"abandoned": []}, 1, "missing advisories rejected", temp)
    print("Composer audit self-test passed: clean evidence accepted; advisory, abandonment, and malformed evidence rejected.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
