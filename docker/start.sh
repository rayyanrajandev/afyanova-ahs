#!/usr/bin/env sh
set -eu

PORT="${PORT:-10000}"

php artisan config:clear --no-ansi
php artisan route:clear --no-ansi
php artisan view:clear --no-ansi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-ansi
fi

php artisan config:cache --no-ansi
php artisan route:cache --no-ansi || true
php artisan view:cache --no-ansi

exec php artisan serve --host=0.0.0.0 --port="$PORT"
