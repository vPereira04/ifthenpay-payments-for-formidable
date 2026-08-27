<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use Ifthenpay\Formidable\Api\IfthenpayClient;
use Ifthenpay\Formidable\Api\IfthenpayPayload;
use Ifthenpay\Formidable\Frontend\RedirectHandler;
use Ifthenpay\Formidable\Settings\SettingsRepository;

/**
 * Core business logic for the ifthenpay Formidable gateway.
 *
 * Called from the global bridge class `FrmIfthenpayActionsController::trigger_gateway()`
 * (see `classes/class-frm-ifthenpay-actions-controller.php`) so the one class
 * Formidable resolves by a dynamically-built name string stays a thin shim.
 *
 * Deliberately mirrors GravityForms' own ifthenpay integration's pattern —
 * NOT Formidable's own bundled PayPal module's — for how a payment relates
 * to the entry it's for: the real Formidable entry is created immediately,
 * in a 'pending'-payment state, exactly like any other Formidable payment
 * gateway (Stripe/Square/PayPal) already does, before ever redirecting to
 * ifthenpay's hosted page.
 *
 * An earlier version of this class deferred entry creation until payment
 * was confirmed (mirroring PayPal Lite's `create_order()`, which also never
 * writes a `wp_frm_payments` row until a captured charge exists) — that
 * only works because PayPal's own capture is synchronous and interactive:
 * the payer approves and the real form submits again, in the same browser
 * session, within seconds. ifthenpay also supports genuinely asynchronous,
 * offline methods (Multibanco, Payshop): a payer can take the reference
 * shown on ifthenpay's hosted page, close the browser entirely, and pay it
 * at an ATM or via home banking hours or days later — completely
 * disconnected from any browser session. Deferring entry creation cannot
 * work for that case at all: there is no live browser left to ever submit
 * the real form once the webhook eventually confirms it, no matter how the
 * pending state is tracked (a `wp_frm_payments` row or a transient) or how
 * long its TTL is. It also meant the reference the payer's own bank/ifthenpay
 * receipt shows them (a synthetic `FRM-PRE-...` string) never matched the
 * `FRM-{entry}-{action}` id the merchant later sees in Formidable's own
 * admin — a real support/reconciliation problem on its own. Creating the
 * real entry up front, as this version does, avoids both: the payment
 * reference is `FRM-{entry}-{action}` from the very first payload sent to
 * ifthenpay, and the webhook can complete it whenever it arrives — in two
 * minutes or two weeks — because it's a permanent row tied to a real entry,
 * not a short-lived, browser-session-bound placeholder.
 *
 * The trade-off, accepted deliberately: an attempt that's abandoned, fails,
 * or is simply never paid now does leave a real (still-pending) entry and
 * payment row behind, visible in Formidable's own Entries/Payments lists —
 * unlike PayPal Lite's own zero-trace-until-captured behavior. That's the
 * same trade-off every other Formidable payment gateway module (including
 * Formidable's own Stripe/Square modules for a *pending* charge) already
 * makes, and it's unavoidable once offline methods need to be supported.
 */
class IfthenpayGateway {

	/**
	 * Nothing to register — trigger() is called directly by
	 * FrmIfthenpayActionsController, not via a hook. Kept only so this class
	 * still exposes the same boot() lifecycle method every other bootstrapped
	 * class here does (see ifthenpay-payments-for-formidable.php).
	 *
	 * @return void
	 */
	public static function boot() {}

