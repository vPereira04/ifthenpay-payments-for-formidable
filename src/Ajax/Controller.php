<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use Ifthenpay\Formidable\Admin\SettingsField;
use Ifthenpay\Formidable\Api\IfthenpayClient;
use Ifthenpay\Formidable\Api\IfthenpayPayload;
use Ifthenpay\Formidable\Mail\IfthenpayEmailHelper;
use Ifthenpay\Formidable\Settings\SettingsRepository;
use Ifthenpay\Formidable\Webhook\WebhookController;

/**
 * Every AJAX endpoint behind the reactive settings screen (blueprint §8.2):
 * connect/disconnect the Backoffice Key, select a Gateway Key (re-renders
 * the methods table), save the rest of the panel (description, expiry days,
 * enabled methods, default method) with its own dedicated button, and
 * request activation of an unprovisioned method.
 *
 * Formidable's own native settings capability (`frm_change_settings`) gates
 * every endpoint here except `request_activation`, which uses
 * `manage_options` literally per the WPREA "Activation Request Flow" mandate
 * (both resolve to Administrator on a stock install — see blueprint §6).
 */
class Controller {

	const NONCE_ACTION = 'iftp_frm_admin';

	/**
	 * @return void
	 */
	public static function boot() {
		add_action( 'wp_ajax_ifthenpay_frm_connect_backoffice', array( self::class, 'connect_backoffice' ) );
		add_action( 'wp_ajax_ifthenpay_frm_disconnect_backoffice', array( self::class, 'disconnect_backoffice' ) );
		add_action( 'wp_ajax_ifthenpay_frm_select_gateway_key', array( self::class, 'select_gateway_key' ) );
		add_action( 'wp_ajax_ifthenpay_frm_request_activation', array( self::class, 'request_activation' ) );
		add_action( 'wp_ajax_ifthenpay_frm_save_settings', array( self::class, 'save_settings' ) );
	}

