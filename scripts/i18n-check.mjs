/**
 * Verify the translation catalogues are complete and consistent.
 *
 *   pnpm i18n:check
 *
 * Pure Node — no Docker, no gettext, no composer. That is deliberate: this runs
 * in CI and in `pnpm run check`, while regeneration (`pnpm i18n`) needs tools a
 * CI box shouldn't have to install.
 *
 * What it catches, and why each one exists:
 *   - untranslated or fuzzy entries    → these render as the source string
 *   - .mo / .l10n.php behind the .po   → the bug that shipped English to Swedish
 *                                        visitors when only the .po was edited
 *   - .json disagreeing with the .po   → the two drifted for months
 *
 * What it does NOT catch: a brand-new source string that was never extracted.
 * That needs `pnpm i18n`.
 */
import { existsSync, readFileSync } from 'fs';
import { relative } from 'path';
import { PACKAGES, paths, root } from './lib/packages.mjs';
import { parsePo, isTranslated } from './lib/po.mjs';

/** Read a WP 6.5 .l10n.php catalogue without executing arbitrary code. */
function readL10nPhp( file ) {
	const text = readFileSync( file, 'utf8' );
	const body = text.slice( text.indexOf( "'messages'" ) );
	const out = new Map();
	// Entries look like 'msgid'=>'msgstr' with PHP single-quote escaping.
	const re = /'((?:[^'\\]|\\.)*)'\s*=>\s*'((?:[^'\\]|\\.)*)'/g;
	const unq = ( s ) => s.replace( /\\'/g, "'" ).replace( /\\\\/g, '\\' );
	let m;
	while ( ( m = re.exec( body ) ) ) out.set( unq( m[ 1 ] ), unq( m[ 2 ] ) );
	return out;
}

/** Read the string table out of a binary MO file. */
function readMo( file ) {
	const buf = readFileSync( file );
	const magic = buf.readUInt32LE( 0 );
	const le = magic === 0x950412de;
	if ( ! le && magic !== 0xde120495 ) throw new Error( `not a MO file: ${ file }` );
	const u32 = ( o ) => ( le ? buf.readUInt32LE( o ) : buf.readUInt32BE( o ) );
	const count = u32( 8 );
	const origOff = u32( 12 );
	const transOff = u32( 16 );
	const out = new Map();
	for ( let i = 0; i < count; i++ ) {
		const kLen = u32( origOff + i * 8 );
		const kOff = u32( origOff + i * 8 + 4 );
		const vLen = u32( transOff + i * 8 );
		const vOff = u32( transOff + i * 8 + 4 );
		const key = buf.toString( 'utf8', kOff, kOff + kLen );
		const val = buf.toString( 'utf8', vOff, vOff + vLen );
		// A plural entry is stored as msgid\0msgid_plural against
		// msgstr[0]\0msgstr[1]. Key it by the singular and keep the first form, so
		// it lines up with how the .po side is read.
		const singular = key.split( '\u0000' )[ 0 ];
		const first = val.split( '\u0000' )[ 0 ];
		if ( singular !== '' ) out.set( singular, first );
	}
	return out;
}

const rel = ( f ) => relative( root, f );
let failures = 0;
const fail = ( msg ) => {
	console.error( `  ✗ ${ msg }` );
	failures++;
};

for ( const pkg of PACKAGES ) {
	console.log( `\n${ pkg.name } (${ pkg.domain })` );

	for ( const locale of pkg.locales ) {
		const p = paths( pkg, locale );
		if ( ! existsSync( p.po ) ) {
			fail( `${ rel( p.po ) } is missing` );
			continue;
		}

		const { entries } = parsePo( readFileSync( p.po, 'utf8' ) );
		const live = entries.filter( ( e ) => ! e.obsolete && ! e.msgctxt );
		const all = entries.filter( ( e ) => ! e.obsolete );

		// 1. Completeness.
		const fuzzy = all.filter( ( e ) => e.fuzzy );
		const untranslated = all.filter( ( e ) => ! e.fuzzy && ! isTranslated( e ) );
		if ( fuzzy.length ) {
			fail( `${ locale }: ${ fuzzy.length } fuzzy entries — msgfmt omits these, so they render untranslated` );
			fuzzy.slice( 0, 5 ).forEach( ( e ) => console.error( `      fuzzy: ${ e.msgid.slice( 0, 60 ) }` ) );
		}
		if ( untranslated.length ) {
			fail( `${ locale }: ${ untranslated.length } untranslated entries` );
			untranslated.slice( 0, 5 ).forEach( ( e ) => console.error( `      empty: ${ e.msgid.slice( 0, 60 ) }` ) );
		}

		// 2. Derived artifacts must agree with the .po.
		//
		// WP-CLI splits catalogues by where a string is used: make-mo/make-php
		// emit only PHP-referenced strings, while JS strings go to the jed file.
		// A string used by both appears in both. So compare each artifact against
		// its own slice rather than against every translated entry.
		const isPhp = ( e ) => e.references.some( ( r ) => /\.php(:|$)/.test( r ) );
		const isJs = ( e ) => e.references.some( ( r ) => /\.(ts|tsx|js|jsx)(:|$)/.test( r ) );

		const expected = new Map();
		for ( const e of all ) {
			if ( e.msgctxt || ! isTranslated( e ) || ! isPhp( e ) ) continue;
			expected.set( e.msgid, e.plurals.length ? e.plurals[ 0 ] : e.msgstr );
		}

		for ( const [ label, file, reader ] of [
			[ 'mo', p.mo, readMo ],
			[ 'l10n.php', p.php, readL10nPhp ],
		] ) {
			if ( ! existsSync( file ) ) {
				fail( `${ rel( file ) } is missing — run: pnpm i18n` );
				continue;
			}
			const got = reader( file );
			const behind = [ ...expected.keys() ].filter( ( k ) => ! got.has( k ) );
			const differs = [ ...expected.keys() ].filter(
				( k ) => got.has( k ) && got.get( k ) !== expected.get( k )
			);
			if ( behind.length || differs.length ) {
				fail(
					`${ label } is out of date with the .po — ${ behind.length } missing, ` +
					`${ differs.length } differing. Run: pnpm i18n`
				);
				behind.slice( 0, 3 ).forEach( ( k ) => console.error( `      missing: ${ k.slice( 0, 60 ) }` ) );
			}
		}

		// 3. The plugin's jed file.
		if ( pkg.jed ) {
			if ( ! existsSync( p.json ) ) {
				fail( `${ rel( p.json ) } is missing — run: pnpm i18n` );
			} else {
				const jed = JSON.parse( readFileSync( p.json, 'utf8' ) );
				const msgs = jed?.locale_data?.messages ?? {};
				// The jed's slice: translated, context-free, JS-referenced.
				const jsExpected = new Map(
					live
						.filter( ( e ) => isJs( e ) && isTranslated( e ) )
						.map( ( e ) => [ e.msgid, e.plurals.length ? e.plurals[ 0 ] : e.msgstr ] )
				);
				const conflicts = [];
				for ( const [ k, v ] of Object.entries( msgs ) ) {
					if ( k === '' ) continue;
					const val = Array.isArray( v ) ? v[ 0 ] : v;
					if ( jsExpected.has( k ) && jsExpected.get( k ) !== val ) conflicts.push( k );
				}
				const jsMissing = [ ...jsExpected.keys() ].filter( ( k ) => ! ( k in msgs ) );
				if ( conflicts.length ) {
					fail( `${ rel( p.json ) } disagrees with the .po on ${ conflicts.length } strings` );
					conflicts.slice( 0, 5 ).forEach( ( k ) => console.error( `      differs: ${ k.slice( 0, 60 ) }` ) );
				}
				if ( jsMissing.length ) {
					fail( `${ rel( p.json ) } is missing ${ jsMissing.length } JS strings — run: pnpm i18n` );
				}
			}
		}

		if ( ! failures ) {
			console.log( `  ✓ ${ locale }: ${ all.length } strings, all translated and in sync` );
		}
	}
}

if ( failures ) {
	console.error( `\n${ failures } problem(s). See above.\n` );
	process.exit( 1 );
}
console.log( '\nTranslations are complete and consistent.\n' );
