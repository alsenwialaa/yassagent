# Architecture

## Authority model

The model interprets language; it does not own identity, state, or commerce truth.

- The browser supplies exact customer text, bounded images, and continuity credentials.
- Gemini requests declared tools using current conversation context.
- PHP validates closed request, tool, state, and terminal contracts.
- Live adapters issue current-run opaque product, variation, cart-line, and content references.
- WooCommerce performs the only cart primitives.
- Durable post-state evidence produces the only customer-visible cart success.

Product cards are display-only. Typed natural language is the only storefront command surface.

## Dependency direction

```text
Presentation → Application → Domain
        Infrastructure ────↑
```

`Domain` has no application or framework dependency. `Application` depends on ports and domain values, not WordPress/WooCommerce globals. `Infrastructure` implements I/O. Composition classes are the only concrete wiring boundary.

## Engineering verification boundary

Production code remains dependency-free at package runtime. Standard development tools are locked separately and are never shipped in the installable ZIP:

- PHPUnit owns the standard PHP test entry point and executes the decomposed historical regression suite as an integration test.
- PHPStan analyzes framework-independent core code at level 8 and WordPress/WooCommerce adapters at level 6 with official pinned stubs; the current exact ledgers contain 111 and 140 findings respectively.
- PHPCS applies PSR-12 plus selected WordPress security/database rules through an exact shrinking debt ledger.
- PHPCompatibilityWP enforces the PHP 7.4+ production floor with zero accepted compatibility debt.
- ESLint analyzes widget modules, administrative JavaScript, unit/browser tests, scripts, and deterministic integration-provider code.
- Architecture scripts enforce one declaration per production file, namespace/file identity, inward dependency direction, framework-free Domain/Application layers, duplicate detection, obvious orphan/private-method/import detection, and exact quality-ledger policy.

Baselines are debt inventories, not suppression authority. Every PHPStan entry is message-, identifier-, path-, and count-bound; every PHPCS entry is file-, sniff-, type-, and count-bound. The gate accepts reductions and rejects growth. Development configuration and ledgers live only in the source package under closed package roots; `vendor/`, `node_modules/`, caches, coverage, and `config/quality/` are excluded from the installable archive.

The former monolithic test entry points are decomposed by subsystem. The compatibility wrapper remains only to preserve all existing assertions while tests are progressively converted to native PHPUnit cases. Browser discovery is independent of file layout and must prove every top-level Playwright case executes exactly once with no skips, retries, or flakes.

## Release-hardening authority

Stage H is the boundary between the cumulative items 1–8 architecture and the item-9 clarification rewrite. It treats the delivered Stage G source and its Git commit as one release authority, requires a clean candidate tree, and separates three facts that must never be conflated:

```text
Local code correctness
Deterministic package reproducibility
External publication evidence
```

`release-hardening-policy.json` closes the accepted static debt, dependency locks, local suppression inventory, and no-growth budgets for oversized components. `release-hardening-findings.json` records every resolved, accepted, planned-item-9, or external-evidence finding with severity, scope, guard, resolution, evidence, and review date. Source mode validates those authorities; item-9 mode rejects unresolved local blockers outside the planned factory rewrite; publication mode additionally requires a fresh locked Composer install/advisory result and a passing artifact-first WordPress/WooCommerce promotion record.

The bounded Stage H runner executes the complete quality gate from the committed tree and again from the extracted source archive. It builds two independent archive sets, requires byte identity, validates safe paths, timestamps, modes, CRCs, required members, and production/source byte equivalence, then keeps container and network failures as explicit publication blockers. An unavailable external service can block promotion, but it cannot weaken or bypass a code gate.

Commerce quantities and price ranges now have single domain authorities (`CartQuantity` and `ProductPriceRange`). Their consumers may project those values but may not reintroduce private numeric limits or epsilon comparisons. The 1,027-line `PendingCartIntentFactory` remains the explicit item-9 target and is under a no-growth budget until it is replaced.

## Composition

`Infrastructure/Composition/PluginKernel.php` delegates to:

