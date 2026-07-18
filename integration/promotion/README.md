# Packaged WordPress/WooCommerce promotion gate

This lane tests the **installable plugin ZIP** through normal WordPress installation and update mechanics. It never mounts plugin source into WordPress or WP-CLI.

An exact local WooCommerce ZIP is mandatory. The selected version must be present in `config/woocommerce-compatibility.json` under `promotion_tested`.

```bash
./scripts/run-woocommerce-promotion-gate.sh \
  --plugin-zip release/yassin-ai-assistant-v1.0.0.zip \
  --sha256 '<expected-plugin-sha256>' \
  --woocommerce-version 10.9.4 \
  --woocommerce-zip /secure/packages/woocommerce-10.9.4.zip
```

The command verifies both package checksums before starting containers and records the exact WooCommerce package identity in promotion evidence. It returns `69` when no supported container runtime is available. That state is a release blocker, never a pass.

Before each phase is accepted, the installed plugin must also produce closed runtime-readiness hardening evidence through the real administrator REST route: a temporary provider outage must return `503` while retaining the exact proof timestamps, an authentication contradiction must return `422` and remove proof authority, and a successful recovery must publish a fresh proof after exactly two provider requests. The evidence closer rejects a generic `ok` marker or any missing/changed field.

The gate runs two fresh-volume phases:

1. clean install, activation, storefront/browser scenarios, retention uninstall, and destructive uninstall;
2. internal legacy fixture install followed by a normal ZIP update, current-schema rebuild, stale pre-release continuation invalidation, boot verification, and diagnostic inspection.

For a complete clean-tree candidate:

```bash
./scripts/run-release-gate.sh \
  --woocommerce-version 10.9.4 \
  --woocommerce-zip /secure/packages/woocommerce-10.9.4.zip
```

To certify every exact package in `promotion_tested` independently:

```bash
./scripts/run-woocommerce-compatibility-matrix.sh \
  --plugin-zip release/yassin-ai-assistant-v1.0.0.zip \
  --woocommerce-zip 10.9.4=/secure/packages/woocommerce-10.9.4.zip
```

Promotion is valid only when `promotion-status.json` reports `passed`. Required evidence includes the verified plugin and WooCommerce package manifests, installed-file manifests, checksums, JUnit and Playwright JSON, one trace per scenario, exact runtime versions, database schema, WordPress/WooCommerce diagnostics, lifecycle assertions, and the final summary.
