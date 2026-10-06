# App Privacy (Apple) and Data safety (Google Play)

These answers come from the app source as of version 0.1.0. When the code changes, re-check them and get legal sign-off.

## What the app actually sends or stores

| Data | Where in code | Sent to | When |
|---|---|---|---|
| Name, email, password, country of residence, entity type (individual / institution), disclosure and terms consent | `app/(auth)/register.tsx` → `POST /auth/register` | ReserveChain API | Registration (optional; guests can browse public data) |
| Email + password; TOTP or recovery code | `login.tsx`, `mfa.tsx` → `/auth/login`, `/auth/mfa/verify` | ReserveChain API | Sign-in. The password is never stored on the device |
| Display name, language preference | `PATCH /me` | ReserveChain API | Profile edit / language change |
| User/account ID, profile, eligibility status | `GET /me` (read only) | — (received from the server) | Signed in |
| Access and refresh tokens | `src/storage/secure.ts` (Keychain `WHEN_UNLOCKED_THIS_DEVICE_ONLY` / Android Keystore) | Stored on device; sent as bearer token to the API | Signed in |
| Expo push token + platform | `src/notifications/push.ts` → `POST /me/devices` | ReserveChain API (delivered through Expo push / APNs / FCM) | Only after the user grants notification permission |
| Support subject + message | `app/support.tsx` → `POST /support` | ReserveChain API | When the user sends a support request |
| Notification read state | `POST /me/notifications/{id}/read` | ReserveChain API | When the user opens a notification |
| Disclosure acknowledgement version, language, biometric on/off | `PreferencesProvider` (on-device secure storage) | Not sent | Local only |
| Biometric check (Face ID / fingerprint) | `expo-local-authentication` | Not sent; the OS returns pass or fail only | Optional |
| Password + typed `DELETE` for account deletion | `app/delete-account.tsx` → `POST /me/delete` | ReserveChain API | Only when the user deletes the account; afterwards tokens and the biometric opt-in are wiped on the device |
| OTA update check (runtime version, platform, channel, random install ID) | `expo-updates` | Expo (EAS Update) service provider | App launch |

**Not present:** analytics SDKs, advertising SDKs or IDFA/AAID, crash reporting, location, contacts, photos, camera (QR codes are only *displayed*), microphone (blocked), payment data, KYC documents (no document upload in the app), and cross-app tracking. **Tracking: No.** No App Tracking Transparency prompt is needed.

## Apple: App Privacy ("nutrition label")

Answer "Yes, we collect data from this app". For every type: **Used for tracking: No.**

| Data type (Apple category) | Collected | Linked to user | Purposes |
|---|---|---|---|
| Contact Info: **Name** | Yes | Yes | App Functionality |
| Contact Info: **Email Address** | Yes | Yes | App Functionality (account, sign-in, service emails) |
| Contact Info: **Other User Contact Info** (country of residence) | Yes | Yes | App Functionality (jurisdiction eligibility) |
| Identifiers: **User ID** | Yes | Yes | App Functionality |
| Identifiers: **Device ID** (push token; install ID for updates) | Yes | Yes (push token) | App Functionality |
| User Content: **Customer Support** | Yes | Yes | App Functionality |
| Other Data: **Other Data Types** (entity type, language preference, consent records) | Yes | Yes | App Functionality |
| Usage Data: Product Interaction | No* | — | — |
| Location, Health, Financial Info, Contacts, Browsing/Search History, Sensitive Info, Purchases, Diagnostics | No | — | — |

\*Notification read receipts are functional state. Declare "Product Interaction · App Functionality" only if counsel wants the most conservative reading.

Settings in App Store Connect: Privacy Policy URL `https://reservechain.io/legal/privacy/`. Account deletion is available in
the app (Account → Danger zone → Delete account, guideline 5.1.1(v)) and on the web at https://reservechain.io/support/delete-account/.

## Google Play: Data safety form

| Question | Answer |
|---|---|
| Does your app collect or share any of the required user data types? | Yes |
| Is all user data encrypted in transit? | Yes (HTTPS/TLS only) |
| Do you provide a way for users to request that their data is deleted? | **Yes.** In the app: Account → Danger zone → Delete account (`POST /me/delete`). Delete account URL (Data safety → Account deletion): **https://reservechain.io/support/delete-account/** |
| Data deletion scope | Profile, credentials, 2FA and recovery codes, sessions, push devices and notifications are erased. Where records must be kept by law (eligibility checks, audit, support), the account is **anonymised** and those records are kept only for the required retention period. Declare this under "some data may be retained" |
| Data shared with third parties? | No. Expo, APNs and FCM are service providers that process data for us, which does not count as "sharing" |

| Category → type | Collected | Shared | Optional? | Purposes | Processed ephemerally? |
|---|---|---|---|---|---|
| Personal info → **Name** | Yes | No | Optional (guest browsing needs no account) | Account management, App functionality | No |
| Personal info → **Email address** | Yes | No | Optional | Account management, App functionality, Developer communications | No |
| Personal info → **User IDs** | Yes | No | Optional | Account management, App functionality | No |
| Personal info → **Other info** (country of residence, entity type, language, consent records) | Yes | No | Optional | Account management, App functionality, Fraud prevention / security & compliance (eligibility) | No |
| Messages → **Other in-app messages** (support requests) | Yes | No | Optional | App functionality (customer support) | No |
| Device or other IDs (push token, update install ID) | Yes | No | Optional (push needs consent) | App functionality | No |
| Location, Financial info, Health, Photos/videos, Audio, Files, Calendar, Contacts, App activity, Web browsing, App info & performance | No | — | — | — | — |

Security practices: data encrypted in transit; tokens in Keystore/Keychain; MFA available; `android.allowBackup=false`.
Do **not** tick "Committed to Play Families Policy" (the app is not for children).
