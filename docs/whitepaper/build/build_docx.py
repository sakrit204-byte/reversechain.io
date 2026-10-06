"""Build an editable, styled Word version of whitepaper.md -> ../ReserveChain-Whitepaper.docx

Requires: python-docx, markdown-it-py; diagrams rendered to ../diagrams/*.png (render_diagrams.mjs).
Features: brand title page, Word TOC field (update with F9 / on open), running header, footer with
disclosure + page numbers, styled tables, highlighted placeholders, embedded diagrams with captions.
"""
import pathlib
import re

from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor
from markdown_it import MarkdownIt

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parent
OUT = ROOT / "ReserveChain-Whitepaper.docx"

INK = RGBColor(0x0B, 0x0F, 0x14)
GRAPHITE = RGBColor(0x14, 0x1A, 0x22)
COPPER = RGBColor(0xC4, 0x6A, 0x3A)
COPPER_DARK = RGBColor(0x8A, 0x4A, 0x24)
NICKEL = RGBColor(0x9F, 0xB3, 0xC2)
MUTED = RGBColor(0x5E, 0x6B, 0x78)
SERIF = "Georgia"  # brand serif is Fraunces; Georgia is used in Word for portability (Fraunces is used in the PDF)
SANS = "Calibri"
MONO = "Consolas"

DISCLOSURE = (
    "ReserveChain is currently in development. No tokens are being offered or sold through this website. "
    "Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, "
    "token allocation or entitlement to participate in any future offering. Any future availability will be subject to "
    "the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody "
    "arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval."
)
EU_NOTICE = "ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA."

DIAGRAM = re.compile(r"<!--\s*diagram:\s*([\w\-]+)\s*-->\s*```mermaid\n.*?```", re.S)
PLACEHOLDER = re.compile(r"^(\[.*\]|Pending.*|To be determined.*|Not yet.*)$")


# ---------------------------------------------------------------- helpers
def shade(cell_or_par, hex_fill):
    el = cell_or_par._tc if hasattr(cell_or_par, "_tc") else cell_or_par._p
    pr = el.get_or_add_tcPr() if hasattr(cell_or_par, "_tc") else el.get_or_add_pPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), hex_fill)
    pr.append(shd)


def border_left(par, color="C46A3A", size=18):
    ppr = par._p.get_or_add_pPr()
    bdr = OxmlElement("w:pBdr")
    left = OxmlElement("w:left")
    left.set(qn("w:val"), "single")
    left.set(qn("w:sz"), str(size))
    left.set(qn("w:space"), "8")
    left.set(qn("w:color"), color)
    bdr.append(left)
    ppr.append(bdr)


def border_top(par, color="C46A3A", size=18):
    ppr = par._p.get_or_add_pPr()
    bdr = OxmlElement("w:pBdr")
    top = OxmlElement("w:top")
    top.set(qn("w:val"), "single")
    top.set(qn("w:sz"), str(size))
    top.set(qn("w:space"), "4")
    top.set(qn("w:color"), color)
    bdr.append(top)
    ppr.append(bdr)


def set_font(run, name, size=None, color=None, bold=None, italic=None):
    run.font.name = name
    rpr = run._element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = OxmlElement("w:rFonts")
        rpr.append(rfonts)
    for a in ("w:ascii", "w:hAnsi", "w:cs", "w:eastAsia"):
        rfonts.set(qn(a), name)
    if size:
        run.font.size = Pt(size)
    if color is not None:
        run.font.color.rgb = color
    if bold is not None:
        run.bold = bold
    if italic is not None:
        run.italic = italic


def add_field(par, instr):
    r = par.add_run()
    b = OxmlElement("w:fldChar")
    b.set(qn("w:fldCharType"), "begin")
    i = OxmlElement("w:instrText")
    i.set(qn("xml:space"), "preserve")
    i.text = instr
    s = OxmlElement("w:fldChar")
    s.set(qn("w:fldCharType"), "separate")
    t = OxmlElement("w:t")
    t.text = "Right-click and choose Update Field to build the table of contents." if "TOC" in instr else "1"
    e = OxmlElement("w:fldChar")
    e.set(qn("w:fldCharType"), "end")
    r._r.append(b)
    r._r.append(i)
    r._r.append(s)
    r._r.append(t)
    r._r.append(e)
    return r


