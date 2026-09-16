<?php
/**
 * The form for one school.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Data\SignupRepository;
use Rockaden\Skolschack\PostTypes\School;
use Rockaden\Skolschack\Services\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * One school: when the group meets, what it costs, and who runs it.
 *
 * A coordinator maintains their own school here in full, the fee and the coordinator list included.
 * Adding somebody to that list genuinely grants them access to this school's children, which is
 * intended, and is why the check on save matters rather than the absence of a link.
 */
class SchoolEditScreen {

	/**
	 * Render the form, for an existing school or a new one.
	 *
	 * @param \WP_Post|null $school The school, or null when adding.
	 * @return void
	 */
	public static function render( ?\WP_Post $school ): void {
		$is_new = null === $school;
		$id     = $is_new ? 0 : (int) $school->ID;

		echo '<div class="wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1>',
			esc_html( $is_new ? __( 'Add school', 'rockaden-skolschack' ) : $school->post_title )
		);
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . SchoolsPage::PAGE_SLUG ) ),
			esc_html__( 'Back to the list', 'rockaden-skolschack' )
		);
		echo '<hr class="wp-header-end" />';

		self::render_saved_notice();
		self::render_problems( $id );

		if ( ! $is_new ) {
			self::render_children_link( $id );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( SchoolActions::SAVE ) );
		printf( '<input type="hidden" name="school" value="%s" />', esc_attr( (string) $id ) );
		wp_nonce_field( SchoolActions::SAVE, SchoolActions::NONCE );

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="rsk-title">%s</label></th><td><input type="text" id="rsk-title" name="school_name" value="%s" class="regular-text" required /></td></tr>',
			esc_html__( 'School name', 'rockaden-skolschack' ),
			esc_attr( $is_new ? '' : $school->post_title )
		);

		self::weekday_row( $is_new ? 0 : (int) get_post_meta( $id, School::META_WEEKDAY, true ) );
		self::time_row(
			$is_new ? '' : (string) get_post_meta( $id, School::META_TIME_START, true ),
			$is_new ? '' : (string) get_post_meta( $id, School::META_TIME_END, true )
		);
		self::text_row( 'room', __( 'Room', 'rockaden-skolschack' ), $is_new ? '' : (string) get_post_meta( $id, School::META_ROOM, true ), __( 'Free text: a room number, a name, or wherever the group actually sits.', 'rockaden-skolschack' ) );
		self::text_row(
			'contact',
			__( 'Contact for guardians', 'rockaden-skolschack' ),
			$is_new ? '' : (string) get_post_meta( $id, School::META_CONTACT, true ),
			__( 'Printed in the confirmation email, exactly as written. One name and number, since a parent wants somebody to call rather than a list. Blank falls back to the club address in settings.', 'rockaden-skolschack' )
		);
		self::fee_row( $is_new ? '' : (string) get_post_meta( $id, School::META_FEE, true ) );
		self::status_row( $is_new ? 'publish' : (string) $school->post_status );
		self::notes_row( $is_new ? '' : (string) get_post_meta( $id, School::META_NOTE, true ) );

		echo '<tr><th scope="row">' . esc_html__( 'Coordinators', 'rockaden-skolschack' ) . '</th><td>';
		self::coordinator_picker( $is_new ? [] : Capabilities::coordinators_of( $id ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( "Anyone ticked here can see and edit this school's signups, and can change this page. Ticking nobody is fine; the school simply has no coordinator yet.", 'rockaden-skolschack' )
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( $is_new ? __( 'Add school', 'rockaden-skolschack' ) : __( 'Save', 'rockaden-skolschack' ) );
		echo '</form>';

		if ( ! $is_new ) {
			self::render_delete( $id );
		}

		echo '</div>';
	}

	/**
	 * Say so when a save has just succeeded.
	 *
	 * The marker arrives on our own redirect and is signed, so a crafted URL cannot make the page
	 * claim a save that never happened.
	 *
	 * @return void
	 */
	public static function render_saved_notice(): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$saved = isset( $_GET['rsk_saved'] );

		if ( ! $saved || '' === $nonce || ! wp_verify_nonce( $nonce, SchoolActions::SAVED_NOTICE ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Saved.', 'rockaden-skolschack' )
		);
	}

	/**
	 * Show anything that went wrong on the last attempt.
	 *
	 * @param int $school_id School post id.
	 * @return void
	 */
	private static function render_problems( int $school_id ): void {
		$stored = get_transient( SchoolActions::failure_key( $school_id ) );

		if ( ! is_array( $stored ) || [] === $stored ) {
			return;
		}

		delete_transient( SchoolActions::failure_key( $school_id ) );

		foreach ( $stored as $message ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( (string) $message ) );
		}
	}

	/**
	 * A link through to this school's children.
	 *
	 * @param int $school_id School post id.
	 * @return void
	 */
	private static function render_children_link( int $school_id ): void {
		$count = SignupRepository::count_per_school_id()[ $school_id ] ?? 0;

		if ( 0 === $count ) {
			return;
		}

		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url(
				add_query_arg(
					[
						'page'       => SignupsPage::PAGE_SLUG,
						'rsk_school' => $school_id,
					],
					admin_url( 'admin.php' )
				)
			),
			esc_html(
				sprintf(
					/* translators: %d: how many children are registered at this school. */
					_n( 'Show the %d child registered here', 'Show the %d children registered here', $count, 'rockaden-skolschack' ),
					$count
				)
			)
		);
	}

	/**
	 * One labelled text input row.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Visible label.
	 * @param string $current     Current value.
	 * @param string $description Optional help text.
	 * @return void
	 */
	private static function text_row( string $name, string $label, string $current, string $description = '' ): void {
		printf(
			'<tr><th scope="row"><label for="rsk-%1$s">%2$s</label></th><td><input type="text" id="rsk-%1$s" name="%1$s" value="%3$s" class="regular-text" />',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $current )
		);

		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}

		echo '</td></tr>';
	}

	/**
	 * The weekday control.
	 *
	 * @param int $current Current ISO weekday.
	 * @return void
	 */
	private static function weekday_row( int $current ): void {
		echo '<tr><th scope="row"><label for="rsk-weekday">' . esc_html__( 'Weekday', 'rockaden-skolschack' ) . '</label></th><td>';
		echo '<select id="rsk-weekday" name="weekday">';
		printf( '<option value="0" %s>%s</option>', selected( $current, 0, false ), esc_html__( '— not set —', 'rockaden-skolschack' ) );

		foreach ( School::weekday_labels() as $iso => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $iso ),
				selected( $current, $iso, false ),
				esc_html( $label )
			);
		}

		echo '</select></td></tr>';
	}

	/**
	 * The start and end time controls.
	 *
	 * @param string $start Current start time.
	 * @param string $end   Current end time.
	 * @return void
	 */
	private static function time_row( string $start, string $end ): void {
		echo '<tr><th scope="row"><label for="rsk-time-start">' . esc_html__( 'Time', 'rockaden-skolschack' ) . '</label></th><td>';
		printf(
			'<input type="time" id="rsk-time-start" name="time_start" value="%1$s" /> &ndash; <input type="time" id="rsk-time-end" name="time_end" value="%2$s" />',
			esc_attr( $start ),
			esc_attr( $end )
		);
		echo '</td></tr>';
	}

	/**
	 * The fee control, showing what blank would inherit.
	 *
	 * @param string $current Current value, blank meaning inherit.
	 * @return void
	 */
	private static function fee_row( string $current ): void {
		$default = (int) SettingsPage::get()['default_fee'];

		echo '<tr><th scope="row"><label for="rsk-fee">' . esc_html__( 'Term fee', 'rockaden-skolschack' ) . '</label></th><td>';
		printf(
			'<input type="number" id="rsk-fee" name="fee" value="%1$s" min="0" step="10" class="small-text" placeholder="%2$s" /> %3$s',
			esc_attr( $current ),
			esc_attr( (string) $default ),
			esc_html__( 'kronor', 'rockaden-skolschack' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: the club-wide default fee in kronor. */
					__( 'Leave blank to use the club default of %d kronor. Enter a number, zero included, to override it for this school.', 'rockaden-skolschack' ),
					$default
				)
			)
		);
		echo '</td></tr>';
	}

	/**
	 * The open or closed control.
	 *
	 * @param string $current Current post status.
	 * @return void
	 */
	private static function status_row( string $current ): void {
		$options = [
			'publish' => __( 'Open for signups', 'rockaden-skolschack' ),
			'draft'   => __( 'Closed', 'rockaden-skolschack' ),
		];

		echo '<tr><th scope="row"><label for="rsk-status">' . esc_html__( 'Status', 'rockaden-skolschack' ) . '</label></th><td>';
		echo '<select id="rsk-status" name="status">';
		foreach ( $options as $code => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $code ),
				selected( $current, $code, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'A closed school keeps its history but disappears from the public signup form.', 'rockaden-skolschack' )
		);
		echo '</td></tr>';
	}

	/**
	 * The notes control.
	 *
	 * @param string $current Current value.
	 * @return void
	 */
	private static function notes_row( string $current ): void {
		echo '<tr><th scope="row"><label for="rsk-note">' . esc_html__( 'Notes', 'rockaden-skolschack' ) . '</label></th><td>';
		printf( '<textarea id="rsk-note" name="note" rows="3" class="large-text">%s</textarea>', esc_textarea( $current ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Anything the fields above cannot hold: a second session for younger children, a later start date, a room not decided yet.', 'rockaden-skolschack' )
		);
		echo '</td></tr>';
	}

	/**
	 * The coordinator picker.
	 *
	 * @param array<int, int> $assigned Currently assigned user ids.
	 * @return void
	 */
	private static function coordinator_picker( array $assigned ): void {
		$users = get_users(
			[
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => 200,
			]
		);

		if ( [] === $users ) {
			echo '<p>' . esc_html__( 'No users to assign.', 'rockaden-skolschack' ) . '</p>';

			return;
		}

		echo '<fieldset style="max-height:16em;overflow:auto;border:1px solid #dcdcde;padding:.5em .75em;">';
		echo '<legend class="screen-reader-text">' . esc_html__( 'Coordinators', 'rockaden-skolschack' ) . '</legend>';

		foreach ( $users as $user ) {
			printf(
				'<label style="display:block;margin:.25em 0;"><input type="checkbox" name="coordinators[]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( (string) $user->ID ),
				checked( in_array( (int) $user->ID, $assigned, true ), true, false ),
				esc_html( $user->display_name . ' (' . $user->user_email . ')' )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * The delete control, for administrators only.
	 *
	 * @param int $school_id School post id.
	 * @return void
	 */
	private static function render_delete( int $school_id ): void {
		if ( ! current_user_can( Capabilities::MANAGE_SCHOOLS ) ) {
			return;
		}

		$children = SignupRepository::count_per_school_id()[ $school_id ] ?? 0;

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Delete this school', 'rockaden-skolschack' ) . '</h2>';

		if ( $children > 0 ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: how many children are registered at this school. */
						__( 'This school cannot be deleted: %d children are registered here and their records point at it. Set it to Closed instead, which keeps the history and removes it from the signup form.', 'rockaden-skolschack' ),
						$children
					)
				)
			);

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( SchoolActions::DELETE ) );
		printf( '<input type="hidden" name="school" value="%s" />', esc_attr( (string) $school_id ) );
		wp_nonce_field( SchoolActions::DELETE, SchoolActions::NONCE );
		printf(
			'<p><label><input type="checkbox" name="confirm" value="1" /> %s</label></p>',
			esc_html__( 'Yes, delete this school', 'rockaden-skolschack' )
		);
		submit_button( __( 'Delete', 'rockaden-skolschack' ), 'delete', 'submit', false );
		echo '</form>';
	}
}