	/**
	 * @param object   $action Formidable payment action (WP_Post with post_content array).
	 * @param object   $entry  Formidable entry.
	 * @param object   $form   Formidable form.
	 *
	 * @return array{success:bool, run_triggers:bool, show_errors:bool, error?:string}
	 */
	public static function trigger( $action, $entry, $form ) {
		$settings = new SettingsRepository();

		if ( ! $settings->has_backoffice_key() || '' === $settings->get_gateway_key() ) {
			return array(
				'success'      => false,
				'run_triggers' => false,
				'show_errors'  => true,
				'error'        => __( 'ifthenpay is not fully configured yet. Please contact the site administrator.', 'ifthenpay-payments-for-formidable' ),
			);
		}

		if ( ! $settings->has_active_methods() ) {
			return array(
				'success'      => false,
				'run_triggers' => false,
				'show_errors'  => true,
				'error'        => __( 'No ifthenpay payment method is currently enabled. Please contact the site administrator.', 'ifthenpay-payments-for-formidable' ),
			);
		}

		$atts   = array(
			'action' => $action,
			'entry'  => $entry,
			'form'   => $form,
		);
		$amount = \FrmTransLiteActionsController::prepare_amount( $action->post_content['amount'], $atts );
		$amount = (float) $amount;

		if ( $amount <= 0 ) {
			return array(
				'success'      => false,
				'run_triggers' => false,
				'show_errors'  => true,
				'error'        => __( 'Please specify an amount for the payment.', 'ifthenpay-payments-for-formidable' ),
			);
		}

		\FrmTransLiteActionsController::prepare_description( $action, $atts );

		self::ensure_payments_table_exists();
		self::ensure_redirect_action_exists( $form->id );

		$payment_id = self::create_pending_payment( $action, $entry, $amount );

		// One secret, minted here and reused everywhere this entry's payment
		// is referenced again later: the success/error/cancel return URLs
		// ifthenpay sends the payer back to, and the same-tab "open the
		// payment link" wrapper URL (RedirectHandler::handle_open()). Entry
		// ids are small sequential ints and every one of those endpoints is
		// otherwise unauthenticated (`wp_ajax_nopriv_*`), so without it any of
		// them could be probed by guessing `?entry=<id>` for another payer's
		// in-flight payment.
		$token = wp_generate_password( 32, false );

		$payload_builder = new IfthenpayPayload( $settings );
		$payload         = $payload_builder->build(
			array(
				'id'          => self::build_reference( $entry, $action ),
				'amount'      => $amount,
				'description' => isset( $action->post_content['description'] ) ? $action->post_content['description'] : '',
				'success_url' => self::build_return_url( 'success', $entry->id, $token ),
				'error_url'   => self::build_return_url( 'error', $entry->id, $token ),
				'cancel_url'  => self::build_return_url( 'cancel', $entry->id, $token ),
			)
		);

		try {
			$response = IfthenpayClient::create_payment_link( $settings->get_gateway_key(), $payload );
		} catch ( \Throwable $e ) {
			self::mark_payment_failed( $payment_id );

			return array(
				'success'      => false,
				'run_triggers' => false,
				'show_errors'  => true,
				'error'        => __( 'Unable to start the ifthenpay payment. Please try again.', 'ifthenpay-payments-for-formidable' ),
			);
		}

		$redirect_url = isset( $response['RedirectUrl'] ) ? $response['RedirectUrl'] : ( isset( $response['PinpayUrl'] ) ? $response['PinpayUrl'] : ( isset( $response['redirect_url'] ) ? $response['redirect_url'] : '' ) );

		if ( '' === $redirect_url ) {
			self::mark_payment_failed( $payment_id );

			return array(
				'success'      => false,
				'run_triggers' => false,
				'show_errors'  => true,
				'error'        => __( 'Unable to start the ifthenpay payment. Please try again.', 'ifthenpay-payments-for-formidable' ),
			);
		}

		// `receipt_id` was already set to $payload['id'] by create_pending_payment() —
		// that's the exact value ifthenpay echoes back as `ref` on the webhook
		// (see WebhookController::handle()'s `get_one_by( $ref, 'receipt_id' )` lookup).
		// It must never be overwritten with ifthenpay's own RequestId here: RequestId
		// is a different value, and doing so used to break the webhook lookup for
		// every payment, leaving it stuck on 'pending' forever.
		self::attach_payment_link_note( $payment_id, $redirect_url );

		// ifthenpay's own id for this attempt — kept only in the short-lived
		// redirect transient (never on the payment row itself, for the same
		// reason as the comment just above) so RedirectHandler::
		// maybe_sync_payment_status() can ask ifthenpay directly for a
		// real-time method while the payer's own poll is waiting, rather
		// than only ever finding out once the async webhook lands.
		$transaction_id = isset( $response['RequestId'] ) ? (string) $response['RequestId'] : ( isset( $response['TransactionId'] ) ? (string) $response['TransactionId'] : '' );

		RedirectHandler::remember_redirect( $entry->id, $redirect_url, $token, $transaction_id );
		RedirectHandler::remember_context(
			$entry->id,
			array(
				// wp_get_referer() can be an admin-ajax.php URL (e.g. Formidable's
				// own `frm_forms_preview` builder-preview iframe) or a wp-admin
				// screen — neither renders a themed front-end page, so the payer
				// would be bounced somewhere the popup can never appear. See
				// RedirectHandler::sanitize_referrer().
				'referrer'     => RedirectHandler::sanitize_referrer( wp_get_referer() ),
				'success_info' => self::capture_real_success_info( $form ),
				'token'        => $token,
			)
		);

		return array(
			'success'      => true,
			'run_triggers' => false,
			'show_errors'  => false,
		);
	}

