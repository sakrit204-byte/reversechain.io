# ReserveChain.io: QA Test Report (local build)

> **Status:** Development QA pass on the local Docker stack, 2026-10-06. This is not a milestone acceptance record. Formal results belong in `docs/acceptance/<milestone>/test-results.md` (see [TEST-PLAN.md](TEST-PLAN.md)).

## 1. Scope

| Area | Covered |
|---|---|
| Pages | All 60 published pages (`wp post list --post_type=page --post_status=publish`), 3 passports (`RC-CU-LOT-000001`, `RC-NI-COL-000008`, `RC-LOT-000001`), and `?lang=es` / `?lang=it` versions of 10 key pages (home, copper program, verification, waitlist, contact, FAQ, portal, how-it-works, risk disclosure, passport). 83 URLs in total. |
| Accessibility | axe-core, WCAG 2.0/2.1 A + AA rule tags, on every URL at 1440 px and 390 px (166 scans). Axe was also run on 7 interactive states. A separate contrast check covered gradient backgrounds that axe cannot measure. Keyboard-only flows, focus visibility, headings, landmarks, `lang`, alt text, reduced motion. |
| Performance | Lighthouse 13.5 (mobile and desktop) on home, copper program, passport, verification and waitlist. Main-thread cost of the dust canvas engine on the home page (10 s idle, plus 10 s of scrolling). |
| Cross-browser | Chromium, Firefox and WebKit at 1440, 1024, 768 and 390 px on 8 key pages (96 combinations). Full-page screenshots, JS console errors, failed requests, horizontal overflow, overlapping text. |
| Functional | Waitlist (valid, invalid, EU/EEA restricted, honeypot, duplicate, rate limit), contact form, verify tool (known hash, random hash, malformed hash, local file hashing, deep link), passport JSON export, language persistence, 404, portal sign-in. |
| Out of scope | Smart contracts, mobile apps, CMS permission matrix, audit-log tamper tests, backups/DR, load testing (TEST-PLAN §5–6, §9–13), and manual screen-reader passes (NVDA, VoiceOver, TalkBack). Those are still required by TEST-PLAN §7. |

## 2. Environment and tools

