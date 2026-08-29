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
    APP_KEY="$(php artisan key:generate --show --no-ansi)"
    export APP_KEY
    echo "AVISO: APP_KEY no estaba configurada; se generó una clave temporal para este despliegue." >&2
fi

if [ -z "${PIZZERIA_SEED_PASSWORD:-}" ]; then
    PIZZERIA_SEED_PASSWORD='Pizzeria123!'
    export PIZZERIA_SEED_PASSWORD
    echo "AVISO: PIZZERIA_SEED_PASSWORD no estaba configurada; se usó la contraseña inicial documentada. Cámbiala en Railway." >&2
fi

php artisan config:clear
php artisan migrate --force
php artisan db:seed --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
