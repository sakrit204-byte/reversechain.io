"""Compose 1920x1080 contest entry boards from live screenshots (scripts/shots) into submission/boards/."""
import os
from PIL import Image, ImageDraw, ImageFont, ImageFilter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHOTS = os.path.join(ROOT, 'scripts', 'shots')
OUT = os.path.join(ROOT, 'submission', 'boards')
os.makedirs(OUT, exist_ok=True)

F = 'C:/Windows/Fonts/'
SERIF = F + 'georgiab.ttf'
SANS = F + 'segoeui.ttf'
SANSB = F + 'segoeuib.ttf'
MONO = F + 'consola.ttf'
BG, PANEL, GOLD, TEXT, MUTED, LINE = (5, 12, 20), (11, 22, 33), (213, 177, 103), (231, 235, 238), (142, 153, 165), (28, 42, 56)
W, H = 1920, 1080


def font(p, s):
    return ImageFont.truetype(p, s)


def base(num, kicker, title, gold, notes):
    im = Image.new('RGB', (W, H), BG)
    d = ImageDraw.Draw(im)
    for x in range(0, W, 64):
        d.line([(x, 0), (x, H)], fill=(9, 18, 28))
    for y in range(0, H, 64):
        d.line([(0, y), (W, y)], fill=(9, 18, 28))
    # brand
    hexagon = [(70, 46), (90, 57.5), (90, 80.5), (70, 92), (50, 80.5), (50, 57.5)]
    d.polygon(hexagon, outline=GOLD, width=3)
    d.polygon([(70, 54), (82, 61), (70, 68), (58, 61)], fill=GOLD)
    d.polygon([(58, 61), (70, 68), (70, 82), (58, 75)], fill=(201, 119, 63))
    d.polygon([(70, 68), (82, 61), (82, 75), (70, 82)], fill=(169, 187, 200))
    d.text((106, 50), 'Reserve', font=font(SERIF, 30), fill=TEXT)
    d.text((106 + d.textlength('Reserve', font=font(SERIF, 30)), 50), 'Chain.io', font=font(SERIF, 30), fill=GOLD)
    d.text((W - 60, 58), f'{num:02d} / 10', font=font(MONO, 22), fill=MUTED, anchor='ra')
    d.text((60, 140), kicker.upper(), font=font(SANSB, 20), fill=GOLD, spacing=4)
    size = 50
    while size > 30 and max(d.textlength(title, font=font(SERIF, size)), d.textlength(gold, font=font(SERIF, size))) > 600:
        size -= 2
    d.text((60, 175), title, font=font(SERIF, size), fill=TEXT)
    d.text((60, 175 + int(size * 1.3)), gold, font=font(SERIF, size), fill=GOLD)
    y = 330
    for n in notes:
        d.ellipse([(62, y + 11), (70, y + 19)], fill=GOLD)
        words, line, lines = n.split(), '', []
        for w_ in words:
            t = (line + ' ' + w_).strip()
            if d.textlength(t, font=font(SANS, 23)) > 520:
                lines.append(line); line = w_
            else:
                line = t
        lines.append(line)
        for ln in lines:
            d.text((86, y), ln, font=font(SANS, 23), fill=TEXT)
            y += 33
        y += 14
    d.line([(60, H - 70), (W - 60, H - 70)], fill=LINE, width=1)
    d.text((60, H - 52), 'Pre-launch · in development · no tokens are offered or sold · subject to final approval', font=font(SANS, 18), fill=MUTED)
    d.text((W - 60, H - 52), 'WordPress CMS · PHP/MySQL · ERC-20 (testnet) · iOS/Android · EN/ES/IT', font=font(SANS, 18), fill=MUTED, anchor='ra')
    return im


def frame(im, shot, box, crop=None, radius=14):
    x, y, w, h = box
    s = Image.open(os.path.join(SHOTS, shot)).convert('RGB')
    if crop:
        s = s.crop(crop)
    r = min(w / s.width, h / s.height)
    s = s.resize((int(s.width * r), int(s.height * r)), Image.LANCZOS)
    shadow = Image.new('RGBA', (s.width + 60, s.height + 60), (0, 0, 0, 0))
    ImageDraw.Draw(shadow).rounded_rectangle([30, 40, s.width + 30, s.height + 30], radius, fill=(0, 0, 0, 170))
    shadow = shadow.filter(ImageFilter.GaussianBlur(18))
    px, py = x + (w - s.width) // 2, y + (h - s.height) // 2
    im.paste(shadow, (px - 30, py - 30), shadow)
    mask = Image.new('L', s.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, s.width - 1, s.height - 1], radius, fill=255)
    im.paste(s, (px, py), mask)
    ImageDraw.Draw(im).rounded_rectangle([px, py, px + s.width - 1, py + s.height - 1], radius, outline=(70, 60, 40), width=2)


