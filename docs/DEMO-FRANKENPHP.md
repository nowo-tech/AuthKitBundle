# Demo with FrankenPHP

## Table of contents

- [Layout](#layout)
- [Quick start](#quick-start)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Symfony `APP_ENV=prod`](#symfony-app_envprod)
- [Troubleshooting](#troubleshooting)
- [Related](#related)

The Auth Kit demo runs on **FrankenPHP** (Caddy + embedded PHP). REQ-DEMO-002 / REQ-DEMO-010.

Base image: `dunglas/frankenphp:1-php8.5-bookworm` (newest PHP allowed by Symfony 8 / demo constraints).

**Smoke check (REQ-TEST-011):** from the bundle root, `make demo-smoke` boots `demo/symfony8` and asserts `HTTP 200` on `http://localhost:$PORT/en/login` (default port **8010**). Also available as `.github/workflows/demo-smoke.yml`.

## Layout

| File | Purpose |
|------|---------|
| `demo/symfony8/` | Symfony **8.1** demo (FrankenPHP, port **8010**) |
| `demo/symfony8/Caddyfile` | Worker mode: `php_server { worker … }` |
| `demo/symfony8/Caddyfile.dev` | Classic mode: plain `php_server` (hot-reload friendly) |

The bundle path repository is mounted at `/var/auth-kit-bundle` inside the PHP container.

## Quick start

```bash
make -C demo up-symfony8   # port 8010
```

- `.env` from `.env.example` (`APP_ENV=dev`, **`FRANKENPHP_MODE=worker`** by default)
- Entrypoint selects Caddyfile from **`FRANKENPHP_MODE`** (not from `APP_ENV`)
- Open http://localhost:8010 — register the first user, then sign in

## Switching classic vs worker (`FRANKENPHP_MODE`)

Demos select the FrankenPHP runtime via **`FRANKENPHP_MODE`** in `.env` / `.env.example` (not a Dockerfile `ENV`):

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Uses `Caddyfile` (`php_server { worker /app/public/index.php 2 }`) |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`, easier hot-reload) |

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make -C demo up-symfony8`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

After changing bundle code under the path mount while in **worker** mode:

```bash
docker compose exec -T php frankenphp reload
```

## Symfony `APP_ENV=prod`

`APP_ENV` still controls Symfony (cache, debug, Composer `--no-dev`). It does **not** pick the Caddyfile.

Example production-style run:

```bash
cd demo/symfony8
# .env: APP_ENV=prod, APP_DEBUG=0, FRANKENPHP_MODE=worker
docker compose down
docker compose up -d --build
docker compose exec -T php composer install --no-dev --no-interaction
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

## Troubleshooting

| Issue | Fix |
|-------|-----|
| `service "php" is not running` right after `up` | Clean checkout without `vendor/`: entrypoint waits up to ~120s for `vendor/autoload_runtime.php` while `make up` runs `composer install`. If it still fails, check `docker compose logs php` |
| `502` or blank page after start | Wait for MySQL healthcheck + FrankenPHP worker boot after Composer; run `docker compose logs php` |
| Routes 404 | Ensure `public/index.php` exists and `root * /app/public` is set in the active Caddyfile |
| Composer cannot reach Packagist | Demo compose sets `dns: 8.8.8.8` / `8.8.4.4` for Docker/WSL DNS issues |
| Template / PHP changes not visible | Prefer `FRANKENPHP_MODE=classic` for hot-reload; with `worker`, run `frankenphp reload` or recreate the container |
| Stale code in worker | `docker compose exec -T php frankenphp reload` or recreate the `php` service |
| Permission errors on `var/` | Entrypoint creates `var/cache` and `var/log` with writable permissions |
| Mode change ignored | Recreate (`docker compose up -d`), do not only `restart` |

## Related

- [demo/README.md](../demo/README.md)
- [INSTALLATION.md](INSTALLATION.md)
