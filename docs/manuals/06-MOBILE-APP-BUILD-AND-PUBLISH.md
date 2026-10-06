# 06 — Mobile App Build and Publication Manual

**Readers:** the ReserveChain release owner and the developer who builds the app. **Source of truth:** `mobile/README.md`, `mobile/app.json`, `mobile/eas.json`, `mobile/.env.example`, `mobile/store/` (listings, privacy, review pack, screenshots), and the server endpoint `POST /me/delete` (`class-rest.php`).
**Related:** [04 §6 Portal](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md#6-participant-portal) · [07 Operations](07-OPERATIONS-BACKUP-DR-MANUAL.md) · [Index](README.md)

The app is built with Expo (SDK 57), React Native 0.86 and TypeScript, with expo-router. Native iOS and Android binaries are built in the cloud by **EAS Build**.

- Bundle ID and package: `io.reservechain.app`.
- Wallet, purchase, Proof of Reserves, redemption, holdings and transactions are **locked**. The app shows them only as "Not yet available". Even when `/config` enables a module, this build shows "Enabled by server configuration — flow not yet included". No purchase, wallet or redemption flow ships in the app.

---

## Contents

1. [Accounts ReserveChain must own](#1-accounts-reservechain-must-own)
2. [Workstation and EAS setup](#2-workstation-and-eas-setup)
3. [Environment variables](#3-environment-variables)
4. [Versioning](#4-versioning)
5. [Build iOS and Android](#5-build-ios-and-android)
6. [TestFlight and Google Play internal testing](#6-testflight-and-google-play-internal-testing)
7. [Store listing pack](#7-store-listing-pack)
8. [Review notes](#8-review-notes)
9. [Over-the-air (OTA) updates](#9-over-the-air-ota-updates)
10. [Account deletion requirement](#10-account-deletion-requirement)
11. [Release checklist](#11-release-checklist)

---

## 1. Accounts ReserveChain must own

Create every one of these **in ReserveChain's name**, using a ReserveChain email address and company billing. Contractors are invited as members with the least privilege they need, and removed at handover. Store-listing ownership is slow, and sometimes impossible, to transfer later.

| Account | What it holds | Notes |
|---|---|---|
| **Apple Developer Program** (organisation) | Team ID, distribution certificates, provisioning profiles, APNs key | Organisation enrolment needs a **D-U-N-S number**. Allow 1–2 weeks |
| **App Store Connect** app record | Bundle `io.reservechain.app`, numeric **App ID** (`ascAppId`), TestFlight, listing | An **App Store Connect API key** is preferred over Apple ID login for EAS |
| **Google Play Console** (organisation) | App, listing, **Play App Signing** key, tracks | One-time registration fee; organisation verification |
| **Google Cloud service account** | JSON key for `eas submit` to Play | Saved as `mobile/secrets/google-play-service-account.json` (git-ignored); the original goes in the vault |
| **Expo / EAS organisation** | Project ID, builds, update channels, Android upload keystore, credentials | `owner` in `app.json` is `reservechain`. Change it if the organisation slug differs |
| **Firebase project** | `google-services.json`, FCM V1 service-account key for Android push | Upload the FCM key to EAS (`eas credentials`) |

All passwords, keys and the keystore backup are **provided separately** through the ReserveChain vault. Never put them in the repository or in this manual.

---

## 2. Workstation and EAS setup

**Before you start**
- Node 24 and npm 11.
- An EAS account that is a member of the ReserveChain Expo organisation.
- Local simulator builds need Xcode 16+ (macOS) or Android Studio. Cloud builds need neither.

**Steps (once per workstation)**
1. `cd mobile && npm ci`.
2. `npm i -g eas-cli`.
3. `eas login` with your own account, which is a member of the ReserveChain organisation.
4. **First time for the project only:** `eas init`. It prints the real project ID. Replace `REPLACE_WITH_EAS_PROJECT_ID` in `app.json`, in **both** `extra.eas.projectId` and `updates.url` (`https://u.expo.dev/<projectId>`). Commit through a pull request.
5. Fill in `eas.json → submit.production.ios.ascAppId` and `appleTeamId` (replace the `REPLACE_WITH_…` placeholders).
6. Copy the Google service-account JSON to `mobile/secrets/google-play-service-account.json`.
7. Credentials: `eas credentials`.
   - **iOS:** let EAS manage the distribution certificate and profiles under the ReserveChain team. Add the App Store Connect API key.
   - **Android:** EAS generates the upload keystore. **Download a backup** and store it in the vault. Enrol in **Play App Signing** in Play Console.
8. Quality gate: `npm run typecheck && npm run lint && npm test && npm run doctor`. All must be green: 27 tests, and doctor 21/21.

**Local run without a backend:** `cp .env.example .env`, then `npm run start:mock`, then press `w` (web), `i` or `a`. Mock mode shows an amber **MOCK DATA** banner. Any email and password signs in, and any 6-digit MFA code is accepted.

**Local run against the Docker stack:** set `EXPO_PUBLIC_API_URL=http://localhost:8088/wp-json/rc/v1` and `EXPO_PUBLIC_API_MOCK=0`, then run `npm start`. On the Android emulator, use `http://10.0.2.2:8088/…`. Note that `.env.example` says port 8080, but the stack listens on **8088** ([KI-08](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register)).

---

## 3. Environment variables

`EXPO_PUBLIC_*` values are **baked into the bundle at build time**. They are public, so never put a secret in one.

| Variable | Default | Where it is set | Meaning |
|---|---|---|---|
| `EXPO_PUBLIC_API_URL` | `http://localhost:8080/wp-json/rc/v1` | `.env` locally; per profile in `eas.json` | Base URL of the `rc/v1` API |
| `EXPO_PUBLIC_API_MOCK` | `0` (`1` in the `development` profile) | `.env` / `eas.json` | `1` = built-in placeholder data, no network |
| `EXPO_PUBLIC_INACTIVITY_MINUTES` | `5` | `.env` / `eas.json` | Automatic sign-out after inactivity |
| `EXPO_PUBLIC_HIDE_MOCK_BANNER` | `0` | **Only** a temporary `.env.local` for store screenshots | Hides the MOCK banner. **Never** set it in `eas.json` or `.env` |
| `APP_VARIANT` | per profile | `eas.json` | `development` / `preview` / `production` |

Profiles in `eas.json`:

| Profile | API | Distribution | Channel | Output |
|---|---|---|---|---|
| `development` | mock | internal | `development` | iOS simulator build, Android APK |
| `preview` | `https://staging.reservechain.io/wp-json/rc/v1` | internal (ad hoc / APK) | `preview` | QA devices |
| `production` | `https://reservechain.io/wp-json/rc/v1` | store | `production` | AAB / IPA, build number auto-incremented |

When the final hosts change, update the `EXPO_PUBLIC_API_URL` values in `eas.json`, then rebuild. A change to an `EXPO_PUBLIC_*` value needs a new build or an OTA update (§9).

> Staging protected by HTTP basic auth blocks the app's API calls ([07 §3.1](07-OPERATIONS-BACKUP-DR-MANUAL.md#31-staging-deploy)). For `preview` builds, keep staging's API reachable, or test against a separate host.

---

## 4. Versioning

| Value | Where | Who changes it | When |
|---|---|---|---|
| **App version** (`expo.version`, for example `0.1.0`) | `app.json` | Developer, by pull request | Every store release. Use semver: MAJOR.MINOR.PATCH |
| **Build number** (iOS `buildNumber`) / **version code** (Android `versionCode`) | Remote, managed by EAS (`appVersionSource: remote`, `autoIncrement: true`) | EAS, automatically, on each production build | Never edit by hand |
| **Runtime version** | `runtimeVersion.policy = appVersion` ⇒ equals the app version | Follows the app version | Bump the app version for **any native change** |

Rules:
- An OTA update reaches only builds with the **same runtime version**, that is, the same app version (§9).
- New native module, permission, SDK upgrade, or `app.json` native setting → bump the app version (at least MINOR) and make a new store build.
- JavaScript or asset-only change → an OTA update on the same version, or a PATCH bump with a new build.
- Tag every store release in Git: `mobile-v0.1.0`.

---

## 5. Build iOS and Android

**Before you start**
- §2 is completed. The quality gate is green.
- For production, the release checklist (§11) is ticked up to "Build".

**Steps**
1. Check that the `EXPO_PUBLIC_API_URL` of the profile you will use is correct in `eas.json`.
2. Build:
   ```bash
   eas build --profile preview --platform all        # QA builds against staging
   eas build --profile production --platform all     # store builds (AAB + IPA)
   ```
3. Follow the build on expo.dev (ReserveChain organisation → project → Builds).
4. **Preview:** install on QA devices from the build page (QR code). iOS devices must be registered with `eas device:create`.
5. Smoke-test on **physical devices** (§11, "Test").

**Result:** completed builds are listed with their version and build number.

**If something goes wrong**
- *Credentials errors:* run `eas credentials` and check the Apple team or Android keystore.
- *"projectId" missing:* §2 step 4 was not done.
- *Dependency errors:* `npm run doctor`, then align versions with `npx expo install --check`.

---

## 6. TestFlight and Google Play internal testing

### 6.1 iOS → TestFlight

**Before you start:** the App Store Connect app record exists (bundle `io.reservechain.app`), and `ascAppId` is filled in.

**Steps**
1. `eas submit --platform ios --profile production --latest`.
2. In App Store Connect → **TestFlight**, wait for processing. Answer the **export compliance** question: the app uses standard HTTPS/TLS only, and `ITSAppUsesNonExemptEncryption=false` is already set. Counsel confirms.
3. Add **internal testers** (ReserveChain staff), then external testers if needed. External testers require a TestFlight beta review.

### 6.2 Android → Play internal testing

**Before you start**
- The Play Console app exists. **The first AAB must be uploaded manually**, because Google requires it: download the AAB from the EAS build page and upload it under **Testing → Internal testing**.
- The service account has Play Console access.

**Steps**
1. `eas submit --platform android --profile production --latest`. This uploads to the `internal` track as a **draft** (`eas.json → submit.production.android`).
2. In Play Console → **Testing → Internal testing**, review the draft and **Roll out**.
3. Add testers through the tester list or email group.

**If something goes wrong**
- *Play API "insufficient permissions":* grant the service account release permissions in Play Console → Users and permissions.
- *iOS "Missing Compliance":* answer the export question in TestFlight.

---

## 7. Store listing pack

The folder `mobile/store/` contains:

| File | Use it for |
|---|---|
| `listing-en.md`, `listing-es.md`, `listing-it.md` | Name, subtitle / short description, keywords, promotional text, what's new, full description **with the mandatory disclosure**. Lengths are already checked against the store limits |
| `privacy-data-safety.md` | Answers for Apple **App Privacy** and Google Play **Data safety** (data collected: name, email, country, entity type, push token, support messages; no tracking) |
| `review-and-compliance.md` | Blockers, export compliance, Apple age rating, IARC, the financial-features declaration, country availability, the reviewer-notes template |
| `screenshots/ios/` | 6 × 1290×2796 framed screenshots (6.7"/6.9" slot) |
| `screenshots/android/` | 6 × 1080×1920 phone screenshots |

**Steps**
1. Copy the copy texts into App Store Connect (each localisation) and Play Console (**Main store listing** and translations). Do not shorten or remove the disclosure. Do not add "investment", "returns" or "profit" wording.
2. Upload the screenshots. If `supportsTablet` stays `true`, Apple also requires iPad screenshots: produce them with the method in `store/README.md`.
3. Fill in App Privacy and Data safety from `privacy-data-safety.md`.
4. Age rating and IARC: follow `review-and-compliance.md`. Play: *"My app doesn't provide any financial features"*; target audience **18+**; no ads.
5. **Country availability:** decided by counsel. The conservative option is to exclude EU/EEA storefronts until counsel decides.
6. Privacy policy URL: `https://reservechain.io/legal/privacy/`. Support URL: the contact page. Both must be live first.

**Regenerating screenshots:** follow `mobile/store/README.md`:
1. a temporary `.env.local` with `EXPO_PUBLIC_API_MOCK=1` and `EXPO_PUBLIC_HIDE_MOCK_BANNER=1`;
2. `npx expo export --platform web --output-dir dist-store --clear`;
3. serve on port 8098 and capture with Playwright;
4. compose the images on the brand background;
5. **restore `.env.local` afterwards**.

---

## 8. Review notes

Paste the **Reviewer notes template** from `mobile/store/review-and-compliance.md` into App Store Connect → **App Review Information → Notes**, and into Play Console → **App content → App access**. Then complete it:

1. The demo account email and password go **only** into the stores' private sign-in fields. They are provided separately and never written in the notes text.
2. Use a **staging** reviewer account with MFA disabled, or give the TOTP secret privately. Create a **second, disposable account** for reviewers who want to test deletion.
3. State clearly: no tokens, no trading, no wallet, no payments, no in-app purchases. Locked modules are server-controlled.
4. Give the path to each screen and the account-deletion path (§10).
5. Give a ReserveChain contact: name, phone and email, provided separately.

---

## 9. Over-the-air (OTA) updates

`expo-updates` is enabled. It checks on load. Each build profile listens to the channel of the same name.

**What may ship over the air:** JavaScript and asset changes only (text, layout, bug fixes) for the **same app version**. Anything native needs a store build (§4).

**Compliance rules**
- An OTA update goes through the **same four-eyes approval** as a store release: a second person reviews the diff, and there is legal sign-off for any user-facing claim.
- **Never use OTA to turn on gated features.** They are controlled server-side by `/config` and need written authorization ([01 §8.3](01-CMS-ADMIN-MANUAL.md#83-gated-modules)).

**Steps**
1. Merge the approved change. Run the quality gate.
2. Publish to preview first: `eas update --channel preview --message "Copy fix: …"`. Test on preview builds.
3. Publish to production: `eas update --channel production --message "…"`.
4. Record the update ID and message in the release log.

**Roll back:** on expo.dev → Updates, republish the previous update to the channel, or run `eas update --channel production` from the previous Git commit.

---

## 10. Account deletion requirement

Apple guideline 5.1.1(v) and Google Play require **in-app account deletion** and a **web deletion URL**. Both are implemented. Check them before every release.

| Where | How |
|---|---|
| In the app | **Account → Danger zone → Delete account** (`app/delete-account.tsx`). The user enters their current password and types `DELETE`, then confirms. The app calls `POST /me/delete`. On success it signs out and wipes the tokens and the biometric opt-in |
| On the web | `https://reservechain.io/support/delete-account/`. The page must be live before submission. It is also the **Data safety → Delete account URL** |

**What the server does** (`delete_account` in `class-rest.php`):
- revokes every session;
- deletes the MFA secrets, push tokens and language preference;
- deletes the user's notifications;
- anonymises their support messages (`[erased on account deletion]`);
- anonymises rather than erases where a record must be kept by law, for example when KYC checks were started.

**Staff accounts cannot delete themselves:** the call returns `rc_delete_staff`. Staff accounts are closed by an administrator.

**Test before each release**
1. Create a disposable account on staging.
2. Delete it in the app. Check that you are signed out and that the account can no longer sign in.
3. Check that the audit trail on staging has the related entries.

---

## 11. Release checklist

Copy this list into the release ticket. Every box needs a name and a date.

**Prepare**
- [ ] Release scope approved by the ReserveChain release owner.
- [ ] Copy and claims reviewed by a second person and signed off by counsel (four-eyes).
- [ ] App version bumped in `app.json` if this is a store release, or the change is confirmed JS-only for OTA (§4).
- [ ] `EXPO_PUBLIC_API_URL` correct for each profile; real `projectId`, `ascAppId`, `appleTeamId` and the Play service account present.
- [ ] `npm run typecheck && npm run lint && npm test && npm run doctor` all green.

**Build**
- [ ] `eas build --profile preview --platform all` installed on physical devices.
- [ ] Smoke test:
  - [ ] the disclosure acknowledgement appears and the EU/EEA notice is present;
  - [ ] guest browsing of programs and passports works, and `RC-CU-LOT-000001` opens;
  - [ ] the locked modules show "Not yet available";
  - [ ] sign in → MFA → account; eligibility pills;
  - [ ] biometric unlock; automatic sign-out after inactivity;
  - [ ] support form; notifications;
  - [ ] **account deletion** (§10);
  - [ ] EN / ES / IT language switch.
- [ ] `eas build --profile production --platform all` completed.

**Submit**
- [ ] `eas submit` to TestFlight and Play internal; internal testers confirmed.
- [ ] Listings, screenshots, App Privacy / Data safety, age rating / IARC, financial-features declaration, country availability: all checked (§7).
- [ ] Reviewer notes completed; credentials only in the private fields (§8).
- [ ] Submitted for review / promoted to production by the release owner.

**After release**
- [ ] Git tag `mobile-vX.Y.Z`; release log updated with build numbers and any update IDs.
- [ ] Crash-free and review status monitored for 48 h.
