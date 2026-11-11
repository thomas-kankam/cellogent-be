#!/usr/bin/env bash
# Install OS packages for Cellogent (Ubuntu/Debian). Run with sudo.
# Usage: sudo bash deploy/setup-server.sh
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "Run as root: sudo bash deploy/setup-server.sh" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

echo "==> Updating apt"
apt-get update -y

echo "==> Installing PHP 8.2+, Redis, Nginx, Supervisor, MySQL client, unzip, git"
# If 8.2 packages are missing on your distro, add ondrej/php first:
#   apt-get install -y software-properties-common && add-apt-repository -y ppa:ondrej/php && apt-get update
apt-get install -y \
  git curl unzip zip \
  nginx supervisor redis-server \
  mysql-client \
  php php-cli php-fpm php-mbstring php-xml php-curl php-zip \
  php-bcmath php-intl php-gd php-mysql php-redis \
  php-tokenizer php-fileinfo php-opcache

# pcntl/posix ship with php-cli on Ubuntu; confirm:
php -m | grep -qi pcntl || echo "WARN: pcntl missing — Horizon needs it (use php-cli, not a build without pcntl)."
php -m | grep -qi redis || { echo "ERROR: php-redis missing"; exit 1; }

echo "==> Enabling & starting Redis + Supervisor + Nginx"
systemctl enable --now redis-server
systemctl enable --now supervisor
systemctl enable --now nginx
systemctl enable --now "php*-fpm" 2>/dev/null || true

if ! command -v composer >/dev/null 2>&1; then
  echo "==> Installing Composer"
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo "==> Server packages ready."
echo "Next:"
echo "  1. Create MySQL database/user"
echo "  2. Clone app into APP_DIR (default /var/www/cellogent/backend)"
echo "  3. Copy .env.example → .env and fill production values"
echo "  4. cd /var/www/cellogent/backend && bash deploy/install-app.sh"
