# Store listing (English, reference)

> Compliance: pre-launch app. Store copy must not offer tokens or use investment or financial-performance wording. The
> mandatory disclosure and EU/EEA notice below are verbatim from `src/config/compliance.ts` and are legally
> authoritative in English. Any copy change needs legal sign-off (four-eyes).

| Field | Limit | Value |
|---|---|---|
| Privacy policy URL | — | https://reservechain.io/legal/privacy/ |
| Support URL | — | https://reservechain.io/support/ |
| Marketing URL (optional) | — | https://reservechain.io/ |
| Category (Apple) | — | Primary: Reference · Secondary: Business |
| Category (Google Play) | — | Business |

## Apple App Store

| Field | Limit | Value |
|---|---|---|
| App name | 30 | `ReserveChain` |
| Subtitle | 30 | `Industrial metals, documented` |
| Keywords | 100 bytes | `copper,nickel,metals,registry,passport,assay,traceability,provenance,lot,evidence,documents` |
| Promotional text | 170 | `Pre-launch: browse proposed copper powder and nickel wire programs, Digital Asset Passports and published documents. No tokens are offered or sold.` |
| What's New (1.0) | 4000 | `First release of the ReserveChain registry app: programs, Digital Asset Passports, documents, account and eligibility status, two-factor sign-in. Platform modules remain inactive.` |

## Google Play

| Field | Limit | Value |
|---|---|---|
| App name | 30 | `ReserveChain: Metals Registry` |
| Short description | 80 | `Pre-launch registry for copper powder and nickel wire documentation.` |
| Release notes (1.0) | 500 | same as Apple "What's New" |

## Full description (both stores, ≤ 4000 characters)

```
ReserveChain is a proposed platform for documenting ultra-high-purity copper powder and high-purity nickel wire. The app is a pre-launch registry: it shows what is documented, what is still pending, and the status of every claim.

EVERY CLAIM CARRIES A STATUS
Statements in the app are marked Proposed, In development, Pending verification, Verified or Not applicable. Values that do not exist yet are shown as "Pending — awaiting verification". Nothing is filled in by assumption.

PROGRAMS
Each program is identified by its element tile: Copper Powder (Cu 29) and Nickel Wire (Ni 28). Program pages list each claim with its current status.

DIGITAL ASSET PASSPORTS
Per-lot passports show asset data fields, a lifecycle timeline and an evidence ledger with SHA-256 fingerprints and a Merkle root. Hashes can be copied and checked independently.

DOCUMENTS
Read the published documents library directly from the app.

ACCOUNT AND ELIGIBILITY
Create an account to follow the status of eligibility checks (KYC/KYB, AML, sanctions screening, jurisdiction). Approval of any check does not create an entitlement to participate in any future offering.

SECURITY
Two-factor authentication (TOTP) with one-time recovery codes, optional Face ID / fingerprint unlock, automatic sign-out after inactivity, and session tokens kept in the device's secure storage.

INACTIVE MODULES
Wallet, purchase, Proof of Reserves, redemption, holdings and transactions are shown as locked. They are not available in this app and will only be considered after authorization.

LANGUAGES
English, Spanish and Italian. The English disclosure is the legally authoritative version.

IMPORTANT DISCLOSURE
ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, token allocation or entitlement to participate in any future offering. Any future availability will be subject to the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval.

EU/EEA NOTICE
ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA.

Privacy policy: https://reservechain.io/legal/privacy/
Support: https://reservechain.io/support/
```

Note: the disclosure says "through this website" because it is quoted verbatim from SPEC. If counsel approves an app-specific
variant ("through this app"), update `compliance.ts`, bump `DISCLOSURE_VERSION`, and update all three listings together.

## Screenshot captions (EN, in `screenshots/`)

| # | File | Headline | Sub-line |
|---|---|---|---|
| 1 | `01-onboarding.png` | Every claim carries a visible status | The disclosure and EU/EEA notice are acknowledged before first use. |
| 2 | `02-overview.png` | Industrial metals, documented | Copper powder and nickel wire programs at a glance. |
| 3 | `03-programs.png` | Two proposed programs: Cu 29 · Ni 28 | Each program is identified by its element tile. |
| 4 | `04-passport.png` | Digital Asset Passports | Per-lot evidence fingerprints and Merkle root. Unknown values stay pending. |
| 5 | `05-assets.png` | Locked until authorized | Wallet, purchase and redemption modules are inactive. No tokens are offered. |
| 6 | `06-account.png` | Profile and eligibility | KYC, AML, sanctions and jurisdiction status, with two-factor sign-in. |
