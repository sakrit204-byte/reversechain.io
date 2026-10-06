"""Build a print-ready, brand-styled HTML version of whitepaper.md (input for build_pdf.mjs).

- Strips YAML front matter and the Markdown cover block; renders a designed cover page instead.
- Replaces each <!-- diagram: NAME --> + ```mermaid block with the rendered ../diagrams/NAME.svg.
- Output: build/out/whitepaper.html
"""
import html
import pathlib
import re

from markdown_it import MarkdownIt

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parent
OUT = HERE / "out"
OUT.mkdir(exist_ok=True)

DISCLOSURE = (
    "ReserveChain is currently in development. No tokens are being offered or sold through this website. "
    "Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, "
    "token allocation or entitlement to participate in any future offering. Any future availability will be subject to "
    "the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody "
    "arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval."
)
EU_NOTICE = "ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA."

DIAGRAM = re.compile(r"<!--\s*diagram:\s*([\w\-]+)\s*-->\s*```mermaid\n.*?```", re.S)


def load_body() -> str:
    text = (ROOT / "whitepaper.md").read_text(encoding="utf-8")
    text = re.sub(r"\A---\n.*?\n---\n", "", text, flags=re.S)  # front matter
    start = text.index("## Important Notice")  # drop markdown cover
    return text[start:]


def diagram_to_figure(m: re.Match) -> str:
    name = m.group(1)
    svg = (ROOT / "diagrams" / f"{name}.svg").as_uri()
    caption = name.split("-", 1)[1].replace("-", " ").capitalize()
    num = int(name.split("-", 1)[0])
    return f'\n<figure class="diagram"><img src="{svg}" alt="{html.escape(caption)}"/><figcaption>Figure {num} — {html.escape(caption)}</figcaption></figure>\n'


def render() -> str:
    body = DIAGRAM.sub(diagram_to_figure, load_body())
    md = MarkdownIt("commonmark", {"html": True, "typographer": True}).enable("table")
    content = md.render(body)
    # Placeholder highlighting: bracketed "[To be ...]" and "Pending — ..." strong text
    content = re.sub(r"<strong>(\[[^<]*?\])</strong>", r'<strong class="ph">\1</strong>', content)
    content = re.sub(r"<strong>((?:Pending|To be determined|Not yet)[^<]*)</strong>", r'<strong class="ph">\1</strong>', content)
    return content


