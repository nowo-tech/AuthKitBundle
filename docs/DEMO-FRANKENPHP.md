# Demo with FrankenPHP

## Table of contents

- [Layout](#layout)
- [Development (default)](#development-default)
- [Production mode (worker)](#production-mode-worker)
- [Troubleshooting](#troubleshooting)
- [Related](#related)

The Auth Kit demos run on **FrankenPHP** (Caddy + embedded PHP). REQ-DEMO-002.

## Layout

| File | Purpose |
|------|---------|
| `demo/symfony7/` | Symfony **7.4** demo (FrankenPHP, port **8009**) |
| `demo/symfony8/` | Symfony **8.1** demo (FrankenPHP, port **8010**) |
| `demo/symfony7/Caddyfile` / `demo/symfony8/Caddyfile` | Production: `php_server` with **worker** |
| `demo/symfony7/Caddyfile.dev` / `demo/symfony8/Caddyfile.dev` | Development: `php_server` without worker |

The bundle path repository is mounted at `/var/auth-kit-bundle` inside the PHP container.

## Development (default)

```bash
make -C demo up-symfony7   # port 8009
make -C demo up-symfony8   # port 8010
```

- `APP_ENV=dev` in `.env` (from `.env.example`)
- Entrypoint selects `Caddyfile.dev` (no worker)
- Open http://localhost:8009 (Symfony 7.4) or http://localhost:8010 (Symfony 8.1) — register the first user, then sign in

## Production mode (worker)

Set `APP_ENV=prod` and `APP_DEBUG=0` in the demo `.env`, then rebuild and start (replace `symfony7` or `symfony8`):

```bash
cd demo/symfony7   # or demo/symfony8
docker compose down
docker compose build --no-cache
docker compose up -d
docker compose exec -T php composer install --no-dev --no-interaction
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

The entrypoint uses `Caddyfile` with:

```caddyfile
php_server {
    worker /app/public/index.php 2
}
```

**FrankenPHP worker mode:** Supported on Symfony 7.4 and 8.1 demos; production `Caddyfile` enables worker mode.

After changing bundle code in the path mount, restart workers in production:

```bash
docker compose exec -T php frankenphp reload
```

## Troubleshooting

| Issue | Fix |
|-------|-----|
| `502` or blank page after start | Wait for MySQL healthcheck; run `docker compose logs php` |
| Routes 404 | Ensure `public/index.php` exists and `root * /app/public` is set in the active Caddyfile |
| Composer cannot reach Packagist | Demo compose sets `dns: 8.8.8.8` / `8.8.4.4` for Docker/WSL DNS issues |
| Template changes not visible in dev | Confirm `APP_ENV=dev` (uses `Caddyfile.dev` + `twig.cache: false`) |
| Stale code in prod worker | `docker compose exec -T php frankenphp reload` or restart the `php` service |
| Permission errors on `var/` | Entrypoint creates `var/cache` and `var/log` with writable permissions |

## Related

- [demo/README.md](../demo/README.md)
- [INSTALLATION.md](INSTALLATION.md)
