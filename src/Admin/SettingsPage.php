<?php
/**
 * Plugin settings screen.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Admin;

use Rockaden\Skolschack\Services\Capabilities;
use Rockaden\Skolschack\Services\Terms;

defined( 'ABSPATH' ) || exit;

/**
 * Club-wide settings, on the Settings API.
 *
 * Everything the confirmation email says about money and logistics is read from here, so none of
 * it is written into the code. Another club, or a changed fee, is a form to fill in.
 */
class SettingsPage {

	/**
	 * Option name holding every setting.
	 *
	 * @var string
	 */
	public const OPTION = 'rockaden_skolschack_settings';

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	private const GROUP = 'rockaden_skolschack_settings_group';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'rockaden-skolschack-settings';

	/**
	 * Register the setting and its fields.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'register_setting' ] );
	}

	/**
	 * Default values for every setting.
	 *
	 * @return array<string, string|int> Defaults.
	 */
	public static function defaults(): array {
		$term = Terms::from_date( new \DateTimeImmutable( 'now', wp_timezone() ) );

		return [
			'term_year'          => $term['year'],
			'term_season'        => $term['season'],
			'default_fee'        => 690,
			'plusgiro'           => '',
			'swish'              => '',
			'fritidskort'        => '',
			'notification_email' => '',
		];
	}

	/**
	 * Current settings, with defaults filled in.
	 *
	 * @return array<string, string|int> Settings.
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Register the option, its sanitiser and its fields.
	 *
	 * @return void
	 */
	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);

		add_settings_section(
			'rsk_term',
			__( 'Current term', 'rockaden-skolschack' ),
			[ self::class, 'render_term_intro' ],
			self::PAGE_SLUG
		);

		add_settings_field(
			'term',
			__( 'Term', 'rockaden-skolschack' ),
			[ self::class, 'render_term_field' ],
			self::PAGE_SLUG,
			'rsk_term'
		);

		add_settings_field(
			'default_fee',
			__( 'Default term fee', 'rockaden-skolschack' ),
			[ self::class, 'render_default_fee_field' ],
			self::PAGE_SLUG,
			'rsk_term'
		);

		add_settings_section(
			'rsk_payment',
			__( 'Payment and contact', 'rockaden-skolschack' ),
			[ self::class, 'render_payment_intro' ],
			self::PAGE_SLUG
		);

		$text_fields = [
			'plusgiro'           => __( 'Plusgiro', 'rockaden-skolschack' ),
			'swish'              => __( 'Swish number', 'rockaden-skolschack' ),
			'fritidskort'        => __( 'Fritidskortet text', 'rockaden-skolschack' ),
			'notification_email' => __( 'Fallback notification address', 'rockaden-skolschack' ),
		];

		foreach ( $text_fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				[ self::class, 'render_text_field' ],
				self::PAGE_SLUG,
				'rsk_payment',
				[ 'key' => $key ]
			);
		}
	}

	/**
	 * Clean every submitted value.
	 *
	 * Every key is assigned explicitly. A key left out here would be silently dropped on save.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, string|int> Sanitised settings.
	 */
	public static function sanitize( $input ): array {
		$input  = is_array( $input ) ? $input : [];
		$clean  = self::defaults();
		$season = isset( $input['term_season'] ) ? (string) $input['term_season'] : '';

		$clean['term_year']          = isset( $input['term_year'] ) ? absint( $input['term_year'] ) : $clean['term_year'];
		$clean['term_season']        = Terms::is_valid_season( $season ) ? $season : $clean['term_season'];
		$clean['default_fee']        = isset( $input['default_fee'] ) ? absint( $input['default_fee'] ) : $clean['default_fee'];
		$clean['plusgiro']           = isset( $input['plusgiro'] ) ? sanitize_text_field( $input['plusgiro'] ) : '';
		$clean['swish']              = isset( $input['swish'] ) ? sanitize_text_field( $input['swish'] ) : '';
		$clean['fritidskort']        = isset( $input['fritidskort'] ) ? sanitize_textarea_field( $input['fritidskort'] ) : '';
		$clean['notification_email'] = isset( $input['notification_email'] ) ? sanitize_email( $input['notification_email'] ) : '';

		return $clean;
	}

	/**
	 * Explain what the term setting does.
	 *
	 * @return void
	 */
	public static function render_term_intro(): void {
		echo '<p>' . esc_html__( 'New signups are filed against this term, and the signup list defaults to it.', 'rockaden-skolschack' ) . '</p>';
	}

	/**
	 * Render the club-wide default fee.
	 *
	 * @return void
	 */
	public static function render_default_fee_field(): void {
		$settings = self::get();

		printf(
			'<input type="number" name="%1$s[default_fee]" id="rsk-default-fee" value="%2$s" min="0" step="10" class="small-text" /> %3$s',
			esc_attr( self::OPTION ),
			esc_attr( (string) (int) $settings['default_fee'] ),
			esc_html__( 'kronor', 'rockaden-skolschack' )
		);
		echo '<p class="description">' . esc_html__( 'Applies to every school that does not set its own fee.', 'rockaden-skolschack' ) . '</p>';
	}

	/**
	 * Explain what the payment settings do.
	 *
	 * @return void
	 */
	public static function render_payment_intro(): void {
		echo '<p>' . esc_html__( 'These appear in the confirmation email sent to guardians.', 'rockaden-skolschack' ) . '</p>';
	}

	/**
	 * Render the term year and season inputs.
	 *
	 * @return void
	 */
	public static function render_term_field(): void {
		$settings = self::get();
		$option   = self::OPTION;

		printf(
			'<input type="number" name="%1$s[term_year]" id="rsk-term-year" value="%2$d" min="2000" max="2100" class="small-text" /> ',
			esc_attr( $option ),
			(int) $settings['term_year']
		);

		printf( '<select name="%s[term_season]" id="rsk-term-season">', esc_attr( $option ) );
		foreach ( Terms::season_labels() as $code => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $code ),
				selected( $settings['term_season'], $code, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render one plain text field.
	 *
	 * @param array{key?: string} $args Field arguments, carrying the settings key.
	 * @return void
	 */
	public static function render_text_field( array $args ): void {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';
		if ( '' === $key ) {
			return;
		}

		$settings = self::get();
		$value    = (string) ( $settings[ $key ] ?? '' );

		if ( 'fritidskort' === $key ) {
			printf(
				'<textarea name="%1$s[%2$s]" id="rsk-%2$s" rows="3" class="large-text">%3$s</textarea>',
				esc_attr( self::OPTION ),
				esc_attr( $key ),
				esc_textarea( $value )
			);

			return;
		}

		printf(
			'<input type="%4$s" name="%1$s[%2$s]" id="rsk-%2$s" value="%3$s" class="regular-text" />',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			esc_attr( $value ),
			'notification_email' === $key ? 'email' : 'text'
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'rockaden-skolschack' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Skolschack settings', 'rockaden-skolschack' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE_SLUG );
		submit_button();
		echo '</form>';
		echo '</div>';
	}
}
