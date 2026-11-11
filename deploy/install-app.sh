#!/usr/bin/env bash
# First-time app install on a server (run from the repo root as a user that can sudo).
# Usage:
#   cd /var/www/cellogent/cellogent-be && bash deploy/install-app.sh
#   APP_DIR=/var/www/cellogent/cellogent-be APP_USER=www-data bash deploy/install-app.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_DIR="${APP_DIR:-$ROOT}"
APP_USER="${APP_USER:-www-data}"
PHP_BIN="${PHP_BIN:-php}"
# Path baked into deploy/*.conf templates (rewritten to $APP_DIR below).
TEMPLATE_APP_DIR="/var/www/cellogent/cellogent-be"

cd "$APP_DIR"

if [[ ! -f artisan ]]; then
  echo "Not a Laravel app root: $APP_DIR" >&2
  exit 1
fi

if [[ ! -f .env ]]; then
  echo "Missing .env — copy .env.example and fill production values first." >&2
  exit 1
fi

echo "==> Composer install (no-dev)"
composer install --no-dev --optimize-autoloader --no-interaction

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "==> Generating APP_KEY"
  $PHP_BIN artisan key:generate --force
fi

echo "==> Migrating database"
$PHP_BIN artisan migrate --force

echo "==> Passport keys"
if [[ ! -f storage/oauth-private.key ]]; then
  $PHP_BIN artisan passport:keys --force
fi
# Personal access client (ignore if already exists)
$PHP_BIN artisan passport:client --personal --name="Cellogent Personal Access Client" --no-interaction 2>/dev/null || true

echo "==> Storage link"
$PHP_BIN artisan storage:link 2>/dev/null || true

echo "==> Permissions"
sudo mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache
sudo chown -R "$APP_USER":"$APP_USER" storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache

echo "==> Caches"
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache 2>/dev/null || true

echo "==> Supervisor (Horizon)"
TMP_SUP="$(mktemp)"
sed "s|${TEMPLATE_APP_DIR}|${APP_DIR}|g" deploy/supervisor-horizon.conf > "$TMP_SUP"
sudo cp "$TMP_SUP" /etc/supervisor/conf.d/cellogent-horizon.conf
rm -f "$TMP_SUP"
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start cellogent-horizon:* 2>/dev/null || sudo supervisorctl restart cellogent-horizon:*

echo "==> Cron (scheduler)"
TMP_CRON="$(mktemp)"
sed "s|${TEMPLATE_APP_DIR}|${APP_DIR}|g" deploy/crontab-scheduler.conf > "$TMP_CRON"
sudo cp "$TMP_CRON" /etc/cron.d/cellogent-scheduler
sudo chmod 644 /etc/cron.d/cellogent-scheduler
rm -f "$TMP_CRON"

echo "==> Optional: git post-merge hook → deploy.sh on every pull"
if [[ -d .git ]]; then
  cp deploy/hooks/post-merge .git/hooks/post-merge
  chmod +x .git/hooks/post-merge
  echo "    Installed .git/hooks/post-merge"
fi

echo ""
echo "Install complete."
echo "  Horizon:  sudo supervisorctl status cellogent-horizon:*"
echo "  Schedule: sudo -u $APP_USER $PHP_BIN artisan schedule:list"
echo "  UI:       \${APP_URL}/horizon?token=\${HORIZON_TOKEN}"
echo "  Deploy:   bash deploy/deploy.sh   (after each git pull)"
