<?php
/**
 * The signups list.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Data\SignupRepository;
use Rockaden\Skolschack\Services\DataQuality;
use Rockaden\Skolschack\Services\Terms;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * A standard WordPress list table over the signups.
 *
 * WP_List_Table is marked private in core and has been for years, while remaining what every plugin
 * uses for exactly this. The alternative is hand-rolling pagination, sortable headers and the search
 * box, which would look almost but not quite like the rest of the admin. This is the pragmatic
 * choice rather than the pure one.
 *
 * The table only ever displays what the repository hands it. It performs no access checks of its
 * own, because the query is already narrowed to what the viewer may see.
 */
class SignupsListTable extends \WP_List_Table {

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	private const PER_PAGE = 50;

	/**
	 * Guardians for the rows on this page, keyed by signup id.
	 *
	 * @var array<int, array<int, object>>
	 */
	private array $guardians = [];

	/**
	 * Set up the table.
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => 'signup',
				'plural'   => 'signups',
				'ajax'     => false,
			]
		);
	}

	/**
	 * The columns, in order.
	 *
	 * @return array<string, string> Column key mapped to its heading.
	 */
	public function get_columns(): array {
		return [
			'name'       => __( 'Child', 'rockaden-skolschack' ),
			'identity'   => __( 'Personnummer', 'rockaden-skolschack' ),
			'school'     => __( 'School', 'rockaden-skolschack' ),
			'class'      => __( 'Class at signup', 'rockaden-skolschack' ),
			'term'       => __( 'Term', 'rockaden-skolschack' ),
			'guardian'   => __( 'Guardian', 'rockaden-skolschack' ),
			'registered' => __( 'Registered', 'rockaden-skolschack' ),
		];
	}

	/**
	 * Which columns the headings can sort by.
	 *
	 * The keys match the repository's own allowlist; anything else in the URL is ignored there.
	 *
	 * @return array<string, array<int, mixed>> Column key mapped to its sort definition.
	 */
	public function get_sortable_columns(): array {
		return [
			'name'       => [ 'name', false ],
			'school'     => [ 'school', false ],
			'class'      => [ 'class', false ],
			'term'       => [ 'term', false ],
			'registered' => [ 'registered', true ],
		];
	}

	/**
	 * Fetch the rows for the current page.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$args = SignupsPage::current_filters();

		$args['page']     = $this->get_pagenum();
		$args['per_page'] = self::PER_PAGE;

		$items = SignupRepository::query( $args );
		$total = SignupRepository::count_matching( $args );

		$this->items     = $items;
		$this->guardians = SignupRepository::guardians_for(
			array_map( static fn ( object $row ): int => (int) $row->id, $items )
		);

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			]
		);
	}

	/**
	 * What to show when there is nothing.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No signups match.', 'rockaden-skolschack' );
	}

	/**
	 * Render any column without its own method.
	 *
	 * @param object $item        The signup row.
	 * @param string $column_name Column key.
	 * @return string Cell contents.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'school':
				$name = (string) $item->school_name;

				return '' === $name
					? '<em>' . esc_html__( 'None', 'rockaden-skolschack' ) . '</em>'
					: esc_html( $name );

			case 'class':
				// Recorded once by the parent at signup and never updated, so a child who
				// has been coming for years still shows the class they started in. The
				// heading says so rather than the value pretending to be current.
				return esc_html( (string) $item->child_class );

			case 'term':
				return esc_html( Terms::label( (int) $item->term_year, (string) $item->term_season ) );

			case 'registered':
				return esc_html( mysql2date( 'Y-m-d', (string) $item->created_at ) );

			default:
				return '';
		}
	}

	/**
	 * The child's name, with any warnings underneath.
	 *
	 * @param object $item The signup row.
	 * @return string Cell contents.
	 */
	public function column_name( $item ): string {
		$name = trim( $item->child_first_name . ' ' . $item->child_last_name );
		$link = add_query_arg(
			[
				'page'   => SignupsPage::PAGE_SLUG,
				'signup' => (int) $item->id,
			],
			admin_url( 'admin.php' )
		);

		$cell = sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>',
			esc_url( $link ),
			esc_html( $name )
		);

		$cell .= sprintf(
			'<div class="row-actions"><span class="edit"><a href="%1$s">%2$s</a></span></div>',
			esc_url( $link ),
			esc_html__( 'Open', 'rockaden-skolschack' )
		);

		$flags = array_filter( explode( ',', (string) $item->flags ) );

		if ( [] === $flags ) {
			return $cell;
		}

		$labels = DataQuality::labels();

		foreach ( $flags as $flag ) {
			$cell .= '<br /><span class="rsk-flag" style="color:#b32d2e;">⚠ '
				. esc_html( $labels[ $flag ] ?? $flag )
				. '</span>';
		}

		return $cell;
	}

	/**
	 * Personnummer where there is one, otherwise the birthdate.
	 *
	 * @param object $item The signup row.
	 * @return string Cell contents.
	 */
	public function column_identity( $item ): string {
		$personnummer = (string) ( $item->child_personnummer ?? '' );

		if ( '' !== $personnummer ) {
			return esc_html( substr( $personnummer, 0, 8 ) . '-' . substr( $personnummer, 8 ) );
		}

		return esc_html( (string) $item->child_birthdate )
			. ' <span class="description">' . esc_html__( '(birthdate only)', 'rockaden-skolschack' ) . '</span>';
	}

	/**
	 * The primary guardian's contact details.
	 *
	 * @param object $item The signup row.
	 * @return string Cell contents.
	 */
	public function column_guardian( $item ): string {
		$guardians = $this->guardians[ (int) $item->id ] ?? [];

		if ( [] === $guardians ) {
			return '<em>' . esc_html__( 'None', 'rockaden-skolschack' ) . '</em>';
		}

		$first = $guardians[0];
		$cell  = '';

		if ( '' !== (string) $first->email ) {
			$cell .= '<a href="mailto:' . esc_attr( $first->email ) . '">' . esc_html( $first->email ) . '</a>';
		}

		if ( '' !== (string) $first->phone ) {
			$cell .= ( '' === $cell ? '' : '<br />' ) . esc_html( $first->phone );
		}

		if ( count( $guardians ) > 1 ) {
			$cell .= '<br /><span class="description">' . sprintf(
				/* translators: %d: how many further guardians there are. */
				esc_html( _n( '%d more guardian', '%d more guardians', count( $guardians ) - 1, 'rockaden-skolschack' ) ),
				count( $guardians ) - 1
			) . '</span>';
		}

		return $cell;
	}
}
