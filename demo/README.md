# Auth Kit Bundle — Demo

FrankenPHP demos for **Symfony 7.4** and **Symfony 8.1** with login, logout, first-user registration, **Bootstrap 5** UI, and **en/es** locale switching.

See [docs/DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md) for dev vs production (worker) setup and troubleshooting.

## Quick start

```bash
make up-symfony7   # http://localhost:8009
make up-symfony8   # http://localhost:8010
```

Register the first user on `/register`, then sign in at `/login`. Use **English** / **Español** in the top-right switcher.

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
- Include `demo/_locale_switcher.html.twig` in layout and home page

Details: `symfony7/templates/bundles/NowoAuthKitBundle/README.md` (same in `symfony8`).

The logged-in home page uses `templates/base.html.twig` (Bootstrap card) plus the same locale switcher.

## Locale switching

| Piece | Location |
|-------|----------|
| UI | `templates/demo/_locale_switcher.html.twig` |
| Route | `GET /locale/{_locale}` → `App\Controller\LocaleController` |
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
| `make release-check` | Start both demos and healthcheck `/login` |

Bundle code is mounted from the repository root (`/var/auth-kit-bundle` in each container).
