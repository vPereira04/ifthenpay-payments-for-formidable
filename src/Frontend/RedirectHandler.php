<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use Ifthenpay\Formidable\Settings\SettingsRepository;

/**
 * Bridges an ifthenpay Pay by Link redirect URL into Formidable's own native
 * "On Submit → Redirect to URL" mechanism, so `js/formidable.js`'s existing
 * AJAX `response.redirect` handling carries the payer to the payment page
 * without any custom front-end JavaScript required (see blueprint §3e for
 * the full trace) — the URL is a same-origin wrapper (`handle_open()`)
 * rather than the real ifthenpay URL directly, so `assets/js/frontend.js`
 * can recognize it and open it in a *new* tab, keeping the payer's original
 * tab in place only long enough to hand off: that original tab then either
 * closes itself or, if the browser won't allow that, shows a static "continue
 * in the other tab" notice and does nothing further — it is NOT involved in
 * showing the outcome. The *new* tab is the one that stays open and does
 * everything else: it goes to the real ifthenpay page, and once ifthenpay
 * sends it back (`handle_return()` — the real payment status is confirmed
 * server-to-server by `WebhookController`, this class only decides which of
 * the four outcomes to show), that same tab either redirects straight to the
 * merchant's own "Redirect to URL" target, or shows a themed popup right
 * there (`maybe_render_modal()`), never trying to close itself. Deliberately
 * NOT a two-tab "original tab polls, second tab tries to self-close and
 * race the poll" design — an earlier version of this class worked that way
 * and was fragile: a same-origin redirect chain can lose the auxiliary
 * "opened by script" relationship a same-tab `window.close()` needs (e.g. an
 * intermediate page setting a Cross-Origin-Opener-Policy header), so the tab
 * meant to disappear could get silently stuck open instead.
 */
class RedirectHandler {

	const TRANSIENT_PREFIX         = 'frm_ifthenpay_redirect_';
	const CONTEXT_TRANSIENT_PREFIX = 'frm_ifthenpay_context_';
	const TRANSIENT_TTL     = 600; // 10 minutes — long enough to cover the redirect step.

	/**
	 * Memoizes compute_modal_data() for the current request — both
	 * maybe_enqueue_frontend_assets() (wp_enqueue_scripts) and
	 * maybe_render_modal() (wp_footer) need it, and there's no reason to
	 * recompute (DB lookups) twice in one request.
	 *
	 * @var array|null
	 */
	private static $modal_data;

	/**
	 * @var bool
	 */
	private static $modal_data_computed = false;

