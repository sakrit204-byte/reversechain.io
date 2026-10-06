# ReserveChain mobile app (iOS / Android)

Expo (SDK 57) + React Native 0.86 + TypeScript (strict) + expo-router. The app produces native
iOS and Android binaries through **EAS Build**. It also runs as a web preview (react-native-web) for demos.

> **Compliance first.** The app follows `../SPEC.md`. It never invents data. Unknown values render as
> `Pending — awaiting verification` or `Not yet provided`. The mandatory disclosure and the EU/EEA notice must be
> acknowledged before first use and stay available from every screen. Wallet, purchase, Proof of Reserves,
> redemption, holdings and transactions exist but are **inactive** unless `/config` turns them on.

---

## 1. Quick start

```bash
cd mobile
npm install
cp .env.example .env            # then edit as needed
npm run start:mock              # placeholder data, no backend needed
# or: npm start                 # uses EXPO_PUBLIC_API_URL
```

Press `w` for the web preview, `i` / `a` for a simulator or emulator, or scan the QR code with a development build.

Requirements: Node 24 and npm 11. Building on your own machine needs Xcode 16+ (iOS) or Android Studio (Android). EAS cloud builds need neither.

| Script | Purpose |
|---|---|
| `npm run typecheck` | `tsc --noEmit` (strict, `noUncheckedIndexedAccess`) |
| `npm run lint` | ESLint (`eslint-config-expo` flat config, React Compiler rules) |
| `npm test` | Jest (`jest-expo`) — API client, i18n completeness, feature-flag gating, mock-data honesty, app smoke test |
| `npm run export:web` | Static web export to `dist/` (build smoke test / demo hosting) |
| `npm run doctor` | `expo-doctor` dependency and config validation |

## 2. Environment

The `EXPO_PUBLIC_*` variables are inlined when the bundle is built. Set them in `.env` for local runs, or in the `env` block of each `eas.json` profile.

| Variable | Default | Meaning |
|---|---|---|
| `EXPO_PUBLIC_API_URL` | `http://localhost:8088/wp-json/rc/v1` | Base URL of the WordPress plugin REST namespace `rc/v1` |
| `EXPO_PUBLIC_API_MOCK` | `0` | `1` = built-in placeholder data, no network calls |
| `EXPO_PUBLIC_INACTIVITY_MINUTES` | `5` | Auto sign-out after this much inactivity |
| `EXPO_PUBLIC_HIDE_MOCK_BANNER` | `0` | Store-screenshot builds only: `1` hides the MOCK DATA banner in a mock build. Never set it in EAS profiles or `.env` |

For an Android emulator, use `http://10.0.2.2:8080/...` to reach the host machine's Docker WordPress.

### Mock mode

`EXPO_PUBLIC_API_MOCK=1` swaps the HTTP client for `src/api/mock.ts`, which implements the same `ReserveChainApi` interface.

- Responses have the real shapes, but every fact that does not exist yet is `null`. That covers purity, weights, labs, custodians, vaults, insurers, dates and **all** token parameters. The UI shows these as pending. A test checks this (`__tests__/featureFlags.test.ts`).
- Records are labelled "(mock placeholder)", and an amber **MOCK DATA** banner is shown on every screen.
- The evidence SHA-256 values are real hashes of the literal strings `RESERVECHAIN MOCK PLACEHOLDER DOCUMENT A/B/C`. They fingerprint only placeholder text.
- Sign-in accepts any email and password. MFA accepts any 6-digit code. Recovery codes are visibly fake (`MOCK000001`…).
- All gated modules are off.

## 3. Features → API mapping

