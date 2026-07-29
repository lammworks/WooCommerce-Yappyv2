# Yappy Payment Button for WooCommerce

A WooCommerce payment gateway for [Yappy](https://www.yappy.com.pa/), the mobile payment
service from Banco General (Panamá), built on the **new** *Botón de Pago Yappy* integration.

## How it works

```
 checkout                 order-pay page                    Yappy
 ────────                 ─────────────                     ─────
 place order  ─────────►  <btn-yappy> rendered
                          from the Yappy CDN
                                │
                          eventClick
                                │
                                ▼
                          admin-ajax  ──►  POST /payments/validate/merchant
                                           POST /payments/payment-wc
                                │◄──  transactionId · token · documentName
                                │
                          eventPayment()  ────────────────►  app push / QR
                                                                  │
 order marked paid  ◄────  IPN (HMAC verified)  ◄─────────────────┘
```

Two properties are deliberate:

* **The browser never talks to Yappy directly.** Credentials stay on the server; the page
  only ever receives the three opaque values the web component needs.
* **The IPN is the only authority on payment.** `eventSuccess` in the browser just triggers
  a short poll of the store's own order status — a customer who closes the tab still gets
  their order marked paid.

## Requirements

| | |
|---|---|
| WordPress | 6.0+ |
| WooCommerce | 7.6+ |
| PHP | 7.4+ |
| Currency | USD or PAB |
| Transport | HTTPS, publicly reachable (Yappy must be able to call the IPN URL) |

Credentials — merchant ID, secret key and a registered domain — come from Yappy Comercial
(botondepagoyappy@bgeneral.com).

## Settings

WooCommerce → Settings → Payments → Yappy.

| Setting | Notes |
|---|---|
| Merchant ID | From Yappy Comercial. |
| Secret key | From Yappy Comercial, base64. Used to verify IPN signatures. |
| Registered domain | Must match the domain registered with Banco General **exactly**. Defaults to the site URL origin. |
| Environment | `Production` or `Sandbox (UAT)`. Credentials are not interchangeable. |
| Button theme / rounded | Passed straight through to the `<btn-yappy>` component. |
| Ask for the phone number | On: the request is pushed to that number. Off or left empty: Yappy renders a QR code. |
| Debug log | Adds request-level detail under WooCommerce → Status → Logs (source `yappy`). Errors are always logged. |

The IPN URL to register is shown on the settings screen:

```
https://your-store.com/wc-api/wc_yappy/
```

## Integration reference

Endpoints, per environment:

| | Production | Sandbox (UAT) |
|---|---|---|
| API | `https://apipagosbg.bgeneral.cloud` | `https://api-comecom-uat.yappycloud.com` |
| CDN | `https://bt-cdn.yappy.cloud/v1/cdn/web-component-btn-yappy.js` | `https://bt-cdn-uat.yappycloud.com/v1/cdn/web-component-btn-yappy.js` |

**1. `POST /payments/validate/merchant`** — `{merchantId, urlDomain}` → `{status, body:{epochTime, token}}`.
The token is short lived, so the plugin never caches it; it is fetched immediately before step 2.

**2. `POST /payments/payment-wc`** — header `Authorization: <token>`, body
`{merchantId, orderId, domain, paymentDate, ipnUrl, aliasYappy?, discount, taxes, subtotal, total}`
→ `{status, body:{transactionId, token, documentName}}`.

**3. `<btn-yappy>`** — attributes `theme`, `rounded`; property `isButtonLoading`; method
`eventPayment({transactionId, token, documentName})`; events `eventClick`, `eventSuccess`,
`eventError`, `isYappyOnline`.

**4. IPN** — `GET {ipnUrl}?orderId&status&domain&hash&confirmationNumber`, where
`hash = HMAC-SHA256(orderId + status + domain, key)` and
`key = explode('.', base64_decode(secret))[0]`.

| Status | Meaning | WooCommerce result |
|---|---|---|
| `E` | Executed | `payment_complete()` |
| `R` | Rejected | `failed` |
| `C` | Cancelled | `cancelled` |
| `X` | Expired | `failed` |

### Order references

Yappy requires `orderId` to be alphanumeric, at most 15 characters, and **unique per
transaction** (a reuse is rejected with `E007`). WooCommerce order IDs satisfy none of that
across retries, so the plugin sends a derived reference:

```
AAAAAAAAABBBBBB
├───────┤├─────┤
 order ID  per-attempt
 base 36   entropy
```

Because the order ID is encoded in the reference, the IPN resolves the order without a meta
query — identical behaviour on HPOS and legacy post storage. Every reference used for an
order is also kept in order meta, so a late notification for an earlier attempt still matches.

### Amounts

Yappy validates the arithmetic and answers `E010` when it does not hold. The plugin derives
the subtotal from the other three values and absorbs any rounding residue there, so
`total = subtotal + taxes − discount` holds exactly *on the transmitted strings* and the
total Yappy charges always equals the total WooCommerce recorded.

## Filters

| Filter | Purpose |
|---|---|
| `wc_yappy_icon` | Checkout icon URL. |
| `wc_yappy_merchant_domain` | Override the domain sent as `urlDomain`/`domain`. |
| `wc_yappy_ipn_url` | Override the IPN URL. |
| `wc_yappy_create_order_args` | Adjust the create-order payload. |
| `wc_yappy_request_args` | Adjust `wp_remote_post()` arguments. |
| `wc_yappy_status_poll_attempts` / `wc_yappy_status_poll_interval` | Tune the post-payment poll. |

## Development

```bash
composer install
composer test    # PHPUnit
composer lint    # php -l over every file
```

The unit suite covers the pieces where a mistake is expensive and silent: signature
verification, the reference codec, the amount split, phone normalisation and the status
vocabulary. Those classes carry no WordPress dependency, so the suite runs without a
WordPress installation.

## Not implemented

* **Refunds.** The Botón de Pago integration exposes no refund endpoint; refunds are done in
  Yappy Comercial. Rather than pretend otherwise, the gateway does not declare `refunds`
  support.
* **Subscriptions / pre-orders / saved payment methods.** Yappy confirms each payment
  interactively in the app, so there is nothing to tokenise.

## Provenance of the API contract

`assets/images/yappy.svg` is a neutral placeholder, **not** the official Yappy logo — replace
it, or filter `wc_yappy_icon`, with the artwork from the Banco General brand kit. The checkout
button itself is rendered by Yappy's own web component and always carries official branding.

The integration contract above was reconstructed from three independent sources that agree on
every field, because the official documentation page was not reachable from the environment
this plugin was written in:

* [`Lawiet/laravel-yappy-checkout-v2`](https://github.com/Lawiet/laravel-yappy-checkout-v2) —
  states it is based on the PHP library from the new-integration page.
* [`@devhubpty/yappy`](https://www.npmjs.com/package/@devhubpty/yappy) — states its types are
  derived from the official Banco General documentation.
* Published extracts of the official page itself (endpoint paths, CDN URL, the four button
  events, the status codes).

**Before going live, verify the payload against the official documentation** at
<https://www.yappy.com.pa/comercial/desarrolladores/boton-de-pago-yappy-nueva-integracion/>
and run a sandbox transaction end to end. The one field where the sources differ is
`paymentDate`: the plugin sends the `epochTime` value Yappy itself returned in step 1, which
sidesteps both the unit question and any clock skew, and falls back to `time()` only if Yappy
omits it.

## License

GPL-2.0-or-later.
