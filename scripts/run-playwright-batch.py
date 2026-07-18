#!/usr/bin/env python3
"""Run one Playwright batch with exact-report-aware teardown supervision."""

from __future__ import annotations

import argparse
import os
import secrets
import signal
import subprocess
import sys
import time
from pathlib import Path
from typing import Sequence


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument('--report', required=True)
    parser.add_argument('--expected', required=True)
    parser.add_argument('--proof', required=True)
    parser.add_argument('--timeout-seconds', required=True, type=int)
    parser.add_argument('--completion-grace-seconds', type=float, default=3.0)
    parser.add_argument('--termination-grace-seconds', type=float, default=5.0)
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if args.command and args.command[0] == '--':
        args.command = args.command[1:]
    if not args.command:
        parser.error('a Playwright command is required after --')
    if args.timeout_seconds < 1:
        parser.error('--timeout-seconds must be positive')
    if args.completion_grace_seconds < 0 or args.termination_grace_seconds < 0:
        parser.error('grace periods must be non-negative')
    return args


def proof_is_clean(proof: Path, expected: Path) -> bool:
    if not proof.is_file() or proof.stat().st_size < 2:
        return False
    try:
        import json
        proof_data = json.loads(proof.read_text(encoding='utf-8'))
        expected_data = json.loads(expected.read_text(encoding='utf-8'))
    except (OSError, ValueError, TypeError):
        return False
    cases = expected_data.get('cases') if isinstance(expected_data, dict) else None
    expected_count = len(cases) if isinstance(cases, list) else 0
    return bool(
        expected_count > 0
        and isinstance(proof_data, dict)
        and proof_data.get('schema_version') == 1
        and proof_data.get('status') == 'passed'
        and proof_data.get('clean') is True
        and proof_data.get('expected') == expected_count
        and proof_data.get('completed') == expected_count
    )


def report_is_exact(report: Path, expected: Path) -> bool:
    if not report.is_file() or report.stat().st_size < 2:
        return False
    try:
        result = subprocess.run(
            [
                'node',
                'scripts/verify-browser-suite.js',
                'report',
                str(report),
                str(expected),
            ],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
            timeout=3,
        )
    except (OSError, subprocess.TimeoutExpired):
        return False
    return result.returncode == 0


def token_process_ids(token: str) -> set[int]:
    """Return Linux descendants carrying this batch's private environment token."""

    proc = Path('/proc')
    if not proc.is_dir():
        return set()
    marker = f'YSAI_PLAYWRIGHT_BATCH_TOKEN={token}'.encode('utf-8')
    found: set[int] = set()
    for entry in proc.iterdir():
        if not entry.name.isdigit():
            continue
        pid = int(entry.name)
        try:
            environment = (entry / 'environ').read_bytes().split(b'\0')
        except (FileNotFoundError, PermissionError, ProcessLookupError, OSError):
            continue
        if marker in environment:
            found.add(pid)
    return found


def signal_token_processes(token: str, sig: int) -> None:
    current = os.getpid()
    for pid in sorted(token_process_ids(token), reverse=True):
        if pid == current:
            continue
        try:
            os.kill(pid, sig)
        except (ProcessLookupError, PermissionError):
            continue


def wait_for_token_processes(token: str, seconds: float) -> bool:
    deadline = time.monotonic() + max(0.0, seconds)
    while token_process_ids(token) and time.monotonic() < deadline:
        time.sleep(0.05)
    return not token_process_ids(token)


