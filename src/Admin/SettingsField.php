<?php
/**
 * @package Ifthenpay\Formidable
 */

namespace Ifthenpay\Formidable\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

use Ifthenpay\Formidable\Ajax\Controller;
use Ifthenpay\Formidable\Api\IfthenpayClient;
use Ifthenpay\Formidable\Settings\SettingsRepository;
use Ifthenpay\Formidable\Webhook\WebhookController;

/**
 * Renders two separate ifthenpay admin surfaces on Formidable's Global
 * Settings screen (`page=formidable-settings`):
 *  - The account/gateway settings (Backoffice Key, Gateway Key, Methods,
 *    Expiry Days) as a sibling "pill" tab inside Formidable's own built-in
 *    Payments section, next to PayPal/Stripe/Square.
 *  - "Confirmation Type" (Payment Received/Pending message+mode+URL) as its
 *    own top-level tab, sibling to General/Permissions/Payments/etc — see
 *    `register_confirmation_settings_tab()`.
 *
 * Formidable's `FrmSettingsController::remove_payments_sections()` pulls
 * PayPal/Stripe/Square/Authorize.Net out of the generic `frm_add_settings_section`
 * filter and into its own hardcoded nested tab strip (`classes/views/frm-settings/payments.php`,
 * the `.frm-long-icon-buttons` pill row). That allowlist is hardcoded with no
 * filter of its own, so a 3rd-party gateway cannot register into it from PHP —
 * which is why the gateway settings use a different mechanism than the
 * Confirmation Type tab:
 *  - Hooks `frm_payments_settings_form` (fires once, right after Formidable's
 *    own Payments panel markup — pill row + PayPal/Stripe/Square panels — has
 *    already been output, still inside the shared `#payments_settings` wrapper)
 *    to output our own hidden panel `<div id="frm_ifthenpay_settings_section">`
 *    as a sibling of theirs.
 *  - Ships JS (`assets/js/admin.js`) that inserts a matching radio+label pill
 *    into the existing `.frm-long-icon-buttons` row at runtime. Formidable's
 *    own tab-switching (`data-frmshow`/`data-frmhide`) is generic event
 *    delegation on any `input[data-frmshow]`, not hardcoded to the 4 built-in
 *    gateways, so once our pill/panel exist with matching attributes, the
 *    native behavior picks them up with no extra JS of our own for the actual
 *    switching — only for the one-time DOM insertion.
 *  - Confirmation Type, by contrast, is a completely ordinary top-level
 *    section registered through the generic `frm_add_settings_section`
 *    filter (see `register_confirmation_settings_tab()`) — Formidable's own
 *    `classes/views/frm-settings/form.php` renders it, and its own
 *    `classes/views/frm-settings/tabs.php` lists it in the sidebar, with no
 *    custom JS/markup of ours needed for either.
 *
 * Both tabs' fields are persisted on save via the unconditional
 * `frm_update_settings` action, which fires from `FrmSettings::update()` for
 * the whole settings form regardless of which section(s) registered fields —
 * the exact same hook PayPal/Stripe/Square use for their own `process_form()`.
 * All of Formidable's Global Settings tabs share one `<form>`/one Save button
 * (`classes/views/frm-settings/form.php`), so a single `process_form()` here
 * covers both.
 *
 * The Backoffice Key is intentionally never part of this form's fields —
 * see blueprint §8.4 (write-only credential, connect/disconnect is AJAX-only).
 */
class SettingsField {

