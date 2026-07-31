=== Yappy Payment Button for WooCommerce ===
Contributors: lammworks
Tags: woocommerce, payment gateway, yappy, panama, banco general
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
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

= 1.3.0 =
* Deliver the IPN over the WordPress REST API (`/wp-json/wc-yappy/v1/ipn`) by default, because full-page caches — Cloudflare and the built-in caches of managed hosts such as GoDaddy — cache the `/wc-api/` path and drop the query string from the cache key, silently swallowing every payment notification. The REST route is served dynamically and left uncached, so orders are marked paid reliably. The legacy `/wc-api/wc_yappy/` endpoint stays available and can be re-selected through the `wc_yappy_ipn_url` filter. The IPN URL is sent to Yappy on every request, so no re-registration is needed.

= 1.2.11 =
* Mark the IPN endpoint as non-cacheable (DONOTCACHEPAGE and explicit no-store / CDN-Cache-Control headers) so page and object caches do not retain it. Note: an edge cache such as Cloudflare set to "cache everything" still needs a bypass rule for /wc-api/ in its own configuration — a cached IPN response never reaches the store and leaves the order unpaid.

= 1.2.10 =
* Log the IPN endpoint being reached even when the gateway is momentarily unavailable, so a reachability test proves whether Yappy's notification actually arrives at the store.

= 1.2.9 =
* Accept the IPN as a POST or JSON body in addition to the documented GET, so a reverse proxy or a POST-style delivery cannot silently drop a payment notification.
* Log the received IPN request and, on a signature mismatch, enough detail (with debug logging enabled) to tell an empty payload, a missing secret key and a genuine hash mismatch apart — to diagnose orders that stay pending after payment.

= 1.2.8 =
* Close the waiting card automatically when its countdown reaches zero: the Yappy request has expired, so the attempt ends and a fresh button is offered instead of leaving a dead 0:00 timer on screen.

= 1.2.7 =
* Take down the waiting card as soon as the Yappy app reports the request ended (for example, the customer cancelled it there), instead of leaving it counting down over an already re-enabled button. Terminal results now end the attempt consistently and leave a fresh button ready.

= 1.2.6 =
* Replace the Yappy button with a fresh, enabled one whenever an attempt ends in place — after the waiting card is closed, or after WooCommerce rejects the submit (for example, terms of service left unchecked) — because the official component cannot re-enable its own button once pressed. The customer's phone number entry is preserved.

= 1.2.5 =
* Fix the inline checkout so closing the Yappy waiting card cancels the attempt and re-enables the Yappy button, letting the customer submit a fresh request (for example, after correcting a mistyped phone number).
* Stop a dismissed waiting card from reappearing when the Yappy component reports a late result.

= 1.2.4 =
* Clear WooCommerce's checkout-processing overlay when the waiting-card close control is pressed while safely keeping the payment request monitored.

= 1.2.3 =
* Make the waiting-card close control high-contrast and return the checkout button to its normal state when it is pressed.

= 1.2.2 =
* Show the Yappy waiting card immediately while WooCommerce creates the request, including a spinner for slower hosts.
* Use the plugin's accessible waiting card, close control and five-minute timer instead of the competing Yappy component modal.

= 1.2.1 =
* Fix the inline checkout handoff so the Yappy waiting card and countdown begin after WooCommerce creates the order.

= 1.2.0 =
* Add a five-minute Yappy payment countdown directly in checkout.
* Detect rejected, cancelled and expired Yappy IPNs without treating them as a successful payment, then allow a fresh Yappy request on the same order.

= 1.1.2 =
* Prevent the inline checkout from reloading after Yappy is started.

= 1.1.1 =
* Keep the checkout visible with a clear waiting animation while the customer confirms the Yappy request in their mobile app.
* Send customers to the receipt only after Yappy's signed notification confirms payment.

= 1.1.0 =
* Launch Yappy directly from the classic WooCommerce checkout, without sending customers through the order-pay page.
* Keep the order-pay page as a safe fallback for checkout blocks, direct payment links and browsers without the Yappy component.

= 1.0.0 =
* First release: new Botón de Pago Yappy integration, block checkout support, HPOS support, signed IPN handling.
