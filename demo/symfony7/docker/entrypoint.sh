#!/bin/sh
set -e

mkdir -p /app/var/cache /app/var/log
chmod -R 777 /app/var 2>/dev/null || true

if [ "${APP_ENV:-dev}" = "dev" ]; then
	cp /app/Caddyfile.dev /etc/caddy/Caddyfile
else
	cp /app/Caddyfile /etc/caddy/Caddyfile
fi

exec docker-php-entrypoint frankenphp run --config /etc/caddy/Caddyfile
