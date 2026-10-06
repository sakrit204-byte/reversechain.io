# ReserveChain — Operator Manuals and Training

These manuals let ReserveChain staff, or a replacement developer, run the ReserveChain platform without the original developer. They were written from the source code: the `reservechain-core` plugin (`wordpress/plugins/reservechain-core/includes/*.php`), the `reservechain` theme, `contracts/`, `mobile/` and `deploy/`. Where an older design document says something different, these manuals follow the code. The differences are listed in [§4](#4-where-the-code-differs-from-older-documents).

> **Mandatory disclosure.** ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, token allocation or entitlement to participate in any future offering. Any future availability will be subject to the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval.
>
> **EU/EEA notice.** ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA.

---

## 1. Manuals

| # | Manual | Main readers | What it covers |
|---|---|---|---|
| 01 | [CMS Administration](01-CMS-ADMIN-MANUAL.md) | Every staff member | Roles, MFA sign-in, pages and translations, the four-eyes workflow, media and documents, SEO, menus, site modes and gated modules, disclosures, jurisdictions, waitlist, support inbox, compliance, system health, audit trail |
| 02 | [Registry and Digital Asset Passports](02-REGISTRY-AND-PASSPORT-MANUAL.md) | Registry managers, reviewers | Data model, record numbers, lots, batches, containers and coils, Cu and Ni fields, CoA transcription, evidence provenance, verification statuses, passports, QC checklist |
| 03 | [Proof of Reserves](03-PROOF-OF-RESERVES-MANUAL.md) | Reviewers, compliance officers | Reconciliation, every exception code, snapshots, attestation intake, alerts, exports, on-chain supply, publication limits |
| 04 | [Redemption, Payments and Portals](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md) | Operations, compliance | The redemption workflow, test redemptions, wallet linking, payment intents, Participant Portal, asset intake, data rooms, and the authorization each one needs before it goes live |
| 05 | [Smart Contracts and Token Administration](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md) | Safe signers, technical operator | Testnet deployment, verification, Safe handover, mint, burn and pause, compliance registry, attestations, redemptions, audit anchoring, incidents |
| 06 | [Mobile App Build and Publication](06-MOBILE-APP-BUILD-AND-PUBLISH.md) | Release owner, developer | Accounts, EAS, environment variables, builds, TestFlight, Play internal testing, store pack, OTA updates, versioning, account deletion, release checklist |
| 07 | [Operations, Backup and Disaster Recovery](07-OPERATIONS-BACKUP-DR-MANUAL.md) | Ops / on-call | Daily, weekly and monthly checklists, deploy, rollback, backup drill, restore, monitoring, incident runbooks, domain, DNS, email and analytics |
| — | [Troubleshooting and Known Issues](TROUBLESHOOTING-AND-KNOWN-ISSUES.md) | Everyone | Symptoms, causes and fixes, plus the register of known issues |
| — | [Training Program](TRAINING-PROGRAM.md) | Trainers, new staff | 10 recorded modules with goals, durations, narration scripts, shot lists and knowledge checks |

### Which manual do I need?

| I need to… | Go to |
|---|---|
| Sign in for the first time, or set up MFA | [01 §2](01-CMS-ADMIN-MANUAL.md#2-signing-in-and-multi-factor-authentication) |
| Change text on a web page | [01 §3](01-CMS-ADMIN-MANUAL.md#3-pages-and-translations) |
| Approve or publish something | [01 §4](01-CMS-ADMIN-MANUAL.md#4-the-four-eyes-workflow) |
| Register a new lot, container or coil | [02 §4](02-REGISTRY-AND-PASSPORT-MANUAL.md#4-adding-physical-asset-records) |
| Enter a Certificate of Analysis | [02 §6](02-REGISTRY-AND-PASSPORT-MANUAL.md#6-entering-a-certificate-of-analysis-coa) |
| Fix a reconciliation exception | [03 §3](03-PROOF-OF-RESERVES-MANUAL.md#3-exception-codes-and-how-to-resolve-them) |
| Export the waitlist | [01 §10](01-CMS-ADMIN-MANUAL.md#10-waitlist-management) |
| Deploy or roll back a release | [07 §3](07-OPERATIONS-BACKUP-DR-MANUAL.md#3-deploying-a-release) |
| Restore a backup | [07 §6](07-OPERATIONS-BACKUP-DR-MANUAL.md#6-restoring-a-backup) |
| Respond to an alert | [07 §8](07-OPERATIONS-BACKUP-DR-MANUAL.md#8-incident-runbooks) |
| Ship a mobile build | [06](06-MOBILE-APP-BUILD-AND-PUBLISH.md) |

---

## 2. Conventions used in every manual

- **Procedure format.** Each task has four parts: **Before you start** (role, prerequisites), **Steps** (numbered), **Result** (what you should see), and **If something goes wrong**.
- **URLs.** Admin links are written for the local stack, `http://localhost:8088/wp-admin/…`. On staging or production, replace `http://localhost:8088` with the site host, for example `https://staging.reservechain.io` or `https://reservechain.io`.
- **Screenshots.** `[Screenshot: …]` marks where a screenshot will be inserted. Each placeholder names the exact admin URL to capture.
- **Credentials.** No password, key or secret appears in these manuals. Account passwords, the admin login, demo-account passwords, recovery codes, backup keys and multisig details are **provided separately** through ReserveChain's password manager.
- **Commands.** `W` means the WP-CLI prefix for your environment:
  - Local (Git Bash on Windows): `MSYS_NO_PATHCONV=1 docker compose run --rm wpcli wp`
  - Server: `cd ~/reservechain/<env> && docker compose -p rc-<env> -f docker-compose.prod.yml --env-file .env run --rm wpcli wp`

  So `W rc audit-verify` means "run `wp rc audit-verify` with your environment's prefix".
- **Testnet only.** Every blockchain procedure targets Sepolia (chain id 11155111), Polygon Amoy (80002) or a local Hardhat node (31337). **Nothing is ever done on mainnet without written authorization from ReserveChain.** The CMS refuses mainnet chain ids, and the deploy script refuses non-testnets.

## 3. Roles at a glance

| WordPress role | Typical holder | Summary |
|---|---|---|
| `administrator` | Named ReserveChain owners | Everything, including settings, gated-module authorization, audit anchoring and payments |
| `rc_compliance_officer` | Compliance lead | Approve, publish, archive, compliance, waitlist, users, audit |
| `rc_reviewer` | Editorial or registry reviewer | Approve (never their own work), audit view |
| `rc_registry_manager` | Registry operator | Create and edit registry records, submit for review |
| `rc_editor` | Content editor | Edit pages and posts, upload, submit for review |
| `rc_auditor` | Internal or external auditor | Read-only, including drafts, plus audit view, verify and export |
| `rc_investor`, `rc_enterprise_client`, `rc_custodian` | External participants | Portal and data-room access only, never wp-admin |

The full matrix is in [01 §1](01-CMS-ADMIN-MANUAL.md#1-roles-and-permissions).

Demo accounts exist on development and staging only: `demo.editor`, `demo.registry`, `demo.reviewer`, `demo.compliance`, `demo.auditor` and `demo.app`. Passwords are provided separately. **Never create these accounts on production.** `deploy.sh` seeds them only when `--seed-demo` is used, which is the default for staging.

## 4. Where the code differs from older documents

The design documents in `docs/` were written before parts of the code. Where they conflict, the code is right.

| Topic | Older documents say | The code does |
|---|---|---|
| Site modes | `prelaunch`, `waitlist_only`, `maintenance`, `live` | 11 modes: `development`, `prelaunch`, `waitlist`, `documentation_release`, `asset_verification`, `enterprise_onboarding`, `maintenance` (open), and `eligibility`, `early_participation`, `live_offering`, `redemption` (locked). See [01 §8](01-CMS-ADMIN-MANUAL.md#8-website-modes-sections-and-module-authorizations) |
| Unlocking a locked mode | `RC_ALLOW_LIVE_MODE` constant | `RC_ALLOW_MODE_<MODE>` constant in `wp-config.php` **and** a written authorization reference entered by a user holding `rc_authorize_modules` |
| Gated modules | wallet, purchase, proof_of_reserves, redemption | 22 gated modules (see [01 §8.3](01-CMS-ADMIN-MANUAL.md#83-gated-modules)) |
| DB schema version | v3 | `RC_DB_VERSION` = 4, plugin `RC_VERSION` = 0.9.0 |
| Page edits | Pages "remain fully editable in the CMS" | Partly true. `wp rc seed --pages-only`, which `deploy.sh` runs on **every** upgrade, overwrites seeded pages, their ES/IT translations and the menus with the files in `seed/pages/`. See [01 §3.1](01-CMS-ADMIN-MANUAL.md#31-seed-files-versus-cms-editing) |
| Local URLs | `http://localhost:8080` in `contracts/README.md` and `mobile/.env.example` | The Docker stack listens on `http://localhost:8088` |

## 5. Keeping these manuals current

1. Treat these files like code. Change them in the same pull request as the behaviour they describe.
2. When you change a capability, a menu slug or a WP-CLI command, search `docs/manuals/` for the old name.
3. Re-record the affected training module ([TRAINING-PROGRAM.md](TRAINING-PROGRAM.md)) when a screen changes visibly.
4. Record the manual version and date in the release notes.
