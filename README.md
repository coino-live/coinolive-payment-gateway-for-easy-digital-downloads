# Coino Live crypto payments for Easy Digital Downloads

Accept USDT, Bitcoin, Ethereum, TRON, TON, Solana and more in Easy Digital Downloads.

**Version 1.0.0** · requires Easy Digital Downloads 3, WordPress 6.7+, PHP 8.0+

- Download the ready-to-install zip: [coinolive-edd-1.0.0.zip](https://coino.live/downloads/coinolive-edd-1.0.0.zip)
- API reference: [coino.live/api-guide](https://coino.live/api-guide) · Support: [support@coino.live](mailto:support@coino.live)

## Description

Customers choose "Pay with crypto" at checkout, pick a coin and network on the Coino Live payment page and send the amount shown. The order updates by itself:

- **Processing** as soon as the payment is seen on the network,
- **Completed** once it is confirmed: Easy Digital Downloads sends the receipt and the download links as usual,
- **On hold** if only part arrived (the customer is asked to send the rest), with a note,
- **Failed** if the payment window closes unpaid.

Payments are credited to your Coino Live balance; withdraw to your own wallet from the Coino Live dashboard. Downloads > Coino Live shows your balance and every payment with its transactions.

- Prices in your shop currency (USD, EUR, GBP, TRY, BRL, RUB and more) are converted at the current rate.
- Secure by design: order status always comes from the Coino Live API, never from the content of a webhook.

## Installation

1. Upload the plugin (Plugins > Add New > Upload Plugin) and activate it. Easy Digital Downloads 3 must be active.
2. In your Coino Live dashboard, open Developers and create an API key for your gateway.
3. Downloads > Settings > Payments: enable "Coino Live (crypto)" under Active Gateways, then open the "Coino Live (crypto)" section, paste the key and save. The page confirms the connection.

Nothing else to configure: the webhook URL (`/wp-json/coinolive-edd/v1/webhook`) is sent with every payment. If webhooks cannot reach your site, open orders are still checked every five minutes and when the customer returns to your shop.

## Changelog

### 1.0.0
- First release for Coino Live API v2.

## License

GPL-2.0-or-later. See https://www.gnu.org/licenses/gpl-2.0.html
