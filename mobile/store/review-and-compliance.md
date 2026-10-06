# Review and compliance pack

## Blockers to fix before the first store submission

1. ~~In-app account deletion (Apple guideline 5.1.1(v))~~ **Resolved.** Account → Danger zone → Delete account
   (`app/delete-account.tsx`). The screen explains what is erased and what may be kept, asks for the current password
   and the typed word `DELETE`, then shows a final confirmation. It calls `POST /me/delete` (bearer,
   `{password, confirm:"DELETE"}`). On success it signs out locally and wipes the tokens and biometric opt-in from secure
   storage. Errors are mapped: 422 `rc_delete_confirm` and 403 `rc_delete_staff`. The server may anonymise rather than
   erase where records must be kept by law. Web deletion URL for Google Play: https://reservechain.io/support/delete-account/
   (the page must be live before submission).
2. Real EAS `projectId`, `ascAppId`, `appleTeamId` and the Play service account (see `../README.md` §5).
3. Privacy policy live at https://reservechain.io/legal/privacy/ and covering the data listed in `privacy-data-safety.md`.

## Export compliance (encryption)

| Question | Answer |
|---|---|
| Does your app use encryption? | Yes, standard HTTPS/TLS through the OS networking stack only |
| Proprietary or non-standard encryption? | No |
| Qualifies for exemption | Yes. Standard encryption in OS / HTTPS (EAR 740.17(b)(1) / Category 5 Part 2 note 4 mass-market exemption) |
| `ITSAppUsesNonExemptEncryption` | `false` (already set in `app.json`) |
| French encryption declaration | Not required (standard HTTPS only) |

SHA-256 is used only to display and compare document fingerprints. That is hashing, not encryption. Counsel should confirm.

## Apple age rating questionnaire (2025+ form)

| Item | Answer |
|---|---|
| Violence (cartoon / realistic / graphic) | None |
| Sexual content, nudity | None |
| Profanity or crude humor | None |
| Alcohol, tobacco, drugs | None |
| Horror / fear themes | None |
| Mature or suggestive themes | None |
| Medical or treatment information | None |
| Simulated gambling / contests | None |
| Gambling (real money) | No |
| Loot boxes | No |
| Unrestricted web access | No (documents open in an in-app browser restricted to the PDF links the API returns) |
| User-generated content / messaging between users | No (support messages go to staff only) |
| Parental controls / age assurance | No |
| Made for Kids | No |
| **Result** | 4+ (Apple may assign a higher rating for finance-adjacent subject matter. Accept 17+/18+ if counsel prefers to signal adult-only eligibility.) |

Apple guideline 3.1.5 (cryptocurrencies) and 5.1.1: the app does **not** offer a wallet, an exchange, trading, mining, ICOs or
token sales. Gated modules are inactive and server-controlled (`/config`). State this in the review notes.

## Google Play content rating (IARC) notes

- Category: **"All Other App Types"** (not Game, not Social).
- Violence, sexuality, language, controlled substances, crude humor: **No** to all.
- Users interact or exchange content: **No**. Shares location: **No**. Digital purchases: **No**. Unrestricted internet: **No**.
- Expected rating: PEGI 3 / ESRB Everyone / USK 0.
- **Financial features declaration** (Policy → App content → Financial features): select *"My app doesn't provide any
  financial features"*. If Play asks about crypto, answer that the app has no crypto exchange, wallet, trading or token
  sale, and that it is an information and registry app. Revisit this before any gated module is turned on.
- Target audience: **18+** only. Ads: **No ads**. Government app: No. News app: No.

## Country availability

The EU/EEA notice says ReserveChain does not currently intend to offer tokens there. The app offers no tokens anywhere, so
listing it in EU/EEA storefronts is a decision for counsel. The conservative option is to exclude EU/EEA storefronts
in App Store Connect (Pricing and Availability) and Play Console (Countries/regions) until counsel decides.

## Reviewer notes template (App Review "Notes" / Play "App access")

```
ReserveChain is a pre-launch information and registry app for proposed industrial-metal programs (copper powder, nickel
wire). It does NOT offer, sell or trade tokens, has no wallet, no exchange, no payments and no in-app purchases.
Modules named Wallet, Purchase, Proof of Reserves, Redemption, Holdings and Transactions are displayed as LOCKED
("Not yet available — subject to authorization") and are controlled server-side; no flow exists in this build.

On first launch the user must acknowledge the mandatory disclosure and EU/EEA notice. Programs, Digital Asset
Passports and Documents are public ("Browse public registry" on the sign-in screen). Sign in to see Account and
eligibility status.

Demo account (provided privately in the App Store Connect / Play Console sign-in fields, not in this text):
  Email:     <provided privately>
  Password:  <provided privately>
  MFA:       disabled for this account  |  or TOTP secret: <provided privately>
Backend:     staging, data is placeholder and clearly marked as pending.

Path to each screen: Overview tab → Programs tab → Passports tab → open RC-CU-LOT-000001 → Assets tab (locked modules) →
Account tab (eligibility, 2FA, biometric unlock, language, support, sign out).
Account deletion: Account tab → Danger zone → Delete account → current password + type DELETE → "Yes, delete permanently".
(Please do not delete the shared demo account; ask us for a second disposable account if you want to test deletion.)
Web deletion request: https://reservechain.io/support/delete-account/
Face ID is optional and used only to unlock an existing session on this device.
Push notifications are optional and only carry account notices.
Contact for review: <name, phone, email of ReserveChain owner>
```
