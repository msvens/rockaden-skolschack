<?php
/**
 * The plugin's admin menu.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Services\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * One top-level menu holding everything this plugin adds.
 *
 * The school post type sets show_in_menu to false and is attached here by hand, because passing
 * a parent slug to register_post_type() depends on the parent menu already existing and fails
 * silently when it does not.
 */
class AdminMenu {

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_pages' ] );
	}

	/**
	 * Add the top-level menu and its submenus.
	 *
	 * @return void
	 */
	public static function add_pages(): void {
		// Viewing signups is what a coordinator comes here for, so it gates the menu itself.
		// Anything narrower would hide the plugin entirely from the people who use it most.
		add_menu_page(
			__( 'Skolschack', 'rockaden-skolschack' ),
			__( 'Skolschack', 'rockaden-skolschack' ),
			Capabilities::VIEW_SIGNUPS,
			SignupsPage::PAGE_SLUG,
			[ SignupsPage::class, 'render' ],
			'dashicons-groups',
			30
		);

		// The entry sharing the parent's slug must come first. WordPress inserts an
		// auto-generated copy of the parent as the first submenu the moment a submenu with a
		// different slug is added, and that copy repeats the menu title.
		add_submenu_page(
			SignupsPage::PAGE_SLUG,
			__( 'Signups', 'rockaden-skolschack' ),
			__( 'Signups', 'rockaden-skolschack' ),
			Capabilities::VIEW_SIGNUPS,
			SignupsPage::PAGE_SLUG,
			[ SignupsPage::class, 'render' ]
		);

		// Viewing is gated by coordinating at least one school, which the page itself checks;
		// the menu uses the capability every signed-in coordinator already has so the entry is
		// not hidden from the people who need it.
		add_submenu_page(
			SignupsPage::PAGE_SLUG,
			__( 'Schools', 'rockaden-skolschack' ),
			__( 'Schools', 'rockaden-skolschack' ),
			Capabilities::VIEW_SIGNUPS,
			SchoolsPage::PAGE_SLUG,
			[ SchoolsPage::class, 'render' ]
		);

		add_submenu_page(
			SignupsPage::PAGE_SLUG,
			__( 'Skolschack settings', 'rockaden-skolschack' ),
			__( 'Settings', 'rockaden-skolschack' ),
			Capabilities::MANAGE_SETTINGS,
			SettingsPage::PAGE_SLUG,
			[ SettingsPage::class, 'render_page' ]
		);
	}
}
