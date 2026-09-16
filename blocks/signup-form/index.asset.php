<?php
/**
 * Script dependencies and version for the signup-form editor script.
 *
 * Hand-authored, because this plugin has no build step. WordPress reads it when registering the
 * block's editorScript from block.json, so the editor script loads after its wp.* dependencies.
 * Mirrors the club theme's blocks.
 *
 * @package RockadenSkolschack
 */

return [
	'dependencies' => [ 'wp-blocks', 'wp-element', 'wp-block-editor' ],
	// Version by modification time so editing index.js always busts the editor cache.
	'version'      => (string) filemtime( __DIR__ . '/index.js' ),
];
