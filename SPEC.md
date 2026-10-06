# ReserveChain.io — Shared Build Specification (source of truth for all workstreams)

## Contest context (summary)
Institutional RWA tokenization platform for **ultra-high-purity Copper Powder** and **high-purity Nickel Wire**.
Stack required: WordPress + custom CMS (PHP/MySQL), ERC-20 contracts (testnet only), native iOS/Android apps,
EN/ES/IT, institutional whitepaper. Entity is a *proposed Swiss* structure (brief also mentions Estonia — never assert either as formed).

### Non-negotiable compliance rules (apply to EVERY workstream: copy, UI, docs, contracts, app)
1. **Never invent missing information.** No prices, supply, purity numbers, lab names, custodians, insurers, vault
   locations, ratios, yields, dates of launch, audit firms. Where data is absent show an explicit placeholder state:
   `Pending — awaiting <thing>` / `Not yet provided` / `Subject to final approval`.
   Generic industry descriptions are fine (e.g. "copper powder is used in additive manufacturing") but no specs for *our* assets.
2. Language: "proposed", "planned", "in development", "subject to final approval". Never say custody, insurance,
   Proof of Reserves, liquidity, redemption, ownership rights or returns are confirmed. No "investment", "returns", "profit", "APY".
3. Mandatory disclosure (verbatim, shown site-wide + in app + in docs):
   > ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, token allocation or entitlement to participate in any future offering. Any future availability will be subject to the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval.
4. EU/EEA statement: "ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA."
5. Token parameters (price, supply, asset-to-token ratio, ownership rights, redemption thresholds, discounts, liquidity,
   appreciation, allocations) are **never hard-coded** — always configuration, defaulting to unset/zero/disabled.
6. Mainnet deployment is never performed. Testnet only (Sepolia default; Polygon Amoy optional).
7. Wallet, purchase, Proof-of-Reserves and redemption features exist but are **inactive / feature-flagged off** until authorized.

## Signature differentiators (the "why we win")
- **Claim Status System** — every factual claim on the platform carries a status: `proposed` · `in_development` ·
  `pending_verification` · `verified` · `not_applicable`. Rendered as pills; driven by CMS. Makes compliance visible.
- **Digital Asset Passport (DAP)** — per-asset (lot/batch/container/coil) passport with QR code, lifecycle timeline,
  evidence ledger of documents with SHA-256 fingerprints, Merkle root of all evidence, field-level pending states.
- **Client-side Verify** — users drop a document in the browser; it is SHA-256 hashed locally (never uploaded) and matched
  against registry fingerprints via `GET /verify?hash=`.
- **Tamper-evident audit trail** — append-only table; each row stores `prev_hash` + `row_hash` (SHA-256 chain);
  MySQL triggers reject UPDATE/DELETE; admin "Verify chain integrity" tool; chain head can be anchored on-chain via `AuditAnchor` contract.
- **Four-eyes editorial workflow** — Draft → Under Review → Approved → Published → Unpublished → Archived; approver ≠ author.
- **Reserve-gated minting** (contracts) — optional cap: total supply may not exceed attested reserve units × configured ratio (ratio unset ⇒ minting disabled).

## Brand / design tokens (shared by web + app)
- Concept: "assay-grade" — periodic-table element tiles as program identity: **Cu 29** (Copper Powder), **Ni 28** (Nickel Wire).
- Colors: ink `#050C14`, graphite `#141A22`, panel `#1B232D`, line `#2A3542`, paper `#F5F2EC`, text-muted `#8A96A3`,
  copper `#C46A3A` (light `#E39A6B`), nickel `#9FB3C2` (light `#C9D6DF`), signal-green `#3FB37F`, amber `#E0A43A`, red `#D9574A`.
- Status pill colors: proposed=nickel, in_development=amber, pending_verification=copper, verified=green, not_applicable=muted.
- Type: "Inter Tight"/Inter for UI, "IBM Plex Mono" for hashes/IDs/data, "Fraunces" (serif) for display headings.
- Tone: sober, institutional, precise. No rockets, no moons, no neon crypto gradients.

## Monorepo layout
```
reservechain.io/
  SPEC.md
  docker-compose.yml          # local WP + MySQL + phpMyAdmin
  wordpress/plugins/reservechain-core/   # registry, DAP, waitlist, audit, workflow, compliance, REST API, admin
  wordpress/themes/reservechain/         # public site theme
  contracts/                  # Hardhat + OpenZeppelin v5, tests, deploy scripts
  mobile/                     # Expo (React Native, TypeScript) app -> native iOS/Android builds via EAS
  docs/                       # architecture, CMS structure, schedule, manuals, whitepaper
```

