#!/usr/bin/env python3
"""Validate the closed Stage H hardening policy and findings ledger."""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Any, Iterable

DEFAULT_ROOT = Path(__file__).resolve().parents[2]
ALLOWED_MODES = {"source", "item9", "publication"}
ALLOWED_STATUSES = {
    "resolved",
    "accepted_debt",
    "planned_item9",
    "external_evidence_required",
}
ALLOWED_SEVERITIES = {"critical", "high", "medium", "low"}
ALLOWED_SCOPES = {"item9", "publication", "maintenance"}
FINDING_KEYS = {
    "id",
    "category",
    "severity",
    "status",
    "scopes",
    "summary",
    "rationale",
    "guard",
    "resolution",
    "evidence",
    "review_by",
}
POLICY_KEYS = {
    "schema",
    "gate",
    "project_state",
    "stage_g_authority",
    "modes",
    "static_debt",
    "maintainability",
    "inline_suppressions",
    "dependency_locks",
    "external_evidence",
}
TEXT_SUFFIXES = {
    ".php", ".js", ".css", ".json", ".md", ".txt", ".py", ".sh", ".xml", ".neon",
}
SECRET_PATTERNS = {
    "private_key": re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    "google_api_key": re.compile(r"\bAIza[0-9A-Za-z_-]{35}\b"),
    "aws_access_key": re.compile(r"\bAKIA[0-9A-Z]{16}\b"),
    "openai_style_key": re.compile(r"\bsk-[A-Za-z0-9_-]{20,}\b"),
}
SUPPRESSION_PATTERN = re.compile(
    r"phpcs:(?:ignore|disable)\b|@phpstan-ignore(?:-line|-next-line)?\b|@psalm-suppress\b"
)
PRODUCTION_HYGIENE_ROOTS = (
    "src",
    "assets",
    "config",
    "yassin-ai-assistant.php",
    "uninstall.php",
)


class HardeningFailure(RuntimeError):
    """One or more closed hardening invariants failed."""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=DEFAULT_ROOT)
    parser.add_argument("--policy", type=Path)
    parser.add_argument("--ledger", type=Path)
    parser.add_argument("--mode", choices=sorted(ALLOWED_MODES), default="source")
    parser.add_argument("--evidence-dir", type=Path)
    parser.add_argument("--require-clean", action="store_true")
    parser.add_argument("--json-output", type=Path)
    return parser.parse_args()


def read_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise HardeningFailure(f"Invalid JSON authority {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise HardeningFailure(f"Expected one JSON object: {path}")
    return value


def sha256(path: Path) -> str:
    try:
        return hashlib.sha256(path.read_bytes()).hexdigest()
    except OSError as exc:
        raise HardeningFailure(f"Unable to read checksum authority: {path}") from exc


def strict_keys(value: dict[str, Any], expected: set[str], label: str) -> None:
    actual = set(value)
    if actual != expected:
        raise HardeningFailure(
            f"{label} keys are not exact; missing={sorted(expected - actual)}, "
            f"extra={sorted(actual - expected)}"
        )


def require_text(value: Any, label: str) -> str:
    if not isinstance(value, str) or value == "" or value != value.strip():
        raise HardeningFailure(f"{label} must be a non-empty, outer-whitespace-free string")
    return value


def parse_review_date(value: Any, label: str) -> dt.date | None:
    if value is None:
        return None
    if not isinstance(value, str) or re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}", value) is None:
        raise HardeningFailure(f"{label} must be null or YYYY-MM-DD")
    try:
        return dt.date.fromisoformat(value)
    except ValueError as exc:
        raise HardeningFailure(f"{label} is not a real date") from exc


def phpstan_baseline(path: Path) -> tuple[int, set[str]]:
    try:
        source = path.read_text(encoding="utf-8")
    except OSError as exc:
        raise HardeningFailure(f"PHPStan baseline is unreadable: {path}") from exc
    if "ignoreErrors:" not in source:
        raise HardeningFailure(f"PHPStan baseline is empty or malformed: {path}")
    blocks = source.split("\n\t\t-\n")[1:]
    if not blocks:
        raise HardeningFailure(f"PHPStan baseline has no exact entries: {path}")
    total = 0
    identifiers: set[str] = set()
    for block in blocks:
        if "message:" not in block or "identifier:" not in block or "path:" not in block:
            raise HardeningFailure(f"Broad PHPStan baseline entry: {path}")
        count = re.search(r"^\s*count:\s*([0-9]+)\s*$", block, re.MULTILINE)
        identifier = re.search(r"^\s*identifier:\s*(\S+)\s*$", block, re.MULTILINE)
        if count is None or int(count.group(1)) < 1 or identifier is None:
            raise HardeningFailure(f"PHPStan baseline entry is not exact: {path}")
        total += int(count.group(1))
        identifiers.add(identifier.group(1))
    return total, identifiers


