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
	const OPT_MODE_SUCCESS     = 'frm_ifthenpay_mode_success';
	const OPT_MODE_PENDING     = 'frm_ifthenpay_mode_pending';
	const OPT_URL_SUCCESS      = 'frm_ifthenpay_url_success';
	const OPT_URL_PENDING      = 'frm_ifthenpay_url_pending';
	const OPT_DISABLE_METHOD_ICONS = 'frm_ifthenpay_disable_method_icons';
	const OPT_BUTTON_TEXT          = 'frm_ifthenpay_button_text';

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
	 * Applies a submitted set of "enabled" method entities (and a candidate
	 * default method) to the saved methods snapshot, persisting both. Shared
	 * by Ajax\Controller::save_settings() and Admin\SettingsField::process_form()
	 * — the two independent save paths on the settings screen — so the
	 * enabled/default-validity rule can never drift between them.
	 *
	 * @param string[] $enabled_entities
	 * @param string   $submitted_default_method
	 *
	 * @return void
	 */
	public function apply_enabled_methods( array $enabled_entities, $submitted_default_method ) {
		$enabled_entities = array_map( 'strtoupper', $enabled_entities );
		$methods          = $this->get_methods();

		foreach ( $methods as &$method ) {
			$method['enabled'] = ! empty( $method['provisioned'] ) && in_array( strtoupper( $method['entity'] ), $enabled_entities, true );
		}
		unset( $method );

		$this->save_methods( $methods );

		$default_method     = strtoupper( $submitted_default_method );
		$default_is_enabled = false;

		foreach ( $methods as $method ) {
			if ( strtoupper( $method['entity'] ) === $default_method && ! empty( $method['enabled'] ) ) {
				$default_is_enabled = true;
				break;
			}
		}

		$this->save_default_method( $default_is_enabled ? $default_method : '' );
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
	 * 'message' (default), 'redirect', or 'open_new_tab' — Payment Received
	 * has a third, ifthenpay-only mode with no Formidable native equivalent:
	 * show the popup message on this page AND open the configured URL in a
	 * new tab alongside it.
	 *
	 * Fallback-only precedence: whenever the form's own native On Submit
	 * action is already a real "Redirect to URL", that always wins over
	 * this setting entirely, exactly as before this setting existed — see
	 * `RedirectHandler::success_mode_target()`. This setting only ever
	 * takes effect for a form left on Formidable's native default "Show
	 * Message" confirmation.
	 *
	 * @return string
	 */
	public function get_success_mode() {
		$mode = get_option( self::OPT_MODE_SUCCESS, 'message' );
		return in_array( $mode, array( 'redirect', 'open_new_tab' ), true ) ? $mode : 'message';
	}

	/**
	 * @param string $mode
	 *
	 * @return void
	 */
	public function save_success_mode( $mode ) {
		update_option( self::OPT_MODE_SUCCESS, in_array( $mode, array( 'redirect', 'open_new_tab' ), true ) ? $mode : 'message', false );
	}

	/**
	 * @return string
	 */
	public function get_success_redirect_url() {
		return (string) get_option( self::OPT_URL_SUCCESS, '' );
	}

	/**
	 * @param string $url
	 *
	 * @return void
	 */
	public function save_success_redirect_url( $url ) {
		update_option( self::OPT_URL_SUCCESS, esc_url_raw( $url ), false );
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
	 * 'message' (default, the popup text above), 'redirect', or
	 * 'open_new_tab' — the same three-way choice as Payment Received (see
	 * get_success_mode()'s own docblock: 'open_new_tab' shows the message
	 * here AND opens the configured URL in a new tab, e.g. the same
	 * "Thank you" page Payment Received redirects to, while this popup still
	 * tells the payer their payment is being confirmed) — but scoped to this
	 * plugin's own Payment Pending outcome, which (unlike Payment Received)
	 * has no native Formidable On Submit equivalent to ever defer to, so this
	 * setting is always authoritative, never just a fallback — see
	 * RedirectHandler::build_destination().
	 *
	 * @return string
	 */
	public function get_pending_mode() {
		$mode = get_option( self::OPT_MODE_PENDING, 'message' );
		return in_array( $mode, array( 'redirect', 'open_new_tab' ), true ) ? $mode : 'message';
	}

	/**
	 * @param string $mode
	 *
	 * @return void
	 */
	public function save_pending_mode( $mode ) {
		update_option( self::OPT_MODE_PENDING, in_array( $mode, array( 'redirect', 'open_new_tab' ), true ) ? $mode : 'message', false );
	}

	/**
	 * @return string
	 */
	public function get_pending_redirect_url() {
		return (string) get_option( self::OPT_URL_PENDING, '' );
	}

	/**
	 * @param string $url
	 *
	 * @return void
	 */
	public function save_pending_redirect_url( $url ) {
		update_option( self::OPT_URL_PENDING, esc_url_raw( $url ), false );
	}

	/**
	 * @return bool
	 */
	public function is_method_icons_disabled() {
		return (bool) get_option( self::OPT_DISABLE_METHOD_ICONS, false );
	}

	/**
	 * @param bool $disabled
	 *
	 * @return void
	 */
	public function save_method_icons_disabled( $disabled ) {
		update_option( self::OPT_DISABLE_METHOD_ICONS, (bool) $disabled, false );
	}

	/**
	 * The `{logo}` token marks where the ifthenpay logo is inserted —
	 * PaymentSelector::render_button_content() splits on it. A merchant who
	 * leaves it out of their custom text simply gets a logo-less button; the
	 * token is the only control over the logo, there is no separate toggle.
	 *
	 * @return string
	 */
	public function get_button_text() {
		$text = get_option( self::OPT_BUTTON_TEXT, '' );
		/* translators: pay button text. Keep the literal {logo} token where the ifthenpay logo should appear, or remove it to show text only. */
		return '' !== $text ? (string) $text : __( 'Pay with {logo}', 'ifthenpay-payments-for-formidable' );
	}

	/**
	 * @param string $text
	 *
	 * @return void
	 */
	public function save_button_text( $text ) {
		update_option( self::OPT_BUTTON_TEXT, sanitize_text_field( $text ), false );
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
