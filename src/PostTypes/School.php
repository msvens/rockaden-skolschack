<?php
/**
 * The school post type.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\PostTypes;

use Rockaden\Skolschack\Admin\SettingsPage;
use Rockaden\Skolschack\Services\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Schools as a post type, deliberately with no front end of any kind.
 *
 * A school record holds nothing personal: its name, when and where the group meets, the term fee
 * and which user coordinates it. All of that is already public on the signup form. That is why
 * schools may live in the posts tables while signups may not.
 */
class School {

	/**
	 * Post type name.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'rsk_school';

	/**
	 * Meta key for the weekday the group meets, stored as an ISO number, 1 = Monday.
	 *
	 * @var string
	 */
	public const META_WEEKDAY = 'rsk_weekday';

	/**
	 * Meta key for the time the session starts, as HH:MM.
	 *
	 * @var string
	 */
	public const META_TIME_START = 'rsk_time_start';

	/**
	 * Meta key for the time the session ends, as HH:MM.
	 *
	 * @var string
	 */
	public const META_TIME_END = 'rsk_time_end';

	/**
	 * Meta key for the contact line printed to guardians.
	 *
	 * @var string
	 */
	public const META_CONTACT = 'rsk_contact';

	/**
	 * Meta key for free-text notes about anything irregular.
	 *
	 * @var string
	 */
	public const META_NOTE = 'rsk_note';

	/**
	 * Meta key for the room.
	 *
	 * @var string
	 */
	public const META_ROOM = 'rsk_room';

	/**
	 * Meta key for the term fee in kronor.
	 *
	 * @var string
	 */
	public const META_FEE = 'rsk_fee';

	/**
	 * Register the post type and its meta.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_type( self::POST_TYPE, self::post_type_args() );
		self::register_meta();
	}

	/**
	 * Arguments for register_post_type().
	 *
	 * Kept separate so activation can register the type before flushing rewrite rules.
	 *
	 * @return array<string, mixed> Registration arguments.
	 */
	public static function post_type_args(): array {
		return [
			'labels'              => [
				'name'          => __( 'Schools', 'rockaden-skolschack' ),
				'singular_name' => __( 'School', 'rockaden-skolschack' ),
			],
			// Not public in any sense: no front-end URL, no archive, no site search, no nav
			// menu entry, no REST endpoint.
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_in_nav_menus'   => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'show_in_rest'        => false,
			// No WordPress screens at all. A school is not an article, and once coordinators
			// can reach one, the post list would show them every school and the post editor
			// would be the wrong shape for five fields. Admin\SchoolsPage replaces both.
			'show_ui'             => false,
			'show_in_menu'        => false,
			'supports'            => [ 'title' ],
			// A real capability type rather than a hand-written map. Pointing every meta
			// capability at one name makes WordPress register that name as an alias for
			// edit_post, after which checking it without a post id always fails.
			'capability_type'     => [ 'rsk_school', 'rsk_schools' ],
			'map_meta_cap'        => true,
		];
	}

	/**
	 * Register the school meta fields.
	 *
	 * Every field carries a sanitize callback as well as an auth callback. The chess plugin
	 * leaves sanitising to its REST layer; there is no such single write path here.
	 *
	 * @return void
	 */
	private static function register_meta(): void {
		// Per post rather than a flat capability: a coordinator may write their own school's
		// meta and nobody else's.
		$auth = static function ( $allowed, $meta_key, $post_id ): bool {
			unset( $allowed, $meta_key );

			return Capabilities::can_edit_school( (int) $post_id );
		};

		$text_fields = [
			self::META_WEEKDAY,
			self::META_TIME_START,
			self::META_TIME_END,
			// Free text on purpose: a room is a code, a name, a floor or a landmark,
			// and there are too many shapes to model.
			self::META_ROOM,
			// One line, written by the club and printed verbatim. A school can have several
			// coordinators and a parent wants one number to call, so this is not derived from
			// whoever happens to be assigned.
			self::META_CONTACT,
			// Text rather than a number so that blank can mean "use the club default"
			// while an explicit zero still means a group that costs nothing.
			self::META_FEE,
			// Where everything irregular goes, so the structured fields stay clean:
			// a second session for younger children, a later start date, a room not
			// yet decided.
			self::META_NOTE,
		];

		foreach ( $text_fields as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				[
					'single'            => true,
					'show_in_rest'      => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => self::META_NOTE === $key ? 'sanitize_textarea_field' : 'sanitize_text_field',
					'auth_callback'     => $auth,
				]
			);
		}

