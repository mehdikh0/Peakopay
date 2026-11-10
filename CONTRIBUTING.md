# Contributing to Peakopay

Thanks for your interest in improving Peakopay! Before you start, please read
this short guide.

## Project constraints (important)

Peakopay runs on cheap shared hosting (cPanel) and on phones with poor
connectivity. These constraints are deliberate — please respect them:

- **Backend: pure PHP 8.1+, no Composer, no framework, no Node.js, no Redis,
  no external services.** Everything must keep working on a plain LAMP host.
- **No runtime dependency on international internet** — payment matching,
  TOTP codes and QR codes are all generated/verified locally. Never introduce
  a call to an external API (QR service, OAuth, CDN, …) into a runtime path.
- **Persian-first UI** — user-facing strings are Persian and RTL. Keep the
  existing style (Persian digits via the existing helpers, consistent design).
- **Android:** Kotlin + Jetpack Compose, Material 3; keep minSdk 26 and the
  offline-first architecture (Room outbox + WorkManager retries).
- **WooCommerce plugin:** GPL-2.0-or-later, no external PHP dependencies.

## Before you submit

1. **Run the full test suite** (requires PHP 8.1+ and a local MySQL/MariaDB):
   ```bash
   php tests/run.php
   ```
   All 745 checks must pass. If you add a feature, add tests for it in the
   same style (tiny `t()`/`eq()` assertions, no PHPUnit).
2. **Security review your own change:**
   - No secrets, keys, passwords, or personal data in code, tests, or logs.
   - New admin/API endpoints need CSRF or HMAC protection and rate limiting.
   - Anything touching money must be transactional and idempotent.
3. **Keep diffs small and focused** — one logical change per PR.

## Pull requests

- Fork, create a feature branch, and open the PR against `main`.
- Describe **what** and **why**; list how you tested it.
- Fill in the pull request template (see
  `.github/pull_request_template.md`).

## Reporting bugs

Use the issue templates (`.github/ISSUE_TEMPLATE/`). Include: component
(backend / plugin / app), versions, PHP/MySQL/Android versions, expected vs
actual behaviour, and logs from `storage/logs/` if relevant. Never include
real card numbers, passwords, or API keys in an issue.

## Licensing

By contributing you agree that your contributions are licensed under the
GNU General Public License v2.0 or later, like the rest of the project.
