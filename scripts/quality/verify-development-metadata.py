#!/usr/bin/env python3
"""Verify deterministic, public, development-only quality tooling metadata."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FORBIDDEN = (
    "packages.applied-caas",
    "internal.api",
    "localhost",
    "127.0.0.1",
    "file:",
)


def load(path: str) -> dict:
    value = json.loads((ROOT / path).read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise SystemExit(f"{path} must contain one JSON object.")
    return value


def exact_version(value: str) -> bool:
    return re.fullmatch(r"(?:v)?[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?", value) is not None


def main() -> int:
    metadata = ("composer.json", "composer.lock", "package.json", "package-lock.json")
    for filename in metadata:
        source = (ROOT / filename).read_text(encoding="utf-8")
        for token in FORBIDDEN:
            if token in source:
                raise SystemExit(f"Private or host-specific dependency reference in {filename}: {token}")

    composer = load("composer.json")
    if set(composer.get("require", {})) != {"php"}:
        raise SystemExit("Production Composer dependencies must remain empty apart from the PHP platform constraint.")
    dev = composer.get("require-dev")
    if not isinstance(dev, dict) or not dev:
        raise SystemExit("Composer development tools are missing.")
    for package, version in dev.items():
        if not isinstance(version, str) or not exact_version(version):
            raise SystemExit(f"Composer development dependency is not pinned exactly: {package} {version}")

    package = load("package.json")
    node_dev = package.get("devDependencies")
    if not isinstance(node_dev, dict) or not node_dev:
        raise SystemExit("Node development tools are missing.")
    for dependency, version in node_dev.items():
        if not isinstance(version, str) or not exact_version(version):
            raise SystemExit(f"Node development dependency is not pinned exactly: {dependency} {version}")

    compatibility = load("config/woocommerce-compatibility.json")
    promoted = compatibility.get("promotion_tested")
    if promoted != [dev.get("php-stubs/woocommerce-stubs")]:
        raise SystemExit("WooCommerce static stubs do not match the exact mutation-promotion version.")
    wordpress_stub = str(dev.get("php-stubs/wordpress-stubs", ""))
    wordpress_minimum = str(compatibility.get("wordpress_minimum", ""))
    if not wordpress_stub.startswith(wordpress_minimum + "."):
        raise SystemExit("WordPress stubs do not match the declared WordPress compatibility line.")

    required = (
        ".github/workflows/runtime-tests.yml",
        "phpunit.xml.dist",
        "phpstan.neon.dist",
        "phpstan-adapters.neon.dist",
        "phpcs.xml.dist",
        "phpcompatibility.xml.dist",
        "eslint.config.js",
        "config/quality/release-hardening-policy.json",
        "config/quality/release-hardening-findings.json",
        "scripts/run-stage-h-gate.py",
        "scripts/quality/verify-release-hardening.py",
        "scripts/quality/self-test-release-hardening.py",
        "scripts/quality/verify-composer-install.py",
        "scripts/quality/verify-node-install.py",
        "scripts/quality/self-test-node-install.py",
        "scripts/quality/self-test-stage-h-runner.py",
        "scripts/quality/verify-release-archives.py",
        "scripts/quality/self-test-release-archives.py",
        "scripts/quality/smoke-production-archive.py",
        "scripts/quality/self-test-production-archive.py",
        "scripts/quality/verify-composer-audit.py",
        "scripts/quality/self-test-composer-audit.py",
        "HARDENING.md",
    )
    missing = [name for name in required if not (ROOT / name).is_file()]
    if missing:
        raise SystemExit("Development quality configuration is incomplete: " + ", ".join(missing))

    limits = {
        "tests/run.php": 2048,
        "tests/js/widget.test.js": 2048,
    }
    for filename, maximum in limits.items():
        size = (ROOT / filename).stat().st_size
        if size > maximum:
            raise SystemExit(f"Monolithic test entry point returned: {filename} is {size} bytes.")
    for path in (ROOT / "tests/browser").glob("*.spec.js"):
        if path.stat().st_size > 50000:
            raise SystemExit(f"Browser specification remains monolithic: {path.name}")

    composer_scripts = composer.get("scripts", {})
    architecture = composer_scripts.get("quality:architecture", []) if isinstance(composer_scripts, dict) else []
    required_architecture = {
        "python3 scripts/quality/verify-composer-install.py",
        "python3 scripts/quality/verify-node-install.py",
        "python3 scripts/quality/self-test-node-install.py",
        "python3 scripts/quality/self-test-stage-h-runner.py",
        "python3 scripts/quality/verify-release-hardening.py --mode source",
        "python3 scripts/quality/self-test-release-hardening.py",
        "python3 scripts/quality/self-test-release-archives.py",
        "python3 scripts/quality/self-test-production-archive.py",
        "python3 scripts/quality/self-test-composer-audit.py",
    }
    if not isinstance(architecture, list) or not required_architecture.issubset(set(architecture)):
        raise SystemExit("Composer quality architecture does not enforce the Stage H authorities.")

    node_scripts = package.get("scripts", {})
    if not isinstance(node_scripts, dict) or not {"test:hardening", "test:stage-h"}.issubset(node_scripts):
        raise SystemExit("Node development metadata omits the Stage H entry points.")

    hardening = load("config/quality/release-hardening-policy.json")
    if hardening.get("project_state") != "unpublished" or hardening.get("gate") != "stage-h-release-hardening-v1":
        raise SystemExit("The unpublished Stage H release authority is missing or malformed.")

    package_script = (ROOT / "scripts/package.py").read_text(encoding="utf-8")
    if '"vendor"' not in package_script or '"composer.json"' not in package_script or '("config", "quality")' not in package_script:
        raise SystemExit("Source packaging does not explicitly include development metadata and exclude it from production.")

    print("Development metadata verified: exact public locks, dev-only tools, aligned stubs, Stage H authority.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
