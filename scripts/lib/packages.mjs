import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

export const root = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );

/**
 * The one translatable package: this repository, at its root.
 *
 * Kept as a list so the i18n scripts stay byte-identical to the copies in
 * chess-wp-plugin and rockaden-wp — only this file differs between them.
 */
export const PACKAGES = [
	{
		name: 'plugin',
		dir: '.',
		domain: 'rockaden-skolschack',
		// English source, Swedish catalogue (the chess plugin's convention).
		locales: [ 'sv_SE' ],
		exclude: 'build,node_modules,vendor,docs,dist,scripts',
		// No TypeScript yet. When a React admin or block is added, list the file
		// that holds its __() calls here, as chess-wp-plugin does.
		tsSources: [],
		// Flip to true only if a JS build lands and script translations are needed.
		jed: false,
	},
];

export const paths = ( pkg, locale ) => ( {
	pkgDir: join( root, pkg.dir ),
	langDir: join( root, pkg.dir, 'languages' ),
	pot: join( root, pkg.dir, 'languages', `${ pkg.domain }.pot` ),
	po: join( root, pkg.dir, 'languages', `${ pkg.domain }-${ locale }.po` ),
	mo: join( root, pkg.dir, 'languages', `${ pkg.domain }-${ locale }.mo` ),
	php: join( root, pkg.dir, 'languages', `${ pkg.domain }-${ locale }.l10n.php` ),
	json: join( root, pkg.dir, 'languages', `${ pkg.domain }-${ locale }.json` ),
	wp: join( root, pkg.dir, 'vendor', 'bin', 'wp' ),
} );
