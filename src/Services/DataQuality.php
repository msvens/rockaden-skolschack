<?php
/**
 * What is wrong with a signup that only a person can put right.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Some problems cannot be fixed by code.
 *
 * A personnummer whose check digit does not match is a typing error, and only somebody who can
 * contact the family can correct it. An import must not quietly repair such a value, and must not
 * drop the row either. It carries it across and marks it, so a coordinator can see it and act.
 *
 * The flags are recomputed on every save rather than stored once, so a corrected record stops
 * warning without anybody having to remember a second step.
 */
class DataQuality {

	/**
	 * A personnummer in the right shape whose check digit does not match.
	 *
	 * @var string
	 */
	public const FLAG_PERSONNUMMER = 'pnr_checksum';

	/**
	 * The signup names no school, or names one that no longer exists.
	 *
	 * @var string
	 */
	public const FLAG_NO_SCHOOL = 'no_school';

	/**
	 * Nobody can be reached about this child.
	 *
	 * @var string
	 */
	public const FLAG_NO_CONTACT = 'no_contact';

	/**
	 * Work out which flags a signup carries.
	 *
	 * @param array<string, mixed>             $signup    Signup field values.
	 * @param array<int, array<string, mixed>> $guardians Its guardians.
	 * @return list<string> Flag names, empty when nothing needs attention.
	 */
	public static function flags_for( array $signup, array $guardians ): array {
		$flags = [];

		$personnummer = (string) ( $signup['child_personnummer'] ?? '' );
		if ( '' !== $personnummer && ! Identity::luhn_is_valid( $personnummer ) ) {
			$flags[] = self::FLAG_PERSONNUMMER;
		}

		if ( 0 === (int) ( $signup['school_id'] ?? 0 ) ) {
			$flags[] = self::FLAG_NO_SCHOOL;
		}

		$reachable = false;
		foreach ( $guardians as $guardian ) {
			if ( '' !== (string) ( $guardian['email'] ?? '' ) || '' !== (string) ( $guardian['phone'] ?? '' ) ) {
				$reachable = true;
				break;
			}
		}

		if ( ! $reachable ) {
			$flags[] = self::FLAG_NO_CONTACT;
		}

		return $flags;
	}

	/**
	 * Readable descriptions, for the admin list.
	 *
	 * @return array<string, string> Flag name mapped to what it means.
	 */
	public static function labels(): array {
		return [
			self::FLAG_PERSONNUMMER => __( 'The personnummer check digit does not match', 'rockaden-skolschack' ),
			self::FLAG_NO_SCHOOL    => __( 'No school', 'rockaden-skolschack' ),
			self::FLAG_NO_CONTACT   => __( 'No way to contact a guardian', 'rockaden-skolschack' ),
		];
	}
}
