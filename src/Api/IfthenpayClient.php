<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use RuntimeException;

/**
 * Thin HTTP client for the ifthenpay API.
 *
 * NOTE (flagged in blueprint §7.1): `GATEWAY_TYPE` and the `cms=formidable`
 * query arg used by `activate_callback()` are assumptions, following the
 * naming pattern of the GravityForms/FluentForms/GiveWP integrations. No
 * ifthenpay Gateway Key "type" has been provisioned for Formidable before;
 * confirm both with ifthenpay support before relying on this in production.
 */
class IfthenpayClient {

	const API_BASE     = 'https://api.ifthenpay.com';
	const GATEWAY_TYPE = 'Formidable';

	/**
	 * @var string
	 */
	private $backoffice_key;

	/**
	 * @param string $backoffice_key
	 */
	public function __construct( $backoffice_key ) {
		$this->backoffice_key = sanitize_text_field( $backoffice_key );
	}

	/**
	 * One-shot key-validity probe used by the Connect button.
	 *
	 * @param string $backoffice_key
	 *
	 * @return bool
	 */
	public static function validate_backoffice_key( $backoffice_key ) {
		$backoffice_key = sanitize_text_field( $backoffice_key );

		if ( '' === $backoffice_key ) {
			return false;
		}

		$url = add_query_arg( array( 'boKey' => $backoffice_key ), self::API_BASE . '/gateway/get' );

		try {
			$data = self::request( 'GET', $url );
			return ! empty( $data );
		} catch ( RuntimeException $e ) {
			return false;
		}
	}

	/**
	 * Returns the Gateway Key rows for this Backoffice Key, scoped to the
	 * "Formidable" gateway type (see class docblock).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_gateway_keys() {
		$args = array(
			'boKey' => $this->backoffice_key,
			'type'  => self::GATEWAY_TYPE,
		);

		return self::request( 'GET', add_query_arg( $args, self::API_BASE . '/gateway/get' ) );
	}

	/**
	 * Returns every payment method ifthenpay supports; the caller filters by
	 * `IsVisible` and cross-references against the Gateway Key's own account
	 * columns to build the methods table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_available_methods() {
		return self::request( 'GET', self::API_BASE . '/gateway/methods/available' );
	}

	/**
	 * POSTs the Pay by Link payload, returns the gateway response
	 * (RequestId, RedirectUrl/PinpayUrl, etc.).
	 *
	 * @param string               $gateway_key
	 * @param array<string, mixed> $payload
	 *
	 * @return array<string, mixed>
	 */
	public static function create_payment_link( $gateway_key, array $payload ) {
		$url = rtrim( self::API_BASE, '/' ) . '/gateway/pinpay/' . rawurlencode( $gateway_key );

		return self::request(
			'POST',
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Synchronous status check for a single Pay by Link payment, keyed by
	 * the `transactionId` ifthenpay itself returned (`RequestId`/
	 * `TransactionId`) when `create_payment_link()` created it — NOT the
	 * `id` we sent in that same call. Mirrors the sibling ifthenpay-form-
	 * builder plugin's `IfthenpayClient::get_payment_status()` (same
	 * endpoint, same param).
	 *
	 * Used as a supplementary, on-demand check (see
	 * RedirectHandler::maybe_sync_payment_status()) so a real-time method
	 * (card, MB WAY, wallets) doesn't have to wait for the async webhook to
	 * land before the payer's own poll can resolve — it cannot replace the
	 * webhook for an offline method (Multibanco, Payshop), which only ever
	 * settles later regardless of how often this is called.
	 *
	 * @param string $transaction_id
	 *
	 * @return array<string, mixed>
	 */
	public static function get_payment_status( $transaction_id ) {
		$url = add_query_arg( array( 'transactionId' => $transaction_id ), self::API_BASE . '/gateway/transaction/status/get' );

		return self::request( 'GET', $url );
	}

	/**
	 * Registers the server-to-server callback URL for a Gateway Key.
	 *
	 * `[ORDER_ID]`, `[ANTI_PHISHING_KEY]`, `[AMOUNT]` are ifthenpay API
	 * placeholders filled in by ifthenpay on every callback it fires.
	 *
	 * @param string $gateway_key
	 * @param string $base_callback_url
	 *
	 * @return bool
	 */
	public static function activate_callback( $gateway_key, $base_callback_url ) {
		$url = self::API_BASE . '/endpoint/callback/activation/?cms=formidable';

		$payload = array(
			'apKey' => base64_encode( $gateway_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'chave' => $gateway_key,
			'urlCb' => $base_callback_url . '&ref=[ORDER_ID]&apk=[ANTI_PHISHING_KEY]&val=[AMOUNT]',
		);

		try {
			$res = self::request(
				'POST',
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload ),
				)
			);
			return 'OK' === (string) ( $res['data'] ?? '' );
		} catch ( RuntimeException $e ) {
			return false;
		}
	}

	/**
	 * @param string               $method
	 * @param string               $url
	 * @param array<string, mixed> $args
	 * @param int                  $timeout
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException
	 */
	private static function request( $method, $url, array $args = array(), $timeout = 10 ) {
		$args = wp_parse_args(
			$args,
			array(
				'timeout'   => $timeout,
				'sslverify' => true,
			)
		);

		$response = 'POST' === strtoupper( $method )
			? wp_remote_post( $url, $args )
			: wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException(
				sprintf( 'ifthenpay API error (%s): %s', esc_html( (string) $code ), esc_html( mb_substr( $body, 0, 300 ) ) )
			);
		}

		return self::decode( $body );
	}

	/**
	 * @param string $body
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException
	 */
	private static function decode( $body ) {
		$data = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'Invalid JSON response from ifthenpay API.' );
		}

		if ( isset( $data['d'] ) ) {
			$data = is_string( $data['d'] ) ? json_decode( $data['d'], true ) : $data['d'];
		}

		if ( ! is_array( $data ) ) {
			return array( 'data' => $data );
		}

		return $data;
	}
}
