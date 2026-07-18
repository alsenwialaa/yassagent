#!/usr/bin/env python3
"""Generate deterministic browser and PHP projections of the canonical public JSON Schema."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parent.parent
SCHEMA_PATH = ROOT / "config" / "public-api-contract.json"
BROWSER_TARGET = ROOT / "assets" / "js" / "widget" / "05-public-contract.js"
PHP_TARGET = ROOT / "src" / "Application" / "Contract" / "GeneratedPublicApiContract.php"
DRAFT = "https://json-schema.org/draft/2020-12/schema"
ENDPOINTS = (
    "boot_request",
    "chat_request",
    "boot_response",
    "turn_response",
    "health_response",
    "conversation_export_response",
    "conversation_delete_response",
    "admin_test_response",
    "error_response",
)
BROWSER_OBJECT_DEFS = (
    "attachment",
    "boot_request",
    "chat_request",
    "image_metadata",
    "presentation",
    "product",
    "receipt_proof",
    "receipt",
    "message",
    "conversation",
    "widget",
    "cart",
    "cart_mutation_capability",
    "capabilities",
    "pending_turn",
    "session",
    "boot_response",
    "turn_response",
    "error_response",
)


class ContractError(RuntimeError):
    """Raised when the canonical schema cannot produce a safe projection."""


def load_schema() -> dict[str, Any]:
    try:
        raw = json.loads(SCHEMA_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ContractError(f"Unable to read canonical public contract: {exc}") from exc
    if not isinstance(raw, dict):
        raise ContractError("Canonical public contract must be a JSON object.")
    expected_root = {
        "$schema",
        "$id",
        "title",
        "description",
        "x-contract-version",
        "x-namespace",
        "x-runtime",
        "oneOf",
        "$defs",
    }
    if set(raw) != expected_root:
        raise ContractError("Canonical public contract root is not closed or complete.")
    if raw.get("$schema") != DRAFT or raw.get("x-contract-version") != 3:
        raise ContractError("Canonical public contract must be Draft 2020-12 version 3.")
    namespace = raw.get("x-namespace")
    if not isinstance(namespace, str) or namespace != "yassin-ai/v1":
        raise ContractError("Canonical public contract namespace is invalid.")
    runtime = raw.get("x-runtime")
    defs = raw.get("$defs")
    if not isinstance(runtime, dict) or not isinstance(defs, dict):
        raise ContractError("Canonical public contract runtime or definitions are invalid.")
    endpoint_refs = runtime.get("endpoint_schemas")
    if not isinstance(endpoint_refs, dict) or set(endpoint_refs) != set(ENDPOINTS):
        raise ContractError("Canonical endpoint schema map is incomplete.")
    for name in ENDPOINTS:
        if endpoint_refs.get(name) != f"#/$defs/{name}":
            raise ContractError(f"Canonical endpoint schema ref is invalid: {name}.")
    root_refs = [row.get("$ref") for row in raw.get("oneOf", []) if isinstance(row, dict)]
    if root_refs != [f"#/$defs/{name}" for name in ENDPOINTS]:
        raise ContractError("Canonical root endpoint union is invalid.")
    direct_objects = [name for name, value in defs.items()
                      if isinstance(value, dict) and value.get("type") == "object"]
    for name in direct_objects:
        definition = defs[name]
        if definition.get("additionalProperties") is not False:
            raise ContractError(f"Object definition is not closed: {name}.")
        properties = definition.get("properties")
        required = definition.get("required")
        if not isinstance(properties, dict) or not isinstance(required, list):
            raise ContractError(f"Object fields are invalid: {name}.")
        if not all(isinstance(field, str) for field in required) or not set(required).issubset(properties):
            raise ContractError(f"Required fields are invalid: {name}.")
    for name in BROWSER_OBJECT_DEFS:
        if name not in direct_objects:
            raise ContractError(f"Browser object definition is invalid: {name}.")
    return raw


def integer(value: Any, name: str, minimum: int = 0) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < minimum:
        raise ContractError(f"Canonical integer is invalid: {name}.")
    return value


def string(value: Any, name: str) -> str:
    if not isinstance(value, str) or value == "":
        raise ContractError(f"Canonical string is invalid: {name}.")
    return value


def definition(schema: dict[str, Any], name: str) -> dict[str, Any]:
    value = schema["$defs"].get(name)
    if not isinstance(value, dict):
        raise ContractError(f"Missing canonical definition: {name}.")
    return value


def prop(schema: dict[str, Any], definition_name: str, property_name: str) -> dict[str, Any]:
    properties = definition(schema, definition_name).get("properties")
    value = properties.get(property_name) if isinstance(properties, dict) else None
    if not isinstance(value, dict):
        raise ContractError(f"Missing canonical property: {definition_name}.{property_name}.")
    return value


def fields(schema: dict[str, Any], name: str) -> list[str]:
    properties = definition(schema, name).get("properties")
    if not isinstance(properties, dict):
        raise ContractError(f"Canonical fields are invalid: {name}.")
    return list(properties.keys())


def required(schema: dict[str, Any], name: str) -> list[str]:
    value = definition(schema, name).get("required")
    if not isinstance(value, list) or not all(isinstance(item, str) for item in value):
        raise ContractError(f"Canonical required fields are invalid: {name}.")
    return list(value)


def maximum(schema: dict[str, Any], definition_name: str, property_name: str) -> int:
    return integer(prop(schema, definition_name, property_name).get("maximum"), f"{definition_name}.{property_name}.maximum")


def max_length(schema: dict[str, Any], definition_name: str, property_name: str) -> int:
    return integer(prop(schema, definition_name, property_name).get("maxLength"), f"{definition_name}.{property_name}.maxLength")


def enum(schema: dict[str, Any], definition_name: str, property_name: str) -> list[str]:
    value = prop(schema, definition_name, property_name).get("enum")
    if not isinstance(value, list) or not value or not all(isinstance(item, str) for item in value):
        raise ContractError(f"Canonical enum is invalid: {definition_name}.{property_name}.")
    return list(value)


def array_max(schema: dict[str, Any], definition_name: str, property_name: str) -> int:
    return integer(prop(schema, definition_name, property_name).get("maxItems"), f"{definition_name}.{property_name}.maxItems")


def reply_context_projection(schema: dict[str, Any]) -> dict[str, Any]:
    variants = definition(schema, "reply_context").get("oneOf")
    if not isinstance(variants, list) or len(variants) != 2:
        raise ContractError("Canonical reply-context variants are invalid.")
    projected: list[dict[str, Any]] = []
    for variant in variants:
        if not isinstance(variant, dict) or variant.get("type") != "object" or variant.get("additionalProperties") is not False:
            raise ContractError("Canonical reply-context object is not closed.")
        properties = variant.get("properties")
        required_fields = variant.get("required")
        if not isinstance(properties, dict) or not isinstance(required_fields, list):
            raise ContractError("Canonical reply-context fields are invalid.")
        projected.append({"fields": list(properties), "required": list(required_fields)})
    text_schema = variants[0]["properties"].get("text")
    product_schema = variants[1]["properties"].get("product_index")
    if not isinstance(text_schema, dict) or not isinstance(product_schema, dict):
        raise ContractError("Canonical reply-context limits are invalid.")
    return {
        "variants": projected,
        "textMaxChars": integer(text_schema.get("maxLength"), "reply_context.text.maxLength"),
        "productIndexMax": integer(product_schema.get("maximum"), "reply_context.product_index.maximum"),
    }


def cart_command_projection(schema: dict[str, Any]) -> dict[str, Any]:
    variants = definition(schema, "cart_command").get("oneOf")
    if not isinstance(variants, list) or not variants:
        raise ContractError("Canonical cart-command variants are invalid.")
    command_fields: dict[str, list[str]] = {}
    quantity_types: list[str] = []
    item_max = 0
    quantity_max = 0
    for variant in variants:
        if not isinstance(variant, dict) or variant.get("type") != "object" or variant.get("additionalProperties") is not False:
            raise ContractError("Canonical cart-command variant is invalid.")
        properties = variant.get("properties")
        required_fields = variant.get("required")
        if not isinstance(properties, dict) or not isinstance(required_fields, list):
            raise ContractError("Canonical cart-command fields are invalid.")
        type_schema = properties.get("type")
        command_type = type_schema.get("const") if isinstance(type_schema, dict) else None
        if not isinstance(command_type, str) or not command_type:
            raise ContractError("Canonical cart-command type is invalid.")
        command_fields[command_type] = list(properties)
        if "item" in properties:
            item_max = max(item_max, integer(properties["item"].get("maxLength"), f"cart_command.{command_type}.item.maxLength"))
        if "quantity" in properties:
            quantity_types.append(command_type)
            quantity_max = max(quantity_max, integer(properties["quantity"].get("maximum"), f"cart_command.{command_type}.quantity.maximum"))
    return {
        "types": list(command_fields),
        "quantityTypes": quantity_types,
        "fieldsByType": command_fields,
        "itemMaxChars": item_max,
        "quantityMax": quantity_max,
    }


def build_projection(schema: dict[str, Any]) -> dict[str, Any]:
    runtime = schema["x-runtime"]
    image_policy = runtime.get("image_policy")
    if not isinstance(image_policy, dict):
        raise ContractError("Canonical image policy is invalid.")
    message_outcomes = enum(schema, "message", "outcome")
    attachment_data = prop(schema, "attachment", "data")
    uuid = definition(schema, "uuid_v4")
    code = definition(schema, "code")
    token = definition(schema, "conversation_token")
    session_token = prop(schema, "session", "token")

    object_fields = {name: fields(schema, name) for name in BROWSER_OBJECT_DEFS}
    object_required = {name: required(schema, name) for name in BROWSER_OBJECT_DEFS}

    projection: dict[str, Any] = {
        "contractVersion": integer(schema.get("x-contract-version"), "x-contract-version", 1),
        "namespace": string(schema.get("x-namespace"), "x-namespace"),
        "fields": object_fields,
        "required": object_required,
        "runtime": {
            "maxBodyBytes": integer(runtime.get("max_body_bytes"), "x-runtime.max_body_bytes", 1024),
            "transcriptMaxRows": integer(runtime.get("transcript_max_rows"), "x-runtime.transcript_max_rows", 2),
            "messageOptionalFailureFields": runtime.get("message_optional_failure_fields"),
            "imagePolicy": image_policy,
        },
        "patterns": {
            "uuidV4": string(uuid.get("pattern"), "uuid_v4.pattern"),
            "code": string(code.get("pattern"), "code.pattern"),
            "conversationToken": string(token.get("pattern"), "conversation_token.pattern"),
            "sessionToken": string(session_token.get("pattern"), "session.token.pattern"),
        },
        "limits": {
            "uuidLength": integer(uuid.get("maxLength"), "uuid_v4.maxLength"),
            "codeMaxChars": integer(code.get("maxLength"), "code.maxLength"),
            "conversationTokenMinChars": integer(token.get("minLength"), "conversation_token.minLength"),
            "conversationTokenMaxChars": integer(token.get("maxLength"), "conversation_token.maxLength"),
            "sessionTokenMaxChars": integer(session_token.get("maxLength"), "session.token.maxLength"),
            "messageMaxChars": max_length(schema, "chat_request", "message"),
            "attachmentMaxItems": array_max(schema, "chat_request", "attachments"),
            "attachmentDataMinChars": integer(attachment_data.get("minLength"), "attachment.data.minLength"),
            "attachmentDataMaxChars": integer(attachment_data.get("maxLength"), "attachment.data.maxLength"),
            "imageMetadataMinBytes": integer(prop(schema, "image_metadata", "byte_length").get("minimum"), "image_metadata.byte_length.minimum"),
            "imageMetadataMaxBytes": maximum(schema, "image_metadata", "byte_length"),
            "imageMimeMaxChars": max_length(schema, "image_metadata", "mime_type"),
            "imageKindMaxChars": max_length(schema, "image_metadata", "kind"),
            "presentationMaxImages": array_max(schema, "presentation", "images"),
            "replyQuoteMaxChars": max_length(schema, "presentation", "reply_quote"),
            "productMaxItems": array_max(schema, "message", "products"),
            "receiptMaxItems": array_max(schema, "message", "receipts"),
            "messageTextMaxChars": max_length(schema, "message", "text"),
            "productIdMax": maximum(schema, "product", "id"),
            "productNameMaxChars": max_length(schema, "product", "name"),
            "formattedPriceMaxChars": max_length(schema, "product", "formatted_price"),
            "shortDescriptionMaxChars": max_length(schema, "product", "short_description"),
            "publicUrlMaxChars": max_length(schema, "product", "permalink"),
            "receiptMessageMaxChars": max_length(schema, "receipt", "message"),
            "receiptCommandMaxItems": array_max(schema, "receipt_proof", "commands"),
            "moneyTextMaxChars": max_length(schema, "receipt_proof", "cart_total"),
            "currencyChars": max_length(schema, "receipt_proof", "currency"),
            "noticeMaxChars": max_length(schema, "cart_mutation_capability", "notice"),
            "messageRoleMaxChars": max_length(schema, "message", "role"),
            "messageOutcomeMaxChars": max_length(schema, "message", "outcome"),
            "pendingStatusMaxChars": max_length(schema, "pending_turn", "status"),
            "widgetTitleMaxChars": max_length(schema, "widget", "title"),
            "widgetSubtitleMaxChars": max_length(schema, "widget", "subtitle"),
            "widgetButtonMaxChars": max_length(schema, "widget", "button_text"),
            "widgetEmptyStateHintMaxChars": max_length(schema, "widget", "empty_state_hint"),
            "serverTimeMin": integer(prop(schema, "boot_response", "server_time").get("minimum"), "boot_response.server_time.minimum"),
            "serverTimeMax": maximum(schema, "boot_response", "server_time"),
            "createdAtMax": maximum(schema, "message", "created_at"),
            "cartCountMax": maximum(schema, "receipt_proof", "cart_count"),
            "retryAfterMax": maximum(schema, "error_response", "retry_after"),
            "errorMessageMaxChars": max_length(schema, "error_response", "message"),
        },
        "enums": {
            "roles": enum(schema, "message", "role"),
            "messageOutcomes": message_outcomes,
            "assistantOutcomes": [item for item in message_outcomes if item],
            "imageMimeTypes": enum(schema, "attachment", "mime_type"),
            "imageScopes": enum(schema, "presentation", "image_scope"),
            "pendingTurnStatuses": enum(schema, "pending_turn", "status"),
            "cartMutationCodes": enum(schema, "cart_mutation_capability", "code"),
        },
        "constants": {
            "imageKind": string(prop(schema, "image_metadata", "kind").get("const"), "image_metadata.kind.const"),
            "receiptAction": string(prop(schema, "receipt", "action").get("const"), "receipt.action.const"),
            "mutationAvailableCode": "available",
        },
        "replyContext": reply_context_projection(schema),
        "cartCommand": cart_command_projection(schema),
    }
    optional_failure_fields = projection["runtime"]["messageOptionalFailureFields"]
    if not isinstance(optional_failure_fields, list) or not optional_failure_fields \
            or not all(isinstance(item, str) for item in optional_failure_fields):
        raise ContractError("Canonical optional message failure fields are invalid.")
    if not set(optional_failure_fields).issubset(object_fields["message"]):
        raise ContractError("Canonical optional message failure fields are not message properties.")
    if projection["limits"]["attachmentMaxItems"] != image_policy.get("max_items", projection["limits"]["attachmentMaxItems"]):
        raise ContractError("Canonical attachment count diverges from image policy.")
    if projection["limits"]["imageMetadataMaxBytes"] != image_policy.get("max_decoded_bytes"):
        raise ContractError("Canonical image metadata limit diverges from image policy.")
    return projection


def php_literal(value: Any, indent: int = 0) -> str:
    pad = "    " * indent
    child = "    " * (indent + 1)
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, str):
        escaped = value.replace("\\", "\\\\").replace("'", "\\'")
        return "'" + escaped + "'"
    if isinstance(value, list):
        if not value:
            return "array()"
        rows = [child + php_literal(item, indent + 1) + "," for item in value]
        return "array(\n" + "\n".join(rows) + "\n" + pad + ")"
    if isinstance(value, dict):
        if not value:
            return "array()"
        rows = [
            child + php_literal(str(key), indent + 1) + " => " + php_literal(item, indent + 1) + ","
            for key, item in value.items()
        ]
        return "array(\n" + "\n".join(rows) + "\n" + pad + ")"
    raise ContractError(f"Unsupported PHP projection value: {type(value).__name__}.")


def render_php(schema: dict[str, Any], projection: dict[str, Any]) -> bytes:
    definitions = list(schema["$defs"].keys())
    object_names = [name for name, value in schema["$defs"].items()
                    if isinstance(value, dict) and value.get("type") == "object"]
    object_fields = {name: fields(schema, name) for name in object_names}
    required_fields = {name: required(schema, name) for name in object_names}
    endpoints = list(schema["x-runtime"]["endpoint_schemas"].keys())
    responses = [name for name in endpoints if name.endswith("_response")]
    payload = f"""<?php

