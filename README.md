<h1 align="center">PaymentHub Gateway for WHMCS</h1>
<h3 align="center">Accept Bitcoin (BTC) & USDT (TRC-20) Payments in WHMCS</h3>

<p align="center">
  <img src="https://img.shields.io/badge/Version-1.1.0-blue?style=flat-square" alt="Version" />
  <img src="https://img.shields.io/badge/WHMCS-8.0+-green?style=flat-square" alt="WHMCS" />
  <img src="https://img.shields.io/badge/PHP-7.4+-purple?style=flat-square" alt="PHP" />
  <img src="https://img.shields.io/badge/License-MIT-yellow?style=flat-square" alt="License" />
</p>

<p align="center">
  A WHMCS payment gateway module that integrates with <a href="https://www.paymenthub.net">PaymentHub</a> to accept cryptocurrency payments directly on your invoices.
</p>

---

## Features

- **Bitcoin (BTC) Payments** — Accept BTC payments with automatic blockchain confirmation
- **USDT (TRC-20) Payments** — Accept Tether on the Tron network
- **Multi-Coin Mode** — Let customers choose their preferred cryptocurrency at checkout
- **Automatic Invoice Marking** — Invoices are marked as paid automatically via webhooks
- **Secure Webhooks** — HMAC-SHA256 signature verification on all callbacks
- **Duplicate Protection** — Built-in duplicate transaction detection
- **Beautiful Pay Button** — Styled "Pay with Crypto" button on invoice pages
- **Full Logging** — All transactions and webhook events logged in WHMCS Gateway Log

---

## Requirements

- WHMCS 8.0 or higher
- PHP 7.4+ with cURL extension enabled
- Active [PaymentHub](https://www.paymenthub.net) merchant account with API key

---

## Installation

### 1. Upload Files

Copy the module files into your WHMCS installation directory:

```
whmcs/
└── modules/
    └── gateways/
        ├── paymenthub.php
        └── callback/
            └── paymenthub.php
```

### 2. Activate the Gateway

In WHMCS Admin, navigate to:

**Configuration > System Settings > Payment Gateways > All Payment Gateways**

Find **"PaymentHub - Crypto Payments"** and click **Activate**.

### 3. Configure Settings

| Field | Description |
|-------|-------------|
| **API URL** | Your PaymentHub instance URL (default: `https://www.paymenthub.net`) |
| **API Key** | Merchant API key from your PaymentHub dashboard |
| **Webhook Secret** | Secret key for verifying webhook signatures |
| **Accepted Coin** | `BTC`, `USDT`, or `All` (customer chooses at checkout) |
| **Fiat Currency** | Currency code matching your WHMCS currency (e.g. `USD`, `EUR`, `BDT`) |

### 4. Set Up Webhook

In your PaymentHub merchant settings, set the webhook URL to:

```
https://your-whmcs-domain.com/modules/gateways/callback/paymenthub.php
```

---

## How It Works

```
Customer Views Invoice
        │
        ▼
Clicks "Pay with Crypto" Button
        │
        ▼
Module Creates Invoice on PaymentHub (API)
        │
        ▼
Customer Redirected to PaymentHub Checkout
        │
        ▼
Customer Pays with BTC or USDT
        │
        ▼
PaymentHub Sends Webhook to WHMCS
        │
        ▼
WHMCS Marks Invoice as Paid ✅
```

---

## Webhook Events

| Event | Action |
|-------|--------|
| `invoice.paid` | Marks WHMCS invoice as paid and records the transaction |
| `invoice.confirming` | Logged — payment detected, awaiting blockchain confirmations |
| `invoice.expired` | Logged — no payment received before expiry |
| `invoice.underpaid` | Logged — partial payment received |
| `invoice.created` | Logged — informational |
| `invoice.coin_selected` | Logged — customer selected a coin in multi-coin mode |

---

## Security

- **HMAC-SHA256** signature verification on all webhook payloads
- **Duplicate transaction detection** prevents double-processing
- **Amount validation** with tolerance ensures payment matches invoice
- **HTTPS/SSL** verification on all API communication
- **Input sanitization** on all output to prevent XSS

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "Unable to create crypto payment" | Verify your API key and ensure the PaymentHub API URL is reachable |
| Payments not marking as paid | Check webhook URL is correct and webhook secret matches |
| Signature verification failed | Double-check the webhook secret in both PaymentHub and WHMCS |
| Gateway not appearing | Ensure files are uploaded to the correct directory and module is activated |

**Gateway Logs:** WHMCS Admin > Utilities > Logs > Gateway Log

---

## File Structure

```
PaymentHub-Gateway-For-WHMCS/
├── README.md
├── LICENSE
└── modules/
    └── gateways/
        ├── paymenthub.php          # Main gateway module
        └── callback/
            └── paymenthub.php      # Webhook handler
```

---

## Support

- **PaymentHub Website:** [https://www.paymenthub.net](https://www.paymenthub.net)
- **Issues:** [GitHub Issues](https://github.com/asrtech-bd/PaymentHub-Gateway-For-WHMCS/issues)
- **Developer:** [ASR Tech](https://github.com/asrtech-bd)

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

<p align="center">
  Made with ❤️ by <a href="https://github.com/asrtech-bd">ASR Tech</a>
</p>
