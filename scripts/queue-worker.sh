#!/usr/bin/env bash
# Cron-safe single-instance queue worker for cPanel shared hosting.
# Crontab (every minute):
#   * * * * * /bin/bash /home/USER/mgplastic_app/scripts/queue-worker.sh >> /home/USER/mgplastic_app/storage/logs/queue-worker.log 2>&1
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"

PHP_BIN="${PHP_BIN:-php}"
LOCK_FILE="${APP_DIR}/storage/framework/queue-worker.lock"
mkdir -p "$(dirname "$LOCK_FILE")"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

# Restart signal from `php artisan queue:restart` is honored automatically.
exec "$PHP_BIN" artisan queue:work \
  --queue=whatsapp,default \
  --sleep=3 \
  --tries=3 \
  --max-time=3600 \
  --memory=192 \
  --timeout=90