def phpcs_count(path: Path) -> int:
    data = read_json(path)
    totals = data.get("totals")
    violations = data.get("violations")
    if (
        not isinstance(totals, dict)
        or not isinstance(totals.get("violations"), int)
        or not isinstance(violations, dict)
        or any(not isinstance(key, str) or not isinstance(value, int) or value < 1 for key, value in violations.items())
    ):
        raise HardeningFailure(f"PHPCS-style baseline has no exact ledger: {path}")
    if sum(violations.values()) != int(totals["violations"]):
        raise HardeningFailure(f"PHPCS-style baseline total does not match its ledger: {path}")
    return int(totals["violations"])


def count_lines(path: Path) -> int:
    try:
        return len(path.read_text(encoding="utf-8").splitlines())
    except OSError as exc:
        raise HardeningFailure(f"Unable to read maintainability target: {path}") from exc


def validate_stage_g_authority(root: Path, authority: dict[str, Any]) -> None:
    strict_keys(authority, {"commit", "tree", "source_archive_sha256"}, "Stage G authority")
    commit = require_text(authority.get("commit"), "Stage G commit authority")
    tree = require_text(authority.get("tree"), "Stage G tree authority")
    archive_hash = require_text(authority.get("source_archive_sha256"), "Stage G source checksum")
    if re.fullmatch(r"[a-f0-9]{40}", commit) is None:
        raise HardeningFailure("Stage G commit authority is malformed")
    if re.fullmatch(r"[a-f0-9]{40}", tree) is None:
        raise HardeningFailure("Stage G tree authority is malformed")
    if re.fullmatch(r"[a-f0-9]{64}", archive_hash) is None:
        raise HardeningFailure("Stage G source archive checksum is malformed")
    if not (root / ".git").exists():
        return
    exists = subprocess.run(
        ["git", "cat-file", "-e", commit + "^{commit}"],
        cwd=root,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
        timeout=10,
    )
    if exists.returncode != 0:
        raise HardeningFailure("Recorded Stage G authority is not present in repository history")
    resolved_tree = subprocess.run(
        ["git", "rev-parse", commit + "^{tree}"],
        cwd=root,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
        timeout=10,
    )
    if resolved_tree.returncode != 0 or resolved_tree.stdout.strip() != tree:
        raise HardeningFailure("Recorded Stage G commit does not resolve to the reviewed tree authority")
    ancestor = subprocess.run(
        ["git", "merge-base", "--is-ancestor", commit, "HEAD"],
        cwd=root,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
        timeout=10,
    )
    if ancestor.returncode != 0:
        raise HardeningFailure("Recorded Stage G authority is not an ancestor of the candidate")


