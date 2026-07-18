# Security model

## Trust boundaries

Untrusted inputs include customer text/images, browser storage, REST bodies, product/catalog text, WooCommerce extension metadata, provider output, forwarded network headers, and database rows that fail exact schema/value validation.

The browser never receives or supplies trusted product IDs, variation IDs, cart item keys, line fingerprints, cart plans, or success evidence. Product cards are informational. Typed chat is the only command surface.

## Model-authored question authority

A customer-facing follow-up is a capability, not a string. The model loop seals the exact provider step under the active `AgentContext`, lease fence, model round, and a digest of the complete current-turn evidence, including recent cart-intent history and active pending authority. Only the sole exact `respond_follow_up` call object with byte-identical validated arguments can produce `VerifiedFollowUpCall` evidence. Copied calls, multiple-call steps, stale contexts, changed customer evidence, and invalid purposes fail closed.

The durable envelope fixes the tool identity and retains exact text plus private call, turn, conversation, round, argument-digest, current-turn-digest, and acceptance evidence. Its SHA-256 integrity value detects accidental corruption and representation drift; it is not a secret MAC and does not claim authenticity against an attacker able to rewrite every trusted database field. Runtime construction still requires live typed evidence, and hydration is restricted to committed-turn replay and pending-intent restoration. None of the private evidence is exposed through REST or privacy export.

## Cart authorization and execution

The primary agent must resolve one live opaque reference in the same run as `cart_apply`. PHP keeps the browser reply context in a separate closed field, proves that the supplied evidence is a byte-exact excerpt of the current customer message, and constructs one closed live proposal.

An isolated Gemini request then compares that exact message, separately structured reply context, current bounded images, bounded conversation, and live proposal. Reply/history/image context may resolve one unique target only; the current text must supply the action and every required quantity/variation value. Evidence is untrusted data, the verifier cannot edit the proposal, and its verdict is bound by an echoed SHA-256 fingerprint and closed reason enum. Malformed output, provider failure, generic acknowledgement of a missing value, or ambiguity denies execution. For an incomplete but bindable request, Gemini also supplies the visible Arabic question and declares `cart_continuation`; the isolated verifier checks it against live missing axes, listed options, action, target, and quantity mode. Server code stores the bound clarification authority and exact verified AI question but never synthesizes or substitutes customer-facing wording. An exact retry, refresh recovery, or replay of the same committed turn returns those exact stored bytes without another model call. A new but insufficient customer reply is a new turn and may receive one newly model-authored adaptive question, but it cannot change the existing continuation identity, action, target, bound values, missing field, or expiry. The server binds a matching plan to the one active clarification; the model cannot emit or select a continuation identifier.

Execution proceeds only in this order: structural validation, live capability proof, semantic authorization, execution marker, fenced WooCommerce mutation. There is no browser approval token, proposal endpoint, confirmation turn, or public cart route.

The semantic verifier rejects informational/recommendation questions, hypothetical or future plans, negation/cancellation, quoted/reported instructions, unresolved references, conflicting quantities, and any server proposal that exceeds the exact current request. Conversation history resolves language only; it cannot create a present execution request.

## Commerce integrity

- Exactly one reversible add/update/remove/replace/clear command is admitted per operation.
- Product and variation identity are revalidated immediately before execution.
- Existing-line updates bind cart key, product/variation identity, normalized attributes, custom-data hash, and live quantity.
- WooCommerce add/update validation and aggregate stock checks run before side effects.
- Whole-request session fencing serializes cart writers from hydration through shutdown.
- One final totals calculation occurs before persistence evidence is sealed.
- Core session storage and enabled logged-in persistent-cart storage are both read back and verified.
- Interrupted operations reconcile from durable intent/effect/persistence evidence and become verified, rejected, pending, or uncertain; uncertainty is never displayed as success.
- A verified receipt is server-generated and must remain byte-identical across commit, replay, and recovery.

## Session and conversation authority

The assistant session token is signed and short-lived. Conversation access additionally requires the opaque conversation token and server-side session binding.

A site-scoped random browser bearer resolves through a server-HMAC index to one assistant session and active conversation. The server stores neither that raw bearer nor a recoverable equivalent. Rotation requires the previous bearer and revokes it atomically. WooCommerce cookies, cart tokens, and login state cannot recover assistant conversation authority.

Resume authentication, row locking, and retention extension occur in one transaction. Conversation replacement clears transcript, attachments, reply state, exact retry material, and stale continuity before the new authority becomes usable.

## Replay and deadlines

