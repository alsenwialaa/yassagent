# REST contract

Canonical machine contract: `config/public-api-contract.json` (version 3, JSON Schema Draft 2020-12)

Namespace: `yassin-ai/v1`

All request and plugin-owned response objects are closed. Unknown fields, list-shaped objects, malformed JSON, invalid UTF-8, excessive body size, invalid credentials, and values outside documented bounds are rejected. Boot, turn, health, privacy export/delete, administrator readiness, and error payloads are constructed through endpoint-specific projectors and validated against the canonical nested schema before `WP_REST_Response` can be created. There is no generic associative-array success emitter.

## Authentication

Storefront routes use `X-YSAI-Session`, a signed short-lived assistant-session token. Conversation routes additionally require a conversation UUID and opaque conversation token bound server-side to that assistant session.

`client_instance_id` is admission identity only. `browser_continuity_secret` is a site-scoped 256-bit bearer stored by the browser; the server stores a site-bound HMAC index. Rotation requires `previous_browser_continuity_secret`. Resume fields must be supplied together. WooCommerce session/cart authority is never assistant-session authority.

## `POST /boot`

Allowed request fields:

```json
{
  "client_instance_id": "uuid",
  "browser_continuity_secret": "base64url bearer",
  "previous_browser_continuity_secret": "optional rotation proof",
  "conversation_id": "optional uuid",
  "conversation_token": "optional opaque token",
  "pending_turn_id": "optional uuid"
}
```

Canonical response fields:

```json
{
  "ok": true,
  "session": { "token": "signed token" },
  "conversation": { "id": "uuid", "token": "opaque token", "messages": [] },
  "widget": {
    "title": "مساعد التسوق",
    "subtitle": "",
    "button_text": "ابدأ المحادثة",
    "empty_state_hint": "اكتب ما تبحث عنه أو اطلب مساعدة في اختيار منتج."
  },
  "cart": {
    "item_count": 0,
    "formatted_total": "SAR 0.00",
    "cart_url": "https://store.example/cart",
    "checkout_url": "https://store.example/checkout"
  },
  "cart_available": true,
  "cart_notice": "",
  "capabilities": {
    "chat_ready": true,
    "images": true,
    "max_images": 2,
    "max_image_bytes": 524288,
    "cart_mutations": { "available": true, "code": "available", "notice": "" }
  },
  "pending_turn": null,
  "server_time": 0
}
```

Boot maps one valid browser bearer to one active assistant session/conversation under a lease. Supplying valid resume credentials authenticates and extends that conversation atomically. A pending turn is projected only by exact ID. `widget.empty_state_hint` is non-conversational interface guidance: the browser renders it outside the transcript, without an assistant role, outcome, message identity, or turn identity.

## `POST /chat`

Allowed request fields:

```json
{
  "conversation_id": "uuid",
  "conversation_token": "opaque token",
  "client_turn_id": "uuid",
  "message": "exact UTF-8 plain text",
  "reply_context": { "text": "optional exact quoted display context" },
  "attachments": [
    { "mime_type": "image/jpeg|image/png|image/webp", "data": "canonical base64" }
  ]
}
```

At least one non-whitespace message or validated attachment is required. `message` is limited to 1,200 Unicode code points and is not rewritten. Optional `reply_context.text` is a separately hashed plain-text display excerpt limited to 280 code points; it never becomes part of `message`. There is no value-only browser command and no product/cart authority field.

Attachment limits:

- at most two;
- 16 to 524,288 decoded bytes each;
- at most 1,048,576 decoded bytes total;
- canonical base64 only;
- JPEG, PNG, or WebP matching the declared MIME type;
- maximum 4096 × 4096 and 12 million pixels;
- 2 MiB raw JSON body ceiling.

Canonical response fields:

```json
{
  "ok": true,
  "message": { "id": "uuid", "turn_id": "uuid", "role": "assistant", "outcome": "answer", "text": "...", "products": [], "receipts": [], "presentation": { "image_scope": "none", "images": [], "reply_quote": "" }, "created_at": 0 },
  "turn_committed": true,
  "conversation": { "id": "uuid", "token": "opaque token", "messages": [] },
  "messages_available": true,
  "messages_notice": "",
  "cart": { "item_count": 0, "formatted_total": "SAR 0.00", "cart_url": "https://store.example/cart", "checkout_url": "https://store.example/checkout" },
  "cart_available": true,
  "cart_notice": "",
  "cart_mutations": { "available": true, "code": "available", "notice": "" }
}
```

`turn_committed=false` is permitted only for the bounded new-turn rate-limit safe failure. A committed top-level `message` must exactly equal the terminal canonical transcript row for its `turn_id`.

## Canonical messages

Every message contains exactly:

```json
{
  "id": "uuid",
  "turn_id": "uuid",
  "role": "user|assistant",
  "outcome": "answer|follow_up|safe_failure|action_verified",
  "text": "plain text",
  "products": [],
  "receipts": [],
  "presentation": { "image_scope": "none|turn_only", "images": [], "reply_quote": "" },
  "created_at": 0
}
```

Assistant rows use one of the four outcomes shown above. Customer rows use an empty `outcome` string; they never masquerade as an assistant terminal outcome.

Safe failures additionally contain `failure_code` and `state_uncertain`. The transcript contains at most twelve complete ordered user/assistant pairs. Product cards and receipts are display evidence only.

Image bytes are never durable. Canonical user presentation stores only bounded kind, MIME type, and decoded byte length. Assistant presentation never contains images.

## Cart execution inside chat

There is no public add/update/remove/clear endpoint. The model can issue one internal `cart_apply` call after live catalog/cart reads. Its opaque references are scoped to the current agent run.

