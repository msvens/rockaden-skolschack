<?php
/**
 * Saving and deleting a school.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Data\SignupRepository;
use Rockaden\Skolschack\PostTypes\School;
use Rockaden\Skolschack\Services\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The write side of the schools screen.
 *
 * Adding a school is an administrator's decision, because it says the club now runs chess there.
 * Maintaining one belongs to whoever runs it. Removing one is refused outright while any child's
 * record points at it, so nobody can delete the thing the history depends on.
 */
class SchoolActions {

	/**
	 * Action name for saving.
	 *
	 * @var string
	 */
	public const SAVE = 'rsk_save_school';

	/**
	 * Action name for deleting.
	 *
	 * @var string
	 */
	public const DELETE = 'rsk_delete_school';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	public const NONCE = 'rsk_school_nonce';

	/**
	 * Nonce action behind the "saved" marker on the redirect.
	 *
	 * @var string
	 */
	public const SAVED_NOTICE = 'rsk_school_saved';

	/**
	 * Hook the handlers up.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::SAVE, [ self::class, 'handle_save' ] );
		add_action( 'admin_post_' . self::DELETE, [ self::class, 'handle_delete' ] );
	}

	/**
	 * The transient key holding a failed attempt.
	 *
	 * @param int $school_id School post id, zero when adding.
	 * @return string Cache key.
	 */
	public static function failure_key( int $school_id ): string {
		return sprintf( 'rsk_school_failed_%d_%d', get_current_user_id(), $school_id );
	}

	/**
	 * Create or update a school.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::SAVE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'rockaden-skolschack' ) );
		}

		$raw       = array_map( 'wp_unslash', (array) $_POST );
		$school_id = absint( $raw['school'] ?? 0 );
		$name      = trim( sanitize_text_field( (string) ( $raw['school_name'] ?? '' ) ) );

		if ( 0 === $school_id ) {
			if ( ! current_user_can( Capabilities::MANAGE_SCHOOLS ) ) {
				wp_die( esc_html__( 'Only an administrator can add a school.', 'rockaden-skolschack' ) );
			}
		} elseif ( ! Capabilities::can_edit_school( $school_id ) ) {
			wp_die( esc_html__( 'That school does not exist, or you do not coordinate it.', 'rockaden-skolschack' ) );
		}

		if ( '' === $name ) {
			set_transient(
				self::failure_key( $school_id ),
				[ __( 'A school needs a name.', 'rockaden-skolschack' ) ],
				5 * MINUTE_IN_SECONDS
			);

			self::redirect_back( $school_id, false );
		}

		$status = 'draft' === ( $raw['status'] ?? '' ) ? 'draft' : 'publish';

		if ( 0 === $school_id ) {
			$school_id = (int) wp_insert_post(
				[
					'post_type'   => School::POST_TYPE,
					'post_title'  => $name,
					'post_status' => $status,
				],
				true
			);
		} else {
			wp_update_post(
				[
					'ID'          => $school_id,
					'post_title'  => $name,
					'post_status' => $status,
				]
			);
		}

		self::save_meta( $school_id, $raw );
		self::save_coordinators( $school_id, $raw );

		self::redirect_back( $school_id, true );
	}

	/**
	 * Remove a school, if nothing points at it.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::DELETE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'rockaden-skolschack' ) );
		}

		if ( ! current_user_can( Capabilities::MANAGE_SCHOOLS ) ) {
			wp_die( esc_html__( 'Only an administrator can delete a school.', 'rockaden-skolschack' ) );
		}

		$raw       = array_map( 'wp_unslash', (array) $_POST );
		$school_id = absint( $raw['school'] ?? 0 );
		$post      = get_post( $school_id );

		if ( ! $post instanceof \WP_Post || School::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'That school does not exist.', 'rockaden-skolschack' ) );
		}

		if ( '1' !== sanitize_text_field( (string) ( $raw['confirm'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Tick the confirmation box if you really mean to delete this school.', 'rockaden-skolschack' ) );
		}

		// The guard that matters. A school with children is what the history points at, so it can
		// only ever be closed, never removed.
		$children = SignupRepository::count_per_school_id()[ $school_id ] ?? 0;

		if ( $children > 0 ) {
			wp_die( esc_html__( 'This school still has children registered. Set it to Closed instead.', 'rockaden-skolschack' ) );
		}

		wp_delete_post( $school_id, true );
		Capabilities::flush_cache();

		wp_safe_redirect( admin_url( 'admin.php?page=' . SchoolsPage::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Store the school's own fields.
	 *
	 * @param int                  $school_id School post id.
	 * @param array<string, mixed> $raw       Submitted values, already unslashed.
	 * @return void
	 */
	private static function save_meta( int $school_id, array $raw ): void {
		$weekday = absint( $raw['weekday'] ?? 0 );
		update_post_meta( $school_id, School::META_WEEKDAY, $weekday >= 1 && $weekday <= 7 ? (string) $weekday : '' );

		foreach ( [
			School::META_TIME_START => 'time_start',
			School::META_TIME_END   => 'time_end',
		] as $key => $field ) {
			$value = sanitize_text_field( (string) ( $raw[ $field ] ?? '' ) );
			update_post_meta( $school_id, $key, School::normalise_time( $value ) );
		}

		update_post_meta( $school_id, School::META_ROOM, sanitize_text_field( (string) ( $raw['room'] ?? '' ) ) );
		update_post_meta( $school_id, School::META_CONTACT, sanitize_text_field( (string) ( $raw['contact'] ?? '' ) ) );
		update_post_meta( $school_id, School::META_NOTE, sanitize_textarea_field( (string) ( $raw['note'] ?? '' ) ) );

		// Blank means inherit the club default, so an empty box stays empty rather than becoming
		// zero, which would mean a group that costs nothing.
		$fee = trim( sanitize_text_field( (string) ( $raw['fee'] ?? '' ) ) );
		update_post_meta( $school_id, School::META_FEE, is_numeric( $fee ) ? (string) absint( $fee ) : '' );
	}

