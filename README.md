# Yassin Store AI Sales Agent 1.0.0

An Arabic-first WooCommerce sales assistant. Gemini interprets natural customer language; PHP, WooCommerce, and the database retain authority over product identity, cart state, persistence, and success.

This project is an unpublished first-release candidate. It intentionally contains no migration chain, deprecated alias, historical fallback, or compatibility path for earlier development builds.

## Interaction model

Customers communicate only by typing normal language or attaching a bounded image. Product cards are informational links. There are no quick-reply controls, browser-issued product/cart authority, proposal screen, confirmation step, approval token, or second cart-authorization turn.

Cart changes execute immediately only after this closed sequence:

1. The primary agent interprets the current message and conversation.
2. It reads the live catalog or cart and receives current-turn opaque references.
3. It submits one `cart_apply` command and a byte-exact excerpt from the current customer message.
4. PHP resolves the opaque references, quantity mode, target, and live cart revision into one closed proposal.
5. An isolated Gemini verifier compares the exact current customer message, separately structured reply context, current bounded images, recent conversation, live labels/attributes, and exact server proposal. It cannot alter the proposal and must echo its evidence fingerprint.
6. Only an authorized verdict reaches the fenced WooCommerce executor.
7. The plugin reports success only after exact post-state, session persistence, and any logged-in persistent-cart state are durably verified.

The semantic pass understands Arabic dialects, English, polite questions, pronouns, ellipsis, and a default add quantity of one. It rejects recommendations, hypotheticals, negation, future plans, quoted instructions, unresolved targets, conflicting quantities, and any proposal broader than the customer request. No rejected proposal reaches WooCommerce. Recoverable ambiguity returns bounded diagnostic evidence to the model, which must author one natural Arabic follow-up; a proven non-request, negation, unsafe request, or provider/protocol failure remains a no-change response.

An incomplete add/replace variation request or quantity update may store one expiring server-side clarification. Gemini must supply the natural Arabic question together with a non-executable continuation descriptor and explicit `cart_continuation` purpose. The model loop seals the exact provider step to the active turn, lease, model round, recent intent history, pending authority, and current customer evidence. Only the sole exact `respond_follow_up` call with byte-identical validated arguments can issue typed question authority. The server validates that exact question against live axes, listed options, target, action, and quantity mode, then stores the bound target, missing field, unchanged AI wording, and private self-verifying provenance. It never writes or substitutes customer-facing question text. An exact retry, refresh recovery, or replay of the same committed turn returns the exact stored AI question byte-for-byte without another model request. A new but insufficient customer reply is a new turn: Gemini may author one adaptive question with new provider and turn provenance, while the server preserves the same action, target, bound values, missing field, and expiry. The customer answers by typing every missing variation value or the missing quantity; a generic acknowledgement is never a value. The server—not the model—binds a structurally matching fresh plan to the one active clarification before semantic verification and rechecks it immediately before execution. Variation pages must belong to one exact projected live catalog epoch. Enumeration is explicitly bounded to 1,000 variations, 16 axes, 128 values per axis, and 100 rows per page; larger option catalogs remain purchasable from their product page rather than being partially represented in chat. Arabic label punctuation or diacritics are matched with the shared catalog normalizer. A new independent request remains possible only when its current message supplies the action and all required values. Structured reply context, a current image, or recent conversation may resolve one unique target, but can never supply the action, quantity, variation value, or approval.

## Prompt protocol

