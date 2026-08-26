<?php
/**
 * Fires when the plugin is deleted via the WordPress admin.
 *
 * @package Ifthenpay\Formidable
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ifthenpay_frm_options = array(
	'frm_ifthenpay_backoffice_key',
	'frm_ifthenpay_gateway_key',
	'frm_ifthenpay_methods',
	'frm_ifthenpay_default_method',
	'frm_ifthenpay_expiry_days',
);

foreach ( $ifthenpay_frm_options as $ifthenpay_frm_option ) {
	delete_option( $ifthenpay_frm_option );
}

global $wpdb;

// Delete this plugin's own per-form "redirect action created" markers and
// activation-request cooldown transients. Payment records in the shared
// `wp_frm_payments` table are Formidable's own data and are intentionally
// left untouched, exactly like every other gateway sharing that table.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'frm_ifthenpay_redirect_action_' ) . '%',
		$wpdb->esc_like( '_transient_frm_ifthenpay_activation_requested_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_frm_ifthenpay_activation_requested_' ) . '%'
	)
);
