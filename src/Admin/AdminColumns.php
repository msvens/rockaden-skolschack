<?php
/**
 * Extra columns that make the coordinator relation visible.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Services\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Because access comes from the school assignment rather than from a role, nothing in the Users
 * screen would otherwise hint that somebody can read children's personal data. These two columns
 * make the relation legible from both directions: who runs this school, and what does this
 * person have access to.
 */
class AdminColumns {

	/**
	 * Hook both columns up.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'manage_users_columns', [ self::class, 'user_columns' ] );
		add_filter( 'manage_users_custom_column', [ self::class, 'render_user_column' ], 10, 3 );
	}

	/**
	 * Add the schools column to the users list.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string> Columns with ours appended.
	 */
	public static function user_columns( array $columns ): array {
		$columns['rsk_schools'] = __( 'School chess', 'rockaden-skolschack' );

		return $columns;
	}

	/**
	 * Render the users list cell.
	 *
	 * @param string $output  Current cell output.
	 * @param string $column  Column name.
	 * @param int    $user_id User id.
	 * @return string Cell output.
	 */
	public static function render_user_column( string $output, string $column, int $user_id ): string {
		if ( 'rsk_schools' !== $column ) {
			return $output;
		}

		$names = [];
		foreach ( Capabilities::coordinator_school_ids( $user_id ) as $school_id ) {
			$names[] = get_the_title( $school_id );
		}

		if ( [] !== $names ) {
			return esc_html( implode( ', ', $names ) );
		}

		$user = get_userdata( $user_id );
		if ( $user instanceof \WP_User && user_can( $user, Capabilities::MANAGE_SCHOOLS ) ) {
			return '<span class="description">' . esc_html__( 'Full access', 'rockaden-skolschack' ) . '</span>';
		}

		return '<span class="description">&mdash;</span>';
	}
}
