// Render ../diagrams/*.mmd to SVG (for HTML/PDF) and PNG (for DOCX) using @mermaid-js/mermaid-cli.
// Uses the locally installed Chrome (see puppeteer-config.json) so no browser download is needed.
import { execFileSync } from "node:child_process";
import { readdirSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const dir = join(here, "..", "diagrams");
const mmdc = join(here, "node_modules", ".bin", process.platform === "win32" ? "mmdc.cmd" : "mmdc");
const common = ["-c", join(here, "mermaid-config.json"), "-p", join(here, "puppeteer-config.json"), "-b", "white"];

for (const f of readdirSync(dir).filter((x) => x.endsWith(".mmd"))) {
  const src = join(dir, f);
  const base = src.replace(/\.mmd$/, "");
  for (const [ext, extra] of [["svg", []], ["png", ["-s", "3", "-w", "1400"]]]) {
    execFileSync(mmdc, ["-i", src, "-o", `${base}.${ext}`, ...common, ...extra], { stdio: "inherit", shell: process.platform === "win32" });
  }
  console.log("rendered", f);
}
