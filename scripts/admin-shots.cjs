// Logs into wp-admin and captures CMS screens. Usage: node scripts/admin-shots.cjs <out_dir> <user> <pass>
const path = require('path');
const { chromium } = require(path.join(__dirname, '../docs/whitepaper/build/node_modules/playwright-core'));
(async () => {
  const [out, user, pass] = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 } });
  await p.goto('http://localhost:8088/wp-login.php');
  await p.fill('#user_login', user); await p.fill('#user_pass', pass);
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  const pages = {
    'admin-control-centre': '/wp-admin/admin.php?page=reservechain',
    'admin-review-queue': '/wp-admin/admin.php?page=rc-review',
    'admin-audit-trail': '/wp-admin/admin.php?page=rc-audit',
    'admin-settings-modules': '/wp-admin/admin.php?page=rc-settings',
    'admin-waitlist': '/wp-admin/admin.php?page=rc-waitlist',
    'admin-health': '/wp-admin/admin.php?page=rc-health',
    'admin-lots': '/wp-admin/edit.php?post_type=rc_lot',
    'admin-por': '/wp-admin/admin.php?page=rc-por',
  };
  for (const [name, url] of Object.entries(pages)) {
    await p.goto('http://localhost:8088' + url, { waitUntil: 'networkidle' });
    await p.screenshot({ path: path.join(out, name + '.png'), fullPage: false });
    console.log('saved', name);
  }
  // A CoA record edit screen.
  await p.goto('http://localhost:8088/wp-admin/edit.php?post_type=rc_coa', { waitUntil: 'networkidle' });
  const link = await p.$('a.row-title');
  if (link) { await Promise.all([p.waitForNavigation(), link.click()]); await p.screenshot({ path: path.join(out, 'admin-coa-edit.png') }); console.log('saved admin-coa-edit'); }
  await b.close();
})();
