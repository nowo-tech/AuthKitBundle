# Contributing

Thank you for contributing to Auth Kit Bundle.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Development setup](#development-setup)
- [Quality gates](#quality-gates)
- [Standards](#standards)
- [Pull requests](#pull-requests)
- [Security](#security)
- [Cursor Agent](#cursor-agent)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)

## Development setup

```bash
make up
make install
make setup-hooks   # CS-check, tests, and commit-msg (REQ-GIT-001)
make qa
```

## Quality gates

Before opening a PR:

```bash
make igor
make release-check
```

This runs git hygiene (REQ-GIT-001), code style, PHPStan, PHPUnit with **100% coverage** (`test-coverage-100`), translation lint, and demo health checks.

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

## Cursor Agent

Contributors using Cursor Agent on this repository should enable these user-level settings (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**) — Cursor Settings → Agents / Chat → Submit with Ctrl + Enter. Enter inserts a newline; Ctrl/⌘+Enter submits the prompt.
2. **Queue follow-ups while the agent is running** — in your user `settings.json` (Linux `~/.config/Cursor/User/settings.json`, Windows `%APPDATA%\Cursor\User\settings.json`, macOS `~/Library/Application Support/Cursor/User/settings.json`):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do not use `"steer"` as the default: it interrupts the running agent turn as soon as the follow-up is sent.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.

If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