def validate_static_debt(root: Path, debt: Any) -> tuple[dict[str, int], dict[str, set[str]]]:
    expected = {"phpstan_core", "phpstan_adapters", "phpcs", "php_compatibility"}
    if not isinstance(debt, dict) or set(debt) != expected:
        raise HardeningFailure("Static debt policy is not exact")
    measured: dict[str, int] = {}
    identifiers: dict[str, set[str]] = {}
    for name, row in debt.items():
        expected_keys = {"maximum", "baseline", "allowed_identifiers"} if name.startswith("phpstan_") else {"maximum", "baseline"}
        if not isinstance(row, dict) or set(row) != expected_keys:
            raise HardeningFailure(f"Static debt entry is not exact: {name}")
        maximum = row.get("maximum")
        if not isinstance(maximum, int) or maximum < 0:
            raise HardeningFailure(f"Static debt maximum is invalid: {name}")
        baseline = root / require_text(row.get("baseline"), f"{name} baseline")
        if not baseline.is_file():
            raise HardeningFailure(f"Static debt baseline is missing: {name}")
        if name.startswith("phpstan_"):
            actual, found_identifiers = phpstan_baseline(baseline)
            allowed = row.get("allowed_identifiers")
            if (
                not isinstance(allowed, list)
                or not allowed
                or len(allowed) != len(set(allowed))
                or any(not isinstance(value, str) or value == "" for value in allowed)
            ):
                raise HardeningFailure(f"PHPStan allowed identifiers are invalid: {name}")
            unknown = found_identifiers - set(allowed)
            if unknown:
                raise HardeningFailure(f"Actionable PHPStan identifiers escaped into {name}: {sorted(unknown)}")
            identifiers[name] = found_identifiers
        else:
            actual = phpcs_count(baseline)
        if actual != maximum:
            raise HardeningFailure(
                f"{name} ledger drifted from the reviewed exact value {maximum} to {actual}; "
                "regenerate and review the policy explicitly"
            )
        measured[name] = actual

    static_policy = read_json(root / "config/quality/static-analysis-policy.json")
    expected_counts = {
        "phpstan_core": int(static_policy["phpstan"]["core"]["baseline_errors"]),
        "phpstan_adapters": int(static_policy["phpstan"]["adapters"]["baseline_errors"]),
        "phpcs": int(static_policy["phpcs"]["baseline_violations"]),
        "php_compatibility": int(static_policy["php_compatibility"]["baseline_violations"]),
    }
    if measured != expected_counts:
        raise HardeningFailure(
            f"Stage H and engineering static-debt authorities disagree: measured={measured}, "
            f"engineering={expected_counts}"
        )
    return measured, identifiers


def validate_maintainability(root: Path, value: Any) -> dict[str, int]:
    if not isinstance(value, dict):
        raise HardeningFailure("Maintainability authority must be an object")
    strict_keys(value, {"threshold_lines", "generated_exclusions", "budgets"}, "maintainability authority")
    threshold = value.get("threshold_lines")
    exclusions = value.get("generated_exclusions")
    budgets = value.get("budgets")
    if not isinstance(threshold, int) or threshold < 100:
        raise HardeningFailure("Maintainability discovery threshold is invalid")
    if (
        not isinstance(exclusions, list)
        or len(exclusions) != len(set(exclusions))
        or any(not isinstance(path, str) or path == "" for path in exclusions)
    ):
        raise HardeningFailure("Generated maintainability exclusions are invalid")
    excluded = set(exclusions)
    for relative in excluded:
        path = root / relative
        if not path.is_file() or count_lines(path) <= threshold:
            raise HardeningFailure(f"Generated maintainability exclusion is stale or missing: {relative}")
    if not isinstance(budgets, list) or not budgets:
        raise HardeningFailure("Maintainability budgets are missing")
    measured: dict[str, int] = {}
    dispositions: dict[str, str] = {}
    for index, row in enumerate(budgets):
        if not isinstance(row, dict):
            raise HardeningFailure(f"Maintainability budget {index} is not an object")
        strict_keys(row, {"path", "maximum_lines", "disposition"}, f"maintainability budget {index}")
        relative = require_text(row.get("path"), f"budget {index} path")
        maximum = row.get("maximum_lines")
        disposition = row.get("disposition")
        if relative in measured or relative in excluded:
            raise HardeningFailure(f"Duplicate or excluded maintainability budget: {relative}")
        if not isinstance(maximum, int) or maximum <= threshold:
            raise HardeningFailure(f"Maintainability budget must exceed discovery threshold: {relative}")
        if disposition not in {"accepted_debt", "planned_item9"}:
            raise HardeningFailure(f"Invalid maintainability disposition: {relative}")
        path = root / relative
        if not path.is_file():
            raise HardeningFailure(f"Maintainability target is missing: {relative}")
        actual = count_lines(path)
        if actual <= threshold:
            raise HardeningFailure(f"Maintainability budget is stale after reduction: {relative}")
        if actual > maximum:
            raise HardeningFailure(f"Maintainability budget grew: {relative} is {actual}, maximum {maximum}")
        measured[relative] = actual
        dispositions[relative] = disposition

    discovered = {
        path.relative_to(root).as_posix()
        for path in (root / "src").rglob("*.php")
        if path.relative_to(root).as_posix() not in excluded and count_lines(path) > threshold
    }
    if discovered != set(measured):
        raise HardeningFailure(
            "Oversized production component inventory drifted; "
            f"missing budgets={sorted(discovered - set(measured))}, stale budgets={sorted(set(measured) - discovered)}"
        )
    planned = [path for path, disposition in dispositions.items() if disposition == "planned_item9"]
    if planned != ["src/Application/Commerce/PendingCartIntentFactory.php"]:
        raise HardeningFailure(f"Item-9 maintainability target is not exact: {planned}")
    return measured


