<?php
/**
 * The schools list.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Data\SignupRepository;
use Rockaden\Skolschack\PostTypes\School;
use Rockaden\Skolschack\Services\Capabilities;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Schools, as a screen of our own rather than WordPress's post list.
 *
 * The post list was tolerable while only administrators saw it. It is not once coordinators do: it
 * shows every school, and a school is not an article, so its editor should not be the post editor.
 *
 * Scoping happens where the rows are fetched, not here.
 */
class SchoolsListTable extends \WP_List_Table {

	/**
	 * How many children each school has, keyed by post id.
	 *
	 * @var array<int, int>
	 */
	private array $counts = [];

	/**
	 * Set up the table.
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => 'school',
				'plural'   => 'schools',
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
			'name'         => __( 'School', 'rockaden-skolschack' ),
			'coordinators' => __( 'Coordinators', 'rockaden-skolschack' ),
			'when'         => __( 'When', 'rockaden-skolschack' ),
			'fee'          => __( 'Term fee', 'rockaden-skolschack' ),
			'children'     => __( 'Children', 'rockaden-skolschack' ),
			'status'       => __( 'Status', 'rockaden-skolschack' ),
		];
	}

	/**
	 * Fetch the schools this person may see.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$args = [
			'post_type'        => School::POST_TYPE,
			'post_status'      => [ 'publish', 'draft' ],
			'numberposts'      => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		];

		$visible = Capabilities::visible_school_ids();

		if ( null !== $visible ) {
			if ( [] === $visible ) {
				$this->items           = [];
				$this->_column_headers = [ $this->get_columns(), [], [] ];

				return;
			}

			$args['include'] = $visible;
		}

		$this->items           = get_posts( $args );
		$this->_column_headers = [ $this->get_columns(), [], [] ];
		$this->counts          = SignupRepository::count_per_school_id();
	}

	/**
	 * What to show when there is nothing.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No schools yet.', 'rockaden-skolschack' );
	}

	/**
	 * Render any column without its own method.
	 *
	 * @param \WP_Post $item        The school.
	 * @param string   $column_name Column key.
	 * @return string Cell contents.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'when':
				return esc_html( School::meeting_time( (int) $item->ID ) );

			case 'fee':
				$fee = School::fee( (int) $item->ID );

				if ( School::fee_is_inherited( (int) $item->ID ) ) {
					return sprintf(
						'%s <span class="description">(%s)</span>',
						esc_html( (string) $fee ),
						esc_html__( 'default', 'rockaden-skolschack' )
					);
				}

				return esc_html( (string) $fee );

			case 'status':
				return 'draft' === $item->post_status
					? '<span class="description">' . esc_html__( 'Closed', 'rockaden-skolschack' ) . '</span>'
					: esc_html__( 'Open for signups', 'rockaden-skolschack' );

			default:
				return '';
		}
	}

	/**
	 * The school's name, linking to its form.
	 *
	 * @param \WP_Post $item The school.
	 * @return string Cell contents.
	 */
	public function column_name( $item ): string {
		$link = add_query_arg(
			[
				'page'   => SchoolsPage::PAGE_SLUG,
				'school' => (int) $item->ID,
			],
			admin_url( 'admin.php' )
		);

		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong><div class="row-actions"><span class="edit"><a href="%1$s">%3$s</a></span></div>',
			esc_url( $link ),
			esc_html( $item->post_title ),
			esc_html__( 'Open', 'rockaden-skolschack' )
		);
	}

	/**
	 * Who coordinates this school.
	 *
	 * @param \WP_Post $item The school.
	 * @return string Cell contents.
	 */
	public function column_coordinators( $item ): string {
		$names = [];

		foreach ( Capabilities::coordinators_of( (int) $item->ID ) as $user_id ) {
			$user = get_userdata( $user_id );

			if ( $user instanceof \WP_User ) {
				$names[] = $user->display_name;
			}
		}

		return [] === $names
			? '<em>' . esc_html__( 'None assigned', 'rockaden-skolschack' ) . '</em>'
			: esc_html( implode( ', ', $names ) );
	}

	/**
	 * How many children are registered here, linking to them.
	 *
	 * @param \WP_Post $item The school.
	 * @return string Cell contents.
	 */
	public function column_children( $item ): string {
		$count = $this->counts[ (int) $item->ID ] ?? 0;

		if ( 0 === $count ) {
			return '<span class="description">0</span>';
		}

		$link = add_query_arg(
			[
				'page'       => SignupsPage::PAGE_SLUG,
				'rsk_school' => (int) $item->ID,
			],
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( (string) $count ) );
	}
}
