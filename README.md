# Peakopay

**Self-hosted card-to-card payments for WooCommerce.**

Peakopay lets a WooCommerce store accept Iranian bank card-to-card transfers
(کارت به کارت) with **automatic, instant verification** — running entirely on
your own cPanel/shared hosting. No SSH, no cron dependency, no Composer, no
external payment gateway or SaaS.

```
WooCommerce order ──► Peakopay checkout page (destination card + exact amount + countdown)
       ▲                                                            │
       │                                                            ▼  customer pays
signed webhook ◄── matching engine ◄── merchant's Android app (bank SMS)
       │
       ▼
order marked "paid" ✅ — the customer's checkout page updates the same second
```

## How it works

1. The customer checks out and gets a Peakopay payment page showing the
   destination card number, an **exact, unique amount** (unique up to 999 rials,
   so same-amount transfers are never confused), and a countdown.
2. The customer pays from their banking app and pastes the reference/tracking
   number (or the transfer is detected automatically).
3. The merchant's Android app receives the **bank deposit SMS** on the merchant's
   phone and delivers it — HMAC-signed — to the backend.
4. The backend matches the deposit to the pending order (card + amount + time
   window), marks the order as paid, and fires a **signed webhook** to WooCommerce.
   The customer's page updates instantly. Verification happens **in-request** —
   it never waits for a cron job.

## Main features

- **7-step web installer** (`/install`) — no terminal, no manual SQL; tables and
  migrations are applied automatically.
- **Zero cron dependency:** payment matching and verification run at the moment
  of payment. An optional maintenance cron (15 min) only expires stale payments
  and cleans up.
- **No framework, no Composer, no Node.js** — pure PHP 8.1+ with PDO.
- **Serious security:** AES-256-GCM encryption (Sodium or OpenSSL) for cards and
  secrets at rest, HMAC-SHA256 webhooks with replay protection, hashed API keys,
  CSRF protection, rate limiting, transactional locking, and a full audit log.
- **Offline-capable two-factor authentication** for the admin panel: standard
  TOTP (RFC 6238, compatible with Google/Microsoft Authenticator), local QR-code
  generation (pure PHP), one-time recovery codes, admin-initiated reset with
  session revocation. Works with international internet cut off.
- **Unique amounts** per payment to eliminate ambiguous same-amount deposits.
- **Safe test mode:** test payments/transactions are labelled and can never be
  confused with real ones.
- **Persian-first Android app:** short-code pairing or panel-account login,
  offline outbox (Room + WorkManager), always-on foreground service, dashboard
  and notifications — fully RTL, Material 3.
- **Multi-store & team:** one backend can serve several stores with role-based
  access (owner / admin / accountant / operator).
- **Persian RTL admin UI** with a modern design and Persian digits.

## Components

| Component | Path | Current version | Description |
|---|---|---|---|
| Backend + API | [`backend/`](backend/) | 2.2.0 | PHP admin panel, REST API, matching engine, installer |
| WooCommerce plugin | [`woocommerce/`](woocommerce/) | 1.2.0 | Gateway + signed webhooks + blocks checkout support |
| Android app | [`android/`](android/) | 2.3.1 | Merchant SMS receiver (Kotlin, Compose, Material 3) |
| Docs | [`docs/`](docs/) | — | Persian operational documentation |
| Tests | [`tests/`](tests/) | — | 745 unit + end-to-end tests (no PHPUnit needed) |

## Architecture

- **Backend** — tiny MVC: `app/Core` (router, DB, crypto, session, rate limiter,
  audit, TOTP, QR encoder), `app/Models`, `app/Controllers` (admin + public API),
  `app/Views` (RTL templates). Migrations live in `backend/migrations/` and are
  applied automatically by the installer. Deployment needs only PHP + MySQL/MariaDB.