def validate_suppressions(root: Path, suppressions: Any) -> int:
    if not isinstance(suppressions, list):
        raise HardeningFailure("Inline suppression authority must be a list")
    allowed_total = 0
    seen: set[tuple[str, str]] = set()
    for index, row in enumerate(suppressions):
        if not isinstance(row, dict):
            raise HardeningFailure(f"Inline suppression {index} is not an object")
        strict_keys(row, {"path", "needle", "count"}, f"inline suppression {index}")
        relative = require_text(row.get("path"), f"suppression {index} path")
        needle = require_text(row.get("needle"), f"suppression {index} needle")
        expected = row.get("count")
        if (relative, needle) in seen:
            raise HardeningFailure(f"Duplicate inline suppression authority: {relative} / {needle}")
        seen.add((relative, needle))
        if not isinstance(expected, int) or expected < 1:
            raise HardeningFailure(f"Inline suppression count is invalid: {relative}")
        path = root / relative
        if not path.is_file():
            raise HardeningFailure(f"Inline suppression source is missing: {relative}")
        actual = path.read_text(encoding="utf-8").count(needle)
        if actual != expected:
            raise HardeningFailure(
                f"Inline suppression drifted: {relative} / {needle!r} expected {expected}, found {actual}"
            )
        allowed_total += expected

    discovered = 0
    for path in (root / "src").rglob("*.php"):
        for line_number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
            matches = list(SUPPRESSION_PATTERN.finditer(line))
            discovered += len(matches)
            for match in matches:
                suffix = line[match.end():]
                if "--" not in suffix and "(" not in suffix:
                    raise HardeningFailure(
                        f"Inline suppression lacks a local rationale: {path.relative_to(root)}:{line_number}"
                    )
    if discovered != allowed_total:
        raise HardeningFailure(
            f"Inline suppressions escaped the reviewed list: expected {allowed_total}, discovered {discovered}"
        )
    return discovered


def validate_policy(root: Path, policy: dict[str, Any]) -> list[str]:
    strict_keys(policy, POLICY_KEYS, "release-hardening policy")
    if policy.get("schema") != 1 or policy.get("gate") != "stage-h-release-hardening-v1":
        raise HardeningFailure("Release-hardening policy version is not exact")
    if policy.get("project_state") != "unpublished":
        raise HardeningFailure("The first-release hardening policy must remain explicitly unpublished")
    authority = policy.get("stage_g_authority")
    if not isinstance(authority, dict):
        raise HardeningFailure("Stage G authority is incomplete")
    validate_stage_g_authority(root, authority)

    modes = policy.get("modes")
    if not isinstance(modes, dict) or set(modes) != ALLOWED_MODES:
        raise HardeningFailure("Hardening modes are not exact")
    for name, row in modes.items():
        if not isinstance(row, dict):
            raise HardeningFailure(f"Hardening mode metadata is invalid: {name}")
        strict_keys(row, {"description"}, f"mode {name}")
        require_text(row.get("description"), f"mode {name} description")

    measured, identifiers = validate_static_debt(root, policy.get("static_debt"))
    maintainability = validate_maintainability(root, policy.get("maintainability"))
    suppression_count = validate_suppressions(root, policy.get("inline_suppressions"))

    locks = policy.get("dependency_locks")
    if not isinstance(locks, dict) or set(locks) != {"composer.lock", "package-lock.json"}:
        raise HardeningFailure("Dependency-lock authority is not exact")
    for relative, expected_hash in locks.items():
        if re.fullmatch(r"[a-f0-9]{64}", str(expected_hash)) is None:
            raise HardeningFailure(f"Dependency-lock checksum is malformed: {relative}")
        if sha256(root / relative) != expected_hash:
            raise HardeningFailure(f"Dependency lock changed without an explicit hardening-policy update: {relative}")

    external = policy.get("external_evidence")
    if not isinstance(external, dict) or set(external) != {
        "composer_advisory_audit",
        "clean_composer_install",
        "woocommerce_promotion",
    }:
        raise HardeningFailure("External evidence authority is not exact")
    for name, relative in external.items():
        relative = require_text(relative, f"external evidence path {name}")
        pure = Path(relative)
        if pure.is_absolute() or ".." in pure.parts:
            raise HardeningFailure(f"Unsafe external evidence path: {relative}")

    result = [f"{name}={value}" for name, value in sorted(measured.items())]
    result.extend(
        f"{name}_identifiers={','.join(sorted(values))}" for name, values in sorted(identifiers.items())
    )
    result.append(f"oversized_components={len(maintainability)}")
    result.append(f"inline_suppressions={suppression_count}")
    return result