def group_exists(group_id: int) -> bool:
    try:
        os.killpg(group_id, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


def signal_group(group_id: int, sig: int) -> None:
    try:
        os.killpg(group_id, sig)
    except ProcessLookupError:
        return


def wait_for_group_exit(group_id: int, seconds: float) -> bool:
    deadline = time.monotonic() + max(0.0, seconds)
    while group_exists(group_id) and time.monotonic() < deadline:
        time.sleep(0.05)
    return not group_exists(group_id)


def terminate_group(process: subprocess.Popen[bytes], token: str, grace_seconds: float) -> None:
    # The Playwright leader may exit while Chromium or Node descendants in its
    # isolated session still hold stdout/stderr open. Clean the process group
    # by its stable PGID even after the leader has already been reaped.
    group_id = process.pid
    process.poll()
    group_alive = group_exists(group_id)
    token_alive = bool(token_process_ids(token))
    if not group_alive and not token_alive:
        return
    if group_alive:
        signal_group(group_id, signal.SIGTERM)
    signal_token_processes(token, signal.SIGTERM)
    group_gone = wait_for_group_exit(group_id, grace_seconds)
    token_gone = wait_for_token_processes(token, grace_seconds)
    if group_gone and token_gone:
        process.poll()
        return
    if group_exists(group_id):
        signal_group(group_id, signal.SIGKILL)
    signal_token_processes(token, signal.SIGKILL)
    wait_for_group_exit(group_id, 2.0)
    wait_for_token_processes(token, 2.0)
    process.poll()


def run(command: Sequence[str], report: Path, expected: Path, proof: Path, timeout_seconds: int,
        completion_grace_seconds: float, termination_grace_seconds: float) -> int:
    for generated in (report, proof):
        try:
            generated.unlink()
        except FileNotFoundError:
            pass

    token = secrets.token_hex(24)
    environment = os.environ.copy()
    environment['YSAI_PLAYWRIGHT_BATCH_TOKEN'] = token
    process = subprocess.Popen(
        list(command),
        stdin=subprocess.DEVNULL,
        start_new_session=True,
        env=environment,
    )
    deadline = time.monotonic() + timeout_seconds
    proof_exact_since: float | None = None
    report_exact_since: float | None = None
    last_report_signature: tuple[int, int] | None = None
    last_verify_at = 0.0

    try:
        while True:
            exit_code = process.poll()
            now = time.monotonic()

            if proof_is_clean(proof, expected):
                proof_exact_since = proof_exact_since or now

            if report.is_file():
                stat = report.stat()
                signature = (stat.st_size, stat.st_mtime_ns)
                should_verify = signature != last_report_signature or now - last_verify_at >= 0.5
                if should_verify:
                    last_report_signature = signature
                    last_verify_at = now
                    if report_is_exact(report, expected):
                        report_exact_since = report_exact_since or now
                    else:
                        report_exact_since = None

            exact_candidates = [
                value for value in (proof_exact_since, report_exact_since)
                if value is not None
            ]
            exact_since = min(exact_candidates) if exact_candidates else None

            if exit_code is not None:
                # A successful Playwright leader can leave a detached browser
                # descendant holding the caller's output pipe. Always retire
                # the isolated process group before returning its exit code.
                terminate_group(process, token, termination_grace_seconds)
                return exit_code

            if exact_since is not None and now - exact_since >= completion_grace_seconds:
                # Playwright has completed every expected case and emitted its
                # exact final report, but a Node/Chromium teardown handle is
                # still alive. Terminate only this isolated process group.
                terminate_group(process, token, termination_grace_seconds)
                if process.poll() is None:
                    print('Playwright batch remained alive after verified completion and could not be terminated.', file=sys.stderr)
                    return 1
                print('Playwright batch report completed exactly; terminated a lingering isolated runner.')
                return 0

            if now >= deadline:
                terminate_group(process, token, termination_grace_seconds)
                print(
                    f'Playwright batch exceeded {timeout_seconds} seconds before an exact report was available.',
                    file=sys.stderr,
                )
                return 124

            time.sleep(0.1)
    except BaseException:
        terminate_group(process, token, termination_grace_seconds)
        raise


def main() -> int:
    args = parse_args()
    return run(
        args.command,
        Path(args.report),
        Path(args.expected),
        Path(args.proof),
        args.timeout_seconds,
        args.completion_grace_seconds,
        args.termination_grace_seconds,
    )


if __name__ == '__main__':
    raise SystemExit(main())
