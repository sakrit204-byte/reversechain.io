# 02 — Asset Registry and Digital Asset Passport Manual

**Readers:** registry managers, reviewers, compliance officers, auditors. **Source of truth:** `includes/class-schema.php` (all fields), `class-registry.php` (numbering, validation), `class-passport.php` (passport assembly), `class-shortcodes.php` (assay grid), `class-seed.php` (worked examples).
**Related:** [01 §4 Workflow](01-CMS-ADMIN-MANUAL.md#4-the-four-eyes-workflow) · [01 §5 Documents](01-CMS-ADMIN-MANUAL.md#5-media-and-documents) · [03 Proof of Reserves](03-PROOF-OF-RESERVES-MANUAL.md) · [Index](README.md)

> **The first rule: nothing is invented.** Leave a field empty when the information has not been provided. Empty fields are shown publicly as an explicit pending state, for example *"Pending: weight certificate required"*. Never estimate, round or "fill in" a value. Every value must have a source you can name in **Data source reference**.

---

## Contents

1. [Data model](#1-data-model)
2. [Record numbers](#2-record-numbers)
3. [Create a metal program](#3-create-a-metal-program)
4. [Adding physical asset records](#4-adding-physical-asset-records)
5. [Copper (Cu) versus nickel (Ni) fields](#5-copper-cu-versus-nickel-ni-fields)
6. [Entering a Certificate of Analysis (CoA)](#6-entering-a-certificate-of-analysis-coa)
7. [Other evidence: custody, valuation, insurance, reserve reports — and linking documents](#7-other-evidence-and-linking-documents)
8. [Verification statuses](#8-verification-statuses)
9. [Digital Asset Passports](#9-digital-asset-passports)
10. [QC checklist before submitting or approving](#10-qc-checklist-before-submitting-or-approving)
11. [Naming conventions](#11-naming-conventions)

---

## 1. Data model

All registry records live under **Asset Registry** in wp-admin (`http://localhost:8088/wp-admin/admin.php?page=reservechain-registry`). One declarative schema (`class-schema.php`) drives the admin forms, validation, the API, the passports and the completeness score. To add a field, change the schema, or use the `rc_registry_schema` filter, in a reviewed pull request.

| Group | Entity (post type) | Prefix | Purpose | Passport? |
|---|---|---|---|---|
| Programs | Metal Program (`rc_program`) | PRG | Copper Powder (Cu 29), Nickel Wire (Ni 28) | — |
| Physical assets | Lot (`rc_lot`) | LOT | Producer lot; holds the Cu or Ni technical fields | ✔ |
| | Batch (`rc_batch`) | BAT | Sub-division of a lot | ✔ |
| | Container (`rc_container`) | CTN | Box or drum; belongs to a batch, or directly to a lot | ✔ |
| | Coil / Spool (`rc_coil`) | COL | Wire bobbin, coil or spool; belongs to a lot | ✔ |
| Evidence | Laboratory (`rc_laboratory`) | LAB | Issuing laboratory | — |
| | Certificate of Analysis (`rc_coa`) | COA | Purity and assay results | — |
| | Custody & Ownership (`rc_custody`) | CUS | Custody intake, transfer, legal ownership, release | — |
| | Valuation (`rc_valuation`) | VAL | Independent valuation | — |
| | Insurance (`rc_insurance`) | INS | Insurance cover | — |
| | Document (`rc_document`) | DOC | File + SHA-256 fingerprint | — |
| Proof of Reserves | Reserve Report (`rc_reserve_report`) | RSV | Attestation of reserve units | — |
| Tokenization | Token Program (`rc_token_program`) | TKN | Token parameters, all unset | — |
| | Redemption (`rc_redemption`) | RDM | Internal; managed on the Redemptions screen ([04](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md)) | — |

```
Metal Program ─┬─ Lot ─┬─ Batch ── Container
               │       ├─ Container (no batch)
               │       └─ Coil
               ├─ Token Program
               └─ Reserve Report
Evidence (CoA, Custody, Valuation, Insurance, Reserve Report) ──"Applies to"──▶ Lot / Batch / Container / Coil
Evidence ──"Document"──▶ Document (file + SHA-256)
Laboratory ◀── CoA
```

**Field visibility.** A field marked **internal** in the form is never shown on the website, in the API or in passports. Examples: supplier/owner, acquisition date, encumbrance, policy number, acquisition cost.

**Lifecycle status fields** (lot, batch, container, coil). These are separate from the verification status:

| Field | Values | Default |
|---|---|---|
| Availability | Not offered for sale · Held: not available · Pending approval | Not offered for sale |
| Custody status | Pending · Under review · Arranged (evidence attached) · Not applicable | Pending |
| Reserve status | Pending · Eligible: pending approval · Accepted into reserve (attested) · Excluded | Pending |
| Tokenization status | Not issued · Proposed · Approved: not issued · Issued (authorized) · Retired | Not issued |
| Redemption status | Not available · Eligible (authorized) · Requested · Released · Delivered | Not available — the Redemptions workflow changes this, not people |
| Encumbrance / lien (internal) | None declared (unverified) · None (verified) · Encumbered / restricted | empty |

The Proof of Reserves reconciliation reads these statuses ([03](03-PROOF-OF-RESERVES-MANUAL.md)). Setting them carelessly creates exceptions.

---

## 2. Record numbers

The system assigns every record a human-readable, **immutable** number the first time it is saved (not at auto-draft):

| Record | Format | Example |
|---|---|---|
| With a program (Cu) | `RC-CU-<PREFIX>-NNNNNN` | `RC-CU-LOT-000001`, `RC-CU-CTN-000001` |
| With a program (Ni) | `RC-NI-<PREFIX>-NNNNNN` | `RC-NI-LOT-000001`, `RC-NI-COL-000008` |
| A program itself | `RC-<SYMBOL>-PRG-NNNNNN` | `RC-CU-PRG-000001` |
| No program (evidence, documents, template) | `RC-<PREFIX>-NNNNNN` | `RC-COA-000001`, `RC-DOC-000004`, `RC-LOT-000001` |

Rules:
- Each type and scope has its own counter (stored in option `rc_seq_<type>_<scope>`). Numbers are never reused, even after archiving.
- **Choose the Metal program before the first Save.** The scope (CU, NI or RC) is fixed on first save. A lot saved without a program keeps `RC-LOT-…` forever, even after you add the program.
- For passport types, the record number is the **passport number**. It is printed on QR codes and labels, so it must never change. Never re-create a record just to get a "nicer" number.

---

## 3. Create a metal program

The seed already creates **Copper Powder** (symbol `Cu`, atomic number 29) and **Nickel Wire** (`Ni`, 28). Create a new program only after a ReserveChain decision.

**Steps**
1. **Asset Registry → Metal Programs → Add Metal Program** (`http://localhost:8088/wp-admin/post-new.php?post_type=rc_program`).
2. Enter the title (for example `Copper Powder`) and the **Element symbol** (required, for example `Cu`). The symbol decides record-number scopes and which program-specific fields appear on lots.
3. Fill in only fields with a source: material form, target purity grade, specification standard, unit of account, origin disclosure, industrial context.
4. Save the draft, submit, and have it approved and published.

---

## 4. Adding physical asset records

### 4.1 Add a lot

**Before you start**
- You are a registry manager (or reviewer, compliance officer, administrator).
- You have the source documents: producer lot sheet, weight certificate, CoA. Register the documents first, or in parallel ([01 §5.2](01-CMS-ADMIN-MANUAL.md#52-add-a-document)).
- The metal program is published.

**Steps**
1. Open **Asset Registry → Lots → Add Lot** (`http://localhost:8088/wp-admin/post-new.php?post_type=rc_lot`).
2. **Title:** see §11, for example `Ultrafine Copper Powder: Lot #03-K-07`.
3. **Content (editor):** a factual one-paragraph description. It becomes the passport description.
4. In **Lot — registry fields**:
   1. **Metal program** (required): choose it **first** (§2).
   2. **Producer lot reference:** exactly as printed, for example `03-K-07`.
   3. **Producer / refiner**, **Production date**, **Gross weight** and **Net weight** (kg), **Declared purity**, **Country of origin**: only when documented.
   4. **Origin and handling:** product name, physical form, supplier/owner (internal), country of manufacture, number of units, packaging, seal numbers, current location (disclosure level only, never the exact vault address), storage and handling requirements.
   5. **Data source reference:** name every source, for example `Owner-supplied CoA 0004512; owner declaration 2026-09-30`.
   6. **Program fields** (Cu or Ni, §5).
   7. **Lifecycle statuses:** leave the defaults unless evidence supports a change.
   8. **Verification status:** see §8. A new lot is usually *In development* or *Pending verification*.
5. Click **Save Draft**. The **Registry record** box shows the record number. For passport types it also shows **View Digital Asset Passport**, the **Evidence completeness** and the **Evidence Merkle root**.
6. Run the QC checklist (§10). Then click **Submit for review**.

**Result:**
- The lot appears in the Review queue. `registry.created` and `registry.updated` (with a field-level diff) are logged.
- After approval and publication, the passport goes live at `/passport/<record no>/`.

> [Screenshot: Lot editor — registry fields and Registry record box — http://localhost:8088/wp-admin/post-new.php?post_type=rc_lot]

**If something goes wrong**
- *Notice "“Metal program” is required.":* select the program and save again.
- *A number field rejects your value:* number fields accept only non-negative numbers, with a dot as the decimal separator. If the document prints a comma decimal (`2000,5`), enter `2000.5` and note the original in the data source reference.
- *The Cu or Ni fields are missing:* the program has no symbol, or it is the other metal (§5).

### 4.2 Add a batch

1. **Asset Registry → Batches → Add Batch** (`http://localhost:8088/wp-admin/post-new.php?post_type=rc_batch`).
2. Set the **Metal program** first, then the **Parent lot**. Fill in Batch reference, Net weight, Particle size distribution (powder only), Packaging, and the lifecycle statuses.
3. Save → QC (§10) → submit.

A batch **inherits** its lot's evidence in the passport (§9.2).

### 4.3 Add a container (box, drum)

1. **Asset Registry → Containers → Add Container** (`http://localhost:8088/wp-admin/post-new.php?post_type=rc_container`).
2. **Metal program** first. Then either **Batch**, or **Parent lot (if no batch)**. Never set both to unrelated parents.
3. Fill in **Container / drum ID** exactly as marked on the object, for example `Box no. 20`. Also: **Tamper seal number** (empty until sealed at custody intake), **Net weight**, **Location (disclosure level)**.
4. Save → QC → submit.

### 4.4 Add a coil, bobbin or spool (wire)

1. **Asset Registry → Coils / Spools → Add Coil** (`http://localhost:8088/wp-admin/post-new.php?post_type=rc_coil`).
2. **Metal program** (Nickel Wire) first, then the **Parent lot**.
3. Fill in **Coil / spool ID** (for example `Bobbin 08`), **Wire diameter** (as printed, for example `0,025 mm`), **Length** (m), **Net weight** (kg) and **Tamper seal number**.
4. Save → QC → submit.

**Bulk entry (for example 30 bobbins).** There is no bulk import in the admin. Either create the records one by one, or have a developer script them with WP-CLI. If a script is used, the records must still be submitted through the workflow and reviewed. `Seed::registry()` in `class-seed.php` shows how the 30 Ni bobbins were created.

---

## 5. Copper (Cu) versus nickel (Ni) fields

Program-specific technical fields exist **on the Lot** only. They appear according to the lot's program symbol: Cu fields for Copper Powder, Ni fields for Nickel Wire. Before a program is chosen, both sets show. Fields of the other metal are hidden, and they are never published.

| Copper Powder (Cu) | Nickel Wire (Ni) |
|---|---|
| Particle-size distribution | Wire diameter |
| Minimum / maximum / average particle size | Gauge |
| Morphology | Diameter tolerance |
| Apparent density · Tap density | Coil length · Net coil weight |
| Oxygen content · Moisture content | Surface finish |
| Flow characteristics | Temper / material condition |
| Production method | Tensile strength · Elongation |
| Container type · Net weight per container (kg) | Electrical / thermal characteristics |
| Safety documentation | Packaging method |

Each field shows its pending text, for example *"Pending: not covered by supplied certificate"*. If a CoA does not cover a property, **leave it empty**. Do not copy values from a product brochure or a different lot.

Batch, container and coil records have their own small field sets (§4.2–4.4). The coil's **Wire diameter** is the per-coil measurement. The lot's **Wire diameter** (`ni_diameter`) is the lot specification.

---

## 6. Entering a Certificate of Analysis (CoA)

A CoA record is an **exact transcription** of the certificate, together with the fingerprinted scan.

**Before you start**
- The laboratory exists under **Asset Registry → Laboratories** (`http://localhost:8088/wp-admin/edit.php?post_type=rc_laboratory`). If it does not, create it with its legal name, country and accreditation, but only what is documented.
- The scan is registered as a Document with type *Certificate of Analysis* ([01 §5.2](01-CMS-ADMIN-MANUAL.md#52-add-a-document)).
- The lot, batch, container or coil the certificate applies to exists.

**Steps**
1. **Asset Registry → Certificates of Analysis → Add Certificate of Analysis** (`http://localhost:8088/wp-admin/post-new.php?post_type=rc_coa`).
2. **Title:** `CoA <number>: <goods as printed>`, for example `CoA 0004512: Ultrafine Copper Powder, Lot #03-K-07`.
3. **Content:** `Exact transcription of the owner-supplied certificate. Values are reproduced as printed (comma decimals).` Adjust the wording to the provenance.
4. Fill in the fields **exactly as printed**. Keep the printed decimal commas and units in text fields.

   | Field | Example (from the seeded CoA 0004512) |
   |---|---|
   | Applies to (required) | the lot `RC-CU-LOT-000001` (hold Ctrl to select several) |
   | Issuing laboratory | the laboratory record |
   | Certificate number | `0004512` |
   | Issue date | `2022-07-04` (the date field uses ISO format; the certificate printed 04.07.2022) |
   | Analytical method | `ICP/OES` |
   | Reported purity | `99,9999 %` |
   | Purity basis / standard reference | `Chemical purity based on the impurities Al, Cd, Fe, Mg, Mo, Ni, Sb, Ti, Zn (TU 1793-011-50316079-2004)` |
   | Goods as described on certificate | `Ultrafine Copper Powder, Lot #03-K-07` |
   | Quantity (as declared to laboratory) | `2000 kg* in glass ampoules, packed in cardboard boxes (*net weight according to data supplied by customer)` |
   | Sample | `10 g, taken by the laboratory at ProSafe in Magdeburg, Box no. 20` |
   | Sampling location / date | `ProSafe, Magdeburg (DE)` / `2022-07-01` |
   | Impurity statement | as printed, if present |
   | Isotopic composition | `Natural copper: 63Cu 69.1 % ± 0.05 %, 65Cu 30.9 % ± 0.05 %` |
   | Radioactivity | `The material is not radioactive (per certificate)` |
   | Evidence provenance | see §6.2 |
   | Certificate document | the scan's Document record |
   | Verification status | normally *Pending verification* |

5. **Results of analysis (assay):** follow the transcription format in §6.1.
6. Save → QC (§10) → submit.

**Result:** after publication, the CoA appears in the passport's evidence list. The **Laboratory analysis** lifecycle stage becomes *pending verification* (or *verified*, §8), and the assay grid renders on the program pages (`[rc_coa]`).

> [Screenshot: CoA editor with assay results textarea — http://localhost:8088/wp-admin/post-new.php?post_type=rc_coa]

### 6.1 Assay transcription format

The **Results of analysis** field is a text area. Write **one element per line**, in the form `Symbol: value`:

```
Ag: 8
Al: <1
Bi: <0,5
P: 5
S: 16
Ni: Matrix
```

Rules (the website renders this as a periodic-style grid; see `assay_grid()` in `class-shortcodes.php`):
- Start each line with the element symbol, capitalised as in the periodic table (`Ag`, `Ti`, `B`), then `:` (or `=`), then the value.
- Copy the value **exactly as printed**: keep `<` for "below detection limit" and keep comma decimals (`<0,5`).
  - A value starting with `<` is shown as *below detection limit*.
  - Any other value is shown as *detected*.
- For the base metal, write `Matrix` (for example `Ni: Matrix` on a nickel certificate). It is shown as *matrix (base metal)*.
- The unit is **ppm** for the whole field. If a certificate uses another unit, do not convert. Put the certificate's unit and values in **Impurity statement** and tell the reviewer.
- Lines that do not match `Symbol: value` are **ignored silently**: headings, blank lines, "Element / Result" rows. Do not add comments inside the field.
- Keep the certificate's element order.

### 6.2 Evidence provenance

| Value | Use when | Effect |
|---|---|---|
| **Owner-supplied: not independently verified by ReserveChain** | The asset owner gave you the certificate | Reconciliation raises the info exception `owner_supplied_only` ([03](03-PROOF-OF-RESERVES-MANUAL.md#3-exception-codes-and-how-to-resolve-them)). It must never be shown as Verified |
| **Received directly from laboratory** | The laboratory sent it to ReserveChain directly; keep the email or portal record | Stronger evidence. It is still not "independently verified" until reviewed |
| **Independently verified (attestation attached)** | An independent party verified the certificate and its attestation is attached as a Document | Required before the CoA itself may be set to *Verified* |

---

## 7. Other evidence and linking documents

All evidence records share the same pattern: **Applies to** (one or more lots, batches, containers or coils; valuations and insurance can also apply to a program), the evidence-specific fields, a **Document** link, and a **Verification status**.

| Evidence | Add screen | Lifecycle stage it feeds | Notes |
|---|---|---|---|
| Custody & Ownership | `post-new.php?post_type=rc_custody` | **Custody intake** (record type *Custody intake*) and **Legal ownership record** (record type *Legal ownership record*) | Custodian, legal owner, jurisdiction, effective date, warehouse receipt number. Custodian stays empty until one is appointed |
| Valuation | `post-new.php?post_type=rc_valuation` | **Independent valuation** | Publishing any value needs compliance four-eyes approval. Acquisition cost and commercial spread are internal |
| Insurance | `post-new.php?post_type=rc_insurance` | **Insurance** | The policy number is internal. A past **Period end** raises `insurance_expired` |
| Reserve Report | `post-new.php?post_type=rc_reserve_report` | **Reserve attestation** | Preferably created through PoR attestation intake ([03 §5](03-PROOF-OF-RESERVES-MANUAL.md#5-recording-a-reserve-attestation)) |

### 7.1 Link a document to a record

There are two ways to link a document. Both put the document's SHA-256 into the passport's document ledger and Merkle root.

1. **Through an evidence record (preferred):** set the evidence record's **Document** field (Certificate document, Supporting document, Valuation report, Certificate of insurance, Reserve report document).
2. **Directly:** on the Document record, set **Related records** to the lot, batch, container, coil, program or token program. Use this for weight certificates, photographs and specimen templates.

Only **published** documents with audience *Public* appear in public passports. Restricted (data-room) documents never do ([04 §8](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md#8-data-rooms-audiences-and-signed-downloads)).

---

## 8. Verification statuses

Every registry record carries a **Verification status**, rendered as a coloured pill on the site.

| Status | Pill colour | Meaning | Minimum evidence required |
|---|---|---|---|
| **Proposed** | nickel | A planned item or claim; no evidence yet | None. Describe the plan only |
| **In development** (default) | amber | Being prepared; information incomplete | None. Fields stay empty until sourced |
| **Pending verification** | copper | Evidence received and recorded, but not independently verified | At least one linked, fingerprinted document. Provenance stated. This is the normal status for owner-supplied certificates |
| **Verified** | green | Evidence attached **and** independently verified **and** reviewed | (a) the supporting document is published with a SHA-256; (b) for CoAs, provenance is *Independently verified* with the attestation attached; for reserve reports, an appointed independent attestor; (c) a reviewer who did not prepare the record has checked the transcription against the document; (d) a compliance officer agrees. **The code does not enforce this. The reviewer and the publisher are the control.** |
| **Not applicable** | muted | The item does not apply to this asset | A one-line reason in the content or the data source reference |

Effects of *Verified* in the code:
- A passport lifecycle stage is *verified* only when a linked evidence record of that kind is *Verified*. Otherwise it is *pending verification* (evidence exists) or *pending* (no evidence).
- Proof of Reserves counts **verified kg** only from lots whose status is *Verified*. It computes coverage only from a *Verified* reserve report ([03](03-PROOF-OF-RESERVES-MANUAL.md)).
- The content rules forbid labelling anything "Verified", "Certified" or "In custody" in page copy. Status pills come only from records.

> **Never set *Verified* on owner-supplied evidence.** Doing so would publish a false claim. Reviewers must use **Request changes** on any record marked *Verified* that lacks the evidence above.

---

## 9. Digital Asset Passports

### 9.1 What a passport is

A passport is **assembled automatically, never typed**, for every *published* lot, batch, container and coil, while the open module `passports_public` is on.

- Public page: `/passport/<record no>/`, for example `http://localhost:8088/passport/RC-CU-LOT-000001/`.
- JSON export: the same URL with `?format=json`. Schema `reservechain.dap/1.0`.
- API: `GET /wp-json/rc/v1/passports/<record no>`.
- WP-CLI: `W rc passport RC-CU-LOT-000001`.

### 9.2 What is derived, and from where

| Passport element | Derived from |
|---|---|
| Identity fields (each provided or pending) | The asset record's public schema fields. Empty fields show their pending text |
| Program | The record's Metal program (name, symbol, atomic number) |
| Lineage | Walks up: container → batch → lot (up to 5 levels) |
| Children | Batches, containers and coils that point to this record |
| Evidence | Published CoA, custody, valuation, insurance and reserve-report records whose **Applies to** includes this record **or any ancestor**. Evidence from an ancestor is marked "inherited from <record no>" |
| Document ledger | Documents linked from that evidence, plus documents whose **Related records** include this record or an ancestor. Each shows its SHA-256, status, issuer, date and version |
| Lifecycle timeline | Registry record created → Laboratory analysis → Custody intake → Legal ownership → Insurance → Independent valuation → Reserve attestation → Token program linkage. Each stage is *verified*, *pending verification* or *pending*, according to §8 |
| Record fingerprint | SHA-256 of the record number, the type and every public field `[key, value]` |
| **Evidence Merkle root** | See §9.3 |
| Completeness | (provided fields that have a pending text + verified lifecycle stages) ÷ (all such fields + counted stages). Gaps stay visible instead of hidden |
| QR code | Encodes the passport URL. Shown on the passport card |
| Disclosure | The current mandatory disclosure |

### 9.3 The Merkle root

- **Leaves:** the record fingerprint, plus the SHA-256 of every document in the ledger.
- **Construction:** leaves are de-duplicated and sorted. Each pair is sorted and hashed as SHA-256(bytes(a) ‖ bytes(b)). An odd leaf is paired with itself. This repeats until one hash remains.
- **What it proves:** if any public field or any linked document changes, the root changes. Anyone can recompute it from the JSON export (`record_fingerprint` + `documents[].sha256`).
- **When it changes:** you edit a public field; you link, unlink or publish a document; you publish evidence that adds a document. An unexpected change in the root is a reason to investigate the audit trail (`registry.updated`).

### 9.4 Check a passport before and after publication

1. In the record's **Registry record** box, read the **Evidence completeness** and **Evidence Merkle root**. In the admin, these include draft evidence.
2. After publication, open **View Digital Asset Passport**. Check:
   - the pending texts are correct;
   - no internal field is visible;
   - the documents show the right SHA-256;
   - inherited evidence is labelled.
3. Open `?format=json` and keep a copy with your release or QC record if required.

> [Screenshot: Public passport page with QR, Merkle root and timeline — http://localhost:8088/passport/RC-CU-LOT-000001/]

### 9.5 The illustrative template

`/passport/RC-LOT-000001/` is the **Illustrative Industrial Metal Asset Template**: a lot with no program, so its number has the `RC-` scope. It shows every field in its pending state. It is clearly labelled illustrative and must **never** be filled with real data. Copy its structure for training only.

Seeded real examples (owner-supplied, *pending verification*):
- `RC-CU-LOT-000001` (Cu Lot #03-K-07)
- `RC-CU-CTN-000001` (Box no. 20, the sampled box)
- `RC-NI-LOT-000001` (Ni Lot 120/NP1)
- `RC-NI-COL-000008` (bobbin 8)

**If something goes wrong**
- *Passport returns 404:* the record is not *Published*, the number is mistyped, the record is not a passport type, or `passports_public` is off ([01 §8.3](01-CMS-ADMIN-MANUAL.md#83-gated-modules)).
- *Evidence missing on the passport:* the evidence record or its document is not published, or the document's audience is not *Public*.
- *A relation shows as pending:* the related record (for example the laboratory) is not published. Relations display only published targets.

---

## 10. QC checklist before submitting or approving

Preparers tick this list before **Submit for review**. Reviewers tick it again before **Approve**. Paste the ticked list into the ticket.

**Identity**
- [ ] The title follows the naming convention (§11).
- [ ] The metal program was set **before** the first save; the record number has the right scope (CU, NI or RC).
- [ ] The parent (lot or batch) is correct. A container has a batch *or* a lot, not unrelated both.

**Values**
- [ ] Every non-empty field can be traced to a named source in **Data source reference**.
- [ ] No value is estimated, rounded, converted or copied from another lot.
- [ ] Text fields keep the printed form (comma decimals, units). Number fields use a dot decimal, and the original is noted.
- [ ] Location is at disclosure level only.
- [ ] Internal-only facts (supplier, cost, policy number) sit only in fields marked *internal*.

**Evidence**
- [ ] Each linked document is fingerprinted, and its SHA-256 matches a hash you computed independently.
- [ ] CoA assay lines follow §6.1 and match the scan line by line, checked by a second person.
- [ ] Provenance is set correctly (§6.2).
- [ ] Documents meant for the passport have audience *Public*.

**Statuses**
- [ ] The verification status matches the evidence (§8). *Verified* only with independent verification.
- [ ] Lifecycle statuses (custody, reserve, tokenization) are unchanged unless evidence supports the change. Changes match [03](03-PROOF-OF-RESERVES-MANUAL.md) to avoid exceptions.
- [ ] Redemption status was not edited by hand.

**Language**
- [ ] The content uses "proposed / pending / subject to verification" language. It has no prohibited words (Content Authoring Guide §4).
- [ ] Illustrative content carries the *Illustrative / demo data* badge.

**After publication (publisher)**
- [ ] The passport opens, the Merkle root is shown, and no internal field is visible.
- [ ] The Proof of Reserves screen shows no new critical exception caused by this record.

---

## 11. Naming conventions

| Record | Title pattern | Example |
|---|---|---|
| Metal Program | `<Material>` | `Copper Powder` |
| Lot | `<Product name>: Lot <producer ref>` | `Ultrafine Copper Powder: Lot #03-K-07` |
| Batch | `Lot <ref>: Batch <ref>` | `Lot #03-K-07: Batch B1` |
| Container | `Lot <ref>: <container id> (<note>)` | `Lot #03-K-07: Box no. 20 (sampled box)` |
| Coil | `Lot <ref>: Bobbin <nn>` | `Lot 120/NP1: Bobbin 08` |
| Laboratory | Legal name as on the accreditation | — |
| CoA | `CoA <number>: <goods as printed>` | `CoA 0004368: Nickel Wire 0.025 mm, Lot 120/NP1` |
| Custody | `<Record type>: Lot <ref> (<custodian or "custodian to be appointed">)` | `Custody intake: Lot #03-K-07 (draft for review)` |
| Reserve Report | `Reserve attestation — <program> — <YYYY-MM-DD>` (created automatically by intake) | `Reserve attestation — Copper Powder — 2026-09-30` |
| Document | `<Doc type> <number> — <issuer> — <subject> (<scan / original / specimen>)` | `CoA 0004512 — IGAS — Cu Lot #03-K-07 (scan)` |
| Document version | `vMAJOR.MINOR`; increase MINOR for a corrected scan, MAJOR for a re-issued document | `v1.0`, `v1.1`, `v2.0` |

Other rules:
- Use the producer's own reference characters exactly, including `#`, `/` and leading zeros.
- No prices, quantities or promotional words in titles.
- English titles. Translations go in the Translations box ([01 §3.3](01-CMS-ADMIN-MANUAL.md#33-edit-translations-es--it)).
