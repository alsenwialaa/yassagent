#!/usr/bin/env python3
"""Prove Stage H rejects destructive paths and unsafe source replay archives."""

from __future__ import annotations

import importlib.util
import json
import stat
import tempfile
import zipfile
from pathlib import Path
from types import ModuleType

ROOT = Path(__file__).resolve().parents[2]
RUNNER = ROOT / "scripts/run-stage-h-gate.py"
TIMESTAMP = (2000, 1, 1, 0, 0, 0)


def load_runner() -> ModuleType:
    spec = importlib.util.spec_from_file_location("ysai_stage_h_runner", RUNNER)
    if spec is None or spec.loader is None:
        raise SystemExit("Unable to load Stage H runner")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def expect_rejected(function, label: str, exception=ValueError) -> None:
    try:
        function()
    except exception:
        return
    raise SystemExit(label + " was not rejected")


def write_zip(path: Path, members: list[tuple[str, bytes, int]]) -> None:
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name, payload, mode in members:
            info = zipfile.ZipInfo(name, date_time=TIMESTAMP)
            info.create_system = 3
            info.external_attr = mode << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, payload)


def main() -> int:
    runner = load_runner()
    valid_inside = ROOT / "release" / "stage-h-self-test"
    if runner.validate_output_path(valid_inside) != valid_inside.resolve():
        raise SystemExit("Safe in-repository release output was not preserved")

    with tempfile.TemporaryDirectory(prefix="ysai-stage-h-runner-self-test-") as directory:
        temp = Path(directory)
        valid_external = temp / "candidate"
        if runner.validate_output_path(valid_external) != valid_external.resolve():
            raise SystemExit("Safe external release output was not preserved")

        target = temp / "target"
        target.mkdir()
        linked = temp / "linked-output"
        linked.symlink_to(target, target_is_directory=True)
        expect_rejected(lambda: runner.validate_output_path(linked), "symbolic-link output")

        linked_parent = temp / "linked-parent"
        linked_parent.symlink_to(target, target_is_directory=True)
        expect_rejected(
            lambda: runner.validate_output_path(linked_parent / "candidate"),
            "symbolic-link output parent",
        )

        input_file = valid_external / "woocommerce.zip"
        expect_rejected(
            lambda: runner.require_input_outside_output(valid_external, input_file, "test input"),
            "input inside output",
        )
        input_root = temp / "input-root"
        output_inside_input = input_root / "candidate"
        expect_rejected(
            lambda: runner.require_input_outside_output(output_inside_input, input_root, "test input"),
            "output inside input",
        )

        nonempty = temp / "nonempty"
        nonempty.mkdir()
        (nonempty / "unrelated.txt").write_text("do not delete", encoding="utf-8")
        expect_rejected(
            lambda: runner.prepare_output_directory(nonempty),
            "unmarked non-empty output",
        )
        if not (nonempty / "unrelated.txt").is_file():
            raise SystemExit("Rejected unmarked output was modified")

        empty = temp / "empty"
        empty.mkdir()
        runner.prepare_output_directory(empty)
        marker = empty / runner.OUTPUT_MARKER
        if json.loads(marker.read_text(encoding="utf-8")) != runner.OUTPUT_MARKER_PAYLOAD:
            raise SystemExit("Fresh Stage H output marker was not exact")
        (empty / "old-evidence.txt").write_text("replace me", encoding="utf-8")
        runner.prepare_output_directory(empty)
        if (empty / "old-evidence.txt").exists() or not runner.output_marker_valid(empty):
            raise SystemExit("Marked Stage H output was not replaced safely")

        valid_zip = temp / "valid-source.zip"
        write_zip(
            valid_zip,
            [("yassin-ai-assistant/package.json", b"{}\n", stat.S_IFREG | 0o644)],
        )
        replay = temp / "replay-valid"
        extracted = runner.extract_source(valid_zip, replay)
        if not (extracted / "package.json").is_file():
            raise SystemExit("Safe source archive was not extracted")

        traversal = temp / "traversal.zip"
        write_zip(
            traversal,
            [("yassin-ai-assistant/../escape.txt", b"bad", stat.S_IFREG | 0o644)],
        )
        expect_rejected(
            lambda: runner.extract_source(traversal, temp / "replay-traversal"),
            "source traversal member",
            RuntimeError,
        )

        collision = temp / "collision.zip"
        write_zip(
            collision,
            [
                ("yassin-ai-assistant/A.txt", b"a", stat.S_IFREG | 0o644),
                ("yassin-ai-assistant/a.txt", b"b", stat.S_IFREG | 0o644),
            ],
        )
        expect_rejected(
            lambda: runner.extract_source(collision, temp / "replay-collision"),
            "case-colliding source members",
            RuntimeError,
        )

        unsafe_mode = temp / "unsafe-mode.zip"
        write_zip(
            unsafe_mode,
            [("yassin-ai-assistant/file.txt", b"bad", stat.S_IFREG | 0o666)],
        )
        expect_rejected(
            lambda: runner.extract_source(unsafe_mode, temp / "replay-mode"),
            "unsafe source member mode",
            RuntimeError,
        )

    for unsafe in (
        ROOT,
        ROOT.parent,
        ROOT / "src",
        ROOT / "scripts" / "nested",
        ROOT / "vendor" / "stage-h",
        ROOT / "node_modules" / "stage-h",
        ROOT / "stage-h-unreviewed",
        ROOT / "release",
    ):
        expect_rejected(lambda unsafe=unsafe: runner.validate_output_path(unsafe), str(unsafe))

    if not runner.paths_overlap(ROOT / "src", ROOT / "src" / "Domain"):
        raise SystemExit("Path-overlap authority failed to detect containment")
    if runner.paths_overlap(ROOT / "src", ROOT / "release"):
        raise SystemExit("Path-overlap authority confused disjoint paths")

    print(
        "Stage H runner self-test passed: safe outputs accepted; source, ancestor, "
        "symlink, unmarked deletion, overlapping input, and unsafe extraction paths rejected."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
