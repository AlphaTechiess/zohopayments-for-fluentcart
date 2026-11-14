# Zoho Payments for FluentCart

[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892BF.svg)](https://php.net)
[![FluentCart](https://img.shields.io/badge/FluentCart-Compatible-green.svg)](https://fluentcart.com)
[![License: GPL v3+](https://img.shields.io/badge/License-GPL%20v3%2B-orange.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![Release](https://img.shields.io/badge/version-1.1.5-brightgreen.svg)](https://github.com/fluentcart/zoho-payments/releases)

Accept secure payments across India with **Zoho Payments** directly inside **FluentCart**. Featuring modern embedded checkout, instant UPI (Google Pay, PhonePe, Paytm, BHIM), Credit/Debit Cards, Net Banking, Wallets, automated refunds, and real-time webhook order synchronization.

---

## ⚡ Highlights & Key Features

* **Embedded Payment Card Preview**: Modern, responsive UI displaying available payment options (UPI with official BHIM brand icon, Cards, Net Banking, and Wallets) seamlessly replacing skeleton loaders.
* **Frictionless Modal Checkout**: Seamless in-page payment experience powered by the official Zoho Payments Web SDK (`zpayments.js`).
* **Instant Cancellation Recovery**: Closing the payment pop-up instantly clears overlay loaders and re-enables the checkout button with no frozen states.
* **Direct FluentCart Dashboard Refunds**: Issue full or partial refunds directly from the FluentCart transaction manager with mutex concurrency locks.
* **Enterprise Webhook Engine (IPN)**: Real-time asynchronous payment and refund verification using HMAC-SHA256 signature verification and replay protection.
* **One-Click Zoho OAuth 2.0**: Secure authorization with token encryption (AES-256-CBC) and automated refresh token lifecycle management.
* **Native Admin Copy Buttons**: One-click copying for Authorized Redirect URIs and Webhook Endpoints with required event badges.
* **Store-Managed Subscriptions**: Full compatibility with FluentCart store-managed billing and manual payment links for recurring invoices.
* **Lightweight & High Performance**: Clean native code under 100 KB total size with zero heavy external dependencies.

---

## 💳 Supported Payment Methods & Currencies

| Category | Supported Providers / Methods |
| :--- | :--- |
| **UPI** | Google Pay, PhonePe, Paytm, BHIM, Cred, and all UPI apps |
| **Cards** | Visa, MasterCard, RuPay, Maestro, American Express |
| **Net Banking** | 50+ major Indian banks (HDFC, ICICI, SBI, Axis, Kotak, etc.) |
| **Wallets** | Leading mobile and digital wallets |
| **Currency** | **INR (₹)** - Indian Rupee |

---

## 📋 Requirements

* **WordPress**: 5.6 or higher
* **PHP**: 7.4 or higher (fully compatible with PHP 8.0, 8.1, 8.2, and 8.3)
* **FluentCart**: Installed and active
* **Zoho Payments Account**: Registered merchant account ([Sign up for Zoho Payments](https://payments.zoho.in/))

---

## 🚀 Installation

1. Download the latest release `.zip` from the [Releases](https://github.com/fluentcart/zoho-payments/releases) section.
2. In your WordPress admin dashboard, navigate to **Plugins &rarr; Add New &rarr; Upload Plugin**.
3. Choose the downloaded `.zip` file and click **Install Now**.
4. Click **Activate Plugin**.

---

## ⚙️ Configuration Guide

### Step 1: Create an ORG Client in Zoho API Console
1. Log in to the [Zoho API Console](https://api-console.zoho.in/add?client_type=ORG).
2. Choose **Server-based Applications** (or ORG Client).
3. In **FluentCart &rarr; Settings &rarr; Payment Methods &rarr; Zoho Payments**, copy the **Authorized Redirect URI** using the built-in Copy button.
4. Paste this URL into the **Authorized Redirect URIs** field in the Zoho API Console.
5. Save to generate your **Client ID** and **Client Secret**.

### Step 2: Configure FluentCart Settings
1. Go to **FluentCart &rarr; Settings &rarr; Payment Methods &rarr; Zoho Payments**.
2. Set your environment (**Live** or **Sandbox / Test**).
3. Fill in:
   * **Account ID**: Found in *Zoho Payments &rarr; Settings &rarr; Account Details*.
   * **API Key**: Located in *Zoho Payments &rarr; Developer Space &rarr; API Keys*.
   * **Client ID** & **Client Secret**: From Step 1.
4. Click **Save Settings**.
5. Click **Connect Zoho OAuth** to authorize the connection. The badge will turn green (**Connected**).

### Step 3: Register the Webhook
1. In Zoho Payments, navigate to **Developer Space &rarr; Webhooks**.
2. Click **Add Webhook**.
3. Copy the **Zoho Webhook URL** from the FluentCart settings page and paste it into the webhook URL field.
4. Select the following required events:
   * `payment.succeeded`
   * `payment.failed`
   * `refund.succeeded`
   * `refund.failed`
5. Copy your **Signing Key** from Zoho Payments Developer Space and paste it into the **Signing Key** field in FluentCart settings.
6. Click **Save Settings**.

---

## 🔄 Selling Subscriptions

Zoho Payments integrates with FluentCart's store-managed subscription engine:
1. Navigate to **FluentCart &rarr; Settings &rarr; Store Settings &rarr; Subscriptions**.
2. Set the renewal collection method to **Store-managed billing (Manual Pay Link)**.
3. When recurring renewals are due, FluentCart automatically generates renewal invoices and emails direct, secure Zoho payment links to your customers.

---

## 🔒 Security & Privacy

* **Zero Card Storage**: Payment details are handled entirely via Zoho Payments PCI-DSS compliant checkout modals. Sensitive credit card or banking details never touch your server.
* **Encrypted Secrets**: OAuth refresh tokens and signing keys are encrypted on the server with AES-256-CBC and salt hashing.
* **Tamper Verification**: Webhook payloads are verified against HMAC-SHA256 signatures with a 15-minute timestamp drift window to prevent replay attacks.
* **Zero Bloat Uninstaller**: Deleting the plugin via the WordPress admin triggers `uninstall.php`, safely removing all options, transients, and secrets without leaving database clutter.

---

## ❓ Frequently Asked Questions

#### Does Zoho Payments support currencies other than INR?
Currently, Zoho Payments processes transactions in **INR (₹)** for Indian entities. Transactions in other currencies are safely rejected before widget initialization.

#### What happens if a customer closes the payment window?
FluentCart immediately clears all loading indicators and resets the "Place Order" button. Customers can retry or select another payment method without refreshing the page.

#### Can I issue refunds directly from FluentCart?
Yes. Open any completed order transaction in FluentCart, click **Refund**, specify the amount, and confirm. The refund is processed instantly through Zoho Payments and synchronized.

---

## 📄 License

This plugin is licensed under the [GNU General Public License v3.0 or later](https://www.gnu.org/licenses/gpl-3.0.html).