CSS = """
@import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400&display=swap');
:root { --ink:#0B0F14; --graphite:#141A22; --line:#2A3542; --paper:#F5F2EC; --muted:#5E6B78;
        --copper:#C46A3A; --copper-l:#E39A6B; --nickel:#9FB3C2; --nickel-l:#C9D6DF; }
@page { size: A4; margin: 22mm 18mm 24mm 18mm; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: Inter, "Segoe UI", Arial, sans-serif; font-size: 10.2pt; line-height: 1.55; color: var(--ink); background: #fff; margin: 0; }
h1, h2, h3, h4 { font-family: Fraunces, Georgia, "Times New Roman", serif; font-weight: 600; color: var(--ink); line-height: 1.2; }
h2:first-child { break-before: avoid; }
h2 { font-size: 21pt; margin: 0 0 10pt; padding-top: 4pt; border-top: 3px solid var(--copper); break-before: page; }
h3 { font-size: 13.5pt; margin: 16pt 0 6pt; color: var(--graphite); }
h4 { font-size: 11pt; margin: 12pt 0 4pt; }
p { margin: 0 0 7pt; text-align: justify; hyphens: auto; }
ul, ol { margin: 0 0 8pt 16pt; padding: 0; } li { margin-bottom: 3pt; }
a { color: var(--copper); text-decoration: none; }
code { font-family: "IBM Plex Mono", Consolas, monospace; font-size: 8.8pt; background: var(--paper); padding: 0 3px; border-radius: 2px; }
table { width: 100%; border-collapse: collapse; margin: 6pt 0 12pt; font-size: 8.8pt; break-inside: auto; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
th { background: var(--ink); color: var(--paper); text-align: left; font-weight: 600; padding: 5pt 6pt; }
td { padding: 4.5pt 6pt; border-bottom: 0.6pt solid #D9D3C7; vertical-align: top; }
tbody tr:nth-child(even) td { background: #FBF9F5; }
blockquote { margin: 8pt 0 12pt; padding: 8pt 12pt; background: var(--paper); border-left: 3px solid var(--copper); font-size: 9.4pt; break-inside: avoid; }
blockquote p { margin: 0 0 4pt; text-align: left; }
strong.ph { color: #8A4A24; background: #F7E7DC; padding: 0 2px; border-radius: 2px; font-weight: 600; }
hr { display: none; }
figure.diagram { margin: 10pt 0 14pt; text-align: center; break-inside: avoid; }
figure.diagram img { max-width: 100%; max-height: 165mm; }
figcaption { font-size: 8.4pt; color: var(--muted); margin-top: 4pt; font-style: italic; }
/* cover */
.cover { height: 297mm; width: 210mm; background: var(--ink); color: var(--paper); position: relative; overflow: hidden; break-after: page; }
.cover .band { position: absolute; left: 0; right: 0; top: 0; height: 6mm; background: linear-gradient(90deg, var(--copper) 0 50%, var(--nickel) 50% 100%); }
.cover .inner { padding: 38mm 22mm 0 22mm; }
.tiles { display: flex; gap: 7mm; margin-bottom: 22mm; }
.tile { width: 34mm; height: 34mm; border: 1.4pt solid; padding: 3mm; box-sizing: border-box; position: relative; font-family: "IBM Plex Mono", monospace; }
.tile .n { position: absolute; top: 3mm; left: 3.5mm; font-size: 9pt; }
.tile .s { position: absolute; left: 3.5mm; bottom: 9mm; font-family: Fraunces, Georgia, serif; font-size: 34pt; font-weight: 600; }
.tile .l { position: absolute; left: 3.5mm; bottom: 3mm; font-size: 6.6pt; letter-spacing: .06em; text-transform: uppercase; }
.tile.cu { border-color: var(--copper); color: var(--copper-l); }
.tile.ni { border-color: var(--nickel); color: var(--nickel-l); }
.cover h1 { font-size: 40pt; color: var(--paper); margin: 0 0 6mm; letter-spacing: -0.01em; }
.cover .sub { font-family: Fraunces, Georgia, serif; font-size: 16pt; color: var(--copper-l); margin-bottom: 4mm; }
.cover .meta { font-size: 9.5pt; color: var(--nickel-l); line-height: 1.7; margin-top: 10mm; font-family: "IBM Plex Mono", monospace; }
.cover .status { display: inline-block; margin-top: 6mm; padding: 2mm 4mm; border: 1pt solid #E0A43A; color: #E0A43A; font-size: 8.5pt; letter-spacing: .08em; text-transform: uppercase; font-family: "IBM Plex Mono", monospace; }
.cover .disc { position: absolute; left: 22mm; right: 22mm; bottom: 18mm; font-size: 7.8pt; line-height: 1.5; color: #C7CDD3; border-top: 0.6pt solid var(--line); padding-top: 4mm; }
.cover .disc b { color: var(--paper); }
"""


def cover() -> str:
    return f"""
<section class="cover">
  <div class="band"></div>
  <div class="inner">
    <div class="tiles">
      <div class="tile cu"><span class="n">29</span><span class="s">Cu</span><span class="l">Copper powder</span></div>
      <div class="tile ni"><span class="n">28</span><span class="s">Ni</span><span class="l">Nickel wire</span></div>
    </div>
    <h1>ReserveChain</h1>
    <div class="sub">Industrial-Metals Reserve Registry<br/>and Tokenization Framework</div>
    <div class="status">Institutional whitepaper · Discussion draft · In development</div>
    <div class="meta">Version: Draft 0.9 — subject to legal review and final approval<br/>
      Date of this version: [To be inserted on approval by ReserveChain]<br/>reservechain.io</div>
  </div>
  <div class="disc"><b>Mandatory disclosure.</b> {html.escape(DISCLOSURE)}<br/><br/><b>EU/EEA notice.</b> {html.escape(EU_NOTICE)}</div>
</section>"""


def main() -> None:
    doc = f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"/><title>ReserveChain Whitepaper</title>
<style>{CSS}</style></head>
<body><main>{render()}</main></body></html>"""
    (OUT / "whitepaper.html").write_text(doc, encoding="utf-8")
    cover_doc = f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"/><title>ReserveChain Whitepaper — Cover</title>
<style>{CSS} @page {{ margin: 0; }}</style></head><body>{cover()}</body></html>"""
    (OUT / "cover.html").write_text(cover_doc, encoding="utf-8")
    print("wrote", OUT / "whitepaper.html", "and cover.html")


if __name__ == "__main__":
    main()