## REST API (WordPress plugin) — namespace `/wp-json/rc/v1`
All responses JSON. Public endpoints are read-only and rate-limited. Auth endpoints issue bearer tokens (HMAC-signed, 1h)
+ refresh tokens. MFA = TOTP (RFC 6238).

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/config` | public | `{site_mode, modules:{wallet,purchase,proof_of_reserves,redemption,waitlist,...}:bool, languages:["en","es","it"], disclosure, eu_notice, network:{chain_id,name,token_address|null}}` |
| GET | `/programs` | public | list of programs `{id, slug, symbol:"Cu"|"Ni", atomic_number, name, summary, status, claims:[{label,status,note}]}` |
| GET | `/programs/{slug}` | public | program detail incl. `token_program` (all params nullable) |
| GET | `/passports?program=` | public | list of passports `{id, passport_no, entity_type, program, title, status, merkle_root, updated_at}` |
| GET | `/passports/{passport_no}` | public | full DAP: `{..., fields:[{key,label,value|null,status}], timeline:[{date,event,status}], documents:[{id,title,type,sha256,status,issued_by|null}], custody:[...], merkle_root, qr_url}` |
| GET | `/verify?hash=<sha256>` | public | `{match:bool, document?, passport_no?}` |
| GET | `/documents` | public | public documents library |
| POST | `/waitlist` | public | body `{name,email,country,entity_type:"individual"|"institution",organisation?,interest:["cu","ni"],language,consent_disclosure:true,consent_privacy:true}` → `{ok, status:"pending_confirmation"|"ineligible_jurisdiction"}` |
| POST | `/auth/register` | public | `{email,password,name,country}` |
| POST | `/auth/login` | public | `{email,password}` → `{mfa_required:bool, mfa_token?}` or `{access_token,refresh_token}` |
| POST | `/auth/mfa/verify` | mfa_token | `{code}` → tokens |
| POST | `/auth/mfa/setup` | bearer | → `{secret, otpauth_url}`; `/auth/mfa/enable {code}` |
| POST | `/auth/refresh` | refresh | → tokens |
| GET | `/me` | bearer | `{id,name,email,country,mfa_enabled,eligibility:{kyc:"not_started"|"pending"|"approved"|"rejected",kyb,aml,sanctions,jurisdiction:"eligible"|"restricted"|"pending"}}` |
| GET | `/me/holdings` | bearer | `{enabled:false, items:[]}` (inactive until authorized) |
| GET | `/me/transactions` | bearer | `{enabled:false, items:[]}` |
| GET | `/me/notifications` | bearer | list |
| POST | `/support` | bearer | `{subject,message}` |

## Smart contracts (contracts/)
- `ReserveToken.sol` — ERC-20 (OZ v5) + ERC20Permit + AccessControl + Pausable. Name/symbol/decimals/cap from constructor/config.
  Roles: DEFAULT_ADMIN (intended Safe multisig), MINTER, BURNER, PAUSER, COMPLIANCE_ADMIN, TREASURY. Transfer hook calls
  `IComplianceRegistry.canTransfer(from,to,amount)` when compliance enabled. Optional `IReserveGuard` mint check.
- `ComplianceRegistry.sol` — per-address eligibility (KYC status, jurisdiction code, frozen, expiry), blocked jurisdictions list.
- `RedemptionManager.sol` — request (escrow tokens) → compliance review → approve (burn) / reject (return); min/max thresholds
  configurable, module disabled by default.
- `ReserveGuard.sol` — attestor role posts reserve attestations `(programId, units, reportHash, uri)`; `maxMintable()`.
- `Treasury.sol` — role-controlled treasury with withdrawal limits / timelock option.
- `AuditAnchor.sol` — anchors `(bytes32 chainHead, uint256 seq, string uri)` from the CMS audit trail.
- Deploy config in `contracts/config/<network>.json` — no hard-coded tokenomics.

## Waitlist rules
EU/EEA country codes (AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE IS LI NO) →
record stored with `jurisdiction_status=restricted` and the user is shown the EU/EEA notice (still allowed to receive general updates
only if they opt in). Double opt-in email confirmation. Honeypot + rate limit. Consent text version + hash stored.