| Item | Version / value |
|---|---|
| Site | http://localhost:8088. WordPress 7.1.2, PHP 8.3.35, Docker 29.1.3, theme `reservechain` 0.9.0, plugin `reservechain-core` 0.9.0 |
| OS / runtime | Windows 11 Pro 10.0.26200, Node 24.15.0, Python 3.11.9 |
| Browsers | Chrome 154.0.8037.93 (system), Firefox 155.0 and WebKit 26.6 (Playwright builds) |
| Tools | Playwright 1.63.0, axe-core 4.14.0, Lighthouse 13.5.0, chrome-launcher, Chrome DevTools Protocol `Performance.getMetrics` |
| Test scripts | `C:\Users\ACER\AppData\Local\Temp\claude\rc-qa\`: `axe.js`, `axe-states.js`, `pills.js`, `kbd.js`, `func.js`, `xb.js`, `lh.mjs`, `cpu.js`. Raw JSON results are in the same folder. Screenshots are in `…\rc-qa\shots\` (96 files named `<browser>-<width>-<page>.png`). |

Test data created in the dev database: two waitlist rows (`qa+<timestamp>@example.org`, CH) and support tickets `RC-SUP-000003`…`000006`. Rate-limit transients (`rc_rl_*`) were cleared between runs.

## 3. Results summary

| Area | Before | After fixes |
|---|---|---|
| axe WCAG A/AA, 166 scans | **319 serious nodes on 166/166 scans** | **0 violations** (0 critical, 0 serious, 0 moderate, 0 minor) |
| axe on interactive states (7) | not run | 7/7 clean |
| Gradient-background contrast (1,339 elements) | 9 failing patterns (status pills in the cream band) | 0 |
| Keyboard / focus / reduced motion (40 checks) | 12 failing | 40/40 pass |
| Lighthouse a11y (mobile + desktop) | 96–100 | 100 on all 5 pages (`td-has-header` still listed on verification, see K-1) |
| Lighthouse performance, mobile / desktop | 95–97 / 100 | 96–97 / 100 |
| Cross-browser matrix (96) | 0 JS errors; 1 real layout defect (CoA badges) found by screenshot review; 1 more found later (hero cards, see F-12) | 96/96 pass |
| Functional smoke (31) | 29/31 (F-3a aria association failed; one sign-in failure was a test artefact from rate-limiting) | 31/31 pass |

### 3.1 Accessibility: axe violations by rule (nodes)

| Rule (impact) | Desktop before | Mobile before | After |
|---|---|---|---|
| `label-content-name-mismatch` (serious): header logo link `aria-label` omitted its visible text | 83 | 83 | 0 |
| `color-contrast` (serious): gold on the cream band | 46 | 46 | 0 |
| `scrollable-region-focusable` (serious): tables and the chain diagram not keyboard-scrollable | 0 | 33 | 0 |
| `definition-list` (serious): `<small>` as a direct child of the PoR `<dl>` | 14 | 14 | 0 |
| **Total** | **143** | **176** | **0** |

By impact: before = 319 serious, 0 critical/moderate/minor. After = 0.

Other structural checks, before and after, all URLs and both viewports:
- Every page has exactly one `<h1>`, and no visible heading levels are skipped.
- One `<main>`, a banner, at least one footer, and labelled `<nav>`s.
- All `<img>` elements have `alt`.
- `<html lang>` is `en-US`, `es-ES` or `it-IT` per `?lang`. Before the fix the attribute was emitted twice (`lang="es-ES" lang="es"`), which is invalid HTML; this is fixed.

### 3.2 Keyboard, focus and motion (Chromium, 40 checks: all pass after fixes)

| Flow | Result after fixes | Defect found before |
|---|---|---|
| Skip link | First Tab stop, visible; Enter moves focus to `#main` | Lenis intercepted `#` links and scrolled without moving focus. The link stayed focused and off-screen at the page bottom. |
| Tab walk, home / waitlist / verification (1440 and 390, 120 stops each) | Every stop visible, with an outline or ring | Revealed-on-scroll blocks could hold focus at opacity 0. The file input was a second, hidden tab stop. |
| Desktop mega-menu | Opens on focus/Enter and sets `aria-expanded`; Tab enters the panel. Escape closes the panel, resets `aria-expanded` and returns focus to the trigger. | Escape left the panel open, with `aria-expanded="true"` stuck. |
| Mobile menu (390) | Opening moves focus into the nav, and the background is `inert`. Escape #1 collapses an open section; Escape #2 closes the menu and returns focus to the burger. | **Tapping or Entering a parent item ("Platform", "Assets"…) closed the whole off-canvas menu, so mobile users could not reach sub-pages through the accordion.** Focus also stayed behind the overlay. |
| Language switcher | Keyboard operable, `aria-current` on the active language, choice persists (`rc_lang` cookie), `hreflang` alternates present | none |
| FAQ `<details>` | Enter and Space toggle; visible focus | none |
| Waitlist errors | Errors render, focus moves to the first invalid field, and that field has `aria-invalid="true"` plus `aria-describedby` → error text. Result is in a `role=status` live region. The EU note is `role=alert`. | Errors were not programmatically tied to their fields. |
| Verify tool | One tab stop (the real file input), with the ring drawn on the drop zone. Hash form submits from the keyboard; result is in a live region. Copyable hashes are focusable buttons. | Duplicate tab stop on the drop zone; `[data-copy]` hashes were mouse-only. |
| Portal sign-in | Keyboard-only sign-in as `demo.app` works. Bad credentials are announced (`role=alert`). Focus moves to the dashboard heading. | none |
| `prefers-reduced-motion: reduce` | No running CSS animations; dust canvases not created or hidden; Lenis not started (`html.rc-static`) | none |

### 3.3 Performance (Lighthouse 13.5, after fixes; before values in brackets where they differ)

Mobile uses Lighthouse's default throttling (slow 4G, 4× CPU). Desktop uses the desktop preset.

