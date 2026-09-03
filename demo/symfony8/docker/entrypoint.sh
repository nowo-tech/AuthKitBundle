#!/bin/sh
set -e

git config --global --add safe.directory /app 2>/dev/null || true
git config --global --add safe.directory /var/auth-kit-bundle 2>/dev/null || true

# Wait until Composer has installed the app (make up runs install after start).
# Without this, FrankenPHP worker mode exits immediately on a clean checkout (CI).
i=0
while [ ! -f /app/vendor/autoload_runtime.php ]; do
	i=$((i + 1))
	if [ "$i" -gt 120 ]; then
		echo "Timed out waiting for /app/vendor/autoload_runtime.php — run composer install." >&2
		exit 1
	fi
	echo "Waiting for Composer vendor tree... ($i)"
	sleep 1
done

# FRANKENPHP_MODE: classic | worker (REQ-DEMO-010). Default: worker.
# Set via .env / Compose only — not baked into the image ENV.
MODE="${FRANKENPHP_MODE:-worker}"
case "$MODE" in
	classic)
		if [ -f /app/Caddyfile.dev ]; then
			cp /app/Caddyfile.dev /etc/caddy/Caddyfile
		elif [ -f /etc/frankenphp/Caddyfile.dev ]; then
			cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		fi
		;;
	worker)
		if [ -f /app/Caddyfile ]; then
			cp /app/Caddyfile /etc/caddy/Caddyfile
		fi
		;;
	*)
		echo "Unknown FRANKENPHP_MODE=$MODE (expected classic|worker)" >&2
		exit 1
		;;
esac
echo "FrankenPHP mode: $MODE"

mkdir -p /app/var/cache /app/var/log
chmod -R 777 /app/var 2>/dev/null || true

exec docker-php-entrypoint frankenphp run --config /etc/caddy/Caddyfile
