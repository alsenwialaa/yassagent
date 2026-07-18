#!/usr/bin/env python3
"""Create deterministic installable and source release archives."""
from __future__ import annotations

import argparse
import os
import re
import stat
import time
import zipfile
from pathlib import Path
from typing import Iterable

ROOT_NAME = "yassin-ai-assistant"
ALWAYS_EXCLUDE = {
    ".git", "__pycache__", ".DS_Store", "node_modules", "vendor", "test-results",
    "playwright-report", "artifacts", "runtime", "release", ".cache", ".phpstan.cache",
    "coverage", ".phpunit.result.cache",
}
PRODUCTION_ROOTS = {
    "ARCHITECTURE.md", "CHANGELOG.md", "LICENSE", "PRIVACY.md", "README.md",
    "REST-CONTRACT.md", "SECURITY.md", "assets", "config", "readme.txt", "src",
    "uninstall.php", "yassin-ai-assistant.php",
}
SOURCE_ROOTS = PRODUCTION_ROOTS | {
    ".dockerignore", ".github", ".gitignore", "DEVELOPMENT.md", "HARDENING.md", "integration", "package-lock.json",
    "package.json", "composer.json", "composer.lock", "phpunit.xml.dist", "phpstan.neon.dist",
    "phpstan-adapters.neon.dist", "phpcs.xml.dist", "phpcompatibility.xml.dist",
    "eslint.config.js", "scripts", "tests",
}
PRODUCTION_EXCLUDE_PREFIXES = {
    ("assets", "js", "widget"),
    ("config", "quality"),
}


def is_sensitive_environment_path(relative: Path) -> bool:
    """Credentials must never enter either release archive."""
    return any(
        part == ".env" or (part.startswith(".env.") and part != ".env.example")
        for part in relative.parts
    )


def version(root: Path) -> str:
    text = (root / "yassin-ai-assistant.php").read_text(encoding="utf-8")
    match = re.search(r"define\('YSAI_VERSION',\s*'([^']+)'\)", text)
    if not match:
        raise RuntimeError("YSAI_VERSION was not found")
    return match.group(1)


def timestamp() -> tuple[int, int, int, int, int, int]:
    epoch = int(os.environ.get("SOURCE_DATE_EPOCH", "946684800"))
    value = time.gmtime(max(epoch, 315532800))
    return value.tm_year, value.tm_mon, value.tm_mday, value.tm_hour, value.tm_min, value.tm_sec - value.tm_sec % 2


def files(root: Path, source: bool) -> Iterable[Path]:
    for path in sorted(root.rglob("*"), key=lambda p: p.as_posix()):
        relative = path.relative_to(root)
        if any(part in ALWAYS_EXCLUDE for part in relative.parts):
            continue
        if path.is_symlink():
            raise RuntimeError(f"Release source contains a symbolic link: {relative}")
        if not path.is_file():
            continue
        if is_sensitive_environment_path(relative):
            continue
        if path.suffix in {".zip", ".pyc"} or path.name.endswith("~"):
            continue
        top_level = relative.parts[0]
        if top_level not in SOURCE_ROOTS:
            raise RuntimeError(f"Unexpected top-level release member: {top_level}")
        if not source and top_level not in PRODUCTION_ROOTS:
            continue
        if not source and any(relative.parts[:len(prefix)] == prefix for prefix in PRODUCTION_EXCLUDE_PREFIXES):
            continue
        yield path


def write_archive(root: Path, target: Path, source: bool) -> None:
    dt = timestamp()
    target.parent.mkdir(parents=True, exist_ok=True)
    target.unlink(missing_ok=True)
    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in files(root, source):
            relative = path.relative_to(root).as_posix()
            info = zipfile.ZipInfo(f"{ROOT_NAME}/{relative}", date_time=dt)
            info.create_system = 3
            mode = path.stat().st_mode
            executable = bool(mode & stat.S_IXUSR)
            info.external_attr = ((0o100755 if executable else 0o100644) << 16)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.flag_bits |= 0x800
            archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parent.parent)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    root = args.root.resolve()
    release = version(root)
    write_archive(root, args.output / f"yassin-ai-assistant-v{release}.zip", source=False)
    write_archive(root, args.output / f"yassin-ai-assistant-v{release}-source.zip", source=True)
    print(release)


if __name__ == "__main__":
    main()
