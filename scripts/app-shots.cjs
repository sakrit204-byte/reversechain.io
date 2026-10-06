// Captures the Expo web build of the iOS/Android app (served on :8099) at phone size.
const path = require('path');
const { chromium } = require(path.join(__dirname, '../docs/whitepaper/build/node_modules/playwright-core'));
(async () => {
  const out = process.argv[2], steps = JSON.parse(process.argv[3]);
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error') console.log('console:', m.text().slice(0, 160)); });
  for (const s of steps) {
    if (s.goto) await p.goto('http://localhost:8099' + s.goto, { waitUntil: 'networkidle' });
    if (s.click) { await p.getByText(s.click, { exact: false }).first().click(); }
    if (s.back) { await p.goBack(); await p.waitForTimeout(1500); }
    if (s.input) { await p.locator('input').nth(s.input[0]).fill(s.input[1]); }
    if (s.clickLast) { await p.getByText(s.clickLast, { exact: true }).last().click(); }
    if (s.fill) { await p.getByPlaceholder(s.fill[0]).or(p.getByLabel(s.fill[0])).first().fill(s.fill[1]); }
    if (s.wait) await p.waitForTimeout(s.wait);
    if (s.shot) { await p.waitForTimeout(800); await p.screenshot({ path: path.join(out, 'app-' + s.shot + '.png') }); console.log('shot', s.shot, p.url()); }
    if (s.text) { console.log((await p.innerText('body')).slice(0, 600).replace(/\n+/g, ' | ')); }
  }
  await b.close();
})();
