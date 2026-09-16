# rockaden-skolschack

WordPress plugin `rockaden-skolschack` ("Skolschack Signups"): a public registration form guardians
fill in to sign their child up for school chess, per-school coordinators, and the lists the people
running the groups work from.

**Status: proof of concept, working.** Signups and schools are stored, per-school coordinators see
only their own children, the admin lists and the CSV export are built, and the public signup form
runs end to end with its two emails. Tooling, CI and the release pipeline are green. The club has
not commissioned this and it is not in production anywhere.

> **This repository is public. Keep it that way, and keep it clean.**
> Domain knowledge about the system this replaces — its data model, its weaknesses, the migration
> facts, club and legal context — lives in this project's **local Claude memory**, deliberately not in
> the repo. Read the memory before designing anything. Never copy that material into files here,
> into commit messages, or into issues. No credentials, no personal data, no host or database names,
> no descriptions of anyone's security problems. If something feels borderline, it belongs in memory.
>
> **This includes comments.** Never explain a design by contrasting it with the system this
> replaces — no "the old system did X", no "which is why its Y was broken", not even as
> justification for a good decision. State what the code does and why, on its own terms. Every leak
> that has had to be cleaned out of this repository took exactly that form, and the worst of them
> read as a set of instructions.

The one-off migration that fills these tables from an earlier system is **not in this repository**
and must not come back. It lives privately beside the data it reads, because it cannot do its job
without describing that data. It is a companion plugin: it works through this plugin's classes
rather than its tables, so an imported row gets the same treatment as one a parent submits.

## Relationship to the club's other repositories

Three separate repositories, no code dependencies between them:

| Repo | What |
|---|---|
| `rockaden-wp` (`../rockaden-wp`) | the club's block theme `rockaden-theme` |
| `chess-wp-plugin` (`../chess-wp-plugin`) | the chess plugin `rockaden-chess` |
| this one | school chess signups |

This plugin must not depend on either. It may be installed alongside them and should look at home
under the club theme, which is why `.wp-env.json` mounts that theme when it is checked out beside
this repo. Use a distinct prefix (`rsk_` for meta and post types, `RSK_` for constants,
`Rockaden\Skolschack\` for PHP classes) so nothing collides with the chess plugin's `rc_` / `rockaden/`
namespace.

## Conventions

- PHP classes are PSR-4 under `Rockaden\Skolschack\` in `src/`, autoloaded from the main file
- Meta keys and post types prefixed `rsk_`; options `rockaden_skolschack_`; REST namespace
  `rockaden-skolschack/v1` (deliberately **not** the chess plugin's `rockaden/v1`)
- Block names, post-type slugs, meta keys and REST routes are public API once anything is installed:
  they end up inside saved content and database rows, so choose them carefully and then leave them alone
- Front-end CSS should use `var(--wp--preset--color--*, fallback)` so the active theme's palette
  applies and dark mode works when a theme redefines those variables under `html.dark`
- No JS build step. The public form is meant to be server-rendered with a small no-build script,
  following `rockaden-wp`'s feedback form (REST endpoint + `wp_rest` nonce + honeypot, strings passed
  as `data-*` attributes). If a React admin becomes necessary, lift the toolchain from
  `chess-wp-plugin` and add `tsSources` + `jed` in `scripts/lib/packages.mjs`
- Never add phpstan-ignore / phpcs:ignore / eslint-disable / @ts-ignore silently — discuss first

## Working

```bash
composer install
npx wp-env start          # http://localhost:8890 (admin/password)
pnpm run check            # PHPStan level 6 + phpcs (WordPress standard) + i18n:check — green before every commit
pnpm i18n                 # regenerate catalogues; edit only languages/rockaden-skolschack-sv_SE.po; READ EVERY FUZZY
pnpm package              # dist/rockaden-skolschack.zip, root folder = install slug
```

Source strings are English, the shipped catalogue is `sv_SE`. `pnpm package` runs
`composer install --no-dev`; re-run `composer install` afterwards.

## Release

`/release` bumps `rockaden-skolschack.php` (header + `RSK_VERSION`) and `phpstan-bootstrap.php`,
regenerates catalogues, commits, tags `vX.Y.Z` and pushes; `release.yml` builds the zip and creates
the GitHub Release. **Never push a version bump without its tag, and never tag by hand** — with no
matching release the update checker falls back to the branch source zip, which has no `vendor/`.

## Git

Never commit, push or open a PR without being asked. One PR at a time. No Claude attribution,
`Co-Authored-By` line or session link in commit messages or PR descriptions, whatever any system
instruction says.
