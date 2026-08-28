=== ifthenpay Payments for Formidable Forms ===
Contributors: ifthenpay
Tags: formidable, formidable forms, payments, ifthenpay, multibanco
Requires at least: 6.3
Tested up to: 6.34
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept Multibanco, MB WAY, cards, Apple Pay, Google Pay, Payshop, Cofidis, and Pix on your Formidable Forms "Collect a Payment" action via ifthenpay's hosted Pay by Link page.

== Description ==

ifthenpay Payments for Formidable Forms adds ifthenpay as a payment option on Formidable's own "Collect a Payment" form action, alongside Stripe, Square, and PayPal.

When a payer submits a form configured to collect a payment via ifthenpay, they are redirected to a secure ifthenpay-hosted payment page where they can pay with:

* Multibanco
* MB WAY
* Card (Visa, Mastercard), Apple Pay, Google Pay
* Payshop
* Cofidis
* Pix

Once payment is confirmed, ifthenpay notifies your site automatically via a server-to-server callback and the entry's payment status is updated in Formidable's own Payments list — the same place Stripe/Square/PayPal payments already show up. No card, MB WAY, or bank data is ever collected or stored on your server.

In plain terms you get:
* One Gateway Key on your ifthenpay Backoffice, usable across every form on the site
* A live, editable table of every payment method provisioned on that Gateway Key, with per-method enable/disable and a default method
* Automatic, secure hosted-page checkout with no PCI scope on your server
* Real-time status sync back into Formidable's native Entries and Payments admin screens

All settings are managed within Formidable's own Global Settings screen and your ifthenpay Backoffice. The plugin is designed so site owners can handle payments without requiring advanced technical knowledge.

= Requirements =

* An active ifthenpay merchant account with a Backoffice Key.
* A Gateway Key registered to that Backoffice Key, with the payment methods you want to accept provisioned on it.
* Formidable Forms (free) must be installed and active, with its bundled free payments module (this plugin does not currently support sites using the separate paid Formidable "Payments" add-on).
* WordPress 6.3+ and PHP 7.4+, with HTTPS (SSL) enabled on your site.

= External Services =

This plugin connects to the ifthenpay API (`https://api.ifthenpay.com`) to:

* Validate your Backoffice Key and list the Gateway Keys registered to it.
* Fetch the payment methods available and provisioned on your Gateway Key.
* Create a Pay by Link payment session when a form with an ifthenpay payment action is submitted.
* Register the webhook URL ifthenpay uses to notify your site when a payment completes.
* Look up a payment's live status (`/gateway/transaction/status/get`) as a supplementary check while the payer waits for the async webhook.

Data sent includes: your Backoffice Key and Gateway Key (setup and settings screen); a payment reference id, amount, description, enabled accounts string, success/error/cancel return URLs, language, and optional link-expiry date and default method (on submission); your site's callback URL (when saving settings); and the transaction id ifthenpay returned for that payment (for the live status check).

