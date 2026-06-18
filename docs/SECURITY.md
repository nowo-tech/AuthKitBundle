# Security

## Table of contents

- [Threat model](#threat-model)
- [Application responsibilities](#application-responsibilities)
- [Bundle responsibilities](#bundle-responsibilities)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Threat model

Auth Kit Bundle provides login/register **UI and persistence helpers**. Symfony Security remains responsible for authentication, session management, and authorization after login.

| Area | Risk | Mitigation |
| --- | --- | --- |
| Login form | CSRF, credential stuffing | Symfony `form_login` with CSRF; document rate limiting via `nowo-tech/login-throttle-bundle` |
| Registration | Mass signup, privilege escalation | `registration_mode`; configurable `registration_role`; app-level `access_control` |
| Password storage | Weak hashing | Uses `UserPasswordHasherInterface` |
| Templates | XSS | Twig auto-escaping; apps must not disable escaping in overrides |
| Configuration | Wrong entity/field mapping | Validation in `Configuration`; documented `security.yaml` setup |

## Application responsibilities

- Configure `security.yaml` (firewall, provider, `access_control`)
- Protect admin routes with appropriate roles
- Run `composer audit` in the application
- Do not commit `.env` or secrets

## Bundle responsibilities

- Hash passwords on registration
- Use Symfony form CSRF defaults on login forms
- No automatic modification of `security.yaml` without explicit CLI command

## Release security checklist (12.4.1)

Before each release, confirm:

| Item | Status |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` up to date | ☐ |
| `.env` listed in `.gitignore`; no secrets in repo | ☐ |
| Flex recipe / default config contain no secrets | ☐ |
| User input validated (forms + Symfony validator on registration) | ☐ |
| Output escaped (Twig templates) | ☐ |
| `composer audit` run on bundle and demo | ☐ |
| Logs do not dump credentials | ☐ |
| Password hashing via Symfony hasher (no custom crypto) | ☐ |
| Registration gate prevents unwanted signups per config | ☐ |
| Document DoS/rate-limit pairing with login throttle bundle | ☐ |

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for private disclosure.
