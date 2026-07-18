# Real WordPress/WooCommerce integration lane

This source-only harness runs the plugin against pinned WordPress 6.9, PHP 7.4, promotion-tested WooCommerce 10.9.4, MariaDB, a strict fake Gemini endpoint, and Playwright Chromium.

```bash
./integration/scripts/run.sh
```

The plugin source is mounted read-only. Test artifacts are written to `integration/artifacts`.

The fake provider accepts three deliberately separate request shapes:

- a no-tool plain-text request proving configured-model access;
- one isolated `readiness_echo` declaration proving minimal structured function calling;
- the production twenty-tool catalog and isolated `verify_current_cart_intent` verifier used by the full storefront scenarios.

The two readiness requests never load the shopper prompt, production catalog, cart semantics, or WooCommerce. Those deeper contracts remain in the offline integration scenarios. Ordinary AI-led turns use `VALIDATED`; explicitly constrained readiness and verifier calls use `ANY` with one declared `allowedFunctionNames` entry. Every request requires an explicit lowercase thinking level, and tool-bearing requests retain strict schemas, exact function-history identities, ordered feedback, and required thought signatures. The fake provider also has deterministic readiness-only modes for temporary outage, authentication, disabled service/billing, access or structured contract/precondition rejection, malformed structured output, and bounded transport delay; its self-test verifies every envelope without coupling those modes to shopper scenarios.

The source-mounted suite and packaged promotion extension define fifty storefront scenarios across:

- boot, signed-session renewal, browser continuity rotation, conversation resume/replacement, and canonical transcript rebasing;
- exact replay, concurrent replay, whole-request cart fencing, and browser/server reconciliation;
- typed natural simple/variable adds, polite default-one requests, pronoun continuation, absolute quantity updates, removal, and whole-cart clear;
- independent semantic cart-intent authorization and current-turn live-reference resolution;
- product/variation deletion, stock loss, Woo rejection/exception, hook-driven quantity/metadata drift, lease loss, interruption, and unattributed divergence;
- catalog search, newest/best-selling browsing, ranking, recommendation memory, and grounded product cards;
- malformed provider output, invalid tool schemas/arguments, mixed output, language correction, quota/unavailability, and missing fields;
- exact receipt persistence, operation journal classification, cart capability disclosure, and database state.

The harness deliberately uses normal typed messages for every storefront action. Product cards remain display-only and no browser selection authority exists.

Run static harness verification without Docker:

```bash
python3 integration/scripts/verify-source.py
node integration/fake-gemini/self-test.js
```

Static verification proves pins, mounts, tool ordering, protocol shape, scenario definitions, fault controls, and packaging exclusion. It does not replace the real Docker/Chromium execution lane.


## Artifact-first promotion authority

`integration/docker-compose.yml` deliberately mounts source and is not package-install evidence. The separate `integration/promotion/compose.yaml` stack installs the audited plugin ZIP and an exact local WooCommerce ZIP, verifies the installed file tree byte-for-byte, then runs clean-install, browser, cart, replay, recovery, upgrade, and uninstall evidence collection.

```bash
./scripts/run-woocommerce-promotion-gate.sh \
  --plugin-zip release/yassin-ai-assistant-v1.0.0.zip \
  --woocommerce-version 10.9.4 \
  --woocommerce-zip /secure/packages/woocommerce-10.9.4.zip
```

The gate exits 69 when Docker, Podman, Nerdctl, or a compatible Compose frontend is unavailable. That result is blocked, not passed.
