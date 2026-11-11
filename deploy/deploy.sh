#!/usr/bin/env bash
# Run after every git pull / release on the server.
# Usage (from app root):
#   bash deploy/deploy.sh
#   SKIP_MIGRATE=1 bash deploy/deploy.sh
#   SKIP_COMPOSER=1 bash deploy/deploy.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_DIR="${APP_DIR:-$ROOT}"
APP_USER="${APP_USER:-www-data}"
PHP_BIN="${PHP_BIN:-php}"
SKIP_MIGRATE="${SKIP_MIGRATE:-0}"
SKIP_COMPOSER="${SKIP_COMPOSER:-0}"

cd "$APP_DIR"

if [[ ! -f artisan ]]; then
  echo "Not a Laravel app root: $APP_DIR" >&2
  exit 1
fi

echo "==> Deploy in $APP_DIR ($(date -Is))"

if [[ "$SKIP_COMPOSER" != "1" ]]; then
  echo "==> composer install"
  composer install --no-dev --optimize-autoloader --no-interaction
fi

if [[ "$SKIP_MIGRATE" != "1" ]]; then
  echo "==> migrate --force"
  $PHP_BIN artisan migrate --force
fi

echo "==> Rebuild caches"
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache 2>/dev/null || true

# Ensure workers reload PHP code (Horizon + any queue:work leftovers)
echo "==> Restart queue workers"
$PHP_BIN artisan queue:restart 2>/dev/null || true
$PHP_BIN artisan horizon:terminate 2>/dev/null || true

# Supervisor brings Horizon back up with the new code
if command -v supervisorctl >/dev/null 2>&1; then
  echo "==> Supervisor restart Horizon"
  sudo supervisorctl start cellogent-horizon:* 2>/dev/null \
    || sudo supervisorctl restart cellogent-horizon:* 2>/dev/null \
    || echo "WARN: could not restart supervisor program (is it installed?)"
fi

# php-fpm picks up new code; restart if we can detect a unit
if systemctl list-units --type=service --all 2>/dev/null | grep -q 'php.*-fpm'; then
  UNIT="$(systemctl list-units --type=service --all --no-legend 2>/dev/null | awk '/php.*-fpm\.service/ {print $1; exit}')"
  if [[ -n "${UNIT:-}" ]]; then
    echo "==> Restart $UNIT"
    sudo systemctl reload "$UNIT" 2>/dev/null || sudo systemctl restart "$UNIT" 2>/dev/null || true
  fi
fi

# Keep ownership correct after deploy as a non-www-data user
if id "$APP_USER" >/dev/null 2>&1; then
  sudo chown -R "$APP_USER":"$APP_USER" storage bootstrap/cache 2>/dev/null || true
fi

echo "==> Status"
$PHP_BIN artisan horizon:status 2>/dev/null || true
echo "Deploy finished at $(date -Is)"
