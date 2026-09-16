<?php
/**
 * Renders the public signup form.
 *
 * A real <form> is rendered server-side; view.js takes over submission so the page does not
 * reload. Labels are translated here, and the messages the script needs are handed to it as
 * data-* attributes, which keeps the script itself free of any translation machinery.
 *
 * The school list and the security token are fetched by the script rather than printed here,
 * because this page can sit behind a cache and both would go stale.
 *
 * Fields that belong together are wrapped in a row so they can share a line on a wide screen and
 * stack on a narrow one, and the second guardian sits inside a native disclosure so a family that
 * only needs one never has to scroll past it.
 *
 * The block renders the form and nothing else. A heading and an introduction are a heading block
 * and a paragraph block, placed on the page by whoever writes it.
 *
 * @package RockadenSkolschack
 */

defined( 'ABSPATH' ) || exit;

$rsk_uid = wp_unique_id( 'rsk-form-' );

/**
 * The marker on a required field's label.
 *
 * Decorative and hidden from assistive technology, which already announces the input's own
 * required attribute and would otherwise say it twice.
 *
 * @return string The marker.
 */
$rsk_required = static function (): string {
	return ' <span class="rsk-signup__req" aria-hidden="true">*</span>';
};
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes( [ 'class' => 'rsk-signup-block' ] ) ); ?>>
	<div class="rsk-signup__inner">
		<form
			class="rsk-signup"
			data-context-url="<?php echo esc_url( rest_url( 'rockaden-skolschack/v1/form-context' ) ); ?>"
			data-submit-url="<?php echo esc_url( rest_url( 'rockaden-skolschack/v1/signups' ) ); ?>"
			data-sending="<?php esc_attr_e( 'Sending…', 'rockaden-skolschack' ); ?>"
			data-success="<?php esc_attr_e( 'Thank you. The signup has been received and a confirmation is on its way to you.', 'rockaden-skolschack' ); ?>"
			data-error="<?php esc_attr_e( 'Something went wrong. Please try again.', 'rockaden-skolschack' ); ?>"
			data-loading-schools="<?php esc_attr_e( 'Loading schools…', 'rockaden-skolschack' ); ?>"
			data-choose-school="<?php esc_attr_e( 'Choose a school', 'rockaden-skolschack' ); ?>"
			<?php
			/*
			 * The messages the script checks with as the reader types. Every one is the same
			 * string the server answers with, so a field cannot say one thing now and another
			 * after submitting, and the Swedish stays in the catalogue rather than in a script.
			 * The server checks all of this again regardless: this only saves the reader a round
			 * trip, it is not what keeps bad data out.
			 */
			?>
			data-msg-first-name="<?php esc_attr_e( 'The child needs a first name.', 'rockaden-skolschack' ); ?>"
			data-msg-last-name="<?php esc_attr_e( 'The child needs a last name.', 'rockaden-skolschack' ); ?>"
			data-msg-pnr-format="<?php esc_attr_e( 'A personnummer is twelve digits, year first and no hyphen.', 'rockaden-skolschack' ); ?>"
			data-msg-pnr-date="<?php esc_attr_e( 'That is not a real date. Check the year, month and day.', 'rockaden-skolschack' ); ?>"
			data-msg-pnr-checksum="<?php esc_attr_e( 'That personnummer does not look right. Please check it and try again.', 'rockaden-skolschack' ); ?>"
			data-msg-postal="<?php esc_attr_e( 'A postal code is five digits.', 'rockaden-skolschack' ); ?>"
			<?php // translators: %s: the email address as typed. ?>
			data-msg-email="<?php echo esc_attr( __( '"%s" is not an email address.', 'rockaden-skolschack' ) ); ?>"
			data-msg-school="<?php esc_attr_e( 'Pick a school from the list.', 'rockaden-skolschack' ); ?>"
		>
			<fieldset class="rsk-signup__group">
				<legend><?php esc_html_e( 'The child', 'rockaden-skolschack' ); ?></legend>

				<div class="rsk-signup__row">
					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-first">
							<?php esc_html_e( 'First name', 'rockaden-skolschack' ); ?><?php echo wp_kses_post( $rsk_required() ); ?>
						</label>
						<input type="text" id="<?php echo esc_attr( $rsk_uid ); ?>-first" name="first_name" required maxlength="50" autocomplete="off" />
					</p>

					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-last">
							<?php esc_html_e( 'Last name', 'rockaden-skolschack' ); ?><?php echo wp_kses_post( $rsk_required() ); ?>
						</label>
						<input type="text" id="<?php echo esc_attr( $rsk_uid ); ?>-last" name="last_name" required maxlength="50" autocomplete="off" />
					</p>
				</div>

				<div class="rsk-signup__row">
					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-pnr">
							<?php esc_html_e( 'Personnummer', 'rockaden-skolschack' ); ?><?php echo wp_kses_post( $rsk_required() ); ?>
						</label>
						<input type="text" id="<?php echo esc_attr( $rsk_uid ); ?>-pnr" name="personnummer" required inputmode="numeric" maxlength="13"
							aria-describedby="<?php echo esc_attr( $rsk_uid ); ?>-pnr-hint" />
						<span class="rsk-signup__hint" id="<?php echo esc_attr( $rsk_uid ); ?>-pnr-hint">
							<?php esc_html_e( 'Twelve digits, year first. A hyphen is fine.', 'rockaden-skolschack' ); ?>
						</span>
					</p>

					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-gender"><?php esc_html_e( 'Gender', 'rockaden-skolschack' ); ?></label>
						<select id="<?php echo esc_attr( $rsk_uid ); ?>-gender" name="gender">
							<option value=""><?php esc_html_e( '— not given —', 'rockaden-skolschack' ); ?></option>
							<option value="M"><?php esc_html_e( 'Boy', 'rockaden-skolschack' ); ?></option>
							<option value="K"><?php esc_html_e( 'Girl', 'rockaden-skolschack' ); ?></option>
						</select>
					</p>
				</div>

				<div class="rsk-signup__row">
					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-school">
							<?php esc_html_e( 'School', 'rockaden-skolschack' ); ?><?php echo wp_kses_post( $rsk_required() ); ?>
						</label>
						<select id="<?php echo esc_attr( $rsk_uid ); ?>-school" name="school_id" required>
							<option value=""><?php esc_html_e( 'Loading schools…', 'rockaden-skolschack' ); ?></option>
						</select>
					</p>

					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-class"><?php esc_html_e( 'Class', 'rockaden-skolschack' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $rsk_uid ); ?>-class" name="class" maxlength="50" />
					</p>
				</div>
			</fieldset>

			<fieldset class="rsk-signup__group">
				<legend><?php esc_html_e( 'Address', 'rockaden-skolschack' ); ?></legend>

				<p class="rsk-signup__field">
					<label for="<?php echo esc_attr( $rsk_uid ); ?>-street"><?php esc_html_e( 'Street', 'rockaden-skolschack' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $rsk_uid ); ?>-street" name="street" maxlength="100" autocomplete="street-address" />
				</p>

				<div class="rsk-signup__row">
					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-postal"><?php esc_html_e( 'Postal code', 'rockaden-skolschack' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $rsk_uid ); ?>-postal" name="postal_code" maxlength="6" inputmode="numeric" autocomplete="postal-code" />
					</p>

					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-city"><?php esc_html_e( 'City', 'rockaden-skolschack' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $rsk_uid ); ?>-city" name="city" maxlength="100" autocomplete="address-level2" />
					</p>
				</div>
			</fieldset>

			<fieldset class="rsk-signup__group">
				<legend><?php esc_html_e( 'Guardian', 'rockaden-skolschack' ); ?></legend>

				<p class="rsk-signup__field">
					<label for="<?php echo esc_attr( $rsk_uid ); ?>-g1-name"><?php esc_html_e( 'Name', 'rockaden-skolschack' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $rsk_uid ); ?>-g1-name" name="guardian1_name" maxlength="100" autocomplete="name" />
				</p>

				<div class="rsk-signup__row">
					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-g1-email">
							<?php esc_html_e( 'Email', 'rockaden-skolschack' ); ?><?php echo wp_kses_post( $rsk_required() ); ?>
						</label>
						<input type="email" id="<?php echo esc_attr( $rsk_uid ); ?>-g1-email" name="guardian1_email" required maxlength="100" autocomplete="email" />
					</p>

					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-g1-phone"><?php esc_html_e( 'Phone', 'rockaden-skolschack' ); ?></label>
						<input type="tel" id="<?php echo esc_attr( $rsk_uid ); ?>-g1-phone" name="guardian1_phone" maxlength="50" autocomplete="tel" />
					</p>
				</div>
			</fieldset>

			<details class="rsk-signup__more">
				<summary><?php esc_html_e( 'Add a second guardian', 'rockaden-skolschack' ); ?></summary>

				<div class="rsk-signup__more-fields">
					<p class="rsk-signup__hint">
						<?php esc_html_e( 'Both guardians receive the confirmation and anything the club sends later.', 'rockaden-skolschack' ); ?>
					</p>

					<p class="rsk-signup__field">
						<label for="<?php echo esc_attr( $rsk_uid ); ?>-g2-name"><?php esc_html_e( 'Name', 'rockaden-skolschack' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $rsk_uid ); ?>-g2-name" name="guardian2_name" maxlength="100" />
					</p>

					<div class="rsk-signup__row">
						<p class="rsk-signup__field">
							<label for="<?php echo esc_attr( $rsk_uid ); ?>-g2-email"><?php esc_html_e( 'Email', 'rockaden-skolschack' ); ?></label>
							<input type="email" id="<?php echo esc_attr( $rsk_uid ); ?>-g2-email" name="guardian2_email" maxlength="100" />
						</p>

						<p class="rsk-signup__field">
							<label for="<?php echo esc_attr( $rsk_uid ); ?>-g2-phone"><?php esc_html_e( 'Phone', 'rockaden-skolschack' ); ?></label>
							<input type="tel" id="<?php echo esc_attr( $rsk_uid ); ?>-g2-phone" name="guardian2_phone" maxlength="50" />
						</p>
					</div>
				</div>
			</details>

			<?php // Honeypot: hidden from people and from assistive technology, tempting to bots. ?>
			<div class="rsk-signup__hp" aria-hidden="true">
				<label for="<?php echo esc_attr( $rsk_uid ); ?>-website"><?php esc_html_e( 'Website', 'rockaden-skolschack' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $rsk_uid ); ?>-website" name="website" tabindex="-1" autocomplete="off" />
			</div>

			<div class="rsk-signup__footer">
				<p class="rsk-signup__note">
					<?php esc_html_e( 'Fields marked * are required.', 'rockaden-skolschack' ); ?>
				</p>

				<p class="rsk-signup__actions">
					<button type="submit" class="rsk-signup__submit"><?php esc_html_e( 'Sign up', 'rockaden-skolschack' ); ?></button>
				</p>
			</div>

			<p class="rsk-signup__status" role="status" aria-live="polite"></p>

			<noscript>
				<p class="rsk-signup__status is-error">
					<?php esc_html_e( 'This form needs JavaScript. Turn it on and reload the page, or contact the club directly.', 'rockaden-skolschack' ); ?>
				</p>
			</noscript>
		</form>
	</div>
</div>
