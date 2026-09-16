<?php
/**
 * Custom table definitions and installation.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Signups and guardians live in their own tables rather than in posts and post meta.
 *
 * A child has one or more guardians, which is a real relation; the duplicate rule is a unique
 * index the database itself enforces; and children's personal data stays out of the shared
 * posts tables where every other plugin can read it. Schools, which hold nothing personal, are
 * an ordinary post type instead.
 */
class Schema {

	/**
	 * Option holding the schema version last installed.
	 *
	 * @var string
	 */
	public const VERSION_OPTION = 'rockaden_skolschack_db_version';

	/**
	 * Bump when a table definition changes, not when the plugin version changes.
	 *
	 * 2: legacy_ids, recording which source rows were collapsed into a single signup on import.
	 * 3: needs_attention and flags, so the admin list can surface rows a person must look at.
	 *
	 * @var string
	 */
	public const DB_VERSION = '3';

	/**
	 * Fully qualified name of the signups table.
	 *
	 * @return string Table name including the site prefix.
	 */
	public static function signups_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'rsk_signups';
	}

	/**
	 * Fully qualified name of the guardians table.
	 *
	 * @return string Table name including the site prefix.
	 */
	public static function guardians_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'rsk_guardians';
	}

	/**
	 * Create or update both tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$signups         = self::signups_table();
		$guardians       = self::guardians_table();

		// dbDelta is whitespace-sensitive and parses this text rather than executing it
		// blindly: two spaces after PRIMARY KEY, one space around the key definitions,
		// and no backticks around the table name. No ENUM columns, because dbDelta
		// misreads them and reissues an ALTER on every run.
		$sql = "CREATE TABLE {$signups} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			term_year smallint(5) unsigned NOT NULL,
			term_season varchar(2) NOT NULL DEFAULT '',
			school_id bigint(20) unsigned NOT NULL DEFAULT 0,
			school_name varchar(100) NOT NULL DEFAULT '',
			child_first_name varchar(50) NOT NULL DEFAULT '',
			child_last_name varchar(50) NOT NULL DEFAULT '',
			child_birthdate date DEFAULT NULL,
			child_personnummer char(12) DEFAULT NULL,
			child_gender varchar(1) NOT NULL DEFAULT '',
			child_class varchar(50) NOT NULL DEFAULT '',
			street varchar(100) NOT NULL DEFAULT '',
			postal_code varchar(5) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			identity_hash char(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_via varchar(20) NOT NULL DEFAULT 'form',
			legacy_ids varchar(255) NOT NULL DEFAULT '',
			needs_attention tinyint(1) NOT NULL DEFAULT 0,
			flags varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY term_identity (term_year,term_season,identity_hash),
			KEY school_term (school_id,term_year,term_season),
			KEY needs_attention (needs_attention)
		) {$charset_collate};";

		dbDelta( $sql );

		$sql = "CREATE TABLE {$guardians} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			signup_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(100) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			email varchar(100) NOT NULL DEFAULT '',
			is_primary tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY signup_id (signup_id)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Reinstall the tables when the stored schema version is behind.
	 *
	 * Runs on every load so a plugin update applies schema changes without the site owner
	 * having to deactivate and reactivate. dbDelta only issues the statements it needs.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( self::VERSION_OPTION, '' );

		if ( self::DB_VERSION === (string) $installed ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether both tables exist.
	 *
	 * @return bool True when the schema is present.
	 */
	public static function is_installed(): bool {
		global $wpdb;

		foreach ( [ self::signups_table(), self::guardians_table() ] as $table ) {
			$found = wp_cache_get( 'table_exists_' . $table, 'rockaden_skolschack' );

			if ( false === $found ) {
				$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
				$found = is_string( $found ) ? $found : '';
				wp_cache_set( 'table_exists_' . $table, $found, 'rockaden_skolschack' );
			}

			if ( '' === $found ) {
				return false;
			}
		}

		return true;
	}
}
