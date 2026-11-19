#!/bin/sh
set -eu
mkdir -p /app/var/sessions /app/var/cache /app/var/log
chown -R www-data:www-data /app/var
attempt=0
until php bin/console app:install; do
 attempt=$((attempt+1))
 if [ "$attempt" -ge 30 ]; then echo "La base reste inaccessible." >&2; exit 1; fi
 sleep 2
done
php bin/console cache:clear --env=prod --no-debug --no-warmup
php bin/console cache:warmup --env=prod --no-debug
chown -R www-data:www-data /app/var
exec apache2-foreground
