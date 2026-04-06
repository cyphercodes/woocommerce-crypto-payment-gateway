=== Cyphercodes Crypto Gateway for WooCommerce ===
Contributors: rayansalhab
Tags: cryptocurrency, bitcoin, payment gateway, woocommerce, crypto
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept cryptocurrency payments in your WooCommerce store via 0xProcessing. Supports Bitcoin, Ethereum, USDT, and 50+ cryptocurrencies.

== Description ==

**Cyphercodes Crypto Gateway for WooCommerce** enables your store to accept cryptocurrency payments through [0xProcessing](https://0xprocessing.com), a secure payment processor supporting 50+ cryptocurrencies.

= Key Features =

* **50+ Cryptocurrencies** — BTC, ETH, USDT (ERC20/TRC20/Polygon), USDC, BNB, SOL, TON, and more
* **HPOS Compatible** — Full support for WooCommerce High-Performance Order Storage
* **Multi-Currency Stores** — Automatic fiat-to-USD conversion for non-USD stores
* **Secure Webhooks** — MD5 signature verification with automatic retry handling
* **Test Mode** — Safely test payments without real transactions
* **Real-Time Updates** — Automatic order status updates via webhooks
* **Underpayment Handling** — Configurable handling of insufficient payments
* **Payment Tracking** — Custom database table for analytics and reporting
* **Theme Customization** — Built-in light/dark/custom presets with CSS custom properties for easy theming

= How It Works =

1. Customer selects "Cryptocurrency via 0xProcessing" at checkout
2. Chooses their preferred cryptocurrency from a searchable dropdown
3. Gets redirected to a secure 0xProcessing payment form
4. Pays via QR code, wallet address, or Web3 wallet (MetaMask, WalletConnect)
5. Order status updates automatically after blockchain confirmation

= Requirements =

* WordPress 6.2+
* WooCommerce 6.0+
* PHP 7.4+
* SSL Certificate (HTTPS)
* [0xProcessing](https://0xprocessing.com) merchant account

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **WooCommerce → Settings → Payments → 0xProcessing Crypto**
4. Enter your Merchant ID, API Key, and Webhook Password
5. Set your webhook URL in the 0xProcessing dashboard:
   `https://yourdomain.com/wp-json/ccgw/v1/webhook`
6. Enable the gateway and start accepting crypto payments

== Frequently Asked Questions ==

= What cryptocurrencies are supported? =

Over 50 cryptocurrencies including Bitcoin (BTC), Ethereum (ETH), Tether (USDT on ERC20, TRC20, and Polygon), USD Coin (USDC), BNB, Solana (SOL), Toncoin (TON), Litecoin (LTC), and many more. The full list depends on your 0xProcessing merchant account configuration.

= Do I need an SSL certificate? =

Yes. HTTPS is required for webhook communication between 0xProcessing and your store.

= Does this work with WooCommerce HPOS? =

Yes. The plugin fully supports High-Performance Order Storage introduced in WooCommerce 7.1.

= How do I test payments? =

Enable **Test Mode** in the plugin settings. You must also be logged into your 0xProcessing merchant account in the same browser. Test payments do not process real funds.

= What happens if a customer underpays? =

The order is placed on hold and the admin receives an email notification. You can then approve or reject the underpayment through your 0xProcessing dashboard.

= Can I customize the checkout appearance? =

Yes. The plugin includes built-in Light, Dark, and Custom theme presets accessible from WooCommerce → Settings → Payments. All colors are defined as CSS custom properties (`--oxp-*`) and can also be overridden in your theme's stylesheet:

`
:root {
    --oxp-accent: #your-brand-color;
}
`

= What happens when I uninstall the plugin? =

All plugin data is cleaned up: the custom database table is dropped, plugin options are deleted, and order meta is removed.

== Screenshots ==

1. Checkout page with cryptocurrency selector
2. Plugin settings page in WooCommerce
3. Order details with payment status

== Changelog ==

= 1.0.0 =
* Initial release
* Fixed-amount payment support
* HPOS compatibility
* Multi-currency support
* Webhook signature verification
* Database payment tracking
* Test mode support
* Active currency filtering
* Configurable order status
* Payment status banners
* Light/Dark/Custom theme presets
* Clean uninstall

== Upgrade Notice ==

= 1.0.0 =
Initial release of Cyphercodes Crypto Gateway for WooCommerce.
