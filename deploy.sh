#!/usr/bin/env bash
# Remote post-rsync steps for Hostinger shared hosting.
# Invoked by GitHub Actions over SSH from the Laravel root (…/public_html/chat).
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

echo "SmackChat deploy complete."
