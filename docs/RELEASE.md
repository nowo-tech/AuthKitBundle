# Release process

## Preconditions

1. `make release-check` passes locally (`ensure-up` → `check-no-cursor-coauthor` → `check-open-prs` → QA → demos).
2. [Security release checklist (12.4.1)](SECURITY.md#release-security-checklist-1241) completed, including [REQ-SEC-004](SECURITY.md#ai-security-audit) Pass.
3. Open PR queue clear: `make check-open-prs` (REQ-REL-003).
4. [CHANGELOG.md](CHANGELOG.md) updated with the target version section.

## Steps

1. Merge changes to `main`.
2. Create an annotated tag: `git tag -a vX.Y.Z -m "Release X.Y.Z"`.
3. Push the tag: `git push origin vX.Y.Z`.
4. GitHub Actions (`release.yml`) creates or updates the GitHub Release using the changelog entry.
5. Publish to Packagist (automatic if the package is registered).

## Versioning

Follow [Semantic Versioning](https://semver.org/):

- **MAJOR**: breaking changes to configuration keys, route names, or public services.
- **MINOR**: backward-compatible features.
- **PATCH**: backward-compatible bug fixes.

## Sync missing releases

If a tag exists without a GitHub Release, run the **Sync Missing Releases** workflow manually (`workflow_dispatch`) or wait for the daily schedule. It does **not** run on tag push (that would race with `release.yml` and can create duplicate releases for the same `tag_name`).

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001). The release commit itself is not covered by an earlier `release-check` run.
