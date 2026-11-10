# Security Policy — Peakopay

## Supported versions

Only the latest released versions of each component receive security fixes:

| Component | Version |
|---|---|
| Backend | 2.2.0 |
| WooCommerce plugin | 1.2.0 |
| Android app | 2.3.1 (versionCode 22) |

## How Peakopay is built for safety (summary)

- **Encryption at rest:** bank card numbers, connection secrets and 2FA
  secrets are encrypted with AES-256-GCM (Sodium `secretbox` or OpenSSL).
  API keys and recovery codes are stored **hashed** only.
- **Wire integrity:** every webhook and device/API request is signed with
  HMAC-SHA256 and protected against replay (timestamp + nonce + idempotency).
- **Web safety:** CSRF tokens on all admin forms, session regeneration on
  login and privilege changes, rate limiting on login/2FA/API, strict
  security headers (CSP with per-request nonce).
- **Money safety:** unique payment amounts, transactional matching with row
  locking, idempotent order completion, clearly-labelled test mode.
- **2FA:** standard offline TOTP (RFC 6238) with locally generated QR codes,
  one-time recovery codes (hashed), admin-initiated reset with mandatory
  reason + session revocation + audit trail.

Full threat model and details (Persian): [`docs/SECURITY.md`](docs/SECURITY.md).

## Reporting a vulnerability

Please report vulnerabilities **privately** — do not open a public issue.

- Use GitHub's **"Report a vulnerability"** feature (Security → Advisories) on
  this repository, or
- contact the maintainer directly via the email listed in the GitHub
  organization profile.

Include reproduction steps and affected versions. You will receive an
acknowledgement within 72 hours. Please avoid public disclosure until a fix
is released. We credit reporters unless they prefer to stay anonymous.

## Never commit secrets

`config/config.php`, `.env`, the Android keystore (`android/keystore/`,
`keystore.properties`) and `local.properties` are **not** part of the
repository (see `.gitignore`). If a real credential was ever committed,
rotate it — removing it from the tip does not remove it from history.