- Every storefront turn uses one canonical JSON envelope: `customer_message` is current customer evidence and `reply_context` is quoted target context only. Administrative runtime readiness intentionally does not load this shopper envelope.
- The Arabic production system instruction defines authority, tool routing, sales behavior, cart semantics, clarification, and terminal output once. Runtime corrections are drawn from one closed feedback policy and cannot contradict exact committed-turn replay or the active continuation authority.
- All twenty production function descriptions live in one exact registry. Composition fails if a tool is missing a description or a description has no tool.
- Ambiguous cart fields carry provider-visible schema descriptions in addition to strict server validation.
- The sole merchant extension field is `store_guidance`; it is JSON-encoded as lower-authority preference data and cannot override language, live-reference, verification, mutation, or receipt rules. The misleading unpublished `core_instructions` setting was removed rather than retained as an alias.
- The isolated verifier has its own non-executing decision prompt, closed reason taxonomy, bounded examples, and one required verdict function.
- Runtime readiness is a separate two-request provider check: one no-tool access response and one closed `readiness_echo` call. Its 30-day proof is bound only to provider configuration and the versioned minimal-probe contract; full prompt/tool/cart behavior is certified offline. An administrator recheck keeps an existing proof active through transient outage, quota, network, timeout, and interruption failures, but deterministic credential/model/service or exact-probe contract contradictions revoke it immediately.

## Runtime guarantees

- Arabic plain-text terminal responses; Markdown/HTML and predominantly non-Arabic terminal prose are rejected for correction.
- Twenty closed production tools under Gemini `VALIDATED` mode for ordinary agent rounds. The isolated semantic verifier and the one-tool runtime readiness call use `ANY` with one allowed function.
- Runtime readiness performs exactly two bounded provider requests and never loads the shopper prompt, production tools, catalog, cart, or WooCommerce. Deep semantic and commerce behavior remains in the release/integration suite.
- Opaque product, variation, cart-line, and content references scoped to one agent run.
- Exactly one add, quantity update, remove, replace, or clear command per cart operation.
- Integer quantities, explicit absolute/increment/decrement semantics, aggregate stock validation, and full variation identity.
- Whole-request WooCommerce cart serialization, monotonic fencing, exact persistence read-back, and conservative interrupted-operation recovery.
- Verified receipt text generated from durable evidence and replayed byte-for-byte.
- One canonical 12-turn transcript window shared by browser history and the next Gemini request.
- Exact UTF-8 customer text across validation, hashing, model input, persistence, replay, and display.
- Idempotent `client_turn_id` replay with one protected exact request envelope and canonical reconciliation after ambiguous timeouts.
- Browser continuity independent of WooCommerce cart cookies and customer login state.
- Clean, exact nine-table InnoDB schema with fail-closed physical validation.
- Bounded image input: JPEG/PNG/WebP, at most two images, 512 KiB decoded each, 1 MiB total, 4096 pixels per axis, and 12 million pixels.
- Conversation-scoped export and deletion plus global uninstall cleanup.

## Requirements

- WordPress 6.9 or later
- PHP 7.4 or later
- WooCommerce 10.9.4 is promotion-tested for verified cart mutation. Later admitted WooCommerce 10.x releases are subject to the closed core-session contract and remain read-only for cart operations until promoted.
- Gemini `gemini-3.5-flash`
- InnoDB for plugin-owned tables
- Standard single-site WordPress; Multisite is unsupported

WordPress Multisite is intentionally unsupported in version 1.0.0.

The supported WooCommerce session handler is the core `WC_Session_Handler`. Persistent Redis/Memcached object caching is supported through transaction-owned database writes and post-commit invalidation. A custom session handler keeps chat and cart viewing available but disables chat cart mutations for that storefront session.

## Installation

1. Back up the staging site.
2. Install and activate WooCommerce 10.9.4 for the full verified-cart path. A later admitted WooCommerce 10.x release may run catalog/chat and read-only cart assistance, but cart mutation remains disabled until that exact release is promotion-tested. The plugin fails closed when the core session capability contract does not match.
3. Install the production ZIP and activate it on a single-site WordPress installation.
4. Open **WooCommerce → وكيل المبيعات الذكي**.
5. Configure Gemini and run the two-request runtime readiness check.
6. Confirm the admin state reports model access and the minimal structured call as verified.
7. Test typed add, variation add, update, increment, decrement, remove, clear, replay, reload, and interrupted-request recovery on the storefront.

Development builds must be installed against a clean reset of earlier assistant-owned storage. WooCommerce cart/session data and plugin settings are outside that schema reset.

