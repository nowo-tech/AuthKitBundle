# Contributing

Thank you for contributing to Auth Kit Bundle.

## Table of contents

- [Development setup](#development-setup)
- [Quality gates](#quality-gates)
- [Standards](#standards)
- [Pull requests](#pull-requests)
- [Security](#security)

## Development setup

```bash
make up
make install
make setup-hooks   # optional: run cs-check + test before each commit
make qa
```

## Quality gates

Before opening a PR:

```bash
make release-check
```

This runs code style, PHPStan, PHPUnit with **100% coverage** (`test-coverage-100`), translation lint, and demo health checks.

## Standards

- PSR-12 via PHP-CS-Fixer
- `declare(strict_types=1);` in all PHP files
- PHPDoc in English for public APIs
- Follow [BUNDLES_FULL_SPECS_DETAILS.md](https://github.com/nowo-tech/bundles/blob/main/BUNDLES_FULL_SPECS_DETAILS.md) for Nowo bundle conventions

## Pull requests

- Use the PR template
- Update `docs/CHANGELOG.md` for user-visible changes
- Add or update tests for behavior changes
- Keep `security.yaml` integration documented when touching auth flows

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md).
