<?php
/**
 * The signups screen.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Data\SignupRepository;
use Rockaden\Skolschack\PostTypes\School;
use Rockaden\Skolschack\Services\Capabilities;
use Rockaden\Skolschack\Services\Terms;

defined( 'ABSPATH' ) || exit;

/**
 * The screen a coordinator actually works from.
 *
 * Everything here reads. The filters narrow what is shown; they never widen it, because the query
 * behind them is already limited to the schools this person coordinates.
 */
class SignupsPage {

	/**
	 * Admin page slug. Also the plugin's top-level menu slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'rockaden-skolschack';

	/**
	 * Read the filters out of the query string.
	 *
	 * Read-only screen, so there is no nonce here: these arguments cannot change anything, and the
	 * rows they can reach are already fixed by the viewer's own access.
	 *
	 * @return array<string, mixed> Filters for the repository.
	 */
	public static function current_filters(): array {
		$term = isset( $_GET['rsk_term'] ) ? sanitize_text_field( wp_unslash( $_GET['rsk_term'] ) ) : '';
		$year = 0;
		$code = '';

		if ( 1 === preg_match( '/^(\d{4})-(VT|HT)$/', $term, $m ) ) {
			$year = (int) $m[1];
			$code = $m[2];
		}

		$filters = [
			'school_id'       => isset( $_GET['rsk_school'] ) ? absint( wp_unslash( $_GET['rsk_school'] ) ) : 0,
			'term_year'       => $year,
			'term_season'     => $code,
			'needs_attention' => ! empty( $_GET['rsk_attention'] ),
			'search'          => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'         => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '',
			'order'           => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
		];

		return $filters;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_SIGNUPS ) ) {
			wp_die( esc_html__( 'You do not have permission to view signups.', 'rockaden-skolschack' ) );
		}

		$requested = isset( $_GET['signup'] ) ? absint( wp_unslash( $_GET['signup'] ) ) : 0;

		if ( $requested > 0 ) {
			// find() narrows to this person's schools, so an id they may not reach comes back as
			// nothing and is refused as though it did not exist.
			$signup = SignupRepository::find( $requested );

			if ( null === $signup ) {
				wp_die( esc_html__( 'That signup does not exist, or it belongs to a school you do not coordinate.', 'rockaden-skolschack' ) );
			}

			$failure = get_transient( SignupActions::failure_key( $requested ) );
			$failure = is_array( $failure ) ? $failure : [];

			if ( [] !== $failure ) {
				delete_transient( SignupActions::failure_key( $requested ) );
			}

			$guardians = [];
			foreach ( $failure['guardians'] ?? [] as $typed ) {
				$guardians[] = (object) [
					'name'       => (string) ( $typed['name'] ?? '' ),
					'email'      => (string) ( $typed['email'] ?? '' ),
					'phone'      => (string) ( $typed['phone'] ?? '' ),
					'is_primary' => ! empty( $typed['is_primary'] ) ? 1 : 0,
				];
			}

			SignupEditScreen::render(
				$signup,
				[] === $guardians ? SignupRepository::guardians_of( $requested ) : $guardians,
				$failure['problems'] ?? [],
				$failure['fields'] ?? []
			);

			return;
		}

		$table = new SignupsListTable();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Signups', 'rockaden-skolschack' ) . '</h1>';
		self::render_export_button();
		echo '<hr class="wp-header-end" />';

		self::render_scope_notice();
		self::render_attention_notice();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::PAGE_SLUG ) );
		self::render_filters();
		$table->search_box( __( 'Search children', 'rockaden-skolschack' ), 'rsk-search' );
		echo '</form>';

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::PAGE_SLUG ) );
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Tell a coordinator which schools they are looking at.
	 *
	 * Without a role there is nothing in the Users screen to say somebody coordinates anything, so
	 * the list itself has to say it.
	 *
	 * @return void
	 */
	private static function render_scope_notice(): void {
		if ( Capabilities::can_see_all_schools() ) {
			return;
		}

		$names = array_map( 'get_the_title', Capabilities::coordinator_school_ids() );

		if ( [] === $names ) {
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of school names. */
					__( 'You are seeing signups for %s.', 'rockaden-skolschack' ),
					implode( ', ', $names )
				)
			)
		);
	}

	/**
	 * Point out anything that needs a person to look at it.
	 *
	 * @return void
	 */
	private static function render_attention_notice(): void {
		$count = SignupRepository::count_matching( [ 'needs_attention' => true ] );

		if ( 0 === $count ) {
			return;
		}

		$link = add_query_arg(
			[
				'page'          => self::PAGE_SLUG,
				'rsk_attention' => '1',
			],
			admin_url( 'admin.php' )
		);

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: how many signups need checking. */
					_n(
						'%d signup needs checking.',
						'%d signups need checking.',
						$count,
						'rockaden-skolschack'
					),
					$count
				)
			),
			esc_url( $link ),
			esc_html__( 'Show them', 'rockaden-skolschack' )
		);
	}

	/**
	 * The school and term dropdowns.
	 *
	 * The school list offers only schools the viewer may see. That is for their convenience; the
	 * query narrows independently, so picking something else by hand achieves nothing.
	 *
	 * @return void
	 */
	private static function render_filters(): void {
		$filters = self::current_filters();

		echo '<div class="tablenav top"><div class="alignleft actions">';

		printf( '<select name="rsk_school"><option value="0">%s</option>', esc_html__( 'All schools', 'rockaden-skolschack' ) );
		foreach ( self::visible_schools() as $school ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $school->ID,
				selected( $filters['school_id'], (int) $school->ID, false ),
				esc_html( $school->post_title )
			);
		}
		echo '</select>';

		$selected_term = 0 === $filters['term_year'] ? '' : $filters['term_year'] . '-' . $filters['term_season'];

		printf( '<select name="rsk_term"><option value="">%s</option>', esc_html__( 'All terms', 'rockaden-skolschack' ) );
		foreach ( SignupRepository::terms_in_use() as $term ) {
			$value = $term->term_year . '-' . $term->term_season;
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected_term, $value, false ),
				esc_html( Terms::label( (int) $term->term_year, (string) $term->term_season ) )
			);
		}
		echo '</select>';

		printf(
			'<label style="margin-left:.5em;"><input type="checkbox" name="rsk_attention" value="1" %s /> %s</label>',
			checked( ! empty( $filters['needs_attention'] ), true, false ),
			esc_html__( 'Only those needing a check', 'rockaden-skolschack' )
		);

		submit_button( __( 'Filter', 'rockaden-skolschack' ), '', '', false, [ 'style' => 'margin-left:.5em;' ] );

		echo '</div></div>';
	}

	/**
	 * Schools the current viewer may see.
	 *
	 * @return array<int, \WP_Post> Schools.
	 */
	private static function visible_schools(): array {
		$args = [
			'post_type'        => School::POST_TYPE,
			'post_status'      => [ 'publish', 'draft' ],
			'numberposts'      => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		];

		if ( ! Capabilities::can_see_all_schools() ) {
			$mine = Capabilities::coordinator_school_ids();

			if ( [] === $mine ) {
				return [];
			}

			$args['include'] = $mine;
		}

		return get_posts( $args );
	}

	/**
	 * The export button, carrying the current filters.
	 *
	 * @return void
	 */
	private static function render_export_button(): void {
		if ( ! current_user_can( Capabilities::EXPORT_SIGNUPS ) ) {
			return;
		}

		$filters = self::current_filters();

		$url = add_query_arg(
			[
				'action'        => SignupsExport::ACTION,
				'rsk_school'    => $filters['school_id'],
				'rsk_term'      => 0 === $filters['term_year'] ? '' : $filters['term_year'] . '-' . $filters['term_season'],
				'rsk_attention' => $filters['needs_attention'] ? '1' : '',
				's'             => $filters['search'],
				'_wpnonce'      => wp_create_nonce( SignupsExport::ACTION ),
			],
			admin_url( 'admin-post.php' )
		);

		printf(
			'<a href="%s" class="page-title-action">%s</a>',
			esc_url( $url ),
			esc_html__( 'Export CSV', 'rockaden-skolschack' )
		);
	}
}
