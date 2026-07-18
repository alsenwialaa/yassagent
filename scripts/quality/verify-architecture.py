#!/usr/bin/env python3
"""Fail closed when source-layer or declaration ownership drifts."""

from __future__ import annotations

import re
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "src"
PREFIX = "YassinStore\\AiAssistant\\"

LAYER_RULES = {
    "Support": {"Support"},
    "Domain": {"Domain", "Support"},
    "Application": {"Application", "Domain", "Support"},
}

WORDPRESS_GLOBAL = re.compile(
    r"\b(?:wp_[A-Za-z0-9_]*|wc_[A-Za-z0-9_]*|WC|determine_locale|is_rtl|"
    r"get_bloginfo|get_option|update_option|delete_option|current_time|home_url|"
    r"admin_url|rest_url)\s*\("
)


def fail(messages: list[str]) -> None:
    if messages:
        raise SystemExit("\n".join(messages))


def layer_for(path: Path) -> str:
    return path.relative_to(SRC).parts[0]


def namespace_of(source: str, path: Path) -> str:
    match = re.search(r"^namespace\s+([^;]+);", source, re.MULTILINE)
    if not match:
        raise SystemExit(f"Production PHP file has no namespace: {path.relative_to(ROOT)}")
    return match.group(1).strip()


def declarations(source: str) -> list[str]:
    return re.findall(
        r"^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+"
        r"([A-Za-z_][A-Za-z0-9_]*)\b",
        source,
        re.MULTILINE,
    )


def imports(source: str) -> list[str]:
    values: list[str] = []
    for match in re.finditer(r"^use\s+([^;]+);", source, re.MULTILINE):
        value = match.group(1).strip()
        if value.startswith("function ") or value.startswith("const "):
            value = value.split(None, 1)[1]
        value = re.split(r"\s+as\s+", value, maxsplit=1, flags=re.IGNORECASE)[0]
        values.append(value.strip("\\"))
    return values


def main() -> int:
    errors: list[str] = []
    seen: dict[str, list[str]] = defaultdict(list)

    for path in sorted(SRC.rglob("*.php")):
        relative = path.relative_to(ROOT).as_posix()
        source = path.read_text(encoding="utf-8")
        layer = layer_for(path)
        namespace = namespace_of(source, path)
        declared = declarations(source)

        if len(declared) != 1:
            errors.append(f"{relative}: expected exactly one named declaration, found {len(declared)}")
        for name in declared:
            fqcn = namespace + "\\" + name
            seen[fqcn].append(relative)
            if path.stem != name:
                errors.append(f"{relative}: declaration {name} does not match its file name")

        for imported in imports(source):
            if not imported.startswith(PREFIX):
                continue
            imported_tail = imported[len(PREFIX):]
            target_layer = imported_tail.split("\\", 1)[0]
            allowed = LAYER_RULES.get(layer)
            if allowed is not None and target_layer not in allowed:
                errors.append(
                    f"{relative}: {layer} may not depend on {target_layer} ({imported})"
                )
            if layer == "Infrastructure" and target_layer in {"Presentation", "Lifecycle"}:
                if not relative.startswith("src/Infrastructure/Composition/"):
                    errors.append(
                        f"{relative}: only the composition root may depend on {target_layer}"
                    )

        if layer in {"Domain", "Application"} and WORDPRESS_GLOBAL.search(source):
            errors.append(f"{relative}: inner layer calls a WordPress/WooCommerce global")

    for fqcn, paths in sorted(seen.items()):
        if len(paths) > 1:
            errors.append(f"Duplicate declaration {fqcn}: {', '.join(paths)}")

    fail(errors)
    print(f"Architecture verified: {len(seen)} unique declarations with closed layer dependencies.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
