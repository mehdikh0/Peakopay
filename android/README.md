# Peakopay — Android App

Merchant app for receiving bank card-to-card deposit SMS notifications and delivering signed
transactions to the Peakopay backend. Fully Persian (RTL), Material 3, single-activity Compose.

> 📘 **Full documentation (Persian):** [`docs/ANDROID.md`](../docs/ANDROID.md) —
> features, architecture, permissions, service behaviour, pairing and account login.
> Permissions reference: [`docs/ANDROID-PERMISSIONS.md`](../docs/ANDROID-PERMISSIONS.md)

## Highlights

- **Easy pairing:** short code from the admin panel (`K7M2Q9`-style) or **login with your panel
  account** (email/password) — device activates immediately.
- **Bank SMS reading:** only SMS matching deposit rules (server-driven, JSON) is processed —
  personal, promotional, withdrawal and purchase SMS are never sent.
- **Offline outbox (Room):** if the network is down, SMS stay queued and are retried by
  WorkManager with exponential backoff. No transaction is lost.
- **Security:** every request is signed with HMAC-SHA256 (device secret + timestamp); the device
  secret lives only in private app storage and is excluded from backups.
- **Dashboard & notifications:** today's income, pending payments, unmatched transactions, send
  history, and instant notifications when a deposit is matched to an order.

## Project layout

```
app/src/main/java/ir/peakopay/app/
├── PeakopayApp.kt        Application class + notification channels + WorkManager
├── MainActivity.kt       single activity (Compose)
├── data/                 prefs, rules repository, REST client + HMAC signing, DTOs
├── db/                   Room: offline outbox + transaction history
├── sms/                  rule model, parser (same contract as server), SMS receiver
├── service/              always-on foreground service (optional, default on)
├── work/                 upload worker, heartbeat, boot receiver
└── ui/                   Compose + M3: setup wizard, home, cards, stores, status, support
```

## Building the APK

**Requirements:** JDK 17+ (tested with JDK 21), Android SDK (platform 35 + build-tools 35),
Gradle Wrapper is bundled (no separate Gradle install needed).

```bash
./gradlew assembleRelease     # output: app/build/outputs/apk/release/app-release.apk
./gradlew assembleDebug       # debug build (applicationId suffix .debug)
```

- Release signing is read from `keystore.properties` + `keystore/peakopay-release.keystore`.
  **Keep these two files safe** — without them a future update cannot replace an installed app.
  Never commit them to a public repository.
- Verified toolchain: JDK 21 + Gradle 8.9 + AGP 8.5.2 + Kotlin 2.0.20 (Compose),
  minSdk 26 / targetSdk 35; release APK validated with apksigner / zipalign / aapt.
- `gradle.properties` is tuned for low-memory machines (serial workers, 1280m heap); on a
  stronger machine you can raise `org.gradle.jvmargs` and `org.gradle.workers.max`.

## Prebuilt APKs

Ready-made APKs are distributed separately from the source repository (see the project
`packages/` folder in the internal workspace, or the release page once published). The APK
matches the source in this repository at the tagged version.