| Feature | Screen(s) | Endpoint(s) |
|---|---|---|
| Disclosure + EU/EEA notice (must be acknowledged; versioned) | `app/onboarding.tsx`, `app/disclosure.tsx`, `DisclosureStrip` | `GET /config` (`disclosure`, `eu_notice`) with a bundled verbatim fallback |
| Register (individual / institution, consent, password policy, EU/EEA warning) | `app/(auth)/register.tsx` | `POST /auth/register` |
| Login → TOTP (or 10-char recovery code) | `app/(auth)/login.tsx`, `app/(auth)/mfa.tsx` | `POST /auth/login`, `POST /auth/mfa/verify` |
| MFA setup (otpauth QR via `react-native-qrcode-svg`, recovery codes shown once) | `app/mfa-setup.tsx` | `POST /auth/mfa/setup`, `POST /auth/mfa/enable` |
| Refresh-token rotation (single-flight, one retry) | `src/api/client.ts` | `POST /auth/refresh` |
| Logout (revokes server sessions) | Account tab | `POST /auth/logout` |
| Profile & eligibility pills (KYC/KYB/AML/sanctions/jurisdiction + overall) | `app/(tabs)/account.tsx` | `GET /me`, `PATCH /me` (language sync) |
| Programs with Cu 29 / Ni 28 element tiles and claim-status pills | `app/(tabs)/programs.tsx`, `app/program/[slug].tsx` | `GET /programs`, `GET /programs/{slug}` |
| Digital Asset Passports (fields with pending states, timeline, evidence ledger with SHA-256, Merkle root, completeness, QR, share) | `app/(tabs)/passports.tsx`, `app/passport/[passportNo].tsx` | `GET /passports?program=`, `GET /passports/{passport_no}` |
| Documents library (PDFs open with `expo-web-browser`) | `app/documents.tsx` | `GET /documents` |
| Holdings, transactions, wallet, purchase, Proof of Reserves, redemption — **locked** | `app/(tabs)/assets.tsx`, Overview module grid | `GET /config` modules, `GET /me/holdings`, `GET /me/transactions` |
| Notifications (+ mark read, push registration scaffold) | `app/notifications.tsx` | `GET /me/notifications`, `POST /me/notifications/{id}/read`, `POST /me/devices` |
| Support form (ticket reference) | `app/support.tsx` | `POST /support` |
| Account deletion (danger zone: erased vs kept, password + typed DELETE, final confirmation; then local sign-out and secure-storage wipe) | `app/delete-account.tsx`, Account tab | `POST /me/delete` |
| Biometric unlock (optional) | `app/lock.tsx`, Account → Security | — |

The client sends `client: "ios" | "android"` on login, MFA verify and refresh. Errors are parsed from the WordPress format `{code, message, data:{status, fields}}`. Field errors from registration are shown next to the matching inputs.

### Feature-flag gating (SPEC rule 7)

`src/config/featureFlags.ts` **fails closed**. A module is active only when `/config.modules.<key> === true`, as a real boolean. Missing config, a missing key, a non-boolean value or a network error all mean inactive. Holdings and transactions also require the endpoint's own `enabled: true`. Even when a service module is enabled, this build shows "Enabled by server configuration — flow not yet included". No purchase, wallet or redemption flows are shipped.

## 4. Architecture

```
app/                         expo-router routes (file-based)
  _layout.tsx                fonts, providers, navigation guard, inactivity touch tracking
  index.tsx                  entry redirect: disclosure → lock → auth → tabs
  onboarding.tsx             language + claim-status legend + disclosure acknowledgement
  lock.tsx                   biometric gate
  (auth)/login|register|mfa  authentication
  (tabs)/overview|programs|passports|assets|account
  program/[slug]  passport/[passportNo]  documents  notifications  support  mfa-setup  disclosure
src/
  api/types.ts               app model (nullable facts, ClaimStatus)
  api/normalize.ts           defensive raw → model mapping; unknown statuses DOWNGRADE to "proposed"
  api/client.ts              typed HTTP client (timeouts, WP errors, bearer, refresh rotation)
  api/mock.ts                MOCK implementation of the same interface
  api/index.ts               picks mock vs HTTP at build time
  auth/SessionProvider.tsx   session state machine (loading/signedOut/locked/signedIn), inactivity, AppState
  auth/biometrics.ts         expo-local-authentication wrapper
  config/                    env, compliance copy (verbatim EN), feature flags, /config + preferences providers
  storage/secure.ts          expo-secure-store wrapper + token store
  i18n/                      i18next + expo-localization; en (reference), es, it
  components/                design system: Typography, ElementTile, StatusPill, Hash, LockedFeature, Disclosure…
  theme/                     brand tokens (SPEC) + status colour mapping
  notifications/push.ts      expo-notifications registration scaffold
__tests__/                   jest
```

**Design.** The dark, institutional look follows the SPEC brand tokens: ink, graphite and panel surfaces with copper and nickel accents. Headings use Fraunces. Hashes and IDs use IBM Plex Mono, grouped in 8-character blocks, selectable and copyable. The rest of the UI uses Inter. Program identity is a periodic-table element tile (Cu 29 / Ni 28). Claim-status pills use the SPEC colours: proposed = nickel, in development = amber, pending verification = copper, verified = green, not applicable = muted. There are no gradients and no crypto styling.

