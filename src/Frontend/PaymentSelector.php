<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use Ifthenpay\Formidable\Settings\SettingsRepository;

/**
 * Makes ifthenpay a visible, selectable payment method on the form itself —
 * mirroring the pattern Formidable's own bundled PayPal module uses (a
 * bordered radio row per method, each with its own icon(s), that swaps the
 * form's native submit button for a method-specific one once selected) —
 * instead of ifthenpay being an invisible, purely server-side gateway choice
 * with no front-end presence of its own.
 *
 * Deliberately a *separate* block from PayPal/Stripe's own selector rather
 * than an entry injected into theirs: their radio group is built by a
 * private, unexported closure in their own JS with no extension point (no
 * hook, no exposed registry) — see `paypal/js/frontend.js` in Formidable
 * core. Rendered at `frm_before_submit_btn`, the same generic "right before
 * the submit button" hook Formidable exposes regardless of which/whether a
 * gateway-specific field (like Stripe's Credit Card field) is present, since
 * none of ifthenpay's methods are card-based and this selector shouldn't
 * depend on one existing.
 */
class PaymentSelector {

	/**
	 * @return void
	 */
	public static function boot() {
		add_action( 'frm_before_submit_btn', array( self::class, 'maybe_render' ) );
	}

	/**
	 * @param array $args {
	 *     @type object $form
	 * }
	 *
	 * @return void
	 */
	public static function maybe_render( $args ) {
		$form = isset( $args['form'] ) ? $args['form'] : null;

		if ( ! $form || ! isset( $form->id ) || ! RedirectHandler::form_has_ifthenpay_payment_action( $form ) ) {
			return;
		}

		$settings = new SettingsRepository();
		$methods  = self::active_methods( $settings );

		if ( ! $methods ) {
			return;
		}

		echo self::render( $methods, RedirectHandler::color_style_for_form( $form ), $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built (and escaped piece by piece) in render()/render_method_icon()/render_button_content().
	}

	/**
	 * Same enabled+provisioned filter `SettingsRepository::get_active_accounts_string()`
	 * uses, but keeping the full row (label, image_url) instead of collapsing
	 * to the PBL accounts string.
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function active_methods( SettingsRepository $settings ) {
		$active = array();

		foreach ( $settings->get_methods() as $method ) {
			if ( empty( $method['enabled'] ) || empty( $method['provisioned'] ) ) {
				continue;
			}

			$active[] = $method;
		}

		return $active;
	}

	/**
	 * @param array<int, array<string, mixed>> $methods
	 * @param string                            $color_style Inline CSS custom properties from
	 *                                                        RedirectHandler::color_style_for_form().
	 * @param SettingsRepository                $settings
	 *
	 * @return string
	 */
	private static function render( array $methods, $color_style, SettingsRepository $settings ) {
		$hide_icons = $settings->is_method_icons_disabled();
		ob_start();
		?>
		<div class="iftp-frm-method-block iftp-frm-method-block--preselected" style="<?php echo esc_attr( $color_style ); ?>">
			<?php if ( ! $hide_icons ) : ?>
			<div class="iftp-frm-method-selector">
				<div class="iftp-frm-method-option">
					<?php /* No ifthenpay brand mark here — the button right below already carries the brand, so this row reads purely as "here's what you can pay with". */ ?>
					<span class="iftp-frm-method-marks">
						<?php foreach ( $methods as $method ) : ?>
							<?php echo self::render_method_icon( $method ); ?>
						<?php endforeach; ?>
					</span>
				</div>
			</div>
			<?php endif; ?>
			<div class="iftp-frm-method-action">
				<?php /* A real submit button, deliberately — see assets/js/frontend.js's own top docblock for why this needs to go through Formidable's own native AJAX submission (validation included) rather than a separate custom endpoint. Sits outside .iftp-frm-method-selector's bordered box by design, not nested inside it. */ ?>
				<button type="submit" class="iftp-frm-pay-button">
					<?php echo self::render_button_content( $settings->get_button_text() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece in render_button_content(). ?>
				</button>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Builds the pay button's inner markup from the merchant's custom text
	 * (`SettingsRepository::get_button_text()`), splitting on the literal
	 * `{logo}` token to interleave the ifthenpay logo wherever the merchant
	 * placed it. Public and shared with the admin live preview (see
	 * `Admin\views\confirmation-settings-tab.php`) so the two never drift.
	 *
	 * The token is the *only* control over the logo — no separate "show
	 * logo" flag. Text with no `{logo}` in it renders with no logo at all,
	 * it is never auto-appended.
	 *
	 * @param string $button_text
	 *
	 * @return string
	 */
	public static function render_button_content( $button_text ) {
		if ( false === strpos( $button_text, '{logo}' ) ) {
			return '<span class="iftp-frm-pay-button__text">' . esc_html( $button_text ) . '</span>';
		}

		$logo = sprintf(
			'<img class="iftp-frm-pay-button__logo" src="%1$s" alt="%2$s" loading="lazy" />',
			esc_url( IFTP_FRM_URL . 'assets/img/logo_ifthenpay_white.svg' ),
			esc_attr__( 'ifthenpay', 'ifthenpay-payments-for-formidable' )
		);

		$parts  = explode( '{logo}', $button_text );
		$output = '';

		foreach ( $parts as $i => $part ) {
			$part = trim( $part );

			if ( '' !== $part ) {
				$output .= '<span class="iftp-frm-pay-button__text">' . esc_html( $part ) . '</span>';
			}

			if ( $i < count( $parts ) - 1 ) {
				$output .= $logo;
			}
		}

		return $output;
	}

	/**
	 * @param array<string, mixed> $method
	 *
	 * @return string
	 */
	private static function render_method_icon( array $method ) {
		$entity    = isset( $method['entity'] ) ? strtoupper( $method['entity'] ) : '';
		$label     = isset( $method['label'] ) ? $method['label'] : $entity;
		$image_url = ! empty( $method['image_url'] )
			? $method['image_url']
			: \Ifthenpay\Formidable\Api\IfthenpayPayload::fallback_logo_url( $entity );

		return sprintf(
			'<img class="iftp-frm-method-mark" src="%1$s" alt="%2$s" loading="lazy" />',
			esc_url( $image_url ),
			esc_attr( $label )
		);
	}
}
