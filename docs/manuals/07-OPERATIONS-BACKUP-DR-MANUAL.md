# 07 — Operations, Backup and Disaster Recovery Manual

**Readers:** the operator / on-call engineer and the ReserveChain release owner. **Source of truth:** `deploy/deploy.sh`, `deploy/backup.sh`, `deploy/restore.sh`, `deploy/rollback.sh`, `deploy/ops-common.sh`, `deploy/docker-compose.prod.yml`, `deploy/env/*.env.example`, `includes/class-monitoring.php`, `includes/class-cli.php`; and the background documents `docs/OPERATIONS.md`, `docs/BACKUP-DR.md`, `docs/DEPLOYMENT-RUNBOOK.md` and `docs/SECURITY.md`.
**Related:** [01 System health](01-CMS-ADMIN-MANUAL.md#13-system-health-and-the-operations-widget) · [05 Incident response](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#10-incident-response) · [Troubleshooting](TROUBLESHOOTING-AND-KNOWN-ISSUES.md) · [Index](README.md)

This manual describes the **single-server deployment** that is implemented today. Staging and production run side by side on one Linux host, each as its own Docker Compose project, behind one shared Caddy (automatic HTTPS). The managed-cloud target architecture is described in `docs/ARCHITECTURE.md §6`.

```
~/reservechain/edge/        Caddy (project rc-edge): Caddyfile, sites/<env>.caddy
~/reservechain/production/  docker-compose.prod.yml, .env (mode 600), app -> releases/<stamp>-<ref>,
                            backup.sh, restore.sh, rollback.sh, ops-common.sh, mu-plugins/rc-smtp.php, logs/, releases.log
~/reservechain/staging/     same layout (project rc-staging)
~/reservechain-backups/<env>/{daily,weekly,monthly}/   encrypted archives + last-success.json
```

**WP-CLI on the server.** In this manual, `W` stands for:
`cd ~/reservechain/<env> && docker compose -p rc-<env> -f docker-compose.prod.yml --env-file .env run --rm wpcli wp`

Secrets (`.env` values, the backup age private key or passphrase, the SSH deploy key, `RC_HEALTH_TOKEN`) are **provided separately** in the ReserveChain vault.

---

## Contents

1. [Operations checklists (daily, weekly, monthly, quarterly)](#1-operations-checklists)
2. [Access you need](#2-access-you-need)
3. [Deploying a release](#3-deploying-a-release)
4. [Rolling back](#4-rolling-back)
5. [Backup verification drill](#5-backup-verification-drill)
6. [Restoring a backup](#6-restoring-a-backup)
7. [Monitoring and alerts](#7-monitoring-and-alerts)
8. [Incident runbooks](#8-incident-runbooks)
9. [Domain, DNS, email and analytics setup notes](#9-domain-dns-email-and-analytics-setup-notes)

---

## 1. Operations checklists

### Daily (about 5 minutes)
- [ ] The **Operations** widget is all green (`https://reservechain.io/wp-admin/index.php`), or `/wp-json/rc/v1/health` returns `"status":"ok"`.
- [ ] Last night's backup exists: `ls -l ~/reservechain-backups/production/daily | tail -2`.
- [ ] No unexplained `ops.alert`, `auth.mfa_failed` or `auth.login_failed` bursts (Audit trail, filter `ops` / `auth`).
- [ ] Review queue and support inbox checked ([01 §4](01-CMS-ADMIN-MANUAL.md#4-the-four-eyes-workflow), [01 §11](01-CMS-ADMIN-MANUAL.md#11-support-inbox)).
- [ ] Any reconciliation-exception email handled ([03 §6](03-PROOF-OF-RESERVES-MANUAL.md#6-alerts)).

### Weekly
- [ ] Review the `ops.*`, `auth.*` and `settings.changed` audit entries. Check that site mode and modules match the authorization file.
- [ ] Off-site copies exist: `rclone ls <remote>/production/daily | tail`.
- [ ] Update window: apply WordPress core, theme, plugin and dependency updates through a pull request → staging → production (§3).
- [ ] `df -h` and `docker system df`. Prune old images if disk use is above 70 %.
- [ ] Run **Verify entire chain now** in the Audit trail if the daily check ever showed a warning.

### Monthly
- [ ] Certificate and domain expiry check (Caddy renews certificates automatically; the registrar renewal date is in the configuration record).
- [ ] Review users and roles: remove leavers, and confirm every staff account has MFA (Compliance page, MFA column).
- [ ] Audit-log export (JSONL) to WORM/off-site storage ([01 §14.3](01-CMS-ADMIN-MANUAL.md#143-export-jsonl)). Anchor the chain head if anchoring is enabled ([05 §9](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#9-audit-anchoring)).
- [ ] Test an alert: `W rc-ops test-alert`. Check that the email and the webhook arrive.

### Quarterly
- [ ] **Restore drill** of a real production archive into staging (§5). Record the timings against the RTO.
- [ ] Access review (roles and MFA) signed by the compliance officer.
- [ ] Rotate `RC_HEALTH_TOKEN`, and the backup key whenever staff change.
- [ ] Review this manual and the known-issues register.

---

## 2. Access you need

| Access | Used for | Kept in |
|---|---|---|
| SSH key `~/.ssh/reservechain_deploy` for `ubuntu@<server>` | deploy.sh, server commands | Vault (provided separately) |
| Git repository (ReserveChain organisation) | Releases, tags | ReserveChain GitHub/GitLab |
| WordPress administrator account with MFA | Settings, health, audit | Personal; at least two named admins |
| `~/reservechain/<env>/.env` | DB passwords, `RC_TOKEN_SECRET`, salts, SMTP, `RC_HEALTH_TOKEN`, backup settings | Server (mode 600) + a copy inside every encrypted backup |
| Backup **age private key** `rc-backup.key`, or the passphrase file | Restores | **Offline**: password manager + sealed copy. Never left on the server |
| rclone remote credentials (write-only) | Off-site copies | Server `~/.config/rclone/` + vault |
| DNS / registrar / email provider / uptime monitor logins | §9 | Vault |

---

## 3. Deploying a release

Every release goes Dev → Staging → Production. You run the commands **from your workstation, in the repository root** (on Windows, use Git Bash).

### 3.1 Staging deploy

**Before you start**
- The changes are merged to `main`, and tests are green:
  - plugin lint: `bash scripts/lint-php.sh`;
  - contracts: `npm test`;
  - mobile: `npm test` (only if they changed).
- You have SSH access (§2).

**Steps**
1. `git checkout main && git pull`.
2. Deploy the working tree, or a commit:
   ```bash
   SITE_HOST=staging.reservechain.io ADMIN_EMAIL=ops@reservechain.io deploy/deploy.sh --env staging ubuntu@SERVER_IP
   # or: deploy/deploy.sh --env staging --ref <commit-or-tag> ubuntu@SERVER_IP
   ```
3. The script:
   1. packs the release to `releases/<UTC-stamp>-<ref>/`;
   2. swaps the `app` symlink atomically;
   3. recreates the WordPress container;
   4. runs `RC\Install::upgrade()` (migrations, triggers, roles) and `wp rc seed --pages-only` (**this overwrites seeded pages and menus**, see [01 §3.1](01-CMS-ADMIN-MANUAL.md#31-seed-files-versus-cms-editing));
   5. runs `wp rc tamper-test` and `wp rc-ops check --no-alerts`;
   6. reinstalls the crontab.
4. Check the output ends with the site and health URLs, and `==> Done: staging release …`.
5. Run the post-deploy checks (§3.3) on staging.
6. UAT: the ReserveChain reviewer signs off in the release ticket.

On the **first deploy**, the script generates `~/reservechain/staging/.env` with random secrets. If you prepared `deploy/env/staging.env` locally (git-ignored, started from the `.example`), it is used instead. Staging is **never indexed**: `noindex` header, disallow-all robots.txt, `blog_public=0`. To add password protection, set `STAGING_BASIC_AUTH` in the staging `.env` and redeploy ([06 §3](06-MOBILE-APP-BUILD-AND-PUBLISH.md#3-environment-variables) explains the effect on the app).

### 3.2 Production deploy

**Before you start**
- Staging is deployed with the **same ref** and UAT is signed off.
- The release notes are approved by the release owner.
- An on-call engineer and a rollback owner are named.
- If downtime is expected, it was announced at least 48 h before.

**Steps**
1. Tag the release: `git tag v1.2.3 && git push origin v1.2.3`.
2. Take a safety backup on the server: `ssh ubuntu@SERVER_IP 'cd ~/reservechain/production && ./backup.sh'`. Note the archive name.
3. Deploy the tag:
   ```bash
   deploy/deploy.sh --env production --ref v1.2.3 --host reservechain.io ubuntu@SERVER_IP
   ```
   Production is seeded with pages only; demo users are **never** created on production.
4. Run the post-deploy checks (§3.3).
5. Record the deployment in the release log. `~/reservechain/production/releases.log` holds the server-side history.

### 3.3 Post-deploy checks

- [ ] `curl -s https://reservechain.io/wp-json/rc/v1/health` → `"status":"ok"`, `"triggers":true`, `"backup_ok":true`.
- [ ] `curl -s https://reservechain.io/wp-json/rc/v1/config` → expected `site_mode`; gated modules still `false` unless authorized; disclosure text present.
- [ ] Home page, a program page, a passport (`/passport/RC-CU-LOT-000001/`), Verify and Waitlist render. The disclosure and the EU/EEA notice are visible.
- [ ] Admin → Audit trail → **Verify entire chain now**: *Intact*; triggers *UPDATE/DELETE blocked*.
- [ ] Dashboard **Operations** widget: all OK.
- [ ] Error rate and latency normal for 30 minutes (Caddy and WordPress logs, §7.4).

### 3.4 Other deploy commands

```bash
deploy/deploy.sh --env production --cron-only ubuntu@SERVER_IP   # reinstall crontab only
deploy/deploy.sh --env staging --push-env ubuntu@SERVER_IP       # upload an edited deploy/env/staging.env over the server .env
deploy/deploy.sh --env production --skip-cron ubuntu@SERVER_IP   # deploy without touching crontab
deploy/deploy.sh --help
```

**If something goes wrong**
- *"LEGACY" message:* the server still has the old single-stack layout. Follow the migration in `docs/DEPLOYMENT-RUNBOOK.md §3.4`.
- *SSH refused:* check the key path (`--key`) and the server firewall.
- *`tamper-test` reports "allowed (triggers missing!)":* see §8.2. Do not open the site to the public until it is fixed.
- *The site shows the wrong pages or menus after deploy:* expected if pages were edited only in the CMS ([01 §3.1](01-CMS-ADMIN-MANUAL.md#31-seed-files-versus-cms-editing)).

---

## 4. Rolling back

**Decision rule:** roll back if a SEV-1 or SEV-2 regression is found and a forward fix cannot be deployed within 30 minutes. For bad *content*, do not roll back: **Unpublish** it instead ([01 §4.6](01-CMS-ADMIN-MANUAL.md#46-unpublish)).

**Steps (on the server)**
```bash
cd ~/reservechain/production
./rollback.sh --list                      # kept releases (last 5); the live one is marked *
./rollback.sh --to previous               # code only: swap symlink, recreate WordPress, re-run migrations
./rollback.sh --to v1.2.2                 # newest kept release built from that ref
```

**Code and database together** (only when the release corrupted data, or included a non-backward-compatible migration):
1. **Export the audit log first** ([01 §14.3](01-CMS-ADMIN-MANUAL.md#143-export-jsonl)). A database rollback discards the audit entries written after the backup, and the export keeps them as evidence.
2. Run:
   ```bash
   BACKUP_AGE_IDENTITY=~/rc-backup.key ./rollback.sh --to previous \
     --with-db ~/reservechain-backups/production/daily/rc-production-<stamp>.tar.age --i-know
   shred -u ~/rc-backup.key
   ```
   For `.tar.enc` archives, use `BACKUP_PASSPHRASE_FILE=…` instead.

A ref that is no longer kept on the server is redeployed from the workstation: `deploy/deploy.sh --env production --ref v1.2.2 ubuntu@SERVER_IP`.

**Result:** `rollback.sh` ends with `wp rc tamper-test`, `wp rc audit-verify` and `wp rc-ops health`. All three must pass. Record the rollback in the incident log.

---

## 5. Backup verification drill

Backups run from cron: production at 02:30 UTC, staging at 03:00 UTC. Each run produces one encrypted archive, `rc-<env>-<YYYYMMDDTHHMMSSZ>.tar.age` (or `.tar.enc`), plus a `.sha256` sidecar. The archive contains `db.sql.gz` (with the triggers), `uploads.tar.gz`, `env.dotenv`, `wp-config.php`, `MANIFEST` and `SHA256SUMS`.

- The script **refuses** to create a backup whose dump lacks the two audit triggers.
- Retention: 7 daily, 4 weekly, 6 monthly (hard links).
- When `RCLONE_REMOTE` is set, each archive is copied off-site and checked with `rclone check`.
- On success, the monitor is told through `wp rc-ops backup-mark`.

**Encryption options**
- **Preferred: age public key.** Set `BACKUP_AGE_RECIPIENT=age1…` in `.env`. The server can encrypt but never decrypt. Keep the private key offline.
- **Fallback: passphrase.** `openssl enc -aes-256-cbc -pbkdf2` with `BACKUP_PASSPHRASE_FILE` (deploy.sh generates `~/.config/reservechain/backup-<env>.pass`). **Copy the passphrase to the vault immediately.** Without it, backups cannot be restored. See [KI-05](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register).

### Quarterly drill: restore production into staging

**Before you start**
- Staging may be overwritten. Announce it to the reviewers.
- You have the **age private key** (or the passphrase file) from the vault.
- Start a timer, so you can compare the result with the RTO (target ≤ 4 h).

**Steps**
1. On the server, pick last night's production archive: `ls ~/reservechain-backups/production/daily | tail -1`.
2. Copy the key to the server for the duration of the drill only: `scp rc-backup.key ubuntu@SERVER_IP:~/`.
3. Restore into staging, rewriting the URL:
   ```bash
   cd ~/reservechain/staging
   BACKUP_AGE_IDENTITY=~/rc-backup.key ./restore.sh \
     --archive ~/reservechain-backups/production/daily/rc-production-<stamp>.tar.age \
     --url https://staging.reservechain.io --yes
   shred -u ~/rc-backup.key
   ```
4. Read the output. It must end with
   `RESTORE OK: env=staging triggers=2 chain verified, site HTTP 200`.
5. Spot-check staging:
   - log in with an admin account (MFA works, because the salts and `RC_TOKEN_SECRET` come from the staging `.env`);
   - open a passport, the Audit trail and the Waitlist.
6. Optional: test an off-site copy by downloading it with `rclone copy <remote>/production/daily/<file> /tmp/` and restoring that file instead.
7. Record the drill in the drill log: date, archive, duration, result, issues.

**Result:** proof that last night's backup decrypts, imports, keeps the audit triggers and passes full chain verification.

**If something goes wrong**
- *Checksum mismatch:* the archive is corrupt. Try the previous day, and investigate the storage.
- *Decrypt failure:* wrong key or passphrase. **Escalate immediately.** Backups you cannot decrypt are not backups.
- *Chain verification fails with a `gap` error just after the restore:* see [KI-07](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register). `restore.sh` strips `AUTO_INCREMENT` to prevent this. Make sure you are using the current script.

---

## 6. Restoring a backup

### 6.1 Restore into production (data loss or corruption)

**Before you start**
- An incident is declared and a decision has been taken to restore. Choose the target time from the alerts and the audit trail.
- **Export the audit log first** (JSONL), unless the database is unreadable.
- You have the age key or passphrase.

**Steps**
1. Switch production to **Maintenance**: Settings & modules → Website mode → *Maintenance*. Or, on the server: `W option patch update rc_settings site_mode maintenance`.
2. Run on the server:
   ```bash
   cd ~/reservechain/production
   BACKUP_AGE_IDENTITY=~/rc-backup.key ./restore.sh --archive ~/reservechain-backups/production/daily/rc-production-<stamp>.tar.age --i-know
   shred -u ~/rc-backup.key
   ```
   - The script refuses production without `--i-know`.
   - It takes an automatic **safety backup** first, unless `--no-pre-backup` is given.
   - It then decrypts, verifies the checksums, drops and re-imports the database (removing DEFINER and AUTO_INCREMENT), checks the triggers, restores uploads, runs `tamper-test` and `audit-verify`, and checks HTTP 200 and `/health`.
3. Compare the restored chain head with the latest on-chain anchor, if anchoring is enabled ([05 §9](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#9-audit-anchoring)).
4. Switch the site mode back to the previous mode (normally *Pre-Launch*).
5. Run the post-deploy checks (§3.3). Record everything in the incident log, including the archived JSONL of the discarded audit entries.

### 6.2 Full disaster-recovery rebuild on a new server

**Steps**
1. Provision a new Linux host with Docker. Allow ports 80 and 443. Install your SSH key.
2. From the workstation, deploy the code without cron:
   `deploy/deploy.sh --env production --skip-cron ubuntu@NEW_IP`
3. On the new server, discard the fresh install and restore data **and** `.env`:
   ```bash
   cd ~/reservechain/production
   docker compose -p rc-production -f docker-compose.prod.yml --env-file .env down -v
   BACKUP_AGE_IDENTITY=~/rc-backup.key ./restore.sh --archive <copied archive> --restore-env --i-know --no-pre-backup
   ```
   The archive comes from the old server's backup directory or from the off-site remote (`rclone copy`).
4. From the workstation, install cron: `deploy/deploy.sh --env production --cron-only ubuntu@NEW_IP`.
5. Point DNS at the new IP (§9). Keep the TTL at 300 s in advance so the switch is fast. Caddy obtains certificates once DNS resolves.
6. Run the post-deploy checks (§3.3). Repeat for staging if needed.

**Target:** RTO ≤ 24 h for the loss of a region or host, and RPO ≤ 24 h on the single-server setup (nightly backups). The managed-cloud design targets RPO 15 min.

### 6.3 Restore a single document file

1. Extract `uploads.tar.gz` from a decrypted archive on a workstation.
2. Copy the file back to `wp-content/uploads/…`. For restricted documents, copy it to `wp-content/uploads/rc-private/…`.
3. Recompute its SHA-256 and compare it with the document's `_rc_sha256`, which is shown in the Registry record box. **Any mismatch is SEV-2.**

---

## 7. Monitoring and alerts

### 7.1 Health endpoints

| Endpoint | Access | Content |
|---|---|---|
| `GET /wp-json/rc/v1/health` | Public, rate-limited to 60/min, no-store | `status` (`ok` / `degraded`), db, audit `seq` / `chain_head` / last verification, triggers, cron age, disk free %, `backup_ok`. Versions only outside production |
| `GET /wp-json/rc/v1/health/full` | Header `X-RC-Health-Token: <RC_HEALTH_TOKEN>` (from `.env`) | Every check with detail, versions, last backup record, failures in the last 24 h, cron schedule, alert state, site mode, SMTP configured |

```bash
curl -s -H "X-RC-Health-Token: $RC_HEALTH_TOKEN" https://reservechain.io/wp-json/rc/v1/health/full | jq .
```

### 7.2 Checks, thresholds and alerts

The checks run **hourly**. **Full chain verification** runs daily at 03:15 UTC. The heartbeat runs every 5 minutes. All are driven by the real crontab that `deploy.sh` installs.

| Check | OK | FAIL (alert) | First response |
|---|---|---|---|
| `database` | `SELECT 1` works | query fails | §8.1 |
| `audit_chain` | Last full verification passed and is < 26 h old | Verification failed | **SEV-2**, §8.2 |
| `triggers` | Both triggers exist | Missing | §8.2 |
| `failed_logins` | ≤ 20 failed sign-ins per hour | > 20 | §8.3 |
| `submissions` | No waitlist/contact 5xx and no mail failure in 24 h (1–2 = WARN) | ≥ 3 | §8.5 |
| `backup` | Last success < 26 h ago | None, failed, or stale | §8.6 |
| `disk` | ≤ 85 % used | > 85 % | §8.7 |
| `cron` | Heartbeat < 20 min (WARN only) | — | Check `crontab -l \| grep rc-ops`, then `logs/cron.log` |

**Delivery**
- Email to *Settings → Contact / support inbox email*, falling back to the WordPress admin email. The subject starts `[ReserveChain PRODUCTION] …`.
- Optional webhook (Slack / Teams / Mattermost):
  ```bash
  W option update rc_alert_webhook 'https://hooks.slack.com/services/…'   # https only
  W rc-ops test-alert
  ```
- Each failing check alerts at most every 6 hours while it stays failing, and re-arms once it recovers. Every alert is logged as `ops.alert`.

### 7.3 External uptime monitor

Internal checks cannot report a server that is completely down. For each environment, configure:
- an **UptimeRobot** or **Better Stack** *keyword* monitor on `https://<host>/wp-json/rc/v1/health`, with the keyword `"status":"ok"`;
- an HTTP monitor on `/`;
- SSL-expiry notifications;
- alert contacts: the on-call email and the same chat channel as the webhook.

Optionally, append a heartbeat call to the backup cron line so that a **missing** backup run also pages someone.

### 7.4 Logs

| What | Where |
|---|---|
| Backups | `~/reservechain/<env>/logs/backup.log`, `~/reservechain-backups/<env>/last-success.json` |
| WP-cron | `~/reservechain/<env>/logs/cron.log` |
| Deploy / rollback history | `~/reservechain/<env>/releases.log` |
| WordPress / PHP | `docker compose -p rc-<env> -f docker-compose.prod.yml --env-file .env logs --tail=200 wordpress` |
| MySQL | same command, with `db` instead of `wordpress` |
| Caddy (TLS, access) | `cd ~/reservechain/edge && docker compose -p rc-edge -f docker-compose.edge.yml logs --tail=200 caddy` |
| Business events | Admin → Audit trail (filter `ops`, `auth`, `settings`) |

---

## 8. Incident runbooks

**Severities**

| Severity | Examples | Response time |
|---|---|---|
| SEV-1 | Active compromise; unauthorised offer-like content published; key compromise | Immediate; on-call + ReserveChain leadership |
| SEV-2 | Integrity failure; suspected data exposure | < 4 h |
| SEV-3 | Degraded service; failed backup | < 1 business day |

The on-call contact list is **[to be provided by ReserveChain]**.

**For every incident:**
1. Acknowledge it in the channel.
2. Open an incident log entry (time, who, what).
3. Preserve evidence before you change anything.
4. For SEV-1 and SEV-2, hold a post-incident review within 5 business days.

### 8.1 Site down

**Steps**
1. Confirm from outside: the uptime monitor, and `curl -I https://reservechain.io/`.
2. SSH to the server and run `docker ps`. Are `wp-production`, `db` and `caddy` running?
3. Restart the environment and the edge:
   ```bash
   cd ~/reservechain/production && docker compose -p rc-production -f docker-compose.prod.yml --env-file .env up -d
   cd ~/reservechain/edge && docker compose -p rc-edge -f docker-compose.edge.yml up -d
   ```
4. Still down? Read the WordPress, db and Caddy logs (§7.4). Typical causes:
   - **Disk full** → §8.7.
   - **TLS or DNS change** → check the Caddy logs and DNS.
   - **A bad release** → `./rollback.sh --to previous` (§4).
   - **The database will not start** → check the db logs. If the data is corrupt, restore (§6.1).
5. Check `/health` and the Operations widget.

**Result:** HTTP 200 and `"status":"ok"`. Record the timeline.

### 8.2 Audit chain failure

Triggered by `audit_chain` FAIL, `triggers` FAIL, a snapshot showing *MODIFIED*, or a manual verification showing *FAILED*.

**Steps**
1. **Do not "fix" any data.** Do not run any UPDATE or DELETE, and do not restore yet.
2. Declare **SEV-2** (SEV-1 if tampering is evident).
3. Read the errors: Audit trail → **Last verification** (`content` / `link` / `gap`, with the first entry number). Or run `W rc audit-verify`.
4. Preserve evidence:
   - export the audit JSONL ([01 §14.3](01-CMS-ADMIN-MANUAL.md#143-export-jsonl));
   - run `./backup.sh` (it refuses to run if the triggers are missing; in that case take a raw `mysqldump` as root);
   - copy the WordPress and db logs.
5. Compare:
   - with the last on-chain anchor (`AuditAnchor.verify(seq, head)`): entries up to the anchored sequence number must match;
   - with the previous JSONL exports and backups, to locate the changed rows.
6. If tampering is suspected, switch the site to **Maintenance** and follow §8.3.
7. **Triggers missing, no tampering** (for example after a manual DB operation):
   ```bash
   W eval 'RC\Install::create_triggers();'
   W rc tamper-test        # must print UPDATE → REJECTED and DELETE → REJECTED
   ```
8. **`gap` right after a restore:** the restore kept a stale `AUTO_INCREMENT`. Restore again with the current `restore.sh` ([KI-07](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register)).
9. After resolution, the post-incident review decides whether to restore from a known-good backup. A restore discards later entries, which are kept in the JSONL. The review also decides whether to anchor a new head.

### 8.3 Suspected compromise

Examples: unknown admin activity, an unexpected `settings.changed` or role change, offer-like content, a sustained `failed_logins` alert.

**Steps**
1. **Contain:**
   - switch the site mode to **Maintenance**;
   - **Unpublish** any unauthorised content;
   - switch off any gated module that was enabled without authorization.
2. **Revoke sessions:**
   - reset the passwords of affected accounts;
   - reset their MFA (`W eval 'RC\Auth::disable_mfa(<ID>);'`), which revokes their app tokens;
   - delete or downgrade any unknown users (Users screen; the action is audit-logged).
3. **Rotate secrets** in the `.env`: DB passwords (coordinate with the db container), `RC_TOKEN_SECRET` (this invalidates **all** API tokens, MFA encryption and signed links, so plan the MFA re-enrolment), the WordPress salts (this logs everyone out), `RC_HEALTH_TOKEN`, and the SMTP credentials. Then `deploy.sh --push-env`, or edit on the server and recreate the containers.
4. **Preserve evidence:** audit JSONL export, a backup, logs, an anchor of the current head.
5. Failed logins: look at the `auth.login_failed` entries (hashed logins and IPs) for a pattern. Block the source at the firewall or Cloudflare. Confirm the login lockout works (5 failures → 15 minutes).
6. **Contracts affected?** The multisig pauses ([05 §10](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#10-incident-response)).
7. **Notify:** ReserveChain counsel decides on regulatory and data-subject notifications (FADP/GDPR timelines).
8. **Recover:** patch, redeploy from a clean tag (§3.2), verify the chain, and return to the previous site mode.

### 8.4 Lost administrator MFA

**Steps**
1. Confirm the person's identity out of band. Two people take part.
2. On the server: `W user get <login> --field=ID`, then `W eval 'RC\Auth::disable_mfa( <ID> );'`.
3. The person signs in with their password and sets MFA up again immediately ([01 §2.2](01-CMS-ADMIN-MANUAL.md#22-set-up-mfa-first-sign-in)).
4. If the person is locked out by failed attempts, wait 15 minutes, or clear the lockout: `W transient delete --all`.
5. If the **password** is also lost: `W user update <login> --user_pass='<new temporary>'`. Hand the temporary password over securely, and the person changes it at once.
6. Record the ticket. The audit trail shows `auth.mfa_disabled` with actor `wp-cli`.

Prevention: always have **at least two** administrators, each with saved recovery codes.

### 8.5 Waitlist or contact failures

**Steps**
1. `W rc-ops test-alert` tests mail delivery.
2. Check the SMTP values in `.env` (`WORDPRESS_SMTP_*`; an empty host means PHP `mail()`, which often fails on cloud hosts). Check the provider's quota and logs, and the SPF/DKIM records (§9).
3. Look for PHP errors on `/wp-json/rc/v1/waitlist` and `/contact` in the WordPress logs.
4. While mail is broken, waitlist registrations are still stored as `pending_confirmation`. They are purged after 30 days, so fix mail within that window, or the registrants will need to register again.

### 8.6 Backup stale or failed

**Steps**
1. Read `~/reservechain/production/logs/backup.log`.
2. Run `./backup.sh` manually and watch the output.
3. Typical causes:
   - **Disk full** → §8.7.
   - **Passphrase file or age recipient missing** → check `.env`.
   - **rclone credentials expired** → `rclone config reconnect`.
   - **Triggers missing** → the script refuses on purpose, see §8.2.
4. This is SEV-3, but fix it the same day.

### 8.7 Disk above 85 %

**Steps**
1. Run `df -h` and `docker system df`.
2. Free space:
   - `docker image prune -a`;
   - check the `BACKUP_DIR` retention settings;
   - truncate large logs (the weekly cron already does this above 20 MB);
   - remove old `releases/` (5 are kept).
3. Grow the volume if needed.

---

## 9. Domain, DNS, email and analytics setup notes

Record every value in the configuration record (`docs/HANDOVER-CHECKLIST.md §4`). All accounts are owned by ReserveChain.

**Domain and registrar**
- Turn on registrar lock and auto-renew. Use two administrators with MFA.
- Note the expiry date in the monthly checklist.

**DNS** (Cloudflare or Route 53 recommended; DNSSEC on)

| Record | Value |
|---|---|
| `A` `reservechain.io` | server IP |
| `A` `staging.reservechain.io` | server IP (same host) |
| `CAA` | `0 issue "letsencrypt.org"` (Caddy uses Let's Encrypt / ZeroSSL; add `0 issue "zerossl.com"` if needed) |
| TTL | 300 s on A records, for fast disaster-recovery cut-over |
| If proxied through Cloudflare | SSL mode **Full (strict)**. WAF and rate-limit rules for `/wp-login.php`, `/wp-json/rc/v1/auth/*`, `/waitlist`, `/verify`, `/support` |

Until DNS exists, `deploy.sh` defaults the hosts to `<ip>.sslip.io` and `staging.<ip>.sslip.io`. To change hosts later, redeploy with `--host`, and update `SITE_HOST` in the `.env`.

**Email (transactional)** — provider **[to be decided by ReserveChain]**
1. Create a sending domain (for example `mail.reservechain.io` or the root domain) with the provider.
2. DNS records:
   - **SPF** (`v=spf1 include:<provider> -all`);
   - **DKIM** (CNAME or TXT from the provider);
   - **DMARC**: start with `v=DMARC1; p=quarantine; rua=mailto:dmarc@reservechain.io`, and move to `p=reject` after 4 weeks of clean reports.
3. Put the SMTP values in each environment's `.env`: `WORDPRESS_SMTP_HOST`, `PORT` (587), `SECURE` (tls), `USER`, `PASSWORD`, `FROM`, `FROM_NAME`. Then `deploy.sh --push-env` (or edit on the server and recreate the containers).
4. Test with `W rc-ops test-alert` and a waitlist registration on staging.
5. Set **Settings & modules → Contact / support inbox email** to the monitored mailbox.

**Analytics** — provider **[to be decided by ReserveChain]**
- Use a privacy-respecting, consent-aware tool (for example self-hosted Plausible or Matomo).
- There is no analytics code in the theme today. Adding it is a code change to `themes/reservechain/` (pull request): the Content Security Policy must allow the analytics host, and the cookie notice (`/legal/cookies/`) must be updated by counsel.
- Never add tracking to staging, to passport pages showing restricted information, or to the portal without counsel approval.

**security.txt**
- Publish `/.well-known/security.txt` with a ReserveChain security contact **[to be provided]**.