	/**
	 * @return void
	 */
	public static function boot() {
		add_action( 'frm_payments_settings_form', array( self::class, 'render_payments_pill_panel' ) );
		add_filter( 'frm_add_settings_section', array( self::class, 'register_confirmation_settings_tab' ) );
		add_action( 'frm_update_settings', array( self::class, 'process_form' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue_assets' ) );
		add_action( 'frm_pay_ifthenpay_sidebar', array( self::class, 'hide_refund_link' ) );
	}

	/**
	 * `stripe/views/payments/sidebar_actions.php` unconditionally renders a
	 * "Payment: Refund" action link for every completed payment regardless of
	 * gateway — there's no filter around that specific block to intercept
	 * server-side, only the generic-looking `misc-pub-section` markup shown in
	 * the class docblock above. ifthenpay Pay by Link has no refund flow
	 * implemented (no API call this plugin can make would actually refund
	 * anything), so that link would just error out if clicked.
	 *
	 * That template calls `do_action( 'frm_pay_' . $payment->paysys . '_sidebar', $payment )`
	 * immediately after the refund block, giving us a gateway-scoped hook
	 * (`frm_pay_ifthenpay_sidebar` — fires only for ifthenpay payments, never
	 * for Stripe/Square/PayPal ones) at a known DOM position: the refund
	 * `<div class="misc-pub-section">` is always this hook's immediate
	 * preceding sibling. Hiding it via that relative position, rather than a
	 * broader selector, is what keeps this scoped to ifthenpay only.
	 *
	 * @return void
	 */
	public static function hide_refund_link() {
		?>
		<script>
		( function () {
			var prev = document.currentScript && document.currentScript.previousElementSibling;
			if ( prev && prev.classList.contains( 'misc-pub-section' ) ) {
				prev.style.display = 'none';
			}
		} )();
		</script>
		<?php
	}

	/**
	 * @return void
	 */
	public static function maybe_enqueue_assets() {
		if ( ! self::on_settings_page() ) {
			return;
		}

		wp_enqueue_style( 'ifthenpay-frm-admin', IFTP_FRM_URL . 'assets/css/admin.css', array(), IFTP_FRM_VERSION );
		wp_enqueue_script( 'ifthenpay-frm-admin', IFTP_FRM_URL . 'assets/js/admin.js', array( 'jquery' ), IFTP_FRM_VERSION, true );

		$settings = new SettingsRepository();

		wp_localize_script(
			'ifthenpay-frm-admin',
			'iftpFrmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Controller::NONCE_ACTION ),
				'logoUrl' => IFTP_FRM_URL . 'assets/img/logo-color.svg',
				'i18n'    => array(
					'connecting'    => __( 'Connecting…', 'ifthenpay-payments-for-formidable' ),
					'requesting'    => __( 'Requesting…', 'ifthenpay-payments-for-formidable' ),
					'requested'     => __( 'Requested', 'ifthenpay-payments-for-formidable' ),
					'loadingTable'  => __( 'Loading payment methods…', 'ifthenpay-payments-for-formidable' ),
					'genericError'  => __( 'Something went wrong. Please try again.', 'ifthenpay-payments-for-formidable' ),
					'ifthenpay'     => __( 'ifthenpay', 'ifthenpay-payments-for-formidable' ),
					'saving'        => __( 'Saving…', 'ifthenpay-payments-for-formidable' ),
				),
				'hasBackofficeKey' => $settings->has_backoffice_key(),
			)
		);
	}

	/**
	 * Global Settings (`page=formidable-settings`, the Payments-tab pill —
	 * see `render_payments_pill_panel()`) and every per-form editor screen
	 * (`page=formidable`, where the "Collect Payment" action's own gateway
	 * tab strip and Currency field live — see `assets/js/admin.js`'s
	 * `initFormActionEnhancements()`). Both are cheap no-ops via their own
	 * defensive element checks when the relevant markup isn't on the page.
	 *
	 * @return bool
	 */
	private static function on_settings_page() {
		return in_array( \FrmAppHelper::simple_get( 'page' ), array( 'formidable-settings', 'formidable' ), true );
	}

	/**
	 * Prints ifthenpay's hidden panel as a sibling of Formidable's own
	 * PayPal/Stripe/Square panels inside `#payments_settings`. Starts hidden
	 * (`frm_hidden`) like every inactive panel there — the JS-inserted pill's
	 * `data-frmshow`/`data-frmhide` (or the direct-link handling in
	 * `assets/js/admin.js` for `?t=ifthenpay_settings`) is what reveals it.
	 *
	 * @return void
	 */
	public static function render_payments_pill_panel() {
		$settings    = new SettingsRepository();
		$connected   = $settings->has_backoffice_key();
		$gateway_key = $settings->get_gateway_key();

		// Live-fetch the Gateway Key list and the methods table (labels,
		// accounts, icons) straight from the ifthenpay API on every page
		// render instead of trusting the last-saved snapshot, so the tab
		// never drifts from what's actually configured on the account —
		// see Ajax\Controller::fetch_live_gateway_state() for the fallback
		// behavior on a transient API failure.
		$live         = $connected
			? Controller::fetch_live_gateway_state( $settings )
			: array(
				'gateway_keys' => array(),
				'methods'      => array(),
			);
		$gateway_keys = $live['gateway_keys'];
		$methods      = $live['methods'];
		?>
		<div id="frm_ifthenpay_settings_section" class="frm_payments_section frm_hidden" role="tabpanel">
			<?php include IFTP_FRM_DIR . 'src/Admin/views/settings-tab.php'; ?>
		</div>
		<?php
	}

	/**
	 * Adds "Confirmation Type" as its own top-level Global Settings tab,
	 * sibling to General/Permissions/Payments/etc — the generic extension
	 * point third-party plugins use for a genuinely new section (unlike the
	 * Payments-pill mechanism `render_payments_pill_panel()` uses, which only
	 * exists because Formidable's own Payments tab has no filter of its own —
	 * see this class's own docblock).
	 *
	 * @param array<array> $sections
	 *
	 * @return array<array>
	 */
	public static function register_confirmation_settings_tab( $sections ) {
		$sections['ifthenpay_confirmation'] = array(
			'class'    => self::class,
			'function' => 'render_confirmation_settings_tab',
			'name'     => __( 'Confirmation Type', 'ifthenpay-payments-for-formidable' ),
			'icon'     => 'frmfont frm_chat_bubbles_icon',
		);

		return $sections;
	}

	/**
	 * @return void
	 */
	public static function render_confirmation_settings_tab() {
		$settings = new SettingsRepository();
		include IFTP_FRM_DIR . 'src/Admin/views/confirmation-settings-tab.php';
	}

	/**
	 * Renders just the `<tr>` rows of the methods table — shared between the
	 * initial page render and the AJAX response from
	 * `Ajax\Controller::select_gateway_key()` so the markup never drifts
	 * between the two call sites (blueprint §8.2, "no Save-then-refresh loop").
	 *
	 * @param array<int, array<string, mixed>> $methods
	 * @param string                            $gateway_key
	 * @param string                            $default_method
	 *
	 * @return string
	 */
	public static function render_methods_table_rows( array $methods, $gateway_key, $default_method ) {
		ob_start();
		include IFTP_FRM_DIR . 'src/Admin/views/methods-table-rows.php';
		return (string) ob_get_clean();
	}

	/**
	 * Persists the description, expiry days, per-method "enabled" flags, and
	 * the default method — everything on this tab EXCEPT the Backoffice Key
	 * (AJAX-only, see class docblock) and the Gateway Key + methods snapshot
	 * (already saved by `Ajax\Controller::select_gateway_key()` the moment
	 * the merchant picked it — this form only toggles which already-fetched
	 * methods are enabled).
	 *
	 * @return void
	 */
	public static function process_form() {
		if ( ! isset( $_POST['frm_ifthenpay_settings_submitted'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by FrmSettingsController::process_form() before `frm_update_settings` fires.
		$settings = new SettingsRepository();

		if ( isset( $_POST['frm_ifthenpay_expiry_days'] ) ) {
			$settings->save_expiry_days( absint( wp_unslash( $_POST['frm_ifthenpay_expiry_days'] ) ) );
		}

		$enabled_entities = isset( $_POST['frm_ifthenpay_methods_enabled'] ) && is_array( $_POST['frm_ifthenpay_methods_enabled'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['frm_ifthenpay_methods_enabled'] ) )
			: array();

		$methods = $settings->get_methods();

		foreach ( $methods as &$method ) {
			$method['enabled'] = ! empty( $method['provisioned'] ) && in_array( strtoupper( $method['entity'] ), array_map( 'strtoupper', $enabled_entities ), true );
		}
		unset( $method );

		$settings->save_methods( $methods );

		$default_method = isset( $_POST['frm_ifthenpay_default_method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['frm_ifthenpay_default_method'] ) ) ) : '';

		$default_is_enabled = false;

		foreach ( $methods as $method ) {
			if ( strtoupper( $method['entity'] ) === $default_method && ! empty( $method['enabled'] ) ) {
				$default_is_enabled = true;
				break;
			}
		}

		$settings->save_default_method( $default_is_enabled ? $default_method : '' );

		self::save_popup_messages( $settings );
		self::save_outcome_redirects( $settings );
		self::maybe_activate_callback( $settings );
	}

	/**
	 * Persists the Payment Received / Payment Pending popup message textareas
	 * (Settings\SettingsRepository's own setters sanitize each value) — every
	 * one is optional, an empty submission just means "keep using the
	 * built-in default text". Payment Canceled/Failed have no message setting
	 * of their own — they always show a fixed, hardcoded message (see
	 * RedirectHandler::resolve_message()). Fields live on the "Confirmation
	 * Type" tab (`confirmation-settings-tab.php`), but this always runs on
	 * every settings save regardless of which tab was visually active — see
	 * this class's own docblock.
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return void
	 */
	private static function save_popup_messages( SettingsRepository $settings ) {
		$fields = array(
			'frm_ifthenpay_msg_success' => 'save_success_message',
			'frm_ifthenpay_msg_pending' => 'save_pending_message',
		);

		foreach ( $fields as $field => $setter ) {
			if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by FrmSettingsController::process_form() before frm_update_settings fires.
				$settings->$setter( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- sanitized inside the setter.
			}
		}
	}

	/**
	 * Persists the Show Message / Redirect to URL / Open in a New Tab mode +
	 * target URL for Payment Received and Payment Pending. Payment Received's
	 * own mode is fallback-only: `SettingsRepository::get_success_mode()`'s
	 * own docblock and `RedirectHandler::success_mode_target()` are what
	 * actually enforce that a form's own native On Submit "Redirect to URL"
	 * action still wins over it — this method just persists whatever was
	 * submitted, same "every field optional" contract as
	 * save_popup_messages() above.
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return void
	 */
	private static function save_outcome_redirects( SettingsRepository $settings ) {
		$modes = array(
			'frm_ifthenpay_mode_success' => 'save_success_mode',
			'frm_ifthenpay_mode_pending' => 'save_pending_mode',
		);

		foreach ( $modes as $field => $setter ) {
			if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by FrmSettingsController::process_form() before frm_update_settings fires.
				$settings->$setter( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		$urls = array(
			'frm_ifthenpay_url_success' => 'save_success_redirect_url',
			'frm_ifthenpay_url_pending' => 'save_pending_redirect_url',
		);

		foreach ( $urls as $field => $setter ) {
			if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by FrmSettingsController::process_form() before frm_update_settings fires.
				$settings->$setter( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- sanitized (esc_url_raw) inside the setter.
			}
		}
	}

	/**
	 * Registers (or re-registers) the webhook callback with ifthenpay every
	 * time the settings screen is saved, as long as a Gateway Key is set —
	 * the same "every save" convention the GravityForms integration's
	 * `activate_callback()` and the Paid Memberships Pro blueprint's
	 * `activate_callback_for_gateway()` both use. Idempotent on ifthenpay's
	 * side, and best-effort here: a failure is not surfaced as a form error
	 * and never blocks the rest of the settings save.
	 *
	 * @param SettingsRepository $settings
	 *
	 * @return void
	 */
	private static function maybe_activate_callback( SettingsRepository $settings ) {
		$gateway_key = $settings->get_gateway_key();

		if ( '' === $gateway_key ) {
			return;
		}

		IfthenpayClient::activate_callback( $gateway_key, WebhookController::base_url() );
	}
}
