#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail() { echo "$1" >&2; exit 1; }

[[ "${YSAI_SKIP_BROWSER_TESTS:-0}" != "1" ]] \
  || fail 'Browser tests are mandatory and cannot be skipped in the release gate.'
command -v node >/dev/null 2>&1 || fail 'Node.js is required for browser tests.'
command -v python3 >/dev/null 2>&1 || fail 'Python 3 is required for browser batch preparation.'
[[ -x node_modules/.bin/playwright ]] \
  || fail 'Playwright is unavailable. Run npm ci before the release gate.'

if [[ -z "${CHROMIUM_PATH:-}" ]]; then
  for candidate in /usr/bin/chromium /usr/bin/chromium-browser /usr/bin/google-chrome; do
    if [[ -x "$candidate" ]]; then
      export CHROMIUM_PATH="$candidate"
      break
    fi
  done
fi
[[ -n "${CHROMIUM_PATH:-}" && -x "$CHROMIUM_PATH" ]] \
  || fail 'A Chromium executable is required. Set CHROMIUM_PATH to an executable browser.'

batch_size="${YSAI_BROWSER_BATCH_SIZE:-5}"
[[ "$batch_size" =~ ^[1-9][0-9]*$ ]] || fail 'YSAI_BROWSER_BATCH_SIZE must be a positive integer.'
(( batch_size <= 10 )) || fail 'Browser batches are capped at 10 cases to avoid hiding resource failures.'
batch_timeout="${YSAI_BROWSER_BATCH_TIMEOUT_SECONDS:-180}"
[[ "$batch_timeout" =~ ^[1-9][0-9]*$ ]] || fail 'YSAI_BROWSER_BATCH_TIMEOUT_SECONDS must be a positive integer.'

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
list_report="$work/discovery.json"
cases_file="$work/cases.json"

CHROMIUM_PATH="$CHROMIUM_PATH" node_modules/.bin/playwright test \
  -c tests/browser/playwright.config.js --list --reporter=json > "$list_report"
node scripts/verify-browser-suite.js discover "$list_report" "$cases_file" tests/browser

mapfile -t selectors < <(node -e '
const data=require(process.argv[1]);
for (const item of data.cases) process.stdout.write(item.selector + "\n");
' "$cases_file")
total="${#selectors[@]}"
[[ "$total" -gt 0 ]] || fail 'Browser discovery produced no runnable selectors.'

executed=0
batch_number=0
for ((start=0; start<total; start+=batch_size)); do
  batch_number=$((batch_number + 1))
  remaining=$((total - start))
  count="$batch_size"
  if (( remaining < count )); then count="$remaining"; fi
  batch_report="$work/report-${batch_number}.json"
  batch_expected="$work/expected-${batch_number}.json"
  batch_proof="$work/proof-${batch_number}.json"
  node - "$cases_file" "$batch_expected" "$start" "$count" <<'NODE'
const fs = require('fs');
const source = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const start = Number(process.argv[4]);
const count = Number(process.argv[5]);
fs.writeFileSync(process.argv[3], JSON.stringify({ cases: source.cases.slice(start, start + count) }, null, 2) + '\n');
NODE
  batch=("${selectors[@]:start:count}")
  echo
  echo "Browser batch ${batch_number}: cases $((start + 1))-$((start + count)) of ${total}"
  runner=(node_modules/.bin/playwright test -c tests/browser/playwright.config.js "${batch[@]}" --workers=1 --reporter=line,json,./scripts/playwright-proof-reporter.js)
  CHROMIUM_PATH="$CHROMIUM_PATH" PLAYWRIGHT_JSON_OUTPUT_NAME="$batch_report" YSAI_PLAYWRIGHT_PROOF_OUTPUT="$batch_proof" \
    python3 scripts/run-playwright-batch.py \
      --report "$batch_report" \
      --expected "$batch_expected" \
      --proof "$batch_proof" \
      --timeout-seconds "$batch_timeout" \
      -- "${runner[@]}"
  [[ -s "$batch_report" ]] || fail "Browser batch ${batch_number} did not produce its JSON report."
  node scripts/verify-browser-suite.js report "$batch_report" "$batch_expected"
  executed=$((executed + count))
done

[[ "$executed" -eq "$total" ]] \
  || fail "Browser gate executed ${executed} of ${total} discovered cases."
echo
echo "Browser gate passed: ${executed}/${total} mandatory cases executed exactly once."
