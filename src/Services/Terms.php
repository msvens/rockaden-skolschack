<?php
/**
 * School terms.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Services;

defined( 'ABSPATH' ) || exit;

/**
 * A term is a year plus a season, stored as two columns rather than one string so that
 * filtering and chronological ordering both work without parsing.
 */
class Terms {

	/**
	 * Spring term (vårtermin), January to June.
	 *
	 * @var string
	 */
	public const SEASON_SPRING = 'VT';

	/**
	 * Autumn term (hösttermin), July to December.
	 *
	 * @var string
	 */
	public const SEASON_AUTUMN = 'HT';

	/**
	 * Every valid season, in chronological order within a year.
	 *
	 * @return list<string> Season codes.
	 */
	public static function seasons(): array {
		return [ self::SEASON_SPRING, self::SEASON_AUTUMN ];
	}

	/**
	 * Whether a season code is one we recognise.
	 *
	 * @param string $season Season code to test.
	 * @return bool True when valid.
	 */
	public static function is_valid_season( string $season ): bool {
		return in_array( $season, self::seasons(), true );
	}

	/**
	 * The term a given date falls in.
	 *
	 * The club's year splits at the summer break: signups from January to June belong to the
	 * spring term, everything else to the autumn term of the same calendar year.
	 *
	 * @param \DateTimeInterface $date Date to classify.
	 * @return array{year: int, season: string} The term.
	 */
	public static function from_date( \DateTimeInterface $date ): array {
		$month = (int) $date->format( 'n' );

		return [
			'year'   => (int) $date->format( 'Y' ),
			'season' => $month <= 6 ? self::SEASON_SPRING : self::SEASON_AUTUMN,
		];
	}

	/**
	 * The term the site is currently working in.
	 *
	 * Read from settings when an administrator has pinned one, so that signups arriving just
	 * before a term starts can still be filed against it. Falls back to today's date.
	 *
	 * @return array{year: int, season: string} The term.
	 */
	public static function current(): array {
		$settings = get_option( 'rockaden_skolschack_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];

		$year   = isset( $settings['term_year'] ) ? (int) $settings['term_year'] : 0;
		$season = isset( $settings['term_season'] ) ? (string) $settings['term_season'] : '';

		if ( $year > 0 && self::is_valid_season( $season ) ) {
			return [
				'year'   => $year,
				'season' => $season,
			];
		}

		return self::from_date( new \DateTimeImmutable( 'now', wp_timezone() ) );
	}

	/**
	 * Human-readable term name, for example "HT 2026".
	 *
	 * @param int    $year   Term year.
	 * @param string $season Season code.
	 * @return string Display label.
	 */
	public static function label( int $year, string $season ): string {
		// Not translated: VT and HT are the club's own vocabulary, and the year is a number.
		return $season . ' ' . $year;
	}

	/**
	 * Season labels for use in a dropdown.
	 *
	 * @return array<string, string> Season code mapped to its name.
	 */
	public static function season_labels(): array {
		return [
			self::SEASON_SPRING => __( 'Spring term', 'rockaden-skolschack' ),
			self::SEASON_AUTUMN => __( 'Autumn term', 'rockaden-skolschack' ),
		];
	}
}
