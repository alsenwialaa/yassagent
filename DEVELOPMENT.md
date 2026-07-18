# Development

This repository targets one unpublished 1.0.0 authority. Do not add migrations, deprecated aliases, historical schema repair, old WooCommerce signatures, or compatibility fallbacks for earlier development builds.


## P0 stabilization freeze

Feature development is frozen until every mandatory quality gate passes from a clean tree and the audited production ZIP passes installation and smoke verification. During P0, changes are limited to correctness and security fixes, public-contract corrections, browser and recovery reliability, compatibility work, tests, diagnostics, observability, and documentation that matches runtime behavior.

Do not add new model tools, shopper flows, UI features, database features, product capabilities, or compatibility surfaces during the freeze. A skipped browser case, missing contract check, unavailable required dependency, or partial test execution is a failed promotion gate—not a qualified pass.

## Supported floor

- WordPress 6.9+
- PHP 7.4+
- WooCommerce 10.9.4 is promotion-tested for cart mutation; other admitted 10.x releases remain read-only until their exact package passes the promotion lane. The range and closed core-session contract are defined in `config/woocommerce-compatibility.json`
- Node/npm versions pinned by `integration/version-lock.json`
- single-site WordPress

## Layout

```text
src/Domain                 framework-independent values and invariants
src/Application            use cases, ports, turn/model/cart contracts
src/Infrastructure         WordPress, WooCommerce, Gemini, database adapters
src/Presentation           REST/admin/storefront entry points
assets/js/widget           authoritative modular widget source
assets/js/widget.js        deterministic production bundle
tests                      PHP, JavaScript, browser, and admin tests
integration                pinned real WordPress/WooCommerce stack
scripts                    build, verification, and packaging
config                     closed public API contract
```

## Core rules

- Domain and Application code must not call WordPress/WooCommerce globals.
- Every provider tool has one immutable contract and one handler.
- The production catalog contains exactly twenty tools under `VALIDATED`. The isolated semantic verifier and minimal `readiness_echo` check use `ANY` with one allowed function; the readiness access request has no tools.
- Runtime readiness stores a schema-v2 record with separate proof and active-attempt fields. The deterministic provider exposes explicit access/structured failure scenarios for transient outage, authentication, service/billing disablement, contract/precondition rejection, and malformed structured output. Promotion evidence must prove transient preservation, deterministic revocation, and exact two-request recovery.
- Product cards are display-only; normal typed language is the command surface.
- Cart execution requires current-run live opaque authority, exact current-message evidence, an isolated fingerprint-bound semantic verdict, and durable WooCommerce verification.
- `cart_apply` is isolated and contains exactly one command.
- No customer-visible cart success may be model-authored.
- Every customer-visible cart clarification must be model-authored and server-validated. PHP may replay only the exact stored question for the same committed turn and must never generate or substitute wording; a new customer turn may accept a new model-authored adaptive question only with new provenance and unchanged continuation authority.
- A follow-up can be constructed only from the sole exact `respond_follow_up` call in a `CurrentTurnModelStep` sealed by the active model loop. No raw string, copied call object, changed validated arguments, stale turn context, or arbitrary database array may issue runtime question authority.
- Durable model-question hydration is limited to `TurnCommitter` and `PendingCartIntent`; all server-only provenance remains outside REST responses and privacy exports.
- Accepted customer text must remain byte-identical. Entity decoding belongs only to trusted WooCommerce/WordPress display facts through `TrustedCommerceText`, never model output.
- Runtime requests may validate schema but never execute DDL.
- The installable archive excludes module source, tests, integration infrastructure, dependencies, credentials, artifacts, and prior releases.

## Install dependencies

```bash
composer install --no-interaction --prefer-dist --no-progress
npm ci
```

`composer.lock` and `package-lock.json` are authoritative. Every direct development dependency is pinned exactly. Composer has no production package dependencies beyond the PHP platform constraint, and neither `vendor/` nor `node_modules/` may enter either release archive.

## Engineering baseline

The standard development gate is:

```bash
npm run test:engineering
```

It runs PHPUnit, PHPStan, PHPCS, PHPCompatibility, source-layer checks, duplicate/dead-code checks, dependency-metadata validation, and ESLint. The complete repository gate runs the same checks again before browser and package verification:

```bash
./scripts/quality-gate.sh
```

Current pre-release debt is explicit and shrinking rather than hidden:

- PHPStan core: level 8 with 111 exact, path-bound baseline findings.
- PHPStan WordPress/WooCommerce adapters: level 6 with 140 exact, path-bound baseline findings.
- PHPCS: 1,175 exact file/sniff/type findings in a no-growth ledger.
- PHPCompatibility for PHP 7.4+: zero findings.
- ESLint: zero findings.