def validate_ledger(root: Path, ledger: dict[str, Any]) -> list[dict[str, Any]]:
    strict_keys(ledger, {"schema", "findings"}, "release-hardening findings")
    if ledger.get("schema") != 1 or not isinstance(ledger.get("findings"), list):
        raise HardeningFailure("Release-hardening findings schema is invalid")
    findings: list[dict[str, Any]] = []
    ids: set[str] = set()
    today = dt.date.today()
    for index, raw in enumerate(ledger["findings"]):
        if not isinstance(raw, dict):
            raise HardeningFailure(f"Finding {index} is not an object")
        strict_keys(raw, FINDING_KEYS, f"finding {index}")
        finding_id = require_text(raw.get("id"), f"finding {index} id")
        if re.fullmatch(r"H-[0-9]{3}", finding_id) is None or finding_id in ids:
            raise HardeningFailure(f"Finding ID is malformed or duplicated: {finding_id}")
        ids.add(finding_id)
        category = require_text(raw.get("category"), f"{finding_id} category")
        if re.fullmatch(r"[a-z][a-z0-9_]*", category) is None:
            raise HardeningFailure(f"Finding category is malformed: {finding_id}")
        severity = raw.get("severity")
        status = raw.get("status")
        scopes = raw.get("scopes")
        if severity not in ALLOWED_SEVERITIES or status not in ALLOWED_STATUSES:
            raise HardeningFailure(f"Finding severity/status is invalid: {finding_id}")
        if not isinstance(scopes, list) or not scopes or len(scopes) != len(set(scopes)):
            raise HardeningFailure(f"Finding scopes are invalid: {finding_id}")
        if any(scope not in ALLOWED_SCOPES for scope in scopes):
            raise HardeningFailure(f"Finding scope is unknown: {finding_id}")
        for field in ("summary", "rationale", "guard", "resolution"):
            require_text(raw.get(field), f"{finding_id} {field}")
        evidence = raw.get("evidence")
        if not isinstance(evidence, list) or not evidence:
            raise HardeningFailure(f"Finding evidence is missing: {finding_id}")
        for relative in evidence:
            relative = require_text(relative, f"{finding_id} evidence")
            pure = Path(relative)
            if pure.is_absolute() or ".." in pure.parts or not (root / pure).exists():
                raise HardeningFailure(f"Finding evidence does not exist or is unsafe: {finding_id}: {relative}")
        review = parse_review_date(raw.get("review_by"), f"{finding_id} review_by")
        if status == "resolved" and review is not None:
            raise HardeningFailure(f"Resolved finding must not carry a review date: {finding_id}")
        if status != "resolved":
            if review is None:
                raise HardeningFailure(f"Unresolved finding lacks a review date: {finding_id}")
            if review < today:
                raise HardeningFailure(f"Finding review date expired: {finding_id} ({review.isoformat()})")
        if status == "external_evidence_required" and "publication" not in scopes:
            raise HardeningFailure(f"External evidence finding must be publication-scoped: {finding_id}")
        if status == "planned_item9" and "publication" not in scopes:
            raise HardeningFailure(f"Planned item-9 debt must block publication: {finding_id}")
        findings.append(raw)
    expected_ids = {
        "H-001", "H-002", "H-003", "H-004", "H-005", "H-006",
        "H-101", "H-102", "H-103", "H-104", "H-105", "H-106",
        "H-201", "H-202",
    }
    if ids != expected_ids:
        raise HardeningFailure(f"Hardening findings inventory is not exact: missing={sorted(expected_ids - ids)}, extra={sorted(ids - expected_ids)}")
    return findings


def hygiene_paths(root: Path) -> Iterable[Path]:
    for relative in PRODUCTION_HYGIENE_ROOTS:
        path = root / relative
        if path.is_file():
            yield path
        elif path.is_dir():
            for child in sorted(path.rglob("*")):
                if child.is_file() and not any(
                    part in {"quality", "__pycache__"} for part in child.relative_to(root).parts
                ):
                    yield child


