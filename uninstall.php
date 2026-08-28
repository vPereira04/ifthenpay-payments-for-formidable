<?php
/**
 * Fires when the plugin is deleted via the WordPress admin.
 *
 * @package Ifthenpay\Formidable
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * @return void
 */
function ifthenpay_frm_uninstall() {
	$options = array(
		'frm_ifthenpay_backoffice_key',
		'frm_ifthenpay_gateway_key',
		'frm_ifthenpay_methods',
		'frm_ifthenpay_default_method',
		'frm_ifthenpay_expiry_days',
		'frm_ifthenpay_msg_success',
		'frm_ifthenpay_msg_pending',
		'frm_ifthenpay_mode_success',
		'frm_ifthenpay_mode_pending',
		'frm_ifthenpay_url_success',
		'frm_ifthenpay_url_pending',
		'frm_ifthenpay_disable_method_icons',
		'frm_ifthenpay_button_text',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	global $wpdb;

	// Delete this plugin's own per-form "redirect action created" markers, the
	// per-entry redirect/context transients (RedirectHandler::TRANSIENT_PREFIX /
	// CONTEXT_TRANSIENT_PREFIX), and activation-request cooldown transients.
	// Payment records in the shared `wp_frm_payments` table are Formidable's own
	// data and are intentionally left untouched, exactly like every other
	// gateway sharing that table.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input; every LIKE value is escaped via esc_like() and passed through prepare().
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'frm_ifthenpay_redirect_action_' ) . '%',
			$wpdb->esc_like( '_transient_frm_ifthenpay_redirect_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_frm_ifthenpay_redirect_' ) . '%',
			$wpdb->esc_like( '_transient_frm_ifthenpay_context_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_frm_ifthenpay_context_' ) . '%',
			$wpdb->esc_like( '_transient_frm_ifthenpay_activation_requested_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_frm_ifthenpay_activation_requested_' ) . '%'
		)
	);
}
ifthenpay_frm_uninstall();
