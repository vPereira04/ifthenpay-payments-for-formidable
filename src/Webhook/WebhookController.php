<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use Ifthenpay\Formidable\Settings\SettingsRepository;

/**
 * Handles the server-to-server ifthenpay callback.
 *
 * Served from a clean rewrite-rule endpoint (`/iftp_frm_{version}`) instead
 * of `admin-ajax.php` — same "iftp_{tag}_{version}" pattern as the
 * FluentForms integration (`IFTP_FF_CALLBACK_SLUG` /
 * `IfthenpayProcessor::handleCallbackEndpoint()`), rather than exposing
 * `wp-admin/admin-ajax.php` to ifthenpay's servers. This endpoint is
 * intentionally nonce-exempt (ifthenpay calls it server-to-server, it never
 * carries a WordPress nonce); the anti-phishing key check below is the
 * equivalent server-to-server authentication mechanism instead.
 */
class WebhookController {

	/**
	 * @return void
	 */
	public static function boot() {
		// add_rewrite_rule() needs the global $wp_rewrite object, which isn't
		// instantiated yet at plugins_loaded (where boot() itself runs) —
		// calling it directly here fataled with "add_rule() on null". Must be
		// deferred to `init` (this is also why the FluentForms integration
		// only calls it from a class constructed at `init:9`, never earlier).
		add_action( 'init', array( self::class, 'register_rewrite_rule' ) );
		add_filter( 'query_vars', array( self::class, 'register_callback_query_var' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_handle' ) );
	}

	/**
	 * @return void
	 */
	public static function register_rewrite_rule() {
		add_rewrite_rule( IFTP_FRM_CALLBACK_SLUG . '/?$', 'index.php?' . IFTP_FRM_CALLBACK_SLUG . '=1', 'top' );
	}

	/**
	 * @param string[] $vars
	 *
	 * @return string[]
	 */
	public static function register_callback_query_var( $vars ) {
		$vars[] = IFTP_FRM_CALLBACK_SLUG;
		return $vars;
	}

	/**
	 * @return void
	 */
	public static function maybe_handle() {
		if ( get_query_var( IFTP_FRM_CALLBACK_SLUG ) ) {
			self::handle();
		}
	}

	/**
	 * The base callback URL registered with ifthenpay via
	 * `IfthenpayClient::activate_callback()` — ifthenpay appends its own
	 * `ref`/`apk`/`val` placeholders on top of this.
	 *
	 * Trailing slash is deliberate: the rewrite rule matches either form, but
	 * WordPress's own canonical-redirect logic 301s the no-slash form to this
	 * one anyway (verified directly against this install), and a
	 * server-to-server webhook caller isn't guaranteed to follow redirects.
	 * Registering the already-canonical URL avoids relying on that redirect.
	 *
	 * @return string
	 */
	public static function base_url() {
		return home_url( '/' . IFTP_FRM_CALLBACK_SLUG . '/' );
	}

	/**
	 * @return void
	 */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- server-to-server callback, see class docblock.
		$ref = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '';
		$apk = isset( $_GET['apk'] ) ? sanitize_text_field( wp_unslash( $_GET['apk'] ) ) : '';
		$val = isset( $_GET['val'] ) ? sanitize_text_field( wp_unslash( $_GET['val'] ) ) : '';
		$mtd = isset( $_GET['mtd'] ) ? sanitize_text_field( wp_unslash( $_GET['mtd'] ) ) : '';
		$req = isset( $_GET['req'] ) ? sanitize_text_field( wp_unslash( $_GET['req'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $ref || '' === $apk ) {
			status_header( 400 );
			exit;
		}

		$settings    = new SettingsRepository();
		$gateway_key = $settings->get_gateway_key();

		if ( '' === $gateway_key || ! hash_equals( $gateway_key, (string) base64_decode( $apk ) ) ) {
			status_header( 403 );
			exit;
		}

		$frm_payment = new \FrmTransLitePayment();
		$payment     = $frm_payment->get_one_by( $ref, 'receipt_id' );

		if ( ! $payment ) {
			status_header( 404 );
			exit;
		}

		if ( 'complete' === $payment->status ) {
			// Already processed — idempotent no-op.
			status_header( 200 );
			exit;
		}

		if ( '' !== $val && round( (float) $val, 2 ) !== round( (float) $payment->amount, 2 ) ) {
			status_header( 409 );
			exit;
		}

		self::complete_pending_payment( $payment, $mtd, $req );

		status_header( 200 );
		exit;
	}

	/**
	 * Marks a not-yet-completed payment complete.
	 *
	 * Conditioned on `status IN ('pending', 'failed')` in the UPDATE's own
	 * WHERE clause — not a plain `WHERE id = ?` — because a webhook retry
	 * (ifthenpay's own, or a merchant manually resending one from their
	 * dashboard) landing again after this already completed it could
	 * otherwise still append a second, duplicate note. The affected-row-count
	 * catches that (0 rows) the same way handle()'s own already-'complete'
	 * check does before ever reaching here.
	 *
	 * 'failed' is included alongside 'pending' because that status is only
	 * ever set by RedirectHandler::mark_unfinished_payment() off the payer's
	 * *front-channel* redirect (a cancel/error query arg) — which, per that
	 * method's own docblock, is a provisional guess, never authoritative.
	 * This webhook is the one authoritative source of truth (server-to-server
	 * from ifthenpay itself), so a payer who canceled at the hosted page and
	 * then went back and paid on the same still-live payment link must still
	 * be completable here, not permanently locked out by that earlier guess.
	 * The prior 'failed' note is never removed — add_meta_to_payment() only
	 * appends — so the merchant still sees the full "canceled, then paid"
	 * history on the entry.
	 *
	 * @param object $payment    A real, already-linked-to-an-entry `wp_frm_payments` row.
	 * @param string $method     ifthenpay method code (e.g. 'MB', 'MBWAY'), '' if unknown.
	 * @param string $request_id ifthenpay's own request/transaction id, recorded for refunds.
	 *
	 * @return bool True if this call is the one that completed it.
	 */
	public static function complete_pending_payment( $payment, $method, $request_id ) {
		global $wpdb;

		$note = sprintf( 'ifthenpay method: %s. Request ID (for refunds): %s.', strtoupper( $method ), $request_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() can't express a WHERE status IN (...) clause.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}frm_payments SET status = %s, meta_value = %s WHERE id = %d AND status IN ( 'pending', 'failed' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not user input.
				'complete',
				maybe_serialize( \FrmTransLiteAppHelper::add_meta_to_payment( $payment->meta_value, $note ) ),
				$payment->id
			)
		);

		if ( ! $claimed ) {
			return false;
		}

		$frm_payment = new \FrmTransLitePayment();
		$payment     = $frm_payment->get_one( $payment->id );

		\FrmTransLiteActionsController::trigger_payment_status_change(
			array(
				'status'  => 'complete',
				'payment' => $payment,
			)
		);

		return true;
	}
}
