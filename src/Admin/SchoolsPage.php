<?php
/**
 * The schools screen.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\PostTypes\School;
use Rockaden\Skolschack\Services\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Schools, on a screen of our own.
 *
 * Dispatches the same way the signups screen does: the list, unless a school is named, in which
 * case its form. One menu entry and one slug.
 */
class SchoolsPage {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'rockaden-skolschack-schools';

	/**
	 * Render whichever view was asked for.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! Capabilities::can_see_any_school() ) {
			wp_die( esc_html__( 'You do not have permission to view schools.', 'rockaden-skolschack' ) );
		}

		$requested = isset( $_GET['school'] ) ? sanitize_text_field( wp_unslash( $_GET['school'] ) ) : '';

		if ( 'new' === $requested ) {
			if ( ! current_user_can( Capabilities::MANAGE_SCHOOLS ) ) {
				wp_die( esc_html__( 'Only an administrator can add a school.', 'rockaden-skolschack' ) );
			}

			SchoolEditScreen::render( null );

			return;
		}

		$school_id = absint( $requested );

		if ( $school_id > 0 ) {
			$school = get_post( $school_id );

			if ( ! $school instanceof \WP_Post || School::POST_TYPE !== $school->post_type
				|| ! Capabilities::can_edit_school( $school_id ) ) {
				wp_die( esc_html__( 'That school does not exist, or you do not coordinate it.', 'rockaden-skolschack' ) );
			}

			SchoolEditScreen::render( $school );

			return;
		}

		self::render_list();
	}

	/**
	 * The list of schools.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		$table = new SchoolsListTable();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Schools', 'rockaden-skolschack' ) . '</h1>';

		if ( current_user_can( Capabilities::MANAGE_SCHOOLS ) ) {
			printf(
				' <a href="%s" class="page-title-action">%s</a>',
				esc_url(
					add_query_arg(
						[
							'page'   => self::PAGE_SLUG,
							'school' => 'new',
						],
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Add school', 'rockaden-skolschack' )
			);
		}

		echo '<hr class="wp-header-end" />';

		if ( ! Capabilities::can_see_all_schools() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'You are seeing the schools you coordinate.', 'rockaden-skolschack' )
			);
		}

		SchoolEditScreen::render_saved_notice();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::PAGE_SLUG ) );
		$table->display();
		echo '</form>';
		echo '</div>';
	}
}
