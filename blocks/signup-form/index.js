/**
 * Editor preview for the signup form block.
 *
 * Vanilla JavaScript over window.wp, no JSX and no build step, mirroring the club theme's blocks.
 * save() returns null because the form is rendered by render.php on the server; the editor shows a
 * read-only stand-in using the same class names so the preview matches the page.
 *
 * The block has no settings. A heading and an introduction above the form are a heading block and
 * a paragraph block, written and placed by whoever builds the page, rather than two text boxes in
 * a sidebar that can only ever produce one arrangement.
 */
( function ( blocks, element, blockEditor ) {
	'use strict';

	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;

	blocks.registerBlockType( 'rockaden-skolschack/signup-form', {
		edit: function () {
			return el(
				'div',
				useBlockProps( { className: 'rsk-signup-block' } ),
				el(
					'div',
					{ className: 'rsk-signup__inner' },
					el(
						'p',
						{ className: 'rsk-signup__hint' },
						'The signup form is rendered on the page itself: the child, an address, a school and one or two guardians.'
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor ) );