- `PersistenceStack` for repositories, transactions, leases, rate admission, retention, and privacy lifecycle;
- `CommerceStack` for catalog/cart projections, WooCommerce storage, mutation proof, and recovery;
- `ToolStack` for the immutable twenty-tool production catalog;
- `AgentStack` for Gemini transport, prompting, the model loop, and isolated cart-intent verification.

`Plugin.php` only boots the composed kernel and registers WordPress hooks.

## Request lifecycle

```text
Widget
  → REST decode and signed-session admission
  → physical-schema gate
  → conversation lease and exact-turn replay lookup
  → rate admission and canonical user-message persistence
  → interrupted commerce/turn reconciliation
  → AgentRunner
      → full production Gemini tool loop
      → live opaque authority
      → isolated semantic cart-intent verifier when needed
  → fenced WooCommerce operation journal
  → exact persistence and post-state verification
  → atomic assistant-message/turn/conversation commit
  → canonical transcript and display-only cart projection
```

A terminal database commit cannot later be rewritten as an HTTP failure by a presentation read. Transcript and cart projections degrade independently while the stored terminal result remains authoritative.

## Model protocol

The application layer exposes provider-neutral `ModelRequest`, `ModelSessionInterface`, `ModelStep`, `FunctionCall`, `FunctionFeedback`, and `ModelGatewayInterface` values. Gemini wire fields remain under `Infrastructure/Gemini`.

The primary model loop enforces:

1. Plain prose cannot terminate a turn.
2. Terminal calls and `cart_apply` must each be alone.
3. Read/state tools may share a step within bounded limits.
4. Invalid payloads return closed corrective feedback.
5. A verified cart receipt ends the turn immediately; the model cannot rewrite it.
6. Exhaustion or protocol failure yields a nonblank safe failure.

The complete serialized provider history is bounded. Normalized model output retains only required text, function identity, arguments, and thought state/signatures. Unsupported metadata never returns to the provider.

## Cart-intent architecture

`cart_apply` accepts exactly one command. The primary agent must first reacquire live authority in the same run and must copy a byte-exact excerpt from the current customer message.

The server then constructs a bounded `CartIntentVerificationRequest` containing:

- exact current customer text and a separately validated structured reply context;
- bounded current-turn image attachments when present;
- at most six complete recent conversation turns;
- the exact live target label, SKU, variation attributes, current quantity, and scope needed by the command;
- the closed server-resolved proposal and quantity meaning;
- the server-bound continuation identity, resolved missing values, and active server-owned clarification, when one exists;
- one SHA-256 evidence fingerprint.

`GeminiCartIntentVerifier` sends this evidence through a separate one-function request. Its system contract treats every evidence string as data, cannot alter the proposal, and returns exactly `authorized`, a closed reason, and the echoed fingerprint. Contradictory, malformed, or unbound output fails closed.

This semantic gate handles dialects, polite questions, pronouns, ellipsis, and image-grounded targets without a PHP keyword grammar. It authorizes only the exact present execution request. Recommendations, information questions, hypothetical/future/negated/quoted requests, unresolved references, target conflicts, quantity conflicts, composite requests, and broader proposals are denied. No denial reaches WooCommerce. When the denial requires clarification, the tool returns bounded internal evidence and the model must finish with one natural Arabic follow-up; server code cannot publish its own question. Non-requests and negations return to the model for a truthful no-change response.

`CartToolService` ordering is fixed:

```text
structural plan → exact current excerpt → live capability → semantic verdict
→ model clarification feedback or execution-start marker → durable mutation coordinator
```

The execution-start marker distinguishes harmless pre-execution denial from any path that may have touched the cart.

## Release promotion boundary

The source-mounted integration stack is a development accelerator, not artifact authority. The artifact-first promotion stack installs the exact deterministic plugin ZIP through WordPress, installs an exact local WooCommerce ZIP whose version is in `promotion_tested`, and verifies the installed plugin tree byte-for-byte against the preflight manifest.

Promotion executes clean-install and upgrade volumes independently, records exact plugin and WooCommerce checksums, and closes evidence only when every discovered browser scenario executes once, lifecycle assertions pass, and WordPress/WooCommerce diagnostics are clean. A missing container runtime produces a blocked status and exit code 69; it cannot be converted into a pass.

