#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  One command: build the app image, bring up MariaDB + Redis, run the v2 test
#  suite inside the container, then tear everything down.
#
#  DEV/TEST ONLY. ⚠ This stack must NEVER be shipped to production.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail
cd "$(dirname "$0")"

echo "▶ building app image…"
docker compose build

echo "▶ running the v2 test suite (starts db + redis)…"
set +e
docker compose run --rm app test
CODE=$?
set -e

echo "▶ tearing down…"
docker compose down -v --remove-orphans >/dev/null 2>&1 || true

echo "▶ exit code: ${CODE}"
exit "${CODE}"
