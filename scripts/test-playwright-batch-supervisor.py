#!/usr/bin/env python3
"""Prove the Playwright supervisor retires isolated and detached descendants."""

from __future__ import annotations

import json
import os
import secrets
import shutil
import signal
import subprocess
import sys
import tempfile
import textwrap
import time
from pathlib import Path

SELF_TEST_TOKEN = "YSAI_PLAYWRIGHT_SUPERVISOR_SELF_TEST_TOKEN"
CASE_TIMEOUT_SECONDS = 45


def marked_process_ids(token: str) -> set[int]:
    """Return Linux processes that inherited this self-test case marker."""

    proc = Path("/proc")
    if not proc.is_dir():
        return set()
    marker = f"{SELF_TEST_TOKEN}={token}".encode("utf-8")
    found: set[int] = set()
    for entry in proc.iterdir():
        if not entry.name.isdigit():
            continue
        try:
            environment = (entry / "environ").read_bytes().split(b"\0")
        except (FileNotFoundError, PermissionError, ProcessLookupError, OSError):
            continue
        if marker in environment:
            found.add(int(entry.name))
    return found


def signal_marked_processes(token: str, sig: int) -> None:
    for pid in sorted(marked_process_ids(token), reverse=True):
        try:
            os.kill(pid, sig)
        except (ProcessLookupError, PermissionError):
            continue


def wait_for_marked_exit(token: str, seconds: float) -> bool:
    deadline = time.monotonic() + max(0.0, seconds)
    while marked_process_ids(token) and time.monotonic() < deadline:
        time.sleep(0.05)
    return not marked_process_ids(token)


def terminate_case(process: subprocess.Popen[bytes], token: str) -> None:
    """Retire the supervisor and every detached process in this test case."""

    if process.poll() is None:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
    signal_marked_processes(token, signal.SIGTERM)
    if process.poll() is None:
        try:
            process.wait(timeout=3)
        except subprocess.TimeoutExpired:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
    if not wait_for_marked_exit(token, 3):
        signal_marked_processes(token, signal.SIGKILL)
        wait_for_marked_exit(token, 2)
    try:
        process.wait(timeout=2)
    except subprocess.TimeoutExpired:
        pass


def run_case(root: Path, work: Path, name: str, leader_exits: bool) -> None:
    report = work / f"{name}-report.json"
    expected = work / f"{name}-expected.json"
    proof = work / f"{name}-proof.json"
    ready = work / f"{name}-ready"
    terminated = work / f"{name}-terminated"
    log = work / f"{name}.log"
    expected.write_text(json.dumps({"cases": [{"id": name}]}), encoding="utf-8")

    descendant = textwrap.dedent(
        f"""\
        import os, signal, time
        def stop(_sig, _frame):
            open({str(terminated)!r}, 'w', encoding='utf-8').write('terminated\\n')
            raise SystemExit(0)
        signal.signal(signal.SIGTERM, stop)
        open({str(ready)!r}, 'w', encoding='utf-8').write(str(os.getpid()))
        while True:
            time.sleep(1)
        """
    )
    leader_tail = "raise SystemExit(0)" if leader_exits else "time.sleep(60)"
    leader = textwrap.dedent(
        f"""\
        import json, os, subprocess, sys, time
        subprocess.Popen([sys.executable, '-c', {descendant!r}], start_new_session=True)
        while not os.path.exists({str(ready)!r}):
            time.sleep(0.01)
        open({str(proof)!r}, 'w', encoding='utf-8').write(json.dumps({{
            'schema_version': 1,
            'status': 'passed',
            'expected': 1,
            'completed': 1,
            'clean': True
        }}))
        {leader_tail}
        """
    )
    command = [
        sys.executable,
        str(root / "scripts/run-playwright-batch.py"),
        "--report", str(report),
        "--expected", str(expected),
        "--proof", str(proof),
        "--timeout-seconds", "20",
        "--completion-grace-seconds", "0.2",
        "--termination-grace-seconds", "3",
        "--", sys.executable, "-c", leader,
    ]
    token = secrets.token_hex(24)
    environment = os.environ.copy()
    environment[SELF_TEST_TOKEN] = token
    with log.open("wb") as output:
        process = subprocess.Popen(
            command,
            cwd=root,
            stdin=subprocess.DEVNULL,
            stdout=output,
            stderr=subprocess.STDOUT,
            start_new_session=True,
            env=environment,
        )
        try:
            return_code = process.wait(timeout=CASE_TIMEOUT_SECONDS)
        except subprocess.TimeoutExpired as error:
            terminate_case(process, token)
            detail = log.read_text(encoding="utf-8", errors="replace")
            raise RuntimeError(
                f"{name}: supervisor exceeded {CASE_TIMEOUT_SECONDS} seconds.\n{detail}"
            ) from error
        finally:
            if process.poll() is None or marked_process_ids(token):
                terminate_case(process, token)

    detail = log.read_text(encoding="utf-8", errors="replace")
    if return_code != 0:
        raise RuntimeError(f"{name}: supervisor returned {return_code}.\n{detail}")
    if marked_process_ids(token):
        terminate_case(process, token)
        raise RuntimeError(f"{name}: marked descendant survived supervisor completion.\n{detail}")
    if not ready.is_file() or not terminated.is_file():
        raise RuntimeError(f"{name}: detached descendant was not terminated.\n{detail}")


def main() -> int:
    root = Path(__file__).resolve().parent.parent
    temp = Path(tempfile.mkdtemp(prefix="ysai-playwright-supervisor-"))
    try:
        run_case(root, temp, "leader-exits", leader_exits=True)
        run_case(root, temp, "leader-lingers", leader_exits=False)
    except (OSError, RuntimeError) as error:
        print(f"Playwright supervisor self-test failed: {error}", file=sys.stderr)
        return 1
    finally:
        shutil.rmtree(temp, ignore_errors=True)
    print("Playwright batch supervisor self-test passed for exited and lingering leaders.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
