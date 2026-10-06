// Reports pages whose document is wider than the viewport at phone width.
const path = require('path');
const { chromium } = require(path.join(__dirname, '../docs/whitepaper/build/node_modules/playwright-core'));
(async () => {
  const urls = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  const p = await b.newPage({ viewport: { width: 390, height: 844 }, isMobile: true });
  for (const u of urls) {
    await p.goto('http://localhost:8088' + u, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(800);
    const r = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, wide: [...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > innerWidth + 2 && getComputedStyle(e).position !== 'fixed').slice(0, 3).map(e => e.tagName + '.' + (e.className.baseVal ?? e.className).toString().split(' ')[0]) }));
    console.log(u, r.sw > r.cw ? 'OVERFLOW ' + r.sw : 'ok', r.sw > r.cw ? r.wide.join(',') : '');
  }
  await b.close();
})();
