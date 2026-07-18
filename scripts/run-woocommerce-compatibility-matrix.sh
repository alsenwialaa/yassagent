#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTRACT="$ROOT/config/woocommerce-compatibility.json"
PLUGIN_ZIP=''
PLUGIN_SHA=''
ARTIFACTS="$ROOT/release/woocommerce-compatibility-matrix"
KEEP=0
EX_USAGE=64
EX_UNAVAILABLE=69
declare -A WOO_ZIPS=()

usage() {
  cat <<'EOF'
Usage: scripts/run-woocommerce-compatibility-matrix.sh --plugin-zip PATH \
       --woocommerce-zip VERSION=PATH [--woocommerce-zip VERSION=PATH ...] [options]

Every exact version in config/woocommerce-compatibility.json promotion_tested
must have one local package mapping. Extra mappings are rejected.

Options:
  --sha256 HASH             Expected plugin SHA-256.
  --artifacts-dir PATH      Matrix evidence directory.
  --keep-environment        Preserve the final per-version stack.
  -h, --help                Show this help.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --plugin-zip) [[ $# -ge 2 ]] || { usage >&2; exit "$EX_USAGE"; }; PLUGIN_ZIP="$2"; shift 2 ;;
    --sha256) [[ $# -ge 2 ]] || { usage >&2; exit "$EX_USAGE"; }; PLUGIN_SHA="${2,,}"; shift 2 ;;
    --woocommerce-zip)
      [[ $# -ge 2 && "$2" == *=* ]] || { usage >&2; exit "$EX_USAGE"; }
      version="${2%%=*}"; path="${2#*=}"
      [[ -z "${WOO_ZIPS[$version]+x}" ]] || { printf 'Duplicate WooCommerce mapping: %s\n' "$version" >&2; exit "$EX_USAGE"; }
      WOO_ZIPS[$version]="$path"; shift 2 ;;
    --artifacts-dir) [[ $# -ge 2 ]] || { usage >&2; exit "$EX_USAGE"; }; ARTIFACTS="$2"; shift 2 ;;
    --keep-environment) KEEP=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unknown argument: %s\n' "$1" >&2; usage >&2; exit "$EX_USAGE" ;;
  esac
done
[[ -n "$PLUGIN_ZIP" && ${#WOO_ZIPS[@]} -gt 0 ]] || { usage >&2; exit "$EX_USAGE"; }
command -v python3 >/dev/null 2>&1 || exit "$EX_UNAVAILABLE"
command -v sha256sum >/dev/null 2>&1 || exit "$EX_UNAVAILABLE"
mapfile -t PROMOTED < <(python3 - "$CONTRACT" <<'PY'
import json, sys
contract=json.load(open(sys.argv[1],encoding='utf-8'))
for version in contract.get('promotion_tested',[]): print(version)
PY
)
[[ ${#PROMOTED[@]} -gt 0 ]] || { printf 'Compatibility contract has no promotion-tested versions.\n' >&2; exit 1; }
for version in "${PROMOTED[@]}"; do
  [[ -n "${WOO_ZIPS[$version]+x}" ]] || { printf 'Missing WooCommerce package mapping for %s\n' "$version" >&2; exit "$EX_USAGE"; }
done
for version in "${!WOO_ZIPS[@]}"; do
  found=0; for promoted in "${PROMOTED[@]}"; do [[ "$version" == "$promoted" ]] && found=1; done
  [[ "$found" == 1 ]] || { printf 'Unexpected WooCommerce package mapping: %s\n' "$version" >&2; exit "$EX_USAGE"; }
done
absolute() { python3 -c 'import os,sys; print(os.path.abspath(sys.argv[1]))' "$1"; }
PLUGIN_ZIP="$(absolute "$PLUGIN_ZIP")"; ARTIFACTS="$(absolute "$ARTIFACTS")"
[[ -f "$PLUGIN_ZIP" ]] || { printf 'Plugin ZIP is missing.\n' >&2; exit "$EX_USAGE"; }
case "$PLUGIN_ZIP" in "$ARTIFACTS"|"$ARTIFACTS"/*) printf 'Plugin ZIP must be outside matrix artifacts.\n' >&2; exit "$EX_USAGE";; esac
for version in "${PROMOTED[@]}"; do
  WOO_ZIPS[$version]="$(absolute "${WOO_ZIPS[$version]}")"
  [[ -f "${WOO_ZIPS[$version]}" ]] || { printf 'WooCommerce ZIP is missing for %s.\n' "$version" >&2; exit "$EX_USAGE"; }
  case "${WOO_ZIPS[$version]}" in "$ARTIFACTS"|"$ARTIFACTS"/*) printf 'WooCommerce ZIP must be outside matrix artifacts.\n' >&2; exit "$EX_USAGE";; esac
done
rm -rf "$ARTIFACTS"; mkdir -p "$ARTIFACTS"
RESULTS="$ARTIFACTS/results.ndjson"; : > "$RESULTS"
overall=0; blocked=0
for version in "${PROMOTED[@]}"; do
  version_dir="$ARTIFACTS/$version"
  args=(--plugin-zip "$PLUGIN_ZIP" --woocommerce-version "$version" --woocommerce-zip "${WOO_ZIPS[$version]}" --artifacts-dir "$version_dir")
  [[ -z "$PLUGIN_SHA" ]] || args+=(--sha256 "$PLUGIN_SHA")
  [[ "$KEEP" != 1 ]] || args+=(--keep-environment)
  set +e
  "$ROOT/scripts/run-woocommerce-promotion-gate.sh" "${args[@]}" > "$ARTIFACTS/$version-command.log" 2>&1
  rc=$?
  set -e
  status='failed'; [[ "$rc" -eq 0 ]] && status='passed'; [[ "$rc" -eq "$EX_UNAVAILABLE" ]] && status='blocked'
  [[ "$status" != failed ]] || overall=1
  [[ "$status" != blocked ]] || blocked=1
  woo_sha="$(sha256sum "${WOO_ZIPS[$version]}" | awk '{print $1}')"
  python3 - "$RESULTS" "$version" "$woo_sha" "$status" "$rc" "$version_dir/promotion-status.json" <<'PY'
import json, os, sys
path, version, checksum, status, rc, evidence = sys.argv[1:]
row={'version':version,'woocommerce_package_sha256':checksum,'status':status,'exit_code':int(rc),
     'evidence':evidence if os.path.isfile(evidence) else ''}
with open(path,'a',encoding='utf-8') as stream: stream.write(json.dumps(row,sort_keys=True)+'\n')
PY
done
final_status='passed'; exit_code=0
if [[ "$overall" -ne 0 ]]; then final_status='failed'; exit_code=1
elif [[ "$blocked" -ne 0 ]]; then final_status='blocked'; exit_code="$EX_UNAVAILABLE"
fi
python3 - "$RESULTS" "$ARTIFACTS/matrix-status.json" "$ARTIFACTS/matrix-summary.md" "$final_status" "$exit_code" <<'PY'
import json, sys
rows=[json.loads(line) for line in open(sys.argv[1],encoding='utf-8') if line.strip()]
payload={'status':sys.argv[4],'exit_code':int(sys.argv[5]),'versions':rows}
with open(sys.argv[2],'w',encoding='utf-8') as stream: json.dump(payload,stream,indent=2,sort_keys=True); stream.write('\n')
lines=['# WooCommerce compatibility matrix','',f"**Status:** {payload['status'].upper()}",'']
for row in rows: lines.append(f"- {row['version']}: {row['status'].upper()} ({row['woocommerce_package_sha256']})")
open(sys.argv[3],'w',encoding='utf-8').write('\n'.join(lines)+'\n')
PY
exit "$exit_code"
