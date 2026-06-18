# Auth Kit Bundle — Demo

FrankenPHP demos for **Symfony 7.4** and **Symfony 8.1** with login, logout, first-user registration, **Bootstrap 5** UI, and **en/es** locale switching.

See [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) for dev vs production (worker) setup and troubleshooting.

## Quick start

```bash
make up-symfony7   # http://localhost:8009
make up-symfony8   # http://localhost:8010
```

Register the first user on `/en/register` or via the **Account** dropdown on `/en`, then sign in at `/en/login` or from the same dropdown. Use **English** / **Español** in the navbar switcher (updates the `/{locale}` segment in the URL).

## Template overrides & Bootstrap

Each demo overrides Auth Kit Twig templates under:

```
symfony7|symfony8/templates/bundles/NowoAuthKitBundle/
├── layout.html.twig
└── security/
    ├── login.html.twig
    └── register.html.twig
```

Symfony resolves these before the bundle defaults (`@NowoAuthKitBundle/…`). The overrides:

- Load **Bootstrap 5** from CDN
- Use `bootstrap_5_layout.html.twig` for form fields
- Keep `@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig` for password fields
- Include `demo/_locale_switcher.html.twig` in the demo navbar
- Render `auth_kit_dropdown()` in `templates/base.html.twig` (`embed.mode: dropdown`)

Details: `symfony7/templates/bundles/NowoAuthKitBundle/README.md` (same in `symfony8`).

The public welcome page (`/en`, `/es`) and the logged-in home (`/en/home`) use `templates/base.html.twig` with navbar, locale switcher, and embedded auth dropdown. `/` redirects to the session locale (default `/en`).

## Locale switching

Demos use `nowo_auth_kit.locale_in_path: true` so Auth Kit routes are `/en/login`, `/es/register`, etc.

| Piece | Location |
|-------|----------|
| UI | `templates/demo/_locale_switcher.html.twig` (swaps `{_locale}` in the current URL) |
| Fallback route | `GET /locale/{_locale}` → `App\Controller\LocaleController` |
| Persistence | `App\EventSubscriber\LocaleSubscriber` (session `_locale`) |
| Demo labels | `translations/demo.en.yaml`, `translations/demo.es.yaml` |
| Bundle copy | `translations/NowoAuthKitBundle.<locale>.yaml` (optional) |

`framework.enabled_locales` and `nowo_auth_kit.enabled_locales` are both `[en, es]`.

## Commands

| Target | Description |
|--------|-------------|
| `make up-symfony7` | Start Symfony 7.4 demo (port **8009**) |
| `make up-symfony8` | Start Symfony 8.1 demo (port **8010**) |
| `make down-symfony7` / `make down-symfony8` | Stop demo |
| `make shell-symfony7` / `make shell-symfony8` | Shell in PHP container |
| `make test-symfony7` / `make test-symfony8` | Run demo tests |
| `make update-bundle-symfony7` / `make update-bundle-symfony8` | Refresh bundle autoload / cache |
| `make release-check` | Start both demos and healthcheck `/en/login` |

Bundle code is mounted from the repository root (`/var/auth-kit-bundle` in each container).

## Troubleshooting deprecations

After pulling demo changes, rebuild the PHP image so the **`intl`** extension is available:

```bash
cd demo/symfony8   # or symfony7
docker compose build --no-cache php
docker compose up -d
```

If Doctrine warns about **MySQL &lt; 8**, the local `mysql-data` volume may come from an older MySQL 5.x install. Reset it (destroys demo DB data):

```bash
docker compose down -v
make up
```

Ensure `.env` uses the MySQL `DATABASE_URL` from `.env.example` (not the leftover PostgreSQL DSN from Symfony Flex).
