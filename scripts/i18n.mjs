/**
 * Regenerate every translation artifact from source.
 *
 *   pnpm i18n
 *
 * Source of truth is the .po. Everything else — .pot, .mo, .l10n.php and the
 * plugin's jed .json — is generated, always together. Regenerating only some of
 * them is what let the .mo and .l10n.php fall seven strings behind the .po and
 * ship English to Swedish visitors.
 *
 * Needs: each package's vendor/bin/wp (composer install) and GNU gettext
 * (msgmerge/msgcat/msgfmt). Neither is needed by `pnpm i18n:check`, which is
 * what runs in CI.
 */
import { execFileSync } from 'child_process';
import { existsSync, readFileSync, writeFileSync, mkdtempSync, rmSync } from 'fs';
import { tmpdir } from 'os';
import { basename, join } from 'path';
import { PACKAGES, paths, root } from './lib/packages.mjs';
import { parsePo, isTranslated, headerValue } from './lib/po.mjs';

const run = ( cmd, args, opts = {} ) =>
	execFileSync( cmd, args, { encoding: 'utf8', stdio: 'pipe', ...opts } );

function requireTool( cmd, hint ) {
	try {
		run( 'which', [ cmd ] );
	} catch {
		console.error( `\n  Missing required tool: ${ cmd }\n  ${ hint }\n` );
		process.exit( 1 );
	}
}

/**
 * Write a POT, keeping the previous POT-Creation-Date when nothing else changed.
 *
 * wp-cli stamps the current time on every run, so without this `pnpm i18n`
 * would dirty both POT files every time it is invoked and `git status` would
 * never come back clean after a no-op regeneration.
 *
 * @param {string} file    Destination path.
 * @param {string} content Freshly generated POT.
 */
function writePot( file, content ) {
	const DATE = /^"POT-Creation-Date:.*\\n"$/m;
	if ( existsSync( file ) ) {
		const previous = readFileSync( file, 'utf8' );
		const prevDate = previous.match( DATE );
		if ( prevDate && content.replace( DATE, '' ) === previous.replace( DATE, '' ) ) {
			content = content.replace( DATE, prevDate[ 0 ] );
		}
	}
	writeFileSync( file, content );
}

/**
 * Build the POT: wp-cli for the WordPress formats (PHP, block.json,
 * theme.json, patterns, headers), xgettext for TypeScript, merged with msgcat.
 *
 * @param {object} pkg Package descriptor.
 * @param {object} p   Resolved paths.
 */
function buildPot( pkg, p ) {
	const tmp = mkdtempSync( join( tmpdir(), 'rc-i18n-' ) );
	try {
		const wpPot = join( tmp, 'wp.pot' );
		run( p.wp, [
			'i18n', 'make-pot', p.pkgDir, wpPot,
			`--domain=${ pkg.domain }`,
			`--exclude=${ pkg.exclude }`,
		], { cwd: root } );

		if ( ! pkg.tsSources.length ) {
			writePot( p.pot, readFileSync( wpPot, 'utf8' ) );
			return;
		}

		// WordPress's __( 'text', 'domain' ) takes a DOMAIN as argument 2, not a
		// gettext context. Telling xgettext otherwise (`__:1,2c`) stamps every
		// entry with msgctxt "rockaden-chess" and nothing matches the catalogue.
		const tsPot = join( tmp, 'ts.pot' );
		run( 'xgettext', [
			'--language=JavaScript',
			'--from-code=UTF-8',
			'--keyword=__:1',
			'--keyword=_x:1,2c',
			'--keyword=_n:1,2',
			'--keyword=_nx:1,2,4c',
			'--add-comments=translators:',
			'--package-name=' + pkg.domain,
			'-o', tsPot,
			...pkg.tsSources,
		], { cwd: p.pkgDir } );

		const merged = join( tmp, 'merged.pot' );
		run( 'msgcat', [ '--use-first', '-o', merged, wpPot, tsPot ] );
		writePot( p.pot, readFileSync( merged, 'utf8' ) );
	} finally {
		rmSync( tmp, { recursive: true, force: true } );
	}
}

/**
 * The jed file the pre_load_script_translations filter serves, containing only
 * strings referenced from JS/TS so PHP-only strings aren't shipped to every page.
 *
 * @param {object} p      Resolved paths.
 * @param {string} locale Target locale.
 */