Each chat request has a UUID `client_turn_id` and canonical request hash. Exact completed requests replay the durable terminal result without another model call or side effect. A different request under the same ID is rejected.

The browser retains at most one exact unresolved request body per tab and blocks new input after any ambiguous result. Server and browser use one timing policy. Body expiry does not imply safety: unresolved identity remains blocked until boot proves terminal completion or the execution guard expires with an authoritative absent result.

Only the exact closed HTTP error envelope plus its required status authorizes credential recovery. Generic 401/4xx/5xx bodies never reset or fork a conversation.

## REST and text safety

REST objects have exact allowed keys and bounded nesting. JSON request size is enforced on actual bytes, independent of `Content-Length`. UUID/token syntax, Unicode code-point limits, canonical base64, image type/dimensions, and aggregate decoded size are validated before domain execution.

Accepted customer text remains byte-identical through request hashing, model input, transcript persistence, replay, and display. It is rendered with text-only DOM APIs. Escaping belongs at output boundaries; WordPress text sanitizers are not used to rewrite intent.

Public URLs use one strict absolute HTTP/HTTPS policy and cannot carry credentials. The widget contains no HTML execution sink or direct unvalidated navigation assignment.

## Provider protocol

Gemini receives one fixed endpoint, bounded requests, an explicit thinking level, and closed tool declarations. Ordinary agent rounds use `VALIDATED`; isolated semantic verdicts use `ANY` with one allowed function. Administrative runtime readiness is separate: one no-tool access request followed by one `ANY` request exposing only `readiness_echo`. Responses must contain exactly one executable candidate and supported part shapes. Function identity, ordering, arguments, responses, and thought signatures are validated before reuse.

Provider error text is not exposed to customers or written to normal logs. Only fixed bounded classifications and a sanitized structural field path cross the transport boundary. Runtime readiness uses a closed failure taxonomy: transient availability, quota, network, timeout, response-size, and interruption failures cannot revoke an unexpired compatibility proof; deterministic provider/configuration contradictions and failures of the exact minimal probe contract do revoke it. A recheck owns a fenced attempt identity separate from the retained proof, and exact option read-back prevents cache or persistence ambiguity from manufacturing readiness.

## Database and concurrency

All nine plugin tables must match the exact InnoDB definition, indexes, collations, and binary authority fields. Runtime entry points fail closed on physical drift and do not execute DDL. Explicit administrator repair shares a maintenance lock and rebuilds the assistant-owned schema as one clean unit. Database-schema state and provider runtime readiness are independent authorities; repair does not manufacture or revoke provider proof.

Conversation, turn, operation, and session writers use row locks and monotonically increasing lease fences. The options-based ingress limiter verifies transactional storage instead of assuming it.

## Network admission

Boot admission is bounded per stable browser identifier, immediate network, trusted resolved client network, and site. Forwarded headers are ignored unless the immediate peer belongs to an explicitly configured canonical proxy CIDR. Global ceilings limit abuse behind large shared networks.

## Privacy and logging

Logs use fixed operational events by default. Optional diagnostics pass through recursive length and key sanitization that removes credentials, cookies, tokens, contact data, customer text, provider bodies, and image content.

Conversation export/deletion requires current assistant-session and conversation authority. Export omits raw execution identities and credentials. Deletion refuses while a turn or cart operation is active and never mutates the WooCommerce cart.

## WooCommerce internals containment

`WooSessionInternalsAdapter` is the only application-facing component permitted to expose compatibility-sensitive WooCommerce core operations. Five private collaborators isolate reflection/core structure, durable session storage and cache identity, cart-hook containment, cookie/token/clone authority, and persistent-cart projection. Static architecture checks reject any production dependency on those collaborators outside the adapter or any escape of protected/private WooCommerce layout.

Cart mutation is denied before side effects unless the exact installed WooCommerce release is promotion-tested and the request can prove the expected core handler, pre-hydration fence, durable storage topology, operation authority, and native writer hooks. The checks run before lock reacquisition, working-session replacement, hook suppression, authority publication, or cache invalidation, and mutating adapter methods repeat the promotion check as defense in depth. In-range but unpromoted releases install no assistant mutation fence and retain only native read-only assistance.

## Supported platform

The first release supports WordPress 6.9+, PHP 7.4+, the admitted WooCommerce 10.x range in `config/woocommerce-compatibility.json`, the verified core WooCommerce session handler, and single-site installations. WooCommerce 10.9.4 is the only promotion-tested cart-mutation release. Other admitted 10.x releases remain read-only for cart operations; unsupported versions or core-session topology disable activation rather than invoking a compatibility guess.