def repeat_header(row):
    trpr = row._tr.get_or_add_trPr()
    h = OxmlElement("w:tblHeader")
    h.set(qn("w:val"), "true")
    trpr.append(h)


# ---------------------------------------------------------------- styles
def setup_styles(doc):
    st = doc.styles
    normal = st["Normal"]
    normal.font.name = SANS
    normal.font.size = Pt(10.5)
    normal.font.color.rgb = INK
    normal.paragraph_format.space_after = Pt(6)
    normal.paragraph_format.line_spacing = 1.15
    for lvl, size, color in ((1, 22, INK), (2, 15, GRAPHITE), (3, 12, GRAPHITE)):
        h = st[f"Heading {lvl}"]
        h.font.name = SERIF
        rpr = h.element.get_or_add_rPr()
        rf = rpr.find(qn("w:rFonts"))
        if rf is None:
            rf = OxmlElement("w:rFonts")
            rpr.append(rf)
        for a in ("w:ascii", "w:hAnsi", "w:cs", "w:eastAsia"):
            rf.set(qn(a), SERIF)
        for a in ("w:asciiTheme", "w:hAnsiTheme", "w:cstheme", "w:eastAsiaTheme"):
            if rf.get(qn(a)) is not None:
                del rf.attrib[qn(a)]
        h.font.size = Pt(size)
        h.font.bold = True
        h.font.color.rgb = color
        h.paragraph_format.space_before = Pt(18 if lvl == 1 else 12)
        h.paragraph_format.space_after = Pt(6)
        h.paragraph_format.keep_with_next = True
    cap = st["Caption"]
    cap.font.name = SANS
    cap.font.size = Pt(8.5)
    cap.font.italic = True
    cap.font.color.rgb = MUTED


# ---------------------------------------------------------------- inline rendering
def add_inline(par, children, base_size=None, base_color=None):
    bold = italic = False
    link = False
    for tok in children:
        t = tok.type
        if t == "strong_open":
            bold = True
        elif t == "strong_close":
            bold = False
        elif t == "em_open":
            italic = True
        elif t == "em_close":
            italic = False
        elif t == "link_open":
            link = True
        elif t == "link_close":
            link = False
        elif t in ("softbreak",):
            par.add_run(" ")
        elif t == "hardbreak":
            par.add_run().add_break()
        elif t == "code_inline":
            r = par.add_run(tok.content)
            set_font(r, MONO, (base_size or 10.5) - 1.2, GRAPHITE)
        elif t == "text":
            r = par.add_run(tok.content)
            if base_size:
                r.font.size = Pt(base_size)
            if base_color is not None:
                r.font.color.rgb = base_color
            r.bold = bold or None
            r.italic = italic or None
            if link:
                r.font.color.rgb = COPPER
            if bold and PLACEHOLDER.match(tok.content.strip()):
                r.font.color.rgb = COPPER_DARK
                rpr = r._element.get_or_add_rPr()
                hl = OxmlElement("w:shd")
                hl.set(qn("w:val"), "clear")
                hl.set(qn("w:color"), "auto")
                hl.set(qn("w:fill"), "F7E7DC")
                rpr.append(hl)