New findings are forbidden. Removing debt is accepted automatically; increasing or broadening a baseline requires an explicit reviewed policy change. Do not add blanket `excludePaths`, unscoped ignore patterns, or warning suppression to make a gate green.

The historical PHP regression suite remains authoritative while it is migrated incrementally. Its small entry point loads ten subsystem case files and is executed from PHPUnit, which proves all 378 cases and the exact current 8,095-assertion count. JavaScript unit coverage is split into seven subsystem case files, and Playwright coverage is split into five top-level specifications while discovery still proves every case executes exactly once.

## Stage H release hardening

Before item 9, run the cumulative hardening authority from a clean committed tree:

```bash
npm run test:stage-h -- \
  --composer /absolute/path/to/composer
```

The Stage H runner does not trust one in-place test result. It records bounded phase logs, runs the complete committed-tree gate, builds twice and compares bytes, audits both archives, extracts the exact source ZIP, performs a clean npm install, attempts a clean Composer install and, when external package infrastructure is unavailable, may use an explicitly marked lock-matching offline metadata fallback for source replay, and reruns the complete gate from that extracted package. It then records Composer advisory evidence and, when supplied, runs the exact installable ZIP with an exact WooCommerce ZIP through the artifact-first promotion lane.

`item9` mode succeeds only when no unresolved code or architecture blocker remains outside the planned clarification rewrite. Publication-only external evidence may remain recorded as `blocked_external`; it is not converted into a pass. `publication` mode requires both current Composer install/advisory evidence and a passing real WordPress/WooCommerce promotion result and exits 69 when required external infrastructure is unavailable.

The closed authorities are:

```text
config/quality/release-hardening-policy.json
config/quality/release-hardening-findings.json
scripts/quality/verify-release-hardening.py
scripts/run-stage-h-gate.py
```

Accepted debt must have an exact guard, resolution, and review date. Planned item-9 debt may block publication but must not be expanded before replacement. Adding an unledgered suppression, increasing a static baseline, growing a guarded oversized component, changing dependency locks without policy review, or omitting Stage H authority from the source archive fails the gate.

## Widget build

```bash
python3 scripts/build-widget.py
python3 scripts/build-widget.py --check
```

`assets/js/widget/build-order.txt` is the only module ordering source. Edit modules, rebuild, and commit the generated bundle. Never edit the bundle directly.

## Verification

```bash
./scripts/quality-gate.sh
```

## GitHub Actions runtime

`.github/workflows/runtime-tests.yml` runs two independent required lanes on
pull requests, pushes to `main`, and manual dispatches:

- the complete PHP 7.4, Node 24.18.0, Composer, ESLint, PHPUnit, PHPStan,
  PHPCS, PHPCompatibility, contract, package, and local Playwright quality gate;
- the pinned Docker runtime with WordPress 6.9.4, PHP 8.3, WooCommerce 10.9.4,
  MariaDB 11.8.8, the deterministic fake Gemini service, and Playwright 1.61.1.

The integration lane always uploads its JUnit, JSON, trace, screenshot, video,
and container-log evidence when those files exist. The workflow is included in
the source archive and excluded from the installable WordPress archive.

The gate runs exact Composer and Node installed-tree metadata validation; PHPUnit; level-8 core and level-6 adapter PHPStan; the shrinking PHPCS debt ledger; zero-debt PHP 7.4 compatibility analysis; architecture, dependency, duplicate, and dead-code checks; ESLint; PHP and JavaScript syntax checks; widget tests; memory probes; deterministic widget verification; Draft 2020-12 public-contract generation and fixture validation; the exact two-request fake-provider readiness check and closed failure taxonomy; promotion-evidence rejection tests; deep storefront/cart integration-source checks; the complete mandatory Playwright suite; clean first-release checks; and deterministic package audit.

The real browser lane is mandatory in every quality-gate run. The source-mounted Docker stack remains useful for fast integration feedback, but it cannot promote a package:

```bash
npm run test:browser
YSAI_RUN_INTEGRATION=1 ./scripts/quality-gate.sh
```

Release authority is the clean-tree, deterministic, artifact-first lane. It requires the exact local WooCommerce ZIP; no floating WordPress.org download is accepted:

```bash
./scripts/run-release-gate.sh \
  --woocommerce-version 10.9.4 \
  --woocommerce-zip /secure/packages/woocommerce-10.9.4.zip
```

