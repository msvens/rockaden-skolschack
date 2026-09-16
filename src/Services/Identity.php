<?php
/**
 * Child identity: birthdate, personnummer, and the key the duplicate rule rests on.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reading a birthdate or a personnummer, whichever is in front of it.
 *
 * A child may be identified by either, and the two are not distinguishable by anything except
 * their shape: eight digits are a date, twelve are a full personnummer. Records reach this plugin
 * holding a mixture of the two, so nothing here assumes which it has.
 *
 * Every value is worked out on its own, and anything that cannot be read is refused rather than
 * turned into a date that was never given.
 */
class Identity {

	/**
	 * Read a stored identity value, whichever of the two forms it is in.
	 *
	 * @param string $raw Value as stored, already repaired and trimmed.
	 * @return array{birthdate: string, personnummer: string, note: string} Birthdate as Y-m-d,
	 *         personnummer as twelve digits or an empty string, and a note when the value needed
	 *         rescuing.
	 * @throws \RuntimeException When the value cannot be read as a date or a number.
	 */
	public static function parse( string $raw ): array {
		$digits = preg_replace( '/\D+/', '', $raw );
		$digits = is_string( $digits ) ? $digits : '';
		$note   = '';

		// Whitespace or a missing hyphen rather than a different format.
		if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2}|\d{8}-\d{4})$/', trim( $raw ) ) && '' !== $digits ) {
			$note = sprintf( 'personnr needed rescuing from %s', $raw );
		}

		if ( 8 === strlen( $digits ) ) {
			return [
				'birthdate'    => self::date_from( $digits ),
				'personnummer' => '',
				'note'         => $note,
			];
		}

		if ( 12 === strlen( $digits ) ) {
			$birthdate = self::date_from( substr( $digits, 0, 8 ) );

			return [
				'birthdate'    => $birthdate,
				'personnummer' => $digits,
				'note'         => self::luhn_is_valid( $digits ) ? $note : trim( $note . ' checksum does not match' ),
			];
		}

		throw new \RuntimeException(
			sprintf( 'Cannot read "%s" as either a birthdate or a personnummer.', esc_html( $raw ) )
		);
	}

	/**
	 * Turn eight digits into a validated date.
	 *
	 * @param string $yyyymmdd Eight digits.
	 * @return string Date as Y-m-d.
	 * @throws \RuntimeException When those digits are not a real date.
	 */
	private static function date_from( string $yyyymmdd ): string {
		$year  = (int) substr( $yyyymmdd, 0, 4 );
		$month = (int) substr( $yyyymmdd, 4, 2 );
		$day   = (int) substr( $yyyymmdd, 6, 2 );

		if ( ! checkdate( $month, $day, $year ) || $year < 1900 ) {
			throw new \RuntimeException( sprintf( '"%s" is not a real date.', esc_html( $yyyymmdd ) ) );
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * The Luhn check Swedish personnummer carry.
	 *
	 * Reported rather than enforced on import: a historic row with a bad checksum is still the
	 * record of a real child who attended. The public form will enforce it.
	 *
	 * @param string $personnummer Twelve digits.
	 * @return bool Whether the checksum matches.
	 */
	public static function luhn_is_valid( string $personnummer ): bool {
		if ( 12 !== strlen( $personnummer ) ) {
			return false;
		}

		// The checksum runs over the ten-digit form, without the century.
		$ten = substr( $personnummer, 2 );
		$sum = 0;

		for ( $i = 0; $i < 10; $i++ ) {
			$digit = (int) $ten[ $i ];
			$digit = 0 === $i % 2 ? $digit * 2 : $digit;
			$sum  += $digit > 9 ? $digit - 9 : $digit;
		}

		return 0 === $sum % 10;
	}

	/**
	 * The key that decides whether two signups are the same child.
	 *
	 * A personnummer identifies a person outright. Without one, the best available key is the name
	 * plus the birthdate, which is what the club had for its first five seasons. Combined with the
	 * term in a unique index, this is what stops the same child being registered twice.
	 *
	 * @param string $first_name    Given name, repaired.
	 * @param string $last_name     Family name, repaired.
	 * @param string $birthdate     Birthdate as Y-m-d.
	 * @param string $personnummer  Twelve digits, or empty.
	 * @return string A 64-character hash.
	 */
	public static function hash( string $first_name, string $last_name, string $birthdate, string $personnummer ): string {
		if ( '' !== $personnummer ) {
			return hash( 'sha256', 'pnr:' . $personnummer );
		}

		$key = sprintf(
			'name:%s|%s|%s',
			mb_strtolower( trim( $last_name ) ),
			mb_strtolower( trim( $first_name ) ),
			$birthdate
		);

		return hash( 'sha256', $key );
	}
}
