'use strict';

// Real production navigation/CSS with a long synthetic server-rendered menu.
const { chromium } = require(process.env.DESKTOP_TEST_BROWSER_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const read = file => fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');
const script = read('public/dashboard/js/dashboard-navigation.js');
const css = read('public/dashboard/branding/dashboard-brand.css');

function menu(mode) {
  const routes = ['dashboard', 'whatsapp', 'meta', 'applies-orders', 'takeaway', 'phone-orders', 'dining',
    'branch-expenses', 'branch-stock', 'branch-shifts', 'employees', 'branch-orders', 'delivery-companies',
    'customers', 'print-settings', 'go-stores', 'categorys', 'products', 'roles', 'contacts', 'settings'];
  // The real Blade puts this link near the top before navigation applies ordering.
  if (mode !== 'absent') routes.splice(1, 0, mode);
  return routes.map(route => '<li class="nav-item"><a class="nav-link" href="/admin/' + route + '"><p>'
    + (route === 'desktop-dashboard' ? 'برنامج الكمبيوتر' : route) + '</p></a></li>').join('');
}

(async () => {
  const browser = await chromium.launch({ headless: true,
    ...(process.env.DESKTOP_TEST_BROWSER_EXECUTABLE ? { executablePath: process.env.DESKTOP_TEST_BROWSER_EXECUTABLE } : {}) });
  try {
    for (const mode of ['desktop-dashboard', 'desktop-pos', 'absent']) {
      const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', route => route.request().resourceType() === 'document'
        ? route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<html dir="rtl"><head><meta charset="utf-8"></head><body class="dashboard-theme">'
          + '<aside id="dashboard-sidebar" class="main-sidebar"><div class="sidebar"><nav>'
          + '<ul class="nav nav-sidebar" data-dashboard-navigation>' + menu(mode) + '</ul>'
          + '</nav></div></aside></body></html>' }) : route.abort());
      await page.goto('http://fixture.test/admin/categorys');
      await page.addStyleTag({ content: css + '\n#dashboard-sidebar{position:fixed;top:0;right:0;bottom:0;width:250px}.sidebar{height:100%}a{display:block}' });
      await page.addScriptTag({ content: script });
      await page.waitForFunction(() => document.querySelector('[data-dashboard-navigation]').dataset.grouped === 'true');
      assert.ok(await page.locator('.dashboard-navigation-group').count() > 0, 'The fixture must exercise real menu grouping.');
      if (mode === 'desktop-dashboard') {
        const link = page.getByRole('link', { name: 'برنامج الكمبيوتر', exact: true });
        assert.equal(await link.isVisible(), true, 'The real modern program shortcut must not be hidden in a grouped submenu.');
        assert.equal(await link.evaluate(node => node.parentElement.parentElement.hasAttribute('data-dashboard-navigation')), true);
        assert.equal(await link.evaluate(node => new URL(node.parentElement.previousElementSibling.querySelector('a').href).pathname), '/admin/print-settings');
        await Promise.all([page.waitForURL('**/admin/desktop-dashboard'), link.click()]);
      } else if (mode === 'desktop-pos') {
        const link = page.locator('[data-dashboard-navigation] > li > a[href="/admin/desktop-pos"]');
        assert.equal(await link.isVisible(), true, 'The legacy POS shortcut keeps its existing root access.');
        assert.equal(await link.evaluate(node => new URL(node.parentElement.nextElementSibling.querySelector('a').href).pathname), '/admin/print-settings');
      } else {
        assert.equal(await page.locator('a[href="/admin/desktop-dashboard"]').count(), 0, 'Client ordering never manufactures a program permission.');
      }
      assert.deepEqual(errors, []);
      await page.close();
    }
    console.log('Modern program stays visible after print settings in grouped navigation; legacy access and missing permissions preserved.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