Use `scripts/run-woocommerce-compatibility-matrix.sh` to execute one independent promotion for every exact version in `promotion_tested`. The plugin ZIP and WooCommerce ZIPs must be outside the evidence directory. Exit 69 means required container infrastructure is unavailable and blocks promotion.

### WooCommerce internals boundary

Application code may depend on `WooSessionInternalsAdapter`, but never on classes below `src/Infrastructure/WooCommerce/Internals/`. Those five collaborators are implementation details of the adapter and are intentionally constructed only there. Keep reflection, protected core fields, session-table/cache identity, core hook priorities, cart-token utilities, clone bookkeeping, and persistent-cart internals inside the matching collaborator. Any new direct WooCommerce write must prove the promotion-tested version and the complete active mutation capability before it acquires the cart fence, suppresses a native writer, changes the working session, publishes operation authority, or invalidates session cache.

When changing the compatibility boundary, run the normal quality gate plus all deterministic compatibility probe modes:

```bash
for mode in success future future_drift drift arity_drift; do
  php tests/woocommerce-compatibility-probe.php "$mode"
done
python3 integration/scripts/verify-source.py
```

`future` proves that an admitted but unpromoted version remains read-only. `future_drift` proves that structural rejection is independent of version policy. Adding a version to `promotion_tested` is incomplete until its exact WooCommerce ZIP and checksum pass the artifact-first matrix.

`YSAI_SKIP_BROWSER_TESTS=1` is rejected. Discovery, execution count, skips, retries, flakes, and runner errors are verified; partial browser execution fails the gate.

## Cart test matrix

At minimum cover:

1. polite add with omitted count defaults to one;
2. named simple and exact variable-product add;
3. pronoun/ellipsis continuation after grounded conversation;
4. absolute quantity set, increment, decrement, partial removal, and whole-line removal;
5. whole-cart clear after an authoritative same-turn cart read;
6. recommendation/information/hypothetical/negated/quoted requests do not mutate;
7. conflicting target, variation, and quantity evidence fails closed;
8. incomplete variation/quantity requests create only the bounded typed clarification;
9. variation pagination cannot combine pages from different live catalog epochs, including same-count stock/attribute changes;
10. Arabic diacritics and punctuation differences in live variation-axis labels resolve through the shared catalog normalizer;
11. deleted, hidden, sold-out, or drifted product/variation revalidation;
12. Woo validation rejection, exceptions, metadata hooks, stock aggregation, persistence mismatch, lease loss, and request interruption;
13. exact replay, concurrent replay, browser timeout, reload, and canonical reconciliation;
14. authenticated persistent-cart enabled/disabled behavior and object-cache topology.

Primary-model tests must distinguish the twenty-tool calls from the isolated one-tool semantic-verification calls.

## Public contract

`config/public-api-contract.json` is the versioned JSON Schema Draft 2020-12 authority for the complete closed REST/widget boundary. Edit it first when changing a public shape, regenerate the browser projection, and run all contract checks:

```bash
python3 scripts/generate-public-contract.py
php scripts/generate-public-contract-fixtures.php
node scripts/validate-public-contract.js
python3 scripts/verify-public-contract.py
```

Do not hand-edit `assets/js/widget/05-public-contract.js` or `src/Application/Contract/GeneratedPublicApiContract.php`; both are generated from the schema. Do not hand-edit the canonical response fixtures; regenerate them through the endpoint projectors. PHP request limits, runtime response validation, JavaScript field projections, typed fixtures, and static boundary checks must continue to agree with that one schema. A controller must return an endpoint-specific validated response through `ApiResponder`; never reintroduce a generic associative-array success method or a second `WP_REST_Response` constructor.

The chat request accepts only exact text and bounded attachments. Do not add browser product/cart identity or execution-authority fields.

## Database changes

Because the project has never shipped, update the one current `SchemaDefinition`, schema version, repositories, tests, and docs together. Explicit repair rebuilds the complete assistant-owned schema. Do not add migration steps or compatibility columns.

Do not add network enumeration, blog switching, or partial network lifecycle paths.

The current schema has nine tables. All must be exact InnoDB definitions with the reviewed indexes and collations.

## Packaging

```bash
python3 scripts/package.py --output release/candidate
```

Run the command twice into separate empty directories and compare hashes. Inspect both ZIPs, verify CRCs, reject symlinks/unsafe members, and prove every production file is byte-identical to its source-package counterpart.

## Release discipline

Promote only the exact production ZIP tested on staging. Record its SHA-256, source ZIP SHA-256, test totals, unavailable lanes, and live-stack observations. Never rebuild after approval without repeating the gate and staging pass.
