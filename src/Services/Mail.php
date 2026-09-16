<?php
/**
 * The two emails a signup produces.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Services;

use Rockaden\Skolschack\Admin\SettingsPage;
use Rockaden\Skolschack\Data\SignupRepository;
use Rockaden\Skolschack\PostTypes\School;

defined( 'ABSPATH' ) || exit;

/**
 * What the club sends when a child is signed up.
 *
 * Two messages, not one. The guardian gets a confirmation, and the coordinators get a notification
 * addressed to them, rather than a copy of a letter written to somebody else that they have to
 * work out concerns them.
 *
 * Everything the confirmation says about money and logistics comes from settings or from the
 * school, so none of it is written into the code.
 */
class Mail {

	/**
	 * Send the guardian's confirmation.
	 *
	 * @param int $signup_id Signup id.
	 * @return bool Whether anything was sent.
	 */
	public static function send_confirmation( int $signup_id ): bool {
		// Unscoped on purpose: a public submission has no logged-in user, so the scoped
		// finder would return nothing and the mail would silently never be sent.
		$signup = SignupRepository::find_unscoped( $signup_id );

		if ( null === $signup ) {
			return false;
		}

		$recipients = [];

		foreach ( SignupRepository::guardians_of( $signup_id ) as $guardian ) {
			if ( '' !== (string) $guardian->email ) {
				$recipients[] = (string) $guardian->email;
			}
		}

		if ( [] === $recipients ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: the school's name. */
			__( 'Signup for school chess at %s', 'rockaden-skolschack' ),
			$signup->school_name
		);

		return self::send( $recipients, $subject, self::confirmation_body( $signup ) );
	}

	/**
	 * Tell the coordinators a child has signed up.
	 *
	 * @param int $signup_id Signup id.
	 * @return bool Whether anything was sent.
	 */
	public static function send_notification( int $signup_id ): bool {
		// Unscoped on purpose: a public submission has no logged-in user, so the scoped
		// finder would return nothing and the mail would silently never be sent.
		$signup = SignupRepository::find_unscoped( $signup_id );

		if ( null === $signup ) {
			return false;
		}

		$recipients = [];

		foreach ( Capabilities::coordinators_of( (int) $signup->school_id ) as $user_id ) {
			$user = get_userdata( $user_id );

			if ( $user instanceof \WP_User && '' !== $user->user_email ) {
				$recipients[] = $user->user_email;
			}
		}

		if ( [] === $recipients ) {
			// A school with nobody assigned would otherwise notify nobody at all, and the signup
			// would sit unnoticed. The club address is the backstop.
			$fallback = trim( (string) SettingsPage::get()['notification_email'] );

			if ( '' === $fallback ) {
				return false;
			}

			$recipients = [ $fallback ];
		}

		$subject = sprintf(
			/* translators: %s: the school's name. */
			__( 'New school chess signup at %s', 'rockaden-skolschack' ),
			$signup->school_name
		);

		return self::send( $recipients, $subject, self::notification_body( $signup, $signup_id ) );
	}

