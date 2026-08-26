/* global jQuery, iftpFrmAdmin */
( function ( $ ) {
	'use strict';

	function init() {
		$( document ).on( 'click', '#iftp-frm-btn-connect', onConnect );
		$( document ).on( 'click', '#iftp-frm-btn-disconnect', onDisconnect );
		$( document ).on( 'change', '#iftp-frm-gateway-key-select', onSelectGatewayKey );
		$( document ).on( 'click', '.iftp-frm-activate-btn', onActivateMethod );
		$( document ).on( 'change', '.iftp-frm-method-enabled', onMethodEnabledChanged );
		$( document ).on( 'change', '.iftp-frm-method-star-input', onDefaultMethodChanged );
		$( document ).on( 'submit', 'form.frm_settings_form', onGlobalSettingsSubmit );

		// Initialize star visibility to match each row's current "Enabled"
		// state on first render (server-rendered rows already do this via
		// PHP, but the AJAX-rendered replacement table needs the same pass).
		$( '.iftp-frm-method-row' ).each( function () {
			syncStarForRow( $( this ) );
		} );

		injectPaymentsPill();
		initFormActionEnhancements();
	}

	// -------------------------------------------------------------------
	// Payments-tab pill injection.
	//
	// Formidable's own Payments settings tab hardcodes which gateways get a
	// pill in its `.frm-long-icon-buttons` row (PayPal/Stripe/Square/Authorize.Net)
	// with no filter for a 3rd party to add to that list from PHP. Our own
	// panel is already rendered server-side as a sibling of theirs (PHP:
	// SettingsField::render_payments_pill_panel(), hooked on
	// `frm_payments_settings_form`) — this only needs to insert the pill
	// button itself. Formidable's tab-switching is generic delegated event
	// handling on any `input[data-frmshow]`/`input[data-frmhide]` (not
	// hardcoded to the 4 built-in gateways), so once inserted, clicking our
	// pill shows/hides panels using Formidable's own native behavior — no
	// extra JS of ours is involved in the actual switching.
	// -------------------------------------------------------------------

	function injectPaymentsPill() {
		var $tablist = $( '#payments_settings .frm-long-icon-buttons[role="tablist"]' );
		var $ourPanel = $( '#frm_ifthenpay_settings_section' );

		// Defensive — if Formidable's markup ever changes shape, just skip
		// the injection instead of throwing on a missing container.
		if ( ! $tablist.length || ! $ourPanel.length ) {
			return;
		}

		var otherPanelSelectors = $tablist.find( 'input[type="radio"][data-frmshow]' )
			.map( function () {
				return $( this ).data( 'frmshow' );
			} )
			.get();

		// Existing pills also need to hide ours when picked.
		$tablist.find( 'input[type="radio"][data-frmhide]' ).each( function () {
			var $input = $( this );
			var hide = $input.attr( 'data-frmhide' ) || '';
			if ( -1 === hide.indexOf( '#frm_ifthenpay_settings_section' ) ) {
				$input.attr( 'data-frmhide', hide ? hide + ',#frm_ifthenpay_settings_section' : '#frm_ifthenpay_settings_section' );
			}
		} );

		var $pillInput = $( '<input>', {
			id: 'frm_toggle_ifthenpay_settings',
			type: 'radio',
			name: 'frm_payment_section',
			value: 'ifthenpay'
		} ).attr( 'data-frmshow', '#frm_ifthenpay_settings_section' )
			.attr( 'data-frmhide', otherPanelSelectors.join( ',' ) );

		var $pillLabel = $( '<label>', {
			'for': 'frm_toggle_ifthenpay_settings',
			'class': 'frm_payment_settings_tab iftp-frm-payment-tab',
			tabindex: '0',
			role: 'tab',
			'aria-selected': 'false'
		} ).append(
			$( '<img>', { src: iftpFrmAdmin.logoUrl, alt: iftpFrmAdmin.i18n.ifthenpay, 'class': 'iftp-frm-pill-logo' } ),
			$( '<span>', { 'class': 'screen-reader-text', text: iftpFrmAdmin.i18n.ifthenpay } )
		);

		$tablist.append( $pillInput, $pillLabel );

		// Direct link to our tab (e.g. bookmarked, or linked from elsewhere)
		// — Formidable's own `t=..._settings` remap doesn't know our key, so
		// it silently falls back to its default (the Payments tab shows, but
		// with Stripe active). Detect it ourselves and switch both the
		// top-level "Payments" tab and our pill into view.
		var params = new URLSearchParams( window.location.search );
		var wantsIfthenpay = 'ifthenpay_settings' === params.get( 't' ) || 'ifthenpay' === params.get( 't' );

		if ( wantsIfthenpay ) {
			activatePaymentsTopLevelTab();
			$pillInput.prop( 'checked', true );
			$tablist.find( 'label[role="tab"]' ).attr( 'aria-selected', 'false' );
			$pillLabel.attr( 'aria-selected', 'true' );
			$( otherPanelSelectors.join( ',' ) ).addClass( 'frm_hidden' );
			$ourPanel.removeClass( 'frm_hidden' );
		}
	}

	// -------------------------------------------------------------------
	// Per-form "Collect Payment" action editor (page=formidable, Settings
	// tab → Actions and Notifications → Collect Payment).
	//
	// Formidable's own gateway-tab template
	// (`stripe/views/action-settings/gateway-buttons.php`) is shared by every
	// registered gateway and assumes each one ships an icon named
	// `frm_{gateway}_full_icon` in its bundled SVG sprite
	// (`FrmAppHelper::include_svg()` — a raw readfile() of a static file at
	// plugin-load time, no filter to add a symbol to it). Our tab renders
	// with an empty `<use href="#frm_ifthenpay_full_icon">` as a result —
	// swapped here for our own logo image instead of touching Formidable's
	// own bundled SVG file.
	//
	// The Currency field (`stripe/views/action-settings/payments-options.php`)
	// is the same shared template with no per-gateway filter either — it
	// already hardcodes an equivalent Square-only lock server-side
	// (`FrmTransLiteAppHelper::show_currency_dropdown()`), so this mirrors
	// that same behavior for ifthenpay client-side (ifthenpay only settles
	// in EUR).
	//
	// The Actions and Notifications panel (and the gateway tabs within it)
	// loads via AJAX after the page itself has already loaded, so a
	// MutationObserver — not a one-time DOMContentLoaded pass — is what
	// catches it appearing.
	// -------------------------------------------------------------------

	function initFormActionEnhancements() {
		if ( typeof MutationObserver === 'undefined' ) {
			return;
		}

		syncFormActionState();

		new MutationObserver( syncFormActionState ).observe( document.body, { childList: true, subtree: true } );

		$( document ).on( 'change', 'input[id^="frm_toggle_"][id$="_settings"]', function () {
			syncCurrencyLock();
		} );
	}

	function syncFormActionState() {
		fixIfthenpayGatewayIcon();
		syncCurrencyLock();
	}

	function fixIfthenpayGatewayIcon() {
		$( 'label[for="frm_toggle_ifthenpay_settings"]' ).each( function () {
			var $label = $( this );

			if ( $label.data( 'iftpIconFixed' ) ) {
				return;
			}

			$label.find( 'svg.frmsvg' ).replaceWith(
				$( '<img>', { src: iftpFrmAdmin.logoUrl, alt: iftpFrmAdmin.i18n.ifthenpay, 'class': 'iftp-frm-pill-logo' } )
			);
			$label.data( 'iftpIconFixed', true );
		} );
	}

	function syncCurrencyLock() {
		var $ifthenpayToggle = $( '#frm_toggle_ifthenpay_settings' );

		if ( ! $ifthenpayToggle.length ) {
			return;
		}

		var $currencySelect = $( 'select[name$="[currency]"]' );

		if ( ! $currencySelect.length ) {
			return;
		}

		if ( $ifthenpayToggle.is( ':checked' ) ) {
			if ( ! $currencySelect.data( 'iftpOriginalValue' ) ) {
				$currencySelect.data( 'iftpOriginalValue', $currencySelect.val() );
			}

			$currencySelect.val( 'eur' ).addClass( 'iftp-frm-currency-locked' );

			if ( ! $currencySelect.data( 'iftpLockBound' ) ) {
				$currencySelect.on( 'change', function () {
					if ( $( '#frm_toggle_ifthenpay_settings' ).is( ':checked' ) ) {
						$( this ).val( 'eur' );
					}
				} );
				$currencySelect.data( 'iftpLockBound', true );
			}
		} else if ( $currencySelect.hasClass( 'iftp-frm-currency-locked' ) ) {
			$currencySelect.removeClass( 'iftp-frm-currency-locked' );

			var original = $currencySelect.data( 'iftpOriginalValue' );

			if ( original ) {
				$currencySelect.val( original );
			}
		}
	}

	function activatePaymentsTopLevelTab() {
		$( '.frm-form-setting-tabs li' ).removeClass( 'tabs active starttab' );
		$( '.frm-form-setting-tabs a[href="#payments_settings"]' ).closest( 'li' ).addClass( 'tabs active starttab' );
		$( '#post-body-content > div.tabs-panel' ).removeClass( 'frm_block' ).addClass( 'frm_hidden' );
		$( '#payments_settings' ).removeClass( 'frm_hidden' ).addClass( 'frm_block' );
	}

	// -------------------------------------------------------------------
	// Backoffice Key connect / disconnect (blueprint §8.2, §8.4)
	// -------------------------------------------------------------------

	function onConnect() {
		var $btn = $( this );
		var $input = $( '#iftp-frm-backoffice-key-input' );
		var $error = $( '#iftp-frm-connect-error' );
		var key = $.trim( $input.val() );

		$error.text( '' );

		if ( ! key ) {
			return;
		}

		var originalLabel = $btn.text();
		$btn.prop( 'disabled', true ).text( iftpFrmAdmin.i18n.connecting );

		$.post( iftpFrmAdmin.ajaxUrl, {
			action: 'ifthenpay_frm_connect_backoffice',
			nonce: iftpFrmAdmin.nonce,
			backoffice_key: key
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					$error.text( ( response && response.data && response.data.message ) || iftpFrmAdmin.i18n.genericError );
					$btn.prop( 'disabled', false ).text( originalLabel );
					return;
				}

				$input.val( '' );
				$( '#iftp-frm-connect-form' ).hide();
				$( '#iftp-frm-connected-state' ).show();
				$( '#iftp-frm-connect-tag' )
					.removeClass( 'frm-grey-tag' )
					.addClass( 'frm-lt-green-tag' )
					.text( '' );

				populateGatewayKeySelect( response.data.gateway_keys || [] );
				$( '#iftp-frm-gateway-section' ).show();
			} )
			.fail( function () {
				$error.text( iftpFrmAdmin.i18n.genericError );
				$btn.prop( 'disabled', false ).text( originalLabel );
			} );
	}

	function onDisconnect() {
		var $btn = $( this );
		$btn.prop( 'disabled', true );

		$.post( iftpFrmAdmin.ajaxUrl, {
			action: 'ifthenpay_frm_disconnect_backoffice',
			nonce: iftpFrmAdmin.nonce
		} ).always( function () {
			$( '#iftp-frm-connected-state' ).hide();
			$( '#iftp-frm-connect-form' ).show();
			$( '#iftp-frm-gateway-section' ).hide();
			$( '#iftp-frm-connect-tag' )
				.removeClass( 'frm-lt-green-tag' )
				.addClass( 'frm-grey-tag' )
				.text( '' );
			$btn.prop( 'disabled', false );
		} );
	}

	function populateGatewayKeySelect( gatewayKeys ) {
		var $select = $( '#iftp-frm-gateway-key-select' );
		$select.empty().append( $( '<option></option>' ).val( '' ).text( '— Select —' ) );

		gatewayKeys.forEach( function ( row ) {
			$select.append( $( '<option></option>' ).val( row.key ).text( row.label || row.key ) );
		} );

		if ( 1 === gatewayKeys.length ) {
			$select.val( gatewayKeys[ 0 ].key ).trigger( 'change' );
		}
	}

	// -------------------------------------------------------------------
	// Gateway Key selection → re-render methods table (blueprint §8.2)
	// -------------------------------------------------------------------

	function onSelectGatewayKey() {
		var gatewayKey = $( this ).val();
		var $tbody = $( '#iftp-frm-methods-table-body' );

		if ( ! gatewayKey ) {
			return;
		}

		$tbody.html( '<tr><td colspan="5" class="iftp-frm-methods-loading">' + iftpFrmAdmin.i18n.loadingTable + '</td></tr>' );

		$.post( iftpFrmAdmin.ajaxUrl, {
			action: 'ifthenpay_frm_select_gateway_key',
			nonce: iftpFrmAdmin.nonce,
			gateway_key: gatewayKey
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					$tbody.html( '<tr><td colspan="5">' + ( ( response && response.data && response.data.message ) || iftpFrmAdmin.i18n.genericError ) + '</td></tr>' );
					return;
				}

				$tbody.html( response.data.html );

				$tbody.find( '.iftp-frm-method-row' ).each( function () {
					syncStarForRow( $( this ) );
				} );
			} )
			.fail( function () {
				$tbody.html( '<tr><td colspan="5">' + iftpFrmAdmin.i18n.genericError + '</td></tr>' );
			} );
	}

	// -------------------------------------------------------------------
	// Default Method star (blueprint §8.6 — pure client-side, no AJAX)
	// -------------------------------------------------------------------

	function onMethodEnabledChanged() {
		var $checkbox = $( this );
		var $row = $checkbox.closest( '.iftp-frm-method-row' );
		var $star = $row.find( '.iftp-frm-method-star' );
		var $starInput = $row.find( '.iftp-frm-method-star-input' );

		if ( ! $checkbox.is( ':checked' ) ) {
			// A disabled method can't stay the default.
			if ( $starInput.is( ':checked' ) ) {
				$starInput.prop( 'checked', false );
			}
			$starInput.prop( 'disabled', true );
			$star.addClass( 'iftp-frm-method-star--hidden' );
		} else {
			$starInput.prop( 'disabled', false );
			$star.removeClass( 'iftp-frm-method-star--hidden' );
		}
	}

	function onDefaultMethodChanged() {
		var $icon = $( this ).closest( '.iftp-frm-method-row' ).find( '.iftp-frm-star-icon' );

		// Re-triggerable "wink": remove then re-add the class on the next
		// frame so the animation replays even on repeated selections.
		$icon.removeClass( 'iftp-frm-star-wink' );
		// eslint-disable-next-line no-unused-expressions
		$icon[ 0 ] && $icon[ 0 ].offsetWidth; // force reflow
		$icon.addClass( 'iftp-frm-star-wink' );
	}

	function syncStarForRow( $row ) {
		var $checkbox = $row.find( '.iftp-frm-method-enabled' );
		var $star = $row.find( '.iftp-frm-method-star' );
		var $starInput = $row.find( '.iftp-frm-method-star-input' );

		if ( $checkbox.is( ':checked' ) ) {
			$starInput.prop( 'disabled', false );
			$star.removeClass( 'iftp-frm-method-star--hidden' );
		} else {
			$starInput.prop( 'disabled', true );
			$star.addClass( 'iftp-frm-method-star--hidden' );
		}
	}

	// -------------------------------------------------------------------
	// Request Activation (blueprint §8.5)
	// -------------------------------------------------------------------

	function onActivateMethod() {
		var $btn = $( this );
		var entity = $btn.data( 'entity' );
		var gatewayKey = $btn.data( 'gateway-key' );
		var originalLabel = $btn.text();

		$btn.prop( 'disabled', true ).text( iftpFrmAdmin.i18n.requesting );

		$.post( iftpFrmAdmin.ajaxUrl, {
			action: 'ifthenpay_frm_request_activation',
			nonce: iftpFrmAdmin.nonce,
			entity: entity,
			gateway_key: gatewayKey
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					$btn.text( iftpFrmAdmin.i18n.requested );
					// Stays disabled — server-side cooldown started, native
					// disabled styling already reads as inactive (blueprint
					// §Activation Request Flow — no custom opacity layered on top).
					return;
				}

				window.alert( ( response && response.data && response.data.message ) || iftpFrmAdmin.i18n.genericError );
				$btn.prop( 'disabled', false ).text( originalLabel );
			} )
			.fail( function () {
				window.alert( iftpFrmAdmin.i18n.genericError );
				$btn.prop( 'disabled', false ).text( originalLabel );
			} );
	}

	// -------------------------------------------------------------------
	// Save (description, expiry days, enabled methods, default method +
	// re-activates the webhook callback). Piggybacks on Formidable's own
	// shared "Update" button (#frm-publishing) instead of a separate save
	// control: intercept that button's form submit, run our AJAX save
	// first, then let the real submit (which persists every other Global
	// Settings section) continue via the DOM's native submit() — that
	// skips the 'submit' event entirely, so there's no risk of re-entering
	// this same handler.
	// -------------------------------------------------------------------

	function onGlobalSettingsSubmit( e ) {
		var $ourPanel = $( '#iftp-frm-gateway-section' );

		if ( ! $ourPanel.length ) {
			return;
		}

		e.preventDefault();

		var form = this;

		$( '#frm-publishing input[type="submit"]' ).prop( 'disabled', true ).val( iftpFrmAdmin.i18n.saving );

		saveIfthenpaySettings().always( function () {
			form.submit();
		} );
	}

	function saveIfthenpaySettings() {
		var enabledMethods = $( '.iftp-frm-method-enabled:checked' )
			.map( function () {
				return $( this ).val();
			} )
			.get();

		var defaultMethod = $( '.iftp-frm-method-star-input:checked' ).val() || '';

		return $.post( iftpFrmAdmin.ajaxUrl, {
			action: 'ifthenpay_frm_save_settings',
			nonce: iftpFrmAdmin.nonce,
			expiry_days: $( '#frm_ifthenpay_expiry_days' ).val(),
			methods_enabled: enabledMethods,
			default_method: defaultMethod
		} );
	}

	$( init );
} )( jQuery );
