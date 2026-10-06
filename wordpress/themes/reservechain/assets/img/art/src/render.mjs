// Re-render the ReserveChain concept renders.
// Usage (from a dir where `three`, `sharp` are installed):
//   node render.mjs <scene|all> [--scale 1] [--out <dir>]
// Env: THREE_DIR (path to node_modules/three), PLAYWRIGHT_CORE (path), CHROME (path), ART_OUT (webp output dir)
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(path.join(process.cwd(), 'x.js'));
const THREE_DIR = process.env.THREE_DIR || path.join(process.cwd(), 'node_modules/three');
const PW = process.env.PLAYWRIGHT_CORE || 'C:/Users/ACER/Desktop/reservechain.io/docs/whitepaper/build/node_modules/playwright-core';
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const OUT = process.env.ART_OUT || path.resolve(HERE, '..');
const RAW = process.env.RAW_OUT || path.join(process.cwd(), 'raw');

export const SCENES = {
  'copper-powder': [2400, 1600],
  'nickel-wire': [2400, 1600],
  'ampoules': [2400, 1600],
  'assay-lab': [2400, 1600],
  'vault': [2400, 1600],
  'emblem': [2400, 2000],
  'ledger': [2400, 1200],
};

const MIME = { '.js': 'text/javascript', '.mjs': 'text/javascript', '.html': 'text/html', '.json': 'application/json', '.png': 'image/png' };
function serve() {
  return new Promise(res => {
    const srv = http.createServer((req, rsp) => {
      const u = decodeURIComponent(new URL(req.url, 'http://x').pathname);
      let f = u.startsWith('/three/') ? path.join(THREE_DIR, u.slice(7)) : path.join(HERE, u);
      if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { rsp.writeHead(404); return rsp.end('nf'); }
      rsp.writeHead(200, { 'content-type': MIME[path.extname(f)] || 'application/octet-stream', 'cache-control': 'no-store' });
      fs.createReadStream(f).pipe(rsp);
    }).listen(0, '127.0.0.1', () => res(srv));
  });
}

const args = process.argv.slice(2);
const which = args[0] || 'all';
const scale = +(args[args.indexOf('--scale') + 1] || 1) || 1;
const noExport = args.includes('--raw-only');
const names = which === 'all' ? Object.keys(SCENES) : which.split(',');

const srv = await serve();
const port = srv.address().port;
const { chromium } = await import(pathToFileURL(path.join(PW, 'index.mjs')).href);
const browser = await chromium.launch({ executablePath: CHROME, headless: true,
  args: ['--use-angle=d3d11', '--enable-gpu', '--ignore-gpu-blocklist', '--enable-unsafe-swiftshader'] });
fs.mkdirSync(RAW, { recursive: true });
const sharp = noExport ? null : require('sharp');
for (const name of names) {
  const [w, h] = SCENES[name];
  const W = Math.round(w * scale), H = Math.round(h * scale);
  const page = await browser.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: 1 });
  page.on('console', m => console.log(`[${name}]`, m.text()));
  page.on('pageerror', e => console.log(`[${name}] ERROR`, e.message));
  const t0 = Date.now();
  await page.goto(`http://127.0.0.1:${port}/${name}.html?w=${W}&h=${H}`);
  await page.waitForFunction('window.__done === true', null, { timeout: 600000 });
  const dataUrl = await page.evaluate(() => document.querySelector('canvas').toDataURL('image/png'));
  const png = Buffer.from(dataUrl.split(',')[1], 'base64');
  const rawFile = path.join(RAW, `${name}.png`);
  fs.writeFileSync(rawFile, png);
  console.log(`${name}: rendered ${W}x${H} in ${((Date.now() - t0) / 1000).toFixed(1)}s`);
  await page.close();
  if (sharp) {
    for (const tw of [2400, 1200, 600]) {
      let q = tw === 600 ? 78 : 82;
      const file = path.join(OUT, `${name}-${tw}.webp`);
      for (;;) {
        await sharp(png).resize({ width: tw, kernel: 'lanczos3' }).webp({ quality: q, effort: 6, smartSubsample: true }).toFile(file);
        const kb = fs.statSync(file).size / 1024;
        if (tw !== 2400 || kb <= 440 || q <= 60) { console.log(`  ${path.basename(file)} q${q} ${kb.toFixed(0)} KB`); break; }
        q -= 4;
      }
    }
  }
}
await browser.close();
srv.close();