The internal command carries a byte-exact excerpt from the current customer message. PHP keeps optional browser reply context separate, builds one live proposal, then an isolated semantic verifier compares the exact message, reply context, current bounded images, recent conversation, and proposal. Context can resolve one unique target but cannot supply an action, quantity, variation, or approval. The verifier cannot alter the action, target, attributes, quantity, or scope and must echo the evidence fingerprint. Only an authorized verdict can cross the WooCommerce execution marker.

An incomplete add/replace variation request or update quantity may create one server-side expiring continuation. The model loop first seals the exact current provider step to the active turn, lease fence, model round, and a digest of the customer message, reply context, image evidence, recent cart-intent history, and pending authority. The sole exact `respond_follow_up` call must contain one natural Arabic question authored by the model; copied calls, changed validated arguments, multiple-call steps, or stale turn evidence are rejected. The server commits the exact unchanged bytes together with private fixed-tool, provider-call, customer-turn, conversation, purpose, round, argument-digest, current-turn-digest, acceptance-time, and integrity evidence. Exact retry or recovery of that committed turn returns the stored bytes without invoking the model again. No server-side canonical-question or wording fallback exists. The next typed message must itself supply every declared missing variation value or quantity and re-resolve matching live target and variation-axis fingerprints. PHP binds the exact active continuation only after the fresh plan matches its full structural contract; no continuation identifier exists in the model tool schema. A generic acknowledgement never fills a value. Expiry and structural identity are checked again immediately before WooCommerce execution. It is not a confirmation flow.

Cart success is returned only as `action_verified` with exactly one receipt whose message matches the assistant text byte-for-byte.

## Exact replay

The browser must reuse the byte-identical serialized body and same `client_turn_id` after an ambiguous result. One bounded envelope and its unresolved-turn identity are retained per tab before dispatch: `sessionStorage` is preferred, with one exact current-document memory fallback after unavailable access, rejected reads or writes, failed verification, or failed cleanup. Admission identity, continuity credentials, canonical conversation credentials, and boot coordination use the same resilient design over `localStorage`. Storage degradation never prevents dispatch. It preserves exact retry only inside the current document, disables claims of reload/cross-tab continuity, and never replaces server idempotency or conversation authority. Any admitted chat turn may reach a cart mutation, so ambiguous results block the composer until exact replay or canonical boot reconciliation proves the outcome.

Body expiry destroys sensitive request material but retains unresolved turn identity. The UI cannot return to ready until the server proves the turn complete or authoritatively absent after the execution guard.

`session_invalid` permits one same-conversation session renewal and exact replay. `conversation_invalid` permits adopting newer shared continuity or creating a new conversation when no current authority remains. Both require the exact closed error envelope and HTTP 401. Other errors do not authorize reset.

## Presentation degradation

After terminal commit, transcript and cart reads are presentation-only. Failure to project either returns HTTP 200 with the authoritative terminal message, the matching availability flag set false, a nonblank notice, and no fabricated data. The widget retains the local terminal pair until boot restores the canonical transcript. If the durable terminal message itself cannot satisfy the canonical public schema, the server emits the closed `committed_response_unavailable` error with HTTP 503 instead of leaking an invalid payload. Replaying the same `client_turn_id` remains mutation-safe because server idempotency, not transport status, owns the committed result.

## `GET /health`

The health route returns only the closed status projection:

```json
{
  "ok": true,
  "version": "1.0.0",
  "architecture": "ai-led-fenced-turns",
  "assistant_ready": true,
  "server_time": 0
}
```

Health is read-only. It combines the exact database-schema canary with the cached two-request provider proof and does not manufacture readiness from shopper traffic.

## Conversation privacy routes

`POST /conversation/export` and `POST /conversation/delete` require current signed session and conversation authority. Export uses an integrity-protected continuation cursor bound to one high-water snapshot. It returns a closed `{"ok":true,"export":{...}}` envelope containing bounded pages of explicitly projected canonical messages, verified receipts, turn records, cart operations, steps, and attempts. It does not recursively pass internal arrays through. Raw database IDs, keys, hashes, markers, credentials, lease fences, pending-cart candidates/fingerprints, and model/provider-call provenance are excluded. The exact accepted question remains visible only as canonical message text; its server-side `model_question` evidence envelope—including fixed tool identity, call IDs, turn digests, and integrity evidence—is never part of the export schema.

Delete is cursor-free, refuses while live turn/cart authority exists, removes the complete assistant-owned conversation graph, does not change the WooCommerce cart, and returns exactly:

```json
{ "ok": true, "deleted": true }
```

## `POST /admin/test`

The administrator readiness route returns one closed result envelope with the configured model, the two required check outcomes, request count, and proof timestamps. Catalog, shopper-prompt, cart, WooCommerce, or arbitrary diagnostic fields are not accepted in the public response. A fresh concurrent or superseded check returns `409`; deterministic provider or minimal-probe contradictions return `422`; quota returns `429`; transient network/upstream failure returns `503`; timeout returns `504`; other closed provider failures return `502`. Positive bounded `retry_after` is included only when the server has authoritative retry guidance. Provider text and values never cross the boundary.

## Errors

Error bodies use one closed envelope:

```json
{
  "ok": false,
  "code": "fixed_machine_code",
  "message": "nonblank bounded customer-safe Arabic text",
  "retry_after": 2
}
```

`retry_after` is optional and appears only as a positive bounded integer. Provider messages, database details, credentials, cart identities, and stack traces never cross this boundary.

Detailed model and database diagnostics remain administrator-only.