# ---------------------------------------------------------------- block rendering
def render_table(doc, tokens, i):
    rows, row, cell = [], None, None
    header_rows = 0
    in_head = False
    while tokens[i].type != "table_close":
        t = tokens[i]
        if t.type == "thead_open":
            in_head = True
        elif t.type == "thead_close":
            in_head = False
        elif t.type == "tr_open":
            row = []
        elif t.type == "tr_close":
            rows.append(row)
            if in_head:
                header_rows += 1
        elif t.type == "inline":
            row.append(t.children)
        i += 1
    ncols = max(len(r) for r in rows)
    table = doc.add_table(rows=len(rows), cols=ncols)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.style = doc.styles["Table Grid"]
    for ri, r in enumerate(rows):
        for ci in range(ncols):
            c = table.cell(ri, ci)
            p = c.paragraphs[0]
            p.paragraph_format.space_after = Pt(1)
            children = r[ci] if ci < len(r) else []
            if ri < header_rows:
                shade(c, "0B0F14")
                add_inline(p, children, 8.5, RGBColor(0xF5, 0xF2, 0xEC))
                for run in p.runs:
                    run.bold = True
            else:
                if ri % 2 == 0:
                    shade(c, "FBF9F5")
                add_inline(p, children, 8.5)
        if ri < header_rows:
            repeat_header(table.rows[ri])
    # light borders
    tbl_pr = table._tbl.tblPr
    borders = OxmlElement("w:tblBorders")
    for edge in ("top", "left", "bottom", "right", "insideH", "insideV"):
        e = OxmlElement(f"w:{edge}")
        e.set(qn("w:val"), "single")
        e.set(qn("w:sz"), "4")
        e.set(qn("w:color"), "D9D3C7")
        borders.append(e)
    tbl_pr.append(borders)
    doc.add_paragraph().paragraph_format.space_after = Pt(2)
    return i


def render(doc, md_text):
    md = MarkdownIt("commonmark", {"html": True, "typographer": True}).enable("table")
    tokens = md.parse(md_text)
    list_stack = []
    quote = 0
    first_h2 = True
    i = 0
    while i < len(tokens):
        t = tokens[i]
        typ = t.type
        if typ == "heading_open":
            level = int(t.tag[1])
            inline = tokens[i + 1]
            word_level = max(1, level - 1)  # md "##" -> Word Heading 1
            if level == 2 and not first_h2:
                doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)
            if level == 2:
                first_h2 = False
            h = doc.add_heading(level=word_level)
            add_inline(h, inline.children)
            if word_level == 1:
                border_top(h)
            i += 3
            continue
        if typ == "paragraph_open":
            inline = tokens[i + 1]
            style = None
            if list_stack:
                style = "List Bullet" if list_stack[-1] == "ul" else "List Number"
            p = doc.add_paragraph(style=style)
            if quote:
                shade(p, "F5F2EC")
                border_left(p)
                p.paragraph_format.left_indent = Cm(0.3)
                add_inline(p, inline.children, 9.5)
            else:
                if not list_stack:
                    p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
                add_inline(p, inline.children)
            i += 3
            continue
        if typ == "bullet_list_open":
            list_stack.append("ul")
        elif typ == "ordered_list_open":
            list_stack.append("ol")
        elif typ in ("bullet_list_close", "ordered_list_close"):
            list_stack.pop()
        elif typ == "blockquote_open":
            quote += 1
        elif typ == "blockquote_close":
            quote -= 1
        elif typ == "table_open":
            i = render_table(doc, tokens, i)
        elif typ == "html_block":
            m = re.search(r"<!--\s*figure:\s*([\w\-]+)\s*-->", t.content)
            if m:
                add_figure(doc, m.group(1))
        elif typ == "fence":
            p = doc.add_paragraph()
            r = p.add_run(t.content)
            set_font(r, MONO, 8.5, GRAPHITE)
        i += 1


def add_figure(doc, name):
    png = ROOT / "diagrams" / f"{name}.png"
    num = int(name.split("-", 1)[0])
    caption = name.split("-", 1)[1].replace("-", " ").capitalize()
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.keep_with_next = True
    if png.exists():
        from PIL import Image  # python-docx dependency chain does not include Pillow; optional

        w, h = Image.open(png).size
        max_w, max_h = 16.0, 17.0
        width = min(max_w, max_h * w / h)
        p.add_run().add_picture(str(png), width=Cm(width))
    else:
        p.add_run(f"[Diagram {name} — render with render_diagrams.mjs]")
    c = doc.add_paragraph(f"Figure {num} — {caption}", style="Caption")
    c.alignment = WD_ALIGN_PARAGRAPH.CENTER