def validate_hygiene(root: Path) -> list[str]:
    todo_pattern = re.compile(r"\b(?:TODO|FIXME|XXX|HACK|WIP)\b")
    scanned = 0
    for path in hygiene_paths(root):
        relative = path.relative_to(root).as_posix()
        if path.stat().st_mode & 0o002:
            raise HardeningFailure(f"World-writable release source: {relative}")
        payload = path.read_bytes()
        if b"\0" in payload:
            raise HardeningFailure(f"NUL byte in release source: {relative}")
        if path.suffix.lower() not in TEXT_SUFFIXES and path.name not in {
            "yassin-ai-assistant.php", "uninstall.php"
        }:
            continue
        try:
            text = payload.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise HardeningFailure(f"Non-UTF-8 release source: {relative}") from exc
        if relative.startswith(("src/", "assets/")) and todo_pattern.search(text):
            raise HardeningFailure(f"Unresolved development marker in production source: {relative}")
        for name, pattern in SECRET_PATTERNS.items():
            if pattern.search(text):
                raise HardeningFailure(f"Possible embedded {name} in production source: {relative}")
        scanned += 1
    return [f"production_hygiene_files={scanned}"]


def git_clean(root: Path) -> bool:
    if not (root / ".git").exists():
        return False
    try:
        result = subprocess.run(
            ["git", "status", "--porcelain", "--untracked-files=all"],
            cwd=root,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            check=False,
            timeout=10,
        )
    except (OSError, subprocess.TimeoutExpired):
        return False
    return result.returncode == 0 and result.stdout == ""


def valid_clean_composer_evidence(data: dict[str, Any], lock_hash: str) -> bool:
    return (
        set(data) == {"schema", "status", "method", "composer_lock_sha256", "package_count", "package_set_sha256"}
        and data.get("schema") == 1
        and data.get("status") == "passed"
        and data.get("method") == "clean"
        and data.get("composer_lock_sha256") == lock_hash
        and isinstance(data.get("package_count"), int)
        and data["package_count"] > 0
        and isinstance(data.get("package_set_sha256"), str)
        and re.fullmatch(r"[a-f0-9]{64}", data["package_set_sha256"]) is not None
    )


def valid_composer_audit_evidence(data: dict[str, Any], lock_hash: str) -> bool:
    return (
        set(data) == {"schema", "status", "composer_lock_sha256", "advisory_count", "abandoned_count"}
        and data.get("schema") == 1
        and data.get("status") == "passed"
        and data.get("composer_lock_sha256") == lock_hash
        and data.get("advisory_count") == 0
        and data.get("abandoned_count") == 0
    )


def valid_promotion_evidence(data: dict[str, Any]) -> bool:
    failures = data.get("failures")
    playwright = data.get("playwright")
    junit = data.get("junit")
    lifecycle = data.get("lifecycle")
    environment = data.get("environment_identity")
    compatibility = data.get("woocommerce_promotion_contract")
    version = data.get("woocommerce_package_version")
    checksum = data.get("woocommerce_package_sha256")
    if data.get("status") != "passed" or failures != []:
        return False
    if not isinstance(playwright, dict) or not isinstance(junit, dict):
        return False
    integers = ("total", "passed", "failed", "skipped", "flaky")
    if any(not isinstance(playwright.get(key), int) or playwright[key] < 0 for key in integers):
        return False
    total = playwright["total"]
    if total < 1 or playwright["passed"] != total or any(playwright[key] != 0 for key in ("failed", "skipped", "flaky")):
        return False
    if data.get("expected_playwright_scenarios") != total or data.get("browser_traces") != total:
        return False
    if any(not isinstance(junit.get(key), int) or junit[key] < 0 for key in ("total", "failed", "errors", "skipped")):
        return False
    if junit["total"] != total or any(junit[key] != 0 for key in ("failed", "errors", "skipped")):
        return False
    for key in (
        "installed_tree_matches_package",
        "clean_installed_tree_matches_package",
        "upgrade_installed_tree_matches_package",
        "installed_checksum_matches_package",
    ):
        if data.get(key) is not True:
            return False
    if not isinstance(lifecycle, dict) or not lifecycle or any(value is not True for value in lifecycle.values()):
        return False
    if data.get("woocommerce_critical_entries") != [] or data.get("wordpress_debug_violations") != []:
        return False
    if (
        not isinstance(version, str)
        or re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version) is None
        or not isinstance(checksum, str)
        or re.fullmatch(r"[a-f0-9]{64}", checksum) is None
    ):
        return False
    if not isinstance(compatibility, dict) or version not in compatibility.get("promotion_tested", []):
        return False
    if not isinstance(compatibility.get("runtime_contract"), str) or compatibility["runtime_contract"] == "":
        return False
    return (
        isinstance(environment, dict)
        and environment.get("host_package_matches") is True
        and isinstance(environment.get("playwright_version"), str)
        and environment["playwright_version"] != ""
        and isinstance(environment.get("browser_version"), str)
        and environment["browser_version"] != ""
    )


