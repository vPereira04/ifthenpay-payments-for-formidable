/**
 * Two independent jobs, both driven by markup/data PHP outputs — see
 * RedirectHandler's own class docblock for the full flow:
 *
 * 1. Shows the ifthenpay outcome popup (`#iftp-frm-modal`, from either a
 *    real return-trip page load or a status-poll response below) and wires
 *    its close interactions. No jQuery dependency for this part. A
 *    return-trip page load only ever happens in the pre-opened *second* tab
 *    (`window.open()`'d below) — the original tab's own poll is what's meant
 *    to show the payer the outcome, so showing it a second time in the
 *    ifthenpay tab too would just be confusing/redundant: that tab instead
 *    closes itself (`maybeCloseOpenedTab()`), falling back to showing the
 *    popup there itself only if the browser won't let it close (e.g. the
 *    no-JS/popup-blocked single-tab fallback, where it's the only tab left).
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
 *    nothing here needs it to) and shows a waiting overlay while polling
 *    RedirectHandler::handle_status() for the outcome. Needs jQuery —
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

	/**
	 * A page carrying `#iftp-frm-modal` on its *initial* load is always
	 * ifthenpay's own return trip (see this file's top docblock) — reached
	 * either in the second, pre-opened tab (the normal case: `window.opener`
	 * is set, since that tab only exists because the original tab's
	 * `window.open()` created it — see RedirectHandler::maybe_override_redirect_url())
	 * or, if a popup blocker or disabled JS ever prevented that tab from
	 * opening in the first place, back in the original/only tab there ever
	 * was (`window.opener` is unset). Only the latter case should actually
	 * show the popup here; the former should just close, since the original
	 * tab's own poll (`pollPaymentStatus()` below) already shows it there.
	 *
	 * `window.close()` is a same-tab no-op unless this tab was actually
	 * opened by script (the browser silently refuses otherwise) — so if nothing
	 * closes within `CLOSE_FALLBACK_MS`, either that wasn't the case after all
	 * or the browser blocked it for some other reason, and the popup is shown
	 * here as a fallback rather than leaving the payer looking at nothing.
	 *
	 * @param {HTMLElement|null} modal
	 * @return {void}
	 */
	function maybeCloseOpenedTab( modal ) {
		if ( ! modal ) {
			return;
		}

		if ( ! window.opener ) {
			activateModal( modal );
			return;
		}

		var CLOSE_FALLBACK_MS = 300;

		window.close();

		setTimeout( function () {
			if ( ! window.closed ) {
				activateModal( modal );
			}
		}, CLOSE_FALLBACK_MS );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		maybeCloseOpenedTab( document.getElementById( 'iftp-frm-modal' ) );
	} );

	if ( ! $ ) {
		return;
	}

	var cfg  = window.iftpFrmFlow || {};
	var i18n = cfg.i18n || {};

	var POLL_INTERVAL_MS = 2500;

	/**
	 * @return {jQuery} The waiting overlay, already appended to the page.
	 */
	function buildWaitingOverlay() {
		var $overlay = $(
			'<div class="iftp-frm-overlay" role="status">' +
				'<div class="iftp-frm-overlay__spinner" aria-hidden="true"></div>' +
				'<p class="iftp-frm-overlay__text"></p>' +
				'<p class="iftp-frm-overlay__brand"><span></span><img src="' + cfg.logoUrl + '" alt="ifthenpay" /></p>' +
			'</div>'
		);

		$overlay.find( '.iftp-frm-overlay__text' ).text( i18n.waiting || 'Waiting for your payment to complete…' );
		$overlay.find( '.iftp-frm-overlay__brand span' ).text( i18n.poweredBy || 'Powered by' );

		$( document.body ).append( $overlay );

		return $overlay;
	}

	/**
	 * Recognizes RedirectHandler::maybe_override_redirect_url()'s own
	 * same-origin wrapper URL (`?action=ifthenpay_frm_open&entry=...&token=...`)
	 * among whatever `frmBeforeFormRedirect` fires for — Formidable fires
	 * that event for *every* "Redirect to URL" On Submit action, not just
	 * ifthenpay's, so anything else must be left alone.
	 *
	 * @param {string} redirectUrl
	 * @return {{entry: string, token: string}|null}
	 */
	function parseIfthenpayOpenUrl( redirectUrl ) {
		var url;

		try {
			url = new URL( redirectUrl, window.location.href );
		} catch ( err ) {
			return null;
		}

		if ( 'ifthenpay_frm_open' !== url.searchParams.get( 'action' ) ) {
			return null;
		}

		var entryId = url.searchParams.get( 'entry' );
		var token   = url.searchParams.get( 'token' );

		if ( ! entryId || ! token ) {
			return null;
		}

		return { entry: entryId, token: token };
	}

	/**
	 * Polls RedirectHandler::handle_status() — the payer already paid (or
	 * canceled/failed) on the tab `frmBeforeFormRedirect`'s own
	 * `window.open()` sent them to; this is what tells the *original* tab
	 * (still on the form) once that resolves one way or another, and shows
	 * the right outcome here instead of leaving it sitting on the blank
	 * content Formidable's own AJAX response left behind (see this file's
	 * own top docblock).
	 *
	 * @param {string} entryId
	 * @param {string} token
	 * @return {void}
	 */
	function pollPaymentStatus( entryId, token ) {
		var $overlay = buildWaitingOverlay();

		function tick() {
			$.get( cfg.ajaxUrl, { action: 'ifthenpay_frm_status', entry: entryId, token: token } )
				.done( function ( response ) {
					if ( ! response || 'pending' === response.status ) {
						setTimeout( tick, POLL_INTERVAL_MS );
						return;
					}

					$overlay.remove();

					if ( 'redirect' === response.action && response.redirectUrl ) {
						window.location = response.redirectUrl;
						return;
					}

					if ( response.modalHtml ) {
						var $injected = $( response.modalHtml );
						$( document.body ).append( $injected );
						activateModal( $injected.get( 0 ) );
					}
				} )
				.fail( function () {
					setTimeout( tick, POLL_INTERVAL_MS );
				} );
		}

		tick();
	}

	$( document ).on( 'frmBeforeFormRedirect', function ( event, formEl, response ) {
		if ( ! response || ! response.redirect ) {
			return;
		}

		var parsed = parseIfthenpayOpenUrl( response.redirect );

		if ( ! parsed ) {
			return;
		}

		pollPaymentStatus( parsed.entry, parsed.token );
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
	$( '.iftp-frm-method-block' ).each( function () {
		var $block = $( this );
		var $form  = $block.closest( 'form' );

		nativeSubmitButtons( $form ).css( 'display', 'none' );
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
} )( window.jQuery );
