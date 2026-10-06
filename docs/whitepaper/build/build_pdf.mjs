// Print build/out/whitepaper.html to ../ReserveChain-Whitepaper.pdf with headless Chrome (playwright-core).
// Uses the locally installed Chrome; override with CHROME_PATH.
import { chromium } from "playwright-core";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const candidates = [
  process.env.CHROME_PATH,
  "C:/Program Files/Google/Chrome/Application/chrome.exe",
  "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
  "/usr/bin/google-chrome",
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
].filter(Boolean);
const executablePath = candidates.find((p) => existsSync(p));

const footer = `
<div style="width:100%;font-family:Arial,sans-serif;font-size:6.5px;color:#5E6B78;padding:0 18mm;display:flex;justify-content:space-between;align-items:flex-end;">
  <span style="max-width:80%;line-height:1.35;">ReserveChain is currently in development. No tokens are being offered or sold. This document is not an offer or offering document.
  EU/EEA: ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA.</span>
  <span style="font-family:Consolas,monospace;font-size:8px;color:#0B0F14;"><span class="pageNumber"></span> / <span class="totalPages"></span></span>
</div>`;
const header = `
<div style="width:100%;font-family:Georgia,serif;font-size:7.5px;color:#C46A3A;padding:0 18mm;display:flex;justify-content:space-between;">
  <span>ReserveChain — Institutional Whitepaper</span><span style="color:#5E6B78;font-family:Arial,sans-serif;">Discussion draft v0.10 · subject to final approval</span>
</div>`;

const browser = await chromium.launch({ executablePath, args: ["--no-sandbox"] });
const page = await browser.newPage();
await page.goto(pathToFileURL(join(here, "out", "whitepaper.html")).href, { waitUntil: "networkidle" });
await page.evaluate(() => document.fonts.ready);
const out = join(here, "..", "ReserveChain-Whitepaper.pdf");
await page.pdf({
  path: join(here, "out", "body.pdf"),
  format: "A4",
  printBackground: true,
  preferCSSPageSize: true,
  displayHeaderFooter: true,
  headerTemplate: header,
  footerTemplate: footer,
});
const cover = await browser.newPage();
await cover.goto(pathToFileURL(join(here, "out", "cover.html")).href, { waitUntil: "networkidle" });
await cover.evaluate(() => document.fonts.ready);
await cover.pdf({ path: join(here, "out", "cover.pdf"), format: "A4", printBackground: true, preferCSSPageSize: true });
await browser.close();

// Merge cover + body (cover has no running header/footer; body pages are numbered from 1).
const { PDFDocument } = await import("pdf-lib");
const merged = await PDFDocument.create();
for (const f of ["cover.pdf", "body.pdf"]) {
  const src = await PDFDocument.load(readFileSync(join(here, "out", f)));
  for (const p of await merged.copyPages(src, src.getPageIndices())) merged.addPage(p);
}
merged.setTitle("ReserveChain — Institutional Whitepaper (Discussion Draft v0.10)");
merged.setAuthor("ReserveChain (in development)");
merged.setSubject("Industrial-metals reserve registry and tokenization framework — not an offering document");
writeFileSync(out, await merged.save());
console.log("wrote", out);
