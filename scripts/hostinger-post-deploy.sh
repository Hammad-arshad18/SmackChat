#!/usr/bin/env bash
# Run once after the first Hostinger pull, and after schema-changing deploys.
# Usage (from the Laravel root on the server):
#   bash scripts/hostinger-post-deploy.sh

set -euo pipefail

cd "$(dirname "$0")/.."

if [[ ! -f .env ]]; then
  echo "Missing .env — copy .env.example and set production values first."
  exit 1
fi

php artisan migrate --force
php artisan storage:link || true
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Hostinger post-deploy complete."
