#!/usr/bin/env bash
# Idempotent deploy for MG Plastic on cPanel (shared hosting).
# Usage: ./deploy/deploy.sh /home/USER/mgplastic_app [/home/USER/public_html]
set -euo pipefail

APP_PATH="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
PUBLIC_PATH="${2:-}"
PHP_BIN="${PHP_BIN:-php}"

cd "$APP_PATH"

echo "==> Preflight"
bash "$APP_PATH/scripts/check-env.sh"

echo "==> git pull"
git pull --ff-only

echo "==> composer install"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> migrate"
"$PHP_BIN" artisan migrate --force

echo "==> caches (no optimize:clear — preserves monitoring / WAHA / OCR counters)"
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan route:clear 2>/dev/null || true
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan event:clear 2>/dev/null || true
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache 2>/dev/null || echo "==> route:cache skipped (closures)"
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache 2>/dev/null || true

if [ -n "$PUBLIC_PATH" ] && [ -x "$APP_PATH/deploy/scripts/post-deploy.sh" ]; then
  bash "$APP_PATH/deploy/scripts/post-deploy.sh" "$APP_PATH" "$PUBLIC_PATH"
fi

echo "==> restart queue workers"
"$PHP_BIN" artisan queue:restart || true
pkill -u "$(whoami)" -f 'queue:work' 2>/dev/null || true

echo "==> whatsapp:health"
set +e
"$PHP_BIN" artisan whatsapp:health
HEALTH_RC=$?
set -e

BASE_URL="${APP_URL:-}"
if [ -z "$BASE_URL" ] && [ -f .env ]; then
  BASE_URL="$(grep -E '^APP_URL=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
fi

smoke() {
  local url="$1"
  local code time
  read -r code time < <(curl -sS -m 10 -o /dev/null -w "%{http_code} %{time_total}" "$url" || echo "000 0")
  echo "  $url -> HTTP $code in ${time}s"
  [ "$code" = "200" ] || [ "$code" = "302" ]
}

PASS=1
if [ -n "$BASE_URL" ]; then
  echo "==> smoke"
  smoke "$BASE_URL/" || PASS=0
  smoke "$BASE_URL/admin/login" || smoke "$BASE_URL/login" || true
else
  echo "==> smoke skipped (APP_URL empty)"
fi

echo "==> SUMMARY"
echo "  whatsapp:health exit=$HEALTH_RC (0=healthy)"
echo "  smoke_pass=$PASS"
if [ "$PASS" -ne 1 ]; then
  exit 1
fi
echo "Deploy OK"