## Public REST surface

Namespace: `yassin-ai/v1`

- `POST /boot`
- `POST /chat`
- `POST /conversation/export`
- `POST /conversation/delete`
- administrator-only readiness and repair routes

There is no standalone public cart mutation endpoint. All cart execution occurs inside one admitted chat turn.

## Tool surface

Catalog and intelligence:

- `catalog_discover`
- `catalog_get_details`
- `catalog_compare`
- `catalog_rank_candidates`
- `catalog_find_alternatives`
- `shopping_memory_update`
- `catalog_get_product_by_sku`
- `catalog_get_variations`
- `catalog_related`
- `catalog_list_categories`

Content:

- `content_search`
- `content_get`
- `store_policy`
- `store_info`

Commerce:

- `cart_view`
- `cart_apply`
- `checkout_get_url`

Terminal:

- `respond_answer`
- `respond_follow_up`
- `respond_safe_failure`

`action_verified` is never model-authored. It is assembled by the server from a verified receipt.

## Widget

The deterministic widget bundle supports responsive list/grid/carousel product presentation, Arabic RTL layout, accessible dialog behavior, reply/copy actions, bounded image attachments, conversation export/deletion, configurable appearance, exact retry recovery, and dynamic mounting. Browser admission identity, the continuity bearer, conversation credentials, and the boot lease prefer site-scoped `localStorage`; unresolved-turn identity and one exact retry envelope prefer per-tab `sessionStorage`. Every store shares one resilient boundary and falls back to current-document memory when access, reads, writes, verification, or cleanup fail. Chat and exact same-document retry remain available, while reload and cross-tab continuity are explicitly reduced. Storage is never mutation authority; server-side idempotency and conversation checks remain authoritative.

Manual placement uses:

```text
[yassin_ai_assistant]
```

## Development verification

```bash
npm ci
./scripts/quality-gate.sh
```

The Playwright browser suite is mandatory and cannot be skipped. The source-mounted Docker lane is a fast development check:

```bash
YSAI_RUN_INTEGRATION=1 ./scripts/quality-gate.sh
```

It is not release authority because WordPress executes the working tree. Promotion must install the exact plugin ZIP and exact WooCommerce package through normal WordPress mechanics:

```bash
./scripts/run-release-gate.sh \
  --woocommerce-version 10.9.4 \
  --woocommerce-zip /secure/packages/woocommerce-10.9.4.zip
```

WooCommerce private/protected implementation knowledge is contained behind one application-facing `WooSessionInternalsAdapter`. Its five private collaborators separate core reflection, session storage/cache, hook containment, cookie/token/clone identity, and persistent-cart projection. An admitted but unpromoted WooCommerce release does not register assistant mutation hooks and cannot enter any direct session-write method.

Every version in `config/woocommerce-compatibility.json` under `promotion_tested` can be certified independently:

```bash
./scripts/run-woocommerce-compatibility-matrix.sh \
  --plugin-zip release/yassin-ai-assistant-v1.0.0.zip \
  --woocommerce-zip 10.9.4=/secure/packages/woocommerce-10.9.4.zip
```

A missing container runtime returns status 69 and remains a publication blocker; it is never a pass.

The version-3 Draft 2020-12 schema at `config/public-api-contract.json` is the single public REST/widget contract authority. Generated browser and PHP projections, endpoint-specific response types, runtime nested-response validation, and projector-built valid/invalid fixtures are checked against it. Privacy export is an explicit allowlist projection and cannot expose pending-cart or model-call authority through unknown internal fields. The deterministic builder must reproduce `assets/js/widget.js` from `assets/js/widget/build-order.txt`. The package script creates separate production and source archives and excludes tests, integration infrastructure, module sources, local artifacts, credentials, and prior release output from the installable ZIP.

## Release limitation

Unit and static checks do not replace staging tests with the exact production ZIP, real WordPress/WooCommerce hooks, the active cache/CDN stack, a real browser, and representative product extensions. Promote only the exact audited archive that passed those checks.
