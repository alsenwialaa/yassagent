#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
args=(--mode publication)

has_composer=0
has_woocommerce_zip=0
for value in "$@"; do
  [[ "$value" != "--composer" ]] || has_composer=1
  [[ "$value" != "--woocommerce-zip" ]] || has_woocommerce_zip=1
done
if [[ "$has_composer" == 0 && -n "${YSAI_COMPOSER:-}" ]]; then
  args+=(--composer "$YSAI_COMPOSER")
fi
if [[ "$has_woocommerce_zip" == 0 && -n "${YSAI_WOOCOMMERCE_ZIP:-}" ]]; then
  args+=(--woocommerce-zip "$YSAI_WOOCOMMERCE_ZIP")
fi

exec python3 "$ROOT/scripts/run-stage-h-gate.py" "${args[@]}" "$@"
