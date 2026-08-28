<?php
/**
 * Plugin Name:       ifthenpay | Payments for Formidable Forms
 * Plugin URI:        https://ifthenpay.com/
 * Description:       ifthenpay Pay by Link integration for Formidable Forms. Requires the free Formidable Forms plugin (with its bundled payments module).
 * Version:           1.0.0
 * Tested up to:      6.34
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Requires Plugins:  formidable
 * Author:            ifthenpay
 * Author URI:        https://ifthenpay.com/
 * License:           GPL v3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ifthenpay-payments-for-formidable
 *
 * @package Ifthenpay\Formidable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_FRM_VERSION', '1.0.0' );
define( 'IFTP_FRM_FILE', __FILE__ );
define( 'IFTP_FRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'IFTP_FRM_URL', plugin_dir_url( __FILE__ ) );
define( 'IFTP_FRM_SLUG', 'iftp_frm' );

// Rewrite-rule slug / query var for the server-to-server callback endpoint —
// same "iftp_{tag}_{version}" pattern as the FluentForms integration
// (IFTP_FF_CALLBACK_SLUG), instead of exposing admin-ajax.php to ifthenpay.
define( 'IFTP_FRM_CALLBACK_SLUG', 'iftp_frm_' . str_replace( '.', '_', IFTP_FRM_VERSION ) );

$ifthenpay_frm_dir      = plugin_dir_path( __FILE__ );
$ifthenpay_frm_autoload = $ifthenpay_frm_dir . 'vendor/autoload.php';

if ( file_exists( $ifthenpay_frm_autoload ) ) {
	require_once $ifthenpay_frm_autoload;
} else {
	// Composer's `vendor/` folder wasn't installed. Fall back to a small
	// PSR-4 autoloader for our own namespace so the plugin still works.
	spl_autoload_register(
		function ( $class ) use ( $ifthenpay_frm_dir ) {
			$prefix = 'Ifthenpay\\Formidable\\';

			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$file     = $ifthenpay_frm_dir . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

add_action( 'plugins_loaded', 'ifthenpay_frm_boot', 20 );

/**
 * Boot the plugin once every other plugin (including Formidable) has loaded.
 *
 * Deferred to priority 20 so Formidable's own `plugins_loaded` (priority 0)
 * boot, `load_formidable_forms()`, has already run and registered its
 * classes/autoloader before we check for them.
 *
 * @return void
 */
function ifthenpay_frm_boot() {
	// Formidable Forms itself isn't active at all. Autoload must stay enabled
	// here — Formidable defines its classes lazily via its own autoloader, so
	// class_exists( ..., false ) would always report them as missing.
	if ( ! class_exists( 'FrmFormAction' ) ) {
		add_action( 'admin_notices', 'ifthenpay_frm_missing_formidable_notice' );
		return;
	}

	// Formidable is active, but its free "Lite" payments module isn't loaded
	// (e.g. the paid Formidable "Payments" add-on has fully replaced it).
	// See blueprint §7.2 — supporting that separate registry is out of scope.
	if ( ! class_exists( 'FrmTransLiteActionsController' ) || ! class_exists( 'FrmTransLiteAppHelper' ) ) {
		add_action( 'admin_notices', 'ifthenpay_frm_missing_payments_module_notice' );
		return;
	}

	// The one class Formidable calls by a dynamically-built global class
	// name string (see blueprint §3a) must exist before any form can be
	// submitted. It is never left to an autoloader.
	require_once IFTP_FRM_DIR . 'classes/class-frm-ifthenpay-actions-controller.php';

	FrmIfthenpayActionsController::boot();

	\Ifthenpay\Formidable\Gateway\IfthenpayGateway::boot();
	\Ifthenpay\Formidable\Frontend\RedirectHandler::boot();
	\Ifthenpay\Formidable\Frontend\PaymentSelector::boot();
	\Ifthenpay\Formidable\Ajax\Controller::boot();
	\Ifthenpay\Formidable\Webhook\WebhookController::boot();
	\Ifthenpay\Formidable\Admin\SettingsField::boot();
}

/**
 * @return void
 */
function ifthenpay_frm_missing_formidable_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'ifthenpay Payments for Formidable Forms requires the free Formidable Forms plugin to be installed and active.', 'ifthenpay-payments-for-formidable' ) .
		'</p></div>';
}

/**
 * @return void
 */
function ifthenpay_frm_missing_payments_module_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'ifthenpay Payments for Formidable Forms requires Formidable\'s free bundled payments module. It could not be found (the paid Formidable "Payments" add-on, if active, is not currently supported by this integration).', 'ifthenpay-payments-for-formidable' ) .
		'</p></div>';
}

register_activation_hook( __FILE__, 'ifthenpay_frm_activate' );

/**
 * @return void
 */
function ifthenpay_frm_activate() {
	// Nothing to install: settings default lazily via get_option() fallbacks
	// and the shared `wp_frm_payments` table is owned/created by Formidable's
	// own Lite payments module, not by this plugin.
	//
	// The callback rewrite rule itself is (re)registered on every request in
	// WebhookController::boot() — this one-time flush just makes the URL work
	// immediately on activation, without the merchant having to visit
	// Settings → Permalinks and re-save first.
	add_rewrite_rule( IFTP_FRM_CALLBACK_SLUG . '/?$', 'index.php?' . IFTP_FRM_CALLBACK_SLUG . '=1', 'top' );
	flush_rewrite_rules();
}