The compatibility matrix invokes that same artifact-first lane once per exact promoted WooCommerce package. Expanding the accepted runtime range does not create release evidence and does not enable mutation for an untested version.

## Typed clarification

### Sealed model-question authority

The model loop converts a terminal provider step into a `CurrentTurnModelStep` capability before any question can be accepted. That capability is bound to the exact `AgentContext` object, conversation and client-turn IDs, current lease resource and fence, provider round, and a versioned digest of the current customer message, reply context, image metadata/content hashes, recent cart-intent history, and active pending clarification. Changing any of that evidence makes the step stale.

`VerifiedFollowUpCall` can be issued only for the sole exact `FunctionCall` object in that sealed step, with byte-identical validated arguments, fixed tool identity `respond_follow_up`, and an allowed purpose. `ModelAuthoredQuestion` has no string constructor and accepts only typed verified evidence. Durable hydration uses the closed `StoredModelQuestionEvidence` envelope at the pending-intent and committed-turn repositories; it records the exact text, call identities, turn/conversation binding, model round, argument digest, current-turn digest, acceptance time, and an integrity digest. The integrity digest detects corruption and representation drift; it is deliberately not described as a secret MAC or protection against an attacker who can rewrite all trusted database fields.

Question text is validated and then retained byte-for-byte. Entity decoding is confined to `TrustedCommerceText` for trusted WooCommerce/WordPress catalog and cart display facts and cannot be used by the model-question path. Because the plugin is unpublished, conversation state schema 5 invalidates older pre-release question envelopes instead of carrying compatibility code.

One expiring `PendingCartIntent` may record missing variation axes for an add/replace or a missing number and quantity mode for an update. It contains stable server identity, product and variation-axis fingerprints when applicable, the exact target, already-bound values, the one missing field, and the exact already validated AI question. It is not a grant or executable plan. Gemini authors the visible question in a `respond_follow_up` call explicitly classified as `cart_continuation`. The server verifies that exact question against bounded live question authority and persists it unchanged with the authority and in the transcript; there is no canonical-question generator or server wording fallback. An exact retry, refresh recovery, or replay of that committed turn returns the same stored question byte-for-byte without asking Gemini again. A new but insufficient customer reply creates a new turn; Gemini may author one adaptive retry question with new provenance, but the server preserves the existing continuation identity, action, target, bound values, missing field, and expiry.

The active clarification is always projected to the isolated verifier. For a terse missing-value response, the server structurally matches one fresh live plan against the active target, operation, variation axes, source line, quantity mode, and missing-field contract. Only then does it bind the internal continuation identity and resolved missing values for semantic verification. The model never emits or selects that identity. The response must itself supply every missing value, remain unexpired at the final execution boundary, and pass the verifier again; generic acknowledgements never fill a value. A genuinely new independent request remains possible when its current text supplies the action and every required quantity/variation value. Structured reply context, a current image, or recent conversation may resolve one unique target only. Remove and clear never create a continuation.

Every paginated variation inspection carries one server-computed epoch over the exact complete projection shown to Gemini: the parent name, SKU and projected variation axes, plus every visible variation row including projected labels, term display values, price, stock, purchasability, and image. Coverage resets when any projected fact changes, even if the visible count is unchanged, so pages from different WooCommerce states cannot jointly authorize a question. Continuation resolution compares live and pending variation-axis labels through the shared Arabic catalog normalizer while preserving the original canonical label in verifier evidence.

Variable-product enumeration is a closed first-release capability: at most 1,000 child variations, 16 variation axes, 128 values per axis, and 100 projected rows per page. The product projection exposes `variation_catalog_supported` separately from structural `cart_supported`. An oversized or malformed option catalog is rejected before child product objects are loaded; an exact live variation returned by SKU lookup remains usable because it does not depend on enumerating the complete catalog.

Durable state serialization is clock-free. Callers provide one explicit `ClockPort` instant for model projection, pending-intent expiry, state transition, and commit. Expired pending state remains inert durable evidence until the next canonical transition, but is never projected to Gemini or accepted as execution context. Internal continuation identity is retained server-side and is absent from the model-visible pending projection.

