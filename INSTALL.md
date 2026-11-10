# Peakopay — Installation Guide

This guide covers everything a normal user needs: deploying the backend on
cPanel hosting, installing the WooCommerce plugin, and setting up the Android
app. A Persian version is available at [`docs/INSTALL-FA.md`](docs/INSTALL-FA.md).

---

## A. Installing the WooCommerce plugin

**Requirements:** WordPress 6.5+ with WooCommerce 8+ installed and activated.

1. Get the plugin archive `Peakopay-WooCommerce-v1.2.0.zip`.
2. In WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the ZIP file, then **Install Now** and **Activate**.
4. In WooCommerce, open **Payments** (or WooCommerce → Settings → Payments),
   find **Peakopay — Card-to-Card Payments**, and enable it, then click
   **Manage**.
5. **Configure the backend connection:**
   - **Backend URL** — your backend's public address, e.g. `https://pay.example.com`
     (no trailing slash).
   - **Store key** — copy it from the Peakopay admin panel → Settings →
     Connections (it is created when you connect the store in the backend).
6. Save, then use the **Test connection** button on the same page — it must
   report success.
7. Place an internal test order and pay it in **test mode** first (see the
   backend Settings) to verify the full flow before going live.

The plugin needs no other configuration. Webhooks are delivered to the backend
URL automatically and are verified by HMAC signature.

---

## B. Deploying the backend

**Requirements (typical cPanel/shared hosting):**

- PHP **8.1 or newer**, with the extensions `pdo_mysql`, `mbstring`, `curl`,
  and either `sodium` or `openssl` (either one is enough for encryption).
- A **MySQL 8+ / MariaDB 10.6+** database.
- **HTTPS** on the domain you will use (required in practice for payments).
- No SSH, Composer, Node.js or cron is required.

**Steps:**

1. **Upload.** In cPanel → File Manager, upload `Peakopay-Backend-v2.2.0.zip`
   into the folder that will host the backend (for example `public_html/pay/`)
   and extract it there. The folder must directly contain `index.php`,
   `install/` files, `app/`, `migrations/`, etc.
2. **Create the database.** In cPanel → **MySQL Databases**: create a database
   (e.g. `cpaneluser_peakopay`) and a dedicated user, and grant the user all
   rights on that database. Note the three values: database name, user, password.
3. **Run the web installer.** Open `https://your-domain/pay/install` in a
   browser. The installer runs 7 short steps:
   1. Requirements check (PHP version + extensions, writable `storage/`)
   2. Database — enter the name/user/password from step 2
   3. Admin account — your email and a strong password
   4. Store — store name and details
   5. Destination card — the bank card customers will pay to
   6. Connections — connect your WooCommerce store (store key)
   7. Done
   The installer creates `config/config.php`, applies all migrations
   automatically, and locks itself afterwards.
4. **Secure it (recommended).**
   - Make sure the site is served over HTTPS.
   - Keep `debug` off (the installer sets this correctly by default).
   - Optionally enable the maintenance cron (see below).
   - The `storage/` and `config/` directories are already protected by shipped
     `.htaccess` files on Apache/LiteSpeed hosts.
5. **Optional maintenance cron** (not required for payment verification —
   matching always happens instantly). In cPanel → Cron Jobs, add every 15
   minutes:
   ```
   php -q /home/USERNAME/public_html/pay/cron.php
   ```
   If your host has no cron, the backend can also trigger maintenance over
   HTTP with the token from Settings → System (see `docs/CPANEL.md`).
6. **Verify the installation:** open `https://your-domain/pay/admin`, log in
   with the admin account, and check the dashboard loads. In
   Settings → System you can run the built-in diagnostics.

---

## C. Installing the Android app

1. **Get the APK:** `Peakopay-Android-v2.3.1.apk` (release build). On your
   phone, allow installing from this source if asked, and install it.
   Requires **Android 8.0+**.
2. **Connect your account** — two ways:
   - **Pairing code (classic):** in the backend admin panel → Devices, add a
     device and copy the short code shown (e.g. `K7M2Q9`). Enter it in the app.
     The device appears as "pending" in the panel — approve it there.
   - **Login with account (easier):** in the app, choose *login with account*
     and enter your panel email/password. The device activates immediately and
     all stores of your account appear.
3. **Permissions:** the app asks for **SMS** permission (to read bank deposit
   SMS) and **Notifications**. SMS is only read on the merchant's own phone;
   only messages matching the server-defined bank-deposit rules are processed —
   personal, promotional, withdrawal and purchase SMS are never sent anywhere.
   See `docs/ANDROID-PERMISSIONS.md` for the full list and rationale.
4. **Initial synchronization:** after connecting, the app downloads the current
   SMS rules and shows your stores, cards and dashboard. If the phone is
   offline, bank SMS are queued in the app and delivered automatically when
   the connection returns — nothing is lost.
5. **Optional always-on service:** enabled by default; it keeps the connection
   alive with a low-key status notification and can be turned off in the app's
   Status screen.

---

## D. Development setup

1. **Get the source:** clone or download this repository.
2. **Backend & tests** — you need PHP 8.1+ (CLI) with `pdo_mysql`,
   `mbstring`, `curl` and a local MySQL/MariaDB:
   ```bash
   # create the two test databases once (adjust credentials as you like)
   mysql -u root -e "CREATE DATABASE peakopay_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                     CREATE DATABASE peakopay_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                     CREATE USER 'hp'@'localhost' IDENTIFIED BY 'hp_test_pass';
                     GRANT ALL ON peakopay_test.* TO 'hp'@'localhost';
                     GRANT ALL ON peakopay_e2e.* TO 'hp'@'localhost';"

   # run the whole suite (745 checks: unit + plugin scenarios + HTTP e2e)
   php tests/run.php
   ```
   The test runner creates and wipes its own databases (`peakopay_test`,
   `peakopay_e2e` by default — override with `HP_TEST_DB`, `HP_TEST_DB_USER`,
   `HP_TEST_DB_PASS`).
3. **Android app** — open the `android/` folder in Android Studio (or use the
   Gradle wrapper: `./gradlew assembleDebug`). You need JDK 17+ and Android
   SDK platform 35. Release signing expects `keystore.properties` + a keystore
   file which are **not** part of the public repository — see
   [`android/README.md`](android/README.md).
4. **WooCommerce plugin** — the plugin source is `woocommerce/peakopay/`; its
   behaviour is covered by the same test suite via WordPress function stubs
   (`tests/plugin-scenarios/`).

---

*Only procedures that are implemented and tested in this codebase are
documented here. For troubleshooting see [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md).*
