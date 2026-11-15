# Coino Live Crypto Payment Gateway for Easy Digital Downloads

A free WordPress plugin that lets your Easy Digital Downloads store accept crypto payments, including USDT, USDC, Bitcoin, Ethereum, TRON, TON and Solana. Customers pay on the Coino Live payment page. Orders update automatically, and Easy Digital Downloads delivers the receipt and download links as usual.

**Version 1.0.0** · requires Easy Digital Downloads 3, WordPress 6.7+, PHP 8.0+ · License: GPL-2.0-or-later

- Download the ready-to-install zip: [coinolive-edd-1.0.0.zip](https://coino.live/downloads/coinolive-edd-1.0.0.zip)
- API reference: [coino.live/api-guide](https://coino.live/api-guide) · Support: [support@coino.live](mailto:support@coino.live)

## Features

- **117 assets on 11 networks:** Bitcoin, Litecoin, Ethereum, BNB Smart Chain, opBNB, Polygon, Base, Arbitrum, TRON, TON and Solana. USDT and USDC are available on most of these networks.
- **Pricing:** the plugin is free. Coino Live charges a 0.25% platform fee per payment. Blockchain network fees are separate.
- **No KYC:** sign up at [coino.live](https://coino.live) with an email and password.
- **Withdraw to your own wallet:** payments are credited to your Coino Live balance. You withdraw them from the Coino Live dashboard.
- **Your shop currency:** prices in USD, EUR, GBP, TRY, BRL, RUB and more are converted at the current rate.
- **Status verified through the API:** order status always comes from the Coino Live API, never from the content of a webhook.

## How crypto checkout works

The customer chooses "Pay with crypto" at checkout, picks a coin and network on the Coino Live payment page and sends the amount shown. The order then updates by itself:

- **Processing** as soon as the payment is seen on the network.
- **Completed** once it is confirmed. Easy Digital Downloads sends the receipt and the download links as usual.
- **On hold** if only part of the amount arrived. The customer is asked to send the rest, and a note is added to the order.
- **Failed** if the payment window closes unpaid.

In WordPress, **Downloads > Coino Live** shows your balance and every payment with its transactions.

## Installation

1. Upload the plugin (**Plugins > Add New > Upload Plugin**) and activate it. Easy Digital Downloads 3 must be active.
2. Sign up at [coino.live](https://coino.live). In your Coino Live dashboard, open **Developers** and create an API key for your gateway.
3. Go to **Downloads > Settings > Payments** and enable "Coino Live (crypto)" under Active Gateways. Then open the "Coino Live (crypto)" section, paste the key and save. The page confirms the connection.

There is nothing else to configure. The webhook URL (`/wp-json/coinolive-edd/v1/webhook`) is sent with every payment.

## FAQ

**Which coins are not accepted?**
Dogecoin and Bitcoin Cash are not accepted.

**Is KYC required?**
No. An email and a password are enough to sign up.

**What if webhooks cannot reach my site?**
Open orders are still checked every five minutes and when the customer returns to your shop.

## Changelog

### 1.0.0
- First release for Coino Live API v2.

## License

GPL-2.0-or-later. See https://www.gnu.org/licenses/gpl-2.0.html
