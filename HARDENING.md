# Stage H release-hardening record

This document is source-package authority for the unpublished first release. It separates three decisions that must not be collapsed into one status:

1. whether the cumulative items 1–8 code is safe enough to begin item 9;
2. whether the production and source archives are reproducible and internally valid;
3. whether the exact installable archive has the external evidence required for publication.

## Baseline authority

Stage H descends from the delivered Stage G source authority:

```text
Commit: d64084b07f3141f560b4e0eaa402df4e1c25d565
Tree:   1124c092fa343d8483b362ec9f8808d7d9b002a8
Source archive SHA-256:
e57a52169d519f8e42333a7a2b5002bb6bf5715ecb2a4bca143deabbce33911d
```

`config/quality/release-hardening-policy.json` verifies the commit, its exact tree, ancestry, dependency locks, static-debt inventory, maintainability budgets, and every local suppression. `config/quality/release-hardening-findings.json` is the closed findings ledger.

## Resolved findings

Stage H resolved the local high-severity findings identified before item 9:

- **H-001 — release authority:** reconstructed one exact cumulative Stage G Git authority instead of continuing from a divergent worktree.
- **H-002 — release gate:** added bounded committed-tree and extracted-source execution, safe marked output replacement, exact Composer and Node replay checks, double deterministic builds, archive identity checks, packaged syntax smoke tests, strict evidence parsing, and machine-readable results.
- **H-003 — input security:** unslashed and sanitized WordPress request values at the trust boundary and retained cryptographic validation for signed WooCommerce identities.
- **H-004 — duplicated low-level encoding:** centralized base64url handling and safe serialized-array decoding and removed duplicated database error extraction.
- **H-005 — actionable static-analysis findings:** removed reachable-control-flow, framework-boundary, WordPress input, and database-error defects from the accepted PHPStan inventory.
- **H-006 — duplicated commerce policy:** centralized cart quantity bounds/equality and product price-range projection in domain authorities.

The item-9 gate has no unresolved critical or high local finding outside the planned clarification rewrite.

## Reviewed debt

Remaining debt is explicit, bounded, and non-growing:

```text
PHPStan core, level 8:                  111 missing-type findings
PHPStan WordPress/Woo adapters, level 6: 140 missing-type findings
PHPCS/WPCS:                            1,175 exact findings
PHP 7.4 compatibility:                     0 findings
ESLint:                                    0 findings
Local suppressions:                       21 exact markers
Oversized non-generated components:       10 exact files
```

The PHPCS inventory is mostly formatting, direct-database, prepared-SQL, and output-analysis debt. It was reduced from 4,228 without broad exclusions. The exact path/sniff/type/count ledger remains a maintenance task; it is not represented as zero debt.

`PendingCartIntentFactory` is the sole `planned_item9` maintainability target. Its no-growth budget exists only to keep the current behavior stable until item 9 replaces it with explicit clarification components.

## Gate modes

### Source mode

Validates the checked-in policy, findings ledger, exact baselines, suppressions, dependency locks, hygiene, Stage G ancestry, and maintainability inventory.

```bash
python3 scripts/quality/verify-release-hardening.py --mode source
```

### Item-9 mode

Runs the cumulative quality gate, builds twice, compares archive bytes, audits and smoke-tests the installable ZIP, extracts the exact source ZIP, installs or verifies locked development tools, reruns the full gate from the extracted source, and records publication-only blockers separately.

```bash
python3 scripts/run-stage-h-gate.py \
  --mode item9 \
  --output release/stage-h \
  --composer /absolute/path/to/composer
```

Exact offline Composer and Node trees may be supplied only when their clean installs fail for a classified network reason. Both trees must match their lock metadata exactly. Offline replay proves lock-matching installed metadata only; it does **not** satisfy the clean Composer-install or current-advisory requirements for publication.

### Publication mode

Publication mode additionally requires:

- a fresh Composer install from the locked source package;
- normalized current Composer audit evidence with zero advisories and zero abandoned packages;
- a passing artifact-first WordPress 6.9.4 / PHP 8.3 / WooCommerce 10.9.4 promotion record for the exact installable ZIP, including clean install, activation, clarification, mutation, replay, recovery, upgrade, uninstall, complete browser traces, installed-byte identity, and clean WordPress/WooCommerce diagnostics; PHP 7.4 remains the separately enforced compatibility floor.

```bash
scripts/run-release-gate.sh \
  --output release/publication-candidate \
  --composer /absolute/path/to/composer \
  --woocommerce-version 10.9.4 \
  --woocommerce-zip /absolute/path/to/woocommerce-10.9.4.zip
```

Missing container or package-network infrastructure remains a blocker. It is never converted into a pass.

## Decision before item 9

Item 9 may begin only when the exact committed Stage H tree passes item-9 mode and the result contains no local failure. Publication remains prohibited until item 9 is complete and both external findings **H-201** and **H-202** have strict passing evidence.
