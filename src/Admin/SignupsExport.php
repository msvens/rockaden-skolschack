<?php
/**
 * CSV export of the signups list.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Data\SignupRepository;
use Rockaden\Skolschack\Services\Capabilities;
use Rockaden\Skolschack\Services\Terms;

defined( 'ABSPATH' ) || exit;

/**
 * The spreadsheet the club actually works from.
 *
 * CSV rather than a real spreadsheet format, because it opens everywhere, needs no library, and
 * survives being mailed around. The byte order mark is what makes Excel read it as UTF-8 instead of
 * mangling every Swedish name.
 *
 * It honours the same filters as the screen and, more importantly, the same scoping: the rows come
 * from the same repository call, so a coordinator's export cannot contain another school's children.
 */
class SignupsExport {

	/**
	 * The admin-post action name.
	 *
	 * @var string
	 */
	public const ACTION = 'rsk_export_signups';

	/**
	 * How many rows to pull per batch.
	 *
	 * @var int
	 */
	private const BATCH = 200;

	/**
	 * Hook the handler up.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Stream the export.
	 *
	 * Nonce first, capability second, matching the theme's settings handler.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'rockaden-skolschack' ) );
		}

		if ( ! current_user_can( Capabilities::EXPORT_SIGNUPS ) ) {
			wp_die( esc_html__( 'You do not have permission to export signups.', 'rockaden-skolschack' ) );
		}

		$filters = SignupsPage::current_filters();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . self::filename() );

		// Written straight to the response rather than through a file handle. The export is a
		// stream, not a file: there is nothing on disk for WP_Filesystem to operate on, and
		// echoing each line keeps the whole thing to plain string handling.
		//
		// Excel reads a CSV as the system codepage unless it opens with a byte order mark, which
		// is what mangles Swedish names in a spreadsheet that is otherwise perfectly good UTF-8.
		echo "\xEF\xBB\xBF";
		echo self::csv_line( self::headings() );

		$page = 1;

		do {
			$filters['page']     = $page;
			$filters['per_page'] = self::BATCH;

			$rows      = SignupRepository::query( $filters );
			$guardians = SignupRepository::guardians_for(
				array_map( static fn ( object $row ): int => (int) $row->id, $rows )
			);

			foreach ( $rows as $row ) {
				echo self::csv_line( self::row( $row, $guardians[ (int) $row->id ] ?? [] ) );
			}

			$fetched = count( $rows );
			++$page;
		} while ( self::BATCH === $fetched );

		exit;
	}

	/**
	 * One CSV record, quoted per RFC 4180.
	 *
	 * A field is wrapped in quotes when it contains a quote, a comma, a semicolon or a line
	 * break, and any quote inside it is doubled. A leading character that a spreadsheet would
	 * read as a formula is prefixed with a quote, so a name beginning with = cannot become an
	 * executable cell in somebody's Excel.
	 *
	 * @param array<int, string> $fields Values in column order.
	 * @return string The record, with its line ending.
	 */
	private static function csv_line( array $fields ): string {
		$escaped = [];

		foreach ( $fields as $field ) {
			$value = (string) $field;

			if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@' ], true ) ) {
				$value = "'" . $value;
			}

			if ( 1 === preg_match( '/["\r\n,;]/', $value ) ) {
				$value = '"' . str_replace( '"', '""', $value ) . '"';
			}

			$escaped[] = $value;
		}

		return implode( ',', $escaped ) . "\r\n";
	}

	/**
	 * A filename carrying the date, so successive exports do not overwrite each other.
	 *
	 * @return string Filename.
	 */
	private static function filename(): string {
		return 'skolschack-' . gmdate( 'Y-m-d' ) . '.csv';
	}

	/**
	 * Column headings.
	 *
	 * @return array<int, string> Headings.
	 */
	private static function headings(): array {
		return [
			__( 'First name', 'rockaden-skolschack' ),
			__( 'Last name', 'rockaden-skolschack' ),
			__( 'Personnummer', 'rockaden-skolschack' ),
			__( 'Birthdate', 'rockaden-skolschack' ),
			__( 'Gender', 'rockaden-skolschack' ),
			__( 'School', 'rockaden-skolschack' ),
			__( 'Class at signup', 'rockaden-skolschack' ),
			__( 'Term', 'rockaden-skolschack' ),
			__( 'Street', 'rockaden-skolschack' ),
			__( 'Postal code', 'rockaden-skolschack' ),
			__( 'City', 'rockaden-skolschack' ),
			__( 'Guardian email', 'rockaden-skolschack' ),
			__( 'Guardian phone', 'rockaden-skolschack' ),
			__( 'Other guardians', 'rockaden-skolschack' ),
			__( 'Registered', 'rockaden-skolschack' ),
			__( 'Needs checking', 'rockaden-skolschack' ),
		];
	}

	/**
	 * One row.
	 *
	 * @param object             $row       The signup.
	 * @param array<int, object> $guardians Its guardians, primary first.
	 * @return array<int, string> Cell values.
	 */
	private static function row( object $row, array $guardians ): array {
		$primary = $guardians[0] ?? null;
		$others  = array_slice( $guardians, 1 );

		$other_contacts = [];
		foreach ( $others as $guardian ) {
			$other_contacts[] = trim( $guardian->email . ' ' . $guardian->phone );
		}

		return [
			(string) $row->child_first_name,
			(string) $row->child_last_name,
			(string) ( $row->child_personnummer ?? '' ),
			(string) $row->child_birthdate,
			(string) $row->child_gender,
			(string) $row->school_name,
			(string) $row->child_class,
			Terms::label( (int) $row->term_year, (string) $row->term_season ),
			(string) $row->street,
			(string) $row->postal_code,
			(string) $row->city,
			null === $primary ? '' : (string) $primary->email,
			null === $primary ? '' : (string) $primary->phone,
			implode( '; ', $other_contacts ),
			mysql2date( 'Y-m-d', (string) $row->created_at ),
			(string) $row->flags,
		];
	}
}