## WooCommerce compatibility boundary

`config/woocommerce-compatibility.json` is the closed release policy for the compatibility-sensitive cart core. It separates an accepted runtime range from explicit promotion evidence. Version `10.9.4` is the current promotion-tested release. Later stable `10.x` releases may boot only when they remain below `11.0.0` and pass the same structural core-session capability proof, but they remain read-only for cart operations until that exact release is added to promotion evidence. They are reported to administrators as capability-gated, not release-tested. WooCommerce `11.x`, prereleases, malformed versions, and releases below the minimum fail closed.

`WooSessionInternalsAdapter` is the one application-facing production boundary for compatibility-sensitive WooCommerce internals. Its implementation is split into five private collaborators under `Infrastructure/WooCommerce/Internals`: `WooCoreStructureProbe` owns reflected core-class and method/property contracts, `WooSessionStorageInternals` owns session rows and cache identity, `WooCartHookTopology` owns native cart writers and hook containment, `WooCartIdentityInternals` owns cookie/cart-token and clone authority, and `WooPersistentCartInternals` owns persistent-cart projection. No application, presentation, lifecycle, or composition component may depend on those collaborators directly. `WooSession`, the request fence, cart stores, activation, and capability inspection use only the adapter's stable explicit surface.

Plugin activation and ordinary boot prove the admitted version and static core contract before composing the storefront. A cart write then repeats the promotion-version, active-object, storage, fence, durable-authority, and hook-topology proofs before acquiring mutation authority or changing request-local Woo state. Mutating adapter methods independently require a promotion-tested version, so a caller cannot bypass the primary capability inspector. Only a promotion-tested release registers the pre-hydration request fence or enters direct WooCommerce session storage and cart mutation. A capability-gated but unpromoted 10.x release retains native catalog/chat and read-only cart assistance and installs no assistant mutation hooks.

The adapter supports only WooCommerce's core session handler. A custom session handler may still serve catalog and read-only chat, but cart mutations remain disabled because the plugin cannot prove the custom handler's locking and durability behavior. Persistent object caches remain supported through transaction-owned database writes followed by cache invalidation. Structural drift is tested independently from version admission: even an in-range future version is rejected when the reflected core contract changes. The artifact-first compatibility matrix remains pinned to every exact version in `promotion_tested`, records the exact WooCommerce package checksum, and cannot turn an accepted version range into mutation evidence.

## Cart durability

`CartOperationCoordinator` is the sole `CartMutationPort` implementation. It uses:

- `CartStepPlanner` for one canonical primitive;
- `CartStepExecutionEngine` for intent recording, Woo execution, final calculation, session persistence, and read-back;
- `CartStepVerifier` and `CartLineAuthorityPolicy` for exact target and unrelated-state validation;
- `CartRecoveryCoordinator` for interrupted-operation evidence;
- `CartOperationTerminalizer` for verified/rejected/uncertain classification;
- `WooCartGateway` as the only owner of `add_to_cart`, `set_quantity`, `remove_cart_item`, and `empty_cart`.

Each operation is fenced by assistant-session/cart authority and a monotonically increasing database lease. The request-level Woo session fence serializes storefront writers from hydration through shutdown. Logged-in persistent cart data is verified separately when enabled.

Totals hooks run once at the final mutation boundary. Staging, proof, and recovery do not recalculate totals.

## Turn and replay state

A durable turn is unique by conversation and `client_turn_id`. Its canonical request hash covers exact customer text and attachment content evidence. The row stores only bounded input fingerprints, execution status, lease fence, terminal payload, and timestamps; canonical messages remain separate.

An exact duplicate replays the stored result without another provider call or side effect. Reusing an ID with different input is rejected.