boards = [
    ('home-fold.png', None, 'Public website · WordPress', 'Institutional pre-launch site', 'with live integrity ledger',
     ['Approved hero copy and mandated browser title; 22 homepage sections in the master order',
      'Navy / gold brand system, SVG logo redraw, self-hosted fonts, no third-party trackers',
      'Ledger reads the real API: audit-chain head, DB immutability, registered units, fingerprints',
      'Mandatory no-offer disclosure, EU/EEA notice and Provisional Asset Notice site-wide']),
    ('passport_RC_CU_LOT_000001-1440.png', (0, 0, 1440, 900), 'Digital Asset Passports', 'Assembled from evidence,', 'never typed by hand',
     ['One passport per lot, container and bobbin: 34 live passports incl. 30 nickel bobbins',
      'QR code, unit status strip, evidence completeness ring, machine-readable JSON export',
      'SHA-256 Merkle root over every linked document — any change is detectable',
      'Lifecycle derived from evidence; missing data shown as explicit pending states']),
    ('el-coa.png', None, 'Owner-supplied evidence', 'IGAS CoA 0004512 transcribed', 'value by value',
     ['Both supplied certificates registered with SHA-256 fingerprints and original scans',
      'Exact transcription (comma decimals), purity basis, sampling, isotopes, radioactivity',
      'Periodic-style assay grid separates detected elements from below-limit and matrix',
      'Labelled owner-supplied — never presented as independently verified']),
    ('el-verify.png', None, 'Public verification', 'Verify any document', 'without uploading it',
     ['Browser computes the SHA-256 fingerprint locally; only the hash is checked',
      'Match returns the registered document, issuer, status and linked passports',
      'Withdrawn or archived documents are flagged, not silently matched',
      'Turns anti-fraud from a warning into a tool anyone can use']),
    ('el-por.png', None, 'Proof of Reserves', 'Computed live,', 'never claimed',
     ['Dashboard calculated from approved registry records in real time',
      'Declared (owner-supplied) and verified quantities are never blended',
      'Coverage is computed only from an independent attestation — none exists, so none is shown',
      'ReserveGuard contract caps minting at attested reserves (fail-closed)']),
    ('admin-audit-trail.png', None, 'CMS · Audit trail', 'Append-only and tamper-evident', 'at three layers',
     ['SHA-256 hash chain: every entry commits to the previous one',
      'MySQL triggers reject UPDATE / DELETE — proven by `wp rc tamper-test`',
      'One-click full-chain verification and JSONL export; chain head anchored on-chain (AuditAnchor)',
      'Logs logins, workflow, field-level diffs, settings, exports, consent and compliance changes']),
    ('admin-review-queue.png', None, 'CMS · Four-eyes workflow', 'Draft › Review › Approved', '› Published › Archived',
     ['Approver must differ from submitter and last editor; direct publishing is intercepted',
      'Approval bound to a content fingerprint — any later edit invalidates it',
      '5 staff roles + administrator, MFA (TOTP) with recovery codes, staff session limits',
      '10 website modes; gated modules need an authorization reference AND a deployment flag']),
    ('app-strip.png', None, 'iOS & Android apps · Expo / React Native', 'Same API, same evidence,', 'same rules',
     ['Registration, login, TOTP MFA, biometric unlock, secure token storage, auto-logout',
      'Programs, passports, documents, eligibility (KYC/KYB/AML/sanctions), notifications, support',
      'Wallet, holdings, purchase, PoR and redemption built but locked until authorized',
      'EN/ES/IT, 27 tests, expo-doctor 21/21; EAS profiles for TestFlight & Google Play']),
    ('contracts', None, 'Smart contracts · Solidity / OpenZeppelin v5', '83 tests passing,', '100% line coverage',
     ['ReserveToken: fail-closed minting, pause, roles, permit, multisig-ready admin handover',
      'ComplianceRegistry (EU/EEA blockable), ReserveGuard, RedemptionManager (escrow → burn)',
      'Treasury limits + timelock, AuditAnchor for the CMS audit chain; Slither reviewed',
      'Every tokenomics parameter unset by default; mainnet deploy refused without written authorization']),
    ('lang_es-1440.png', (0, 0, 1440, 900), 'Trilingual · EN / ES / IT', '63 routes, 3 languages,', 'one source of truth',
     ['Full mega-menu IA from the Website Developer Instructions (6 groups, 60+ pages)',
      '518 interface strings + every page translated; English remains authoritative',
      'hreflang, canonical, noindex outside production, per-page SEO fields',
      'Whitepaper (33 pp. PDF + editable DOCX), architecture, schedule, traceability matrix, runbooks']),
]

for i, (shot, crop, kicker, title, gold, notes) in enumerate(boards, 1):
    im = base(i, kicker, title, gold, notes)
    if shot == 'contracts':
        d = ImageDraw.Draw(im)
        d.rounded_rectangle([700, 140, 1860, 960], 16, fill=(3, 8, 14), outline=(70, 60, 40), width=2)
        d.text((730, 165), '$ npx hardhat test', font=font(MONO, 24), fill=GOLD)
        lines = open(os.path.join(SHOTS, 'hardhat-test.txt'), encoding='utf-8', errors='replace').read().splitlines()[-24:]
        y = 210
        for ln in lines:
            col = (63, 179, 127) if '✔' in ln or 'passing' in ln else TEXT
            d.text((730, y), ln.replace('✔', '+')[:88], font=font(MONO, 21), fill=col)
            y += 30
    elif shot == 'app-strip.png':
        frame(im, shot, (650, 250, 1230, 700))
    else:
        frame(im, shot, (700, 130, 1170, 840), crop)
    im.save(os.path.join(OUT, f'{i:02d}-{kicker.split("·")[0].strip().lower().replace(" ", "-").replace("/", "")}.png'), optimize=True)
    print('board', i)
