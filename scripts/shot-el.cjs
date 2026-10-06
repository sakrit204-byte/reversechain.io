// Screenshot a page region: node scripts/shot-el.cjs <out.png> <path> <css selector> [width]
const path = require('path');
const { chromium } = require(path.join(__dirname, '../docs/whitepaper/build/node_modules/playwright-core'));
(async () => {
  const [out, p, sel, w = '1440'] = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  const pg = await b.newPage({ viewport: { width: +w, height: 1000 } });
  await pg.goto('http://localhost:8088' + p, { waitUntil: 'networkidle' });
  await pg.evaluate(() => document.querySelectorAll('[data-reveal]').forEach(e => e.classList.add('is-in')));
  await pg.waitForTimeout(1500);
  const el = await pg.$(sel); await el.scrollIntoViewIfNeeded(); await pg.waitForTimeout(500);
  await el.screenshot({ path: out }); console.log('saved', out);
  await b.close();
})();
