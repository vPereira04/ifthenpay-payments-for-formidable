<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

/**
 * Wraps every `frm_ifthenpay_*` option this plugin stores.
 *
 * The Backoffice Key is deliberately write-only: it is stored, but nothing
 * in this class (or anywhere else in the plugin) ever returns its raw value
 * to a template/AJAX response once saved. Only `has_backoffice_key()` is
 * exposed for that purpose.
 */
class SettingsRepository {

	const OPT_BACKOFFICE_KEY   = 'frm_ifthenpay_backoffice_key';
	const OPT_GATEWAY_KEY      = 'frm_ifthenpay_gateway_key';
	const OPT_METHODS          = 'frm_ifthenpay_methods';
	const OPT_DEFAULT_METHOD   = 'frm_ifthenpay_default_method';
	const OPT_EXPIRY_DAYS      = 'frm_ifthenpay_expiry_days';
	const OPT_ACTIVATION_CACHE = 'frm_ifthenpay_activation_requested_';
	const OPT_MSG_SUCCESS      = 'frm_ifthenpay_msg_success';
	const OPT_MSG_PENDING      = 'frm_ifthenpay_msg_pending';
	const OPT_MSG_CANCELED     = 'frm_ifthenpay_msg_canceled';
	const OPT_MSG_FAILED       = 'frm_ifthenpay_msg_failed';

	/**
	 * @return bool
	 */
	public function has_backoffice_key() {
		return '' !== $this->get_backoffice_key();
	}

	/**
	 * Internal accessor only. Never expose this value to a template, AJAX
	 * response, or log — see blueprint §8.4 (write-only Backoffice Key).
	 *
	 * @return string
	 */
	public function get_backoffice_key() {
		return (string) get_option( self::OPT_BACKOFFICE_KEY, '' );
	}

	/**
	 * @param string $key
	 *
	 * @return void
	 */
	public function save_backoffice_key( $key ) {
		update_option( self::OPT_BACKOFFICE_KEY, sanitize_text_field( $key ), false );
	}

	/**
	 * @return void
	 */
	public function delete_backoffice_key() {
		delete_option( self::OPT_BACKOFFICE_KEY );
		delete_option( self::OPT_GATEWAY_KEY );
		delete_option( self::OPT_METHODS );
		delete_option( self::OPT_DEFAULT_METHOD );
	}

	/**
	 * @return string
	 */
	public function get_gateway_key() {
		return (string) get_option( self::OPT_GATEWAY_KEY, '' );
	}

	/**
	 * @param string $gateway_key
	 *
	 * @return void
	 */
	public function save_gateway_key( $gateway_key ) {
		update_option( self::OPT_GATEWAY_KEY, sanitize_text_field( $gateway_key ), false );
	}

	/**
	 * Cached snapshot of the methods table for the current Gateway Key, as
	 * returned by the ifthenpay API (entity, label, account, provisioned).
	 * Refreshed every time the merchant (re)selects a Gateway Key.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_methods() {
		$methods = get_option( self::OPT_METHODS, array() );
		return is_array( $methods ) ? $methods : array();
	}

	/**
	 * @param array<int, array<string, mixed>> $methods
	 *
	 * @return void
	 */
	public function save_methods( array $methods ) {
		update_option( self::OPT_METHODS, $methods, false );
	}

	/**
	 * @return string
	 */
	public function get_default_method() {
		return (string) get_option( self::OPT_DEFAULT_METHOD, '' );
	}

	/**
	 * Only ever set to a method that is both provisioned and enabled.
	 *
	 * @param string $entity
	 *
	 * @return void
	 */
	public function save_default_method( $entity ) {
		update_option( self::OPT_DEFAULT_METHOD, sanitize_text_field( $entity ), false );
	}

	/**
	 * Used only as a last-resort fallback when the payment action itself has
	 * no description set (see IfthenpayGateway::trigger() /
	 * IfthenpayPayload::build()) — the per-action "Description" field on
	 * Formidable's own native Payment Options view (shared with
	 * Stripe/Square/PayPal, `stripe/views/action-settings/payments-options.php`)
	 * is the actual, merchant-facing source now; this plugin no longer stores
	 * a separate global default of its own.
	 *
	 * @return string
	 */
	public function get_default_description() {
		return sprintf(
			/* translators: %s: site name. */
			__( 'Payment to %s', 'ifthenpay-payments-for-formidable' ),
			get_bloginfo( 'name' )
		);
	}

