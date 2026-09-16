# Skolschack Signups (`rockaden-skolschack`)

WordPress plugin for running school chess signups: a public registration form that guardians fill in
for their child, per-school coordinators, and the lists the people running the groups work from.

**Status: proof of concept.** Signups and schools are stored, coordinators are assigned per school
and see only their own children, the admin lists and the CSV export work, and the public signup form
runs end to end. Nothing here is in production use.

## Requirements

- WordPress 6.5+
- PHP 8.1+

## Development

Requires PHP 8.1+ with Composer, Node.js 22+ (for the i18n and packaging scripts), and Docker.

```bash
git clone https://github.com/msvens/rockaden-skolschack.git
cd rockaden-skolschack
composer install
npx wp-env start        # WordPress at http://localhost:8890 (admin/password)
```

`.wp-env.json` mounts this directory as `wp-content/plugins/rockaden-skolschack` and, if you have it
checked out beside this one, the club theme from `../rockaden-wp` as
`wp-content/themes/rockaden-theme`, so the public form renders in its real surroundings. Drop the
theme mapping to develop against a stock theme. On first start:

```bash
npx wp-env run cli wp plugin activate rockaden-skolschack
npx wp-env run cli wp rewrite structure '/%postname%/'
npx wp-env run cli wp rewrite flush --hard
```

There is no JS build step. The public form is intended to work as plain server-rendered HTML with a
small no-build script, following the same pattern as the club theme's feedback form. If a React admin
screen ever becomes necessary, the build toolchain can be lifted from `chess-wp-plugin`.

### Quality checks

```bash
pnpm run check     # PHPStan + phpcs + translations
```

### Translations

Source strings are English; the catalogue is `languages/rockaden-skolschack-sv_SE.po`, the only file
you edit. Everything else is generated:

```bash
pnpm i18n          # re-extract, merge into the .po, regenerate .mo/.l10n.php
pnpm i18n:check    # verify nothing has drifted (runs in `check` and CI)
```

After adding or changing a user-facing string, run `pnpm i18n`, fill in any untranslated entries, and
run it again. WordPress 6.5+ reads the generated `.l10n.php` in preference to the `.mo`, so a string
translated only in the `.po` still renders in English; `pnpm i18n:check` fails on exactly that.
`msgmerge` may mark a reworded string **fuzzy** with a guessed translation, and fuzzy entries are
excluded from the compiled catalogue — review every one.

Regenerating needs `vendor/bin/wp` (`composer install`) and GNU gettext (`brew install gettext`).
Checking needs neither, which is why CI only runs the check.

### Package

```bash
pnpm package            # dist/rockaden-skolschack.zip (root folder = install slug)
```

### Creating a release

Bump `Version:` and `RSK_VERSION` in `rockaden-skolschack.php` and `RSK_VERSION` in
`phpstan-bootstrap.php`, run `pnpm i18n`, commit, then tag and push — GitHub Actions builds the zip
and publishes a release:

```bash
git tag v0.2.0
git push origin main v0.2.0
```

Never push a version bump to `main` without tagging it: the update checker falls back to the branch's
source zip when it finds no release, and that zip has no `vendor/`.

## License

[MIT](LICENSE)
