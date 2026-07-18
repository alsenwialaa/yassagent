#!/usr/bin/env python3
"""Validate promotion ZIP inputs and emit a closed byte-level manifest."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import stat
import zipfile
from pathlib import Path, PurePosixPath
from typing import Iterable

PLUGIN_SLUG = "yassin-ai-assistant"
PLUGIN_MAIN = f"{PLUGIN_SLUG}/yassin-ai-assistant.php"
COMPATIBILITY_MEMBER = f"{PLUGIN_SLUG}/config/woocommerce-compatibility.json"
FORBIDDEN_PLUGIN_PREFIXES = (
    f"{PLUGIN_SLUG}/.git/",
    f"{PLUGIN_SLUG}/node_modules/",
    f"{PLUGIN_SLUG}/tests/",
    f"{PLUGIN_SLUG}/integration/",
    f"{PLUGIN_SLUG}/scripts/",
)
MANIFEST_VERSION = 1
CONTRACT_KEYS = {
    "schema_version", "minimum", "maximum_exclusive", "tested_up_to",
    "promotion_tested", "wordpress_minimum", "runtime_contract",
}


def fail(message: str) -> None:
    raise SystemExit(message)


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def stable_version(value: str) -> tuple[int, int, int]:
    match = re.fullmatch(r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)", value)
    if match is None:
        fail(f"Stable semantic version is malformed: {value!r}")
    return tuple(int(part) for part in match.groups())


def wordpress_version(value: str) -> tuple[int, ...]:
    match = re.fullmatch(r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:\.(0|[1-9][0-9]*))?", value)
    if match is None:
        fail(f"WordPress version is malformed: {value!r}")
    return tuple(int(part) for part in match.groups() if part is not None)


def safe_members(archive: zipfile.ZipFile) -> Iterable[zipfile.ZipInfo]:
    seen: set[str] = set()
    for info in archive.infolist():
        name = info.filename
        if name in seen:
            fail(f"Duplicate ZIP member: {name}")
        seen.add(name)
        pure = PurePosixPath(name)
        if pure.is_absolute() or ".." in pure.parts or not pure.parts:
            fail(f"Unsafe ZIP member: {name}")
        mode = info.external_attr >> 16
        if stat.S_ISLNK(mode):
            fail(f"ZIP member is a symbolic link: {name}")
        if not info.is_dir() and mode not in (0, stat.S_IFREG | 0o644, stat.S_IFREG | 0o755):
            fail(f"ZIP member has an unsupported mode: {name}")
        yield info


def plugin_header_field(source: bytes, field: str) -> str:
    text = source.decode("utf-8", errors="strict")
    match = re.search(
        rf"^\s*\*?\s*{re.escape(field)}:\s*([^\r\n]+)$",
        text,
        flags=re.MULTILINE | re.IGNORECASE,
    )
    return match.group(1).strip() if match else ""


def plugin_version(source: bytes) -> str:
    value = plugin_header_field(source, "Version")
    if value:
        return value
    text = source.decode("utf-8", errors="strict")
    match = re.search(r"define\('YSAI_VERSION',\s*'([^']+)'\)", text)
    return match.group(1).strip() if match else ""


def compatibility_contract(payload: bytes) -> dict[str, object]:
    try:
        contract = json.loads(payload.decode("utf-8", errors="strict"))
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        fail(f"WooCommerce compatibility contract is invalid JSON: {error}")
    if not isinstance(contract, dict) or set(contract) != CONTRACT_KEYS:
        fail("WooCommerce compatibility contract is not a closed object.")
    if contract.get("schema_version") != 1:
        fail("Unsupported WooCommerce compatibility contract version.")
    for field in ("minimum", "maximum_exclusive", "tested_up_to"):
        if not isinstance(contract.get(field), str):
            fail(f"WooCommerce compatibility field is invalid: {field}")
        stable_version(str(contract[field]))
    if not isinstance(contract.get("wordpress_minimum"), str):
        fail("WooCommerce compatibility WordPress minimum is invalid.")
    wordpress_version(str(contract["wordpress_minimum"]))
    runtime_contract = contract.get("runtime_contract")
    if not isinstance(runtime_contract, str) or re.fullmatch(r"[a-z0-9][a-z0-9._-]{2,127}", runtime_contract) is None:
        fail("WooCommerce runtime-contract identifier is invalid.")
    tested = contract.get("promotion_tested")
    if not isinstance(tested, list) or not tested or not all(isinstance(value, str) for value in tested):
        fail("WooCommerce promotion-tested versions are invalid.")
    tuples = [stable_version(value) for value in tested]
    if len(set(tested)) != len(tested) or tuples != sorted(tuples):
        fail("WooCommerce promotion-tested versions must be unique and sorted.")
    minimum = stable_version(str(contract["minimum"]))
    maximum = stable_version(str(contract["maximum_exclusive"]))
    tested_up_to = stable_version(str(contract["tested_up_to"]))
    if minimum >= maximum or not minimum <= tested_up_to < maximum:
        fail("WooCommerce compatibility range or tested-up-to value is invalid.")
    if any(not minimum <= version < maximum for version in tuples):
        fail("WooCommerce promotion-tested version is outside the accepted range.")
    if tuples[-1] != tested_up_to:
        fail("WooCommerce tested-up-to value differs from promotion evidence.")
    return contract


def archive_file_manifest(
    archive: zipfile.ZipFile,
    members: list[zipfile.ZipInfo],
    root: str,
) -> dict[str, dict[str, object]]:
    files: dict[str, dict[str, object]] = {}
    prefix = root + "/"
    for info in members:
        if info.is_dir():
            continue
        if not info.filename.startswith(prefix):
            fail(f"Archive member escaped expected root {root}: {info.filename}")
        relative = info.filename[len(prefix):]
        if relative == "":
            fail(f"Archive contains an empty file path under {root}.")
        payload = archive.read(info)
        mode = info.external_attr >> 16
        files[relative] = {
            "sha256": sha256_bytes(payload),
            "bytes": len(payload),
            "executable": bool(mode & stat.S_IXUSR),
        }
    return dict(sorted(files.items()))


def validate_plugin(
    path: Path,
    expected_sha: str,
    expected_version: str,
    selected_woocommerce: str,
) -> dict[str, object]:
    if not path.is_file():
        fail(f"Plugin ZIP does not exist: {path}")
    actual_sha = sha256(path)
    if expected_sha and re.fullmatch(r"[a-f0-9]{64}", expected_sha) is None:
        fail("Expected plugin SHA-256 is malformed.")
    if expected_sha and actual_sha != expected_sha:
        fail(f"Plugin SHA-256 mismatch: expected {expected_sha}, got {actual_sha}")

    with zipfile.ZipFile(path) as archive:
        bad_crc = archive.testzip()
        if bad_crc is not None:
            fail(f"Plugin ZIP CRC verification failed at {bad_crc}.")
        infos = list(safe_members(archive))
        members = [info.filename for info in infos if not info.is_dir()]
        for required in (PLUGIN_MAIN, COMPATIBILITY_MEMBER):
            if required not in members:
                fail(f"Plugin ZIP is missing {required}.")
        roots = {PurePosixPath(name).parts[0] for name in members}
        if roots != {PLUGIN_SLUG}:
            fail(f"Plugin ZIP must have exactly one root directory named {PLUGIN_SLUG}.")
        for name in members:
            if any(name.startswith(prefix) for prefix in FORBIDDEN_PLUGIN_PREFIXES):
                fail(f"Development-only path leaked into installable ZIP: {name}")
            lower = name.lower()
            if "/.env" in lower or lower.endswith(".log") or lower.endswith(".sql"):
                fail(f"Sensitive or runtime artifact leaked into installable ZIP: {name}")

        main = archive.read(PLUGIN_MAIN)
        version = plugin_version(main)
        if version == "":
            fail("Plugin version could not be read from the installable ZIP.")
        if expected_version and version != expected_version:
            fail(f"Plugin version mismatch: expected {expected_version}, got {version}")
        contract = compatibility_contract(archive.read(COMPATIBILITY_MEMBER))
        if selected_woocommerce not in contract["promotion_tested"]:
            fail(
                f"WooCommerce {selected_woocommerce} is not in the plugin package's promotion-tested contract."
            )
        if plugin_header_field(main, "Requires at least") != contract["wordpress_minimum"]:
            fail("Plugin WordPress header differs from the compatibility contract.")
        if plugin_header_field(main, "WC requires at least") != contract["minimum"]:
            fail("Plugin WooCommerce minimum header differs from the compatibility contract.")
        if plugin_header_field(main, "WC tested up to") != contract["tested_up_to"]:
            fail("Plugin WooCommerce tested-up-to header differs from the compatibility contract.")
        file_manifest = archive_file_manifest(archive, infos, PLUGIN_SLUG)

    return {
        "kind": "plugin",
        "file": path.name,
        "sha256": actual_sha,
        "version": version,
        "files": len(file_manifest),
        "root": PLUGIN_SLUG,
        "woocommerce_compatibility": contract,
        "members": file_manifest,
    }


def validate_woocommerce(path: Path, expected_version: str) -> dict[str, object]:
    if not path.is_file():
        fail(f"WooCommerce ZIP does not exist: {path}")
    stable_version(expected_version)
    actual_sha = sha256(path)
    main = "woocommerce/woocommerce.php"
    with zipfile.ZipFile(path) as archive:
        bad_crc = archive.testzip()
        if bad_crc is not None:
            fail(f"WooCommerce ZIP CRC verification failed at {bad_crc}.")
        infos = list(safe_members(archive))
        members = [info.filename for info in infos if not info.is_dir()]
        if main not in members:
            fail(f"WooCommerce ZIP is missing {main}.")
        roots = {PurePosixPath(name).parts[0] for name in members}
        if roots != {"woocommerce"}:
            fail("WooCommerce ZIP must have exactly one root directory named woocommerce.")
        version = plugin_version(archive.read(main))
        if version != expected_version:
            fail(f"WooCommerce version mismatch: expected {expected_version}, got {version or 'unknown'}")
    return {
        "kind": "woocommerce",
        "file": path.name,
        "sha256": actual_sha,
        "version": version,
        "files": len(members),
        "root": "woocommerce",
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plugin", type=Path, required=True)
    parser.add_argument("--expected-sha256", default="")
    parser.add_argument("--expected-version", default="")
    parser.add_argument("--woocommerce", type=Path)
    parser.add_argument("--woocommerce-version", required=True)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    selected = args.woocommerce_version.strip()
    stable_version(selected)
    result: dict[str, object] = {
        "manifest_version": MANIFEST_VERSION,
        "plugin": validate_plugin(
            args.plugin.resolve(),
            args.expected_sha256.strip().lower(),
            args.expected_version.strip(),
            selected,
        ),
    }
    if args.woocommerce is not None:
        result["woocommerce"] = validate_woocommerce(args.woocommerce.resolve(), selected)
    else:
        result["woocommerce"] = {
            "kind": "woocommerce",
            "version": selected,
            "source": "not supplied; package identity not promotion-authoritative",
        }

    rendered = json.dumps(result, ensure_ascii=False, sort_keys=True, indent=2) + "\n"
    if args.output is not None:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(rendered, encoding="utf-8")
    print(rendered, end="")


if __name__ == "__main__":
    main()