# ---------------------------------------------------------------- page furniture
def title_page(doc):
    sec = doc.sections[0]
    sec.top_margin = Cm(2.5)
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(60)
    r = p.add_run("Cu 29   ·   Ni 28")
    set_font(r, MONO, 14, COPPER, bold=True)
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(30)
    r = p.add_run("ReserveChain")
    set_font(r, SERIF, 40, INK, bold=True)
    p = doc.add_paragraph()
    r = p.add_run("Industrial-Metals Reserve Registry\nand Tokenization Framework")
    set_font(r, SERIF, 18, COPPER)
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(18)
    r = p.add_run("INSTITUTIONAL WHITEPAPER · DISCUSSION DRAFT · IN DEVELOPMENT")
    set_font(r, MONO, 9.5, RGBColor(0xB0, 0x7A, 0x1E), bold=True)
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(12)
    r = p.add_run(
        "Version: Draft 0.9 — subject to legal review and final approval\n"
        "Date of this version: [To be inserted on approval by ReserveChain]\nreservechain.io"
    )
    set_font(r, MONO, 9.5, MUTED)
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(110)
    border_top(p, "9FB3C2", 8)
    r = p.add_run("Mandatory disclosure. ")
    set_font(r, SANS, 8.5, INK, bold=True)
    r = p.add_run(DISCLOSURE)
    set_font(r, SANS, 8.5, MUTED)
    p = doc.add_paragraph()
    r = p.add_run("EU/EEA notice. ")
    set_font(r, SANS, 8.5, INK, bold=True)
    r = p.add_run(EU_NOTICE)
    set_font(r, SANS, 8.5, MUTED)


def toc_page(doc):
    doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)
    p = doc.add_paragraph()
    r = p.add_run("Contents")
    set_font(r, SERIF, 22, INK, bold=True)
    border_top(p)
    add_field(doc.add_paragraph(), 'TOC \\o "1-2" \\h \\z \\u')
    doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)


def furniture(doc):
    # new section for body so the title page has no header/footer
    sec = doc.sections[-1]
    sec.different_first_page_header_footer = True
    for s in doc.sections:
        s.page_height, s.page_width = Cm(29.7), Cm(21.0)
        s.left_margin = s.right_margin = Cm(2.0)
        s.bottom_margin = Cm(2.2)
        hp = s.header.paragraphs[0]
        r = hp.add_run("ReserveChain — Institutional Whitepaper")
        set_font(r, SERIF, 8, COPPER)
        r = hp.add_run("\tDiscussion draft 0.9 · subject to final approval")
        set_font(r, SANS, 7.5, MUTED)
        hp.paragraph_format.tab_stops.add_tab_stop(Cm(17.0), alignment=2)
        fp = s.footer.paragraphs[0]
        r = fp.add_run(
            "ReserveChain is currently in development. No tokens are being offered or sold. Not an offer or offering document. "
            "ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA."
        )
        set_font(r, SANS, 6.5, MUTED)
        fp2 = s.footer.add_paragraph()
        fp2.alignment = WD_ALIGN_PARAGRAPH.RIGHT
        r = fp2.add_run("Page ")
        set_font(r, SANS, 8, INK)
        pr = add_field(fp2, "PAGE")
        set_font(pr, SANS, 8, INK)


def main():
    text = (ROOT / "whitepaper.md").read_text(encoding="utf-8")
    text = re.sub(r"\A---\n.*?\n---\n", "", text, flags=re.S)
    body = text[text.index("## Important Notice"):]
    # drop the hand-written TOC (Word TOC field replaces it)
    body = re.sub(r"## Table of Contents\n.*?(?=\n## )", "", body, flags=re.S)
    body = DIAGRAM.sub(lambda m: f"\n<!-- figure: {m.group(1)} -->\n", body)

    doc = Document()
    setup_styles(doc)
    title_page(doc)
    toc_page(doc)
    render(doc, body)
    furniture(doc)
    # ask Word to refresh fields (TOC) on open
    settings = doc.settings.element
    upd = OxmlElement("w:updateFields")
    upd.set(qn("w:val"), "true")
    settings.append(upd)
    core = doc.core_properties
    core.title = "ReserveChain — Institutional Whitepaper (Discussion Draft 0.9)"
    core.author = "ReserveChain (in development)"
    core.subject = "Not an offering document"
    doc.save(OUT)
    print("wrote", OUT)


if __name__ == "__main__":
    main()
