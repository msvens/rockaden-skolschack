# Release Command

Create a new release tag and push it:

## Steps

1. **Determine version**
   - Run `git tag --sort=-v:refname | head -1` to find the latest tag
   - If arguments contain a version (e.g., `0.3.0` or `v0.3.0`), use that
   - Otherwise, bump the minor version by 1
   - Ensure the version starts with `v` prefix; the bare version is used for file updates

2. **Verify state**
   - `git status` must be clean; if dirty, abort and suggest `/co`
   - Confirm we are on `main`

3. **Bump version strings**
   - `rockaden-skolschack.php` — the `Version:` header and `define( 'RSK_VERSION', '...' )`
   - `phpstan-bootstrap.php` — the `define( 'RSK_VERSION', '...' )` line

   Then run `pnpm i18n` and stage the regenerated catalogue. `wp i18n make-pot` builds
   `Project-Id-Version` from the `Version:` header, so bumping without regenerating leaves the `.pot`
   stale. Expect header-only churn. If `pnpm i18n` reports anything needing attention, stop — that is
   untranslated work, not release noise.

   Commit as: `Bump version to <version>`

4. **Show what will be released**
   - `git log --oneline <latest-tag>..HEAD`, display the new version, ask the user to confirm

5. **Create and push tag**
   - `git tag <version>`, `git push origin main`, `git push origin <version>`

## Important

- **The bump commit and the tag go out together.** The update checker falls back to the branch's
  source zip when it finds no release, and that zip has no `vendor/` — so `main` must never sit at a
  version higher than the latest release. Never push a bump without its tag; never tag by hand.
- **Abort if the working tree is dirty**
- **Never force-push tags** — if the tag exists, abort
- Tag format is always `vX.Y.Z`
- **No Claude attribution** in the version bump commit
