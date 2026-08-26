=== ifthenpay Payments for Formidable Forms ===
Contributors: ifthenpay
Tags: formidable, formidable forms, payments, ifthenpay, multibanco, mb way
Requires at least: 6.3
Tested up to: 6.34
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept Multibanco, MB WAY, cards, Apple Pay, Google Pay, Cofidis, and Pix on your Formidable Forms "Collect a Payment" action via ifthenpay's hosted Pay by Link page.

== Description ==

ifthenpay Payments for Formidable Forms adds ifthenpay as a payment option on Formidable's own "Collect a Payment" form action, alongside Stripe, Square, and PayPal.

When a payer submits a form configured to collect a payment via ifthenpay, they are redirected to a secure ifthenpay-hosted payment page where they can pay with:

* Multibanco
* MB WAY
* Card (Visa, Mastercard, Amex), Apple Pay, Google Pay
* Cofidis
* Pix

Once payment is confirmed, ifthenpay notifies your site automatically and the entry's payment status is updated in Formidable's own Payments list — the same place Stripe/Square/PayPal payments already show up.

= Requirements =

* Formidable Forms (free) must be installed and active, with its bundled free payments module (this plugin does not currently support sites using the separate paid Formidable "Payments" add-on).
* An ifthenpay account with a Gateway Key.

= External Services =

This plugin connects to the ifthenpay API (`https://api.ifthenpay.com`) to:

* Validate your Backoffice Key and list the Gateway Keys registered to it.
* Fetch the payment methods available on your Gateway Key.
* Create a Pay by Link payment session when a form with an ifthenpay payment action is submitted.
* Register the webhook URL ifthenpay uses to notify your site when a payment completes.

No card or payment data is ever collected or stored on your server — the payer completes payment entirely on ifthenpay's own hosted page. See ifthenpay's terms of service (https://ifthenpay.com/termos-e-condicoes/) and privacy policy (https://ifthenpay.com/politica-de-privacidade/).

== Installation ==

1. Install and activate Formidable Forms (if not already active).
2. Install and activate this plugin.
3. Go to Formidable → Global Settings → ifthenpay and connect your Backoffice Key.
4. Choose a Gateway Key, enable the payment methods you want to accept, and pick a default method.
5. On any form, add a "Collect a Payment" action and choose ifthenpay as the gateway.

== Changelog ==

= 1.0.0 =
* Initial release.