| Page | Mobile perf | LCP (s) | CLS | TBT (ms) | Desktop perf | LCP (s) | JS gz / raw (KB) | CSS gz / raw (KB) |
|---|---|---|---|---|---|---|---|---|
| Home | 96 [95] | 2.47 [2.61] | 0 | 0 | 100 | 0.59 | 33.0 / 114.6 | 19.4 / 89.7 |
| Copper program | 96 [97] | 2.46 [2.44] | 0 | 0 | 100 | 0.54 | 27.7 / 101.1 | 19.4 / 89.7 |
| Passport RC-CU-LOT-000001 | 97 [96] | 2.34 [2.45] | 0 | 0 | 100 | 0.57 | 27.7 / 101.1 | 19.4 / 89.7 |
| Verification | 97 | 2.29 [2.30] | 0 | 0 | 100 | 0.48 | 27.7 / 101.1 | 19.4 / 89.7 |
| Waitlist | 96 [97] | 2.45 [2.44] | 0 | 0 | 100 | 0.54 | 27.7 / 101.1 | 19.4 / 89.7 |

Notes:
- Best-practices scored 100 everywhere.
- SEO scored 63–69 only because of `is-crawlable`. Non-production environments are deliberately `noindex` (`inc/seo.php`).
- Fonts are self-hosted (`assets/fonts/*.woff2`, `font-display: swap`). Inter Tight and Fraunces (normal) are preloaded. Fonts transfer about 155 KB.
- There are 0 third-party requests and no external scripts, styles or fonts.
- `unsized-images` passes on all five pages.
- Before the fix, `qrcode.js` and `public.js` were parser-blocking. They are now `defer`. The only remaining render-blocking resource is `main.css` (19 KB gz).
- Mobile LCP is inside the 2.5 s budget but close to it (see K-5).

**Dust canvas engine (home, Chrome DevTools Protocol metrics, 165 Hz display):**

Main-thread % = task time ÷ wall time. "rAF/s" is how often the browser delivered animation frames; above 60 means no dropped frames on a 60 Hz panel. In the "before" runs the scroll column double-counted frames, so those values are halved here.

| Scenario | Dust on: before | Dust on: after (60 fps cap) | Dust blocked (baseline) |
|---|---|---|---|
| 1440 px, idle 10 s | 66.8 % main thread, ~165 rAF/s | **19.4 %**, 165 rAF/s (dust draws ~55 fps) | 3.3–3.9 % |
| 1440 px, scrolling 10 s | 47.2 %, ~150 rAF/s | 41.6 %, 160 rAF/s | 32.1–32.7 % |
| 390 px (touch), idle | 25.7 % | **11.3 %** | 3.5–3.8 % |
| 390 px (touch), scrolling | 43.5 % | 28.9 % | 24.8–27.1 % |
| 1440 px idle, **4× CPU throttle** | 99.4 %, **56 rAF/s (dropping frames)** | 93.3 %, 90 rAF/s | 17–20 % |
| 390 px idle, 4× CPU throttle | 98.6 %, 119 rAF/s | 52.5 %, 162 rAF/s | 16–19 % |

There were no long tasks at normal CPU, and the heap stays under 3 MB. The engine already stops the hero layer when it is off-screen and is disabled under reduced motion.

### 3.4 Cross-browser matrix (after fixes): 96/96 pass

Each combination was checked for no JS errors, no failed requests, no horizontal overflow, no element outside the viewport, and no overlapping text.

| Page | Chromium 1440 / 1024 / 768 / 390 | Firefox 1440 / 1024 / 768 / 390 | WebKit 1440 / 1024 / 768 / 390 |
|---|---|---|---|
| `/` | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ |
| `/assets/industrial-metals/copper-powder/` | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓¹ | ✓ ✓ ✓ ✓¹ |
| `/passport/RC-CU-LOT-000001/` | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ |
| `/platform/verification/` | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ |
| `/participation/waitlist/` | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ |
| `/portal/` | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ |
| `/resources/faq/` | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ |
| `/platform/how-it-works/` | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ | ✓ ✓ ✓ ✓ |

¹ At 390 px the page is about 35,000 px tall, which is more than Firefox and WebKit can capture (32,767 px limit), so the screenshot is clipped at 32,000 px. The page itself is fine.