	/**
	 * The guardian's message.
	 *
	 * @param object $signup The signup.
	 * @return string Plain text.
	 */
	private static function confirmation_body( object $signup ): string {
		$settings  = SettingsPage::get();
		$school_id = (int) $signup->school_id;
		$lines     = [];

		$lines[] = sprintf(
			/* translators: 1: the child's name, 2: the school. */
			__( 'Thank you. %1$s is signed up for school chess at %2$s.', 'rockaden-skolschack' ),
			trim( $signup->child_first_name . ' ' . $signup->child_last_name ),
			$signup->school_name
		);
		$lines[] = '';

		$when = School::meeting_time( $school_id );
		$room = trim( (string) get_post_meta( $school_id, School::META_ROOM, true ) );

		if ( '' !== $when ) {
			$lines[] = sprintf(
				/* translators: %s: a weekday and time, for example "tisdag 14:20-15:05". */
				__( 'We play %s.', 'rockaden-skolschack' ),
				$when
			);
		}

		if ( '' !== $room ) {
			$lines[] = sprintf(
				/* translators: %s: a room. */
				__( 'We meet in %s.', 'rockaden-skolschack' ),
				$room
			);
		}

		$note = trim( (string) get_post_meta( $school_id, School::META_NOTE, true ) );

		if ( '' !== $note ) {
			$lines[] = $note;
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: the term fee in kronor. */
			__( 'The club charges a membership fee of %d kronor per term.', 'rockaden-skolschack' ),
			School::fee( $school_id )
		);

		$payment = [];

		if ( '' !== trim( (string) $settings['plusgiro'] ) ) {
			$payment[] = sprintf(
				/* translators: %s: a plusgiro number. */
				__( 'plusgiro %s', 'rockaden-skolschack' ),
				$settings['plusgiro']
			);
		}

		if ( '' !== trim( (string) $settings['swish'] ) ) {
			$payment[] = sprintf(
				/* translators: %s: a Swish number. */
				__( 'Swish %s', 'rockaden-skolschack' ),
				$settings['swish']
			);
		}

		if ( [] !== $payment ) {
			$lines[] = sprintf(
				/* translators: %s: one or more ways to pay. */
				__( 'Pay to %s.', 'rockaden-skolschack' ),
				implode( __( ' or ', 'rockaden-skolschack' ), $payment )
			);
		}

		if ( '' !== trim( (string) $settings['fritidskort'] ) ) {
			$lines[] = (string) $settings['fritidskort'];
		}

		$lines[] = '';
		$lines[] = __( 'You are welcome to try twice before deciding whether to carry on.', 'rockaden-skolschack' );

		$contact = School::contact( $school_id );

		if ( '' !== $contact ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %s: a contact name and number. */
				__( 'Questions about the chess at this school go to %s.', 'rockaden-skolschack' ),
				$contact
			);
		}

		$lines[] = '';
		$lines[] = __( 'Schackklubben Rockaden', 'rockaden-skolschack' );

		return implode( "\n", $lines );
	}

	/**
	 * The coordinators' message.
	 *
	 * Written to them rather than copied to them, and it links straight to the record so they can
	 * act on it instead of retyping it somewhere.
	 *
	 * @param object $signup    The signup.
	 * @param int    $signup_id Its id.
	 * @return string Plain text.
	 */
	private static function notification_body( object $signup, int $signup_id ): string {
		$lines = [];

		$lines[] = sprintf(
			/* translators: 1: the child's name, 2: the school. */
			__( '%1$s has signed up for chess at %2$s.', 'rockaden-skolschack' ),
			trim( $signup->child_first_name . ' ' . $signup->child_last_name ),
			$signup->school_name
		);

		if ( '' !== trim( (string) $signup->child_class ) ) {
			$lines[] = sprintf(
				/* translators: %s: the child's class. */
				__( 'Class: %s', 'rockaden-skolschack' ),
				$signup->child_class
			);
		}

		$lines[] = '';

		foreach ( SignupRepository::guardians_of( $signup_id ) as $guardian ) {
			$parts = array_filter( [ (string) $guardian->name, (string) $guardian->email, (string) $guardian->phone ] );

			if ( [] !== $parts ) {
				$lines[] = implode( ', ', $parts );
			}
		}

		$lines[] = '';
		$lines[] = add_query_arg(
			[
				'page'   => 'rockaden-skolschack',
				'signup' => $signup_id,
			],
			admin_url( 'admin.php' )
		);

		return implode( "\n", $lines );
	}

	/**
	 * Send one message.
	 *
	 * The content type is set explicitly and removed again. Sent without one, a message whose
	 * source encoding is not what the receiving end assumes arrives with its Swedish broken.
	 *
	 * @param array<int, string> $to      Recipients.
	 * @param string             $subject Subject.
	 * @param string             $body    Plain text body.
	 * @return bool Whether it was accepted for delivery.
	 */
	private static function send( array $to, string $subject, string $body ): bool {
		$charset = static fn (): string => 'text/plain; charset=UTF-8';

		add_filter( 'wp_mail_content_type', $charset );
		$sent = wp_mail( array_values( array_unique( $to ) ), $subject, $body );
		remove_filter( 'wp_mail_content_type', $charset );

		return $sent;
	}
}
