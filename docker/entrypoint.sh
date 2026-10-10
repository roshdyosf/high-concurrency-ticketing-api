#!/bin/sh
set -e

echo "[entrypoint] Rebuilding Redis state from PostgreSQL..."

php artisan inventory:rebuild-ga \
    || echo "[entrypoint] WARNING: inventory:rebuild-ga failed (is the database migrated?)"

php artisan inventory:rebuild-seat-locks \
    || echo "[entrypoint] WARNING: inventory:rebuild-seat-locks failed (is the database migrated?)"

exec "$@"
