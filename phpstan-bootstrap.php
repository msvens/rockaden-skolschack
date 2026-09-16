<?php
/**
 * PHPStan bootstrap: define runtime constants that are set in the plugin
 * bootstrap, so static analysis sees them without running WordPress.
 *
 * @package RockadenSkolschack
 */

define( 'RSK_VERSION', '0.1.0' );
define( 'RSK_PLUGIN_FILE', __DIR__ . '/rockaden-skolschack.php' );
define( 'RSK_PLUGIN_DIR', __DIR__ . '/' );
define( 'RSK_PLUGIN_URL', 'https://example.com/wp-content/plugins/rockaden-skolschack/' );

// Defined by wp-config.php at runtime; the importer opens a second connection with them.
define( 'DB_USER', 'user' );
define( 'DB_PASSWORD', 'password' );
define( 'DB_HOST', 'localhost' );
