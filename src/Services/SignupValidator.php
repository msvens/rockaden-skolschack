<?php
/**
 * Validation rules for a signup, shared by the admin screen and the public form.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Services;

defined( 'ABSPATH' ) || exit;

/**
 * One set of rules, two audiences.
 *
 * Problems come back marked as errors or warnings, and that distinction is the whole point. A
 * personnummer whose check digit does not match is a warning on the admin screen: a handful of
 * records on file are exactly that, and refusing to save one would make every other field on it
 * unfixable. A parent filling in the public form gets the same rule as an error instead, because
 * they can simply retype it.
 *
 * So the admin screen saves through warnings and the public form will not, without either of them
 * owning a private copy of the rules.
 */
class SignupValidator {

	/**
	 * Something that must be fixed before saving.
	 *
	 * @var string
	 */
	public const ERROR = 'error';

	/**
	 * Something worth a person's attention that does not block a save.
	 *
	 * @var string
	 */
	public const WARNING = 'warning';

	/**
	 * Check a signup and its guardians.
	 *
	 * @param array<string, mixed>             $fields    Signup values, as stored.
	 * @param array<int, array<string, mixed>> $guardians Guardians.
	 * @param bool                             $strict    Promote warnings to errors, which is what
	 *                                                    the public form wants: a parent can retype
	 *                                                    a number, so nothing questionable should
	 *                                                    get stored in the first place.
	 * @return array<int, array{field: string, level: string, message: string}> Problems found.
	 */
	public static function check( array $fields, array $guardians, bool $strict = false ): array {
		$problems = [];

		$first = trim( (string) ( $fields['child_first_name'] ?? '' ) );
		$last  = trim( (string) ( $fields['child_last_name'] ?? '' ) );

		if ( '' === $first ) {
			$problems[] = self::problem( 'child_first_name', self::ERROR, __( 'The child needs a first name.', 'rockaden-skolschack' ) );
		}

		if ( '' === $last ) {
			$problems[] = self::problem( 'child_last_name', self::ERROR, __( 'The child needs a last name.', 'rockaden-skolschack' ) );
		}

		$problems = array_merge( $problems, self::check_identity( $fields, $strict ) );

		$postal = trim( (string) ( $fields['postal_code'] ?? '' ) );
		if ( '' !== $postal && 1 !== preg_match( '/^\d{5}$/', $postal ) ) {
			$problems[] = self::problem( 'postal_code', self::ERROR, __( 'A postal code is five digits.', 'rockaden-skolschack' ) );
		}

		if ( ! Terms::is_valid_season( (string) ( $fields['term_season'] ?? '' ) ) ) {
			$problems[] = self::problem( 'term_season', self::ERROR, __( 'Pick a term.', 'rockaden-skolschack' ) );
		}

		$problems = array_merge( $problems, self::check_guardians( $guardians ) );

		if ( ! $strict ) {
			return $problems;
		}

		return array_map(
			static function ( array $problem ): array {
				$problem['level'] = self::ERROR;

				return $problem;
			},
			$problems
		);
	}

	/**
	 * Whether a set of problems contains anything that must block a save.
	 *
	 * @param array<int, array{field: string, level: string, message: string}> $problems Problems.
	 * @return bool True when at least one is an error.
	 */
	public static function has_errors( array $problems ): bool {
		foreach ( $problems as $problem ) {
			if ( self::ERROR === $problem['level'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check the birthdate and personnummer.
	 *
	 * @param array<string, mixed> $fields Signup values.
	 * @param bool                 $strict Whether the public form's wording applies.
	 * @return array<int, array{field: string, level: string, message: string}> Problems found.
	 */
	private static function check_identity( array $fields, bool $strict ): array {
		$problems     = [];
		$personnummer = trim( (string) ( $fields['child_personnummer'] ?? '' ) );
		$birthdate    = trim( (string) ( $fields['child_birthdate'] ?? '' ) );

		if ( '' !== $personnummer ) {
			if ( 1 !== preg_match( '/^\d{12}$/', $personnummer ) ) {
				$problems[] = self::problem(
					'child_personnummer',
					self::ERROR,
					__( 'A personnummer is twelve digits, year first and no hyphen.', 'rockaden-skolschack' )
				);

				return $problems;
			}

			try {
				Identity::parse( $personnummer );
			} catch ( \RuntimeException $e ) {
				// The exception says what the parser choked on, in English, for a log. What a
				// parent needs is which part of their own typing to look at.
				unset( $e );

				$problems[] = self::problem(
					'child_personnummer',
					self::ERROR,
					__( 'That is not a real date. Check the year, month and day.', 'rockaden-skolschack' )
				);

				return $problems;
			}

			if ( ! Identity::luhn_is_valid( $personnummer ) ) {
				// A warning rather than an error: a handful of records on file are exactly this,
				// and only somebody who can reach the family can put it right.
				//
				// The wording differs by audience as well as the level. An administrator is being
				// told to check with the family; a parent is being told they have mistyped, which
				// is almost always what has happened.
				$problems[] = self::problem(
					'child_personnummer',
					self::WARNING,
					$strict
						? __( 'That personnummer does not look right. Please check it and try again.', 'rockaden-skolschack' )
						: __( 'The check digit does not match. Worth confirming with the family.', 'rockaden-skolschack' )
				);
			}

			return $problems;
		}

		if ( '' === $birthdate ) {
			$problems[] = self::problem(
				'child_birthdate',
				self::ERROR,
				__( 'Give either a personnummer or a birthdate.', 'rockaden-skolschack' )
			);

			return $problems;
		}

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $birthdate, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			$problems[] = self::problem( 'child_birthdate', self::ERROR, __( 'That is not a real date.', 'rockaden-skolschack' ) );

			return $problems;
		}

		if ( strtotime( $birthdate ) > time() ) {
			$problems[] = self::problem( 'child_birthdate', self::ERROR, __( 'The birthdate is in the future.', 'rockaden-skolschack' ) );
		}

		return $problems;
	}

	/**
	 * Check the guardians.
	 *
	 * @param array<int, array<string, mixed>> $guardians Guardians.
	 * @return array<int, array{field: string, level: string, message: string}> Problems found.
	 */
	private static function check_guardians( array $guardians ): array {
		$problems  = [];
		$reachable = false;

		foreach ( $guardians as $index => $guardian ) {
			$email = trim( (string) ( $guardian['email'] ?? '' ) );
			$phone = trim( (string) ( $guardian['phone'] ?? '' ) );

			if ( '' !== $email && ! is_email( $email ) ) {
				$problems[] = self::problem(
					'guardian_email_' . $index,
					self::ERROR,
					sprintf(
						/* translators: %s: the email address as typed. */
						__( '"%s" is not an email address.', 'rockaden-skolschack' ),
						$email
					)
				);
			}

			if ( '' !== $email || '' !== $phone ) {
				$reachable = true;
			}
		}

		if ( ! $reachable ) {
			// A warning, not an error. The record is still worth keeping, and DataQuality already
			// flags it so somebody can chase the contact details.
			$problems[] = self::problem(
				'guardians',
				self::WARNING,
				__( 'Nobody can be contacted about this child.', 'rockaden-skolschack' )
			);
		}

		return $problems;
	}

	/**
	 * Build one problem.
	 *
	 * @param string $field   Field it belongs to.
	 * @param string $level   ERROR or WARNING.
	 * @param string $message What to tell the person.
	 * @return array{field: string, level: string, message: string} The problem.
	 */
	private static function problem( string $field, string $level, string $message ): array {
		return [
			'field'   => $field,
			'level'   => $level,
			'message' => $message,
		];
	}
}