Two layout defects were found before the fixes, in all three engines:
- **CoA badge row** (copper program, 768/390): the long "Owner-supplied: not independently verified…" tag wrapped as a broken inline box over the status pill.
- **Home hero cards** (≤ 980 px): the parallax lifted the Cu/Ni cards by up to ~100 px. They covered the hero micro-disclosure, "Registration does not constitute an investment, token purchase or reservation…". That is compliance text (CI-1 spirit).

The automated overlap check also flagged hidden content inside closed `<details>`; the checker was corrected to ignore it.

### 3.5 Functional smoke (after fixes): 31/31 pass

| ID | Case | Result |
|---|---|---|
| F-1 | Waitlist, valid non-EU (CH) | 200 `pending_confirmation`, `jurisdiction: eligible`, dev confirm link shown, 1 row stored |
| F-2 | EU/EEA (IT, DE) | Restricted note and "general updates only" opt-in appear on country change. Submit returns `ineligible_jurisdiction`, `reason: eu_eea`, `stored: false`, warning alert shown. |
| F-3 | Invalid email + missing consents | 422 with 3 field errors. Focus goes to the email field, which has `aria-invalid` and `aria-describedby="rc-err-waitlist-email"`. |
| F-4 | Honeypot filled | Fake success (200), **no row stored** (row count unchanged apart from F-1) |
| F-5 | Rate limit | 6th submission returns 429 |
| F-6 | Duplicate email | Same neutral message, no duplicate row |
| F-9 | Contact empty / valid | 422 with message / 200 with ticket `RC-SUP-00000x` |
| F-11 | XSS/SQLi payload in contact | Accepted as plain data (200), no 5xx. Admin-side escaping not inspected; see K-8. |
| V-1…5 | Verify | Known hash (`398f2de5…`, CoA 0004512) gives a match with document details. Random hash gives "No match". Malformed hash gives client validation with focus kept. Local file SHA-256 matches Node's `crypto`. `?hash=` deep link checks automatically. No JS errors. |
| P-1 | Passport JSON | `/passport/<no>/?format=json` returns 200 `application/json` for all 3 passports. REST `/rc/v1/passports/<no>` returns 200. The page links the export. QR renders. |
| L-1 | Language | `?lang=it` persists to the next page (cookie `rc_lang`), switching back to EN persists, `hreflang` en/es/it/x-default present |
| 404 | Unknown URL | HTTP 404, branded template, single h1, navigation links |
| PT-1 | Portal | `demo.app` / `ReserveChain-Demo-2026` signs in. Gated modules are shown locked or inactive; no holdings. |
| CI-5 | `/config` | All gated modules `false` |

## 4. Fixed issues

All changes are presentation, markup or accessibility changes; no business logic changed.

