=== Zoho Payments for FluentCart ===
Contributors: zohopayments
Tags: fluentcart, zoho payments, payment gateway, upi, checkout
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Cards, UPI, Net Banking, Wallets (India) and Cards & ACH (US) in FluentCart via Zoho Payments with on-demand OAuth token caching.

== Description ==

**Zoho Payments for FluentCart** integrates Zoho Payments directly into FluentCart with dual-edition support for both **India (`zoho.in`)** and **United States (`zoho.com`)** accounts.

= Key Features =

* **Dual-Edition Support:** Supports both India (`zoho.in`) and US (`zoho.com`) editions with Live and Sandbox modes.
* **On-Demand OAuth 2.0 Token Caching:** Zero hourly cron jobs. Generates access tokens strictly when needed, caches them with mutex locking, and retries automatically on 401.
* **Embedded Zoho Checkout Widget:** Seamless modal checkout with accurate payment method tracking (`UPI`, `Card`, `Net Banking`, `Wallet`, `ACH`).
* **Admin Refunds & Webhook Reconciliation:** Process refunds from FluentCart and sync `payment.succeeded`, `payment.failed`, `refund.succeeded`, and `refund.failed` events via HMAC-SHA256 verified webhooks.
* **Multi-Platform Safe:** Tags sessions with `Method: FluentCart` and cleanly ignores events from other stores sharing the same Zoho account.
* **Order-Safe Uninstall:** Removes all Zoho credentials and plugin transients while preserving FluentCart orders and transactions.

= External Services =

This plugin connects to Zoho Payments services to authenticate your merchant account, initialize checkout sessions, verify transactions, and issue refunds:

* **Zoho Accounts OAuth 2.0 (`https://accounts.zoho.in` and `https://accounts.zoho.com`)** — Used during OAuth setup and on-demand token refresh (sends Client ID, Client Secret, authorization code/refresh token, and Redirect URI).
* **Zoho Payments REST API (`https://payments.zoho.in`, `https://paymentssandbox.zoho.in`, `https://payments.zoho.com`, `https://paymentssandbox.zoho.com`)** — Used for payment sessions, verification, and refunds (sends Account ID, amount, currency, Order ID, and refund details).
* **Zoho Payments Widget SDK (`https://static.zohocdn.com/zpay/zpay-js/v1/zpayments.js`)** — Loaded on checkout to render Zoho's PCI-compliant payment modal.
* **Legal:** [Zoho Terms of Service](https://www.zoho.com/terms.html) | [Zoho Privacy Policy](https://www.zoho.com/privacy.html)

== Installation ==

1. Upload and activate the plugin via **Plugins → Add New → Upload Plugin** (requires FluentCart).
2. Go to **FluentCart → Settings → Payment Settings → Zoho Payments**.
3. Select your **Zoho Edition / Region** (`India` or `United States`).
4. Choose the **Live credentials** or **Test credentials** tab (active mode follows your FluentCart **Store Order Mode**).
5. Enter your **Account ID**, **Zoho Payments API Key**, and **Webhook Signing Key**.
6. Register the displayed **Webhook URL** and required events in Zoho Payments Developer Space.
7. Create an ORG Client at `https://api-console.zoho.com/add?client_type=ORG` using the displayed **Authorized Redirect URI**, save your **OAuth Client ID** and **OAuth Client Secret**, then click **Connect Zoho OAuth**.

== Changelog ==

= 1.0 =
* Initial production release for FluentCart with India & US dual-edition support, on-demand OAuth caching, refunds, and HMAC-verified webhooks.
