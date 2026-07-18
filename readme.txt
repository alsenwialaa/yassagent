=== Yassin Store AI Sales Agent ===
Contributors: yassinstore
Tags: woocommerce, ai, sales assistant, arabic, gemini
Requires at least: 6.9
Requires PHP: 7.4
WC requires at least: 10.9.4
WC tested up to: 10.9.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Arabic-first AI sales assistant with live catalog tools, typed natural-language cart requests, verified WooCommerce execution, and a responsive storefront widget.

== Description ==

Yassin Store AI Sales Agent lets customers discover, compare, and discuss products in Arabic and request cart changes using normal chat language.

Gemini interprets meaning, while the server retains authority over live product identity, cart state, quantity semantics, WooCommerce validation, persistence, and customer-visible success.

Cart mutations execute immediately only after the model reacquires current live product/cart references, PHP resolves one exact proposal, and an isolated semantic verifier confirms that the exact current customer message asks to execute that proposal now. The verifier understands dialects, polite questions, pronouns, and ellipsis; it rejects recommendations, hypotheticals, negation, quoted instructions, unresolved targets, and conflicting quantities. A denial is the final no-change result for that turn and cannot be retried by a later model round.

Product cards are informational. The storefront contains no quick-reply product/cart selection interface, proposal step, confirmation screen, or approval token.

Features include:

* Live semantic catalog discovery, SKU lookup, comparison, ranking, alternatives, categories, related products, and variation inspection.
* Typed shopping memory with privacy-safe product-selection constraints.
* Immediate verified add, quantity update, increment, decrement, remove, replace, and clear operations.
* One model-authored, server-validated typed clarification when an add/replace variation value or update quantity is missing, sealed to the sole exact current-turn provider call and active lease/model round, with exact continuation binding, private provenance, unchanged question bytes, and final-boundary expiry.
* Fenced WooCommerce session writes, exact post-state verification, durable receipts, interruption recovery, and idempotent replay.
* Canonical multi-tab transcript history and browser continuity independent of WooCommerce login/cart identity.
* Resilient browser continuity: local and per-tab persistence degrade coherently to current-document memory, so chat and exact same-document retry continue without treating browser storage as execution authority.
* Responsive Arabic RTL widget with product cards, reply/copy, image attachments, and conversation export/deletion.
* Closed REST/widget contracts, exact UTF-8 text, bounded image validation, and recursive log sanitization.

This is an unpublished first-release architecture with one clean schema and no migration or historical compatibility path.

== Installation ==

1. Install and activate WooCommerce 10.9.4 on WordPress 6.9+ for verified cart mutation. A later admitted WooCommerce 10.x release remains read-only for cart operations until promotion-tested. The plugin verifies its closed core-session capability contract before activation.
2. Upload and activate the plugin on a standard single-site installation.
3. Open WooCommerce > وكيل المبيعات الذكي.
4. Enter the Gemini API key and run the two-request runtime readiness check. A later administrator recheck preserves an unexpired proof through temporary network, quota, timeout, or upstream failures; credential, service, model, or exact minimal-contract contradictions revoke it.
5. Confirm model access and the minimal structured call, then test the exact production ZIP on staging.

For this first release, activation rejects WordPress Multisite before creating settings or schema.

The core WooCommerce session handler is required for chat cart mutations. Custom session handlers keep catalog/chat/cart viewing available but disable chat cart writes for that session.

== Frequently Asked Questions ==

= Does the model directly edit the cart? =

No. The model proposes one structured command using current-run opaque references. PHP constructs the exact live proposal, a separate semantic pass verifies present customer intent, and WooCommerce execution is accepted only after durable post-state proof.

= Does adding require a confirmation step? =

No. A clear typed request executes in the same turn. An incomplete request may ask one typed question for a missing add/replace variation value or update quantity; the customer's next message must supply the concrete value and is not a separate approval flow.

= Can I use Multisite or another WooCommerce session handler? =

Multisite is unsupported. A custom WooCommerce session handler disables chat cart mutations because the first release cannot prove its storage and locking behavior.

= Are uploaded images stored? =

No image bytes are stored in canonical history. Only bounded MIME type and decoded byte length metadata remain after the turn.

== Changelog ==

= 1.0.0 =

* Initial unpublished release candidate.
* Typed natural-language storefront interaction with isolated semantic cart-intent verification.
* Verified immediate WooCommerce cart execution, durable replay/recovery, privacy controls, and deterministic packaging.