The browser protects one exact unresolved request body in per-tab `sessionStorage` when available. Unresolved-turn identity uses the same per-tab boundary. Browser admission identity, the continuity bearer, canonical conversation credentials, and the short boot lease prefer site-scoped `localStorage`. A single resilient storage adapter owns all access and degrades an entire storage area coherently to current-document memory after an unavailable getter, rejected read, rejected or unverifiable write, or failed cleanup. Chat and exact same-document retry continue; reload recovery and cross-tab continuity do not. A new document creates fresh browser authority when no durable local state was available. Storage never grants execution authority, and server-side turn idempotency remains authoritative. Ambiguous results block new input until exact replay or canonical boot reconciliation proves completion. Client and server deadlines come from one execution policy.

## Continuity and sessions

A stable browser admission UUID is not authority. A separate random local bearer resolves through a server-HMAC-indexed continuity row to one assistant session and active conversation. The raw bearer is never stored server-side. Rotation requires the previous bearer and revokes it atomically.

Assistant conversation authority is independent of WooCommerce session/login identity. Losing browser continuity creates a new conversation; the surviving cart does not recover the old one.

## Storage

The clean first-release schema contains exactly nine InnoDB tables:

1. browser continuity authorities
2. conversations
3. messages
4. turns
5. cart operations
6. operation steps
7. operation-step attempts
8. leases
9. rate limits

Activation/explicit repair validates the exact physical definition. Runtime requests never perform DDL. Since the project is unpublished, repair rebuilds the complete assistant-owned schema instead of migrating historical development shapes.

The dependency gate also ensures activation rejects WordPress Multisite before schema or settings changes.

## Public projection

`config/public-api-contract.json` is the version-3 JSON Schema Draft 2020-12 authority for the complete closed REST/widget boundary. The generator produces both the browser projection and the PHP contract projection. Every plugin-owned boot, turn, health, privacy export/delete, administrator readiness, and error response crosses an endpoint-specific projector and the bounded PHP schema validator before the sole `WP_REST_Response` emitter can serialize it; arbitrary success arrays cannot cross the boundary. Canonical typed fixtures are built through the same projectors and checked by both PHP and AJV. Canonical messages contain exact role, outcome, text, product cards, receipts, presentation metadata, and timestamps. Privacy export uses explicit allowlist projection rather than recursive field removal. No client response contains raw database IDs, cart keys, line fingerprints, mutation plans, lease fences, pending-cart authority, or provider internals.

Images are turn-only model input. Canonical history stores bounded MIME/byte metadata but no image bytes, filename, URL, or thumbnail.

## Readiness

Runtime readiness is deliberately small and independent of shopper behavior. An administrator-triggered check performs exactly two bounded provider requests: one plain-text request with no tools to prove access to the configured model, followed by one `ANY` request exposing only the closed `readiness_echo` function and an opaque one-use token. The token must be returned unchanged through the normal Gemini gateway, schema projection, response decoder, function-call identity checks, and timeout boundary.

The persisted compatibility proof expires after exactly thirty days. Its fingerprint covers the effective API-key hash, fixed model, configured endpoint, a monotonic provider-configuration epoch, and the complete versioned minimal-probe wire contract, including the thinking level. It does not hash source files and does not include the shopper prompt, merchant guidance, production tool catalog, cart semantics, semantic verifier, WooCommerce, or schema readiness. Changing those shopper-facing concerns therefore cannot turn a production health check into an implicit integration suite.

The proof and the one active administrator attempt are separate authorities in a closed schema-v2 option record. Starting a recheck does not remove an unexpired proof. A timeout, network failure, quota response, temporary upstream outage, oversized response, or interrupted check records bounded failure evidence but preserves that proof. Authentication, missing/invalid model configuration, disabled service or billing, rejected minimal request contract/precondition, or an invalid minimal response contradicts and revokes it. Fresh concurrent attempts are rejected with bounded retry guidance; stale or configuration-old attempts may be superseded and are fenced before the second provider request and before publication. Option writes are read back exactly, cache eviction is limited to the readiness option, and failed revoking writes delete stale authority rather than leaving a proof active.

Health is a read-only conjunction of the physical database-schema canary and the cached provider runtime proof. It does not depend on a recent shopper boot and ordinary boot/health traffic does not write readiness state. Full catalog, clarification, cart-intent, mutation, replay, recovery, and WooCommerce compatibility certification remains in the mandatory offline release and integration gates.