def external_evidence_satisfied(policy: dict[str, Any], evidence_dir: Path | None) -> dict[str, bool]:
    result = {name: False for name in policy["external_evidence"]}
    if evidence_dir is None:
        return result
    lock_hash = policy["dependency_locks"]["composer.lock"]
    for name, relative in policy["external_evidence"].items():
        path = evidence_dir / relative
        try:
            data = read_json(path)
        except HardeningFailure:
            continue
        if name == "woocommerce_promotion":
            result[name] = valid_promotion_evidence(data)
        elif name == "composer_advisory_audit":
            result[name] = valid_composer_audit_evidence(data, lock_hash)
        elif name == "clean_composer_install":
            result[name] = valid_clean_composer_evidence(data, lock_hash)
    return result


def mode_blockers(
    findings: list[dict[str, Any]], mode: str, external: dict[str, bool]
) -> tuple[list[str], list[str]]:
    blockers: list[str] = []
    external_blockers: list[str] = []
    for finding in findings:
        status = finding["status"]
        scopes = finding["scopes"]
        finding_id = finding["id"]
        if status == "resolved" or mode == "source":
            continue
        if mode == "item9":
            if "item9" in scopes and status != "planned_item9":
                blockers.append(finding_id)
            continue
        if "publication" not in scopes:
            continue
        if status == "external_evidence_required":
            if finding_id == "H-201" and not external.get("woocommerce_promotion", False):
                external_blockers.append(finding_id)
            elif finding_id == "H-202" and not (
                external.get("clean_composer_install", False)
                and external.get("composer_advisory_audit", False)
            ):
                external_blockers.append(finding_id)
        elif status == "planned_item9":
            blockers.append(finding_id)
        elif status == "accepted_debt" and finding["severity"] in {"critical", "high"}:
            blockers.append(finding_id)
    return blockers, external_blockers


def main() -> int:
    args = parse_args()
    root = args.root.resolve()
    policy_path = (args.policy or root / "config/quality/release-hardening-policy.json").resolve()
    ledger_path = (args.ledger or root / "config/quality/release-hardening-findings.json").resolve()
    try:
        policy = read_json(policy_path)
        ledger = read_json(ledger_path)
        measured = validate_policy(root, policy)
        findings = validate_ledger(root, ledger)
        measured.extend(validate_hygiene(root))
        if args.require_clean and not git_clean(root):
            raise HardeningFailure("Stage H requires a clean Git candidate tree")
        external = external_evidence_satisfied(
            policy,
            args.evidence_dir.resolve() if args.evidence_dir else None,
        )
        blockers, external_blockers = mode_blockers(findings, args.mode, external)
        if blockers:
            raise HardeningFailure(
                "Hardening blockers remain for mode " + args.mode + ": " + ", ".join(blockers)
            )
        status = "blocked_external" if external_blockers else "passed"
        result = {
            "schema": 1,
            "gate": policy["gate"],
            "mode": args.mode,
            "status": status,
            "code_blockers": blockers,
            "external_blockers": external_blockers,
            "finding_counts": {
                name: sum(1 for finding in findings if finding["status"] == name)
                for name in sorted(ALLOWED_STATUSES)
            },
            "measured": measured,
            "git_clean_required": bool(args.require_clean),
            "external_evidence": external,
        }
        if args.json_output:
            args.json_output.parent.mkdir(parents=True, exist_ok=True)
            args.json_output.write_text(
                json.dumps(result, indent=2, sort_keys=True) + "\n",
                encoding="utf-8",
            )
        if external_blockers:
            print(
                "Release hardening is code-clean but externally blocked: "
                + ", ".join(external_blockers)
            )
            return 69 if args.mode == "publication" else 0
        print(
            "Release hardening verified: "
            + ", ".join(measured)
            + f"; mode={args.mode}; findings={len(findings)}."
        )
        return 0
    except HardeningFailure as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
