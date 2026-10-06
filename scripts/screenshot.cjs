// Usage: node scripts/screenshot.cjs <out_dir> <url_path>[,<url_path>...] [width] [full=1]
// Captures pages from the local stack with the system Chrome (playwright-core).
const path = require('path');
const { chromium } = require(path.join(__dirname, '../docs/whitepaper/build/node_modules/playwright-core'));
(async () => {
  const [out, paths, width = '1440', full = '1'] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  const page = await browser.newPage({ viewport: { width: +width, height: 900 }, deviceScaleFactor: 1 });
  for (const p of paths.split(',')) {
    await page.goto('http://localhost:8088' + p, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.querySelectorAll('[data-reveal]').forEach(e => e.classList.add('is-in')));
    await page.waitForTimeout(400);
    const name = (p.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'home') + '-' + width + '.png';
    await page.screenshot({ path: path.join(out, name), fullPage: full === '1' });
    console.log('saved', name);
  }
  await browser.close();
})();
