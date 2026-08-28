/**
 * Two independent jobs, both driven by markup/data PHP outputs — see
 * RedirectHandler's own class docblock for the full flow:
 *
 * 1. Shows the ifthenpay outcome popup (`#iftp-frm-modal`, printed by a real
 *    return-trip page load) and wires its close interactions. No jQuery
 *    dependency for this part. A return-trip page load only ever happens in
 *    the pre-opened *second* tab (`window.open()`'d below, or the current
 *    tab itself in the no-JS/popup-blocked single-tab fallback) — that tab
 *    stays put and shows this directly; it never tries to close itself (an
 *    earlier version of this file had the *original* tab poll for the
 *    outcome instead, with the second tab trying to `window.close()` itself
 *    once done — dropped because a same-origin redirect chain can lose the
 *    `window.opener` relationship a same-tab close needs, e.g. an
 *    intermediate page setting a Cross-Origin-Opener-Policy header, leaving
 *    that tab stuck open with no outcome shown at all).
 * 2. On a form carrying an ifthenpay payment action — whether submitted via
 *    PaymentSelector.php's "Pay with ifthenpay" button (a real
 *    `type="submit"` button, so this is Formidable's own normal AJAX
 *    submission, validation included — see PaymentSelector::render()'s own
 *    comment) or the form's native submit button: the real Formidable entry
 *    is created immediately, same as any other payment gateway, and
 *    RedirectHandler::maybe_override_redirect_url() substitutes the
 *    post-submit redirect with a same-origin wrapper URL and forces
 *    `openInNewTab` on for it, so Formidable's own front-end script
 *    (`js/formidable.js`'s `doRedirect()`) does
 *    `window.open( response.redirect, '_blank' )` instead of navigating this
 *    tab away — see RedirectHandler::maybe_override_redirect_url()'s own
 *    docblock for why. This tab stays on the form; the listener below
 *    recognizes that wrapper URL via Formidable's own `frmBeforeFormRedirect`
 *    event (fired right before that `window.open()`/`window.location` call —
 *    it can't be canceled from here, Formidable never checks for that, but
 *    nothing here needs it to) and hands off: this tab's only remaining job
 *    was pre-opening the payment tab, so it tries to close itself, falling
 *    back to a static "continue in the other tab" notice if the browser
 *    won't allow that (e.g. it wasn't opened by script — the normal case for
 *    whatever page the payer actually landed the form on). Needs jQuery —
 *    Formidable's own front-end script is a hard jQuery dependency already.
 */
