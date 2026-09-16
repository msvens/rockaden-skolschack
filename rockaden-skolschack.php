<?php
/**
 * Plugin Name: Skolschack Signups
 * Plugin URI:  https://github.com/msvens/rockaden-skolschack
 * Description: Signup management for school chess groups: a public registration form, per-school coordinators, and lists for the people who run the groups.
 * Version:     0.1.0
 * Author:      msvens
 * Text Domain: rockaden-skolschack
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License:     MIT
 *
 * @package RockadenSkolschack
 */

use Rockaden\Skolschack\Admin\AdminColumns;
use Rockaden\Skolschack\Admin\AdminMenu;
use Rockaden\Skolschack\Admin\SchoolActions;
use Rockaden\Skolschack\Admin\SettingsPage;
use Rockaden\Skolschack\Admin\SignupActions;
use Rockaden\Skolschack\Admin\SignupsExport;
use Rockaden\Skolschack\Data\Schema;
use Rockaden\Skolschack\PostTypes\School;
use Rockaden\Skolschack\Rest\SignupController;
use Rockaden\Skolschack\Services\Capabilities;

defined( 'ABSPATH' ) || exit;

define( 'RSK_VERSION', '0.1.0' );
define( 'RSK_PLUGIN_FILE', __FILE__ );
define( 'RSK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RSK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// GitHub-based update checker. Reads release assets from this plugin's
// repository and lets WordPress show the standard "update available" UI.
// Pre-releases on GitHub are skipped automatically.
if ( file_exists( RSK_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once RSK_PLUGIN_DIR . 'vendor/autoload.php';

	$rsk_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/msvens/rockaden-skolschack/',
		__FILE__,
		'rockaden-skolschack'
	);
	// REQUIRE_RELEASE_ASSETS: a release without a matching asset must never be
	// replaced by its auto-generated source zip, which lacks vendor/ and would
	// install a broken copy. (The constant is inherited from Vcs\Api, so the
	// versioned class name is still not spelled out).
	$rsk_vcs_api = $rsk_update_checker->getVcsApi();
	if ( method_exists( $rsk_vcs_api, 'enableReleaseAssets' ) ) {
		$rsk_vcs_api->enableReleaseAssets( '/rockaden-skolschack\.zip$/', $rsk_vcs_api::REQUIRE_RELEASE_ASSETS );
	}
}

/**
 * PSR-4-style autoloader for plugin classes under the Rockaden\Skolschack namespace.
 */
spl_autoload_register(
	function ( string $class_name ): void {
		$prefix = 'Rockaden\\Skolschack\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = RSK_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// Load translations (English-source msgids; ships sv_SE for Swedish).
// Direct load_textdomain on init priority 1 — load_plugin_textdomain is not
// reliable in WP 6.5+ for non-global plugin language directories, and the
// textdomain must be loaded before anything rendering on init.
add_action(
	'init',
	function (): void {
		$locale = determine_locale();
		$mofile = RSK_PLUGIN_DIR . 'languages/rockaden-skolschack-' . $locale . '.mo';
		if ( file_exists( $mofile ) ) {
			load_textdomain( 'rockaden-skolschack', $mofile, $locale );
		}
	},
	1
);

add_action( 'init', [ School::class, 'register' ] );

// The one public surface: a block holding the signup form, and the endpoint behind it.
add_action(
	'init',
	function (): void {
		register_block_type( RSK_PLUGIN_DIR . 'blocks/signup-form' );
	}
);

SignupController::register();
AdminMenu::register();
SettingsPage::register();
SchoolActions::register();
AdminColumns::register();
SignupsExport::register();
SignupActions::register();
Capabilities::register();

// Applies schema changes after a plugin update without needing a reactivation.
// dbDelta only issues the statements it actually needs.
add_action( 'plugins_loaded', [ Schema::class, 'maybe_upgrade' ] );

register_activation_hook(
	RSK_PLUGIN_FILE,
	function (): void {
		// The post type first: Capabilities reads the capabilities WordPress generates
		// for it, so it has to exist before the roles are built.
		register_post_type( School::POST_TYPE, School::post_type_args() );
		Schema::install();
		Capabilities::install();
		flush_rewrite_rules();
	}
);

register_deactivation_hook( RSK_PLUGIN_FILE, 'flush_rewrite_rules' );

/*
 * Still to come, phase by phase: the WP-CLI importer, the signup list and export,
 * and the public form block with its REST route. See CLAUDE.md for the conventions.
 */
