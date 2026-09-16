<?php
/**
 * The detail and edit screen for one signup.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Data\SignupRepository;
use Rockaden\Skolschack\PostTypes\School;
use Rockaden\Skolschack\Services\Capabilities;
use Rockaden\Skolschack\Services\DataQuality;
use Rockaden\Skolschack\Services\SignupValidator;
use Rockaden\Skolschack\Services\Terms;

defined( 'ABSPATH' ) || exit;

/**
 * One child, their details and their guardians.
 *
 * The screen renders whatever it is handed. It performs no access check of its own beyond the
 * capability to edit at all, because the row reached it through the repository, which has already
 * refused anything outside this person's schools.
 */
class SignupEditScreen {

	/**
	 * Render the screen for one signup.
	 *
	 * @param object                                                           $signup    The row.
	 * @param array<int, object>                                               $guardians Its guardians.
	 * @param array<int, array{field: string, level: string, message: string}> $problems  Validation
	 *                                                                                    messages from
	 *                                                                                    a failed save.
	 * @param array<string, mixed>                                             $submitted Values as
	 *                                                                                    typed, when a
	 *                                                                                    save failed.
	 * @return void
	 */
	public static function render( object $signup, array $guardians, array $problems = [], array $submitted = [] ): void {
		$value = static function ( string $key, $fallback ) use ( $submitted ) {
			return array_key_exists( $key, $submitted ) ? $submitted[ $key ] : $fallback;
		};

		echo '<div class="wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1>',
			esc_html( trim( $signup->child_first_name . ' ' . $signup->child_last_name ) )
		);
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . SignupsPage::PAGE_SLUG ) ),
			esc_html__( 'Back to the list', 'rockaden-skolschack' )
		);
		echo '<hr class="wp-header-end" />';

		self::render_saved_notice();
		self::render_problems( $problems );
		self::render_flags( $signup );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( SignupActions::SAVE ) );
		printf( '<input type="hidden" name="signup" value="%d" />', (int) $signup->id );
		wp_nonce_field( SignupActions::SAVE, SignupActions::NONCE );

		echo '<h2>' . esc_html__( 'Child', 'rockaden-skolschack' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'child_first_name', __( 'First name', 'rockaden-skolschack' ), (string) $value( 'child_first_name', $signup->child_first_name ) );
		self::text_row( 'child_last_name', __( 'Last name', 'rockaden-skolschack' ), (string) $value( 'child_last_name', $signup->child_last_name ) );
		self::text_row(
			'child_personnummer',
			__( 'Personnummer', 'rockaden-skolschack' ),
			(string) $value( 'child_personnummer', $signup->child_personnummer ?? '' ),
			__( 'Twelve digits, no hyphen. Leave blank to use a birthdate instead.', 'rockaden-skolschack' )
		);
		self::text_row( 'child_birthdate', __( 'Birthdate', 'rockaden-skolschack' ), (string) $value( 'child_birthdate', $signup->child_birthdate ), '', 'date' );
		self::gender_row( (string) $value( 'child_gender', $signup->child_gender ) );
		self::text_row(
			'child_class',
			__( 'Class at signup', 'rockaden-skolschack' ),
			(string) $value( 'child_class', $signup->child_class ),
			__( 'As recorded when the family signed up. It is not updated as the child moves up.', 'rockaden-skolschack' )
		);

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Address', 'rockaden-skolschack' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'street', __( 'Street', 'rockaden-skolschack' ), (string) $value( 'street', $signup->street ) );
		self::text_row( 'postal_code', __( 'Postal code', 'rockaden-skolschack' ), (string) $value( 'postal_code', $signup->postal_code ) );
		self::text_row( 'city', __( 'City', 'rockaden-skolschack' ), (string) $value( 'city', $signup->city ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Group', 'rockaden-skolschack' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::school_row( (int) $value( 'school_id', $signup->school_id ) );
		self::term_row( (int) $value( 'term_year', $signup->term_year ), (string) $value( 'term_season', $signup->term_season ) );
		self::status_row( (string) $value( 'status', $signup->status ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Guardians', 'rockaden-skolschack' ) . '</h2>';
		self::render_guardians( $guardians );

		submit_button( __( 'Save', 'rockaden-skolschack' ) );
		echo '</form>';

		self::render_delete( $signup );
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
	private static function render_saved_notice(): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$saved = isset( $_GET['rsk_saved'] );

		if ( ! $saved || '' === $nonce || ! wp_verify_nonce( $nonce, SignupActions::SAVED_NOTICE ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Saved.', 'rockaden-skolschack' )
		);
	}

	/**
	 * Show validation messages from a save that did not go through.
	 *
	 * @param array<int, array{field: string, level: string, message: string}> $problems Problems.
	 * @return void
	 */
	private static function render_problems( array $problems ): void {
		foreach ( $problems as $problem ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				SignupValidator::ERROR === $problem['level'] ? 'error' : 'warning',
				esc_html( $problem['message'] )
			);
		}
	}

	/**
	 * Show what this record is currently flagged for.
	 *
	 * @param object $signup The row.
	 * @return void
	 */
	private static function render_flags( object $signup ): void {
		$flags = array_filter( explode( ',', (string) $signup->flags ) );

		if ( [] === $flags ) {
			return;
		}

		$labels = DataQuality::labels();

		foreach ( $flags as $flag ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( $labels[ $flag ] ?? $flag )
			);
		}
	}

	/**
	 * One labelled text input row.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Visible label.
	 * @param string $current     Current value.
	 * @param string $description Optional help text.
	 * @param string $type        Input type.
	 * @return void
	 */
	private static function text_row( string $name, string $label, string $current, string $description = '', string $type = 'text' ): void {
		printf(
			'<tr><th scope="row"><label for="rsk-%1$s">%2$s</label></th><td><input type="%3$s" id="rsk-%1$s" name="%1$s" value="%4$s" class="regular-text" />',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $current )
		);

		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}

		echo '</td></tr>';
	}

	/**
	 * The gender control.
	 *
	 * @param string $current Current value.
	 * @return void
	 */
	private static function gender_row( string $current ): void {
		$options = [
			''  => __( '— not given —', 'rockaden-skolschack' ),
			'M' => __( 'Boy', 'rockaden-skolschack' ),
			'K' => __( 'Girl', 'rockaden-skolschack' ),
		];

		echo '<tr><th scope="row"><label for="rsk-child_gender">' . esc_html__( 'Gender', 'rockaden-skolschack' ) . '</label></th><td>';
		echo '<select id="rsk-child_gender" name="child_gender">';
		foreach ( $options as $code => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $code ),
				selected( $current, $code, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';
	}

	/**
	 * The school control, offering only schools this person may see.
	 *
	 * @param int $current Current school post id.
	 * @return void
	 */
	private static function school_row( int $current ): void {
		echo '<tr><th scope="row"><label for="rsk-school_id">' . esc_html__( 'School', 'rockaden-skolschack' ) . '</label></th><td>';
		echo '<select id="rsk-school_id" name="school_id">';
		printf( '<option value="0" %s>%s</option>', selected( $current, 0, false ), esc_html__( '— none —', 'rockaden-skolschack' ) );

		foreach ( self::selectable_schools() as $school ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $school->ID,
				selected( $current, (int) $school->ID, false ),
				esc_html( $school->post_title )
			);
		}

		echo '</select></td></tr>';
	}

	/**
	 * Schools the current user may move a child to.
	 *
	 * @return array<int, \WP_Post> Schools.
	 */
	private static function selectable_schools(): array {
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
	 * The term controls.
	 *
	 * @param int    $year   Current year.
	 * @param string $season Current season.
	 * @return void
	 */
	private static function term_row( int $year, string $season ): void {
		echo '<tr><th scope="row"><label for="rsk-term_year">' . esc_html__( 'Term', 'rockaden-skolschack' ) . '</label></th><td>';
		printf(
			'<input type="number" id="rsk-term_year" name="term_year" value="%s" min="2000" max="2100" class="small-text" /> ',
			esc_attr( (string) $year )
		);
		echo '<select name="term_season">';
		foreach ( Terms::season_labels() as $code => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $code ),
				selected( $season, $code, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';
	}

	/**
	 * The status control.
	 *
	 * @param string $current Current status.
	 * @return void
	 */
	private static function status_row( string $current ): void {
		$options = [
			'active'    => __( 'Active', 'rockaden-skolschack' ),
			'withdrawn' => __( 'Withdrawn', 'rockaden-skolschack' ),
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
			esc_html__( 'Withdrawn keeps the record but marks the child as no longer attending.', 'rockaden-skolschack' )
		);
		echo '</td></tr>';
	}

	/**
	 * The guardian rows, plus one blank row for adding another.
	 *
	 * No JavaScript: an existing guardian is removed by ticking its box, and a new one is added by
	 * filling in the blank row. Both take effect on save.
	 *
	 * @param array<int, object> $guardians Existing guardians.
	 * @return void
	 */
	private static function render_guardians( array $guardians ): void {
		echo '<table class="widefat striped" style="max-width:60em;"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Name', 'rockaden-skolschack' ) );
		printf( '<th>%s</th>', esc_html__( 'Email', 'rockaden-skolschack' ) );
		printf( '<th>%s</th>', esc_html__( 'Phone', 'rockaden-skolschack' ) );
		printf( '<th>%s</th>', esc_html__( 'Main contact', 'rockaden-skolschack' ) );
		printf( '<th>%s</th>', esc_html__( 'Remove', 'rockaden-skolschack' ) );
		echo '</tr></thead><tbody>';

		$index = 0;

		foreach ( $guardians as $guardian ) {
			self::guardian_row( $index, (string) $guardian->name, (string) $guardian->email, (string) $guardian->phone, (bool) $guardian->is_primary, true );
			++$index;
		}

		self::guardian_row( $index, '', '', '', [] === $guardians, false );

		echo '</tbody></table>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Fill in the empty row to add another guardian. Tick Remove to drop one.', 'rockaden-skolschack' )
		);
	}

	/**
	 * One guardian row.
	 *
	 * @param int    $index      Row index.
	 * @param string $name       Name.
	 * @param string $email      Email.
	 * @param string $phone      Phone.
	 * @param bool   $is_primary Whether this is the main contact.
	 * @param bool   $removable  Whether to offer a remove box.
	 * @return void
	 */
	private static function guardian_row( int $index, string $name, string $email, string $phone, bool $is_primary, bool $removable ): void {
		echo '<tr>';

		printf( '<td><input type="text" name="guardian[%1$s][name]" value="%2$s" class="regular-text" /></td>', esc_attr( (string) $index ), esc_attr( $name ) );
		printf( '<td><input type="email" name="guardian[%1$s][email]" value="%2$s" class="regular-text" /></td>', esc_attr( (string) $index ), esc_attr( $email ) );
		printf( '<td><input type="text" name="guardian[%1$s][phone]" value="%2$s" /></td>', esc_attr( (string) $index ), esc_attr( $phone ) );
		printf(
			'<td><input type="radio" name="guardian_primary" value="%1$s" %2$s /></td>',
			esc_attr( (string) $index ),
			checked( $is_primary, true, false )
		);

		if ( $removable ) {
			printf( '<td><input type="checkbox" name="guardian[%s][remove]" value="1" /></td>', esc_attr( (string) $index ) );
		} else {
			echo '<td></td>';
		}

		echo '</tr>';
	}

	/**
	 * The delete control, for administrators only.
	 *
	 * Its own form, its own nonce, and a checkbox that must be ticked. Nothing here can be
	 * triggered by a stray click, and there is no JavaScript confirm to bypass.
	 *
	 * @param object $signup The row.
	 * @return void
	 */
	private static function render_delete( object $signup ): void {
		if ( ! current_user_can( Capabilities::DELETE_SIGNUPS ) ) {
			return;
		}

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Delete this signup', 'rockaden-skolschack' ) . '</h2>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'This removes the child and their guardians permanently. Consider marking them withdrawn instead.', 'rockaden-skolschack' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( SignupActions::DELETE ) );
		printf( '<input type="hidden" name="signup" value="%d" />', (int) $signup->id );
		wp_nonce_field( SignupActions::DELETE, SignupActions::NONCE );
		printf(
			'<p><label><input type="checkbox" name="confirm" value="1" /> %s</label></p>',
			esc_html__( 'Yes, delete this signup', 'rockaden-skolschack' )
		);
		submit_button( __( 'Delete', 'rockaden-skolschack' ), 'delete', 'submit', false );
		echo '</form>';
	}
}
