<?php
/**
 * Methods table `<tr>` rows — shared between the initial settings-tab
 * render and `Ajax\Controller::select_gateway_key()`'s AJAX response.
 *
 * Expects: $methods (array), $gateway_key (string), $default_method (string).
 *
 * @package Ifthenpay\Formidable
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

$iftp_frm_settings = new \Ifthenpay\Formidable\Settings\SettingsRepository();

if ( empty( $methods ) ) :
	?>
	<tr class="iftp-frm-methods-empty-row">
		<td colspan="4"><?php esc_html_e( 'No payment methods were found for this Gateway Key.', 'ifthenpay-payments-for-formidable' ); ?></td>
	</tr>
	<?php
	return;
endif;

foreach ( $methods as $method ) :
	$entity      = strtoupper( $method['entity'] );
	$provisioned = ! empty( $method['provisioned'] );
	$enabled     = $provisioned && ! empty( $method['enabled'] );
	$is_default  = $provisioned && $enabled && strtoupper( $default_method ) === $entity;
	$row_class   = 'iftp-frm-method-row' . ( $provisioned ? '' : ' iftp-frm-method-row--unprovisioned' );
	// Falls back to the logo CDN at render time too, not just at fetch time —
	// covers a methods snapshot saved before `image_url` existed on this row.
	$image_url   = ! empty( $method['image_url'] ) ? $method['image_url'] : \Ifthenpay\Formidable\Api\IfthenpayPayload::fallback_logo_url( $entity );
	?>
	<tr class="<?php echo esc_attr( $row_class ); ?>" data-entity="<?php echo esc_attr( $entity ); ?>">
		<td class="iftp-frm-col-control iftp-frm-col-enabled">
			<input
				type="checkbox"
				class="iftp-frm-method-enabled"
				name="frm_ifthenpay_methods_enabled[]"
				value="<?php echo esc_attr( $entity ); ?>"
				id="iftp-frm-enabled-<?php echo esc_attr( $entity ); ?>"
				data-entity="<?php echo esc_attr( $entity ); ?>"
				<?php checked( $enabled ); ?>
				<?php disabled( ! $provisioned ); ?>
			/>
		</td>
		<td class="iftp-frm-col-control iftp-frm-col-default">
			<input
				type="radio"
				class="iftp-frm-method-star-input screen-reader-text"
				name="frm_ifthenpay_default_method"
				value="<?php echo esc_attr( $entity ); ?>"
				id="iftp-frm-default-<?php echo esc_attr( $entity ); ?>"
				<?php checked( $is_default ); ?>
				<?php disabled( ! $enabled ); ?>
			/>
			<label
				for="iftp-frm-default-<?php echo esc_attr( $entity ); ?>"
				class="iftp-frm-method-star <?php echo $enabled ? '' : 'iftp-frm-method-star--hidden'; ?>"
				title="<?php esc_attr_e( 'Use as the default payment method', 'ifthenpay-payments-for-formidable' ); ?>"
			>
				<span class="screen-reader-text"><?php esc_html_e( 'Default payment method', 'ifthenpay-payments-for-formidable' ); ?></span>
				<span class="iftp-frm-star-icon" aria-hidden="true">★</span>
			</label>
		</td>
		<td class="iftp-frm-col-method">
			<span class="iftp-frm-logo-wrap" aria-hidden="true">
				<img class="iftp-frm-logo" src="<?php echo esc_url( $image_url ); ?>" alt="" loading="lazy" />
			</span>
			<span class="iftp-frm-method-label">
				<?php echo esc_html( isset( $method['label'] ) ? $method['label'] : $entity ); ?>
			</span>
		</td>
		<td class="iftp-frm-col-account">
			<?php if ( $provisioned ) : ?>
				<span class="iftp-frm-account-pill"><?php echo esc_html( $method['account'] ); ?></span>
			<?php else : ?>
				<div class="iftp-frm-status-cell">
					<?php $iftp_frm_on_cooldown = $iftp_frm_settings->is_activation_requested( $gateway_key, $entity ); ?>
					<span class="iftp-frm-status iftp-frm-status--off"><?php esc_html_e( 'Method Not Activated', 'ifthenpay-payments-for-formidable' ); ?></span>
					<button
						type="button"
						class="button-secondary frm-button-secondary iftp-frm-activate-btn"
						data-entity="<?php echo esc_attr( $entity ); ?>"
						data-gateway-key="<?php echo esc_attr( $gateway_key ); ?>"
						<?php disabled( $iftp_frm_on_cooldown ); ?>
					>
						<?php echo $iftp_frm_on_cooldown ? esc_html__( 'Requested', 'ifthenpay-payments-for-formidable' ) : esc_html__( 'Request Activation', 'ifthenpay-payments-for-formidable' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</td>
	</tr>
	<?php
endforeach;