- **WooCommerce plugin** — a gateway class plus signature-verified webhook
  endpoint, connection tester, and per-store configuration. Legacy webhook
  headers (`X-HamrahPay-*`, from the product's previous name) are still accepted
  for servers installed before the rename; they are internal wire identifiers
  only and never shown to users.
- **Android app** — single-activity Jetpack Compose app. SMS receiver → rule-based
  parser (same rule contract as the server) → Room outbox → signed batch upload.
  The device secret never leaves private storage and is excluded from backups.

## Requirements

**Backend (cPanel or any shared hosting):**

- PHP **8.1+** with `pdo_mysql`, `mbstring`, `curl`; `sodium` (or `openssl`)
  for encryption
- MySQL 8+ / MariaDB 10.6+
- HTTPS (recommended/required for a payment flow)
- No shell access, Composer, Node.js, or cron needed

**WooCommerce:** WordPress 6.5+, WooCommerce 8+, PHP 7.4+ (on the WP side).

**Android:** Android 8.0+ (minSdk 26), built with JDK 17+, platform 35.

**Development:** any machine with PHP 8.1+, a MySQL/MariaDB server, and (for the
app) the Android SDK.

## Installation overview

Complete, step-by-step guides:

- 📘 **[INSTALL.md](INSTALL.md)** — English installation guide (backend, plugin, app, dev setup)
- 📗 **[docs/INSTALL-FA.md](docs/INSTALL-FA.md)** — راهنمای نصب فارسی

In short: upload the backend ZIP to your host, create a database, open
`/install` and follow 7 steps; install the plugin ZIP in WordPress and connect
it with the store key; install the Android APK and pair it with a short code or
your panel account.

## Configuration

- All backend settings live in the admin UI (Settings) and are stored per store.
- `backend/config/config.sample.php` documents the optional `config.php` keys
  (`app_key`, `base_url`, `db`), and `backend/.env.example` shows the optional
  `.env` alternative. The web installer creates this file for you.
- The optional maintenance cron (15 min) is documented in `docs/CPANEL.md`.

## Testing

```bash
# from the repository root (requires a local MySQL/MariaDB)
php tests/run.php
```

One command, no PHPUnit: **745 checks** — unit tests (crypto, signature, parser,
TOTP/QR known-answer vectors, …), plugin scenarios (WordPress stubs), and a full
HTTP end-to-end flow against a real PHP server + database, including checkout,
webhooks, device pairing and 2FA login. See the badge-checkable workflow in
[`.github/workflows/tests.yml`](.github/workflows/tests.yml).

## Security

- Encryption at rest for cards, secrets and 2FA secrets (AES-256-GCM via Sodium
  or OpenSSL); API keys and recovery codes are stored hashed only.
- All webhook/API calls are HMAC-SHA256 signed with timestamp + nonce replay
  protection; admin forms are CSRF-protected; login and 2FA are rate-limited.
- Details and responsible disclosure: [SECURITY.md](SECURITY.md) (summary) and
  [`docs/SECURITY.md`](docs/SECURITY.md) (full Persian document).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Pull requests are welcome — please keep
the no-framework, no-Composer constraints for the backend, and run
`php tests/run.php` before submitting.

## License

Licensed under the **GNU General Public License v2.0 or later**
([LICENSE](LICENSE)). The WooCommerce plugin is GPL-2.0-or-later (see
`woocommerce/peakopay/LICENSE.txt`).

Third-party assets: the Vazirmatn font is used under the SIL Open Font License
(see `docs/` notes); no other runtime dependencies.

## Status

Stable and feature-complete for its intended use, maintained in production.
Current versions: backend **2.2.0**, WooCommerce plugin **1.2.0**, Android
**2.3.1** (versionCode 22). The automated test suite (745 checks) passes on the
released code in this repository.

---

<sub>Peakopay was formerly known as HamrahPay / همراه‌پی; the rename is complete
in all user-facing surfaces. A few internal wire identifiers (legacy webhook
headers, channel IDs) are kept for backward compatibility with already-installed
servers and are documented in `docs/dev-history/`.</sub>
