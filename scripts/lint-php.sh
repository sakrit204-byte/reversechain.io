#!/usr/bin/env bash
# PHP syntax check of plugin + theme inside the WordPress container.
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
docker compose exec -T wordpress sh -c 'for f in $(find /var/www/html/wp-content/plugins/reservechain-core /var/www/html/wp-content/themes/reservechain -name "*.php"); do php -l "$f" >/dev/null 2>&1 || php -l "$f"; done; echo "php lint done"'
