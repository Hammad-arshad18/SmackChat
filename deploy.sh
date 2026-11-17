#!/usr/bin/env bash
# Remote post-rsync steps for Hostinger shared hosting.
# Invoked by GitHub Actions over SSH from the Laravel root (…/public_html/chat).
#
# Safe to re-run: the app is put in maintenance mode, the database is backed up
# first, and the app is brought back up even when a step fails.
set -euo pipefail

export PATH="$HOME/bin:/usr/local/bin:$PATH"

cd "$(dirname "$0")"

if [[ ! -f .env ]]; then
  echo "Missing .env in $(pwd). Create it on the server before deploying."
  exit 1
fi

if [[ ! -f artisan ]]; then
  echo "artisan not found in $(pwd). Wrong deploy path?"
  exit 1
fi

APP_URL="$(grep -E '^APP_URL=' .env | head -1 | cut -d= -f2- | tr -d '"' || true)"
APP_URL="${APP_URL:-http://localhost}"

bring_up() {
  php artisan up >/dev/null 2>&1 || true
}
trap bring_up EXIT

# A failed deploy must not leave the office staring at a maintenance page.
php artisan down --retry=30 >/dev/null 2>&1 || true

# Keep a copy before the schema changes. Never block a deploy on this, but say so.
if ! php artisan smackchat:backup --keep=7; then
  echo "WARNING: pre-deploy backup failed. Check storage/app/backups and disk space."
fi

composer install \
  --no-interaction \
  --prefer-dist \
  --optimize-autoloader \
  --no-dev \
  --ignore-platform-reqs

php artisan migrate --force
php artisan storage:link || true
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart || true

php artisan up
trap - EXIT

if command -v curl >/dev/null 2>&1; then
  status="$(curl -fsS -o /dev/null -w '%{http_code}' "${APP_URL%/}/up" || true)"
  if [[ "$status" != "200" ]]; then
    echo "Health check failed: ${APP_URL%/}/up returned ${status:-no response}."
    exit 1
  fi
  echo "Health check passed."
fi

# Retention and backups only run when the scheduler is wired to cron.
if command -v crontab >/dev/null 2>&1; then
  if ! crontab -l 2>/dev/null | grep -q 'schedule:run'; then
    echo "WARNING: no 'schedule:run' cron entry. History pruning, backups, and"
    echo "         audit retention will never run. Add:"
    echo "         * * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1"
  fi
fi

echo "SmackChat deploy complete."
