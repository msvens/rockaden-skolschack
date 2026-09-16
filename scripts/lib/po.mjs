/**
 * Minimal PO reader.
 *
 * Only what the i18n scripts need: entries with their translation, flags and
 * source references. Deliberately not a full gettext implementation — writing
 * PO/MO files is left to msgmerge/msgfmt and wp-cli, which do it correctly.
 */

/** Unescape a gettext string literal. */
function unescape( raw ) {
	return raw
		.replace( /\\n/g, '\n' )
		.replace( /\\t/g, '\t' )
		.replace( /\\r/g, '\r' )
		.replace( /\\"/g, '"' )
		.replace( /\\\\/g, '\\' );
}

/** Join a run of `"..."` continuation lines into one value. */
function joinLiterals( lines ) {
	return lines
		.map( ( l ) => {
			const m = l.match( /"((?:[^"\\]|\\.)*)"/ );
			return m ? unescape( m[ 1 ] ) : '';
		} )
		.join( '' );
}

/**
 * Parse a PO file.
 *
 * @param {string} text Raw PO contents.
 * @return {{header: string, entries: Array<{msgid: string, msgctxt: string|null,
 *   msgstr: string, plurals: string[], fuzzy: boolean, obsolete: boolean,
 *   references: string[]}>}} Parsed catalogue.
 */
export function parsePo( text ) {
	const entries = [];
	let header = '';

	for ( const block of text.split( /\n[ \t]*\n/ ) ) {
		const lines = block.split( '\n' );
		const obsolete = lines.some( ( l ) => l.startsWith( '#~' ) );
		// Strip the obsolete marker so one parser handles both forms.
		const body = lines.map( ( l ) => l.replace( /^#~\s?/, '' ) );

		const fuzzy = body.some( ( l ) => /^#,.*\bfuzzy\b/.test( l ) );
		const references = body
			.filter( ( l ) => l.startsWith( '#: ' ) )
			.flatMap( ( l ) => l.slice( 3 ).trim().split( /\s+/ ) )
			.filter( Boolean );

		let msgctxt = null;
		let msgid = null;
		let msgstr = null;
		const plurals = [];

		for ( let i = 0; i < body.length; i++ ) {
			const line = body[ i ];
			const key = line.match( /^(msgctxt|msgid|msgid_plural|msgstr(?:\[(\d+)\])?)\s/ );
			if ( ! key ) continue;

			// Collect this line plus any bare "..." continuations.
			const run = [ line ];
			while ( i + 1 < body.length && /^\s*"/.test( body[ i + 1 ] ) ) {
				run.push( body[ ++i ] );
			}
			const value = joinLiterals( run );

			if ( key[ 1 ] === 'msgctxt' ) msgctxt = value;
			else if ( key[ 1 ] === 'msgid' ) msgid = value;
			else if ( key[ 1 ].startsWith( 'msgstr' ) ) {
				if ( key[ 2 ] === undefined ) msgstr = value;
				else plurals[ Number( key[ 2 ] ) ] = value;
			}
		}

		if ( msgid === null ) continue;
		if ( msgid === '' && ! obsolete ) {
			header = msgstr ?? '';
			continue;
		}
		entries.push( {
			msgid,
			msgctxt,
			msgstr: msgstr ?? '',
			plurals,
			fuzzy,
			obsolete,
			references,
		} );
	}

	return { header, entries };
}

/**
 * A translation is only usable at runtime if it is present AND not fuzzy —
 * msgfmt omits fuzzy entries from the MO, so they render as the source string.
 *
 * @param {{msgstr: string, plurals: string[], fuzzy: boolean}} entry PO entry.
 * @return {boolean} Whether the entry contributes a translation.
 */
export function isTranslated( entry ) {
	if ( entry.fuzzy ) return false;
	if ( entry.plurals.length ) return entry.plurals.every( Boolean );
	return entry.msgstr !== '';
}

/** Header field lookup, e.g. `Language`. */
export function headerValue( header, field ) {
	const m = header.match( new RegExp( `^${ field }:\\s*(.*)$`, 'mi' ) );
	return m ? m[ 1 ].trim() : '';
}
