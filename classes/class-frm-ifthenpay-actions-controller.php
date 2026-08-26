<?php
/**
 * Global, non-namespaced bridge class.
 *
 * Formidable Lite's `FrmTransLiteActionsController::trigger_action()`
 * resolves the gateway controller by building a *global* class-name string
 * at runtime — `'Frm' . $class_name . 'ActionsController'` — and calling it
 * directly, with no `class_exists()` guard beforehand (verified by reading
 * `stripe/controllers/FrmTransLiteActionsController.php`). A namespaced
 * class would never be found by that lookup, and the missing-class call
 * would be a fatal error, not a graceful failure.
 *
 * This is why this one class is deliberately global and `require_once`'d
 * directly from the plugin bootstrap rather than left to any autoloader.
 * Every other class in this plugin is namespaced under `Ifthenpay\Formidable\`
 * and PSR-4 autoloaded — this file is the only intentional exception. See
 * the project blueprint §3a for the full trace.
 *
 * @package Ifthenpay\Formidable
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'You are not allowed to call this page directly.' );
}

class FrmIfthenpayActionsController extends FrmTransLiteActionsController {

	/**
	 * @return void
	 */
	public static function boot() {
		add_filter( 'frm_payment_gateways', array( self::class, 'add_gateway' ) );
	}

	/**
	 * Registers ifthenpay into Formidable Lite's shared gateway registry
	 * (`FrmTransLiteAppHelper::get_gateways()`), the same registry Stripe,
	 * Square, and PayPal register into.
	 *
	 * `'recurring' => false` hides the ifthenpay tab whenever the payment
	 * action's `type` is `'recurring'` — Formidable's own `gateway-buttons.php`
	 * view already does this automatically for any gateway that declares it
	 * doesn't support recurring, no extra code needed here (see blueprint §3a).
	 *
	 * `'include' => array()` — mirrors the key Stripe/Square/PayPal set to
	 * `['billing_first_name', 'billing_last_name', 'credit_card', 'billing_address']`,
	 * but verified by reading the whole Lite plugin: nothing in this codebase
	 * actually consumes `$gateway['include']` (only the three built-in
	 * gateways set it — it's presumably read by the paid "Formidable
	 * Payments" add-on, not this free Lite version). Kept at `[]` for
	 * accuracy/documentation even though it has no effect either way —
	 * ifthenpay's hosted Pay by Link page collects whatever payer details it
	 * needs itself, no Formidable field mapping is required for this gateway.
	 *
	 * @param array $gateways
	 *
	 * @return array
	 */
	public static function add_gateway( $gateways ) {
		$gateways['ifthenpay'] = array(
			'label'      => 'ifthenpay',
			'user_label' => __( 'Payment', 'ifthenpay-payments-for-formidable' ),
			'class'      => 'Ifthenpay',
			'recurring'  => false,
			'include'    => array(),
		);

		return $gateways;
	}

	/**
	 * Called synchronously during entry creation whenever
	 * `$action->post_content['gateway'] === 'ifthenpay'`. Kept as a thin
	 * shim so the one class Formidable must find by its exact dynamic name
	 * stays trivial to reason about; all real logic lives in the namespaced,
	 * PSR-4 autoloaded `Ifthenpay\Formidable\Gateway\IfthenpayGateway`.
	 *
	 * @param WP_Post  $action
	 * @param stdClass $entry
	 * @param mixed    $form
	 *
	 * @return array
	 */
	public static function trigger_gateway( $action, $entry, $form ) {
		return \Ifthenpay\Formidable\Gateway\IfthenpayGateway::trigger( $action, $entry, $form );
	}
}
