# 01 — CMS Administration Manual

**Readers:** all ReserveChain staff who use wp-admin. **Source of truth:** `reservechain-core/includes/` (`class-install.php`, `class-auth.php`, `class-workflow.php`, `class-admin.php`, `class-settings.php`, `class-i18n.php`, `class-waitlist.php`, `class-compliance.php`, `class-audit-log.php`, `class-monitoring.php`) and `themes/reservechain/inc/seo.php`.
**Related:** [02 Registry](02-REGISTRY-AND-PASSPORT-MANUAL.md) · [07 Operations](07-OPERATIONS-BACKUP-DR-MANUAL.md) · [Troubleshooting](TROUBLESHOOTING-AND-KNOWN-ISSUES.md) · [Index](README.md)

Credentials for the admin account and the demo accounts are **provided separately**. They never appear in this manual.

---

## Contents

1. [Roles and permissions](#1-roles-and-permissions)
2. [Signing in and multi-factor authentication](#2-signing-in-and-multi-factor-authentication)
3. [Pages and translations](#3-pages-and-translations)
4. [The four-eyes workflow](#4-the-four-eyes-workflow)
5. [Media and documents](#5-media-and-documents)
6. [SEO fields](#6-seo-fields)
7. [Menus](#7-menus)
8. [Website modes, sections and module authorizations](#8-website-modes-sections-and-module-authorizations)
9. [Disclosures and jurisdictions](#9-disclosures-and-jurisdictions)
10. [Waitlist management](#10-waitlist-management)
11. [Support inbox](#11-support-inbox)
12. [Compliance page and user eligibility](#12-compliance-page-and-user-eligibility)
13. [System health and the Operations widget](#13-system-health-and-the-operations-widget)
14. [Audit trail](#14-audit-trail)

---

## 1. Roles and permissions

Roles are created by `Install::create_roles()` on every plugin upgrade. Any manual change to these roles, for example with a role-editor plugin, is **overwritten** on the next deploy. To change a role permanently, change `class-install.php`.

### 1.1 Custom capabilities

| Capability | Meaning |
|---|---|
| `rc_manage_registry` | Create and edit Asset Registry records |
| `rc_submit` | Submit content for review |
| `rc_approve` | Approve, or request changes on, content under review (four-eyes) |
| `rc_publish` | Publish and unpublish approved content |
| `rc_archive` | Archive and restore content |
| `rc_manage_compliance` | Manage KYC/KYB/AML/sanctions statuses and jurisdiction controls |
| `rc_manage_waitlist` | View and export the waitlist; see the support inbox |
| `rc_view_audit` | View, verify and export the audit trail; see System health and Proof of Reserves |
| `rc_anchor_audit` | Record an on-chain anchor of the audit chain head |
| `rc_manage_settings` | Change site mode, sections, modules, disclosures, network and Web3 settings |
| `rc_authorize_modules` | Switch on gated modules and locked modes, with a written authorization reference |
| `rc_manage_intake` | Work the asset-owner intake pipeline |
| `rc_manage_payments` | Work payment intents (administrator only by default) |

### 1.2 Permissions matrix

✔ = granted · — = not granted.

| Permission | administrator | rc_compliance_officer | rc_reviewer | rc_registry_manager | rc_editor | rc_auditor |
|---|---|---|---|---|---|---|
| Edit pages and posts | ✔ | ✔ | ✔ | ✔ | ✔ | — |
| Upload files | ✔ | ✔ | ✔ | ✔ | ✔ | — |
| Edit registry records | ✔ | ✔ | ✔ | ✔ | — | read-only¹ |
| `rc_manage_registry` | ✔ | — | — | ✔ | — | — |
| `rc_submit` | ✔ | —² | —² | ✔ | ✔ | — |
| `rc_approve` (never own work) | ✔ | ✔ | ✔ | — | — | — |
| `rc_publish` | ✔ | ✔ | — | — | — | — |
| `rc_archive` | ✔ | ✔ | — | — | — | — |
| `rc_manage_compliance` | ✔ | ✔ | — | — | — | — |
| `rc_manage_waitlist` (waitlist + support inbox) | ✔ | ✔ | — | — | — | — |
| `rc_view_audit` | ✔ | ✔ | ✔ | — | — | ✔ |
| `rc_anchor_audit` | ✔ | — | — | — | — | — |
| `rc_manage_settings` | ✔ | — | — | — | — | — |
| `rc_authorize_modules` | ✔ | — | — | — | — | — |
| `rc_manage_intake` | ✔ | ✔ | ✔ | ✔ | — | — |
| `rc_manage_payments` | ✔ | — | — | — | — | — |
| List and edit users | ✔ | ✔ | — | — | — | — |
| Modify audit log rows | blocked by database triggers for **every** role, including administrators | | | | | |

¹ Auditors can open draft records so that they appear in lists. Saving is blocked by the workflow layer.
² Reviewers and compliance officers do not hold `rc_submit`. Content they create must be submitted by an editor or registry manager, or by an administrator.

> **Gap to know about:** the Proof of Reserves screen requires `rc_view_audit`, which registry managers do not have. In practice, reviewers and compliance officers create PoR snapshots and record attestations ([03](03-PROOF-OF-RESERVES-MANUAL.md)).

### 1.3 Separation-of-duties rules (enforced by code)

- The approver must differ from the person who submitted the item **and** from the last editor.
- Editors and registry managers can never publish directly. A Publish click is converted into "Submit for review".
- Any change after approval sends the item back to Under Review.
- Gated modules need a written authorization reference. Locked modes also need a server-side constant.

### 1.4 Grant or change a user's role

**Before you start**
- You need the `administrator` or `rc_compliance_officer` role.
- You need a written request naming the person and the role, approved by a ReserveChain manager.

**Steps**
1. Open **Users → All Users** (`http://localhost:8088/wp-admin/users.php`).
2. To add someone, click **Add New User**. Use the person's own work email address, never a shared address.
3. In **Role**, choose the narrowest role that fits (§1.2).
4. Click **Add New User** or **Update User**.
5. Ask the person to sign in and set up MFA (§2.2). If **Enforce MFA for all staff roles** is on, they cannot open any other admin page until they do.

**Result:** the audit trail shows `user.created` or `user.role_changed`, with the old and new role.

> [Screenshot: Users list with role column — http://localhost:8088/wp-admin/users.php]

**If something goes wrong**
- *The role is not in the list:* the plugin is not active, or the upgrade has not run. Load any admin page as an administrator, or run `W eval 'RC\Install::upgrade();'`.
- *The person left the company:* change their role to **No role for this site** or delete the user. Both actions are audit-logged. Never reuse the account for someone else.

---

## 2. Signing in and multi-factor authentication

MFA uses TOTP (RFC 6238): 6 digits, a 30-second step and ±1 step tolerance. A code can be used only once. The secret is encrypted at rest with AES-256-GCM, using a key derived from `RC_TOKEN_SECRET`.

When **Enforce MFA for all staff roles** is ticked (the default), every staff role is redirected to the profile page until MFA is set up. Staff roles are administrator, editor, `rc_editor`, `rc_reviewer`, `rc_compliance_officer`, `rc_registry_manager` and `rc_auditor`.

### 2.1 Sign in

**Before you start:** you need your username, password (provided separately) and authenticator app.

**Steps**
1. Go to `http://localhost:8088/wp-login.php` (production: `https://reservechain.io/wp-login.php`).
2. Enter your **Username or Email Address** and **Password**.
3. In **Authentication code (if MFA is enabled)**, enter the 6-digit code that your authenticator app shows now. You can also enter an unused 10-character recovery code.
4. Click **Log In**.

**Result:** the WordPress dashboard opens. The audit trail records `auth.login` with `"mfa": true`. Staff sessions last 8 hours, or 24 hours if you ticked **Remember Me**.

> [Screenshot: Login form with the Authentication code field — http://localhost:8088/wp-login.php]

**If something goes wrong**
- *"A valid authentication code is required for this account":* the code was missing, wrong, already used, or your phone's clock is off. Wait for the next code. Check that the phone uses automatic time.
- *"The credentials provided are incorrect":* the username or password is wrong. The message is deliberately generic.
- *"Too many failed attempts. Try again in 15 minutes":* 5 failures from the same network for the same username lock that pair for 15 minutes. Wait. If it was not you, report it to ops; the attempts appear as `auth.login_failed` entries.

### 2.2 Set up MFA (first sign-in)

**Before you start:** install an authenticator app, such as 1Password, Authy, Google Authenticator or Microsoft Authenticator.

**Steps**
1. Sign in with your username and password, leaving the code field empty. If MFA is enforced, you are taken to **Profile → Multi-factor authentication** (`http://localhost:8088/wp-admin/profile.php#rc-mfa`).
2. Scan the QR code with your authenticator app, or type the key shown under **Or enter manually**.
3. Type the current 6-digit code into **6-digit code** and click **Enable MFA**.
4. A green box shows **8 single-use recovery codes**. Store them **now** in the ReserveChain password manager, in your personal vault entry. They are shown only once.
5. Sign out and sign in again with a code to confirm the setup works.

**Result:** the status shows **Enabled** with "8 recovery codes remaining". The audit trail records `auth.mfa_enabled`.

> [Screenshot: MFA set-up block with QR code — http://localhost:8088/wp-admin/profile.php#rc-mfa]

**If something goes wrong**
- *The code is rejected at Enable MFA:* the phone clock is wrong, or you scanned an old QR code. Reload the profile page, which generates a new secret, and scan again.
- *You closed the page before saving the recovery codes:* disable MFA (§2.4) and set it up again to get a new set.

### 2.3 Use a recovery code

1. On the login form, enter one recovery code (10 characters, letters and digits) in **Authentication code**.
2. Each code works once. The audit trail records `auth.recovery_code_used` with the number of codes remaining.
3. When 3 or fewer codes remain, regenerate them: disable MFA and set it up again (§2.4, §2.2).

### 2.4 Disable or re-enrol MFA (you still have the device)

1. Open **Profile** (`http://localhost:8088/wp-admin/profile.php#rc-mfa`).
2. Enter a current code in **Current code** and click **Disable MFA**.
3. All your app and API sessions are revoked, and `auth.mfa_disabled` is logged.
4. If enforcement is on, set MFA up again immediately (§2.2).

### 2.5 Lost device and no recovery codes (break-glass)

There is no button for one user to reset another user's MFA. This is deliberate. Recovery uses WP-CLI on the server and is logged.

**Before you start**
- Two people must take part: the requester and an administrator who holds server access. The requester's identity must be confirmed out of band, for example by video call with a known face, or a callback to a known phone number.
- You need a ticket or incident reference.

**Steps**
1. Find the user ID: `W user get <login> --field=ID`.
2. Reset MFA: `W eval 'RC\Auth::disable_mfa( <ID> );'`. This deletes the secret and recovery codes and revokes every session.
3. Check: `W user meta get <ID> rc_mfa_enabled` should return nothing.
4. Ask the user to sign in with their password only, then set up MFA again at once (§2.2).
5. Record the ticket reference in the incident log.

**Result:** the audit trail shows `auth.mfa_disabled` with actor `wp-cli`.

**If something goes wrong**
- *The person is the only administrator and has no server access:* the server operator performs the steps above. This is why ReserveChain should have **at least two named administrators**. See also [07 §8.4](07-OPERATIONS-BACKUP-DR-MANUAL.md#84-lost-administrator-mfa).
- *The account may be compromised:* treat it as an incident ([07 §8.3](07-OPERATIONS-BACKUP-DR-MANUAL.md#83-suspected-compromise)). Reset the password as well, and review the account's audit entries.

---

## 3. Pages and translations

### 3.1 Seed files versus CMS editing

Read this before you edit any page.

The website's pages are kept as HTML files in `wordpress/plugins/reservechain-core/seed/pages/`. The file name is the URL path with `/` written as `__`. For example, `platform__custody.html` is `/platform/custody/`, and the Spanish and Italian versions are `platform__custody.es.html` and `platform__custody.it.html`. The command `wp rc seed --pages-only` copies these files into WordPress.

**`deploy/deploy.sh` runs `wp rc seed --pages-only` on every deploy.** That command:
- overwrites the title, content, excerpt, kicker and order of every page that has a seed file, and publishes it directly;
- overwrites the ES/IT translation fields of those pages;
- deletes and rebuilds the primary mega menu and the 7 footer menus.

In practice this means:

| Page type | Where to edit | What survives a deploy |
|---|---|---|
| A page that has a file in `seed/pages/` (all standard site pages) | **In the seed file**, through a pull request, then deploy | Only the seed-file content. CMS edits to these pages are lost on the next deploy |
| A page created in the CMS with no seed file (for example a news post, or a new landing page) | In the CMS, under the four-eyes workflow | Everything |
| SEO title and meta description (§6) | Either. The seed file only overwrites them when it has `seo_title` / `seo_desc` keys | CMS values survive unless the seed file sets those keys |
| Menus | In `Seed::menus()` (code) | Only the coded menus |

**Recommended policy:**
- Make permanent changes to standard pages in the seed files, and review them as code.
- Use CMS editing for urgent fixes. Open a pull request with the same change at once, so the next deploy does not undo the fix.

The technical fix for this behaviour is listed in [Troubleshooting KI-01](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register).

### 3.2 Edit a page in the CMS

**Before you start**
- You need `rc_editor`, `rc_registry_manager` or any role that can edit pages.
- Read the content rules in `docs/CONTENT-AUTHORING-GUIDE.md` §4. Never invent figures, partners or dates. Words such as "buy", "invest" and "guaranteed" are prohibited.
- Check §3.1 to see whether the page will be overwritten by the next deploy.

**Steps**
1. Open **Pages** (`http://localhost:8088/wp-admin/edit.php?post_type=page`) and click the page title.
2. Edit the content. The content is plain HTML built from the theme's classes and shortcodes (Content Authoring Guide §2–3). Do not add `<script>`, inline styles or external images.
3. Keep the shortcodes that pages must contain: `[rc_disclosure]` on the homepage, asset pages, waitlist and documentation pages, and `[rc_provisional]` on asset, program and registry pages.
4. Click **Update** (or **Save Draft** for a new page). You cannot publish directly:
   - If the page was **Published**, your change moves it to **Under Review**, and the old version stays offline until it is re-published. Prefer the next option for live pages.
   - For a live page with large changes, ask a publisher to **Unpublish** first, or work on a copy: **Add New** page → paste → submit.
5. In the **Four-eyes workflow** box (right column), click **Submit for review**.

**Result:** the page appears in **ReserveChain → Review queue** under "Under review". The audit trail shows `workflow.submit`.

> [Screenshot: Page editor with Four-eyes workflow box and Translations box — http://localhost:8088/wp-admin/post.php?post=<ID>&action=edit]

**If something goes wrong**
- *Notice "Changes to published content require review":* this is expected (step 4). A reviewer must approve, and a publisher must publish again.
- *Notice "Direct publishing is disabled":* this is also expected. The item was routed to review.
- *The page looks unstyled:* a class name is misspelt. Compare with `docs/CONTENT-AUTHORING-GUIDE.md` §2.

### 3.3 Edit translations (ES / IT)

English is the authoritative version. The ES and IT texts are stored in the **Translations (ES / IT)** box on the same page. A missing translation falls back to English, with a visible notice. Legal texts need translations approved by counsel.

**Steps**
1. Open the page (§3.2) and scroll to **Translations (ES / IT)**.
2. Expand **Español** or **Italiano**. A pill shows *translated* or *missing*.
3. Fill in **Title**, **Summary** and **Content (HTML)**. Keep exactly the same HTML structure and the same shortcodes as the English version.
4. Click **Update**, then **Submit for review**. Translation changes change the content fingerprint, so any previous approval is reset.
5. Check the result on the site with `?lang=es` or `?lang=it`, for example `http://localhost:8088/platform/custody/?lang=es`.

**Result:** the audit trail records `content.translation` and lists the fields that changed, for example `es.content`.

**If something goes wrong:** the translation disappeared after a deploy → see §3.1. Put the translation in the `.es.html` or `.it.html` seed file.

### 3.4 Change a seeded page permanently (seed file)

1. Edit `seed/pages/<path>.html`, and the `.es.html` and `.it.html` versions, in the repository.
2. The header comment must keep `title:`, `excerpt:`, `kicker:` and `order:`. The optional keys are `template:`, `seo_title:` and `seo_desc:`.
3. Open a pull request. A second person reviews it (four-eyes).
4. Deploy to staging ([07 §3](07-OPERATIONS-BACKUP-DR-MANUAL.md#3-deploying-a-release)), check the page, then deploy to production.
5. To resync locally without a deploy: `W rc seed --pages-only`.

---

## 4. The four-eyes workflow

Every page, post and registry record goes through this workflow (`class-workflow.php`).

```
Draft ──submit──▶ Under Review ──approve──▶ Approved ──publish──▶ Published
  ▲                 │   ▲                      │                     │
  └──request changes┘   └──── (auto-return if content changes) ──────┘
Published ──unpublish──▶ Unpublished ──submit──▶ Under Review
Draft / Under Review / Approved / Unpublished / Published ──archive──▶ Archived ──restore──▶ Draft
```

| Action (button label) | From | To | Who |
|---|---|---|---|
| **Submit for review** | Draft, Pending, Unpublished | Under Review | `rc_submit` or `rc_manage_registry` |
| **Approve** | Under Review | Approved | `rc_approve`, and **not** the submitter or the last editor |
| **Request changes** | Under Review, Approved | Draft | `rc_approve` |
| **Publish** | Approved | Published | `rc_publish`, and only if the content still matches the approved fingerprint |
| **Unpublish** | Published | Unpublished | `rc_publish` |
| **Archive** | any except Archived | Archived | `rc_archive` |
| **Restore to draft** | Archived | Draft | `rc_archive` |

**Approval is bound to a fingerprint.** On approval, the system stores a SHA-256 fingerprint. It covers the title, the content, the excerpt and every `_rc_*` field, which includes all registry fields and the ES/IT translations. **SEO fields are not included** (§6).

### 4.1 Submit an item for review

**Before you start:** the item is saved as a draft and you hold `rc_submit` or `rc_manage_registry`.

**Steps**
1. Open the item. In the **Four-eyes workflow** box, check that **State** is *Draft* or *Unpublished*.
2. Click **Submit for review**.

**Result:** State changes to *Under Review*. The history shows "submit by <you>". The item appears in the Review queue (`http://localhost:8088/wp-admin/admin.php?page=rc-review`).

### 4.2 Approve an item

**Before you start**
- You hold `rc_approve` (reviewer, compliance officer or administrator).
- You neither submitted the item nor edited it last.

**Steps**
1. Open **ReserveChain → Review queue** (`http://localhost:8088/wp-admin/admin.php?page=rc-review`).
2. Under **Under review**, click the item title to open it. Read the content in full. For registry records, also follow [02 §10 QC checklist](02-REGISTRY-AND-PASSPORT-MANUAL.md#10-qc-checklist-before-submitting-or-approving).
3. **Do not change anything** while reviewing. A save by you makes you the last editor, and you could then no longer approve.
4. Click **Approve**, in the Review queue row or in the item's Four-eyes workflow box.

**Result:** State becomes *Approved*. The item moves to "Approved — ready to publish". The audit trail records `workflow.approve` with the fingerprint.

> [Screenshot: Review queue with Under review and Approved tables — http://localhost:8088/wp-admin/admin.php?page=rc-review]

**If something goes wrong**
- *The Approve button is greyed out.* Hover over it to read the reason:
  - "Four-eyes rule: you submitted or last edited this item…" → another reviewer must approve it.
  - "Your role does not permit this action." → you lack `rc_approve`.

### 4.3 Request changes

1. In the Review queue, or in the item, click **Request changes**. The item returns to *Draft* and any approval is cleared.
2. The button has no comment field. Tell the author what to change through ReserveChain's usual channel (email or ticket), quoting the record number.

### 4.4 Publish

**Before you start**
- You hold `rc_publish` (compliance officer or administrator).
- The item is *Approved*.
- For registry records: the record is not marked *Verified* unless the evidence supports it ([02 §8](02-REGISTRY-AND-PASSPORT-MANUAL.md#8-verification-statuses)).

**Steps**
1. In the Review queue, under **Approved — ready to publish**, click **Publish**.
2. Open the public URL and check the page. For registry records, open the passport ([02 §9](02-REGISTRY-AND-PASSPORT-MANUAL.md#9-digital-asset-passports)).

**Result:** State becomes *Published*. Only published items appear on the website, in the API, in passports and in the app.

**If something goes wrong:** the Publish button is greyed out with "Content differs from the approved version." Someone changed the item after approval. It must be approved again.

### 4.5 Why was my approval invalidated?

An approved item goes back to *Under Review* on its own when anyone saves a change to the title, content, excerpt, any registry field or any translation. The history shows **auto_return** with "Content changed after approval — approval invalidated." This guarantees that the exact approved content is what gets published.

Common causes:
- someone fixed a typo after approval;
- someone re-saved the record with a changed relation;
- someone added a translation.

**Fix:** a reviewer approves the item again.

### 4.6 Unpublish

1. Open the published item. In the Four-eyes workflow box, click **Unpublish**. You need `rc_publish`.
2. The item disappears from the website, API and passports at once. The content and its history are kept.
3. To bring it back: edit if needed → **Submit for review** → **Approve** → **Publish**.

Use Unpublish as the first response to wrong or unauthorised content ([07 §8](07-OPERATIONS-BACKUP-DR-MANUAL.md#8-incident-runbooks)).

### 4.7 Archive and restore

- **Archive** (`rc_archive`) removes an item from all lists and from the website. Use it for superseded records. Archived records keep their record numbers forever.
- **Restore to draft** brings an item back as a draft.
- Do not delete registry records. Deletion is audit-logged (`content.deleted`), but it breaks the evidence chains of passports.

---

## 5. Media and documents

### 5.1 How fingerprints work

- Every upload to the Media Library gets a SHA-256 fingerprint at upload time (`_rc_sha256` on the attachment). The upload is logged as `media.uploaded` with its hash and MIME type.
- An **rc_document** record (Asset Registry → Documents) wraps a file with its type, issuer, date, version and audience. Its fingerprint is recomputed when the file is attached, and logged in `registry.updated`.
- Visitors check a file with the Verify tool, which hashes the file **in their browser** and asks `/wp-json/rc/v1/verify?hash=…`. Only published, public documents match.
- **The file of a published document cannot be replaced.** The file field locks with "File is locked because this document is published. Publish a new document version instead."

Uploads are checked by extension **and** by content. PDFs containing JavaScript, launch actions or embedded files are rejected. The maximum upload size is set by PHP (50 MB locally, `deploy/php-uploads.ini`).

### 5.2 Add a document

**Before you start**
- You hold a role that can edit registry records (registry manager, reviewer, compliance officer or administrator).
- You have the original file. Never re-save, re-scan or "optimise" an evidence PDF; that changes its fingerprint.

**Steps**
1. Open **Asset Registry → Documents → Add Document** (`http://localhost:8088/wp-admin/post-new.php?post_type=rc_document`).
2. **Title:** use a descriptive name, for example `CoA 0004512 — IGAS — Cu Lot #03-K-07 (scan)`. See [02 §11](02-REGISTRY-AND-PASSPORT-MANUAL.md#11-naming-conventions).
3. **File:** click **Select / upload file** and choose the original file.
4. Fill in **Document type**, **Issued by**, **Issue date** and **Version** (for example `v1.0`).
5. **Related records:** select the lot, batch, container, coil, program or token program the document belongs to.
6. **Show in public document library:** *Yes* only for documents intended for the public Documents page.
7. **Audience:** *Public*, or a restricted audience ([04 §8](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md#8-data-rooms-audiences-and-signed-downloads)). A restricted file is moved to private storage automatically.
8. **Verification status:** usually *Pending verification* ([02 §8](02-REGISTRY-AND-PASSPORT-MANUAL.md#8-verification-statuses)).
9. Click **Save Draft**. The record number (`RC-DOC-…`) and the **SHA-256** appear in the **Registry record** box.
10. Compare the SHA-256 with a hash you compute yourself: `certutil -hashfile file.pdf SHA256` on Windows, or `shasum -a 256 file.pdf` on macOS and Linux.
11. Click **Submit for review**.

**Result:** after approval and publication, the document appears in passports and in the library, and `/verify` matches it.

> [Screenshot: Document editor with file field and SHA-256 — http://localhost:8088/wp-admin/post-new.php?post_type=rc_document]

**If something goes wrong**
- *Upload rejected, "File content does not match a PDF document":* the file is not a real PDF, or it was renamed. Get the original file.
- *Upload rejected because of active content:* the PDF contains JavaScript or embedded files. Ask the issuer for a flattened copy and record why the file differs.
- *Upload fails silently, or with "exceeds the maximum upload size":* see [Troubleshooting KI-03](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register).

### 5.3 Replace a document (a new version)

Replacing a published file is blocked on purpose. A new file is a new version with a new fingerprint.

1. Create a **new** document (§5.2) with the same title plus a new **Version**, for example `v1.1`.
2. Have it reviewed and published.
3. On each evidence record that pointed to the old document (CoA, custody record and so on), change **Document** to the new one, then submit, approve and publish. Each such edit resets that record's approval, as expected.
4. Unpublish or archive the old document. Archive it if it was simply superseded; unpublish it if it was wrong.

**Result:** the passport's document list and **Merkle root** change, and the change is visible in the audit trail.

### 5.4 Media Library (non-evidence images)

Images used on web pages go to **Media → Add New** (`http://localhost:8088/wp-admin/media-new.php`). They are fingerprinted and logged too, but they are not evidence. Never use a Media Library file as evidence without wrapping it in an rc_document.

---

## 6. SEO fields

Each page has an **SEO** box in the right column. It has two fields:

- **SEO title** — replaces the browser title.
- **Meta description** — up to 320 characters. If empty, the page excerpt is used.

The theme also writes the canonical link, `hreflang` links (EN/ES/IT plus x-default), Open Graph tags and, on the homepage, Organization JSON-LD.

**Steps**
1. Open the page and fill in the **SEO** box. Keep to the content rules: no prices, no claims.
2. Click **Update**.

**Note:** SEO fields are **not** part of the approval fingerprint. Saving a published page as an editor still sends it to review (§3.2). An administrator or compliance officer can save SEO changes on a live page without a re-approval. Use that ability carefully, because it is a known gap ([Troubleshooting KI-06](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register)).

**Indexing rules**
- Staging and development are never indexed: `noindex` is set whenever `RC_ENV` is not `production`.
- Passport pages are `noindex` unless the option `rc_index_passports` is set. Set it only after a ReserveChain decision: `W option update rc_index_passports 1`.

> [Screenshot: SEO meta box — http://localhost:8088/wp-admin/post.php?post=<ID>&action=edit]

---

## 7. Menus

The theme has 8 menu locations: **Primary navigation** (mega menu) and **Footer — Platform, Assets, Enterprise, Participation, Company, Resources, Legal**.

**Menus are built by code.** `Seed::menus()` deletes and rebuilds every menu on each `wp rc seed --pages-only`, which means on every deploy. Edits made in **Appearance → Menus** (`http://localhost:8088/wp-admin/nav-menus.php`) last only until the next deploy.

### 7.1 Change a menu permanently

1. Edit the menu definition in `includes/class-seed.php`, in the `menus()` function (around line 638). Menu items point at page paths.
2. The page must exist (have a seed file) before the menu can link to it.
3. Open a pull request, have it reviewed, and deploy to staging, then to production.

### 7.2 Hide a section without editing menus

Use **Settings & modules → Website sections** (§8.2). Hidden sections return HTTP 404 and are removed from the navigation automatically.

---

## 8. Website modes, sections and module authorizations

All of these are on **ReserveChain → Settings & modules** (`http://localhost:8088/wp-admin/admin.php?page=rc-settings`). Only users with `rc_manage_settings` (administrators) see this page. Every save is audit-logged as `settings.changed`, with a field-by-field diff.

> [Screenshot: Settings & modules, Website mode panel — http://localhost:8088/wp-admin/admin.php?page=rc-settings]

### 8.1 Website modes

| Mode | Behaviour | Locked? |
|---|---|---|
| Development | Staff preview; the public sees the holding page (HTTP 503) | No |
| **Pre-Launch** (default) | Full informational site, waitlist open, no offering | No |
| Waitlist | Homepage, disclosures, waitlist, contact and legal pages only | No |
| Documentation Release | Pre-Launch, with the whitepaper and document library emphasised | No |
| Asset Verification | Pre-Launch, with the registry and passports emphasised | No |
| Enterprise Onboarding | Pre-Launch, with enterprise and asset-owner intake emphasised | No |
| Maintenance | Holding page (HTTP 503) for visitors; staff are unaffected; the API returns 503 to non-staff | No |
| Eligibility | KYC/KYB eligibility checks open | **Locked** |
| Early Participation | Token-acquisition module visible | **Locked** |
| Live Offering | Offering live under definitive documentation | **Locked** |
| Redemption | Physical redemption requests open | **Locked** |

**Change to an open mode**
1. Select the mode's radio button and click **Save settings (changes are audited)**.
2. Check the coloured bar at the top of ReserveChain admin pages. It shows the environment and the site mode.

**Change to a locked mode (dual key)**

A locked mode needs two independent actions:
1. **Key 1, deployment action:** the server operator adds `define( 'RC_ALLOW_MODE_<MODE>', true );` to `wp-config.php`. Examples: `RC_ALLOW_MODE_EARLY_PARTICIPATION`, `RC_ALLOW_MODE_REDEMPTION`. On the single-server deployment, add it through the environment's WordPress config extras, then redeploy. Until this is done, the radio button is disabled and shows "not set — locked".
2. **Key 2, admin action:** an administrator with `rc_authorize_modules` enters the **Written authorization reference** next to the mode (for example the board resolution number), selects the mode and saves.

If either key is missing, the save reverts to the previous mode with an error. On success, the reference, the user and the time are stored and audit-logged.

**Never use a locked mode in this engagement.** They exist so the platform does not need rebuilding later.

### 8.2 Website sections

The **Website sections** panel lists 28 sections, from Platform overview to Redemption Portal. Untick a section to hide it: it returns 404 and leaves the navigation. Content is not deleted. In **Waitlist** mode, only the waitlist, legal and contact pages show, whatever the section settings say.

### 8.3 Gated modules

**Open modules** can be switched freely: waitlist, public registry, public passports, verify tool, mobile app registration, and news.

**Gated modules** are built but inactive. Each one needs a written authorization reference *and* the `rc_authorize_modules` capability:

`wallet` · `purchase` · `usdt_payments` · `kyc_kyb` · `eligibility` · `proof_of_reserves` · `reserve_dashboard` · `holdings` · `client_documents` · `redemption` · `unit_selection` · `logistics` · `investor_portal` · `enterprise_portal` · `asset_owner_portal` · `tokenomics` · `contract_info` · `dex_info` · `team_profiles` · `partners_directory` · `mobile_app_links` · `waitlist_nationality`

#### Authorize a gated module

**Before you start**
- You need **written authorization** from ReserveChain's authorized signatories, with a reference number. The authorization must name the module and the environment.
- You are an administrator. A second person (compliance officer) is present or reviews the change afterwards. See "Dual control" below.

**Steps**
1. Open **Settings & modules**, panel **Modules → Gated modules**.
2. Type the reference into the module's **Written authorization reference** field.
3. Tick the module's checkbox.
4. Click **Save settings (changes are audited)**.
5. Check that the module now shows "Authorized <date>".
6. **Dual control:** the second person opens **Audit trail**, filters by `settings`, and confirms that the `settings.changed` entry shows the module, the reference and your user. They record the check in the authorization file.

**Result:** the module is on. `/wp-json/rc/v1/config` reports it as `true`, and the app and website unlock the related screens. The dashboard card "Gated modules active" counts it.

**If something goes wrong:** the error says "…was not activated: a written authorization reference and the rc_authorize_modules capability are required." Either the reference is empty or you are not an administrator. The module stays off.

> **Dual control is a procedure, not a code check.** For gated modules, the code requires one administrator plus a reference. ReserveChain's policy should require the second-person audit check in step 6. Locked modes are enforced dual-key in code (§8.1).

**Switching a gated module off** needs no reference. Untick it and save. Do this immediately if a module was switched on by mistake.

### 8.4 Network (testnet only) and redemption terms

- **Network:** chain ID, network name, explorer URL, token contract and AuditAnchor contract. Mainnet chain IDs (1, 10, 56, 137, 42161, 8453) are refused with "Mainnet chain IDs are not permitted without written authorization."
- **Redemption terms:** fee schedule and minimum. Leave them empty unless written approval exists. While they are empty, live redemption requests are refused ([04](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md)).
- **Security & contact:** **Enforce MFA for all staff roles** (keep it ticked) and **Contact / support inbox email**, which also receives monitoring alerts.

---

## 9. Disclosures and jurisdictions

### 9.1 Edit the mandatory disclosures

The **Mandatory disclosures** panel holds three texts: the **Prelaunch disclosure**, the **EU/EEA notice** and the **Provisional Asset Notice**. They are shown site-wide, in the apps and in emails. A SHA-256 of the disclosure plus the EU notice (the **consent fingerprint**) is stored with every waitlist registration.

**Before you start:** you need counsel-approved wording.

**Steps**
1. Edit the text. You can extend it; you cannot remove it, because an empty field is restored to the default on save.
2. Save. Note the new **Current consent fingerprint**.
3. Record the old and new fingerprints, with the counsel approval reference, in the compliance file. Waitlist registrations after this moment carry the new `consent_version` (`v` plus the first 12 hex characters of the hash).

### 9.2 Jurisdictions

**Before you start:** you need the list confirmed by counsel.

**Steps**
1. Open **Settings & modules → Jurisdictions** (`http://localhost:8088/wp-admin/admin.php?page=rc-settings#jurisdictions`).
2. **Restrict EU/EEA residents** — keep this ticked. It covers AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE IS LI NO.
3. **Additional restricted jurisdictions:** ISO alpha-2 codes, comma-separated. The placeholder default is `CU, IR, KP, SY, RU, BY` and must be confirmed by counsel. Unknown codes are dropped silently, so check the saved value.
4. Save.

**Result:** new waitlist registrations and user eligibility are evaluated against the new list at once. Existing waitlist rows keep the jurisdiction they were stored with.

---

## 10. Waitlist management

Screen: **ReserveChain → Waitlist** (`http://localhost:8088/wp-admin/admin.php?page=rc-waitlist`). You need `rc_manage_waitlist` (compliance officer or administrator).

How registrations work:
- They use double opt-in. The confirmation token is stored only as a hash.
- They are stored with the consent version and consent hash.
- EU/EEA and restricted countries are stored as `restricted`, and **only** if the person opted in to general updates. Otherwise nothing is stored, and only `waitlist.restricted` is logged.
- Registration of interest creates no allocation or entitlement.

> [Screenshot: Waitlist screen with counters and table — http://localhost:8088/wp-admin/admin.php?page=rc-waitlist]

### 10.1 Review the waitlist

The screen shows counters (Total, Confirmed, Institutions, Restricted) and the **latest 200** registrations. For the full list, export it (§10.2).

| Column | Meaning |
|---|---|
| Jurisdiction | `eligible`, `restricted` or `pending` |
| Status | `pending_confirmation`, `confirmed` or `unsubscribed` |
| Consent | Consent version; hover over it to see the full consent hash |

### 10.2 Export (CSV)

**Steps**
1. Click **Export CSV**. The file is `reservechain-waitlist-YYYYMMDD.csv`.
2. Store it only in ReserveChain's approved storage. It contains personal data.
3. Delete local copies when the task is finished.

**Result:** `waitlist.exported` is logged with the row count. Values starting with `=`, `+`, `-` or `@` are prefixed with `'` to block spreadsheet formula injection.

### 10.3 Unsubscribe requests

- Each confirmation email contains a signed unsubscribe link. Using it sets `status = unsubscribed` and `consent_updates = 0`, and logs `waitlist.unsubscribed`.
- **There is no unsubscribe button in the admin.** If a person asks by email, an administrator runs on the server (replace the email address):

  ```bash
  W eval '$h=hash("sha256",strtolower("person@example.com")); global $wpdb; $n=$wpdb->update($wpdb->prefix."rc_waitlist",array("status"=>"unsubscribed","consent_updates"=>0,"updated_at"=>current_time("mysql",true)),array("email_hash"=>$h)); RC\Audit_Log::record("waitlist.unsubscribed","waitlist",0,"Unsubscribed on request (support ticket)",array("email_hash"=>$h,"rows"=>$n));'
  ```

### 10.4 Erasure requests (right to be forgotten)

1. Confirm the requester's identity and log the request.
2. Run on the server:

   ```bash
   W eval '$h=hash("sha256",strtolower("person@example.com")); global $wpdb; $n=$wpdb->delete($wpdb->prefix."rc_waitlist",array("email_hash"=>$h)); RC\Audit_Log::record("waitlist.erased","waitlist",0,"Erasure request processed (ticket ref)",array("email_hash"=>$h,"rows"=>$n));'
   ```
3. Reply to the requester. The audit trail keeps only the email **hash**, which is not personal data in clear.

### 10.5 Retention

- Unconfirmed registrations are deleted automatically after **30 days** by the daily `rc_waitlist_purge` job, and logged as `waitlist.purged`.
- Retention for confirmed registrations is **[to be defined by ReserveChain counsel]**.
- Backups contain waitlist data for their own retention period ([07 §5](07-OPERATIONS-BACKUP-DR-MANUAL.md#5-backup-verification-drill)).

**If something goes wrong:** confirmation emails do not arrive → [07 §8.5](07-OPERATIONS-BACKUP-DR-MANUAL.md#85-waitlist-or-contact-failures). In development, the API response contains `dev_confirm_url` so you can confirm without email.

---

## 11. Support inbox

Screen: **ReserveChain → Support inbox** (`http://localhost:8088/wp-admin/admin.php?page=rc-support`). You need `rc_manage_waitlist`.

It shows the latest 200 messages from the website contact form and from app and portal support. Each has a ticket number `RC-SUP-000123`, the channel, the sender, the subject and the first 30 words.

**Steps**
1. Check the inbox daily.
2. Reply from the ReserveChain support mailbox, quoting the ticket number. The inbox is read-only: it has no reply or status function.
3. Escalate security and fraud reports (portal topic "Security / fraud report") to ops at once.
4. Track open tickets in ReserveChain's ticketing tool or a shared log.

When an app user deletes their account, their support messages are anonymised to `[erased on account deletion]`.

> [Screenshot: Support inbox — http://localhost:8088/wp-admin/admin.php?page=rc-support]

---

## 12. Compliance page and user eligibility

Screen: **ReserveChain → Compliance** (`http://localhost:8088/wp-admin/admin.php?page=rc-compliance`). You need `rc_manage_compliance`.

ReserveChain does not verify identity itself. This page records the **outcomes** reported by the external KYC/KYB provider, which is **[to be selected by ReserveChain]**. A provider webhook can set them through `do_action( 'rc_compliance_update', $user_id, $check, $state, $source )`.

The table lists users with their country, jurisdiction, entity type, KYC, KYB, AML, sanctions, MFA, wallets and **Overall** status.

**How "Overall" is calculated**
- `restricted` if the country is restricted.
- `eligible subject to final approval` if the jurisdiction is eligible **and** the required checks are approved. Individuals need KYC + AML + sanctions. Institutions need KYB + AML + sanctions.
- Otherwise `incomplete`.

Eligibility never confers a right to participate.

### 12.1 Record a check outcome for a user

**Before you start**
- You have the provider's result: report ID, date and outcome.
- You hold `rc_manage_compliance`.

**Steps**
1. On the Compliance page, click the user's name. This opens **Edit User** (`http://localhost:8088/wp-admin/user-edit.php?user_id=<ID>`).
2. Scroll to **ReserveChain eligibility**.
3. Set **Country of residence**. Users can set it once; after that only compliance can change it. Set **Entity type** too.
4. Set each check (KYC, KYB, AML screening, Sanctions / PEP screening) to *Not started*, *Pending*, *Approved*, *Rejected* or *Not applicable*.
5. Click **Update User**.
6. Keep the provider report in the provider's system. **Never upload KYC documents to WordPress.**

**Result:**
- Each change is logged (`compliance.kyc`, `compliance.country` and so on).
- The user gets an in-app notification "Compliance status updated".
- **Overall** recalculates at once.

> [Screenshot: User profile — ReserveChain eligibility section — http://localhost:8088/wp-admin/user-edit.php?user_id=<ID>]

**If something goes wrong:** the fields are greyed out → you lack `rc_manage_compliance`.

---

## 13. System health and the Operations widget

### 13.1 System health page

**ReserveChain → System health** (`http://localhost:8088/wp-admin/admin.php?page=rc-health`) needs `rc_view_audit`. Each row shows **OK** or **Action required**:

| Check | What OK means |
|---|---|
| Environment | `RC_ENV` is development, staging or production |
| HTTPS | Site served over HTTPS (not required in development) |
| PHP version / MySQL version | PHP ≥ 8.1, MySQL ≥ 8.0 |
| Audit immutability triggers | Both triggers present |
| Token secret configured | `RC_TOKEN_SECRET` is not the development default |
| File editor disabled | `DISALLOW_FILE_EDIT` is true |
| Debug display off | `WP_DEBUG_DISPLAY` is false |
| Staff MFA enforcement / Your account MFA | Both on |
| OpenSSL AES-256-GCM | Available (needed for MFA secrets) |
| Gated modules locked | Shows "All locked" or "Some authorized" |
| Database schema version | Matches `RC_DB_VERSION` |

### 13.2 Operations widget (Dashboard)

The WordPress **Dashboard** (`http://localhost:8088/wp-admin/index.php`) has two ReserveChain widgets:

- **ReserveChain — platform status:** website mode, items awaiting review, waitlist counts, audit chain head and last verification, DB triggers, and gated modules.
- **Operations:** OK, WARN or FAIL for each of database, audit_chain, triggers, failed_logins, submissions, backup, disk and cron, plus the environment. Click **Run full checks now** to verify the whole audit chain and send alerts for failing checks.

What each check means, and what to do when it fails, is in [07 §7](07-OPERATIONS-BACKUP-DR-MANUAL.md#7-monitoring-and-alerts).

> [Screenshot: Dashboard with Operations widget — http://localhost:8088/wp-admin/index.php]

---

## 14. Audit trail

Screen: **ReserveChain → Audit trail** (`http://localhost:8088/wp-admin/admin.php?page=rc-audit`). You need `rc_view_audit`.

The audit trail is append-only and hash-chained. Each entry stores the SHA-256 of the previous entry. Database triggers reject UPDATE and DELETE for every user, including administrators. There is intentionally no delete function anywhere.

> [Screenshot: Audit trail — Integrity and On-chain anchoring panels — http://localhost:8088/wp-admin/admin.php?page=rc-audit]

### 14.1 Read and filter

- The table shows 50 entries per page, newest first: sequence number, time (UTC), actor and role, action, object, summary, the `data` details, and the first 12 characters of the hash.
- **Filter by action prefix:** type `workflow`, `auth`, `registry`, `settings`, `waitlist`, `compliance`, `por`, `redemption`, `payment`, `intake`, `ops` or `audit`, then click **Filter**.
- IP addresses are never stored. Only an HMAC of the IP is kept.

### 14.2 Verify the chain

**Steps**
1. Click **Verify entire chain now**.
2. Read **Last verification**: *Intact — N entries* or *FAILED*, followed by error lines.

**Result:** `audit.verified` is appended. The check also runs automatically every day at 03:15 UTC (`ops.integrity_check`).

**If something goes wrong:** *FAILED* is a **SEV-2 incident**. Do not try to "fix" any data. Follow [07 §8.2](07-OPERATIONS-BACKUP-DR-MANUAL.md#82-audit-chain-failure). The error type tells you what happened:

| Error | Meaning |
|---|---|
| `content` | A row was modified |
| `link` | A row does not link to its predecessor |
| `gap` | A row was removed, or the auto-increment counter jumped (for example after a bad restore, [07 §6](07-OPERATIONS-BACKUP-DR-MANUAL.md#6-restoring-a-backup)) |

### 14.3 Export (JSONL)

1. Click **Export (JSONL, independently verifiable)**. The file is `reservechain-audit-YYYYMMDD-HHMMSS.jsonl`, one JSON row per line.
2. Store it in WORM storage, or with the incident record.
3. An auditor can recompute every row with the verification contract:

   `row_hash = SHA-256( prev_hash ␟ created_at ␟ actor_id ␟ actor_login ␟ actor_role ␟ ip_hash ␟ action ␟ object_type ␟ object_id ␟ summary ␟ data )`

   Here ␟ is the byte `0x1F`, and the first row's `prev_hash` is 64 zeros.

`audit.exported` is logged.

### 14.4 Anchor the chain head on-chain

Anchoring writes the current chain head (sequence number + hash) to the `AuditAnchor` contract on testnet. Any later rewrite of history becomes provable. Anchoring is disabled until the network and signer are approved.

**Before you start:** you need an administrator account (`rc_anchor_audit`), the AuditAnchor deployment and the anchor key. Both are provided separately ([05 §9](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#9-audit-anchoring)).

**Steps (automatic, preferred)**
1. In `contracts/`, run `WP_URL=<site> WP_USER=<admin login> WP_APP_PASSWORD=<application password> npm run anchor:sepolia`.
2. The script reads `/wp-json/rc/v1/audit/head`, anchors the head, and posts the transaction back. The anchor appears under **On-chain anchoring → Latest anchor**.

**Steps (manual record)**
1. Run the script without `WP_USER` and note the transaction hash.
2. In **On-chain anchoring**, check that the sequence number and chain head fields contain the values that were anchored, choose the network, and paste the **0x… transaction hash**.
3. Click **Record anchor**.

**Result:** "Anchor recorded." appears and `audit.anchored` is logged. If the head does not match the stored row at that sequence number, you see "Anchor rejected: chain head does not match the stored entry."