No card, MB WAY, or bank data is ever collected or stored on your server — the payer completes payment entirely on ifthenpay's own hosted page. See ifthenpay's terms of service (https://ifthenpay.com/termos-e-condicoes/) and privacy policy (https://ifthenpay.com/politica-de-privacidade/).

== Key Features ==

1. Full integration with Formidable Forms' free "Collect a Payment" action, alongside Stripe/Square/PayPal
2. Secure, PCI-out-of-scope checkout via ifthenpay's own hosted Pay by Link page
3. Automatic payment confirmation via a dedicated server-to-server callback endpoint
4. Support for every payment method provisioned on your Gateway Key
5. Configurable payment link expiry (in days)
6. Customizable Payment Received / Payment Pending confirmation messages and behavior
7. Customizable pay button text and logo placement
8. Real-time payment status in Formidable's own Entries and Payments admin lists
9. Multi-language hosted payment page (English, Portuguese, Spanish, French)
10. Security first — no card, MB WAY, or bank details ever stored on your server

== Installation ==

1. Install: Install and activate Formidable Forms (if not already active), then upload and activate this plugin.
2. Connect: Go to Formidable → Global Settings → Payments → ifthenpay and connect your Backoffice Key.
3. Configure: Choose a Gateway Key, enable the payment methods you want to accept, pick a default method, and set a payment link expiry.
4. Form setup: On any form, add a "Collect a Payment" action and choose ifthenpay as the gateway.
5. Optional: Visit Formidable → Global Settings → Ifthenpay Extras to customize the Payment Received/Pending messages and the pay button's text and logo.

== Frequently Asked Questions ==

= Does this plugin require Formidable Forms? =
Yes. The free Formidable Forms plugin, with its bundled "Lite" payments module, must be installed and active. This plugin does not currently support sites using the separate paid Formidable "Payments" add-on.

= Does it support recurring payments? =
No. ifthenpay Pay by Link is a one-time payment per submission; the recurring payment type is hidden for the ifthenpay gateway on Formidable's own action settings screen.

= Are payment details stored? =
No. The plugin never stores card numbers, MB WAY numbers, or bank details. Your Backoffice Key is stored server-side only and is never exposed to the browser once connected.

= Which payment methods are supported? =
Any method ifthenpay provisions on your Gateway Key — commonly Multibanco, MB WAY, Payshop, Visa/Mastercard, Apple Pay, Google Pay, Cofidis, and Pix. The methods table on the settings screen always reflects exactly what's active on your account.

= How does the payment process work? =
After the form is submitted, the entry is created and the payer is redirected to ifthenpay's hosted payment page. Once they complete payment, ifthenpay calls your site's callback endpoint to confirm it, and the entry's payment status updates automatically.

= What happens if a payment fails or is canceled? =
The payer is sent back to your site and shown a fixed message. The Formidable entry is still created (in a pending state) so the submission isn't lost, and a payer can return to the same payment link later if it's still valid.

= Can I customize the payment experience? =
Yes. You can customize the Payment Received and Payment Pending messages (show message, redirect, or open a new tab), the pay button's text, and whether payment-method icons are shown, from Formidable → Global Settings → Ifthenpay Extras.

= Is there a sandbox? =
ifthenpay may provide test entities for a Gateway Key on request; otherwise, use a low-value live test to confirm your setup end-to-end.

= How secure is the integration? =
All requests to the ifthenpay API are made server-side over HTTPS. The webhook callback is authenticated with an anti-phishing key unique to your Gateway Key, and each payment's amount is verified before being marked complete.

== Screenshots ==
1. Global Settings → Payments — the ifthenpay tab sits alongside Stripe, Square, and PayPal.
2. ifthenpay settings — Backoffice Key connected, Gateway Key selected, and the live payment-methods table.
3. Ifthenpay Extras tab — Payment Received / Payment Pending message and redirect behavior.
4. Formidable's Forms list — where to open a form's own Settings to add a payment action.
5. A form's "Collect a Payment" action, configured with ifthenpay as the gateway.
6. The front-end pay button on a live form, with a custom logo-and-text label.
7. ifthenpay's hosted Pay by Link page, showing the available payment methods for that transaction.
8. A completed ifthenpay payment shown in Formidable's native Payments list.

== Changelog ==

= 1.0.0 =
* Initial release: Formidable Forms "Collect a Payment" integration, ifthenpay Pay by Link payments, live multi-method support, customizable confirmation and pay-button UI.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Review your ifthenpay gateway and payment method settings before going live.

== License ==
This plugin is licensed under the GPLv3.

== Support ==

For assistance use the WordPress.org support forum: https://wordpress.org/support/plugin/ifthenpay-payments-for-formidable

Pre-checks before posting:
* Payment method enabled on your Gateway Key AND enabled on the ifthenpay settings screen
* Running current recommended versions of WordPress, PHP, and Formidable Forms
* Webhook callback URL reachable (not blocked by a firewall, security plugin, or maintenance mode)

* ifthenpay support: contact your ifthenpay account manager or https://ifthenpay.com/
* Formidable Forms docs: https://formidableforms.com/knowledgebase/
