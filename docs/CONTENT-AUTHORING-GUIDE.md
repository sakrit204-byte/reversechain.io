# Content Authoring Guide — ReserveChain website pages

Pages live in `wordpress/plugins/reservechain-core/seed/pages/` and are synchronised into WordPress with
`wp rc seed --pages-only --force` (overwrites those pages and menus from the seed files; without `--force` only missing pages are created, so CMS edits are never lost on deploy). Pages then remain fully editable in the CMS, under the four-eyes workflow.

## 1. File format

* File name = URL path with `/` written as `__`. Example: `assets__industrial-metals__copper-powder.html` → `/assets/industrial-metals/copper-powder/`. Home is `home.html`.
* Parent pages must exist (e.g. `assets.html` and `assets__industrial-metals.html` before the copper page).
* Translations: same name + `.es.html` / `.it.html` (identical HTML structure, translated text, shortcodes unchanged).
* First lines = header comment:

```html
<!--
title: Ultrafine Copper Powder — Asset Program
excerpt: One or two sentence lead shown under the H1 in the page hero (also the meta description).
kicker: Initial asset program · Cu 29
order: 1
-->
```

* Body = a sequence of `<section class="rc-section">` blocks. The page template already renders the dark hero
  (breadcrumbs, kicker, H1 = title, lead = excerpt). Do **not** repeat the H1. Start with `<section>`s.
* Plain, valid HTML only. No inline `style` except CSS custom properties, no `<script>`, no external images.
  No emojis. Use `data-reveal` on cards/blocks you want to fade in.

## 2. Layout components (all classes exist in `assets/css/main.css`)

```html
<section class="rc-section">                       <!-- variants: rc-section--tint, rc-section--white, rc-section--dark, rc-section--cream, rc-section--tight -->
  <div class="rc-wrap">
    <div class="rc-section__head">                 <!-- add rc-section__head--center or --split -->
      <p class="rc-kicker">Eyebrow</p>
      <h2>First line <em>gold second line</em></h2>  <!-- <em> renders gold, not italic -->
      <p class="rc-lead">Lead paragraph.</p>
    </div>
    <div class="rc-grid rc-grid--3">               <!-- --2 / --3 / --4 -->
      <article class="rc-card" data-reveal>
        <span class="rc-card__num">01</span>       <!-- or <div class="rc-card__icon">SVG</div> -->
        <h3>Title</h3><p>Text.</p>
        <div class="rc-card__foot">[rc_status s="proposed"] <a href="/platform/custody/">Explore Custody →</a></div>
      </article>
      <a class="rc-card rc-card--link" href="/platform/verification/"> ... </a>
    </div>
  </div>
</section>
```

Other blocks:

* `rc-split` (two columns; `rc-split--wide`) · `rc-prose` (long-form text, legal pages) · `rc-list` (dash bullets) · `rc-checks` (tick bullets)
* `<ol class="rc-steps"><li><div><h3>Step</h3><p>Text</p></div>[rc_status s="proposed"]</li></ol>` (numbered process with status at right)
* `<div class="rc-claims"><div><strong>Claim</strong>[rc_status s="pending_verification"]<small>Note</small></div>…</div>` (claim-status board)
* `<div class="rc-roadmap"><div class="is-active"><span class="rc-phase">Phase 1</span><h3>…</h3><ul><li>…</li></ul>[rc_status s="in_development"]</div>…</div>`
* `<div class="rc-faq"><h3>Category</h3><details><summary>Question?</summary><div><p>Answer</p></div></details>…</div>`
* `<div class="rc-table-wrap"><table class="rc-table">…</table></div>` · `<dl class="rc-kv"><div><dt>Key</dt><dd>Value</dd></div></dl>`
* `<div class="rc-callout"><p>…</p></div>` · `<div class="rc-cta"><div><h2>…</h2><p>…</p></div><div class="rc-btn-row">…buttons…</div></div>`
* `<figure class="rc-diagram"><svg …>…</svg><figcaption>…</figcaption></figure>` — inline SVG diagrams welcome (use `currentColor`, `#D5B167` gold, `#C9773F` copper, `#A9BBC8` nickel, `#1C2A38` lines, text fill `#E7EBEE`, font-family "Inter Tight").
* Buttons: `<a class="rc-btn rc-btn--primary" href>` (gold), `rc-btn--cream`, `rc-btn` (outline), size `rc-btn--sm`. Group in `<div class="rc-btn-row">`.
* Program identity tiles: `<a class="rc-element" href="…"><span class="rc-element__n">29</span><span class="rc-element__s">Cu</span><span class="rc-element__l">Copper Powder <small>99.9999 %*</small></span></a>` (add `rc-element--ni` for nickel) inside `<div class="rc-elements">`.
* `<span class="rc-demo-badge">Illustrative / demo data</span>` — mandatory on any illustrative example.
* `<hr class="rc-goldrule">`

## 3. Live modules (shortcodes — never hard-code these values)