	/**
	 * @return int
	 */
	public function get_expiry_days() {
		$days = get_option( self::OPT_EXPIRY_DAYS, 3 );
		return max( 0, (int) $days );
	}

	/**
	 * @param int $days
	 *
	 * @return void
	 */
	public function save_expiry_days( $days ) {
		update_option( self::OPT_EXPIRY_DAYS, max( 0, (int) $days ), false );
	}

	/**
	 * Returns only the methods marked `enabled` in the saved methods table,
	 * built into ifthenpay's `"ENTITY|ACCOUNT;..."` accounts string.
	 *
	 * @return string
	 */
	public function get_active_accounts_string() {
		$parts = array();

		foreach ( $this->get_methods() as $method ) {
			if ( empty( $method['enabled'] ) || empty( $method['provisioned'] ) ) {
				continue;
			}

			$entity  = isset( $method['entity'] ) ? strtoupper( sanitize_text_field( $method['entity'] ) ) : '';
			$account = isset( $method['account'] ) ? sanitize_text_field( $method['account'] ) : '';

			if ( '' === $entity || '' === $account ) {
				continue;
			}

			$parts[] = $entity . '|' . $account;
		}

		return implode( ';', $parts );
	}

	/**
	 * True when at least one method is enabled + provisioned.
	 *
	 * @return bool
	 */
	public function has_active_methods() {
		return '' !== $this->get_active_accounts_string();
	}

	/**
	 * @return string
	 */
	public function get_success_message() {
		$value = get_option( self::OPT_MSG_SUCCESS, '' );
		return '' !== $value ? (string) $value : __( 'Your payment was received. Thank you!', 'ifthenpay-payments-for-formidable' );
	}

	/**
	 * @param string $message
	 *
	 * @return void
	 */
	public function save_success_message( $message ) {
		update_option( self::OPT_MSG_SUCCESS, sanitize_textarea_field( $message ), false );
	}

	/**
	 * @return string
	 */
	public function get_pending_message() {
		$value = get_option( self::OPT_MSG_PENDING, '' );
		return '' !== $value ? (string) $value : __( 'Your payment is being confirmed. This can take a few minutes — you will receive a confirmation once it clears.', 'ifthenpay-payments-for-formidable' );
	}

	/**
	 * @param string $message
	 *
	 * @return void
	 */
	public function save_pending_message( $message ) {
		update_option( self::OPT_MSG_PENDING, sanitize_textarea_field( $message ), false );
	}

	/**
	 * @return string
	 */
	public function get_canceled_message() {
		$value = get_option( self::OPT_MSG_CANCELED, '' );
		return '' !== $value ? (string) $value : __( 'You canceled the payment before it was completed.', 'ifthenpay-payments-for-formidable' );
	}

	/**
	 * @param string $message
	 *
	 * @return void
	 */
	public function save_canceled_message( $message ) {
		update_option( self::OPT_MSG_CANCELED, sanitize_textarea_field( $message ), false );
	}

	/**
	 * @return string
	 */
	public function get_failed_message() {
		$value = get_option( self::OPT_MSG_FAILED, '' );
		return '' !== $value ? (string) $value : __( 'Your payment could not be completed. Please try again.', 'ifthenpay-payments-for-formidable' );
	}

	/**
	 * @param string $message
	 *
	 * @return void
	 */
	public function save_failed_message( $message ) {
		update_option( self::OPT_MSG_FAILED, sanitize_textarea_field( $message ), false );
	}

	/**
	 * 24h "Request Activation" cooldown, scoped per Gateway Key + method.
	 *
	 * @param string $gateway_key
	 * @param string $entity
	 *
	 * @return bool
	 */
	public function is_activation_requested( $gateway_key, $entity ) {
		return false !== get_transient( $this->activation_cache_key( $gateway_key, $entity ) );
	}

	/**
	 * @param string $gateway_key
	 * @param string $entity
	 *
	 * @return void
	 */
	public function mark_activation_requested( $gateway_key, $entity ) {
		set_transient( $this->activation_cache_key( $gateway_key, $entity ), time(), DAY_IN_SECONDS );
	}

	/**
	 * @param string $gateway_key
	 * @param string $entity
	 *
	 * @return string
	 */
	private function activation_cache_key( $gateway_key, $entity ) {
		return self::OPT_ACTIVATION_CACHE . md5( $gateway_key . '|' . strtoupper( $entity ) );
	}
}