	/**
	 * @return void
	 */
	public static function connect_backoffice() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'frm_change_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-formidable' ) ), 403 );
		}

		$backoffice_key = isset( $_POST['backoffice_key'] ) ? sanitize_text_field( wp_unslash( $_POST['backoffice_key'] ) ) : '';

		if ( '' === $backoffice_key || ! IfthenpayClient::validate_backoffice_key( $backoffice_key ) ) {
			wp_send_json_error( array( 'message' => __( 'That Backoffice Key could not be validated. Please double-check it and try again.', 'ifthenpay-payments-for-formidable' ) ) );
		}

		$settings = new SettingsRepository();
		$settings->save_backoffice_key( $backoffice_key );

		$client       = new IfthenpayClient( $backoffice_key );
		$gateway_rows = self::safe_call( array( $client, 'get_gateway_keys' ) );

		wp_send_json_success(
			array(
				'connected'    => true,
				'gateway_keys' => self::map_gateway_key_choices( (array) $gateway_rows ),
			)
		);
	}

	/**
	 * Live-refreshes the Gateway Key list and (when the stored Gateway Key
	 * still exists on the account) the methods table straight from the
	 * ifthenpay API, called on every settings-tab page render (blueprint
	 * "always correct" requirement — mirrors the GravityForms integration's
	 * page-render fetch instead of relying only on the last-saved snapshot).
	 *
	 * Never destroys the already-saved, working methods snapshot on a
	 * transient API failure — the persisted config (and the fallback
	 * returned here) is only overwritten once the live fetch actually
	 * succeeds, so a temporary ifthenpay outage can't break a working
	 * payment configuration.
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return array{gateway_keys: array<int, array<string, string>>, methods: array<int, array<string, mixed>>}
	 */
	public static function fetch_live_gateway_state( SettingsRepository $settings ) {
		$methods = $settings->get_methods();

		if ( ! $settings->has_backoffice_key() ) {
			return array(
				'gateway_keys' => array(),
				'methods'      => $methods,
			);
		}

		$client       = new IfthenpayClient( $settings->get_backoffice_key() );
		$gateway_rows = (array) self::safe_call( array( $client, 'get_gateway_keys' ) );
		$gateway_keys = self::map_gateway_key_choices( $gateway_rows );

		$gateway_key = $settings->get_gateway_key();

		if ( '' === $gateway_key || empty( $gateway_rows ) ) {
			return array(
				'gateway_keys' => $gateway_keys,
				'methods'      => $methods,
			);
		}

		$gateway_row = null;

		foreach ( $gateway_rows as $row ) {
			if ( self::first_present( $row, array( 'GatewayKey', 'gatewayKey', 'gateway_key' ) ) === $gateway_key ) {
				$gateway_row = $row;
				break;
			}
		}

		if ( null === $gateway_row ) {
			return array(
				'gateway_keys' => $gateway_keys,
				'methods'      => $methods,
			);
		}

		$catalog = self::safe_call( array( IfthenpayClient::class, 'get_available_methods' ) );

		if ( empty( $catalog ) ) {
			return array(
				'gateway_keys' => $gateway_keys,
				'methods'      => $methods,
			);
		}

		$fresh_methods = self::build_methods_from_catalog( (array) $catalog, $gateway_row, self::index_by_entity( $methods ) );

		$settings->save_methods( $fresh_methods );

		return array(
			'gateway_keys' => $gateway_keys,
			'methods'      => $fresh_methods,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $gateway_rows
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function map_gateway_key_choices( array $gateway_rows ) {
		$choices = array();

		foreach ( $gateway_rows as $row ) {
			$key = self::first_present( $row, array( 'GatewayKey', 'gatewayKey', 'gateway_key' ) );

			if ( '' === $key ) {
				continue;
			}

			$choices[] = array(
				'key'   => $key,
				'label' => self::first_present( $row, array( 'Alias', 'alias', 'Description', 'description' ), $key ),
			);
		}

		return $choices;
	}

	/**
	 * Shared build step for a methods table row set — used by both
	 * `select_gateway_key()`'s AJAX response and `fetch_live_gateway_state()`'s
	 * page-render refresh so the two call sites can never drift.
	 *
	 * @param array<int, array<string, mixed>>    $catalog
	 * @param array<string, mixed>                $gateway_row
	 * @param array<string, array<string, mixed>> $previous_methods Indexed by uppercase entity.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_methods_from_catalog( array $catalog, array $gateway_row, array $previous_methods ) {
		$methods = array();

		foreach ( $catalog as $method ) {
			$entity = strtoupper( self::first_present( $method, array( 'Entity', 'entity' ) ) );

			if ( '' === $entity ) {
				continue;
			}

			$is_visible = self::first_present( $method, array( 'IsVisible', 'is_visible' ), true );

			if ( ! $is_visible ) {
				continue;
			}

			$label       = self::first_present( $method, array( 'Alias', 'alias', 'Label', 'label' ), $entity );
			$account     = self::find_account_for_entity( $gateway_row, $entity, $label );
			$provisioned = '' !== $account;
			$previous    = isset( $previous_methods[ $entity ] ) ? $previous_methods[ $entity ] : array();

			$methods[] = array(
				'entity'      => $entity,
				'label'       => $label,
				'account'     => $account,
				// The ifthenpay PBL API's `selected_method` field takes this
				// numeric catalog Position, never the entity code — see
				// IfthenpayPayload::resolve_selected_method_position().
				'position'    => (int) self::first_present( $method, array( 'Position', 'position' ), 0 ),
				'provisioned' => $provisioned,
				'enabled'     => $provisioned && ! empty( $previous['enabled'] ),
				'image_url'   => self::resolve_logo_url( $method, $entity ),
			);
		}

		return $methods;
	}

	/**
	 * @return void
	 */
	public static function disconnect_backoffice() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'frm_change_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-formidable' ) ), 403 );
		}

		( new SettingsRepository() )->delete_backoffice_key();

		wp_send_json_success( array( 'connected' => false ) );
	}

	/**
	 * Re-fetches the methods table for a newly selected Gateway Key and
	 * returns its rendered HTML for the front-end to inject in place —
	 * no page reload (blueprint §8.2).
	 *
	 * @return void
	 */
	public static function select_gateway_key() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'frm_change_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-formidable' ) ), 403 );
		}

		$gateway_key = isset( $_POST['gateway_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_key'] ) ) : '';

		if ( '' === $gateway_key ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a Gateway Key.', 'ifthenpay-payments-for-formidable' ) ) );
		}

		$settings = new SettingsRepository();

		if ( ! $settings->has_backoffice_key() ) {
			wp_send_json_error( array( 'message' => __( 'Connect a Backoffice Key first.', 'ifthenpay-payments-for-formidable' ) ) );
		}

		$client       = new IfthenpayClient( $settings->get_backoffice_key() );
		$gateway_rows = self::safe_call( array( $client, 'get_gateway_keys' ) );
		$catalog      = self::safe_call( array( IfthenpayClient::class, 'get_available_methods' ) );

		$gateway_row = null;

		foreach ( (array) $gateway_rows as $row ) {
			$key = self::first_present( $row, array( 'GatewayKey', 'gatewayKey', 'gateway_key' ) );

			if ( $key === $gateway_key ) {
				$gateway_row = $row;
				break;
			}
		}

		if ( null === $gateway_row ) {
			wp_send_json_error( array( 'message' => __( 'That Gateway Key could not be found on this Backoffice account.', 'ifthenpay-payments-for-formidable' ) ) );
		}

		if ( empty( $catalog ) ) {
			// Never overwrite a working methods snapshot with an empty one just
			// because the methods catalog fetch had a transient failure — same
			// protection fetch_live_gateway_state() already has for page renders.
			wp_send_json_error( array( 'message' => __( 'Could not load the payment methods catalog from ifthenpay. Please try again.', 'ifthenpay-payments-for-formidable' ) ) );
		}

		$previous_methods = self::index_by_entity( $settings->get_methods() );
		$methods          = self::build_methods_from_catalog( (array) $catalog, $gateway_row, $previous_methods );

		$settings->save_gateway_key( $gateway_key );
		$settings->save_methods( $methods );

		if ( '' !== $settings->get_default_method() && ! self::default_method_still_valid( $methods, $settings->get_default_method() ) ) {
			$settings->save_default_method( '' );
		}

		wp_send_json_success(
			array(
				'html' => SettingsField::render_methods_table_rows( $methods, $gateway_key, $settings->get_default_method() ),
			)
		);
	}

	/**
	 * @return void
	 */
	public static function request_activation() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// Literal WPREA "Activation Request Flow" mandate — manage_options,
		// not frm_change_settings, for this one endpoint. See blueprint §6.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-formidable' ) ), 403 );
		}

		$entity = isset( $_POST['entity'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['entity'] ) ) ) : '';

		$settings    = new SettingsRepository();
		$gateway_key = $settings->get_gateway_key();

		if ( '' === $entity || '' === $gateway_key ) {
			wp_send_json_error( array( 'message' => __( 'Missing method or Gateway Key.', 'ifthenpay-payments-for-formidable' ) ) );
		}

		if ( $settings->is_activation_requested( $gateway_key, $entity ) ) {
			wp_send_json_error( array( 'message' => __( 'Activation for this method was already requested recently.', 'ifthenpay-payments-for-formidable' ) ) );
		}

		$sent = IfthenpayEmailHelper::send_activation_email(
			array(
				'gateway_key'    => $gateway_key,
				'entity'         => $entity,
				// Read server-side only, at point of use — never echoed back to JS. See blueprint §8.4.
				'backoffice_key' => $settings->get_backoffice_key(),
				'customer_email' => wp_get_current_user()->user_email,
				'site_url'       => home_url( '/' ),
				'site_name'      => get_bloginfo( 'name' ),
				'wp_version'     => get_bloginfo( 'version' ),
				'frm_version'    => class_exists( '\FrmAppHelper' ) ? \FrmAppHelper::plugin_version() : '',
				'plugin_version' => defined( 'IFTP_FRM_VERSION' ) ? IFTP_FRM_VERSION : '',
			)
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'The activation request could not be sent. Please try again.', 'ifthenpay-payments-for-formidable' ) ) );
		}

		$settings->mark_activation_requested( $gateway_key, $entity );

		wp_send_json_success( array( 'message' => __( 'Requested', 'ifthenpay-payments-for-formidable' ) ) );
	}

	/**
	 * Dedicated save for this panel — description, expiry days, per-method
	 * "enabled" flags, and default method — plus (re)activating the webhook
	 * callback for the current Gateway Key. Deliberately independent of
	 * Formidable's own shared Global Settings form submit (`frm_update_settings`,
	 * still handled separately by `SettingsField::process_form()`): that form
	 * wraps every settings section on the page, saved together by a single
	 * "Update" button several sections away from this one, which is easy to
	 * miss and gives no confirmation specific to ifthenpay. This button saves
	 * and confirms this panel on its own, matching the rest of this settings
	 * screen's "no reload needed" pattern.
	 *
	 * @return void
	 */
	public static function save_settings() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'frm_change_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-formidable' ) ), 403 );
		}

		$settings = new SettingsRepository();

		if ( isset( $_POST['expiry_days'] ) ) {
			$settings->save_expiry_days( absint( wp_unslash( $_POST['expiry_days'] ) ) );
		}

		$enabled_entities = isset( $_POST['methods_enabled'] ) && is_array( $_POST['methods_enabled'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['methods_enabled'] ) )
			: array();

		$methods = $settings->get_methods();

		foreach ( $methods as &$method ) {
			$method['enabled'] = ! empty( $method['provisioned'] ) && in_array( strtoupper( $method['entity'] ), array_map( 'strtoupper', $enabled_entities ), true );
		}
		unset( $method );

		$settings->save_methods( $methods );

		$default_method     = isset( $_POST['default_method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['default_method'] ) ) ) : '';
		$default_is_enabled = false;

		foreach ( $methods as $method ) {
			if ( strtoupper( $method['entity'] ) === $default_method && ! empty( $method['enabled'] ) ) {
				$default_is_enabled = true;
				break;
			}
		}

		$settings->save_default_method( $default_is_enabled ? $default_method : '' );

		$gateway_key    = $settings->get_gateway_key();
		$callback_saved = '' !== $gateway_key && IfthenpayClient::activate_callback( $gateway_key, WebhookController::base_url() );

		wp_send_json_success(
			array(
				'message'        => __( 'Settings saved.', 'ifthenpay-payments-for-formidable' ),
				'callback_saved' => $callback_saved,
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $methods
	 * @param string                            $entity
	 *
	 * @return bool
	 */
	private static function default_method_still_valid( array $methods, $entity ) {
		foreach ( $methods as $method ) {
			if ( strtoupper( $method['entity'] ) === strtoupper( $entity ) ) {
				return ! empty( $method['enabled'] ) && ! empty( $method['provisioned'] );
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $methods
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function index_by_entity( array $methods ) {
		$indexed = array();

		foreach ( $methods as $method ) {
			if ( isset( $method['entity'] ) ) {
				$indexed[ strtoupper( $method['entity'] ) ] = $method;
			}
		}

		return $indexed;
	}

	/**
	 * The exact column-naming convention ifthenpay's `/gateway/get` response
	 * uses for each method's account number (e.g. `Multibanco`, `MbWay`,
	 * `Payshop`, `CCard`...) has not been verified against a live API
	 * response for this integration — see blueprint's Implementation Notes.
	 * This defensively tries several casings of the entity name, and of the
	 * method's own display label, as a column key before giving up — ifthenpay
	 * keys some columns by the method's display name rather than its entity
	 * code (verified against a live response: the "MB" entity's column is
	 * named "Multibanco", not "MB"/"Mb" — confirmed against the GravityForms
	 * sibling's own verified `resolve_account_in_row()`).
	 *
	 * @param array<string, mixed> $gateway_row
	 * @param string               $entity
	 * @param string               $label
	 *
	 * @return string
	 */
	private static function find_account_for_entity( array $gateway_row, $entity, $label = '' ) {
		$candidates = array_filter(
			array(
				$entity,
				ucfirst( strtolower( $entity ) ),
				str_replace( ' ', '', ucwords( strtolower( str_replace( '_', ' ', $entity ) ) ) ),
				$label,
				strtoupper( $label ),
				ucfirst( strtolower( $label ) ),
			)
		);

		if ( 'MB' === strtoupper( $entity ) ) {
			$candidates[] = 'Multibanco';
			$candidates[] = 'MULTIBANCO';
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $gateway_row[ $candidate ] ) && '' !== $gateway_row[ $candidate ] ) {
				return self::extract_account_number( (string) $gateway_row[ $candidate ] );
			}
		}

		return '';
	}

	/**
	 * ifthenpay's gateway-key row returns each provisioned method's value
	 * already formatted as "ENTITY | ACCOUNT" (e.g. "MB | EDT-501474"), not a
	 * bare account number. `SettingsRepository::get_active_accounts_string()`
	 * rebuilds "ENTITY|ACCOUNT" itself from the stored entity + account, so
	 * keeping the entity prefix here produced a corrupted, doubled-up string
	 * (e.g. "MB|MB | EDT-501474") sent to the Pay by Link API — which is why
	 * ifthenpay's hosted page couldn't find the payment data. Strip it back
	 * off so only the bare account number is stored.
	 *
	 * @param string $raw_value
	 *
	 * @return string
	 */
	private static function extract_account_number( $raw_value ) {
		$raw_value = trim( $raw_value );

		if ( false !== strpos( $raw_value, '|' ) ) {
			$parts     = explode( '|', $raw_value, 2 );
			$raw_value = trim( $parts[1] );
		}

		return sanitize_text_field( $raw_value );
	}

	/**
	 * The methods catalog (`/gateway/methods/available`) carries the method's
	 * own logo under `SmallImageUrl`/`ImageUrl` (naming not fully verified
	 * against a live response — see blueprint's Implementation Notes, same
	 * caveat as `find_account_for_entity()`). Falls back to ifthenpay's
	 * predictable logo CDN, exactly like the GravityForms integration's
	 * `IfthenpayPayload::fallback_logo_url()` reference implementation.
	 *
	 * @param array<string, mixed> $method
	 * @param string               $entity
	 *
	 * @return string
	 */
	private static function resolve_logo_url( array $method, $entity ) {
		$url = self::first_present( $method, array( 'SmallImageUrl', 'small_image_url', 'ImageUrl', 'image_url', 'Logo', 'logo' ) );

		return '' !== $url ? esc_url_raw( $url ) : IfthenpayPayload::fallback_logo_url( $entity );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param string[]             $keys
	 * @param mixed                $default
	 *
	 * @return mixed
	 */
	private static function first_present( array $row, array $keys, $default = '' ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && '' !== $row[ $key ] ) {
				return is_string( $row[ $key ] ) ? sanitize_text_field( $row[ $key ] ) : $row[ $key ];
			}
		}

		return $default;
	}

	/**
	 * @param callable $callback
	 *
	 * @return array<int, mixed>
	 */
	private static function safe_call( $callback ) {
		try {
			$result = call_user_func( $callback );
			return is_array( $result ) ? $result : array();
		} catch ( \RuntimeException $e ) {
			return array();
		}
	}
}
