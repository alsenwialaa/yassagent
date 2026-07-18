#!/usr/bin/env sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

rm -rf "$ROOT/artifacts"
mkdir -p "$ROOT/artifacts"
chmod 0777 "$ROOT/artifacts"

cleanup() {
  status=$?
  trap - EXIT INT TERM
  if [ "$status" -ne 0 ]; then
    docker compose logs --no-color db fake-gemini wordpress > "$ROOT/artifacts/compose.log" 2>&1 || true
  fi
  if [ "${YSAI_KEEP_INTEGRATION_ENV:-0}" != "1" ]; then
    docker compose down -v --remove-orphans >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap cleanup EXIT INT TERM

docker compose down -v --remove-orphans >/dev/null 2>&1 || true
docker compose up -d --build db fake-gemini wordpress
docker compose run --rm --entrypoint sh wpcli /workspace/plugin/integration/scripts/bootstrap.sh
docker compose run --rm runner