| # | Severity | Issue | Fix | File(s) |
|---|---|---|---|---|
| F-1 | S3 | Header logo `aria-label="ReserveChain — Home"` did not contain the visible "ReserveChain.io" text (fails WCAG 2.5.3) | Removed the `aria-label`; the visible logo text plus a `screen-reader-text` "— Home" is now the name | `themes/reservechain/header.php` |
| F-2 | S3 | `<html>` emitted `lang` twice | Kept `language_attributes()`, which already reflects `?lang` | `header.php` |
| F-3 | S2 | Gold text on the cream band failed contrast: kicker 2.8:1, `h2 em` / card numbers / links 1.7:1, `.rc-fine` 2.4:1 | Cream-band overrides: gold `#86601C` (4.8:1), fine print `#4A525C` (6.7:1). Dark components inside the band keep their own colours. | `assets/css/main.css` (QA block) |
| F-4 | S2 | Status pills in the cream band: 2.5:1 inside dark tables and cards, 4.3:1 on cream. Axe reports these only as "incomplete" because of the gradient background. | Light text inside dark components; darker text on cream | `main.css` |
| F-5 | S3 | PoR `<dl>` had `<small>` as a direct child | The note is now `<dd class="rc-por__note"><small>…</small></dd>`, styled identically. Counter animation skips it. | `plugins/reservechain-core/includes/class-shortcodes.php`, `main.css`, `assets/js/motion.js` |
| F-6 | S2 | Horizontally scrolling tables and the chain diagram could not be reached by keyboard (WCAG 2.1.1) | When content overflows, add `tabindex=0`, plus `role=region` and an `aria-label` from the caption or section heading. Re-evaluated on resize. | `assets/js/main.js` |
| F-7 | **S2** | **Mobile menu: activating a parent item closed the whole off-canvas menu, so sub-pages were unreachable through the accordion** | The nav click handler ignores clicks the accordion already handled (`defaultPrevented`) | `main.js` |
| F-8 | S2 | Mobile menu focus management | Focus moves into the nav on open, background is `inert` while open, Escape closes and returns focus to the burger, menu closes on resize to desktop | `main.js` |
| F-9 | S3 | Mega-menu: Escape did not close the panel (`:focus-within` kept it open) or reset `aria-expanded`; outside click did not reset `aria-expanded` | Escape closes the panel and returns focus to the trigger (an `.rc-esc` class suppresses `:focus-within` until focus leaves). `aria-expanded` is reset on Escape and outside click. | `main.js`, `main.css` |
| F-10 | S2 | Skip link and in-page anchors: Lenis smooth-scroll prevented focus from moving | After `lenis.scrollTo`, focus moves to the target (adding `tabindex=-1` when needed). Skip link uses `position: fixed` when focused. | `assets/js/motion.js`, `main.css` |
| F-11 | S3 | Focus could land inside scroll-reveal blocks still at opacity 0 | `.rc-rv:focus-within` is shown immediately | `main.css` |
| F-12 | **S2** | **Hero parallax at ≤ 980 px pushed the Cu/Ni cards over the hero micro-disclosure text** | Hero card and ledger parallax only on the two-column layout (≥ 981 px) | `motion.js` |
| F-13 | S3 | Waitlist/contact errors were not programmatically associated with their fields | `aria-invalid="true"` plus `aria-describedby` pointing at the error element; cleared on resubmit | `plugins/reservechain-core/assets/public.js` |
| F-14 | S3 | Verify drop zone: the focusable `<label tabindex=0>` and the hidden file input gave two tab stops, one of them invisible | `tabindex` removed from the label; the native file input is the single stop, and the focus ring is shown on the zone (`:focus-within`) | `class-shortcodes.php`, `main.css` |
| F-15 | S3 | `[data-copy]` hashes were mouse-only | Given `tabindex=0`, `role=button`, `aria-label="Copy: <hash>"`, and Enter/Space support | `public.js`, `class-shortcodes.php` (new i18n string) |
| F-16 | S3 | CoA badge row wrapped as a broken inline box over the status pill (768/390, all engines) | `.rc-coa__badges` is now a wrapping flex row | `main.css` |
| F-17 | S3 | Dust engine ran uncapped at the display refresh rate. On 120–165 Hz displays this cost 2–3× the CPU, and per-frame physics made the motion faster than designed. | Draws capped at about 60 fps. Desktop idle main thread dropped from 66.8 % to 19.4 %. | `assets/js/dust.js` |
| F-18 | S4 | `qrcode.js` and `public.js` were loaded parser-blocking | Registered with `strategy => defer` (still in the footer, same order) | `class-shortcodes.php` |