| Shortcode | Renders |
|---|---|
| `[rc_disclosure]` | Mandatory no-offer disclosure + EU/EEA notice (homepage, asset pages, waitlist, documentation **must** include it) |
| `[rc_provisional]` | Provisional Asset Notice (asset/program/registry pages **must** include it) |
| `[rc_status s="proposed\|in_development\|pending_verification\|verified\|not_applicable" label="optional"]` | Status pill |
| `[rc_waitlist]` · `[rc_contact]` | Functional forms |
| `[rc_verify]` | Client-side SHA-256 document verification tool |
| `[rc_passports program="copper-powder\|nickel-wire" limit="12"]` | Digital Asset Passport cards (live) |
| `[rc_registry_table program="…" type="rc_lot\|rc_coil\|rc_container"]` | Registry table with unit-level verification/custody/reserve/tokenization/redemption status |
| `[rc_coa program="copper-powder"]` | Owner-supplied Certificate of Analysis panel (exact transcription, assay grid, scan, fingerprint) |
| `[rc_por program="…"]` | Proof-of-Reserves dashboard computed live (declared vs verified, no invented coverage) |
| `[rc_program_facts program="…"]` · `[rc_token_params program="…"]` | Program fields / token-program parameters (all pending) |
| `[rc_documents type="whitepaper\|coa\|legal\|policy\|specimen"]` | Document library with fingerprints |
| `[rc_registry_stats]` · `[rc_audit_status]` | Live counts / tamper-evident audit chain status |
| `[rc_module module="wallet\|purchase\|redemption\|holdings\|investor_portal\|…" title="…"]` | "Built but inactive" locked-module panel |

Program slugs: `copper-powder`, `nickel-wire`. Passport URLs: `/passport/RC-CU-LOT-000001/` (Cu Lot #03-K-07),
`/passport/RC-CU-CTN-000001/` (Box no. 20), `/passport/RC-NI-LOT-000001/` (Ni Lot 120/NP1), `/passport/RC-NI-COL-000008/` (bobbin 8), `/passport/RC-LOT-000001/` (Illustrative Industrial Metal Asset Template).

## 4. Non-negotiable content rules (from the brief + master instructions)

1. **Never invent** quantities, prices, valuations, partners, custodians, insurers, auditors, vault locations, people, dates, metrics, contract addresses, token symbols or returns. If unknown: "Pending", "To be confirmed", "Subject to final approval".
2. **Owner-supplied facts you may use** (always attributed as owner-supplied / subject to documentary verification):
   - Ultra-High-Purity Copper Powder — 99.9999 % (IGAS CoA 0004512, 04.07.2022, Lot #03-K-07, 2000 kg declared by customer, glass ampoules in cardboard boxes, ICP/OES, natural isotopic composition, "not radioactive").
   - High-Purity Nickel Wire 0.025 mm — 99.9807 % (IGAS CoA 0004368, 19.10.2021, Lot 120/NP1, "Nickel wire 0,025 mm dia, DKRNT NP1", 5000 g declared, 30 bobbins in 1 box, ICP/MS + ICP/OES, GOST 2179-75 impurities 0,0193 %).
   - "Swiss corporate and issuance structure in development." (Never assert an incorporated entity, address, register number.)
3. Language: proposed · planned · in development · intended · subject to final legal review / documentary verification / final approval.
   Each of Verification, Custody, Proof of Reserves, Tokenization and Redemption pages must state it describes a **proposed framework** subject to final legal, contractual, technical and operational confirmation.
4. Prohibited: "invest now", "buy", "presale", "join the presale", "guaranteed", "risk-free", "returns", "yield", "APY", "profit", "100% backed", "insured", "audited", "secured", "liquidity", "trade", countdowns, wallet-connect, prices, "MiCA-compliant", ISO/SOC certifications, partner logos/names (SGS, Intertek, Lloyd's, etc.), fake people/boards/testimonials, "RSC"/"$RSC".
   Use "token-acquisition module" (never "presale"). The "20% discount" may only be explained as a proposed methodology subject to approval — never as a promotional claim; no worked example with a token price.
5. Never label anything Verified, Certified, Secured, In Custody, Tokenized, Available or Redeemable. Illustrative content carries `<span class="rc-demo-badge">Illustrative / demo data</span>`.
6. EU/EEA: ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA. Do not build content around MiCA.
7. Tone: institutional, precise, calm, evidence-first. Short sentences. British/International English. No hype adjectives ("revolutionary", "game-changing").
8. Every page: rich and specific (no filler, no repeated text across pages), 5–9 sections, ends with a relevant CTA band using labels from the CTA library: Explore Assets · How ReserveChain Works · View Initial Programs · View Copper Program · View Nickel Program · View Product Gallery · View Asset Registry · View Digital Asset Passport · View Reserve Report · View Verification Process · Explore Custody · Learn About Tokenization · How Redemption Works · Join Waitlist · Check Eligibility · View Program Overview · View Documents · View Whitepaper · View Investor Presentation · Enterprise Services · Technology Licensing · Submit an Asset · Contact ReserveChain · Access Portal.
9. Internal links use root-relative paths of the sitemap (see `docs/SITEMAP.md`).