declare(strict_types=1);

namespace YassinStore\\AiAssistant\\Application\\Contract;

/**
 * Generated by scripts/generate-public-contract.py from the canonical schema.
 * Do not edit this file directly.
 */
final class GeneratedPublicApiContract
{{
    public const CONTRACT_VERSION = {projection['contractVersion']};
    public const SCHEMA_ID = {php_literal(schema['$id'])};
    public const NAMESPACE = {php_literal(projection['namespace'])};
    public const ENDPOINT_DEFINITIONS = {php_literal(endpoints, 1)};
    public const RESPONSE_DEFINITIONS = {php_literal(responses, 1)};
    public const DEFINITIONS = {php_literal(definitions, 1)};
    public const OBJECT_FIELDS = {php_literal(object_fields, 1)};
    public const REQUIRED_FIELDS = {php_literal(required_fields, 1)};

    private function __construct()
    {{
    }}
}}
"""
    return payload.encode("utf-8")


def render_browser(projection: dict[str, Any]) -> bytes:
    payload = json.dumps(projection, ensure_ascii=False, sort_keys=True, indent=4)
    content = f"""/* Generated by scripts/generate-public-contract.py from config/public-api-contract.json.
 * Do not edit this file directly.
 */
(function (window) {{
    'use strict';

    var Runtime = window.YSAIWidgetRuntime = window.YSAIWidgetRuntime || {{}};

    function deepFreeze(value) {{
        if (!value || typeof value !== 'object' || Object.isFrozen(value)) {{
            return value;
        }}
        Object.keys(value).forEach(function (key) {{
            deepFreeze(value[key]);
        }});
        return Object.freeze(value);
    }}

    Runtime.publicContract = deepFreeze({payload});
}}(window));
"""
    return content.encode("utf-8")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="Fail unless the generated browser and PHP projections are current.")
    args = parser.parse_args()
    try:
        schema = load_schema()
        projection = build_projection(schema)
        browser_output = render_browser(projection)
        php_output = render_php(schema, projection)
    except ContractError as exc:
        raise SystemExit(str(exc)) from exc
    if args.check:
        browser_current = BROWSER_TARGET.read_bytes() if BROWSER_TARGET.is_file() else b""
        php_current = PHP_TARGET.read_bytes() if PHP_TARGET.is_file() else b""
        stale = []
        if browser_current != browser_output:
            stale.append(str(BROWSER_TARGET.relative_to(ROOT)))
        if php_current != php_output:
            stale.append(str(PHP_TARGET.relative_to(ROOT)))
        if stale:
            raise SystemExit("Generated public-contract projections are stale: " + ", ".join(stale) + ".")
        print("Browser and PHP public-contract projections are current.")
        return
    BROWSER_TARGET.write_bytes(browser_output)
    PHP_TARGET.write_bytes(php_output)
    print(f"Wrote {BROWSER_TARGET.relative_to(ROOT)} ({len(browser_output)} bytes).")
    print(f"Wrote {PHP_TARGET.relative_to(ROOT)} ({len(php_output)} bytes).")


if __name__ == "__main__":
    main()
