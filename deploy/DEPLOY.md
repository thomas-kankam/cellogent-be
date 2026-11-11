# Cellogent backend — server setup & deploy

Canonical app path on the server:

```text
APP_DIR=/var/www/cellogent/backend
APP_USER=www-data
```

Horizon **requires Redis**. Do not use `QUEUE_CONNECTION=database` in production with Horizon.

---

## A. Manual setup (step by step)

Use this if you want to understand every piece, or if scripts are blocked.

### A1. Install OS packages

```bash
sudo apt-get update -y
sudo apt-get install -y \
  git curl unzip zip \
  nginx supervisor redis-server \
  mysql-client \
  php php-cli php-fpm php-mbstring php-xml php-curl php-zip \
  php-bcmath php-intl php-gd php-mysql php-redis \
  php-tokenizer php-fileinfo php-opcache

# Composer (if missing)
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

sudo systemctl enable --now redis-server supervisor nginx
sudo systemctl enable --now php*-fpm   # or: php8.2-fpm / php8.3-fpm
php -m | grep -i redis    # must show redis
php -m | grep -i pcntl    # Horizon needs this (comes with php-cli)
```

### A2. Create the database

```sql
CREATE DATABASE cellogent CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cellogent'@'%' IDENTIFIED BY 'strong-password';
GRANT ALL ON cellogent.* TO 'cellogent'@'%';
FLUSH PRIVILEGES;
```

### A3. Put the code on the server

```bash
sudo mkdir -p /var/www/cellogent
sudo chown -R $USER:www-data /var/www/cellogent
git clone <your-repo-url> /var/www/cellogent/backend
cd /var/www/cellogent/backend
cp .env.example .env
nano .env   # fill production values — see checklist below
```

### A4. Install PHP deps + Laravel bootstrap

```bash
cd /var/www/cellogent/backend
composer install --no-dev --optimize-autoloader --no-interaction
php artisan key:generate --force
php artisan migrate --force
php artisan passport:keys --force          # or copy oauth-*.key from the old server
php artisan passport:client --personal --name="Cellogent Personal Access Client" --no-interaction
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### A5. Supervisor (Horizon workers)

```bash
sudo cp /var/www/cellogent/backend/deploy/supervisor-horizon.conf \
  /etc/supervisor/conf.d/cellogent-horizon.conf
# If your path differs, edit the three paths inside that file first.
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start cellogent-horizon:*
sudo supervisorctl status cellogent-horizon:*
```

### A6. Cron (scheduler — every minute)

```bash
sudo cp /var/www/cellogent/backend/deploy/crontab-scheduler.conf \
  /etc/cron.d/cellogent-scheduler
sudo chmod 644 /etc/cron.d/cellogent-scheduler
sudo -u www-data php /var/www/cellogent/backend/artisan schedule:list
```

### A7. Nginx

Point `root` at `/var/www/cellogent/backend/public`, PHP to your `php*-fpm` socket.  
Allow contact uploads: `client_max_body_size 25M;`.

Horizon UI: `https://api.yourdomain.com/horizon?token=YOUR_HORIZON_TOKEN`

### A8. After every `git pull` (manual)

```bash
cd /var/www/cellogent/backend
git pull
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan queue:restart
php artisan horizon:terminate          # Supervisor starts Horizon again
sudo supervisorctl start cellogent-horizon:* || sudo supervisorctl restart cellogent-horizon:*
sudo systemctl reload php*-fpm         # or restart the fpm unit
```

---

## B. Scripted setup (recommended)

Same outcome as section A, with less typing.

### B1. Packages (once per server)

```bash
# From anywhere — copy repo first, or run after clone:
cd /var/www/cellogent/backend   # after clone in B2, or run from a checkout
sudo bash deploy/setup-server.sh
```

Creates/enables: PHP + extensions, Redis, Nginx, Supervisor, Composer.

### B2. Clone + `.env` (once)

```bash
sudo mkdir -p /var/www/cellogent
sudo chown -R $USER:www-data /var/www/cellogent
git clone <your-repo-url> /var/www/cellogent/backend
cd /var/www/cellogent/backend
cp .env.example .env
nano .env   # production checklist below
```

### B3. First install (once)

```bash
cd /var/www/cellogent/backend
bash deploy/install-app.sh
```

This runs Composer, migrate, Passport keys/client, storage link, caches, installs Supervisor + cron for `/var/www/cellogent/backend`, starts Horizon, and installs a **git post-merge hook** so later pulls auto-deploy.

### B4. Every update

**Option 1 — hook (easiest):**

```bash
cd /var/www/cellogent/backend
git pull
# post-merge → deploy.sh automatically
```

**Option 2 — run deploy yourself:**

```bash
cd /var/www/cellogent/backend
git pull
bash deploy/deploy.sh
```

`deploy.sh` runs: `composer install` → `migrate --force` → clear + rebuild caches → `horizon:terminate` + Supervisor restart → PHP-FPM reload.

Skip pieces when needed:

```bash
SKIP_MIGRATE=1 bash deploy/deploy.sh
SKIP_COMPOSER=1 bash deploy/deploy.sh
```

### B5. Re-install the auto-deploy hook (if missing)

```bash
cd /var/www/cellogent/backend
cp deploy/hooks/post-merge .git/hooks/post-merge
chmod +x .git/hooks/post-merge
```

---

## `.env` production checklist

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
FRONTEND_URL=https://app.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=cellogent
DB_USERNAME=cellogent
DB_PASSWORD=...

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

OTP_EXPOSE_IN_LOCAL=false
HORIZON_TOKEN=long-random-string
HORIZON_EMAIL=admin@cellogent.com

# Kairos / Paystack / Swift — fill all keys
# Webhooks must hit this host:
#   {APP_URL}/api/webhooks/paystack
#   {APP_URL}/api/webhooks/kairos-payment?token={KAIROS_PAY_WEBHOOK_TOKEN}
#   {APP_URL}/api/swift_webhook
```

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # HORIZON_TOKEN / webhook tokens
```

### Passport keys

- `storage/oauth-private.key`
- `storage/oauth-public.key`

**Do not commit these.** New server: either copy keys from the old host (tokens keep working) or `php artisan passport:keys --force` (all old tokens die).

---

## What each piece does

| Piece | Role |
|---|---|
| **Horizon** (Supervisor) | Workers: SMS, DLR, payments, contacts, email, engagement |
| **Cron** (`schedule:run`) | DLR sync, payment verify, blasts, birthdays, rewards |
| **Redis** | Required for Horizon queues |
| **Passport** | API OAuth tokens |
| **deploy.sh** | Safe post-pull: migrate, cache, horizon:terminate |

Useful checks:

```bash
cd /var/www/cellogent/backend
php artisan about
php artisan horizon:status
redis-cli ping
sudo supervisorctl status cellogent-horizon:*
sudo -u www-data php artisan schedule:list
curl -sI https://api.yourdomain.com/up
```

---

## Common mistakes

1. **Horizon with `QUEUE_CONNECTION=database`** — use `redis`.  
2. **No cron** — DLR, payments, campaigns freeze.  
3. **Forgot `horizon:terminate` after deploy** — old code keeps running (`deploy.sh` does this).  
4. **Passport keys missing** — 500 on token create.  
5. **`storage/` not writable by `www-data`**.  
6. **`APP_DEBUG=true` in production**.  
7. **Webhooks still pointing at the old host**.