( function ( $ ) {
	'use strict';

	/**
	 * @param {HTMLElement|null} modal
	 * @return {void}
	 */
	function activateModal( modal ) {
		if ( ! modal || false === modal.hidden ) {
			return;
		}

		modal.hidden = false;

		// "Open in a New Tab" Payment Received mode (RedirectHandler::build_modal_html()) —
		// the popup itself stays put, this just also opens the merchant's
		// configured URL alongside it. `activateModal()`'s own hidden-guard
		// above already makes this fire at most once per modal.
		var openUrl = modal.getAttribute( 'data-iftp-open-url' );

		if ( openUrl ) {
			window.open( openUrl, '_blank' );
		}

		function close() {
			modal.hidden = true;

			if ( ! window.history || ! window.history.replaceState ) {
				return;
			}

			var url = new URL( window.location.href );
			url.searchParams.delete( 'ifthenpay_notice' );
			url.searchParams.delete( 'ifthenpay_entry' );
			url.searchParams.delete( 'ifthenpay_token' );
			window.history.replaceState( {}, document.title, url.toString() );
		}

		modal.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-iftp-close]' ) ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.hidden ) {
				close();
			}
		} );
	}

	// A page carrying `#iftp-frm-modal` on its *initial* load is always
	// ifthenpay's own return trip (see this file's top docblock) — this tab
	// stays put and shows it directly, whether it's the pre-opened second tab
	// or, in the no-JS/popup-blocked fallback, the only tab there ever was.
	document.addEventListener( 'DOMContentLoaded', function () {
		activateModal( document.getElementById( 'iftp-frm-modal' ) );
	} );

	if ( ! $ ) {
		return;
	}

	var cfg  = window.iftpFrmFlow || {};
	var i18n = cfg.i18n || {};

	/**
	 * Shown once this tab has handed off to the pre-opened payment tab and
	 * failed to close itself (see handOffToPaymentTab()) — a static notice,
	 * not a "waiting" state: this tab has no further role in the payment, the
	 * *other* tab is the one that will show the outcome once ifthenpay sends
	 * it back.
	 *
	 * @return {void}
	 */
	function showHandoffNotice() {
		var $notice = $(
			'<div class="iftp-frm-overlay" role="status">' +
				'<p class="iftp-frm-overlay__text"></p>' +
				'<p class="iftp-frm-overlay__brand"><span></span><img src="' + cfg.logoUrl + '" alt="ifthenpay" /></p>' +
			'</div>'
		);

		$notice.find( '.iftp-frm-overlay__text' ).text( i18n.continueInOtherTab || 'Continue in the other tab — you can close this one.' );
		$notice.find( '.iftp-frm-overlay__brand span' ).text( i18n.poweredBy || 'Powered by' );

		$( document.body ).append( $notice );
	}

	/**
	 * Recognizes RedirectHandler::maybe_override_redirect_url()'s own
	 * same-origin wrapper URL (`?action=ifthenpay_frm_open&entry=...&token=...`)
	 * among whatever `frmBeforeFormRedirect` fires for — Formidable fires
	 * that event for *every* "Redirect to URL" On Submit action, not just
	 * ifthenpay's, so anything else must be left alone.
	 *
	 * @param {string} redirectUrl
	 * @return {boolean}
	 */
	function isIfthenpayOpenUrl( redirectUrl ) {
		var url;

		try {
			url = new URL( redirectUrl, window.location.href );
		} catch ( err ) {
			return false;
		}

		return 'ifthenpay_frm_open' === url.searchParams.get( 'action' );
	}

	/**
	 * This tab's only job was pre-opening the payment tab (`window.open()`,
	 * fired by Formidable's own `doRedirect()` right after this event) — that
	 * already happened by the time this runs, so there's nothing further for
	 * this tab to do. Tries to close itself; a browser only allows
	 * `window.close()` on a tab it did not itself open via script when that
	 * tab's session history length is still 1 (i.e. the payer landed directly
	 * on the form with no other navigation first) — falls back to a static
	 * notice rather than leaving the payer looking at Formidable's own blank
	 * post-AJAX-submit page when it doesn't.
	 *
	 * @return {void}
	 */
	function handOffToPaymentTab() {
		var CLOSE_CHECK_MS = 300;

		window.close();

		setTimeout( function () {
			if ( ! window.closed ) {
				showHandoffNotice();
			}
		}, CLOSE_CHECK_MS );
	}

	$( document ).on( 'frmBeforeFormRedirect', function ( event, formEl, response ) {
		if ( ! response || ! response.redirect || ! isIfthenpayOpenUrl( response.redirect ) ) {
			return;
		}

		handOffToPaymentTab();
	} );

	// ---- Payment method block (PaymentSelector.php) ----------------------------
	// ifthenpay comes pre-selected (no radio to choose it — it's shown as
	// already-active): as soon as the block exists, hide the form's native
	// submit button(s) and rely on our own "Pay" button instead. If the payer
	// then picks a DIFFERENT method elsewhere on the form (Formidable's own
	// Card/PayPal row, or any other radio field), that reverts — native
	// submit comes back and our block hides, since ifthenpay is no longer
	// the active choice.

	/**
	 * @param {jQuery} $form
	 * @return {jQuery}
	 */
	function nativeSubmitButtons( $form ) {
		// `.iftp-frm-pay-button` is itself a real `type="submit"` button (see
		// PaymentSelector::render()'s own comment for why) — excluded here so
		// hiding/restoring the form's *other* native submit button(s) around
		// it never touches this one too.
		return $form.find( 'input[type="submit"], button[type="submit"]' ).not( '.frm_prev_page, .iftp-frm-pay-button' );
	}

	// `.iftp-frm-method-block` is the outer wrapper — it contains both the
	// bordered `.iftp-frm-method-selector` box (logo + method icons) and the
	// `.iftp-frm-method-action` "Pay with ifthenpay" button as siblings (the
	// button deliberately sits outside the bordered box, see
	// PaymentSelector::render()) — hiding/showing happens on this shared
	// wrapper so both move together.

	/**
	 * @param {jQuery} $form
	 * @return {void}
	 */
	function hideNativeSubmitButtons( $form ) {
		var $block = $form.find( '.iftp-frm-method-block' );

		if ( ! $block.length || $block.prop( 'hidden' ) ) {
			// Not ours to touch — either no ifthenpay block on this form, or
			// the payer already picked a different method (see the 'change'
			// handler below), in which case the native button(s) are meant
			// to stay visible.
			return;
		}

		nativeSubmitButtons( $form ).css( 'display', 'none' );
	}

	$( '.iftp-frm-method-block' ).each( function () {
		hideNativeSubmitButtons( $( this ).closest( 'form' ) );
	} );

	// Any radio elsewhere in the form being selected (Formidable's own
	// Card/PayPal row, or an unrelated radio field) means ifthenpay is no
	// longer the active method — put the native submit button(s) back and
	// hide our block.
	$( document ).on( 'change', 'form input[type="radio"]', function () {
		var $form  = $( this ).closest( 'form' );
		var $block = $form.find( '.iftp-frm-method-block' );

		if ( ! $block.length || $block.prop( 'hidden' ) ) {
			return;
		}

		$block.prop( 'hidden', true );
		nativeSubmitButtons( $form ).css( 'display', '' );
	} );

	// A blocked submit attempt (a required field left empty, an invalid
	// email, etc.) re-validates and re-renders field-level error messages
	// through Formidable's own core JS — none of which know our native
	// submit button(s) are supposed to stay hidden, so that pass can leave
	// them visible again next to our own "Pay" button until the page is
	// reloaded. Re-asserting the hide right after every validation pass
	// (pass or fail — cheap, and correct either way) closes that gap instead
	// of tracking down every internal Formidable code path that can touch
	// it. Two distinct signals, both needed: `frm_get_ajax_form_errors` is a
	// native `CustomEvent` (see formidable.js's own `triggerCustomEvent()`)
	// fired on every submit attempt for the client-side pass — required
	// fields, format checks — before any request goes out; `frmFormErrors`
	// is a jQuery-triggered event fired only after the server rejects an
	// AJAX submission (checks formidable.js can't do client-side, e.g.
	// server-side spam/validation rules).
	document.addEventListener( 'frm_get_ajax_form_errors', function ( event ) {
		var formEl = event.frmData && event.frmData.formEl;

		if ( formEl ) {
			hideNativeSubmitButtons( $( formEl ) );
		}
	} );

	$( document ).on( 'frmFormErrors', function ( event, formEl ) {
		if ( formEl ) {
			hideNativeSubmitButtons( $( formEl ) );
		}
	} );

	// A gateway-level failure (e.g. IfthenpayGateway::trigger() rejecting a
	// missing/zero amount) never reaches the two handlers above: Formidable's
	// own FrmTransLiteActionsController::show_failed_message() forces
	// `show_form=1` and swaps in an error message while still treating the
	// entry as successfully created, so formidable.js takes its `response.content`
	// branch — replacing the whole `.frm_forms` wrapper with fresh markup and
	// firing `frmFormComplete`, never `frmFormErrors`/`frm_get_ajax_form_errors`.
	// That fresh markup's native submit button(s) never get hidden, and stay
	// visible next to our "Pay" button until some later submit attempt
	// incidentally fires one of the events above, or the page is reloaded.
	// `object` on `frmFormComplete` is the detached pre-replace form element,
	// not the new one, so re-scan the document the same way the initial
	// page-load pass does rather than relying on it.
	$( document ).on( 'frmFormComplete', function () {
		$( '.iftp-frm-method-block' ).each( function () {
			hideNativeSubmitButtons( $( this ).closest( 'form' ) );
		} );
	} );
} )( window.jQuery );
