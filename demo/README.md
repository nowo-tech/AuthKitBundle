# Auth Kit Bundle — Demo

FrankenPHP demo for **Symfony 8.1** with login, logout, first-user registration, **Bootstrap 5** UI, and **en/es** locale switching.

See [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) for dev vs production (worker) setup and troubleshooting.

## Quick start

```bash
make up-symfony8   # http://localhost:8010
```

Register the first user on `/en/register` or via the **Account** dropdown on `/en`, then try the flows below.

## Use cases (no real mailer)

Password reset and magic login are **enabled**. Locale mode is **`both` + `redirect`**: canonical `/{locale}/…` and bare URLs (e.g. `/login`) that redirect to the default/current locale.

Notifiers only log and store the last payload in the session **demo inbox**.

| Flow | URL | What to do |
|------|-----|------------|
| Password login | `/en/login` | Sign in with email + password |
| Bare login (redirect) | `/login` | Redirects to `/en/login` (or current locale) |
| Locale ES | `/es/login` | Same form in Spanish |
| Password reset | `/en/reset-password` or `/reset-password` | Submit email → inbox link/code |
| Magic login | `/en/magic-login` or `/magic-login` | Submit email → inbox signed link |
| Register | `/en/register` or `/register` | Until the first user exists (`first_user_only`) |

To try **serve** instead of redirect, set `locale.unlocalized: serve` in `symfony8/config/packages/nowo_auth_kit.yaml` and clear the cache — bare URLs then render with `locale.default`.

The welcome page (`/en`) lists the same use cases as cards.

## Template overrides & Bootstrap

The demo overrides Auth Kit Twig templates under:

```
symfony8/templates/bundles/NowoAuthKitBundle/
├── layout.html.twig
└── security/
    ├── login.html.twig
    ├── register.html.twig
    ├── reset_request.html.twig
    └── magic_login_request.html.twig
```

Symfony resolves these before the bundle defaults (`@NowoAuthKitBundle/…`). The overrides:

- Load **Bootstrap 5** from CDN
- Use `bootstrap_5_layout.html.twig` for form fields
- Keep `@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig` for password fields
- Include `demo/_locale_switcher.html.twig` in the demo navbar
- Show the session **demo delivery inbox** (last reset / magic-login link or code) on auth pages
- Render `auth_kit_dropdown()` in `templates/base.html.twig` (`embed.mode: dropdown`)

Details: `symfony8/templates/bundles/NowoAuthKitBundle/README.md`.

The public welcome page (`/en`, `/es`) and the logged-in home (`/en/home`) use `templates/base.html.twig` with navbar, locale switcher, and embedded auth dropdown. `/` redirects to the session locale (default `/en`).

## Locale switching

The demo uses `locale.in_path: both` with `unlocalized: redirect`:

- Canonical auth routes: `/en/login`, `/es/register`, …
- Bare aliases: `/login`, `/register`, … → 302 to `/{locale}/…`

| Piece | Location |
|-------|----------|
| Config | `symfony8/config/packages/nowo_auth_kit.yaml` → `locale` |
| UI | `templates/demo/_locale_switcher.html.twig` (swaps `{_locale}` in the current URL) |
| Fallback route | `GET /locale/{_locale}` → `App\Controller\LocaleController` |
| Persistence | `App\EventSubscriber\LocaleSubscriber` (session `_locale`) |
| Demo labels | `translations/demo.en.yaml`, `translations/demo.es.yaml` |
| Bundle copy | `translations/NowoAuthKitBundle.<locale>.yaml` (optional) |

`framework.enabled_locales` and `nowo_auth_kit.locale.enabled` are both `[en, es]`.

## Commands

| Target | Description |
|--------|-------------|
| `make up-symfony8` | Start Symfony 8.1 demo (port **8010**) |
| `make down-symfony8` | Stop demo |
| `make shell-symfony8` | Shell in PHP container |
| `make test-symfony8` | Run demo tests |
| `make update-bundle-symfony8` | Refresh bundle autoload / cache |
| `make release-check` | Start demo and healthcheck `/en/login` |

Bundle code is mounted from the repository root (`/var/auth-kit-bundle` in the container).

## Troubleshooting deprecations

After pulling demo changes, rebuild the PHP image so the **`intl`** extension is available:

```bash
cd demo/symfony8
docker compose build --no-cache php
docker compose up -d
```

If Doctrine warns about **MySQL &lt; 8**, the local `mysql-data` volume may come from an older MySQL 5.x install. Reset it (destroys demo DB data):

```bash
docker compose down -v
make up
```

Ensure `.env` uses the MySQL `DATABASE_URL` from `.env.example` (not the leftover PostgreSQL DSN from Symfony Flex).