	/**
	 * @return void
	 */
	public static function boot() {
		add_filter( 'frm_redirect_url', array( self::class, 'maybe_override_redirect_url' ), 5, 3 );
		add_filter( 'frm_get_met_on_submit_actions', array( self::class, 'maybe_force_single_redirect_action' ), 10, 2 );
		add_filter( 'frm_form_attributes', array( self::class, 'maybe_mark_payment_form' ), 10, 2 );
		add_filter( 'frm_time_to_check_duplicates', array( self::class, 'maybe_disable_duplicate_check' ), 10, 2 );
		add_action( 'wp_ajax_ifthenpay_frm_return', array( self::class, 'handle_return' ) );
		add_action( 'wp_ajax_nopriv_ifthenpay_frm_return', array( self::class, 'handle_return' ) );
		add_action( 'wp_ajax_ifthenpay_frm_open', array( self::class, 'handle_open' ) );
		add_action( 'wp_ajax_nopriv_ifthenpay_frm_open', array( self::class, 'handle_open' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_frontend_assets' ) );
		add_action( 'wp_footer', array( self::class, 'maybe_render_modal' ) );
	}

	/**
	 * @param int    $entry_id
	 * @param string $redirect_url   The real ifthenpay hosted payment page URL.
	 * @param string $token          Same one-time secret threaded through this entry's whole
	 *                               payment lifecycle — see IfthenpayGateway::trigger(). Required
	 *                               by handle_open() before it will hand the URL back out.
	 * @param string $transaction_id ifthenpay's own id for this attempt (its `RequestId`/
	 *                               `TransactionId`), if it returned one — used by
	 *                               maybe_sync_payment_status() (see its own docblock), empty
	 *                               string otherwise.
	 *
	 * @return void
	 */
	public static function remember_redirect( $entry_id, $redirect_url, $token, $transaction_id = '' ) {
		set_transient(
			self::TRANSIENT_PREFIX . (int) $entry_id,
			array(
				'url'            => esc_url_raw( $redirect_url ),
				'token'          => (string) $token,
				'transaction_id' => (string) $transaction_id,
			),
			self::TRANSIENT_TTL
		);
	}

	/**
	 * Remembers what Formidable would have done on its own — the form's real
	 * On Submit behavior, plus the page the payer actually submitted from —
	 * so `handle_return()` can hand the payer back to something resembling
	 * that native experience once ifthenpay is done with them instead of a
	 * bare `home_url('/')`.
	 *
	 * @param int   $entry_id
	 * @param array $context {
	 *     @type string $referrer     The page the form was submitted from — already
	 *                                validated by sanitize_referrer().
	 *     @type array  $success_info See `IfthenpayGateway::capture_real_success_info()`.
	 *     @type string $token        The one secret IfthenpayGateway::trigger() mints once
	 *                                and reuses for this entry's whole payment lifecycle —
	 *                                see its own docblock for the full list of endpoints
	 *                                this gates.
	 * }
	 *
	 * @return void
	 */
	public static function remember_context( $entry_id, array $context ) {
		set_transient( self::CONTEXT_TRANSIENT_PREFIX . (int) $entry_id, $context, self::TRANSIENT_TTL );
	}

	/**
	 * @param int $entry_id
	 *
	 * @return array
	 */
	private static function get_context( $entry_id ) {
		$context = get_transient( self::CONTEXT_TRANSIENT_PREFIX . (int) $entry_id );
		return is_array( $context ) ? $context : array();
	}

	/**
	 * Rejects anything that isn't a normal, on-this-site front-end page —
	 * an admin-ajax.php URL (Formidable's own "Preview" builder tool loads a
	 * form via `admin-ajax.php?action=frm_forms_preview`, and a merchant
	 * testing a payment form from inside that preview iframe would otherwise
	 * have `wp_get_referer()` return that raw AJAX URL), a wp-admin/wp-login
	 * screen, or an off-site URL. None of those render `wp_footer()`, so the
	 * popup could never appear there — falling back to the homepage beats
	 * silently stranding the payer on a blank AJAX response.
	 *
	 * @param string|false $referrer
	 *
	 * @return string
	 */
	public static function sanitize_referrer( $referrer ) {
		if ( ! $referrer ) {
			return home_url( '/' );
		}

		$referrer_host = wp_parse_url( $referrer, PHP_URL_HOST );
		$site_host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( ! $referrer_host || ! $site_host || $referrer_host !== $site_host ) {
			return home_url( '/' );
		}

		$path = (string) wp_parse_url( $referrer, PHP_URL_PATH );

		if ( false !== stripos( $path, 'admin-ajax.php' ) || false !== stripos( $path, '/wp-admin/' ) || false !== stripos( $path, '/wp-login.php' ) ) {
			return home_url( '/' );
		}

		return esc_url_raw( $referrer );
	}

	/**
	 * Appends `data-iftp-payment="1"` onto the `<form>` tag itself (via
	 * Formidable's own `frm_form_attributes` filter — the same filter
	 * `entry-form.php` echoes straight into the opening `<form ...>` markup)
	 * for any form carrying an active ifthenpay "Collect a Payment" action.
	 * `assets/js/frontend.js` uses this to decide, at *submit* time, whether
	 * to pre-open a blank tab for the payment page — deliberately not done
	 * for every Formidable form on the site, since that would flash a blank
	 * tab open-then-closed on every unrelated form's submit too.
	 *
	 * @param string $attributes
	 * @param object $form
	 *
	 * @return string
	 */
	public static function maybe_mark_payment_form( $attributes, $form ) {
		if ( self::form_has_ifthenpay_payment_action( $form ) ) {
			$attributes .= ' data-iftp-payment="1"';
		}

		return $attributes;
	}

	/**
	 * @param object $form
	 *
	 * @return bool
	 */
	public static function form_has_ifthenpay_payment_action( $form ) {
		if ( ! $form || ! isset( $form->id ) || ! class_exists( 'FrmFormAction' ) ) {
			return false;
		}

		foreach ( (array) \FrmFormAction::get_action_for_form( $form->id, 'payment' ) as $payment_action ) {
			if ( self::action_allows_ifthenpay( $payment_action ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A "Collect a Payment" action's `gateway` field is a checkbox group, not
	 * a single choice — a merchant can enable more than one gateway on the
	 * same action and let the payer pick at submission time (that's the
	 * "Credit Card" / "PayPal" radio row Formidable's own Stripe/PayPal
	 * modules already render). `post_content['gateway']` is therefore stored
	 * as an array (e.g. `['ifthenpay']`, or `['stripe','ifthenpay']`), never
	 * a bare string — comparing it directly against `'ifthenpay'` with `===`
	 * can never match.
	 *
	 * @param object $payment_action
	 *
	 * @return bool
	 */
	private static function action_allows_ifthenpay( $payment_action ) {
		if ( ! isset( $payment_action->post_content['gateway'] ) ) {
			return false;
		}

		return in_array( 'ifthenpay', (array) $payment_action->post_content['gateway'], true );
	}

	/**
	 * Formidable refuses to create a new entry (`FrmEntry::is_duplicate()`)
	 * whenever another entry with identical field values was created on the
	 * same form within the last `$duplicate_entry_time` seconds (60 by
	 * default) — the payer sees Formidable's own generic "We're sorry. It
	 * looks like you've already submitted that." failure message and no
	 * entry, no payment attempt, nothing is created at all.
	 *
	 * `IfthenpayGateway::trigger()` always creates a real, permanent entry up
	 * front for every payment *attempt*, completed or not (see its own class
	 * docblock for why) — so a payer whose first attempt failed, was
	 * canceled, or was simply abandoned, and who tries again with the exact
	 * same field values (the same item and amount is the normal case, not an
	 * edge one) gets wrongly told they already submitted, with no way to
	 * retry short of changing an answer just to dodge the duplicate hash.
	 * Formidable's own duplicate window makes sense for a typical form
	 * (double-clicking a "Send" button); it doesn't for a purchase a payer
	 * may legitimately want to retry, or repeat, at any interval — ifthenpay
	 * itself, not this entry-level heuristic, is what actually prevents a
	 * duplicate charge.
	 *
	 * @param int   $seconds
	 * @param array $new_values `FrmEntry::package_entry_data()`'s output — includes `form_id`.
	 *
	 * @return int
	 */
	public static function maybe_disable_duplicate_check( $seconds, $new_values ) {
		$form_id = isset( $new_values['form_id'] ) ? (int) $new_values['form_id'] : 0;

		if ( ! $form_id || ! class_exists( 'FrmForm' ) ) {
			return $seconds;
		}

		$form = \FrmForm::getOne( $form_id );

		return $form && self::form_has_ifthenpay_payment_action( $form ) ? 0 : $seconds;
	}

	/**
	 * Filters Formidable's own On Submit "Redirect to URL" action target.
	 * Only overrides the URL for the specific entry that has a pending
	 * ifthenpay redirect; every other entry (no payment, a different
	 * gateway, or a merchant's own manually-configured redirect) is left
	 * completely untouched.
	 *
	 * Returns handle_open()'s wrapper URL rather than the real ifthenpay
	 * hosted-page URL directly, and forces `open_in_new_tab` on for this one
	 * redirect (see force_open_in_new_tab()) — Formidable's own front-end
	 * script (`js/formidable.js`'s `doRedirect()`) then does
	 * `window.open(response.redirect, '_blank')` itself instead of
	 * navigating the current tab away, so the payer's original tab stays on
	 * the form long enough to run assets/js/frontend.js's own
	 * `frmBeforeFormRedirect` listener (hands off to the new tab and closes
	 * itself, or shows a static "continue in the other tab" notice) while the
	 * new tab lands on handle_open() and 302s straight to the real ifthenpay
	 * URL. Without JS at all, Formidable's own code falls back to
	 * `window.location = response.redirect` regardless, landing on the exact
	 * same handle_open() 302 in the current tab instead — a transparent,
	 * no-JS-required fallback for the single-tab flow.
	 *
	 * @param string $url
	 * @param object $form
	 * @param array  $args
	 *
	 * @return string
	 */
	public static function maybe_override_redirect_url( $url, $form, $args ) {
		$entry_id = isset( $args['entry_id'] ) ? (int) $args['entry_id'] : 0;

		if ( ! $entry_id ) {
			return $url;
		}

		$pending = get_transient( self::TRANSIENT_PREFIX . $entry_id );

		if ( ! is_array( $pending ) || empty( $pending['url'] ) ) {
			return $url;
		}

		self::strip_redirect_delay( $form );
		self::force_open_in_new_tab( $form );

		return add_query_arg(
			array(
				'action' => 'ifthenpay_frm_open',
				'entry'  => $entry_id,
				'token'  => isset( $pending['token'] ) ? $pending['token'] : '',
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Formidable only takes its fast, single-action redirect path
	 * (`FrmFormsController::redirect_after_submit()`, JSON `response.redirect`,
	 * which fires the `frmBeforeFormRedirect` event `assets/js/frontend.js`
	 * relies on for the pre-opened tab / hand-off) when
	 * exactly one On Submit "Confirmation" action is met for the entry. As
	 * soon as a merchant's own message-type confirmation is *also* met
	 * alongside our synthetic redirect one (the normal case), Formidable's
	 * `run_multi_on_submit_actions()` instead forces its multi-action,
	 * artificially-delayed (`redirect_delay_time`, 8s by default) inline-`
	 * <script>` path — which never fires `frmBeforeFormRedirect` at all, so
	 * neither the fast tab-open nor the success/failure popup ever happen.
	 *
	 * Filtering `$met_actions` down to just the redirect-type action(s) here,
	 * only for the one entry actually mid-ifthenpay-payment, makes Formidable
	 * treat the submission as a single-action confirmation regardless of how
	 * many *other* confirmations the merchant has configured on the form —
	 * their own message/page/redirect confirmations are simply deferred to
	 * `RedirectHandler::resolve_message()`'s own popup once the payment
	 * actually resolves, the same way `capture_real_success_info()` already
	 * intended.
	 *
	 * Never returns an empty array: if nothing in `$met_actions` is a
	 * redirect-type action (e.g. `ensure_redirect_action_exists()` somehow
	 * failed to establish one), the original, unfiltered list is returned
	 * unchanged rather than risking Formidable's own no-redirect fallback.
	 *
	 * @param array $met_actions Formidable's own On Submit actions that meet this entry's conditional logic.
	 * @param array $args        See `FrmFormsController::get_met_on_submit_actions()` — includes `entry_id`.
	 *
	 * @return array
	 */
	public static function maybe_force_single_redirect_action( $met_actions, $args ) {
		$entry_id = isset( $args['entry_id'] ) ? (int) $args['entry_id'] : 0;

		if ( ! $entry_id || ! class_exists( 'FrmOnSubmitHelper' ) ) {
			return $met_actions;
		}

		$pending = get_transient( self::TRANSIENT_PREFIX . $entry_id );

		if ( ! is_array( $pending ) || empty( $pending['url'] ) ) {
			return $met_actions;
		}

		$redirect_only = array();

		foreach ( (array) $met_actions as $action ) {
			if ( 'redirect' === \FrmOnSubmitHelper::get_action_type( $action ) ) {
				$redirect_only[] = $action;
			}
		}

		return $redirect_only ? $redirect_only : $met_actions;
	}

	/**
	 * Formidable's own "On Submit → Redirect" action supports an optional
	 * multi-second "show a message, then redirect" delay
	 * (`post_content['redirect_delay']`/`redirect_delay_time`, 8s by default
	 * when enabled) meant for a merchant's own thank-you-page hand-off.
	 * `ensure_redirect_action_exists()` may end up reusing whichever redirect
	 * action a form already had for its own unrelated purposes — if that
	 * action has the delay enabled, the same multi-second pause would also
	 * apply to the off-site ifthenpay redirect, which must never be delayed:
	 * the "redirect" *is* the payment flow here, not a courtesy pause before
	 * one.
	 *
	 * `$form` here is the exact same object `redirect_after_submit()` goes on
	 * to read `options['redirect_delay']` from a few lines later (both for
	 * the AJAX `response.delay` path and the non-AJAX `wp_redirect()` vs.
	 * delayed-JS-redirect branch) — mutating it in place here reaches both,
	 * regardless of whether this form has been migrated to Formidable's
	 * newer multi-on-submit-action model. (An earlier attempt hooked
	 * `frm_get_run_success_action_args` instead, which only fires for
	 * migrated forms — this form isn't one, which is why that attempt had no
	 * effect.) `frm_redirect_url` itself always fires here, on every path,
	 * strictly before either delay check.
	 *
	 * Also zeroes `redirect_delay_time` (the numeric seconds), not just the
	 * `redirect_delay` boolean above — `maybe_force_single_redirect_action()`
	 * should already keep Formidable off the delayed-JS path entirely, but
	 * `redirect_after_submit_using_js()` reads `redirect_delay_time`
	 * unconditionally if that path is ever reached anyway (e.g. a future
	 * Formidable version, or an edge case this class doesn't anticipate), so
	 * this is a cheap, harmless second line of defense against the same 8s
	 * default (`FrmOnSubmitAction::get_defaults()`) reappearing.
	 *
	 * @param object $form
	 *
	 * @return void
	 */
	private static function strip_redirect_delay( $form ) {
		if ( isset( $form->options ) && is_array( $form->options ) ) {
			if ( isset( $form->options['redirect_delay'] ) ) {
				$form->options['redirect_delay'] = '';
			}

			if ( isset( $form->options['redirect_delay_time'] ) ) {
				$form->options['redirect_delay_time'] = 0;
			}
		}
	}

	/**
	 * Makes Formidable's own AJAX submit response carry `openInNewTab: 1`
	 * for this one redirect, so its front-end script's `doRedirect()`
	 * (`js/formidable.js`) does `window.open( response.redirect, '_blank' )`
	 * instead of `window.location = response.redirect` — keeping the payer's
	 * original tab on the form (see maybe_override_redirect_url()'s own
	 * docblock for the full flow this enables).
	 *
	 * Same technique and same reasoning as strip_redirect_delay() just above
	 * (mutating the exact object `FrmFormsController::get_ajax_redirect_response_data()`
	 * goes on to read `options['open_in_new_tab']` from a few lines later,
	 * rather than hooking a filter that only fires for a migrated form) — a
	 * plain front-end "Open success page in a new tab" checkbox drives that
	 * same option normally; this flips it on programmatically only for the
	 * one entry that actually has a pending ifthenpay redirect; every other
	 * submission on this form keeps whatever the merchant actually
	 * configured, on the very next request.
	 *
	 * @param object $form
	 *
	 * @return void
	 */
	private static function force_open_in_new_tab( $form ) {
		if ( isset( $form->options ) && is_array( $form->options ) ) {
			$form->options['open_in_new_tab'] = true;
		}
	}

	/**
	 * Resolves handle_open()'s wrapper URL to the real ifthenpay hosted
	 * payment page and 302s there — same tab (no-JS fallback) or the
	 * pre-opened new tab (assets/js/frontend.js), whichever loaded this URL.
	 * Token-gated: without it, entry ids being small sequential ints would
	 * let anyone probe `?entry=<guessed-id>` and get redirected to another
	 * payer's live payment link mid-flight (their card isn't charged without
	 * their own action there, but it's still an avoidable info leak — the
	 * amount/description are visible on that hosted page).
	 *
	 * @return void
	 */
	public static function handle_open() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- same-tab hand-off immediately after an AJAX submit, not a WP form submission; token-gated below.
		$entry_id = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;
		$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$stored = $entry_id ? get_transient( self::TRANSIENT_PREFIX . $entry_id ) : false;

		if ( is_array( $stored ) && ! empty( $stored['url'] ) && ! empty( $stored['token'] ) && hash_equals( $stored['token'], $token ) ) {
			wp_redirect( $stored['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- the ifthenpay hosted payment page, off-site by design.
			exit;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Lightweight intermediary target for the PBL `success_url`/`error_url`/
	 * `cancel_url` (see blueprint §5a/§7.5). ifthenpay redirects the payer's
	 * browser here after the hosted page; the real payment status is
	 * confirmed server-to-server by `WebhookController`, not by this
	 * front-channel redirect, which — per the ifthenpay PBL contract — is
	 * never authoritative on its own. This only uses it to pick which popup
	 * to show and, for a cancel/error, to mark the payment as such (only
	 * while it's still `pending` — a webhook that already completed it is
	 * never overwritten).
	 *
	 * Sends the payer back to the page Formidable itself would have used:
	 * the form's own configured "Redirect to URL" target on success, or the
	 * page they actually submitted from otherwise — with a query flag that
	 * `maybe_render_modal()` turns into a themed popup there.
	 *
	 * @return void
	 */
	public static function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- front-channel hand-off from ifthenpay, not a WP form submission; token-verified below.
		$entry_id     = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;
		$status_param = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$token        = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$context      = $entry_id ? self::get_context( $entry_id ) : array();
		$stored_token = isset( $context['token'] ) ? $context['token'] : '';
		$token_ok     = $entry_id && '' !== $stored_token && hash_equals( $stored_token, $token );

		// A cancel/error claim only ever flips a real payment to 'failed' when
		// the token proves this actually came from the link ifthenpay was
		// given for this entry (IfthenpayGateway::trigger()) — otherwise
		// resolve_outcome() falls through to its 'pending' default, same as
		// if no status were present at all. Without this, anyone could flip a
		// stranger's still-pending payment to 'failed' (and fire the "Failed
		// Payment" merchant notification for it) just by guessing
		// `?action=ifthenpay_frm_return&status=error&entry=<id>` directly —
		// entry ids are small sequential ints and this endpoint is
		// necessarily `wp_ajax_nopriv_*`.
		$outcome = self::resolve_outcome( $entry_id, $token_ok ? $status_param : '' );

		// CONTEXT_TRANSIENT_PREFIX is deliberately not cleared here — this
		// same tab's *next* page load (this redirect's own destination, one
		// hop away for the message/open_new_tab modes) needs it to resolve the
		// actual message via compute_modal_data(). Self-deletes via its own
		// short TTL (self::TRANSIENT_TTL) either way, which already covers an
		// abandoned redirect.

		// Reuses the one token IfthenpayGateway::trigger() minted for this
		// entry's whole lifecycle rather than minting a new one — handing a
		// verified token forward when we have one, or none at all rather than
		// a value that would incorrectly pass compute_modal_data()'s own check.
		$destination = self::build_destination( $outcome, $context, $entry_id, $token_ok ? $stored_token : null );

		// A merchant's own "Redirect to URL" target is allowed to be off-site
		// (Formidable's own redirect_after_submit() uses plain wp_redirect()
		// for exactly this reason) — only these two branches of
		// build_destination() can return one (Payment Received's own
		// success_mode_target(), or this plugin's own Pending redirect
		// setting — see outcome_redirect_url()), everything else stays on
		// this site. 'open_new_tab' is deliberately excluded here — it never
		// navigates *this* page away, only opens an extra tab once the popup
		// itself renders (see build_modal_html()). Canceled/Failed can never
		// be a configured redirect — they always send the payer back to the
		// form with a fixed message.
		$is_configured_redirect = ( 'success' === $outcome && 'redirect' === self::success_mode_target( $context )['mode'] )
			|| ( 'pending' === $outcome && '' !== self::outcome_redirect_url( $outcome ) );

		if ( $is_configured_redirect ) {
			wp_redirect( $destination ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * @param int    $entry_id
	 * @param string $status_param Raw `status` query arg ifthenpay sent us back — a hint, never authoritative.
	 *
	 * @return string One of 'success', 'pending', 'failed', 'canceled'.
	 */
	private static function resolve_outcome( $entry_id, $status_param ) {
		$payment = $entry_id ? ( new \FrmTransLitePayment() )->get_one_by( $entry_id, 'item_id' ) : null;

		if ( $payment && 'complete' === $payment->status ) {
			return 'success';
		}

		if ( 'cancel' === $status_param ) {
			self::mark_unfinished_payment( $payment, __( 'Payment canceled by the payer at the ifthenpay hosted page.', 'ifthenpay-payments-for-formidable' ) );
			return 'canceled';
		}

		if ( 'error' === $status_param ) {
			self::mark_unfinished_payment( $payment, __( 'ifthenpay reported the payment as failed or incomplete.', 'ifthenpay-payments-for-formidable' ) );
			return 'failed';
		}

		// $status_param is 'success' (or missing) but the webhook hasn't
		// landed yet — the payer's browser often beats it back here. Rather
		// than only ever resolving once/if that async webhook arrives (which,
		// on a site that isn't publicly reachable from ifthenpay's servers —
		// e.g. local development — may be never), ask ifthenpay directly for
		// this attempt's real-time status right now — this is the ONE chance
		// to resolve it synchronously, since this tab shows whatever outcome
		// this call returns and there is no later poll to catch up if it
		// hasn't settled yet. A no-op for an offline method (Multibanco,
		// Payshop) or if ifthenpay never returned a transaction id for this
		// attempt — see maybe_sync_payment_status()'s own docblock; either way
		// falls through to the 'pending' outcome below, and the webhook still
		// completes the payment server-side whenever it does land.
		if ( $payment && 'pending' === $payment->status ) {
			self::maybe_sync_payment_status( $payment, $entry_id );
			$payment = ( new \FrmTransLitePayment() )->get_one( $payment->id );

			if ( $payment && 'complete' === $payment->status ) {
				return 'success';
			}
		}

		return 'pending';
	}

	/**
	 * Marks a still-pending payment 'failed' and fires Formidable's own
	 * payment-status trigger (so any "Failed Payment" email/action a
	 * merchant configured still runs) — mirroring what `WebhookController`
	 * does for a completed one. A no-op if the payment is missing or already
	 * resolved (the check-then-write is done as one conditional `UPDATE …
	 * WHERE id = ? AND status = 'pending'`, not a separate read then a plain
	 * `WHERE id = ?` write, specifically so a webhook that completes this
	 * same payment in the narrow window between the `'pending' !== status`
	 * check above and this running can never be raced and overwritten back
	 * to 'failed' — a paid payment must never be able to end up shown as
	 * failed).
	 *
	 * @param object|null $payment
	 * @param string      $note
	 *
	 * @return void
	 */
	private static function mark_unfinished_payment( $payment, $note ) {
		if ( ! $payment || 'pending' !== $payment->status ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- FrmTransLitePayment::update() can't express the conditional `WHERE status = 'pending'` guard needed to avoid racing a concurrent webhook completion.
		$claimed = $wpdb->update(
			$wpdb->prefix . 'frm_payments',
			array(
				'status'     => 'failed',
				// maybe_serialize(), matching FrmTransLitePayment's own
				// get_defaults()['meta_value']['sanitize'] — this bypasses its
				// update()/fill_values() (needed for the conditional WHERE
				// below), which is what normally does this automatically.
				'meta_value' => maybe_serialize( \FrmTransLiteAppHelper::add_meta_to_payment( $payment->meta_value, $note ) ),
			),
			array(
				'id'     => $payment->id,
				'status' => 'pending',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( ! $claimed ) {
			return;
		}

		$frm_payment = new \FrmTransLitePayment();

		\FrmTransLiteActionsController::trigger_payment_status_change(
			array(
				'status'  => 'failed',
				'payment' => $frm_payment->get_one( $payment->id ),
			)
		);
	}

	/**
	 * @param string      $outcome From `resolve_outcome()`.
	 * @param array       $context From `get_context()`.
	 * @param int         $entry_id
	 * @param string|null $token   One-time secret from `handle_return()`, forwarded as
	 *                             `ifthenpay_token` so `compute_modal_data()` can prove
	 *                             the popup load is the actual return trip and not a
	 *                             guessed `?ifthenpay_entry=` URL.
	 *
	 * @return string
	 */
	private static function build_destination( $outcome, $context, $entry_id, $token = null ) {
		$referrer = ! empty( $context['referrer'] ) ? $context['referrer'] : home_url( '/' );

		if ( 'success' === $outcome ) {
			$target = self::success_mode_target( $context );

			if ( 'redirect' === $target['mode'] ) {
				return $target['url'];
			}

			// 'message' and 'open_new_tab' both stay on-site (the popup query
			// string below) — open_new_tab's extra tab is opened client-side
			// once the popup itself renders, see build_modal_html().
		} elseif ( 'pending' === $outcome ) {
			$redirect_url = self::outcome_redirect_url( $outcome );

			if ( '' !== $redirect_url ) {
				return $redirect_url;
			}
		}

		// Canceled and Failed always fall through to here — the payer is
		// always sent back to the form page (referrer) with the popup query
		// string below, never a configured redirect.

		$args = array(
			'ifthenpay_notice' => $outcome,
			'ifthenpay_entry'  => $entry_id,
		);

		if ( $token ) {
			$args['ifthenpay_token'] = $token;
		}

		return add_query_arg( $args, $referrer );
	}

	/**
	 * Resolves what Payment Received should actually do, in the same
	 * fallback-only precedence order everywhere this outcome is decided
	 * (build_destination(), handle_return()'s off-site check,
	 * compute_modal_data()) — kept as one shared helper so that precedence
	 * can never drift between those call sites:
	 *
	 * 1. The form's own native On Submit "Redirect to URL" action, captured
	 *    at submit time (`IfthenpayGateway::capture_real_success_info()`) —
	 *    always wins when present. Any Formidable field-value shortcodes in
	 *    it are NOT re-resolved here the way a normal, same-request redirect
	 *    would; a static URL is the common case and works as-is.
	 * 2. Otherwise (the form was left on Formidable's native default "Show
	 *    Message" confirmation), this plugin's own Payment Received setting
	 *    (`SettingsRepository::get_success_mode()`) decides: 'redirect'
	 *    (a real page redirect) or 'open_new_tab' (stays on the popup, but
	 *    the caller should also open `url` in a new tab — see
	 *    build_modal_html()). Falls back to 'message' when no URL is
	 *    configured for either of those, same as `outcome_redirect_url()`
	 *    does for Pending.
	 *
	 * @param array $context From `get_context()` — needs `success_info`.
	 *
	 * @return array{mode:string, url:string} `url` is always empty when `mode` is 'message'.
	 */
	private static function success_mode_target( array $context ) {
		$success_info = isset( $context['success_info'] ) ? $context['success_info'] : array();

		if ( 'redirect' === ( $success_info['type'] ?? '' ) && ! empty( $success_info['url'] ) ) {
			return array(
				'mode' => 'redirect',
				'url'  => esc_url_raw( $success_info['url'] ),
			);
		}

		$settings = new SettingsRepository();
		$mode     = $settings->get_success_mode();
		$url      = $settings->get_success_redirect_url();

		if ( 'message' === $mode || '' === $url ) {
			return array(
				'mode' => 'message',
				'url'  => '',
			);
		}

		return array(
			'mode' => $mode,
			'url'  => $url,
		);
	}

	/**
	 * The merchant's own "Redirect to URL" choice for the Pending outcome
	 * (settings screen — see SettingsRepository's `get_pending_mode()`/
	 * `get_pending_redirect_url()`), mirroring Formidable's own native On
	 * Submit "Redirect to URL" option but scoped to this plugin's own
	 * fallback outcome. Deliberately never called for 'success', which has
	 * its own, three-way version of this same lookup — see
	 * success_mode_target(). Canceled/Failed have no mode setting of their
	 * own — they're never a configured redirect (see build_destination()).
	 *
	 * @param string $outcome Must be 'pending'.
	 *
	 * @return string Empty when Pending is still set to "Show Message"/"Open in a New Tab" (the default) or has no URL configured.
	 */
	private static function outcome_redirect_url( $outcome ) {
		if ( 'pending' !== $outcome ) {
			return '';
		}

		$settings = new SettingsRepository();
		$mode     = $settings->get_pending_mode();
		$url      = $settings->get_pending_redirect_url();

		return ( 'redirect' === $mode && '' !== $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * The URL to open in a new tab alongside the popup — the "Open in a New
	 * Tab" mode's own target, for every outcome that offers it (currently
	 * Payment Received and Payment Pending; see SettingsRepository's
	 * `get_success_mode()`/`get_pending_mode()`). Deliberately separate from
	 * outcome_redirect_url() above, which only ever returns a URL for the
	 * 'redirect' mode (a real page navigation, replacing the popup entirely)
	 * — this one only ever returns a URL for 'open_new_tab' (the popup itself
	 * still renders; the URL just opens alongside it, client-side — see
	 * build_modal_html()/assets/js/frontend.js's `activateModal()`).
	 *
	 * @param string $claimed
	 * @param array  $context From `get_context()` — needs `success_info` for the 'success' case.
	 *
	 * @return string
	 */
	private static function open_new_tab_url( $claimed, array $context ) {
		if ( 'success' === $claimed ) {
			$target = self::success_mode_target( $context );
			return 'open_new_tab' === $target['mode'] ? $target['url'] : '';
		}

		if ( 'pending' === $claimed ) {
			$settings = new SettingsRepository();
			return 'open_new_tab' === $settings->get_pending_mode() ? $settings->get_pending_redirect_url() : '';
		}

		return '';
	}

	/**
	 * Best-effort synchronous check against ifthenpay's own
	 * `/gateway/transaction/status/get` for a still-pending payment — called
	 * from resolve_outcome() so a real-time method (card, MB WAY, wallets)
	 * can resolve the instant the payer's tab lands back on this site,
	 * instead of only ever finding out once WebhookController::handle()'s
	 * async callback lands (which this tab, unlike the two-tab-poll design an
	 * earlier version of this class used, has no later chance to catch up
	 * with). Can't do anything for an offline method (Multibanco, Payshop):
	 * ifthenpay's own status for those stays 'pending' until it actually
	 * settles regardless of how often this is called, sometimes hours or
	 * days later — the webhook remains the only way those ever resolve, and
	 * is the only thing that needs to for them, since (unlike the pre-entry
	 * design this class used to have) the real entry already exists and
	 * isn't waiting on a live browser to do anything further.
	 *
	 * A no-op — never throws, never blocks the redirect that follows — if
	 * ifthenpay never returned a transaction id for this attempt (e.g. it
	 * predates this feature, or the redirect transient already expired) or
	 * the API call itself fails; the payer just sees the 'pending' outcome
	 * in that case, and the async webhook remains the fallback that
	 * eventually completes the payment server-side either way.
	 *
	 * @param object $payment  The still-'pending' wp_frm_payments row.
	 * @param int    $entry_id
	 *
	 * @return void
	 */
	private static function maybe_sync_payment_status( $payment, $entry_id ) {
		$stored         = get_transient( self::TRANSIENT_PREFIX . (int) $entry_id );
		$transaction_id = is_array( $stored ) && ! empty( $stored['transaction_id'] ) ? (string) $stored['transaction_id'] : '';

		if ( '' === $transaction_id ) {
			return;
		}

		try {
			$status = \Ifthenpay\Formidable\Api\IfthenpayClient::get_payment_status( $transaction_id );
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! is_array( $status ) ) {
			return;
		}

		$value = isset( $status['Status'] ) ? $status['Status'] : ( isset( $status['status'] ) ? $status['status'] : '' );

		if ( ! in_array( strtolower( (string) $value ), array( '1', 'paid', 'payed', 'completed', 'success' ), true ) ) {
			return;
		}

		\Ifthenpay\Formidable\Webhook\WebhookController::complete_pending_payment(
			$payment,
			isset( $status['Method'] ) ? (string) $status['Method'] : '',
			isset( $status['RequestId'] ) ? (string) $status['RequestId'] : $transaction_id
		);
	}

	/**
	 * Unconditional (Formidable-active-gated), not tied to compute_modal_data()
	 * like before: assets/js/frontend.js now has two independent jobs — show
	 * the outcome popup on a return trip (query-string-driven, same as
	 * before) AND hand off to the pre-opened payment tab on the *original*
	 * submit page, which has none of the return-trip query args yet. Both
	 * self-gate against the DOM (`#iftp-frm-modal` /
	 * `.frm-show-form[data-iftp-payment]`), so this is a cheap no-op script on
	 * any page without either.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_frontend_assets() {
		if ( ! class_exists( 'FrmForm' ) ) {
			return;
		}

		// See Admin\SettingsField::asset_version()'s own docblock — filemtime,
		// not the fixed IFTP_FRM_VERSION release string, so an actual edit to
		// either file always busts the browser's cache.
		wp_enqueue_style( 'ifthenpay-frm-frontend', IFTP_FRM_URL . 'assets/css/frontend.css', array(), \Ifthenpay\Formidable\Admin\SettingsField::asset_version( 'assets/css/frontend.css' ) );
		wp_enqueue_script( 'ifthenpay-frm-frontend', IFTP_FRM_URL . 'assets/js/frontend.js', array( 'jquery' ), \Ifthenpay\Formidable\Admin\SettingsField::asset_version( 'assets/js/frontend.js' ), true );

		wp_localize_script(
			'ifthenpay-frm-frontend',
			'iftpFrmFlow',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'logoUrl' => IFTP_FRM_URL . 'assets/img/logo_ifthenpay_white.svg',
				'i18n'    => array(
					'continueInOtherTab' => __( 'Continue in the other tab — you can close this one.', 'ifthenpay-payments-for-formidable' ),
					'poweredBy'          => __( 'Powered by', 'ifthenpay-payments-for-formidable' ),
				),
			)
		);
	}

	/**
	 * Prints the popup markup just before `</body>` — used instead of an
	 * `the_content` filter so it works on every front-end template
	 * (archives, non-loop block templates, etc.), not just a singular post's
	 * main content.
	 *
	 * @return void
	 */
	public static function maybe_render_modal() {
		$data = self::get_modal_data();

		if ( ! $data ) {
			return;
		}

		echo self::build_modal_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built (and escaped/sanitized piece by piece) in build_modal_html().
	}

	/**
	 * Memoized: both hooks above need this and it's expensive (DB lookups) —
	 * no reason to run it twice in the same request.
	 *
	 * @return array{status:string, claimed:string, message:string, form:object|null, open_url:string}|null
	 */
	private static function get_modal_data() {
		if ( ! self::$modal_data_computed ) {
			self::$modal_data          = self::compute_modal_data();
			self::$modal_data_computed = true;
		}

		return self::$modal_data;
	}

	/**
	 * Re-derives the outcome from the payment's own current DB status rather
	 * than trusting `ifthenpay_notice` outright, so a guessed/tampered entry
	 * id can't paint a false popup for someone else's payment.
	 *
	 * This is the direct return-trip page load — the tab that actually went
	 * to ifthenpay and back, which now stays put and shows this instead of
	 * trying to close itself (see this class's own docblock). The only tab
	 * that ever renders this for a given entry, so `open_url` carries
	 * open_new_tab_url()'s real value here without risking a duplicate
	 * `window.open()` anywhere else.
	 *
	 * @return array{status:string, claimed:string, message:string, form:object|null, open_url:string}|null
	 */
	private static function compute_modal_data() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display hint, re-verified against the DB below.
		if ( empty( $_GET['ifthenpay_notice'] ) || empty( $_GET['ifthenpay_entry'] ) ) {
			return null;
		}

		$claimed  = sanitize_key( wp_unslash( $_GET['ifthenpay_notice'] ) );
		$entry_id = absint( wp_unslash( $_GET['ifthenpay_entry'] ) );
		$token    = isset( $_GET['ifthenpay_token'] ) ? sanitize_text_field( wp_unslash( $_GET['ifthenpay_token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $entry_id || ! class_exists( 'FrmTransLitePayment' ) ) {
			return null;
		}

		$payment = ( new \FrmTransLitePayment() )->get_one_by( $entry_id, 'item_id' );

		if ( ! $payment || ! self::claim_matches_payment( $claimed, $payment->status ) ) {
			return null;
		}

		$form    = self::get_entry_form( $entry_id );
		$message = self::resolve_message( $claimed, $entry_id, $token, $form );

		if ( ! $message ) {
			return null;
		}

		return array(
			'status'   => in_array( $claimed, array( 'failed', 'canceled' ), true ) ? 'error' : 'success',
			'claimed'  => $claimed,
			'message'  => $message,
			'form'     => $form,
			'open_url' => self::open_new_tab_url( $claimed, self::get_context( $entry_id ) ),
		);
	}

	/**
	 * @param string $claimed        The `ifthenpay_notice` query value.
	 * @param string $payment_status The payment row's actual current status.
	 *
	 * @return bool
	 */
	private static function claim_matches_payment( $claimed, $payment_status ) {
		if ( 'success' === $claimed ) {
			return 'complete' === $payment_status;
		}

		if ( in_array( $claimed, array( 'failed', 'canceled' ), true ) ) {
			// Both cancel and error land on the same 'failed' DB status —
			// see resolve_outcome() — the distinct wording is cosmetic only.
			return 'failed' === $payment_status;
		}

		if ( 'pending' === $claimed ) {
			return 'pending' === $payment_status;
		}

		return false;
	}

	/**
	 * Builds the final popup message HTML: for a success, the form's own
	 * native "On Submit → Show Message" text (captured pre-payment by
	 * `IfthenpayGateway::capture_real_success_info()`, carried here via the
	 * context transient) ONLY while ifthenpay's own Payment Received setting
	 * is still left on its default "Show Message" mode — the moment a
	 * merchant explicitly picks "Open in a New Tab" (the only other mode that
	 * ever reaches this popup; "Redirect to URL" never shows a message at
	 * all), this plugin's own configured message is what counts instead, not
	 * whatever the native form happens to say — that's the whole point of
	 * giving that mode its own editable message field right next to the URL
	 * one (settings-tab.php). Pending (and Success again once neither of the
	 * above applies) falls back to this plugin's own configurable message
	 * (`SettingsRepository`), or its built-in default text when that setting
	 * is empty. Canceled/Failed are never configurable — they always show a
	 * fixed, hardcoded message.
	 *
	 * Formidable field shortcodes (e.g. `[21]`) are only ever resolved
	 * against the real entry when `$token` matches the one-time secret
	 * `IfthenpayGateway::trigger()` minted for this entry — otherwise the
	 * message is shown as plain, unprocessed text. Entry ids are small
	 * sequential ints and the query string is otherwise unauthenticated, so
	 * without that check anyone could read another payer's submitted field
	 * values (or another payer's personalized message) by guessing
	 * `?ifthenpay_notice=<x>&ifthenpay_entry=<id>` directly.
	 *
	 * @param string      $claimed
	 * @param int         $entry_id
	 * @param string      $token
	 * @param object|null $form
	 *
	 * @return string
	 */
	private static function resolve_message( $claimed, $entry_id, $token, $form ) {
		$context      = self::get_context( $entry_id );
		$success_info = isset( $context['success_info'] ) ? $context['success_info'] : array();
		$stored_token = isset( $context['token'] ) ? $context['token'] : '';
		$token_ok     = '' !== $stored_token && hash_equals( $stored_token, $token );

		// Deliberately never deleted here, even on a verified read: the payer
		// reloading this same return-trip page should still resolve the real
		// message, not silently fall back to the generic configured text.
		// Relying on its own short TTL (self::TRANSIENT_TTL) for cleanup still
		// fully closes the concern an explicit delete would otherwise guard
		// against (an attacker without a valid token burning another payer's
		// context): deletion was already conditional on $token_ok, so an
		// attacker who never has a valid token could never trigger it either
		// way — not deleting on a *valid* read doesn't reopen that.
		$settings = new SettingsRepository();

		if ( 'success' === $claimed && $token_ok && 'message' === $settings->get_success_mode() && 'message' === ( $success_info['type'] ?? '' ) && isset( $success_info['message'] ) ) {
			return self::render_message( $success_info['message'], $form, $entry_id, true );
		}

		$defaults = array(
			'success'  => $settings->get_success_message(),
			'pending'  => $settings->get_pending_message(),
			// Fixed, non-configurable text — Canceled/Failed have no message
			// setting of their own (see this method's own docblock).
			'canceled' => __( 'You canceled the payment before it was completed.', 'ifthenpay-payments-for-formidable' ),
			'failed'   => __( 'Your payment could not be completed. Please try again.', 'ifthenpay-payments-for-formidable' ),
		);

		if ( ! isset( $defaults[ $claimed ] ) ) {
			return '';
		}

		return self::render_message( $defaults[ $claimed ], $form, $entry_id, $token_ok );
	}

	/**
	 * @param string      $message
	 * @param object|null $form
	 * @param int         $entry_id
	 * @param bool        $resolve_shortcodes
	 *
	 * @return string
	 */
	private static function render_message( $message, $form, $entry_id, $resolve_shortcodes ) {
		if ( ! $resolve_shortcodes || ! $form || ! class_exists( 'FrmFormsHelper' ) ) {
			return '<p>' . esc_html( $message ) . '</p>';
		}

		return \FrmFormsHelper::get_success_message(
			array(
				'message'  => $message,
				'form'     => $form,
				'entry_id' => $entry_id,
				'class'    => 'iftp-frm-modal__frm-message',
			)
		);
	}

	/**
	 * @param int $entry_id
	 *
	 * @return object|null
	 */
	private static function get_entry_form( $entry_id ) {
		if ( ! class_exists( 'FrmEntry' ) || ! class_exists( 'FrmForm' ) ) {
			return null;
		}

		$entry = \FrmEntry::getOne( $entry_id );

		if ( ! $entry || ! isset( $entry->form_id ) ) {
			return null;
		}

		$form = \FrmForm::getOne( $entry->form_id );

		return $form ? $form : null;
	}

	/**
	 * @param array{status:string, claimed:string, message:string, form:object|null, open_url:string} $data
	 *
	 * @return string
	 */
	private static function build_modal_html( array $data ) {
		$colors   = self::build_color_style( self::get_style_vars( $data['form'] ) );
		$open_url = isset( $data['open_url'] ) ? (string) $data['open_url'] : '';

		ob_start();
		?>
		<div id="iftp-frm-modal" class="iftp-frm-modal iftp-frm-modal--<?php echo esc_attr( $data['status'] ); ?>" style="<?php echo esc_attr( $colors ); ?>" role="dialog" aria-modal="true" aria-labelledby="iftp-frm-modal-title" <?php echo $open_url ? 'data-iftp-open-url="' . esc_url( $open_url ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() already applied above; the surrounding attribute markup itself is a static literal. ?> hidden>
			<div class="iftp-frm-modal__backdrop" data-iftp-close></div>
			<div class="iftp-frm-modal__box">
				<button type="button" class="iftp-frm-modal__close" data-iftp-close aria-label="<?php esc_attr_e( 'Close', 'ifthenpay-payments-for-formidable' ); ?>">&times;</button>
				<div class="iftp-frm-modal__icon"><?php echo self::status_icon( $data['status'], $data['claimed'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG, no dynamic data. ?></div>
				<div id="iftp-frm-modal-title" class="iftp-frm-modal__message"><?php echo $data['message']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built (and escaped/sanitized) in resolve_message()/render_message(). ?></div>
				<button type="button" class="iftp-frm-modal__ok" data-iftp-close><?php esc_html_e( 'OK', 'ifthenpay-payments-for-formidable' ); ?></button>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Public entry point for another class (PaymentSelector) that wants the
	 * same "look like it belongs to this form" inline CSS custom properties
	 * the popup uses — see build_color_style()'s own docblock for the exact
	 * property list.
	 *
	 * @param object|null $form
	 *
	 * @return string Inline `style` attribute content (CSS custom properties only).
	 */
	public static function color_style_for_form( $form ) {
		return self::build_color_style( self::get_style_vars( $form ) );
	}

	/**
	 * Pulls the merchant's own form-style colors (Formidable's Style/Theme
	 * Builder — the same colors a merchant already sees on the form itself:
	 * success/error message colors, the submit button's accent color, corner
	 * radius) so the popup looks like it belongs to their form instead of a
	 * generic plugin dialog. Falls back to Formidable's own stock defaults
	 * (from `FrmStyle::get_defaults()`) when the form/style can't be resolved.
	 *
	 * @param object|null $form
	 *
	 * @return array<string, string>
	 */
	private static function get_style_vars( $form ) {
		if ( ! $form || ! class_exists( 'FrmStylesController' ) ) {
			return array();
		}

		$style = \FrmStylesController::get_form_style( $form );

		if ( ! $style || empty( $style->post_content ) || ! is_array( $style->post_content ) ) {
			return array();
		}

		return $style->post_content;
	}

	/**
	 * @param array<string, string> $vars
	 *
	 * @return string Inline `style` attribute content (CSS custom properties only).
	 */
	private static function build_color_style( array $vars ) {
		$props = array(
			'--iftp-success-bg'     => self::sanitize_hex_color( $vars['success_bg_color'] ?? '', 'dff0d8' ),
			'--iftp-success-border' => self::sanitize_hex_color( $vars['success_border_color'] ?? '', 'd6e9c6' ),
			'--iftp-success-text'   => self::sanitize_hex_color( $vars['success_text_color'] ?? '', '468847' ),
			'--iftp-error-bg'       => self::sanitize_hex_color( $vars['error_bg'] ?? '', 'fee4e2' ),
			'--iftp-error-border'   => self::sanitize_hex_color( $vars['error_border'] ?? '', 'f5b8aa' ),
			'--iftp-error-text'     => self::sanitize_hex_color( $vars['error_text'] ?? '', 'f04438' ),
			'--iftp-accent'         => self::sanitize_hex_color( $vars['submit_bg_color'] ?? '', '4199fd' ),
			'--iftp-accent-hover'   => self::sanitize_hex_color( $vars['submit_hover_bg_color'] ?? '', '3680d3' ),
			'--iftp-accent-text'    => self::sanitize_hex_color( $vars['submit_text_color'] ?? '', 'ffffff' ),
		);

		$css = '';

		foreach ( $props as $name => $hex ) {
			$css .= $name . ':#' . $hex . ';';
		}

		$css .= '--iftp-radius:' . self::sanitize_css_length( $vars['border_radius'] ?? '', '8px' ) . ';';

		return $css;
	}

	/**
	 * @param string $value
	 * @param string $fallback Already-known-safe (no leading '#').
	 *
	 * @return string Hex digits only, no leading '#'.
	 */
	private static function sanitize_hex_color( $value, $fallback ) {
		$value = ltrim( (string) $value, '#' );
		return preg_match( '/^[0-9a-fA-F]{3,8}$/', $value ) ? $value : $fallback;
	}

	/**
	 * @param string $value
	 * @param string $fallback Already-known-safe.
	 *
	 * @return string
	 */
	private static function sanitize_css_length( $value, $fallback ) {
		return preg_match( '/^\d{1,3}(?:\.\d+)?(?:px|em|rem|%)$/', (string) $value ) ? $value : $fallback;
	}

	/**
	 * Static inline SVGs — no dynamic data, colored via `currentColor` so
	 * they pick up `--iftp-success-text`/`--iftp-error-text` automatically.
	 *
	 * @param string $status  From compute_modal_data(): 'success' or 'error'.
	 * @param string $claimed The raw `ifthenpay_notice` value — 'pending' gets its own icon.
	 *
	 * @return string
	 */
	private static function status_icon( $status, $claimed ) {
		if ( 'pending' === $claimed ) {
			return '<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
		}

		if ( 'error' === $status ) {
			return '<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>';
		}

		return '<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12l2.5 2.5L16 9"/></svg>';
	}
}
