#!/usr/bin/env bash
# Post-deploy for MG Plastic on cPanel (split: app + public_html).
set -euo pipefail

APP_PATH="${1:?APP_PATH required}"
PUBLIC_PATH="${2:?PUBLIC_PATH required}"

cd "$APP_PATH"

echo "==> Ensuring writable directories"
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache storage/app/public
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

echo "==> Linking public storage"
PUBLIC_STORAGE="${PUBLIC_PATH}/storage"
rm -rf "$PUBLIC_STORAGE"
ln -sfn "${APP_PATH}/storage/app/public" "$PUBLIC_STORAGE"

if [ ! -f vendor/autoload.php ]; then
  echo "==> Installing Composer dependencies on server (fallback)"
  if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader --no-interaction
  else
    echo "ERROR: vendor/ missing and composer not available on server"
    exit 1
  fi
fi

echo "==> Running migrations"
php artisan migrate --force

echo "==> Rebuilding caches"
php artisan optimize:clear
php artisan config:cache
php artisan view:cache

# route:cache skipped — closure routes in routes/api.php

if php artisan list 2>/dev/null | grep -q 'queue:restart'; then
  php artisan queue:restart 2>/dev/null || true
fi

echo "==> Post-deploy complete"
