#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROMOTION_ROOT="$ROOT/integration/promotion"
COMPOSE_FILE="$PROMOTION_ROOT/compose.yaml"
DEFAULT_ARTIFACTS="$PROMOTION_ROOT/artifacts"
CONTRACT="$ROOT/config/woocommerce-compatibility.json"
VERSION_LOCK="$ROOT/integration/version-lock.json"
EX_USAGE=64
EX_UNAVAILABLE=69

usage() {
  cat <<'EOF'
Usage: scripts/run-woocommerce-promotion-gate.sh --plugin-zip PATH --woocommerce-zip PATH [options]

Installs the exact plugin ZIP and exact promotion-tested WooCommerce ZIP into
fresh clean-install and upgrade WordPress stacks. A missing container runtime
returns 69 and is never counted as a pass.

Options:
  --sha256 HASH                 Expected installable-plugin SHA-256.
  --woocommerce-version VER    Exact promotion-tested WooCommerce version.
  --artifacts-dir PATH          Evidence directory.
  --keep-environment            Preserve the final compose stack.
  -h, --help                    Show this help.
EOF
}

plugin_zip=''
expected_sha=''
woocommerce_zip="${YSAI_WOOCOMMERCE_ZIP:-}"
woocommerce_version=''
artifacts_dir="${YSAI_PROMOTION_ARTIFACTS_DIR:-$DEFAULT_ARTIFACTS}"
keep_environment=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --plugin-zip) [[ $# -ge 2 ]] || { usage >&2; exit "$EX_USAGE"; }; plugin_zip="$2"; shift 2 ;;
    --sha256) [[ $# -ge 2 ]] || { usage >&2; exit "$EX_USAGE"; }; expected_sha="${2,,}"; shift 2 ;;
    --woocommerce-version) [[ $# -ge 2 ]] || { usage >&2; exit "$EX_USAGE"; }; woocommerce_version="$2"; shift 2 ;;
    --woocommerce-zip) [[ $# -ge 2 ]] || { usage >&2; exit "$EX_USAGE"; }; woocommerce_zip="$2"; shift 2 ;;
    --artifacts-dir) [[ $# -ge 2 ]] || { usage >&2; exit "$EX_USAGE"; }; artifacts_dir="$2"; shift 2 ;;
    --keep-environment) keep_environment=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unknown argument: %s\n' "$1" >&2; usage >&2; exit "$EX_USAGE" ;;
  esac
done

[[ -n "$plugin_zip" && -n "$woocommerce_zip" ]] || { usage >&2; exit "$EX_USAGE"; }
command -v python3 >/dev/null 2>&1 || { printf 'python3 is required.\n' >&2; exit "$EX_UNAVAILABLE"; }
command -v sha256sum >/dev/null 2>&1 || { printf 'sha256sum is required.\n' >&2; exit "$EX_UNAVAILABLE"; }

mapfile -t contract_values < <(python3 - "$CONTRACT" "$VERSION_LOCK" "$woocommerce_version" <<'PY'
import json, re, sys
contract_path, lock_path, requested = sys.argv[1:]
contract = json.load(open(contract_path, encoding='utf-8'))
lock = json.load(open(lock_path, encoding='utf-8'))
expected = {'schema_version','minimum','maximum_exclusive','tested_up_to','promotion_tested','wordpress_minimum','runtime_contract'}
if set(contract) != expected or contract.get('schema_version') != 1:
    raise SystemExit('WooCommerce compatibility contract is not closed.')
promoted = contract.get('promotion_tested')
if not isinstance(promoted, list) or not promoted or not all(isinstance(v, str) for v in promoted):
    raise SystemExit('WooCommerce promotion-tested set is invalid.')
selected = requested or (promoted[0] if len(promoted) == 1 else '')
if selected not in promoted:
    raise SystemExit('Select an exact WooCommerce version from promotion_tested: ' + ', '.join(promoted))
if str(lock.get('wordpress','')) != str(contract.get('wordpress_minimum','')):
    raise SystemExit('Promotion WordPress pin differs from the compatibility contract.')
if not re.fullmatch(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', selected):
    raise SystemExit('Selected WooCommerce version is malformed.')
print(selected)
print(contract['runtime_contract'])
PY
)
[[ ${#contract_values[@]} -eq 2 ]] || exit 1
woocommerce_version="${contract_values[0]}"
runtime_contract="${contract_values[1]}"

absolute() { python3 -c 'import os,sys; print(os.path.abspath(sys.argv[1]))' "$1"; }
plugin_zip="$(absolute "$plugin_zip")"
woocommerce_zip="$(absolute "$woocommerce_zip")"
artifacts_dir="$(absolute "$artifacts_dir")"
[[ -f "$plugin_zip" ]] || { printf 'Plugin ZIP is missing: %s\n' "$plugin_zip" >&2; exit "$EX_USAGE"; }
[[ -f "$woocommerce_zip" ]] || { printf 'WooCommerce ZIP is missing: %s\n' "$woocommerce_zip" >&2; exit "$EX_USAGE"; }
for input in "$plugin_zip" "$woocommerce_zip"; do
  case "$input" in "$artifacts_dir"|"$artifacts_dir"/*)
    printf 'Package inputs must be outside the evidence directory: %s\n' "$input" >&2; exit "$EX_USAGE" ;;
  esac
done

package_dir="$PROMOTION_ROOT/runtime/package"
rm -rf "$artifacts_dir" "$PROMOTION_ROOT/runtime"
mkdir -p "$artifacts_dir" "$package_dir"
chmod 0777 "$artifacts_dir"

python3 "$PROMOTION_ROOT/scripts/verify-package.py" \
  --plugin "$plugin_zip" \
  --expected-sha256 "$expected_sha" \
  --woocommerce-version "$woocommerce_version" \
  --woocommerce "$woocommerce_zip" \
  --output "$artifacts_dir/package-manifest.json" >/dev/null
plugin_version="$(python3 -c 'import json,sys; p=json.load(open(sys.argv[1],encoding="utf-8")); print(p["plugin"]["version"])' "$artifacts_dir/package-manifest.json")"
plugin_sha="$(python3 -c 'import json,sys; p=json.load(open(sys.argv[1],encoding="utf-8")); print(p["plugin"]["sha256"])' "$artifacts_dir/package-manifest.json")"
woo_sha="$(python3 -c 'import json,sys; p=json.load(open(sys.argv[1],encoding="utf-8")); print(p["woocommerce"]["sha256"])' "$artifacts_dir/package-manifest.json")"
[[ "$plugin_version" =~ ^[A-Za-z0-9._-]+$ ]] || { printf 'Unsafe plugin version in package manifest.\n' >&2; exit 1; }
cp "$plugin_zip" "$package_dir/yassin-ai-assistant.zip"
cp "$woocommerce_zip" "$package_dir/woocommerce.zip"
python3 "$PROMOTION_ROOT/scripts/package-legacy.py" \
  --source "$PROMOTION_ROOT/fixtures/legacy-plugin" \
  --output "$package_dir/yassin-ai-assistant-legacy.zip" > "$artifacts_dir/legacy-package.sha256"
printf '%s  %s\n' "$plugin_sha" 'yassin-ai-assistant.zip' > "$artifacts_dir/installed-package.sha256"

COMPOSE=()
runtime_name=''
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  COMPOSE=(docker compose); runtime_name='docker compose'
elif command -v podman >/dev/null 2>&1 && podman compose version >/dev/null 2>&1; then
  COMPOSE=(podman compose); runtime_name='podman compose'
elif command -v podman-compose >/dev/null 2>&1; then
  COMPOSE=(podman-compose); runtime_name='podman-compose'
elif command -v nerdctl >/dev/null 2>&1 && nerdctl compose version >/dev/null 2>&1; then
  COMPOSE=(nerdctl compose); runtime_name='nerdctl compose'
fi

head_commit="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || printf 'unavailable')"
branch="$(git -C "$ROOT" branch --show-current 2>/dev/null || printf 'unavailable')"
python3 - "$artifacts_dir/environment-host.json" "$runtime_name" "$head_commit" "$branch" \
  "$plugin_version" "$plugin_sha" "$woocommerce_version" "$woo_sha" "$runtime_contract" <<'PY'
import json, platform, sys, time
(path, runtime, commit, branch, version, plugin_sha, woo_version, woo_sha, runtime_contract) = sys.argv[1:]
payload = {
    'captured_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
    'container_runtime': runtime,
    'git_commit': commit,
    'git_branch': branch,
    'host_platform': platform.platform(),
    'host_architecture': platform.machine(),
    'plugin_version': version,
    'plugin_package_sha256': plugin_sha,
    'woocommerce_version': woo_version,
    'woocommerce_package_sha256': woo_sha,
    'woocommerce_runtime_contract': runtime_contract,
}
with open(path, 'w', encoding='utf-8') as stream:
    json.dump(payload, stream, indent=2, sort_keys=True); stream.write('\n')
PY

if [[ ${#COMPOSE[@]} -eq 0 ]]; then
  cat > "$artifacts_dir/promotion-summary.md" <<MD
# WooCommerce promotion summary

**Status:** BLOCKED

No supported container runtime is installed. WooCommerce ${woocommerce_version} package ${woo_sha} and plugin package ${plugin_sha} were verified, but the real WordPress/WooCommerce lane was not executed and must not be counted as a pass.
MD
  python3 - "$artifacts_dir/promotion-status.json" "$woocommerce_version" "$woo_sha" <<'PY'
import json, sys
path, version, checksum = sys.argv[1:]
with open(path, 'w', encoding='utf-8') as stream:
    json.dump({'status':'blocked','reason':'container_runtime_unavailable','exit_code':69,
               'woocommerce_package_version':version,'woocommerce_package_sha256':checksum},
              stream, indent=2, sort_keys=True); stream.write('\n')
PY
  printf 'No supported container runtime is available; promotion is blocked.\n' >&2
  exit "$EX_UNAVAILABLE"
fi

export YSAI_PROMOTION_PROJECT_NAME="ysai-promotion-$(date -u +%Y%m%d%H%M%S)-$$"
export YSAI_PROMOTION_PACKAGE_DIR="$package_dir"
export YSAI_PROMOTION_ARTIFACTS_DIR="$artifacts_dir"
export YSAI_PROMOTION_PLUGIN_VERSION="$plugin_version"
export YSAI_PROMOTION_PLUGIN_SHA256="$plugin_sha"
export YSAI_PROMOTION_WOOCOMMERCE_VERSION="$woocommerce_version"
export YSAI_TEST_CONTROL_TOKEN="${YSAI_TEST_CONTROL_TOKEN:-ysai-promotion-control}"
export YSAI_TEST_API_KEY="${YSAI_TEST_API_KEY:-promotion-key}"

compose() { "${COMPOSE[@]}" -f "$COMPOSE_FILE" "$@"; }
clean_stack() { compose down -v --remove-orphans >/dev/null 2>&1 || true; }
collect_compose_logs() {
  local phase="$1"
  compose logs --no-color > "$artifacts_dir/compose-${phase}.log" 2>&1 || true
  compose ps -a > "$artifacts_dir/compose-${phase}-ps.txt" 2>&1 || true
}
cleanup() {
  local status=$?
  trap - EXIT INT TERM
  collect_compose_logs final
  if [[ "$keep_environment" != 1 ]]; then clean_stack; fi
  exit "$status"
}
trap cleanup EXIT INT TERM

"${COMPOSE[@]}" version > "$artifacts_dir/container-runtime.txt" 2>&1 || true
sha256sum "$COMPOSE_FILE" > "$artifacts_dir/compose-definition.sha256"
build_status=0; main_status=0; runner_status=0; upgrade_status=0
set +e
compose config --images > "$artifacts_dir/compose-images.txt" 2> "$artifacts_dir/compose-config-error.log"
compose_config_status=$?
set -e
if [[ "$compose_config_status" -ne 0 ]]; then
  set +e
  compose config 2>> "$artifacts_dir/compose-config-error.log" | awk '$1 == "image:" { print $2 }' | sort -u > "$artifacts_dir/compose-images.txt"
  compose_config_status=${PIPESTATUS[0]}
  set -e
fi
if [[ "$compose_config_status" -ne 0 || ! -s "$artifacts_dir/compose-images.txt" ]]; then
  build_status=${compose_config_status:-1}; [[ "$build_status" -ne 0 ]] || build_status=1
fi

record_main() { if "$@"; then :; else local rc=$?; [[ "$main_status" -ne 0 ]] || main_status=$rc; fi; }
record_runner() { if "$@"; then :; else local rc=$?; [[ "$runner_status" -ne 0 ]] || runner_status=$rc; fi; }
record_upgrade() { if "$@"; then :; else local rc=$?; [[ "$upgrade_status" -ne 0 ]] || upgrade_status=$rc; fi; }

clean_stack
if [[ "$build_status" -eq 0 ]]; then
  if compose build fake-gemini runner; then
    compose images > "$artifacts_dir/compose-images-runtime.txt" 2>&1 || true
  else
    build_status=$?
  fi
fi

if [[ "$build_status" -ne 0 ]]; then
  main_status=$build_status
elif compose up -d db fake-gemini wordpress; then
  if compose run --rm wpcli /promotion/scripts/bootstrap-clean.sh; then
    record_runner compose run --rm runner
    record_main compose run --rm wpcli /promotion/scripts/collect-main.sh
    record_main compose run --rm wpcli /promotion/scripts/uninstall-check.sh
  else
    main_status=$?
  fi
else
  main_status=$?
fi
collect_compose_logs main
clean_stack

if [[ "$build_status" -ne 0 ]]; then
  upgrade_status=$build_status
elif compose up -d db fake-gemini wordpress; then
  record_upgrade compose run --rm wpcli /promotion/scripts/bootstrap-upgrade.sh
else
  upgrade_status=$?
fi
collect_compose_logs upgrade
if [[ "$keep_environment" != 1 ]]; then clean_stack; fi

set +e
python3 "$PROMOTION_ROOT/scripts/summarize.py" --artifacts "$artifacts_dir" --source-root "$ROOT" \
  --build-status "$build_status" --runner-status "$runner_status" \
  --main-status "$main_status" --upgrade-status "$upgrade_status"
summary_status=$?
set -e
if [[ "$summary_status" -ne 0 ]]; then
  printf 'WooCommerce promotion gate failed. See %s/promotion-summary.md\n' "$artifacts_dir" >&2
  exit "$summary_status"
fi
printf 'WooCommerce promotion gate passed. Evidence: %s\n' "$artifacts_dir"
