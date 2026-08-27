<?php
/**
 * The "ifthenpay" tab on Formidable's Global Settings screen.
 *
 * Expects: $settings (SettingsRepository), $connected (bool),
 * $gateway_key (string), $gateway_keys (array), $methods (array).
 *
 * @package Ifthenpay\Formidable
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}
?>
<h3 style="margin-bottom: 0;"><?php esc_html_e( 'ifthenpay Settings', 'ifthenpay-payments-for-formidable' ); ?></h3>
<p class="frm-description">
	<?php esc_html_e( 'Accept Multibanco, MB WAY, cards, Apple Pay, Google Pay, Cofidis, and Pix through a single ifthenpay Gateway Key, via a secure hosted payment page.', 'ifthenpay-payments-for-formidable' ); ?>
</p>

<div id="iftp-frm-connect-card" class="frm-card-item frm6" style="max-width: 480px;">
	<div class="frm-flex-col" style="width: 100%;">
		<div>
			<span style="font-size: var(--text-lg); font-weight: 500; margin-right: 5px;">
				<?php esc_html_e( 'Backoffice Key', 'ifthenpay-payments-for-formidable' ); ?>
			</span>
			<div id="iftp-frm-connect-tag" class="frm-meta-tag <?php echo $connected ? 'frm-lt-green-tag' : 'frm-grey-tag'; ?>" style="font-size: var(--text-sm); font-weight: 600;">
				<?php if ( $connected ) : ?>
					<?php \FrmAppHelper::icon_by_class( 'frmfont frm_checkmark_icon', array( 'style' => 'width: 10px; position: relative; top: 2px; margin-right: 5px;' ) ); ?>
					<?php esc_html_e( 'Connected', 'ifthenpay-payments-for-formidable' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Not configured', 'ifthenpay-payments-for-formidable' ); ?>
				<?php endif; ?>
			</div>
		</div>

		<div id="iftp-frm-connect-form" class="iftp-frm-connect-form" <?php echo $connected ? 'style="display:none;"' : ''; ?>>
			<p style="margin-top: 5px;">
				<?php esc_html_e( 'Paste the Backoffice Key ifthenpay gave you when your contract was signed. It is stored securely and never shown again once connected.', 'ifthenpay-payments-for-formidable' ); ?>
			</p>
			<input type="password" id="iftp-frm-backoffice-key-input" class="frm_with_left_label" autocomplete="off" style="width:100%;max-width:360px;" />
			<div class="frm-card-bottom">
				<button type="button" id="iftp-frm-btn-connect" class="button-secondary frm-button-secondary">
					<?php esc_html_e( 'Connect', 'ifthenpay-payments-for-formidable' ); ?>
				</button>
				<span id="iftp-frm-connect-error" class="iftp-frm-error" role="alert"></span>
			</div>
		</div>

		<div id="iftp-frm-connected-state" <?php echo $connected ? '' : 'style="display:none;"'; ?>>
			<p style="margin-top: 5px;">
				<?php esc_html_e( 'Backoffice Key connected.', 'ifthenpay-payments-for-formidable' ); ?>
			</p>
			<div class="frm-card-bottom">
				<button type="button" id="iftp-frm-btn-disconnect" class="button-secondary frm-button-secondary">
					<?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-formidable' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>

<div id="iftp-frm-gateway-section" <?php echo $connected ? '' : 'style="display:none;"'; ?>>
	<table class="form-table">
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Gateway Key', 'ifthenpay-payments-for-formidable' ); ?></th>
			<td>
				<select id="iftp-frm-gateway-key-select" name="frm_ifthenpay_gateway_key_display" class="frm_with_left_label">
					<option value=""><?php esc_html_e( '— Select —', 'ifthenpay-payments-for-formidable' ); ?></option>
					<?php
					$iftp_frm_current_listed = false;
					foreach ( $gateway_keys as $iftp_frm_gw_choice ) :
						$iftp_frm_current_listed = $iftp_frm_current_listed || $iftp_frm_gw_choice['key'] === $gateway_key;
						?>
						<option value="<?php echo esc_attr( $iftp_frm_gw_choice['key'] ); ?>" <?php selected( $gateway_key, $iftp_frm_gw_choice['key'] ); ?>><?php echo esc_html( $iftp_frm_gw_choice['label'] ); ?></option>
					<?php endforeach; ?>
					<?php if ( '' !== $gateway_key && ! $iftp_frm_current_listed ) : ?>
						<option value="<?php echo esc_attr( $gateway_key ); ?>" selected="selected"><?php echo esc_html( $gateway_key ); ?></option>
					<?php endif; ?>
				</select>
				<p class="frm-description"><?php esc_html_e( 'Every Gateway Key registered on this Backoffice Key.', 'ifthenpay-payments-for-formidable' ); ?></p>
			</td>
		</tr>
	</table>

	<h4><?php esc_html_e( 'Payment Methods', 'ifthenpay-payments-for-formidable' ); ?></h4>
	<table class="wp-list-table widefat striped iftp-frm-methods-table">
		<thead>
			<tr>
				<th class="iftp-frm-col-control"><?php esc_html_e( 'Enabled', 'ifthenpay-payments-for-formidable' ); ?></th>
				<th class="iftp-frm-col-control"><?php esc_html_e( 'Default Method', 'ifthenpay-payments-for-formidable' ); ?></th>
				<th><?php esc_html_e( 'Method', 'ifthenpay-payments-for-formidable' ); ?></th>
				<th><?php esc_html_e( 'Account', 'ifthenpay-payments-for-formidable' ); ?></th>
			</tr>
		</thead>
		<tbody id="iftp-frm-methods-table-body">
			<?php if ( '' === $gateway_key || empty( $methods ) ) : ?>
				<tr class="iftp-frm-methods-empty-row">
					<td colspan="4"><?php esc_html_e( 'Select a Gateway Key to load its payment methods.', 'ifthenpay-payments-for-formidable' ); ?></td>
				</tr>
			<?php else : ?>
				<?php echo \Ifthenpay\Formidable\Admin\SettingsField::render_methods_table_rows( $methods, $gateway_key, $settings->get_default_method() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped partial. ?>
			<?php endif; ?>
		</tbody>
	</table>

	<table class="form-table">
		<tr class="form-field">
			<th scope="row"><label for="frm_ifthenpay_expiry_days"><?php esc_html_e( 'Expiry Days', 'ifthenpay-payments-for-formidable' ); ?></label></th>
			<td>
				<input type="number" min="0" id="frm_ifthenpay_expiry_days" name="frm_ifthenpay_expiry_days" class="frm_with_left_label" style="width:100px;" value="<?php echo esc_attr( $settings->get_expiry_days() ); ?>" />
				<p class="frm-description"><?php esc_html_e( 'Payment links unpaid after this many days are marked expired. 0 disables expiry.', 'ifthenpay-payments-for-formidable' ); ?></p>
			</td>
		</tr>
	</table>
	<p class="frm-description">
		<?php esc_html_e( 'The payment description shown on the ifthenpay hosted page comes from the form\'s own "Collect Payment" action settings (Description field), not from here.', 'ifthenpay-payments-for-formidable' ); ?>
	</p>

	<p class="frm-description">
		<?php esc_html_e( 'The messages shown after checkout for Payment Received and Payment Pending are configured on the "Confirmation Type" tab of Global Settings, not here. Payment Canceled and Payment Failed are never configurable: the payer is always sent back to the form and shown a fixed message.', 'ifthenpay-payments-for-formidable' ); ?>
	</p>
</div>

<input type="hidden" name="frm_ifthenpay_settings_submitted" value="1" />
