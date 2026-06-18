# Release process

## Preconditions

1. `make release-check` passes locally.
2. [Security release checklist (12.4.1)](SECURITY.md#release-security-checklist-1241) completed.
3. [CHANGELOG.md](CHANGELOG.md) updated with the target version section.

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

If a tag exists without a GitHub Release, run the `sync-releases.yml` workflow manually or push an empty commit to trigger it.
