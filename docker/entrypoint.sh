#!/bin/sh
set -eu

cd /var/www/html

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

if [ ! -f public/build/manifest.json ]; then
    npm install
    npm run build
fi

php artisan storage:link --force >/dev/null 2>&1 || php artisan storage:link || true

echo "Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
attempt=0
until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }'; do
    attempt=$((attempt + 1))
    if [ "$attempt" -gt 30 ]; then
        echo "MySQL did not become ready." >&2
        exit 1
    fi
    sleep 2
done

php artisan migrate --force

set +e
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
exit(Illuminate\Support\Facades\DB::table("users")->count() > 0 ? 0 : 2);
'
seed_status=$?
set -e

if [ "$seed_status" -eq 2 ]; then
    php artisan db:seed --force
elif [ "$seed_status" -ne 0 ]; then
    exit "$seed_status"
fi

php artisan queue:listen --tries=1 --timeout=0 &

cd /var/www/html/public
exec php -c /var/www/html/php.ini -S 0.0.0.0:8000 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