**Accessibility.** Every control has a role and label. Pills read "Status: …". Element tiles read "Copper Powder, element symbol Cu, atomic number 29". Live regions announce errors. Touch targets are at least 44–48 pt. Hashes have spoken labels.

**i18n.** EN, ES and IT cover every UI string, including the disclosure. The English disclosure and EU notice are verbatim from SPEC and checked by a test. ES and IT show faithful translations, a note that **the English text is legally authoritative**, and a toggle that reveals the English text. The `i18n.test.ts` test checks that all locales have identical keys and matching `{{placeholders}}`, and that no prohibited promotional terms appear. The chosen language syncs to the server with `PATCH /me`.

## 5. Native builds with EAS

```bash
npm i -g eas-cli
eas login                      # with the ReserveChain-owned Expo account
eas init                       # writes the real projectId → replace REPLACE_WITH_EAS_PROJECT_ID in app.json (extra.eas.projectId and updates.url)
```

Profiles in `eas.json`:

| Profile | Distribution | Notes |
|---|---|---|
| `development` | internal | iOS simulator build + Android APK, `EXPO_PUBLIC_API_MOCK=1`, channel `development` |
| `preview` | internal (ad-hoc / APK) | staging API, channel `preview`, for QA devices |
| `production` | store | AAB / IPA, `autoIncrement` build numbers (remote app version source), channel `production` |

```bash
eas build --profile development --platform all
eas build --profile preview --platform all
eas build --profile production --platform all
```

The bundle identifier and Android package are both `io.reservechain.app`. Update the `EXPO_PUBLIC_API_URL` values in `eas.json` once the staging and production hosts are final.

### Signing

- **iOS:** let EAS manage credentials (`eas credentials`). This needs the ReserveChain Apple Developer team, which creates the distribution certificate and provisioning profiles. Put the team ID in `submit.production.ios.appleTeamId`.
- **Android:** EAS generates and stores the upload keystore. Enrol in **Play App Signing**. Export a backup of the keystore with `eas credentials` and store it in ReserveChain's vault.

### Submit: TestFlight and Google Play internal testing

```bash
# iOS → App Store Connect / TestFlight
eas submit --platform ios --profile production --latest
# Android → Play Console "internal" track (draft)
eas submit --platform android --profile production --latest
```

- **iOS:** create the app record in App Store Connect first (bundle `io.reservechain.app`). Put its numeric ID in `submit.production.ios.ascAppId`. Prefer an App Store Connect API key (`eas credentials` → App Store Connect API Key) over Apple ID login.
- **Android:** create the app in Play Console. Upload the first AAB **manually** once, because Google requires it. Create a Google Cloud service account with Play Console access. Save its JSON key as `secrets/google-play-service-account.json` (git-ignored). Later submissions go to the `internal` track as drafts.

### OTA updates (expo-updates)

`runtimeVersion` uses the `appVersion` policy, and each build profile maps to an update channel of the same name.

```bash
eas update --channel preview --message "Copy fix"
eas update --channel production --message "…"
```

Only JS and asset changes can ship over the air. Any native change, such as a new native module, a permission or an SDK upgrade, needs a version bump and a new store build. **Compliance:** OTA updates must go through the same approval (four-eyes) as a store release. Never use OTA to turn on gated features. Those are controlled server-side by `/config` and need authorization first.

### Push notifications

`src/notifications/push.ts` requests permission and gets an Expo push token, which needs a real EAS `projectId`. It posts the token to `POST /me/devices {push_token}`. If that call fails (404/405, offline), the app keeps the token locally only and does not crash. For Android delivery, add ReserveChain's Firebase project (`google-services.json`, git-ignored, referenced via `android.googleServicesFile`) and upload the FCM V1 service-account key to EAS (`eas credentials`). For iOS, EAS manages the APNs key. Push does not work in Expo Go (SDK 53+), so use a development build.

## 6. Account ownership (important)

All of these accounts must be **created and owned by ReserveChain**, not by the contractor:

- **Apple Developer Program** (organisation enrolment with a D-U-N-S number) and the **App Store Connect** app record
- **Google Play Console** developer account (organisation) and the Google Cloud service account
- **Expo / EAS** organisation (`owner` in `app.json` is set to `reservechain`; change it if the org slug differs)
- **Firebase** project (FCM) for Android push

Contractors should be invited as team members with the least privilege they need. Signing keys, keystores, APNs keys and API keys belong to ReserveChain and are stored in its vault. Ownership transfers of store listings are slow and sometimes impossible, so set this up correctly from the start.

## 7. Store publication checklist

