#!/usr/bin/env python3
"""Detect obvious dead declarations, unused imports, and duplicated source identities."""

from __future__ import annotations

import hashlib
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "src"
ENTRY_DECLARATIONS = {"Autoload", "Plugin", "Activator", "Deactivator"}


def main() -> int:
    sources = {path: path.read_text(encoding="utf-8") for path in sorted(SRC.rglob("*.php"))}
    joined = "\n".join(sources.values())
    joined += (ROOT / "yassin-ai-assistant.php").read_text(encoding="utf-8")
    joined += (ROOT / "uninstall.php").read_text(encoding="utf-8")
    identifiers = Counter(re.findall(r"\b[A-Za-z_][A-Za-z0-9_]*\b", joined))

    errors: list[str] = []
    body_hashes: dict[str, list[str]] = defaultdict(list)
    for path, source in sources.items():
        relative = path.relative_to(ROOT).as_posix()
        declarations = re.findall(
            r"\b(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)\b", source
        )
        for name in declarations:
            if name not in ENTRY_DECLARATIONS and identifiers[name] == 1:
                errors.append(f"{relative}: orphan declaration {name}")

        source_without_uses = re.sub(r"^use\s+[^;]+;\s*$", "", source, flags=re.MULTILINE)
        body_names = set(re.findall(r"\b[A-Za-z_][A-Za-z0-9_]*\b", source_without_uses))
        for match in re.finditer(
            r"^use\s+([^;]+?)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?;\s*$",
            source,
            re.MULTILINE,
        ):
            fqcn, alias = match.groups()
            if fqcn.startswith("function ") or fqcn.startswith("const "):
                continue
            name = alias or fqcn.rsplit("\\", 1)[-1]
            if name not in body_names:
                errors.append(f"{relative}: unused import {name}")

        calls = Counter(re.findall(r"\b([A-Za-z_][A-Za-z0-9_]*)\s*\(", source))
        for name in re.findall(
            r"\bprivate\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", source
        ):
            if name != "__construct" and calls[name] == 1:
                errors.append(f"{relative}: unused private method {name}")

        normalized = re.sub(r"\s+", " ", re.sub(r"/\*.*?\*/|//[^\n]*", "", source, flags=re.S)).strip()
        body_hashes[hashlib.sha256(normalized.encode("utf-8")).hexdigest()].append(relative)

    for digest, paths in sorted(body_hashes.items()):
        if len(paths) > 1:
            errors.append(f"Byte-equivalent production sources ({digest[:12]}): {', '.join(paths)}")

    duplicated_policy_methods = re.compile(
        r"\b(?:private|protected|public)\s+(?:static\s+)?function\s+"
        r"(?:sameQuantity|validQuantity|priceRange)\s*\("
    )
    quantity_literal = re.compile(r"(?<![0-9.])999(?:\.0)?(?![0-9])")
    for path, source in sources.items():
        relative = path.relative_to(ROOT).as_posix()
        if duplicated_policy_methods.search(source):
            errors.append(f"{relative}: duplicates a shared quantity or price-range policy")
        if relative != "src/Domain/Commerce/CartQuantity.php" and quantity_literal.search(source):
            errors.append(f"{relative}: hard-codes the authoritative maximum cart quantity")
        if relative != "src/Domain/Commerce/CartQuantity.php" and re.search(r"floor\([^\n]*quantity", source, re.IGNORECASE):
            errors.append(f"{relative}: duplicates the canonical integral cart-quantity policy")
        if (
            relative not in {
                "src/Domain/Commerce/CartQuantity.php",
                "src/Domain/Shopping/ProductPriceRange.php",
            }
            and "0.000001" in source
        ):
            errors.append(f"{relative}: duplicates a canonical quantity or price-range tolerance")

    if (
        "ProductPriceRange::fromSnapshot" not in joined
        or "ProductPriceRange::fromValues" not in joined
        or "CartQuantity::equals" not in joined
        or "CartQuantity::isPositiveInteger" not in joined
    ):
        errors.append("Shared price-range or cart-quantity policy is not used by production code")

    if errors:
        raise SystemExit("\n".join(errors))
    print(f"Code health verified across {len(sources)} production PHP files.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