		// Not single: a school may have several coordinators, and a coordinator several
		// schools. The assignment is what grants access, so it is the relation itself.
		register_post_meta(
			self::POST_TYPE,
			Capabilities::META_COORDINATOR,
			[
				'single'            => false,
				'show_in_rest'      => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
			]
		);
	}

	/**
	 * Who a guardian should contact about this school.
	 *
	 * @param int $school_id School post id.
	 * @return string The school's contact line, or the club's fallback when it has none.
	 */
	public static function contact( int $school_id ): string {
		$own = trim( (string) get_post_meta( $school_id, self::META_CONTACT, true ) );

		if ( '' !== $own ) {
			return $own;
		}

		return trim( (string) SettingsPage::get()['notification_email'] );
	}

	/**
	 * The term fee that applies to a school, in kronor.
	 *
	 * A blank school fee inherits the club-wide default, so nobody types the same number
	 * nine times. An explicit value, zero included, always wins.
	 *
	 * @param int $school_id School post id.
	 * @return int Fee in kronor.
	 */
	public static function fee( int $school_id ): int {
		$own = get_post_meta( $school_id, self::META_FEE, true );
		$own = is_string( $own ) ? trim( $own ) : '';

		if ( '' !== $own && is_numeric( $own ) ) {
			return (int) $own;
		}

		$settings = SettingsPage::get();

		return (int) $settings['default_fee'];
	}

	/**
	 * Whether a school uses the club default rather than its own fee.
	 *
	 * @param int $school_id School post id.
	 * @return bool True when the fee is inherited.
	 */
	public static function fee_is_inherited( int $school_id ): bool {
		$own = get_post_meta( $school_id, self::META_FEE, true );
		$own = is_string( $own ) ? trim( $own ) : '';

		return '' === $own || ! is_numeric( $own );
	}

	/**
	 * Schools that are open for signups, oldest name first.
	 *
	 * Draft is the club's way of closing a school without losing its history, so only
	 * published schools ever reach the public form.
	 *
	 * @return list<\WP_Post> Published schools.
	 */
	public static function open_for_signups(): array {
		$posts = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			]
		);

		return $posts;
	}

	/**
	 * Weekday numbers mapped to their names in the current locale.
	 *
	 * Taken from WordPress rather than translated here, so the names follow the site
	 * language without this plugin shipping a single weekday string.
	 *
	 * @return array<int, string> ISO weekday number to name, 1 = Monday.
	 */
	public static function weekday_labels(): array {
		global $wp_locale;

		$labels = [];
		for ( $iso = 1; $iso <= 7; $iso++ ) {
			$labels[ $iso ] = $wp_locale instanceof \WP_Locale
				? $wp_locale->get_weekday( 7 === $iso ? 0 : $iso )
				: (string) $iso;
		}

		return $labels;
	}

	/**
	 * When a school's group meets, as one readable line.
	 *
	 * @param int $school_id School post id.
	 * @return string For example "tisdag 14:20-15:05", or an empty string when unset.
	 */
	public static function meeting_time( int $school_id ): string {
		$iso   = (int) get_post_meta( $school_id, self::META_WEEKDAY, true );
		$start = (string) get_post_meta( $school_id, self::META_TIME_START, true );
		$end   = (string) get_post_meta( $school_id, self::META_TIME_END, true );

		$labels = self::weekday_labels();
		$parts  = [];

		if ( isset( $labels[ $iso ] ) ) {
			$parts[] = $labels[ $iso ];
		}

		if ( '' !== $start ) {
			$parts[] = '' !== $end ? $start . '-' . $end : $start;
		}

		return implode( ' ', $parts );
	}

	/**
	 * Normalise a submitted time to HH:MM, or an empty string when it is not a time.
	 *
	 * @param string $value Raw submitted value.
	 * @return string Normalised time.
	 */
	public static function normalise_time( string $value ): string {
		$value = trim( $value );

		if ( 1 !== preg_match( '/^([01]?[0-9]|2[0-3])[:.]?([0-5][0-9])$/', $value, $m ) ) {
			return '';
		}

		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}
}
