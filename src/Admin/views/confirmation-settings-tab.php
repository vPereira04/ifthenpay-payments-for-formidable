<?php
/**
 * The "Confirmation Type" tab on Formidable's Global Settings screen — its
 * own top-level tab (registered via `frm_add_settings_section`), separate
 * from the ifthenpay Gateway tab nested inside Payments (Backoffice Key /
 * Gateway Key / Methods / Expiry Days live there instead — see
 * `settings-tab.php`).
 *
 * Expects: $settings (SettingsRepository).
 *
 * @package Ifthenpay\Formidable
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}
?>
<p class="frm-description">
	<?php esc_html_e( 'Shown once the payer is sent back from the ifthenpay hosted page. Message fields support Formidable field shortcodes like [21]; Redirect/Open-in-a-New-Tab URLs are plain URLs only. "Open in a New Tab" shows the message here while also opening the URL in a new tab — handy for pointing Payment Pending to the same "Thank you" page as Payment Received, with its own wording for the still-confirming case. For Payment Received specifically: a form with its own native "On Submit → Redirect to URL" action always keeps using that instead of anything set here; otherwise "Show Message" shows the form\'s own native message when it has one, while "Open in a New Tab" always uses the message configured here.', 'ifthenpay-payments-for-formidable' ); ?>
</p>
<p class="frm-description">
	<?php esc_html_e( 'Payment Canceled and Payment Failed are not configurable: the payer is always sent back to the form and shown a fixed message.', 'ifthenpay-payments-for-formidable' ); ?>
</p>
<?php
$iftp_frm_mode_labels = array(
	'message'      => __( 'Show Message', 'ifthenpay-payments-for-formidable' ),
	'redirect'     => __( 'Redirect to URL', 'ifthenpay-payments-for-formidable' ),
	'open_new_tab' => __( 'Open in a New Tab', 'ifthenpay-payments-for-formidable' ),
);
// Static, hand-rolled inline SVGs — no dynamic data, deliberately not
// referencing Formidable's own bundled sprite (`FrmAppHelper::include_svg()`
// has no symbol for any of these, and this plugin already avoids relying
// on that private, undocumented sprite elsewhere — see assets/js/admin.js's
// fixIfthenpayGatewayIcon()).
$iftp_frm_mode_icons = array(
	'message'      => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-8.9 8.4 8.9 8.9 0 0 1-3.8-.9L3 21l1.9-5.4A8.4 8.4 0 1 1 21 11.5z"/></svg>',
	'redirect'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
	'open_new_tab' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/></svg>',
);

$iftp_frm_outcomes = array(
	'success' => array(
		'label' => __( 'Payment Received', 'ifthenpay-payments-for-formidable' ),
		'mode'  => $settings->get_success_mode(),
		'msg'   => $settings->get_success_message(),
		'url'   => $settings->get_success_redirect_url(),
		'modes' => array( 'message', 'redirect', 'open_new_tab' ),
	),
	'pending' => array(
		'label' => __( 'Payment Pending', 'ifthenpay-payments-for-formidable' ),
		'mode'  => $settings->get_pending_mode(),
		'msg'   => $settings->get_pending_message(),
		'url'   => $settings->get_pending_redirect_url(),
		'modes' => array( 'message', 'redirect', 'open_new_tab' ),
	),
);
?>
<table class="form-table">
	<?php foreach ( $iftp_frm_outcomes as $iftp_frm_outcome_key => $iftp_frm_outcome ) : ?>
		<tr class="form-field">
			<th scope="row"><?php echo esc_html( $iftp_frm_outcome['label'] ); ?></th>
			<td>
				<div class="iftp-frm-outcome-mode" data-outcome="<?php echo esc_attr( $iftp_frm_outcome_key ); ?>">
					<?php foreach ( $iftp_frm_outcome['modes'] as $iftp_frm_mode_key ) : ?>
						<label class="iftp-frm-outcome-box">
							<input type="radio" name="frm_ifthenpay_mode_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" value="<?php echo esc_attr( $iftp_frm_mode_key ); ?>" class="iftp-frm-outcome-mode-input" data-outcome="<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" <?php checked( $iftp_frm_outcome['mode'], $iftp_frm_mode_key ); ?> />
							<span class="iftp-frm-outcome-box__icon"><?php echo $iftp_frm_mode_icons[ $iftp_frm_mode_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG, no dynamic data. ?></span>
							<span class="iftp-frm-outcome-box__label"><?php echo esc_html( $iftp_frm_mode_labels[ $iftp_frm_mode_key ] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<?php
				// "Open in a New Tab" needs BOTH fields — the message shows
				// on this page while the URL opens alongside it in the new
				// tab — so only "Redirect to URL" (no message at all, it
				// just navigates away) ever hides the message field, and
				// only plain "Show Message" ever hides the URL field. Each
				// is its own block-level wrapper (not the bare textarea/
				// input directly) so the two always stack — message above
				// URL — instead of sitting side by side when both show at
				// once, and so each can carry its own mini label.
				$iftp_frm_message_hidden = 'redirect' === $iftp_frm_outcome['mode'];
				$iftp_frm_url_hidden     = 'message' === $iftp_frm_outcome['mode'];
				?>
				<div class="iftp-frm-outcome-field <?php echo $iftp_frm_message_hidden ? 'frm_hidden' : ''; ?>" data-outcome="<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" data-mode="message">
					<label class="iftp-frm-outcome-field__label" for="frm_ifthenpay_msg_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>"><?php esc_html_e( 'Message', 'ifthenpay-payments-for-formidable' ); ?></label>
					<textarea id="frm_ifthenpay_msg_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" name="frm_ifthenpay_msg_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" rows="2" class="frm_with_left_label" style="width:100%;max-width:480px;border-radius: 6px;border-color: #d0d0d0;color: #4f4f4f;"><?php echo esc_textarea( $iftp_frm_outcome['msg'] ); ?></textarea>
				</div>
				<div class="iftp-frm-outcome-field <?php echo $iftp_frm_url_hidden ? 'frm_hidden' : ''; ?>" data-outcome="<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" data-mode="url">
					<label class="iftp-frm-outcome-field__label" for="frm_ifthenpay_url_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>"><?php esc_html_e( 'URL', 'ifthenpay-payments-for-formidable' ); ?></label>
					<input type="url" id="frm_ifthenpay_url_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" name="frm_ifthenpay_url_<?php echo esc_attr( $iftp_frm_outcome_key ); ?>" class="frm_with_left_label" style="width:100%;max-width:480px;border-radius: 6px;border-color: #d0d0d0;color: #4f4f4f;" placeholder="https://" value="<?php echo esc_attr( $iftp_frm_outcome['url'] ); ?>" />
				</div>
			</td>
		</tr>
	<?php endforeach; ?>
</table>