	/**
	 * @param object $entry
	 * @param object $action
	 *
	 * @return string
	 */
	private static function build_reference( $entry, $action ) {
		return 'FRM-' . (int) $entry->id . '-' . (int) $action->ID;
	}

	/**
	 * A lightweight admin-ajax intermediary is used instead of the original
	 * page URL because nothing available inside `trigger()` reliably captures
	 * "the exact page the visitor was on" for an AJAX-submitted form embedded
	 * via shortcode/block on an arbitrary page. See blueprint §7.5.
	 *
	 * @param string $status
	 * @param int    $entry_id
	 * @param string $token
	 *
	 * @return string
	 */
	private static function build_return_url( $status, $entry_id, $token ) {
		return add_query_arg(
			array(
				'action' => 'ifthenpay_frm_return',
				'status' => $status,
				'entry'  => (int) $entry_id,
				'token'  => $token,
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * @param object $action
	 * @param object $entry
	 * @param float  $amount
	 *
	 * @return int
	 */
	private static function create_pending_payment( $action, $entry, $amount ) {
		$new_values = array(
			// NOT FrmTransLiteAppHelper::get_formatted_amount_for_currency():
			// that helper divides by 100, because Stripe/Square/PayPal pass it
			// an amount already converted to the currency's smallest unit
			// (cents) for their own charge APIs. $amount here is already a
			// plain decimal (FrmTransLiteActionsController::prepare_amount()'s
			// own docblock: "Convert the amount into 10.00") — the exact same
			// value IfthenpayPayload::build() sends to the PBL API. Running it
			// through the /100 helper anyway silently divided every stored
			// amount by 100 (a real submitted "2.00" was stored as "0.02"),
			// which is also why the webhook's amount check kept rejecting
			// otherwise-correct callbacks as tampered.
			'amount'     => number_format( (float) $amount, 2, '.', '' ),
			'status'     => 'pending',
			'paysys'     => 'ifthenpay',
			'item_id'    => $entry->id,
			'action_id'  => $action->ID,
			'receipt_id' => self::build_reference( $entry, $action ),
			'sub_id'     => '',
			'test'       => 0,
		);

		$frm_payment = new \FrmTransLitePayment();
		return $frm_payment->create( $new_values );
	}

	/**
	 * Logs the ifthenpay Pay by Link URL onto the payment record as its own
	 * timestamped note (`FrmTransLiteAppHelper::add_meta_to_payment()` — the
	 * same mechanism WebhookController::handle() uses for its own completion
	 * note), right next to the "ifthenpay method: ... Request ID (for
	 * refunds): ..." note added once payment completes. This is the durable
	 * record of the link: `RedirectHandler`'s transient is only meant to
	 * survive the immediate browser hand-off (10 minute TTL, deleted the
	 * moment the browser returns), so it isn't reliable enough by the time a
	 * merchant looks the payment up later.
	 *
	 * @param int    $payment_id
	 * @param string $redirect_url
	 *
	 * @return void
	 */
	private static function attach_payment_link_note( $payment_id, $redirect_url ) {
		if ( ! $payment_id || '' === $redirect_url ) {
			return;
		}

		$frm_payment = new \FrmTransLitePayment();
		$payment     = $frm_payment->get_one( $payment_id );

		$frm_payment->update(
			$payment_id,
			array(
				'meta_value' => \FrmTransLiteAppHelper::add_meta_to_payment(
					$payment ? $payment->meta_value : array(),
					sprintf( 'ifthenpay Payment Link: %s', $redirect_url )
				),
			)
		);
	}

	/**
	 * @param int $payment_id
	 *
	 * @return void
	 */
	private static function mark_payment_failed( $payment_id ) {
		if ( ! $payment_id ) {
			return;
		}

		$frm_payment = new \FrmTransLitePayment();
		$frm_payment->update( $payment_id, array( 'status' => 'failed' ) );
	}

	/**
	 * Defensive: the shared `wp_frm_payments` table is normally created by
	 * Formidable's own Lite payments install routine the first time a
	 * Stripe/Square/PayPal action is used. If a merchant's very first
	 * payment action on this site is an ifthenpay one, make sure the table
	 * exists before we try to write to it.
	 *
	 * @return void
	 */
	private static function ensure_payments_table_exists() {
		if ( \FrmTransLiteAppHelper::payments_table_exists() ) {
			return;
		}

		$db = new \FrmTransLiteDb();
		$db->upgrade();
	}

	/**
	 * Reads whatever the form's own real "On Submit" success behavior is
	 * *before* our silent redirect action (see `ensure_redirect_action_exists()`)
	 * overrides it for this entry — so `RedirectHandler::handle_return()` can
	 * hand the payer back to something resembling Formidable's own native
	 * confirmation once ifthenpay is done with them, instead of a bare
	 * homepage redirect. Checked in the same precedence Formidable itself
	 * uses: the modern per-action model first, then the legacy `$form->options`
	 * fields for a form that never got migrated (see `FrmOnSubmitHelper`).
	 * A "page"-type success action has no simple later equivalent (it renders
	 * inline via `[display-frm-data]`-style page content, not a plain URL or
	 * static message) — falls through to the default message in that case.
	 *
	 * @param object $form
	 *
	 * @return array{type:string, url?:string, message?:string}
	 */
	private static function capture_real_success_info( $form ) {
		// ensure_redirect_action_exists() has already run by the time this is
		// called (see trigger()) and, past the very first payment on this
		// form, permanently leaves its own synthetic 'redirect' action sitting
		// alongside whatever the merchant actually configured — so get_all()
		// below returns BOTH actions on every single call, forever, not just
		// the first. Without excluding it by id, this loop's result depends on
		// get_all()'s return order: if it happens to surface our own action
		// first, this would report the payer's real "Thank you" experience as
		// a redirect to home_url('/') instead of their actual configured
		// message/redirect — exactly the silent-redirect symptom this
		// excludes.
		$own_redirect_action_id = (int) get_option( 'frm_ifthenpay_redirect_action_' . $form->id );

		if ( class_exists( 'FrmOnSubmitAction' ) ) {
			$on_submit = new \FrmOnSubmitAction();

			foreach ( (array) $on_submit->get_all( $form->id ) as $existing_action ) {
				if ( $own_redirect_action_id && (int) $existing_action->ID === $own_redirect_action_id ) {
					continue;
				}

				$events = isset( $existing_action->post_content['event'] ) ? (array) $existing_action->post_content['event'] : array( 'create' );

				if ( ! in_array( 'create', $events, true ) ) {
					continue;
				}

				$type = isset( $existing_action->post_content['success_action'] ) ? $existing_action->post_content['success_action'] : 'message';

				if ( 'redirect' === $type ) {
					return array(
						'type' => 'redirect',
						'url'  => isset( $existing_action->post_content['success_url'] ) ? $existing_action->post_content['success_url'] : '',
					);
				}

				if ( 'message' === $type ) {
					return array(
						'type'    => 'message',
						'message' => isset( $existing_action->post_content['success_msg'] ) ? $existing_action->post_content['success_msg'] : \FrmOnSubmitHelper::get_default_msg(),
					);
				}

				// An unhandled type (e.g. 'page') on this action doesn't mean no
				// usable message/redirect confirmation exists on the form — keep
				// scanning instead of giving up on the very first one, or a real
				// confirmation placed after it would silently never be found.
			}
		}

		// Legacy, unmigrated forms keep their On Submit config directly on $form->options.
		if ( is_array( $form->options ) && ! empty( $form->options['success_action'] ) ) {
			if ( 'redirect' === $form->options['success_action'] ) {
				return array(
					'type' => 'redirect',
					'url'  => isset( $form->options['success_url'] ) ? $form->options['success_url'] : '',
				);
			}

			if ( 'message' === $form->options['success_action'] ) {
				return array(
					'type'    => 'message',
					'message' => isset( $form->options['success_msg'] ) ? $form->options['success_msg'] : \FrmOnSubmitHelper::get_default_msg(),
				);
			}
		}

		return array(
			'type'    => 'message',
			'message' => \FrmOnSubmitHelper::get_default_msg(),
		);
	}

	/**
	 * See blueprint §3e.6 — creates a dedicated, silent On Submit "redirect"
	 * action on the form the first time an ifthenpay payment is triggered on
	 * it, only if the form doesn't already have a *live* one. This is what
	 * lets Formidable's own native `frm_redirect_url` → `response.redirect` →
	 * `window.location` chain carry the payer to the ifthenpay hosted page,
	 * without requiring the merchant to manually configure anything.
	 *
	 * Self-healing: the cached `$option_name` id is re-validated on every
	 * call, not just trusted once it's set. A merchant is free to edit or
	 * delete the confirmation this created (they have no way to tell it apart
	 * from their own otherwise, and Formidable's own UI never warns them it's
	 * plugin-managed) — if they change its type away from 'redirect' or
	 * delete it outright, the cached id would otherwise keep pointing at a
	 * now-invalid action forever, and the payment redirect would silently
	 * stop firing on every future submission with no way to recover short of
	 * manually deleting the `frm_ifthenpay_redirect_action_{$form_id}` option.
	 * Re-checking here instead means a stale/edited/deleted action is simply
	 * replaced with a fresh one on the very next payment.
	 *
	 * @param int $form_id
	 *
	 * @return void
	 */
	private static function ensure_redirect_action_exists( $form_id ) {
		$option_name = 'frm_ifthenpay_redirect_action_' . $form_id;
		$cached_id   = (int) get_option( $option_name );

		if ( $cached_id && self::is_live_redirect_action( $cached_id ) ) {
			return;
		}

		$on_submit = new \FrmOnSubmitAction();
		$existing  = $on_submit->get_all( $form_id );

		foreach ( (array) $existing as $existing_action ) {
			if ( ! isset( $existing_action->post_content['success_action'] ) || 'redirect' !== $existing_action->post_content['success_action'] ) {
				continue;
			}

			if ( empty( $existing_action->post_content['open_in_new_tab'] ) ) {
				// A redirect action from before `open_in_new_tab` started being
				// persisted (see this method's own docblock) — upgrade it in
				// place rather than matching it as-is forever.
				$existing_action->post_content['open_in_new_tab'] = true;
				$on_submit->save_settings( $existing_action );
			}

			update_option( $option_name, $existing_action->ID, false );
			return;
		}

		$on_submit->form_id = $form_id;
		$new_action         = $on_submit->prepare_new( $form_id );
		// A recognizable name, distinct from anything a merchant would name
		// their own confirmations — so it's visually obvious this one is
		// plugin-managed and shouldn't be repurposed (its actual target URL
		// is irrelevant anyway: RedirectHandler::maybe_override_redirect_url()
		// always swaps it for the real ifthenpay hosted page for the one
		// entry that's actually mid-payment).
		$new_action->post_title                      = __( 'ifthenpay Payment Redirect — do not edit', 'ifthenpay-payments-for-formidable' );
		$new_action->post_content['success_action']  = 'redirect';
		$new_action->post_content['success_url']     = home_url( '/' );
		// Explicit, not relying on FrmOnSubmitAction's own default — this
		// redirect *is* the payment hand-off, never a delayed courtesy
		// message. See RedirectHandler::strip_redirect_delay() for the other
		// half of this (an existing, reused action's delay).
		$new_action->post_content['redirect_delay']  = '';
		// Persisted here rather than left to RedirectHandler::force_open_in_new_tab()'s
		// in-request `$form->options` mutation alone — Formidable's own
		// `FrmOnSubmitHelper::populate_on_submit_data()` re-derives
		// `open_in_new_tab` FROM this action's own post_content on every
		// on-submit pass (see `maybe_trigger_redirect_with_action()` /
		// `get_run_success_action_args()` in Formidable core), which
		// overwrites/clobbers any earlier in-memory mutation with whatever's
		// actually stored on the action — leaving it unset here meant that
		// re-derivation could silently reset the payer's redirect back to a
		// same-tab navigation instead of the pre-opened-tab flow this whole
		// plugin depends on.
		$new_action->post_content['open_in_new_tab'] = true;

		$action_id = $on_submit->save_settings( $new_action );

		if ( $action_id && ! is_wp_error( $action_id ) ) {
			update_option( $option_name, $action_id, false );
		}
	}

	/**
	 * Also requires `open_in_new_tab` to still be true on the action itself —
	 * not just `success_action === 'redirect'` — so an action created by an
	 * older version of this plugin (before `open_in_new_tab` started being
	 * persisted on it directly, see `ensure_redirect_action_exists()`'s own
	 * docblock) is treated as stale and rebuilt with the field present,
	 * rather than kept around indefinitely without it.
	 *
	 * @param int $action_id
	 *
	 * @return bool True if the action still exists (isn't deleted/trashed) and is still a live redirect-type confirmation.
	 */
	private static function is_live_redirect_action( $action_id ) {
		$action = ( new \FrmOnSubmitAction() )->get_single_action( $action_id );

		if ( ! $action || 'trash' === $action->post_status ) {
			return false;
		}

		if ( ! isset( $action->post_content['success_action'] ) || 'redirect' !== $action->post_content['success_action'] ) {
			return false;
		}

		return ! empty( $action->post_content['open_in_new_tab'] );
	}
}