New translatable string for ES/IT: `Copy` (text domain `reservechain`, used in the copy button's accessible name). The JS fallback label "Scrollable content" in `main.js` is used only when a scroll region has no caption or heading; none currently lacks one.

## 5. Known issues (not fixed)

Each was out of scope for this pass, or needs an owner decision.

| # | Severity | Issue | Recommended fix |
|---|---|---|---|
| K-1 | S3 | Seeded comparison tables (e.g. `/platform/verification/`, and the program and asset pages) use `<td>` for row labels and an empty `<th>` corner. Lighthouse reports `td-has-header`. | In `seed/pages/*.html` (EN/ES/IT), make the first cell of each row `<th scope="row">`, give header cells `scope="col"`, and add a `<caption>` or `aria-label`. This is content, so it belongs to the content owner. |
| K-2 | S3 | Waitlist anti-bot minimum fill time (3 s). A fast keyboard or autofill user who submits within 3 s gets "Please take a moment to review the form." (429) with no field guidance. Empty submits do not show validation until 3 s have passed. | Validate required fields before the timing check, or validate on the client first. This is a business-logic change, so it was not made. |
| K-3 | S4 | All status pills render in the neutral colour: the later `.rc-pill { --c: var(--s-na) }` block (main.css ~L826) overrides the `.rc-pill--*` modifiers. Status is still shown by text and the level bar, so this is not an a11y failure. | If colour-coded pills are intended, re-declare the modifiers after that block. Needs a design decision. |
| K-4 | S3 | Dust engine on low-end CPUs: at 4× throttle it still takes about 93 % of the main thread at idle on desktop (rAF 90/s, so no visible jank), and about 52 % at 390. | Scale particle count by `navigator.hardwareConcurrency` / `deviceMemory`; draw the ambient layer at 30 fps on touch devices; pause when the pointer is idle; honour `Save-Data`. |
| K-5 | S3 | Mobile LCP 2.29–2.47 s under slow 4G is within budget but has little margin. FCP is about 1.8 s, blocked only by `main.css` (19 KB gz, 90 KB raw). | Inline critical CSS for the header and hero; consider preloading only the Fraunces axis actually used by the hero; enable long-lived `Cache-Control` for `/wp-content/**` (static assets currently have no cache headers). |
| K-6 | S4 | With Lenis active, browser scrolling to a newly focused element on long pages animates for up to about 1.5 s. Focus is correct; the indicator just arrives late. | Optionally call `lenis.scrollTo(el, {immediate:true})` on `focusin` from the keyboard. |
| K-7 | S4 | Copper program page is about 35,000 px tall at 390 px. | Consider collapsing the long spec and assay sections on mobile (`<details>`). |
| K-8 | S3 | The contact form stores raw XSS and SQL payloads, as expected. Output escaping in the wp-admin ticket views was not inspected. | Include admin views in the F-11 security test at staging. |
| K-9 | S4 | The CSP allows `fonts.googleapis.com` and `fonts.gstatic.com`, which are not used. `script-src` includes `'unsafe-inline'` because of WordPress inline localisation. | Remove the unused hosts; move to nonce- or hash-based inline scripts when feasible. |
| K-10 | S4 | Playwright WebKit on Windows renders Fraunces headings noticeably heavier than Chromium and Firefox. This is likely a variable-font limitation of the WinCairo port. | Confirm on real Safari (macOS and iOS) during the TEST-PLAN §3 device pass. |
| K-11 | Info | SEO `is-crawlable` fails because non-production is `noindex` by design | Verify that production removes the `noindex`. |
| K-12 | Info | Manual screen-reader passes (NVDA + Firefox, VoiceOver + Safari/iOS, TalkBack) and WCAG 2.2-specific criteria (2.4.11 focus not obscured, 2.5.8 target size) were not run. | Run them at M3 per TEST-PLAN §7. |

## 6. Remaining for staging

- **Email delivery:** real SMTP or transactional provider with SPF, DKIM and DMARC. Test double opt-in (F-7: valid, expired and reused links), unsubscribe, and contact notifications end to end. Locally the confirm link is only shown in the dev response.
- **HTTPS / HSTS:** TLS certificate, HTTP→HTTPS redirect, `Strict-Transport-Security` (start with a short max-age, then preload), and secure cookie flags (`rc_lang`, auth). Re-test the verify tool's local hashing, since `crypto.subtle` requires a secure context.
- **Caching / compression:** long-lived `Cache-Control` and immutable hashing for theme and plugin assets, Brotli, HTTP/2 or HTTP/3. Re-run Lighthouse on the staging origin.
- **Backups:** scheduled encrypted DB and uploads backups on the server, an off-site immutable copy, and a restore drill (TEST-PLAN §12, B-1…B-6). None of this exists in the local Docker stack.
- **Rate limits behind a proxy:** confirm `Security::client_key()` uses the real client IP behind the load balancer or CDN, so the waitlist, contact and login limits are not shared by all users.
- **Indexing:** production must drop `noindex` (K-11) and serve the sitemap on the final domain.
- **Remaining TEST-PLAN items:** screen readers, real devices (iOS Safari, Android Chrome), k6 load test, CMS permission matrix, audit-log tamper and anchor tests.