- [x] Final app icon, adaptive icon, monochrome, notification icon, splash and favicon in ReserveChain branding (`assets/images/`, sources in `assets/brand/`). Store copy, privacy answers and screenshots: `store/`.
- [ ] App name and subtitle. Description in EN/ES/IT. The mandatory disclosure must be in the store description, with no "investment/returns/profit" wording.
- [ ] Screenshots (6.9" and 6.5" iPhone, 13" iPad if `supportsTablet` stays on; Android phone and tablet)
- [ ] Privacy policy URL and support URL (hosted on reservechain.io)
- [ ] App Store **App Privacy** details and Google Play **Data safety** form. Data collected: name, email, country, entity type, device push token, support messages. None of it is used for tracking.
- [ ] Export compliance: `ITSAppUsesNonExemptEncryption=false` is set because the app uses standard HTTPS/TLS only. Confirm this with counsel.
- [ ] Age rating questionnaires. The **finance / crypto category declarations**: Apple guideline 3.1.5 and Google Play's Financial Services policy (crypto exchanges and wallets). The app does **not** offer tokens, trading or a wallet. Explain this in review notes and attach the disclosure.
- [ ] Country availability: consider excluding EU/EEA storefronts, in line with the EU/EEA notice, as counsel decides.
- [ ] Reviewer demo account with MFA disabled (or a shared TOTP secret) on a staging backend, plus notes on how to reach each screen.
- [ ] Production `EXPO_PUBLIC_API_URL`, real `projectId`, `ascAppId`, `appleTeamId` and Play service account filled in
- [ ] `npm run typecheck && npm run lint && npm test && npm run doctor` all green, and the production build smoke-tested on physical devices
- [ ] Legal sign-off on every user-facing claim (four-eyes)

## 8. Security notes

- **Token storage:** tokens live in `expo-secure-store`. On iOS that is the Keychain with `WHEN_UNLOCKED_THIS_DEVICE_ONLY`, so they never sync to iCloud and are not in backups. On Android it is Keystore-backed encrypted storage. `android.allowBackup=false`. On web (demo only), tokens stay in memory and are never written to localStorage.
- **Session:** the access token lasts 1 hour and is refreshed silently with the rotated refresh token. Concurrent refreshes are coalesced into one, because an old refresh token is invalid after rotation. If refresh fails, local tokens are wiped and the user is signed out. Logout revokes server sessions with `/auth/logout`.
- **Auto sign-out after inactivity:** the timer runs in the foreground and is also checked when the app returns from the background. Any touch resets it. The default is 5 minutes.
- **Biometric unlock (optional):** turning it on requires a successful biometric prompt. When on, a cold start or a return from more than 30 s in the background shows the lock screen. The device passcode is allowed as a fallback.
- **MFA:** TOTP per RFC 6238. Recovery codes are shown once and need an explicit "I have stored them" confirmation. Codes are never logged.
- **Input and output:** all API data is normalised defensively. Malformed SHA-256 values are dropped rather than displayed, and unknown claim statuses never upgrade.
- **Certificate pinning (recommended for production):** pin the API host's SPKI hashes with a primary and a backup key. Options are `react-native-ssl-public-key-pinning` (config plugin) or a custom native module using TrustKit (iOS) and OkHttp `CertificatePinner` / `network_security_config.xml` (Android). Ship backup pins and keep a rotation runbook so a certificate renewal cannot lock out users.
- **Jailbreak and root detection (recommended):** add a RASP or integrity check, such as `jail-monkey` as a baseline, or **Play Integrity API** and **App Attest / DeviceCheck** with server-side verification for stronger guarantees. Use it to warn the user, and enforce on the server for sensitive operations, rather than relying on a client-side block alone.
- Also recommended: hide sensitive screens in the app switcher (`expo-screen-capture` / a privacy overlay), turn on Android `FLAG_SECURE` for MFA setup and recovery codes, run dependency audits in CI, and lock dependencies with `npm ci`.

## 9. Validation status

- `npx tsc --noEmit`: clean
- `npx eslint .`: clean
- `npx jest`: 36 tests passing across 6 suites. They cover the API client, normalisation, i18n completeness, feature-flag gating, mock-data honesty, the MOCK DATA banner flag, account deletion (client, mock and in-app flow), and an expo-router smoke test of onboarding → guest browsing → passport → locked modules.
- `npx expo-doctor`: 21/21
- `npx expo export --platform web`: succeeds

Native binaries have **not** been built here, because they need the ReserveChain EAS, Apple and Google accounts.
