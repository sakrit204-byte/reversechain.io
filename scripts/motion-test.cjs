// Scrolls a page like a user and captures viewport frames; reports JS errors.
const path = require('path');
const { chromium } = require(path.join(__dirname, '../docs/whitepaper/build/node_modules/playwright-core'));
(async () => {
  const [out, url, stops] = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  const p = await b.newPage({ viewport: { width: 1440, height: 900 } });
  const errs = [];
  p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
  await p.goto('http://localhost:8088' + url, { waitUntil: 'networkidle' });
  await p.waitForTimeout(1600);
  let i = 0;
  for (const y of stops.split(',').map(Number)) {
    await p.mouse.move(900, 400);
    await p.evaluate(t => window.scrollTo(0, t), y);
    await p.waitForTimeout(1400);
    await p.screenshot({ path: path.join(out, `motion-${i++}.png`) });
  }
  console.log('classes:', await p.evaluate(() => document.documentElement.className));
  console.log('errors:', errs.length ? errs : 'none');
  await b.close();
})();
