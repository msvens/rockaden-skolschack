<?php
/**
 * Access control.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Services;

use Rockaden\Skolschack\PostTypes\School;

defined( 'ABSPATH' ) || exit;

/**
 * Being assigned to a school is the permission.
 *
 * There is deliberately no coordinator role. A role is a bundle of permissions saved onto an
 * account, which then has to be kept in step with the school assignment by hand, and two facts
 * that must agree will eventually disagree. Here the assignment is the only fact and it is
 * answered live, so removing somebody from their last school revokes their access immediately,
 * with no second step for anyone to forget.
 *
 * Administrators are unaffected. They hold the capabilities outright and never lose access by
 * being assigned, or gain a narrower view by not being.
 */
class Capabilities {

	/**
	 * Meta key on a school post listing its coordinators. Not single: a school may have
	 * several, and a person may cover several schools.
	 *
	 * @var string
	 */
	public const META_COORDINATOR = 'rsk_coordinator_id';

	/**
	 * Role created by earlier versions, removed on activation.
	 *
	 * @var string
	 */
	private const LEGACY_ROLE = 'rsk_coordinator';

	/**
	 * See signups.
	 *
	 * @var string
	 */
	public const VIEW_SIGNUPS = 'rsk_view_signups';

	/**
	 * Create and change signups.
	 *
	 * @var string
	 */
	public const EDIT_SIGNUPS = 'rsk_edit_signups';

	/**
	 * Remove signups.
	 *
	 * @var string
	 */
	public const DELETE_SIGNUPS = 'rsk_delete_signups';

	/**
	 * Export signups.
	 *
	 * @var string
	 */
	public const EXPORT_SIGNUPS = 'rsk_export_signups';

	/**
	 * Create and change schools. The primitive capability WordPress generates for the school
	 * post type, used directly so one grant governs both the menu and the type's own screens.
	 *
	 * @var string
	 */
	public const MANAGE_SCHOOLS = 'edit_rsk_schools';

	/**
	 * Change plugin settings.
	 *
	 * @var string
	 */
	public const MANAGE_SETTINGS = 'rsk_manage_settings';

	/**
	 * Guards against a capability check that triggers a query that checks capabilities.
	 *
	 * @var bool
	 */
	private static bool $resolving = false;

	/**
	 * Per-request cache of school assignments, keyed by user id.
	 *
	 * @var array<int, list<int>>
	 */
	private static array $schools_by_user = [];