	/**
	 * Replace the school's coordinator list.
	 *
	 * Assigning somebody here genuinely grants them access to this school's children. That is the
	 * intended behaviour, and it is why the caller has already checked that this person may edit
	 * this particular school.
	 *
	 * @param int                  $school_id School post id.
	 * @param array<string, mixed> $raw       Submitted values, already unslashed.
	 * @return void
	 */
	private static function save_coordinators( int $school_id, array $raw ): void {
		$submitted = isset( $raw['coordinators'] ) && is_array( $raw['coordinators'] )
			? array_map( 'absint', $raw['coordinators'] )
			: [];

		$wanted  = array_values( array_unique( array_filter( $submitted, static fn ( int $id ): bool => $id > 0 ) ) );
		$current = Capabilities::coordinators_of( $school_id );

		foreach ( array_diff( $current, $wanted ) as $remove ) {
			delete_post_meta( $school_id, Capabilities::META_COORDINATOR, $remove );
		}

		foreach ( array_diff( $wanted, $current ) as $add ) {
			add_post_meta( $school_id, Capabilities::META_COORDINATOR, $add );
		}

		Capabilities::flush_cache();
	}

	/**
	 * Go back to the school, carrying a signed marker on success.
	 *
	 * @param int  $school_id School post id.
	 * @param bool $saved     Whether the save went through.
	 * @return void
	 */
	private static function redirect_back( int $school_id, bool $saved ): void {
		$args = [
			'page'   => SchoolsPage::PAGE_SLUG,
			'school' => $school_id > 0 ? $school_id : 'new',
		];

		if ( $saved ) {
			$args['rsk_saved'] = '1';
			$args['_wpnonce']  = wp_create_nonce( self::SAVED_NOTICE );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
