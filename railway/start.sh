#!/bin/sh

set -eu

database_connection="${DB_CONNECTION:-sqlite}"

if [ "$database_connection" = "sqlite" ]; then
    database_file="${DB_DATABASE:-database/database.sqlite}"
    case "$database_file" in
        /*) ;;
        *) database_file="$(pwd)/$database_file" ;;
    esac
    mkdir -p "$(dirname "$database_file")"
    touch "$database_file"
    export DB_DATABASE="$database_file"
fi

if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: configura APP_KEY en las variables de Railway." >&2
    exit 1
fi

if [ -z "${PIZZERIA_SEED_PASSWORD:-}" ]; then
    echo "ERROR: configura PIZZERIA_SEED_PASSWORD en las variables de Railway." >&2
    exit 1
fi

php artisan config:clear
php artisan migrate --force
php artisan db:seed --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