	/**
	 * Hook up the live capability grant.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', [ self::class, 'grant_to_coordinators' ], 10, 4 );
		add_filter( 'map_meta_cap', [ self::class, 'map_school_caps' ], 10, 4 );
	}

	/**
	 * Teach WordPress that a coordinator owns their school.
	 *
	 * WordPress decides whether a post belongs to somebody by its author, and resolves editing one
	 * into "may edit other people's". Our coordinators are a many-to-many relation held in meta,
	 * which that machinery cannot see, and an author column could not hold several people anyway.
	 *
	 * So the per-post question is answered here instead, by delegating to can_edit_school(). The
	 * point is that our own screens and a plain current_user_can( 'edit_post', $id ) then give the
	 * same answer, rather than each having a private opinion.
	 *
	 * @param array<int, string> $caps    Capabilities the check has been resolved to.
	 * @param string             $cap     Capability being asked about.
	 * @param int                $user_id User being tested.
	 * @param array<int, mixed>  $args    Arguments, the first being the post id.
	 * @return array<int, string> Capabilities required.
	 */
	public static function map_school_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, [ 'edit_post', 'read_post', 'delete_post' ], true ) ) {
			return $caps;
		}

		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;

		if ( $post_id <= 0 || School::POST_TYPE !== get_post_type( $post_id ) ) {
			return $caps;
		}

		// Deleting a school stays with administrators, because removing one is a club decision
		// rather than part of running a group. Editing and reading follow the assignment.
		if ( 'delete_post' === $cap ) {
			return [ self::MANAGE_SCHOOLS ];
		}

		return self::can_edit_school( $post_id, $user_id ) ? [ 'read' ] : [ 'do_not_allow' ];
	}

	/**
	 * Whether somebody may edit one particular school.
	 *
	 * The single definition of that question. Everything else, including the capability mapping
	 * above, delegates here rather than repeating the rule.
	 *
	 * @param int $school_id School post id.
	 * @param int $user_id   User to test. Defaults to the current user.
	 * @return bool True for an administrator, or somebody assigned to this school.
	 */
	public static function can_edit_school( int $school_id, int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, self::MANAGE_SCHOOLS ) ) {
			return true;
		}

		return in_array( $school_id, self::coordinator_school_ids( $user_id ), true );
	}

	/**
	 * Whether somebody has any business on the schools screen at all.
	 *
	 * @return bool True for an administrator, or anyone coordinating at least one school.
	 */
	public static function can_see_any_school(): bool {
		return self::can_see_all_schools() || [] !== self::coordinator_school_ids();
	}

	/**
	 * The schools somebody may see on the schools screen.
	 *
	 * @return array<int, int>|null School post ids, or null meaning no restriction.
	 */
	public static function visible_school_ids(): ?array {
		if ( self::can_see_all_schools() ) {
			return null;
		}

		return self::coordinator_school_ids();
	}

	/**
	 * Every capability this plugin defines.
	 *
	 * @return list<string> Capability names.
	 */
	public static function all(): array {
		return array_merge(
			self::coordinator_caps(),
			[ self::DELETE_SIGNUPS, self::MANAGE_SETTINGS ],
			self::school_caps()
		);
	}

	/**
	 * What being assigned to a school grants.
	 *
	 * Deleting signups is absent on purpose: removing a child's record is an administrator's
	 * decision, not a side effect of running a group.
	 *
	 * @return list<string> Capability names.
	 */
	public static function coordinator_caps(): array {
		return [
			self::VIEW_SIGNUPS,
			self::EDIT_SIGNUPS,
			self::EXPORT_SIGNUPS,
		];
	}

	/**
	 * The primitive capabilities WordPress generates for the school post type.
	 *
	 * Read back from the registered type rather than written out, so the list cannot drift.
	 *
	 * @return list<string> Capability names.
	 */
	public static function school_caps(): array {
		$type = get_post_type_object( School::POST_TYPE );

		if ( ! $type instanceof \WP_Post_Type ) {
			return [ self::MANAGE_SCHOOLS ];
		}

		// Plural names only. The singular ones are meta capabilities that map_meta_cap
		// resolves per post; granting those to a role is meaningless.
		$caps = array_values( (array) $type->cap );
		$caps = array_filter( $caps, static fn ( $cap ): bool => is_string( $cap ) && str_contains( $cap, 'rsk_schools' ) );

		return array_values( array_unique( $caps ) );
	}

	/**
	 * Grant administrators everything, and clear away the role earlier versions created.
	 *
	 * @return void
	 */
	public static function install(): void {
		// Earlier versions shipped a coordinator role. The school assignment replaced it,
		// and leaving it behind would keep granting access nothing shows any more.
		remove_role( self::LEGACY_ROLE );

		$admin = get_role( 'administrator' );
		if ( $admin instanceof \WP_Role ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove the granted capabilities. Called from an explicit uninstall only.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		remove_role( self::LEGACY_ROLE );

		$admin = get_role( 'administrator' );
		if ( $admin instanceof \WP_Role ) {
			foreach ( self::all() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/**
	 * Grant the signup capabilities to anyone assigned to at least one school.
	 *
	 * @param array<string, bool> $allcaps Capabilities the user already has.
	 * @param array<int, string>  $caps    Primitive capabilities being required.
	 * @param array<int, mixed>   $args    Arguments to the original check.
	 * @param \WP_User            $user    User being tested.
	 * @return array<string, bool> Possibly extended capabilities.
	 */
	public static function grant_to_coordinators( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		unset( $args );

		if ( self::$resolving || $user->ID <= 0 ) {
			return $allcaps;
		}

		// Only do the lookup when one of ours is actually being asked for.
		if ( [] === array_intersect( $caps, self::coordinator_caps() ) ) {
			return $allcaps;
		}

		if ( [] === self::coordinator_school_ids( $user->ID ) ) {
			return $allcaps;
		}

		foreach ( self::coordinator_caps() as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * The schools a user coordinates.
	 *
	 * The single source of per-school scoping: every screen, query and export narrows through
	 * this rather than deciding for itself.
	 *
	 * @param int $user_id User to look up. Defaults to the current user.
	 * @return list<int> School post ids, empty when the user coordinates none.
	 */
	public static function coordinator_school_ids( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return [];
		}

		if ( isset( self::$schools_by_user[ $user_id ] ) ) {
			return self::$schools_by_user[ $user_id ];
		}

		// Reentrancy guard: the query below can itself trigger a capability check, and
		// that check would call straight back into here.
		self::$resolving = true;
		$mine            = [];

		foreach ( self::all_school_ids() as $id ) {
			if ( in_array( $user_id, self::coordinators_of( $id ), true ) ) {
				$mine[] = $id;
			}
		}

		self::$resolving                   = false;
		self::$schools_by_user[ $user_id ] = $mine;

		return $mine;
	}

	/**
	 * The users assigned to one school.
	 *
	 * @param int $school_id School post id.
	 * @return list<int> User ids.
	 */
	public static function coordinators_of( int $school_id ): array {
		$raw = get_post_meta( $school_id, self::META_COORDINATOR, false );
		$ids = array_map( 'intval', is_array( $raw ) ? $raw : [] );

		return array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * Every school id, published or draft.
	 *
	 * Fetched whole and filtered in PHP rather than with a meta query. The club runs fewer
	 * than a dozen schools, and WordPress primes the meta cache for them in one query.
	 *
	 * @return list<int> School post ids.
	 */
	private static function all_school_ids(): array {
		$ids = get_posts(
			[
				'post_type'        => School::POST_TYPE,
				'post_status'      => [ 'publish', 'draft' ],
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			]
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Forget the cached assignments, after a school's coordinators change.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$schools_by_user = [];
	}

	/**
	 * Whether the current user sees every school rather than only their own.
	 *
	 * @return bool True for administrators and anyone else granted school management.
	 */
	public static function can_see_all_schools(): bool {
		return current_user_can( self::MANAGE_SCHOOLS );
	}
}
