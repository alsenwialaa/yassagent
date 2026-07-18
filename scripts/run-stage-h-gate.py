#!/usr/bin/env python3
"""Run the cumulative Stage H release-hardening gate with bounded evidence."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import signal
import stat
import subprocess
import sys
import time
import zipfile
from pathlib import Path
from typing import Any, Sequence

ROOT = Path(__file__).resolve().parent.parent
EXTERNAL_BLOCKED = 69
OUTPUT_MARKER = ".ysai-stage-h-output.json"
OUTPUT_MARKER_PAYLOAD = {
    "schema": 1,
    "purpose": "yassin-ai-assistant-stage-h-output",
}
MAX_SOURCE_ARCHIVE_UNCOMPRESSED = 128 * 1024 * 1024
MAX_SOURCE_MEMBER_UNCOMPRESSED = 16 * 1024 * 1024


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=("item9", "publication"), default="item9")
    parser.add_argument("--output", type=Path, default=ROOT / "release/stage-h")
    parser.add_argument("--composer", type=Path)
    parser.add_argument("--offline-vendor", type=Path)
    parser.add_argument("--offline-node-modules", type=Path)
    parser.add_argument("--woocommerce-version", default="10.9.4")
    parser.add_argument("--woocommerce-zip", type=Path)
    parser.add_argument("--allow-dirty", action="store_true")
    parser.add_argument("--keep-replay", action="store_true")
    parser.add_argument("--keep-environment", action="store_true")
    parser.add_argument("--quality-timeout-seconds", type=int, default=3600)
    parser.add_argument("--source-replay-timeout-seconds", type=int, default=3600)
    return parser.parse_args()


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def utc_now() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def git_value(args: Sequence[str]) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
        timeout=10,
    )
    return result.stdout.strip() if result.returncode == 0 else ""


def clean_tree() -> bool:
    result = subprocess.run(
        ["git", "status", "--porcelain", "--untracked-files=all"],
        cwd=ROOT,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
        timeout=10,
    )
    return result.returncode == 0 and result.stdout == ""


def paths_overlap(first: Path, second: Path) -> bool:
    """Return true when either resolved path contains the other."""
    first = first.resolve()
    second = second.resolve()
    return first == second or first in second.parents or second in first.parents


def lexical_absolute(path: Path) -> Path:
    """Return an absolute path without trusting symbolic-link resolution."""
    return Path(os.path.abspath(os.fspath(path.expanduser())))


def has_symlink_component(path: Path) -> bool:
    """Reject an output whose existing path authority contains any symbolic link."""
    absolute = lexical_absolute(path)
    current = Path(absolute.anchor)
    for part in absolute.parts[1:]:
        current /= part
        if current.is_symlink():
            return True
    return False


def validate_output_path(candidate: Path) -> Path:
    """Reject destructive output locations before any directory is removed."""
    expanded = lexical_absolute(candidate)
    if has_symlink_component(expanded):
        raise ValueError("Stage H output path must not contain a symbolic link")
    output = expanded.resolve()
    if output == ROOT or output in ROOT.parents:
        raise ValueError("Stage H output must not be the repository or one of its ancestors")
    if ROOT in output.parents:
        release_root = (ROOT / "release").resolve()
        if output == release_root or release_root not in output.parents:
            raise ValueError("In-repository Stage H output must be a child of release/")
    if output.exists() and (not output.is_dir() or output.is_symlink()):
        raise ValueError("Existing Stage H output must be a real directory")
    return output


def output_marker_valid(output: Path) -> bool:
    marker = output / OUTPUT_MARKER
    if not marker.is_file() or marker.is_symlink():
        return False
    try:
        value = json.loads(marker.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return False
    return value == OUTPUT_MARKER_PAYLOAD


def prepare_output_directory(output: Path) -> None:
    """Replace only an empty or previously marked Stage H output directory."""
    if output.exists():
        entries = list(output.iterdir())
        if entries and not output_marker_valid(output):
            raise ValueError(
                "Existing Stage H output is non-empty and lacks the exact Stage H marker"
            )
        shutil.rmtree(output)
    output.mkdir(parents=True)
    (output / OUTPUT_MARKER).write_text(
        json.dumps(OUTPUT_MARKER_PAYLOAD, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )


def require_input_outside_output(output: Path, candidate: Path | None, label: str) -> None:
    if candidate is None:
        return
    path = candidate.expanduser().resolve()
    if paths_overlap(output, path):
        raise ValueError(f"{label} must not overlap the Stage H output directory")


def validate_optional_directory(candidate: Path | None, label: str) -> None:
    if candidate is None:
        return
    if has_symlink_component(candidate):
        raise ValueError(f"{label} path must not contain a symbolic link")
    path = candidate.expanduser().resolve()
    if not path.is_dir():
        raise ValueError(f"{label} must be a real directory")


def validate_optional_file(candidate: Path | None, label: str) -> None:
    if candidate is None:
        return
    if has_symlink_component(candidate):
        raise ValueError(f"{label} path must not contain a symbolic link")
    path = candidate.expanduser().resolve()
    if not path.is_file():
        raise ValueError(f"{label} must be a real file")


def terminate(process: subprocess.Popen[bytes], grace: float = 5.0) -> None:
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        return
    deadline = time.monotonic() + grace
    while process.poll() is None and time.monotonic() < deadline:
        time.sleep(0.05)
    if process.poll() is None:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        try:
            process.wait(timeout=2)
        except subprocess.TimeoutExpired:
            pass


def tail(path: Path, lines: int = 40) -> str:
    try:
        rows = path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        return ""
    return "\n".join(rows[-lines:])


def run_phase(
    phases: list[dict[str, Any]],
    name: str,
    command: Sequence[str],
    cwd: Path,
    log: Path,
    timeout_seconds: int,
    environment: dict[str, str],
    allowed: set[int] | None = None,
) -> dict[str, Any]:
    allowed = allowed or {0}
    log.parent.mkdir(parents=True, exist_ok=True)
    started = time.monotonic()
    print(f"[Stage H] START {name}", flush=True)
    with log.open("wb") as stream:
        process = subprocess.Popen(
            list(command),
            cwd=cwd,
            env=environment,
            stdin=subprocess.DEVNULL,
            stdout=stream,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
        timed_out = False
        try:
            return_code = process.wait(timeout=timeout_seconds)
        except subprocess.TimeoutExpired:
            timed_out = True
            terminate(process)
            return_code = 124
    duration = round(time.monotonic() - started, 3)
    status = "passed" if return_code in allowed and not timed_out else "failed"
    row = {
        "name": name,
        "status": status,
        "return_code": return_code,
        "timed_out": timed_out,
        "duration_seconds": duration,
        "command": list(command),
        "cwd": str(cwd),
        "log": str(log),
    }
    phases.append(row)
    print(f"[Stage H] END   {name}: {status} ({duration:.1f}s, exit {return_code})", flush=True)
    if status != "passed":
        excerpt = tail(log)
        if excerpt:
            print(excerpt, file=sys.stderr, flush=True)
    return row


def composer_command(args: argparse.Namespace) -> Path:
    if args.composer:
        candidate = args.composer.resolve()
    else:
        found = shutil.which("composer")
        candidate = Path(found).resolve() if found else Path()
    if not candidate.is_file() or not os.access(candidate, os.X_OK):
        raise SystemExit("An executable Composer binary is required; pass --composer PATH")
    return candidate


def write_composer_wrapper(output: Path, composer: Path) -> Path:
    tools = output / "tools"
    tools.mkdir(parents=True, exist_ok=True)
    wrapper = tools / "composer"
    wrapper.write_text(
        "#!/usr/bin/env sh\nexec " + json.dumps(str(composer)) + " \"$@\"\n",
        encoding="utf-8",
    )
    wrapper.chmod(0o755)
    return tools


def extract_source(source_zip: Path, replay_root: Path) -> Path:
    """Extract the audited source ZIP without delegating path or mode handling to ZipFile."""
    if replay_root.exists():
        shutil.rmtree(replay_root)
    replay_root.mkdir(parents=True)
    destination = replay_root.resolve()
    seen: set[str] = set()
    seen_casefold: set[str] = set()
    total_uncompressed = 0
    with zipfile.ZipFile(source_zip) as archive:
        bad = archive.testzip()
        if bad is not None:
            raise RuntimeError(f"Source archive CRC verification failed: {bad}")
        for info in archive.infolist():
            name = info.filename
            member = Path(name)
            if (
                "\0" in name
                or name in seen
                or name.casefold() in seen_casefold
                or member.is_absolute()
                or ".." in member.parts
                or len(member.parts) < 2
                or member.parts[0] != "yassin-ai-assistant"
                or info.is_dir()
            ):
                raise RuntimeError(f"Unsafe or duplicate source archive member: {name}")
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
                raise RuntimeError(f"Unsupported source archive member: {name}")
            if info.file_size > MAX_SOURCE_MEMBER_UNCOMPRESSED:
                raise RuntimeError(f"Oversized source archive member: {name}")
            total_uncompressed += info.file_size
            if total_uncompressed > MAX_SOURCE_ARCHIVE_UNCOMPRESSED:
                raise RuntimeError("Source archive expands beyond the reviewed bound")
            target = (destination / member).resolve()
            if destination not in target.parents:
                raise RuntimeError(f"Source archive member escapes replay root: {name}")
            target.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(info, "r") as source, target.open("wb") as sink:
                shutil.copyfileobj(source, sink)
            target.chmod(permissions)
    root = destination / "yassin-ai-assistant"
    if not root.is_dir():
        raise RuntimeError("Source archive did not contain the canonical root")
    return root


def compare_archives(first: Path, second: Path) -> None:
    names = sorted(path.name for path in first.glob("*.zip"))
    if not names or names != sorted(path.name for path in second.glob("*.zip")):
        raise RuntimeError("Deterministic builds produced different archive sets")
    for name in names:
        if (first / name).read_bytes() != (second / name).read_bytes():
            raise RuntimeError(f"Deterministic archive mismatch: {name}")


def strict_promotion_status(data: Any) -> bool:
    if not isinstance(data, dict) or data.get("status") != "passed" or data.get("failures") != []:
        return False
    playwright = data.get("playwright")
    junit = data.get("junit")
    lifecycle = data.get("lifecycle")
    if not isinstance(playwright, dict) or not isinstance(junit, dict) or not isinstance(lifecycle, dict):
        return False
    total = playwright.get("total")
    if (
        not isinstance(total, int)
        or total < 1
        or playwright.get("passed") != total
        or any(playwright.get(key) != 0 for key in ("failed", "skipped", "flaky"))
        or data.get("expected_playwright_scenarios") != total
        or data.get("browser_traces") != total
        or junit.get("total") != total
        or any(junit.get(key) != 0 for key in ("failed", "errors", "skipped"))
    ):
        return False
    if any(
        data.get(key) is not True
        for key in (
            "installed_tree_matches_package",
            "clean_installed_tree_matches_package",
            "upgrade_installed_tree_matches_package",
            "installed_checksum_matches_package",
        )
    ):
        return False
    return (
        bool(lifecycle)
        and all(value is True for value in lifecycle.values())
        and data.get("woocommerce_critical_entries") == []
        and data.get("wordpress_debug_violations") == []
    )


def classify_network_failure(log: Path) -> str:
    text = log.read_text(encoding="utf-8", errors="replace").lower()
    network_markers = (
        "could not resolve host",
        "getaddrinfo",
        "temporary failure in name resolution",
        "failed to open stream",
        "connection timed out",
        "network is unreachable",
        "curl error 6",
        "could not connect",
        "connection refused",
        "eai_again",
        "enetwork",
        "network request to",
        "fetch failed",
        "registry.npmjs.org",
    )
    return "blocked_external" if any(marker in text for marker in network_markers) else "failed"


def write_summary(output: Path, result: dict[str, Any]) -> None:
    (output / "stage-h-result.json").write_text(
        json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    lines = [
        "# Stage H release-hardening summary",
        "",
        f"**Mode:** {result['mode']}",
        f"**Status:** {result['status'].upper()}",
        f"**Commit:** `{result['git']['commit']}`",
        f"**Tree:** `{result['git']['tree']}`",
        "",
        "## Phases",
        "",
    ]
    for phase in result["phases"]:
        lines.append(
            f"- {phase['name']}: **{phase['status'].upper()}** "
            f"(exit {phase['return_code']}, {phase['duration_seconds']}s)"
        )
    lines += ["", "## Packages", ""]
    for name, metadata in result.get("packages", {}).items():
        lines.append(f"- {name}: `{metadata['sha256']}` ({metadata['bytes']} bytes)")
    lines += ["", "## External publication evidence", ""]
    for name, status in result["external_evidence"].items():
        lines.append(f"- {name}: **{status.upper()}**")
    lines += ["", "## Source replay dependencies", ""]
    for name, method in result.get("source_replay_dependencies", {}).items():
        lines.append(f"- {name}: `{method}`")
    if result.get("failures"):
        lines += ["", "## Failures", ""] + [f"- {value}" for value in result["failures"]]
    if result.get("external_blockers"):
        lines += ["", "## Publication blockers", ""] + [f"- {value}" for value in result["external_blockers"]]
    lines.append("")
    (output / "stage-h-summary.md").write_text("\n".join(lines), encoding="utf-8")


def write_checksums(output: Path) -> None:
    packages = sorted((output / "packages").glob("*.zip"))
    (output / "SHA256SUMS").write_text(
        "".join(f"{sha256(path)}  packages/{path.name}\n" for path in packages),
        encoding="utf-8",
    )


def write_evidence_manifest(output: Path) -> None:
    ignored = {"evidence-manifest.json"}
    rows = []
    for path in sorted(output.rglob("*")):
        if not path.is_file():
            continue
        relative = path.relative_to(output).as_posix()
        if relative in ignored or relative.startswith(("source-replay/", "build-a/", "build-b/", "tools/")):
            continue
        rows.append({"path": relative, "sha256": sha256(path), "bytes": path.stat().st_size})
    payload = {"schema": 1, "files": rows}
    (output / "evidence-manifest.json").write_text(
        json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )


def main() -> int:
    args = parse_args()
    if not args.allow_dirty and not clean_tree():
        raise SystemExit(
            "Stage H requires a clean Git tree; commit the candidate or use "
            "--allow-dirty only while developing the gate"
        )
    try:
        output = validate_output_path(args.output)
        composer = composer_command(args)
        validate_optional_directory(args.offline_vendor, "Offline Composer tree")
        validate_optional_directory(args.offline_node_modules, "Offline Node tree")
        validate_optional_file(args.woocommerce_zip, "WooCommerce package")
        require_input_outside_output(output, composer, "Composer executable")
        require_input_outside_output(output, args.offline_vendor, "Offline Composer tree")
        require_input_outside_output(output, args.offline_node_modules, "Offline Node tree")
        require_input_outside_output(output, args.woocommerce_zip, "WooCommerce package")
        prepare_output_directory(output)
    except ValueError as exc:
        raise SystemExit(str(exc)) from exc
    logs = output / "logs"
    phases: list[dict[str, Any]] = []
    failures: list[str] = []
    external_blockers: list[str] = []
    tools = write_composer_wrapper(output, composer)
    environment = os.environ.copy()
    environment["PATH"] = str(tools) + os.pathsep + environment.get("PATH", "")
    environment["YSAI_SKIP_BROWSER_TESTS"] = "0"

    context = {
        "schema": 1,
        "captured_at": utc_now(),
        "mode": args.mode,
        "git": {
            "branch": git_value(["branch", "--show-current"]),
            "commit": git_value(["rev-parse", "HEAD"]),
            "tree": git_value(["rev-parse", "HEAD^{tree}"]),
            "clean": clean_tree(),
        },
        "host": {
            "python": sys.version.split()[0],
            "php": subprocess.run(["php", "-r", "echo PHP_VERSION;"], stdout=subprocess.PIPE, text=True, check=False).stdout,
            "node": subprocess.run(["node", "--version"], stdout=subprocess.PIPE, text=True, check=False).stdout.strip(),
            "composer": str(composer),
        },
    }
    (output / "stage-h-context.json").write_text(
        json.dumps(context, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    shutil.copy2(ROOT / "config/quality/release-hardening-policy.json", output / "release-hardening-policy.json")
    shutil.copy2(ROOT / "config/quality/release-hardening-findings.json", output / "release-hardening-findings.json")

    preflight = run_phase(
        phases,
        "hardening-preflight",
        [
            sys.executable,
            "scripts/quality/verify-release-hardening.py",
            "--mode",
            "item9",
            *(["--require-clean"] if not args.allow_dirty else []),
            "--json-output",
            str(output / "hardening-preflight.json"),
        ],
        ROOT,
        logs / "hardening-preflight.log",
        120,
        environment,
    )
    if preflight["status"] != "passed":
        failures.append("hardening-preflight")

    if not failures:
        lock_verify = run_phase(
            phases,
            "composer-lock-install-verification",
            [sys.executable, "scripts/quality/verify-composer-install.py"],
            ROOT,
            logs / "composer-lock-install-verification.log",
            120,
            environment,
        )
        if lock_verify["status"] != "passed":
            failures.append("composer-lock-install-verification")

    if not failures:
        hardening_self_test = run_phase(
            phases,
            "hardening-self-test",
            [sys.executable, "scripts/quality/self-test-release-hardening.py"],
            ROOT,
            logs / "hardening-self-test.log",
            180,
            environment,
        )
        if hardening_self_test["status"] != "passed":
            failures.append("hardening-self-test")

    if not failures:
        quality = run_phase(
            phases,
            "committed-tree-quality-gate",
            ["bash", "scripts/quality-gate.sh"],
            ROOT,
            logs / "committed-tree-quality-gate.log",
            args.quality_timeout_seconds,
            environment,
        )
        if quality["status"] != "passed":
            failures.append("committed-tree-quality-gate")

    build_a = output / "build-a"
    build_b = output / "build-b"
    build_a.mkdir(); build_b.mkdir()
    if not failures:
        first = run_phase(
            phases,
            "deterministic-build-a",
            [sys.executable, "scripts/package.py", "--output", str(build_a)],
            ROOT,
            logs / "deterministic-build-a.log",
            300,
            environment,
        )
        second = run_phase(
            phases,
            "deterministic-build-b",
            [sys.executable, "scripts/package.py", "--output", str(build_b)],
            ROOT,
            logs / "deterministic-build-b.log",
            300,
            environment,
        )
        if first["status"] != "passed" or second["status"] != "passed":
            failures.append("deterministic-build")
        else:
            try:
                compare_archives(build_a, build_b)
            except RuntimeError as exc:
                failures.append(str(exc))

    packages_dir = output / "packages"
    packages_dir.mkdir()
    package_meta: dict[str, dict[str, Any]] = {}
    production_zip: Path | None = None
    source_zip: Path | None = None
    if not failures:
        for archive in sorted(build_a.glob("*.zip")):
            target = packages_dir / archive.name
            shutil.copy2(archive, target)
            package_meta[target.name] = {"sha256": sha256(target), "bytes": target.stat().st_size}
            if target.name.endswith("-source.zip"):
                source_zip = target
            else:
                production_zip = target
        if production_zip is None or source_zip is None:
            failures.append("deterministic package set is incomplete")
        else:
            archive_phase = run_phase(
                phases,
                "archive-audit",
                [
                    sys.executable,
                    "scripts/quality/verify-release-archives.py",
                    "--production",
                    str(production_zip),
                    "--source",
                    str(source_zip),
                    "--manifest",
                    str(output / "archive-manifest.json"),
                ],
                ROOT,
                logs / "archive-audit.log",
                300,
                environment,
            )
            if archive_phase["status"] != "passed":
                failures.append("archive-audit")
            else:
                smoke_phase = run_phase(
                    phases,
                    "packaged-production-smoke",
                    [
                        sys.executable,
                        "scripts/quality/smoke-production-archive.py",
                        "--archive",
                        str(production_zip),
                        "--json-output",
                        str(output / "packaged-production-smoke.json"),
                    ],
                    ROOT,
                    logs / "packaged-production-smoke.log",
                    900,
                    environment,
                )
                if smoke_phase["status"] != "passed":
                    failures.append("packaged-production-smoke")

    clean_composer = False
    source_replay_passed = False
    node_install_method = "not_run"
    composer_install_method = "not_run"
    replay_root = output / "source-replay"
    if not failures and source_zip is not None:
        replay = extract_source(source_zip, replay_root)
        npm_log = logs / "source-replay-npm-ci.log"
        npm_install = run_phase(
            phases,
            "source-replay-npm-ci",
            ["npm", "ci", "--no-audit", "--no-fund"],
            replay,
            npm_log,
            600,
            environment,
        )
        if npm_install["status"] == "passed":
            node_verify = run_phase(
                phases,
                "source-replay-clean-node-verification",
                [
                    sys.executable,
                    "scripts/quality/verify-node-install.py",
                    "--method",
                    "clean",
                    "--json-output",
                    str(output / "clean-node-install.json"),
                ],
                replay,
                logs / "source-replay-clean-node-verification.log",
                120,
                environment,
            )
            if node_verify["status"] == "passed":
                node_install_method = "clean"
            else:
                failures.append("source-replay-clean-node-verification")
        elif classify_network_failure(npm_log) == "blocked_external" and args.offline_node_modules:
            offline_node = args.offline_node_modules.resolve()
            if not offline_node.is_dir() or offline_node.is_symlink():
                failures.append("offline Node tree is malformed")
            else:
                shutil.rmtree(replay / "node_modules", ignore_errors=True)
                shutil.copytree(offline_node, replay / "node_modules", symlinks=True)
                npm_verify = run_phase(
                    phases,
                    "source-replay-offline-node-verify",
                    [
                        sys.executable,
                        "scripts/quality/verify-node-install.py",
                        "--method",
                        "offline_lock_match",
                        "--json-output",
                        str(output / "offline-node-install.json"),
                    ],
                    replay,
                    logs / "source-replay-offline-node-verify.log",
                    120,
                    environment,
                )
                if npm_verify["status"] == "passed":
                    node_install_method = "offline_lock_match"
                else:
                    failures.append("source-replay-offline-node-verify")
        else:
            failures.append("source-replay-node-install")

        composer_log = output / "source-package-composer-install.log"
        if not failures:
            composer_install = run_phase(
                phases,
                "source-replay-composer-install",
                [str(composer), "install", "--no-interaction", "--prefer-dist", "--no-progress", "--no-ansi"],
                replay,
                composer_log,
                600,
                environment,
            )
            if composer_install["status"] == "passed":
                clean_verify = run_phase(
                    phases,
                    "source-replay-clean-composer-verification",
                    [
                        sys.executable,
                        "scripts/quality/verify-composer-install.py",
                        "--method",
                        "clean",
                        "--json-output",
                        str(output / "clean-composer-install.json"),
                    ],
                    replay,
                    logs / "source-replay-clean-composer-verification.log",
                    120,
                    environment,
                )
                clean_composer = clean_verify["status"] == "passed"
                if clean_composer:
                    composer_install_method = "clean"
                if not clean_composer:
                    failures.append("source-replay-clean-composer-verification")
            elif classify_network_failure(composer_log) == "blocked_external" and args.offline_vendor:
                offline_vendor = args.offline_vendor.resolve()
                if not (offline_vendor / "composer").is_dir():
                    failures.append("offline vendor tree is malformed")
                else:
                    shutil.rmtree(replay / "vendor", ignore_errors=True)
                    shutil.copytree(offline_vendor, replay / "vendor", symlinks=True)
                    verify_vendor = run_phase(
                        phases,
                        "source-replay-offline-composer-verify",
                        [
                            sys.executable,
                            "scripts/quality/verify-composer-install.py",
                            "--method",
                            "offline_lock_match",
                            "--json-output",
                            str(output / "offline-composer-install.json"),
                        ],
                        replay,
                        logs / "source-replay-offline-composer-verify.log",
                        120,
                        environment,
                    )
                    if verify_vendor["status"] != "passed":
                        failures.append("source-replay-offline-composer-verify")
                    else:
                        composer_install_method = "offline_lock_match"
                        external_blockers.append("H-202 clean Composer install unavailable; exact offline metadata used")
            else:
                failures.append("source-replay-composer-install")

        if not failures:
            source_quality = run_phase(
                phases,
                "extracted-source-quality-gate",
                ["bash", "scripts/quality-gate.sh"],
                replay,
                logs / "extracted-source-quality-gate.log",
                args.source_replay_timeout_seconds,
                environment,
            )
            source_replay_passed = source_quality["status"] == "passed"
            if not source_replay_passed:
                failures.append("extracted-source-quality-gate")

    composer_audit_raw = output / "composer-audit-raw.json"
    audit = run_phase(
        phases,
        "composer-advisory-query",
        [
            str(composer),
            "audit",
            "--locked",
            "--format=json",
            "--abandoned=fail",
            "--no-interaction",
            "--no-ansi",
        ],
        ROOT,
        composer_audit_raw,
        300,
        environment,
    )
    composer_audit_passed = False
    if audit["status"] == "passed":
        audit_verify = run_phase(
            phases,
            "composer-advisory-evidence-verification",
            [
                sys.executable,
                "scripts/quality/verify-composer-audit.py",
                "--input",
                str(composer_audit_raw),
                "--output",
                str(output / "composer-audit.json"),
            ],
            ROOT,
            logs / "composer-advisory-evidence-verification.log",
            120,
            environment,
        )
        composer_audit_passed = audit_verify["status"] == "passed"
        if not composer_audit_passed:
            failures.append("composer-advisory-evidence-verification")
    elif classify_network_failure(composer_audit_raw) == "blocked_external":
        audit["status"] = "blocked_external"
        external_blockers.append("H-202 composer advisory service unavailable")
    else:
        failures.append("composer-advisory-query")

    promotion_passed = False
    if production_zip is not None and args.woocommerce_zip:
        woo_zip = args.woocommerce_zip.resolve()
        if not woo_zip.is_file():
            failures.append("WooCommerce ZIP does not exist")
        else:
            promotion_dir = output / "woocommerce-promotion"
            promotion = run_phase(
                phases,
                "woocommerce-artifact-promotion",
                [
                    "bash",
                    "scripts/run-woocommerce-promotion-gate.sh",
                    "--plugin-zip",
                    str(production_zip),
                    "--sha256",
                    sha256(production_zip),
                    "--woocommerce-version",
                    args.woocommerce_version,
                    "--woocommerce-zip",
                    str(woo_zip),
                    "--artifacts-dir",
                    str(promotion_dir),
                    *(["--keep-environment"] if args.keep_environment else []),
                ],
                ROOT,
                logs / "woocommerce-artifact-promotion.log",
                3600,
                environment,
                allowed={0, EXTERNAL_BLOCKED},
            )
            status_file = promotion_dir / "promotion-status.json"
            if promotion["return_code"] == 0 and status_file.is_file():
                try:
                    promotion_payload = json.loads(status_file.read_text(encoding="utf-8"))
                    promotion_passed = strict_promotion_status(promotion_payload)
                except (OSError, json.JSONDecodeError):
                    promotion_passed = False
                if not promotion_passed:
                    failures.append("woocommerce-promotion-evidence-invalid")
            elif promotion["return_code"] == 0:
                failures.append("woocommerce-promotion-status-missing")
            elif promotion["return_code"] == EXTERNAL_BLOCKED:
                promotion["status"] = "blocked_external"
                external_blockers.append("H-201 container runtime unavailable")
            else:
                failures.append("woocommerce-artifact-promotion")
    else:
        external_blockers.append("H-201 exact WooCommerce promotion evidence not supplied")

    if not clean_composer:
        external_blockers.append("H-202 clean Composer install not independently proven")

    evidence_audit = run_phase(
        phases,
        "hardening-post-audit",
        [
            sys.executable,
            "scripts/quality/verify-release-hardening.py",
            "--mode",
            args.mode,
            "--evidence-dir",
            str(output),
            "--json-output",
            str(output / "hardening-post-audit.json"),
        ],
        ROOT,
        logs / "hardening-post-audit.log",
        120,
        environment,
        allowed={0, EXTERNAL_BLOCKED},
    )
    if evidence_audit["return_code"] == EXTERNAL_BLOCKED:
        evidence_audit["status"] = "blocked_external"
    elif evidence_audit["status"] != "passed":
        failures.append("hardening-post-audit")

    external_evidence = {
        "clean_composer_install": "passed" if clean_composer else "blocked_external",
        "composer_advisory_audit": "passed" if composer_audit_passed else "blocked_external",
        "woocommerce_promotion": "passed" if promotion_passed else "blocked_external",
        "source_package_replay": "passed" if source_replay_passed else "failed",
    }
    if failures:
        status = "failed"
    elif args.mode == "publication" and external_blockers:
        status = "blocked_external"
    elif args.mode == "item9" and external_blockers:
        status = "passed_item9_with_publication_blockers"
    else:
        status = "passed"
    result = {
        **context,
        "completed_at": utc_now(),
        "status": status,
        "phases": phases,
        "packages": package_meta,
        "external_evidence": external_evidence,
        "source_replay_dependencies": {
            "node": node_install_method,
            "composer": composer_install_method,
        },
        "external_blockers": sorted(set(external_blockers)),
        "failures": failures,
    }
    write_summary(output, result)
    write_checksums(output)
    write_evidence_manifest(output)
    if not args.keep_replay:
        shutil.rmtree(replay_root, ignore_errors=True)
        shutil.rmtree(build_a, ignore_errors=True)
        shutil.rmtree(build_b, ignore_errors=True)

    if failures:
        return 1
    if args.mode == "publication" and external_blockers:
        return EXTERNAL_BLOCKED
    print(
        "[Stage H] item-9 gate passed; publication remains externally blocked."
        if external_blockers
        else "[Stage H] complete hardening gate passed.",
        flush=True,
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
