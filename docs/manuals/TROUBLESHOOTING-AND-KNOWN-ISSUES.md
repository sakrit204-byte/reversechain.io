# Troubleshooting and Known Issues

**Readers:** everyone. Start with the symptom tables in Part A. Part B is the register of known issues: behaviour that is confirmed in the code or the tooling, together with a workaround and the proper fix.
**Sources:** the code (`includes/*.php`, `deploy/*.sh`, `docker-compose.yml`, `contracts/`, `mobile/`), `docs/DEPLOYMENT-RUNBOOK.md §7`, `docs/OPERATIONS.md`, `docs/BACKUP-DR.md`, `contracts/SECURITY.md §4`, `docs/TEST-PLAN.md`, and development notes from the build.
**Related:** [Index](README.md) · [07 Incident runbooks](07-OPERATIONS-BACKUP-DR-MANUAL.md#8-incident-runbooks)

`W` = the WP-CLI prefix for your environment ([README §2](README.md#2-conventions-used-in-every-manual)).

---

## Part A — Symptoms and fixes

### A.1 Sign-in and access

| Symptom | Likely cause | Fix |
|---|---|---|
| "A valid authentication code is required for this account" | Wrong or reused TOTP code, or the phone clock is off | Wait for the next code; set the phone to automatic time; or use a recovery code ([01 §2.3](01-CMS-ADMIN-MANUAL.md#23-use-a-recovery-code)) |
| "Too many failed attempts. Try again in 15 minutes" | 5 failures for the same username from the same network | Wait 15 minutes, or `W transient delete --all` (an admin action; record it) |
| Redirected to Profile on every page | Staff MFA enforcement is on and you have no MFA yet | Set up MFA ([01 §2.2](01-CMS-ADMIN-MANUAL.md#22-set-up-mfa-first-sign-in)) |
| Lost phone and lost recovery codes | — | Break-glass reset ([01 §2.5](01-CMS-ADMIN-MANUAL.md#25-lost-device-and-no-recovery-codes-break-glass)) |
| Participant redirected away from wp-admin | Portal roles have no admin access, by design | They use `/portal/` |
| A ReserveChain menu item is missing | Missing capability (matrix in [01 §1.2](01-CMS-ADMIN-MANUAL.md#12-permissions-matrix)), or a role was reset by a deploy (KI-11, KI-12) | Check the role; re-apply the capability |

### A.2 Content and workflow

| Symptom | Likely cause | Fix |
|---|---|---|
| "Direct publishing is disabled…" | Expected. Publish is routed to review | Approve, then publish through the workflow ([01 §4](01-CMS-ADMIN-MANUAL.md#4-the-four-eyes-workflow)) |
| Approve button greyed out | You submitted or last edited the item (four-eyes) | Another reviewer approves |
| Publish greyed out: "Content differs from the approved version" | The item was edited after approval | Approve it again |
| Item jumped back from Approved to Under Review | Auto-return after a change ([01 §4.5](01-CMS-ADMIN-MANUAL.md#45-why-was-my-approval-invalidated)) | Approve it again |
| Page edits or menu edits disappeared after a deploy | `wp rc seed --pages-only` overwrote them (KI-01) | Put the change in `seed/pages/` or `Seed::menus()` |
| Spanish or Italian page shows English with a notice | The translation is missing | Fill in the Translations box, or the `.es.html` / `.it.html` seed file |
| Page looks unstyled | Wrong class names | Check `docs/CONTENT-AUTHORING-GUIDE.md §2` |

### A.3 Registry, passports, documents

| Symptom | Likely cause | Fix |
|---|---|---|
| Passport returns 404 | Record not published; wrong number; not a passport type; or `passports_public` off | [02 §9.5](02-REGISTRY-AND-PASSPORT-MANUAL.md#95-the-illustrative-template) |
| Record number is `RC-LOT-…` instead of `RC-CU-LOT-…` | The program was not selected before the first save | The number is immutable. Archive and re-create only if the record was never published or printed |
| Cu or Ni fields missing on a lot | The program has no symbol, or it is the other metal | [02 §5](02-REGISTRY-AND-PASSPORT-MANUAL.md#5-copper-cu-versus-nickel-ni-fields) |
| Assay grid shows fewer elements than entered | Lines not in `Symbol: value` form are ignored | [02 §6.1](02-REGISTRY-AND-PASSPORT-MANUAL.md#61-assay-transcription-format) |
| "The file of a published document cannot be replaced." | By design | Add a new document version ([01 §5.3](01-CMS-ADMIN-MANUAL.md#53-replace-a-document-a-new-version)) |
| `/verify` always returns `match:false` | Document not published; audience not Public; or the user hashed a different file (re-saved or re-scanned) | Check the document's state, audience and SHA-256 |
| Upload fails or is rejected | PHP size limit (KI-03, KI-04); not a real PDF; active content in the PDF | See the KI entries; get the original file |
| `document.storage_failed` in the audit trail | File permissions under `wp-content/uploads` | Fix ownership (`www-data`), then re-save the document |

### A.4 Proof of Reserves, redemption, payments

| Symptom | Likely cause | Fix |
|---|---|---|
| "Publication is locked: the Proof of Reserves module has not been authorized." | Expected until written authorization | [01 §8.3](01-CMS-ADMIN-MANUAL.md#83-gated-modules) |
| Snapshot shows **MODIFIED** | Stored JSON no longer matches its SHA-256 | SEV-2 ([07 §8.2](07-OPERATIONS-BACKUP-DR-MANUAL.md#82-audit-chain-failure)) |
| Token supply card shows `rpc_not_configured` | No RPC in Web3 settings, or the chain is not a testnet | [04 §5.1](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md#51-configure-web3-settings-administrator) |
| Redemption action greyed out | Four-eyes conflict, module off, or wrong state; the reason is shown next to the button | [04 §2.2](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md#22-who-does-what-and-the-evidence-for-each-step) |
| "Test redemptions can only be created by staff in the development environment." | Not `RC_ENV=development` | Use the local stack |
| Payment intent stuck in `confirming` | RPC unreachable or chain mismatch (`evidence.pending`) | Fix the RPC; click **Re-check now** |
| "RPC refused: … not an allowed testnet" | The RPC points at mainnet or another chain | Use a Sepolia or Amoy endpoint matching the platform network |

### A.5 Platform and operations

| Symptom | Likely cause | Fix |
|---|---|---|
| Dashboard: "DB immutability triggers: Missing" | The DB user lacks the TRIGGER privilege, or a manual DB operation dropped them | `W eval 'RC\Install::create_triggers();'` then `W rc tamper-test` ([07 §8.2](07-OPERATIONS-BACKUP-DR-MANUAL.md#82-audit-chain-failure)) |
| Chain verification FAILED | Tampering, or a manual DB edit; or `gap` after an old-style restore (KI-07) | SEV-2 runbook |
| `backup` check FAIL | Backup failed or stale | [07 §8.6](07-OPERATIONS-BACKUP-DR-MANUAL.md#86-backup-stale-or-failed) |
| `cron` WARN, scheduled jobs not running | Crontab missing or Docker unavailable to cron | `crontab -l \| grep rc-ops`; `deploy.sh --cron-only`; read `logs/cron.log` |
| Confirmation emails not arriving | SMTP not set; SPF/DKIM missing | [07 §8.5](07-OPERATIONS-BACKUP-DR-MANUAL.md#85-waitlist-or-contact-failures) |
| `/health/full` returns 404 | `RC_HEALTH_TOKEN` empty | Set it in `.env` (at least 16 characters) and recreate the container |
| `/health/full` returns 401 | Wrong token | Use the value from `~/reservechain/<env>/.env` |
| App shows no modules, or cannot load | `/config` unreachable; wrong `EXPO_PUBLIC_API_URL`; staging basic auth (KI-17) | Check the URL and the port (KI-08) |
| Hardhat tests fail after a dependency update | OpenZeppelin breaking change | Pin versions; read the changelog |
| WP-CLI from Git Bash fails with odd paths like `C:/Program Files/Git/var/www/html` | MSYS path conversion (KI-09) | Prefix with `MSYS_NO_PATHCONV=1` |

---

## Part B

### Known issues register

Status values: **Open** (workaround needed), **Mitigated** (fixed or guarded, but stay aware), **TBD** (not yet assessed).

| ID | Area | Issue | Impact | Workaround | Proper fix | Status |
|---|---|---|---|---|---|---|
| **KI-01** | CMS / deploy | `deploy.sh` runs `wp rc seed --pages-only` on **every** deploy. `Seed::pages()` overwrites the title, content, excerpt and ES/IT translations of every page that has a seed file, and publishes it directly. `Seed::menus()` deletes and rebuilds all menus | CMS edits to standard pages and menus are lost on the next deploy, and the four-eyes history of those pages is bypassed by the seed | Edit standard pages in `seed/pages/` and menus in `class-seed.php`, through a pull request. Use CMS edits only for urgent fixes, mirrored in the repository at once ([01 §3.1](01-CMS-ADMIN-MANUAL.md#31-seed-files-versus-cms-editing)) | After go-live: remove `wp rc seed --pages-only` from the upgrade branch of `deploy.sh`, or make `Seed::pages()` skip pages modified since the last seed | Open |
| **KI-02** | Documentation | Older design documents describe modes, module lists and constants that differ from the code (`waitlist_only`, `live`, `RC_ALLOW_LIVE_MODE`, 4 gated modules, DB schema v3) | Operators may look for settings that do not exist | Follow these manuals ([README §4](README.md#4-where-the-code-differs-from-older-documents)) | Update `docs/CMS-STRUCTURE.md §7` and `docs/DEPLOYMENT-RUNBOOK.md` | Open |
| **KI-03** | Docker / PHP | The official `wordpress` image defaults to `upload_max_filesize=2M` / `post_max_size=8M`. The repository raises these to 50M/52M by mounting `deploy/php-uploads.ini` (local `docker-compose.yml` and `deploy/docker-compose.prod.yml`). A container created before the mount was added keeps the old limits | Evidence PDFs fail to upload ("exceeds the maximum upload size", or a silent failure) | Recreate the container: `docker compose up -d --force-recreate wordpress` (server: add `-p rc-<env> -f docker-compose.prod.yml --env-file .env`). Check with `docker compose exec wordpress php -r 'echo ini_get("upload_max_filesize"),"/",ini_get("post_max_size");'`. The `wpcli` container does not mount the ini file, so do not check there | — | Mitigated |
| **KI-04** | Asset intake | The intake form accepts up to **5 × 20 MB** certificates in one POST (100 MB), but `post_max_size` is 52 MB | Large multi-file submissions fail as a whole | Ask submitters to stay under about 50 MB in total, or to send fewer or smaller files | Raise `post_max_size` (and the Caddy/proxy body limit) to at least 110M in `php-uploads.ini`, or lower the intake maximum | Open |
| **KI-05** | Backups | Two encryption modes. **age** (preferred): authenticated public-key encryption; the server cannot decrypt; it needs the `age` package (`sudo apt-get install -y age`). **openssl** fallback: AES-256-CBC + PBKDF2, which is not authenticated (integrity comes from `SHA256SUMS` and the `.sha256` sidecar), and the passphrase file lives on the server | With openssl, someone with server access can decrypt the backups, and losing the passphrase makes every backup unrestorable | Set `BACKUP_AGE_RECIPIENT`, keep `rc-backup.key` offline, and copy it to the server only during a restore. If using openssl, copy `~/.config/reservechain/backup-<env>.pass` to the vault on day one. `.tar.age` needs `BACKUP_AGE_IDENTITY`; `.tar.enc` needs `BACKUP_PASSPHRASE_FILE` | Make age mandatory for production | Mitigated |
| **KI-06** | Workflow | SEO meta (`_rct_seo_title`, `_rct_seo_desc`) is not part of the approval fingerprint, which covers only `_rc_*` meta | A user with `rc_publish` can change the SEO text of a live page without re-approval | Treat SEO changes as content: request a second-person check | Include `_rct_*` keys in `Workflow::fingerprint()` | Open |
| **KI-07** | Restore | mysqldump writes the *live* `AUTO_INCREMENT` value. Keeping it made the first audit entry after a restore skip ids, so verification failed with a `gap` error (found in the restore drill of 2026-10-06) | False integrity alarm after a restore | `restore.sh` now strips `AUTO_INCREMENT=` and `DEFINER`. Always use the current script | — | Mitigated |
| **KI-08** | Local tooling | `contracts/.env.example` (`WP_URL=http://localhost:8080`), `contracts/README.md` and `mobile/.env.example` (`EXPO_PUBLIC_API_URL=http://localhost:8080/…`) say port **8080**, but the Docker stack listens on **8088** | Anchoring and the mobile app fail locally with connection refused | Use `http://localhost:8088` (Android emulator: `http://10.0.2.2:8088`) | Update the example files | Open |
| **KI-09** | Windows / Git Bash | MSYS converts arguments that look like Unix paths (for example `/var/www/html`) into Windows paths when calling `docker` | `docker compose run … wp …` fails or targets wrong paths | Prefix commands with `MSYS_NO_PATHCONV=1`. `deploy/ops-common.sh`, `scripts/dev-reset.sh` and `scripts/lint-php.sh` set it automatically and use `cygpath -m` for compose file paths | — | Mitigated |
| **KI-10** | Permissions | The Proof of Reserves screen requires `rc_view_audit`. `rc_registry_manager` lacks it, but snapshot creation and attestation intake are coded for `rc_manage_registry` too | Registry managers cannot reach PoR | Reviewers and compliance officers run PoR tasks ([03 §1](03-PROOF-OF-RESERVES-MANUAL.md#1-access-and-screen-layout)) | Change the menu capability, or grant `rc_view_audit` to registry managers | Open |
| **KI-11** | Roles | `Install::create_roles()` removes and re-creates the five `rc_*` staff roles on every upgrade, which `deploy.sh` runs on every deploy. Capabilities added by hand (for example `wp cap add rc_compliance_officer rc_manage_payments`) are lost | Manual capability grants silently disappear | Re-apply grants after each deploy, or grant to a second administrator instead | Make extra capabilities part of `create_roles()` (code change) | Open |
| **KI-12** | Roles / intake | `rc_manage_intake` is added to compliance, registry manager and reviewer by `Intake::install()`, which runs only when the plugin version changes. A later `Install::upgrade()` (deploy) re-creates those roles without it | After some deploys, only administrators see **Asset intake** | Check with `W cap list rc_reviewer \| grep intake`. Restore with `W eval 'RC\Intake::install();'` | Add `rc_manage_intake` in `create_roles()` | Open (verify after each deploy) |
| **KI-13** | Workflow | **Request changes** in the review queue has no comment field. The handler accepts a `comment`, but the buttons are plain links | Feedback is not recorded with the transition | Send feedback by email or ticket, quoting the record number | Add a comment prompt | Open |
| **KI-14** | Waitlist / privacy | No admin button to unsubscribe, erase or edit a waitlist entry; the screen lists only the latest 200 rows | Data-subject requests need WP-CLI | Use the `W eval` procedures in [01 §10.3–10.4](01-CMS-ADMIN-MANUAL.md#103-unsubscribe-requests). Use the CSV export for the full list | Add admin actions | Open |
| **KI-15** | Support | The support inbox is read-only: no status, assignment or reply | Tickets must be tracked elsewhere | Use the support mailbox or a ticketing tool | Add a status field | Open |
| **KI-16** | QA / browsers | `docs/TEST-PLAN.md` plans Playwright end-to-end runs on Chromium, **Firefox** and **WebKit** (Safari), but no Firefox or WebKit results are recorded yet. Development screenshots and motion tests were run in Chromium only (`scripts/*.cjs`) | Possible rendering or behaviour differences in Safari and Firefox: Lenis smooth scroll, `motion.js`, SubtleCrypto file hashing in `[rc_verify]`, the QR rendering | Smoke-test the homepage, a passport, Verify, Waitlist and Portal in Safari (macOS/iOS) and Firefox before launch | Add Firefox and WebKit projects to the Playwright suite | **TBD** |
| **KI-17** | Staging / app | `STAGING_BASIC_AUTH` protects the whole staging site except `/wp-json/rc/v1/health*` | `preview` app builds cannot call the staging API | Leave basic auth off while app QA runs, or test the app against another host | Exclude `/wp-json/rc/v1/*` from basic auth, with other protection | Open |
| **KI-18** | Anchoring | WordPress Application Passwords (used by `anchor-audit.ts` to report back) are available only over HTTPS, or on a local environment | Automatic report-back fails on plain-HTTP hosts | Record the anchor manually in the Audit trail ([01 §14.4](01-CMS-ADMIN-MANUAL.md#144-anchor-the-chain-head-on-chain)) | — | Mitigated |
| **KI-19** | Registry | Choosing the *Verified* status is not technically restricted. The form only shows a warning | A wrong *Verified* could be published if the reviewer and the publisher both miss it | QC checklist and four-eyes ([02 §8, §10](02-REGISTRY-AND-PASSPORT-MANUAL.md#8-verification-statuses)) | Require `rc_manage_compliance`, or an attached independent attestation, to set *Verified* | Open |
| **KI-20** | Gated modules | Turning on a gated module needs one administrator plus a reference. Dual control is procedural. Locked modes are dual-key in code (constant + reference) | One administrator could enable a module | Second-person audit check ([01 §8.3](01-CMS-ADMIN-MANUAL.md#83-gated-modules)); keep the administrator count small | Add a second-approver step for gated modules | Open |
| **KI-21** | PoR | The "latest attestation" is the most recently **published** reserve report, not the one with the latest report date | Publishing an older report after a newer one makes the older one count | Publish attestations in date order; unpublish superseded reports | Order by `report_date` | Open |
| **KI-22** | Development stack | Local `RC_TOKEN_SECRET` is the development default, and the dev compose file contains fixed DB passwords | Never acceptable outside development | `deploy.sh` generates unique secrets for staging and production. System health flags a default secret | — | Mitigated |
| **KI-23** | Smart contracts | The 11 contract limitations in `contracts/SECURITY.md §4`: attestor as oracle, no clawback, compliance can block escrow returns, global pause, UTC-day limits, and others | See [05 §11](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#11-known-limitations) | Operational procedures in 05 | Independent audit before any non-test use | Open |
| **KI-24** | Mobile | Native binaries have not been built yet. They need the ReserveChain EAS, Apple and Google accounts. Placeholders `REPLACE_WITH_…` remain in `app.json` and `eas.json` | No store builds yet | [06 §2](06-MOBILE-APP-BUILD-AND-PUBLISH.md#2-workstation-and-eas-setup) | — | Open |

### Reporting a new issue

1. Reproduce it on staging or locally. Note the URL, the user role, the time (UTC) and the audit entries involved.
2. File it in the repository issue tracker with the label `known-issue`.
3. Add a row here (the next KI number) in the same pull request as the workaround or the fix.
