<?php
/**
 * Saving and deleting a signup.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Data\SignupRepository;
use Rockaden\Skolschack\Services\Capabilities;
use Rockaden\Skolschack\Services\Identity;
use Rockaden\Skolschack\Services\SignupValidator;
use Rockaden\Skolschack\Services\Terms;

defined( 'ABSPATH' ) || exit;

/**
 * The write side of the signups screen.
 *
 * Every handler checks the nonce, then the capability, then whether this person may reach this
 * particular signup at all. The third check is the one that matters: hiding a link is not access
 * control, and a form can be posted by anybody who knows the address.
 */
class SignupActions {

	/**
	 * Action name for saving.
	 *
	 * @var string
	 */
	public const SAVE = 'rsk_save_signup';

	/**
	 * Action name for deleting.
	 *
	 * @var string
	 */
	public const DELETE = 'rsk_delete_signup';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	public const NONCE = 'rsk_signup_nonce';

	/**
	 * Nonce action behind the "saved" marker on the redirect.
	 *
	 * @var string
	 */
	public const SAVED_NOTICE = 'rsk_signup_saved';

	/**
	 * Hook both handlers up.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::SAVE, [ self::class, 'handle_save' ] );
		add_action( 'admin_post_' . self::DELETE, [ self::class, 'handle_delete' ] );
	}

	/**
	 * Save one signup.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::SAVE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'rockaden-skolschack' ) );
		}

		// Read once, here, where the nonce has just been checked. Everything downstream works on
		// this array rather than reaching for superglobals of its own.
		$raw    = array_map( 'wp_unslash', (array) $_POST );
		$signup = self::reachable( $raw, Capabilities::EDIT_SIGNUPS );

		$fields    = self::fields_from( $raw );
		$guardians = self::guardians_from( $raw );
		$problems  = SignupValidator::check( $fields, $guardians );

		$clash = SignupRepository::find_clash(
			(int) $signup->id,
			(int) $fields['term_year'],
			(string) $fields['term_season'],
			(string) $fields['identity_hash']
		);

		if ( null !== $clash ) {
			$problems[] = [
				'field'   => 'child_personnummer',
				'level'   => SignupValidator::ERROR,
				'message' => sprintf(
					/* translators: 1: the other child's name, 2: the term. */
					__( '%1$s is already registered for %2$s with these details. Two children cannot share an identity in one term.', 'rockaden-skolschack' ),
					trim( $clash->child_first_name . ' ' . $clash->child_last_name ),
					Terms::label( (int) $clash->term_year, (string) $clash->term_season )
				),
			];
		}

		if ( SignupValidator::has_errors( $problems ) ) {
			// Handed back through a short-lived transient rather than rendered here. This is
			// admin-post.php, not an admin screen: rendering the page from inside it lands the
			// person on the login form instead of their work. The transient carries what they
			// typed so the redirect loses nothing.
			self::remember_failure( (int) $signup->id, $fields, $guardians, $problems );

			wp_safe_redirect(
				add_query_arg(
					[
						'page'       => SignupsPage::PAGE_SLUG,
						'signup'     => (int) $signup->id,
						'rsk_failed' => '1',
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		SignupRepository::update_by_id( (int) $signup->id, $fields, $guardians );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'      => SignupsPage::PAGE_SLUG,
					'signup'    => (int) $signup->id,
					'rsk_saved' => '1',
					'_wpnonce'  => wp_create_nonce( self::SAVED_NOTICE ),
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Delete one signup.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::DELETE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'rockaden-skolschack' ) );
		}

		$raw    = array_map( 'wp_unslash', (array) $_POST );
		$signup = self::reachable( $raw, Capabilities::DELETE_SIGNUPS );

		if ( '1' !== sanitize_text_field( (string) ( $raw['confirm'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Tick the confirmation box if you really mean to delete this signup.', 'rockaden-skolschack' ) );
		}

		SignupRepository::delete( (int) $signup->id );

		wp_safe_redirect( admin_url( 'admin.php?page=' . SignupsPage::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Check the capability and the reach, and return the signup being acted on.
	 *
	 * The reach check is a repository lookup rather than a comparison here, so it is the same
	 * narrowing the list and the export use. A signup outside this person's schools comes back as
	 * nothing, and is refused as if it did not exist.
	 *
	 * @param array<string, mixed> $raw        Submitted values, already unslashed.
	 * @param string               $capability Capability required.
	 * @return object The signup.
	 */
	private static function reachable( array $raw, string $capability ): object {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'rockaden-skolschack' ) );
		}

		$signup = SignupRepository::find( absint( $raw['signup'] ?? 0 ) );

		if ( null === $signup ) {
			wp_die( esc_html__( 'That signup does not exist, or it belongs to a school you do not coordinate.', 'rockaden-skolschack' ) );
		}

		return $signup;
	}

	/**
	 * Read the submitted signup fields.
	 *
	 * The identity hash is recomputed here rather than trusted from the form, so renaming a child
	 * genuinely changes who the record is, and nobody can post one that does not match the name.
	 *
	 * @param array<string, mixed> $raw Submitted values, already unslashed.
	 * @return array<string, mixed> Field values ready for the repository.
	 */
	private static function fields_from( array $raw ): array {
		$post = static function ( string $key ) use ( $raw ): string {
			return sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );
		};

		$personnummer = preg_replace( '/\D+/', '', $post( 'child_personnummer' ) );
		$personnummer = is_string( $personnummer ) ? $personnummer : '';
		$birthdate    = $post( 'child_birthdate' );

		if ( 12 === strlen( $personnummer ) ) {
			try {
				$parsed    = Identity::parse( $personnummer );
				$birthdate = $parsed['birthdate'];
			} catch ( \RuntimeException $e ) {
				unset( $e );
			}
		}

		$school_id = (int) $post( 'school_id' );
		$season    = $post( 'term_season' );

		return [
			'child_first_name'   => $post( 'child_first_name' ),
			'child_last_name'    => $post( 'child_last_name' ),
			'child_personnummer' => '' === $personnummer ? null : $personnummer,
			'child_birthdate'    => $birthdate,
			'child_gender'       => substr( $post( 'child_gender' ), 0, 1 ),
			'child_class'        => $post( 'child_class' ),
			'street'             => $post( 'street' ),
			'postal_code'        => $post( 'postal_code' ),
			'city'               => $post( 'city' ),
			'school_id'          => $school_id,
			'school_name'        => $school_id > 0 ? get_the_title( $school_id ) : '',
			'term_year'          => (int) $post( 'term_year' ),
			'term_season'        => Terms::is_valid_season( $season ) ? $season : '',
			'status'             => 'withdrawn' === $post( 'status' ) ? 'withdrawn' : 'active',
			'identity_hash'      => Identity::hash(
				$post( 'child_first_name' ),
				$post( 'child_last_name' ),
				$birthdate,
				$personnummer
			),
		];
	}

	/**
	 * Read the submitted guardian rows.
	 *
	 * A row ticked for removal is dropped, and a row with nothing in it is ignored, which is what
	 * makes the trailing blank row work as "add another" without any JavaScript.
	 *
	 * @param array<string, mixed> $raw Submitted values, already unslashed.
	 * @return array<int, array{name: string, email: string, phone: string, is_primary: bool}> Guardians.
	 */
	private static function guardians_from( array $raw ): array {
		$rows    = isset( $raw['guardian'] ) && is_array( $raw['guardian'] ) ? $raw['guardian'] : [];
		$primary = absint( $raw['guardian_primary'] ?? 0 );

		$guardians = [];

		foreach ( (array) $rows as $index => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}

			$name  = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			$email = sanitize_email( (string) ( $row['email'] ?? '' ) );
			$phone = sanitize_text_field( (string) ( $row['phone'] ?? '' ) );

			if ( '' === $name && '' === $email && '' === $phone ) {
				continue;
			}

			$guardians[] = [
				'name'       => $name,
				'email'      => $email,
				'phone'      => $phone,
				'is_primary' => (int) $index === $primary,
			];
		}

		// Somebody has to be the main contact, so if the chosen row was removed, promote the first.
		$has_primary = false;
		foreach ( $guardians as $guardian ) {
			$has_primary = $has_primary || $guardian['is_primary'];
		}

		if ( ! $has_primary && [] !== $guardians ) {
			$guardians[0]['is_primary'] = true;
		}

		return $guardians;
	}

	/**
	 * The transient key holding a failed attempt, per person and per signup.
	 *
	 * @param int $signup_id Signup id.
	 * @return string Cache key.
	 */
	public static function failure_key( int $signup_id ): string {
		return sprintf( 'rsk_failed_%d_%d', get_current_user_id(), $signup_id );
	}

	/**
	 * Keep what somebody typed, briefly, so a redirect does not throw it away.
	 *
	 * Short-lived on purpose: it holds a child's details, so it should not outlive the round trip
	 * that needs it.
	 *
	 * @param int                                                              $signup_id Signup id.
	 * @param array<string, mixed>                                             $fields    Values as typed.
	 * @param array<int, array<string, mixed>>                                 $guardians Guardians as typed.
	 * @param array<int, array{field: string, level: string, message: string}> $problems  What was wrong.
	 * @return void
	 */
	private static function remember_failure( int $signup_id, array $fields, array $guardians, array $problems ): void {
		set_transient(
			self::failure_key( $signup_id ),
			[
				'fields'    => $fields,
				'guardians' => $guardians,
				'problems'  => $problems,
			],
			5 * MINUTE_IN_SECONDS
		);
	}
}
