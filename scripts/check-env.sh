#!/usr/bin/env bash
# Fail loudly when web/CLI PHP or platform requirements are wrong.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"

PHP_BIN="${PHP_BIN:-php}"

echo "==> PHP binary: $PHP_BIN"
"$PHP_BIN" -v

echo "==> Composer platform requirements"
if command -v composer >/dev/null 2>&1; then
  composer check-platform-reqs --no-dev || {
    echo "ERROR: platform requirements failed."
    echo "On cPanel: MultiPHP Manager → select ea-php83 (or matching composer.lock) for the domain AND CLI."
    exit 1
  }
else
  echo "WARN: composer not in PATH — skipped check-platform-reqs"
fi

MAJOR="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION;')"
MINOR="$("$PHP_BIN" -r 'echo PHP_MINOR_VERSION;')"
if [ "$MAJOR" -lt 8 ] || { [ "$MAJOR" -eq 8 ] && [ "$MINOR" -lt 2 ]; }; then
  echo "ERROR: PHP ${MAJOR}.${MINOR} is too old (need >= 8.2)."
  exit 1
fi

echo "==> check-env OK"
