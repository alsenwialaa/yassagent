# Privacy

## Data processed

The assistant may process:

- exact customer messages and bounded image attachments;
- canonical assistant messages and product/receipt presentation;
- browser admission identity, signed assistant session, continuity authority, and conversation credentials;
- typed shopping goal, stage, product constraints, compared product names, and one unresolved question;
- WooCommerce product/catalog facts and current cart state needed for the request;
- turn, lease, rate-limit, and cart-operation evidence required for replay and recovery.

Customer text is validated as UTF-8 plain text and remains byte-identical across request hashing, model input, transcript persistence, replay, and display. It is not silently sanitized or normalized.

## Images

Images are available to the model for one turn only. Durable canonical history stores MIME type and decoded byte length, not image bytes, filename, local preview, URL, or thumbnail. Browser previews are revoked and discarded after use.

## Cart evidence

The model sees current-run opaque references rather than WooCommerce database IDs or cart keys. Durable operation rows contain bounded plan/effect/persistence evidence for idempotency and interruption recovery. Public responses and exports omit raw cart keys, line fingerprints, persistence markers, resource hashes, request hashes, lease fences, and database row IDs.

The isolated semantic cart verifier receives only the exact current message, optional structured reply context, current turn-only images, a bounded recent conversation, live display facts needed to compare the target, the exact closed proposal, and an evidence fingerprint. It cannot alter the proposal.

## Browser storage

When browser persistence is available, site-scoped `localStorage` contains:

- a stable random admission UUID;
- a random continuity bearer used to resolve the active assistant session/conversation;
- canonical conversation credentials and a short boot-coordination lease.

Per-tab `sessionStorage` contains at most one bounded unresolved-turn identity and one exact unresolved request envelope. The envelope may contain exact customer text, conversation credentials, reply context, and bounded image bytes. It exists only for exact retry, is usable only with matching server authority, and is removed after terminal reconciliation, bounded expiry, or tab-session end. After body expiry, only unresolved turn identity remains until the server proves the outcome.

All browser persistence passes through one resilient boundary. If storage is unavailable or rejects a read, write, verification, or cleanup, the affected area is quarantined and the current document uses memory only. The active page can continue chatting and can replay its exact retained request, but a reload or another tab cannot rely on that ephemeral state. A new document receives fresh browser authority when durable local continuity was unavailable. The raw continuity bearer is never stored server-side. WooCommerce cart/login state is not used to recover assistant conversation authority, and browser storage is never cart-mutation authority.

## Shopping memory

Shopping memory has a closed product-selection schema and rejects contact, address, payment, credential, medical-record, and phone-shaped identifier data. It stores no product database IDs. A new topic clears stale context; old memory ages out of active model context.

## Logging

Normal logs contain fixed operational event names only. Optional diagnostics pass through recursive key/value and length limits that remove customer text, credentials, cookies, session/conversation tokens, continuity bearers, contact data, provider bodies, image data, and oversized values. Provider-controlled error messages are not logged verbatim.

## Retention and lifecycle

Conversation retention is configurable. Lowering retention rebases stored expiry to the new maximum. Scheduled cleanup removes expired conversation graphs in bounded transactional batches and separately retires stale leases and rate buckets.

## Model-authored question evidence

The visible question is stored as ordinary canonical assistant-message text. Separately, the server stores a private evidence envelope containing the fixed follow-up tool identity, model-step and tool-call identifiers, provider call identifier, client-turn and conversation IDs, model round, validated-argument and current-turn digests, acceptance time, and integrity digest. These fields support strict commit, replay, and pending-clarification validation. They are not included in public REST responses or conversation exports. The integrity digest is a corruption/drift check, not a secret authentication code.

The storefront privacy controls provide conversation-scoped export and deletion to the current credential holder. Export captures one coherent high-water snapshot in bounded signed-cursor pages and projects each public field through an explicit closed allowlist. Pending-cart candidates, fingerprints, model-question provenance, provider identifiers, internal persistence evidence, and future unknown fields cannot leak through a recursive pass-through filter. Deletion refuses while a turn/cart operation is active, removes the complete assistant conversation graph, and does not alter the WooCommerce cart.

When **Delete data on uninstall** is enabled, uninstall attempts each assistant-owned cleanup stage independently, including tables, options, scheduled hooks, and ingress rows. One failed stage does not suppress later cleanup attempts.

## External processing

Configured conversation text and turn-only images are sent to the selected Gemini endpoint to generate the assistant response and, for an attempted cart action, to the isolated semantic verifier. WooCommerce and WordPress continue to process store/customer data according to the site’s own policies and configuration.

Site operators should disclose the assistant, Gemini processing, retention period, browser retry storage, and conversation export/deletion controls in their published privacy notice.
