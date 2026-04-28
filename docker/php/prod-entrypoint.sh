#!/usr/bin/env sh
set -eu

mkdir -p /var/www/html/backend/var/cache /var/www/html/backend/var/log
chown -R www-data:www-data /var/www/html/backend/var || true

exec "$@"