function writeJed( p, locale ) {
	const { header, entries } = parsePo( readFileSync( p.po, 'utf8' ) );
	const messages = { '': { domain: 'messages', lang: locale, 'plural-forms': 'nplurals=2; plural=n != 1;' } };
	// Take the revision date from the .po rather than "now", so a no-op
	// regeneration produces a byte-identical file.
	const revision = ( headerValue( header, 'PO-Revision-Date' ) || '' ).slice( 0, 10 );

	let skipped = 0;
	for ( const e of entries ) {
		if ( e.obsolete || e.msgctxt ) continue;
		const fromJs = e.references.some( ( r ) => /\.(ts|tsx|js|jsx)(:|$)/.test( r ) );
		if ( ! fromJs ) continue;
		if ( ! isTranslated( e ) ) {
			skipped++;
			continue;
		}
		messages[ e.msgid ] = [ e.msgstr ];
	}

	writeFileSync(
		p.json,
		JSON.stringify( {
			'translation-revision-date': revision,
			generator: 'scripts/i18n.mjs',
			domain: 'messages',
			locale_data: { messages },
		} ) + '\n'
	);
	return { count: Object.keys( messages ).length - 1, skipped };
}

requireTool( 'msgmerge', 'GNU gettext is required: brew install gettext' );
requireTool( 'msgcat', 'GNU gettext is required: brew install gettext' );
requireTool( 'msgattrib', 'GNU gettext is required: brew install gettext' );

/**
 * Compile the runtime catalogues from a fuzzy-free copy of the .po.
 *
 * `wp i18n make-mo` drops fuzzy entries but `wp i18n make-php` keeps them, and
 * since WordPress 6.5 the .l10n.php is loaded in preference to the .mo. So a
 * guessed translation awaiting review went straight to visitors while the .mo
 * and every check here said it had not. A reworded Swedish string arrived on
 * the public signup form carrying a %d that the source string did not have.
 *
 * Stripping the entries up front makes both artifacts agree: a fuzzy entry
 * falls back to the English source until somebody approves it.
 *
 * The copy keeps the original filename because make-php derives the output name
 * from it, so only the directory may differ.
 *
 * @param {{po: string, mo: string, langDir: string, wp: string}} p Locale paths.
 */
function compileFrom( p ) {
	const dir = mkdtempSync( join( tmpdir(), 'i18n-' ) );
	const reviewed = join( dir, basename( p.po ) );

	try {
		run( 'msgattrib', [ '--no-fuzzy', '--no-obsolete', '-o', reviewed, p.po ] );
		run( p.wp, [ 'i18n', 'make-mo', reviewed, p.mo ], { cwd: root } );
		run( p.wp, [ 'i18n', 'make-php', reviewed, p.langDir ], { cwd: root } );
	} finally {
		rmSync( dir, { force: true, recursive: true } );
	}
}

let problems = 0;

for ( const pkg of PACKAGES ) {
	console.log( `\n${ pkg.name } (${ pkg.domain })` );
	const base = paths( pkg, pkg.locales[ 0 ] );

	if ( ! existsSync( base.wp ) ) {
		console.error( `  vendor/bin/wp missing — run: composer install` );
		process.exit( 1 );
	}

	buildPot( pkg, base );
	const potEntries = parsePo( readFileSync( base.pot, 'utf8' ) ).entries.length;
	console.log( `  pot     ${ potEntries } strings` );

	for ( const locale of pkg.locales ) {
		const p = paths( pkg, locale );

		// Fuzzy matching intentionally left on: it is how a reworded string keeps
		// its translation (e.g. "Search..." -> "Search…"). A fuzzy entry is a
		// guess awaiting review, so it must never reach a reader — see
		// compileFrom() for why that takes more than leaving it in the .po.
		run( 'msgmerge', [ '--update', '--backup=none', '--quiet', p.po, p.pot ] );

		compileFrom( p );

		const { entries } = parsePo( readFileSync( p.po, 'utf8' ) );
		const live = entries.filter( ( e ) => ! e.obsolete );
		const fuzzy = live.filter( ( e ) => e.fuzzy ).length;
		const missing = live.filter( ( e ) => ! isTranslated( e ) && ! e.fuzzy ).length;

		let jed = '';
		if ( pkg.jed ) {
			const r = writeJed( p, locale );
			jed = `, jed ${ r.count }`;
		}
		console.log(
			`  ${ locale }  ${ live.length } strings, ${ fuzzy } fuzzy, ${ missing } untranslated${ jed }`
		);
		problems += fuzzy + missing;
	}
}

console.log(
	problems
		? `\n${ problems } entries need attention — run: pnpm i18n:check\n`
		: '\nAll catalogues complete.\n'
);
