<?php
/**
 * The public signup endpoint.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Rest;

use Rockaden\Skolschack\Data\SignupRepository;
use Rockaden\Skolschack\PostTypes\School;
use Rockaden\Skolschack\Services\Identity;
use Rockaden\Skolschack\Services\Mail;
use Rockaden\Skolschack\Services\SignupValidator;
use Rockaden\Skolschack\Services\Terms;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The only part of this plugin the public can reach.
 *
 * The order of the checks inside a submission is deliberate and is the order they appear in below:
 * honeypot, rate limit, validation, duplicate, then the write. A bot should learn nothing, a flood
 * should stop before it costs anything, and a parent should be told precisely what is wrong.
 */
class SignupController {

	/**
	 * REST namespace. Deliberately not the chess plugin's.
	 *
	 * @var string
	 */
	private const NAMESPACE = 'rockaden-skolschack/v1';

	/**
	 * How many submissions one visitor may make per hour.
	 *
	 * @var int
	 */
	private const RATE_LIMIT = 6;

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * Declare the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/signups',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'submit' ],
				'permission_callback' => [ self::class, 'verify_nonce' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/form-context',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'form_context' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Confirm the request came from our own form.
	 *
	 * This is CSRF protection, not authentication: anyone may sign a child up. A missing or stale
	 * token means the page was cached, which is what form_context() is for. The token still has to
	 * belong to whoever is sending it, which is why form_context() takes care over who it mints for.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool Whether the token is good.
	 */
	public static function verify_nonce( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		return is_string( $nonce ) && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * The schools currently open, and a fresh token.
	 *
	 * Fetched by the script on every page view, so the form works behind a page cache. The theme's
	 * feedback form bakes its token into the HTML and breaks exactly there; its reactions block
	 * solves it this way, and this follows that.
	 *
	 * @return WP_REST_Response The context.
	 */
	public static function form_context(): WP_REST_Response {
		$schools = [];

		foreach ( School::open_for_signups() as $school ) {
			$schools[] = [
				'id'   => (int) $school->ID,
				'name' => $school->post_title,
			];
		}

		$response = new WP_REST_Response(
			[
				'nonce'   => self::issue_nonce(),
				'schools' => $schools,
			]
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Mint the token the submission will be checked against.
	 *
	 * This request is the one request that can never carry a token, because fetching one is the
	 * whole point of it. WordPress treats a REST request without a token as anonymous and resets
	 * the current user to nobody before we are called, so a token created here would belong to
	 * nobody. The submission that follows does carry a token, is therefore authenticated from the
	 * cookies, and the two no longer agree: a logged-in visitor is told "Cookie check failed" on a
	 * form that works perfectly for everyone else.
	 *
	 * Reading the cookie back puts the right person in place for the moment it takes to create the
	 * token. Nothing is granted that the cookie did not already grant, and an anonymous visitor is
	 * untouched.
	 *
	 * @return string The token.
	 */
	private static function issue_nonce(): string {
		$previous = get_current_user_id();
		$user_id  = wp_validate_auth_cookie( '', 'logged_in' );

		if ( ! is_int( $user_id ) || 0 === $user_id ) {
			return wp_create_nonce( 'wp_rest' );
		}

		wp_set_current_user( $user_id );
		$nonce = wp_create_nonce( 'wp_rest' );
		wp_set_current_user( $previous );

		return $nonce;
	}

	/**
	 * Take a submission.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error The outcome.
	 */
	public static function submit( WP_REST_Request $request ) {
		// An empty result means the body was not JSON, so fall back on emptiness rather than on
		// the type: get_json_params() always hands back an array.
		$params = $request->get_json_params();
		$params = [] !== $params ? $params : $request->get_params();

		// Honeypot first, and answered with a plausible success. A bot that is told it failed
		// learns how to pass; one that is told it worked has no reason to try again.
		if ( '' !== trim( (string) ( $params['website'] ?? '' ) ) ) {
			return new WP_REST_Response( [ 'ok' => true ] );
		}

		if ( self::is_rate_limited() ) {
			return new WP_Error(
				'rsk_too_many',
				__( 'That is a lot of signups from one place. Wait a little and try again, or contact the club.', 'rockaden-skolschack' ),
				[ 'status' => 429 ]
			);
		}

		$school_id = absint( $params['school_id'] ?? 0 );
		$school    = get_post( $school_id );

		if ( ! $school instanceof \WP_Post || School::POST_TYPE !== $school->post_type || 'publish' !== $school->post_status ) {
			return new WP_Error(
				'rsk_unknown_school',
				__( 'Pick a school from the list.', 'rockaden-skolschack' ),
				[ 'status' => 400 ]
			);
		}

		$term      = Terms::current();
		$fields    = self::fields_from( $params, $school_id, $school->post_title, $term );
		$guardians = self::guardians_from( $params );

		// Strict: the admin screen may save a questionable personnummer because only a person can
		// chase the family, but a parent typing it can simply correct it now.
		$problems = SignupValidator::check( $fields, $guardians, true );

		if ( [] !== $problems ) {
			return new WP_Error(
				'rsk_invalid',
				$problems[0]['message'],
				[
					'status'   => 400,
					'field'    => self::input_name( (string) $problems[0]['field'] ),
					'problems' => array_column( $problems, 'message' ),
				]
			);
		}

		$existing = SignupRepository::find_by_identity(
			(int) $fields['term_year'],
			(string) $fields['term_season'],
			(string) $fields['identity_hash']
		);

		if ( null !== $existing ) {
			return new WP_Error(
				'rsk_already_registered',
				self::already_registered_message( $existing ),
				[ 'status' => 409 ]
			);
		}

		self::record_attempt();

		$signup_id = SignupRepository::save( $fields, $guardians );
		SignupRepository::replace_guardians( $signup_id, $guardians );

		Mail::send_confirmation( $signup_id );
		Mail::send_notification( $signup_id );

		return new WP_REST_Response( [ 'ok' => true ], 201 );
	}

	/**
	 * Translate a stored field name into the name of the input that carries it.
	 *
	 * The validator answers in the vocabulary of the table, because the admin screens work in
	 * that vocabulary too. The form does not: it has one personnummer box and no birthdate box
	 * at all. Translating here rather than in the script keeps this endpoint speaking the
	 * language of the only thing that calls it, and leaves the validator free of any knowledge
	 * of the form.
	 *
	 * An unmapped name comes back empty, which the script reads as "nothing to point at". That
	 * is the right answer for a problem with no input behind it, such as the term.
	 *
	 * @param string $field The validator's name for the field.
	 * @return string The input's name, or an empty string when the form has no such input.
	 */
	private static function input_name( string $field ): string {
		$map = [
			'child_first_name'   => 'first_name',
			'child_last_name'    => 'last_name',
			'child_personnummer' => 'personnummer',
			// The form has no birthdate box; the personnummer is where a birthdate comes from.
			'child_birthdate'    => 'personnummer',
			'postal_code'        => 'postal_code',
			'guardian_email_0'   => 'guardian1_email',
			'guardian_email_1'   => 'guardian2_email',
			'guardians'          => 'guardian1_email',
		];

		return $map[ $field ] ?? '';
	}

	/**
	 * What to tell somebody whose child is already registered.
	 *
	 * Rather than a bare refusal, it names the school and the term and hands over somebody to talk
	 * to. A parent told only "no" has nowhere to go with it.
	 *
	 * @param object $existing The signup already on file.
	 * @return string The message.
	 */
	private static function already_registered_message( object $existing ): string {
		$contact = School::contact( (int) $existing->school_id );

		$message = sprintf(
			/* translators: 1: the child's name, 2: the school, 3: the term. */
			__( '%1$s is already signed up for chess at %2$s this term (%3$s), so there is nothing more to do.', 'rockaden-skolschack' ),
			trim( $existing->child_first_name . ' ' . $existing->child_last_name ),
			$existing->school_name,
			Terms::label( (int) $existing->term_year, (string) $existing->term_season )
		);

		if ( '' === $contact ) {
			return $message;
		}

		return $message . ' ' . sprintf(
			/* translators: %s: a contact name and number. */
			__( 'If that looks wrong, contact %s.', 'rockaden-skolschack' ),
			$contact
		);
	}

	/**
	 * Whether this visitor has submitted too often.
	 *
	 * Keyed on a hash rather than an address. No visitor address is stored anywhere by this plugin,
	 * and a transient that expires within the hour is not a record of who visited.
	 *
	 * @return bool True when the limit has been reached.
	 */
	private static function is_rate_limited(): bool {
		return (int) get_transient( self::rate_key() ) >= self::RATE_LIMIT;
	}

	/**
	 * Count one submission against the limit.
	 *
	 * @return void
	 */
	private static function record_attempt(): void {
		$key = self::rate_key();
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * The rate-limit key for this visitor.
	 *
	 * @return string A key derived from, but not containing, the address.
	 */
	private static function rate_key(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';

		return 'rsk_rate_' . hash( 'sha256', $address . wp_salt() );
	}

	/**
	 * Read the submitted child and address details.
	 *
	 * @param array<string, mixed>             $params      Submitted values.
	 * @param int                              $school_id   The chosen school.
	 * @param string                           $school_name Its name, snapshotted.
	 * @param array{year: int, season: string} $term      The current term.
	 * @return array<string, mixed> Field values ready for the repository.
	 */
	private static function fields_from( array $params, int $school_id, string $school_name, array $term ): array {
		$get = static function ( string $key ) use ( $params ): string {
			return trim( sanitize_text_field( (string) ( $params[ $key ] ?? '' ) ) );
		};

		$personnummer = preg_replace( '/\D+/', '', $get( 'personnummer' ) );
		$personnummer = is_string( $personnummer ) ? $personnummer : '';
		$birthdate    = '';

		if ( 12 === strlen( $personnummer ) ) {
			try {
				$parsed    = Identity::parse( $personnummer );
				$birthdate = $parsed['birthdate'];
			} catch ( \RuntimeException $e ) {
				unset( $e );
			}
		}

		$first = $get( 'first_name' );
		$last  = $get( 'last_name' );

		return [
			'child_first_name'   => $first,
			'child_last_name'    => $last,
			'child_personnummer' => '' === $personnummer ? null : $personnummer,
			'child_birthdate'    => $birthdate,
			'child_gender'       => substr( $get( 'gender' ), 0, 1 ),
			'child_class'        => $get( 'class' ),
			'street'             => $get( 'street' ),
			'postal_code'        => $get( 'postal_code' ),
			'city'               => $get( 'city' ),
			'school_id'          => $school_id,
			'school_name'        => $school_name,
			'term_year'          => $term['year'],
			'term_season'        => $term['season'],
			'status'             => 'active',
			'created_via'        => 'form',
			'identity_hash'      => Identity::hash( $first, $last, $birthdate, $personnummer ),
		];
	}

	/**
	 * Read the submitted guardians.
	 *
	 * Two of them, the second optional, both rendered by the server. A row with nothing in it is
	 * ignored, which is how the optional one stays optional without any JavaScript.
	 *
	 * @param array<string, mixed> $params Submitted values.
	 * @return array<int, array{name: string, email: string, phone: string, is_primary: bool}> Guardians.
	 */
	private static function guardians_from( array $params ): array {
		$guardians = [];

		foreach ( [ 'guardian1', 'guardian2' ] as $prefix ) {
			$name = trim( sanitize_text_field( (string) ( $params[ $prefix . '_name' ] ?? '' ) ) );

			// Deliberately not sanitize_email(): it returns an empty string for anything it does
			// not like, so a mistyped address would arrive here as no address at all. A second
			// guardian filled in with nothing else would then be dropped as an empty row and the
			// parent would be told the signup went through, while that guardian silently never
			// heard from the club again. Kept as typed, the validator can say what is wrong with
			// it, and nothing that fails is_email() is ever saved.
			$email = trim( sanitize_text_field( (string) ( $params[ $prefix . '_email' ] ?? '' ) ) );
			$phone = trim( sanitize_text_field( (string) ( $params[ $prefix . '_phone' ] ?? '' ) ) );

			if ( '' === $name && '' === $email && '' === $phone ) {
				continue;
			}

			$guardians[] = [
				'name'       => $name,
				'email'      => $email,
				'phone'      => $phone,
				'is_primary' => [] === $guardians,
			];
		}

		return $guardians;
	}
}
