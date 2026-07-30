=== Yappy Payment Button for WooCommerce ===
Contributors: lammworks
Tags: woocommerce, payment gateway, yappy, panama, banco general
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments with Yappy, the mobile payment service from Banco General (Panamá), using the new Botón de Pago Yappy integration.

== Description ==

Adds Yappy as a payment method in WooCommerce. Customers place their order as usual and then confirm the payment in their Yappy app — either by receiving the request on their phone number, or by scanning a QR code.

The plugin implements the **new** Botón de Pago Yappy integration:

* `POST /payments/validate/merchant` to obtain a short-lived token.
* `POST /payments/payment-wc` to create the order.
* The official `<btn-yappy>` web component, loaded from the Yappy CDN.
* The IPN callback, verified with HMAC-SHA256, as the single authority on whether an order was paid.

The classic (shortcode) checkout launches the Yappy button inline, without a separate WooCommerce payment page. The WooCommerce Checkout block remains supported through the standard secure payment-page fallback. High-Performance Order Storage is supported.

= What you need =

A Yappy Comercial account with a merchant ID, a secret key, and the store domain registered with Banco General. Request them at botondepagoyappy@bgeneral.com.

= Security =

Store credentials never reach the browser. Every call to Yappy is made server side, and payment notifications are only accepted when their HMAC-SHA256 signature matches, using a constant-time comparison.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/woocommerce-yappy/`, or install the ZIP through Plugins → Add New.
2. Activate it.
3. Go to WooCommerce → Settings → Payments → Yappy.
4. Enter your merchant ID and secret key, confirm the registered domain, and enable the gateway.
5. Register the IPN URL shown on that settings screen with Yappy Comercial.

The store currency must be USD or PAB, and the site must be served over HTTPS.

== Frequently Asked Questions ==

= Does the customer have to type their phone number? =

No. If they leave it empty — or if you turn the field off in the settings — Yappy shows a QR code instead.

= When is the order marked as paid? =

Only when Yappy's server-to-server notification arrives and its signature verifies. The browser cannot mark an order paid, so a customer closing the tab does not lose the payment.

= Can I refund through the plugin? =

No. The Botón de Pago integration does not expose a refund endpoint; refunds are handled in Yappy Comercial.

= Which order statuses does Yappy report? =

Executed (paid), Rejected (marked failed), Cancelled (marked cancelled) and Expired (marked failed).

== Changelog ==

= 1.1.1 =
* Keep the checkout visible with a clear waiting animation while the customer confirms the Yappy request in their mobile app.
* Send customers to the receipt only after Yappy's signed notification confirms payment.

= 1.1.0 =
* Launch Yappy directly from the classic WooCommerce checkout, without sending customers through the order-pay page.
* Keep the order-pay page as a safe fallback for checkout blocks, direct payment links and browsers without the Yappy component.

= 1.0.0 =
* First release: new Botón de Pago Yappy integration, block checkout support, HPOS support, signed IPN handling.
