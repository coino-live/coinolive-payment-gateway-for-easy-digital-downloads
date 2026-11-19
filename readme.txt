=== Coino Live Crypto Payments for Easy Digital Downloads ===
Contributors: coinolive
Tags: crypto, usdt, bitcoin, payments, easy digital downloads
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDT, Bitcoin, Ethereum, TRON, TON, Solana and more in Easy Digital Downloads.

== Description ==

Customers choose "Pay with crypto" at checkout, pick a coin and network on the Coino Live payment page and send the amount shown. The order updates by itself:

* **Processing** as soon as the payment is seen on the network,
* **Completed** once it is confirmed: Easy Digital Downloads sends the receipt and the download links as usual,
* **On hold** if only part arrived (the customer is asked to send the rest), with a note,
* **Failed** if the payment window closes unpaid.

Payments are credited to your Coino Live balance; withdraw to your own wallet from the Coino Live dashboard. Downloads > Coino Live shows your balance and every payment with its transactions.

* Prices in your shop currency (USD, EUR, GBP, TRY, BRL, RUB and more) are converted at the current rate.
* Secure by design: order status always comes from the Coino Live API, never from the content of a webhook.

== Installation ==

1. Upload the plugin (Plugins > Add New > Upload Plugin) and activate it. Easy Digital Downloads 3 must be active.
2. In your Coino Live dashboard, open Developers and create an API key for your gateway.
3. Downloads > Settings > Payments: enable "Coino Live (crypto)" under Active Gateways, then open the "Coino Live (crypto)" section, paste the key and save. The page confirms the connection.

Nothing else to configure: the webhook URL (`/wp-json/coinolive-edd/v1/webhook`) is sent with every payment. If webhooks cannot reach your site, open orders are still checked every five minutes and when the customer returns to your shop.

== External services ==

This plugin connects to the Coino Live API (https://coino.live/api/v2), the crypto payment service it is built for. It cannot work without it.

* When a customer chooses Coino Live at checkout, it sends the order total and store currency, the order number and a short description ("Order #… at your store name"), the customer's email, the return and checkout URLs, the payment window, the Easy Digital Downloads order ID, your site address and the webhook URL.
* To update orders (after a webhook, when the customer returns, and every five minutes for open orders), it reads the payment by its ID.
* It reads the list of supported currencies (at most every 12 hours) to check that your store currency can be used. The Downloads > Coino Live page reads your balances and this store's payments.

Every request is authenticated with the API key you enter. The service is provided by Coino Live: [Terms of Service](https://coino.live/terms-of-service), [Privacy Policy](https://coino.live/privacy-policy).

== Changelog ==

= 1.0.1 =
* Tested with WordPress 7.1.
* Error messages are escaped where they are created.
* The readme lists what the plugin sends to the Coino Live API (External services).
* Plugin URI points to the plugins section of the Coino Live API guide.
* Translations load on init; the WordPress.org package gets them from translate.wordpress.org.
* A website address entered as the API URL now gives a clear error instead of an empty one.

= 1.0.0 =
* First release for Coino Live API v2.
