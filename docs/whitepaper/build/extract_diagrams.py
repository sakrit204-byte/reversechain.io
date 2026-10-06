"""Extract every ```mermaid block preceded by <!-- diagram: NAME --> from whitepaper.md into ../diagrams/NAME.mmd.

The Markdown file is the single source; .mmd files are regenerated on every build so they never drift.
"""
import pathlib, re, sys

HERE = pathlib.Path(__file__).resolve().parent
WP = HERE.parent / "whitepaper.md"
OUT = HERE.parent / "diagrams"
OUT.mkdir(exist_ok=True)

PATTERN = re.compile(r"<!--\s*diagram:\s*([\w\-]+)\s*-->\s*```mermaid\n(.*?)```", re.S)

def main() -> int:
    text = WP.read_text(encoding="utf-8")
    found = PATTERN.findall(text)
    if not found:
        print("no diagrams found", file=sys.stderr)
        return 1
    for name, body in found:
        (OUT / f"{name}.mmd").write_text(body.strip() + "\n", encoding="utf-8")
        print(f"wrote diagrams/{name}.mmd")
    return 0

if __name__ == "__main__":
    sys.exit(main())
