// Render-only fixtures exported by FoundationTest; no connection to production.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.ERP_PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const folder = process.env.ERP_RENDER_DIR;
  assert(folder, 'ERP_RENDER_DIR must point to test-generated HTML');
  const publicRoot = path.resolve(__dirname, '../../public');
  const browser = await chromium.launch({ executablePath: process.env.ERP_CHROME || '/usr/bin/google-chrome', headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1050 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.hostname !== 'localhost') return route.abort();
    const pathname = decodeURIComponent(url.pathname);
    const root = pathname.startsWith('/erp/') || pathname.startsWith('/dashboard/') ? publicRoot : folder;
    const file = path.resolve(root, '.' + pathname);
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) return route.fulfill({ status: 404, body: 'Fixture not found' });
    const extension = path.extname(file);
    return route.fulfill({ path: file, contentType: ({ '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png' })[extension] || 'text/html' });
  });
  for (const screen of ['home', 'branches', 'inventory', 'employees', 'payroll', 'orders', 'accounts', 'audit', 'purchases', 'production', 'finance', 'trial']) {
    await page.goto(`http://localhost/${screen}.html`);
    assert.equal(await page.locator('main').count(), 1, screen);
    assert.equal(await page.locator('h1').count(), 1, screen);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${screen}: desktop page overflow`);
    await page.screenshot({ path: path.join(folder, `${screen}-desktop.png`), fullPage: true });
  }
  await page.goto('http://localhost/accounts.html');
  const role = page.locator('[data-role-picker]').first();
  const accountForm = page.locator('form').filter({ has: role }).first();
  await role.selectOption('branch_manager');
  assert(await accountForm.locator('[name="branch_id"]').isEnabled());
  assert(await accountForm.locator('[value="payroll.manage"]').isDisabled());
  assert(await accountForm.locator('[value="branches.manage"]').isDisabled());
  await role.selectOption('deputy_manager');
  assert(await accountForm.locator('[name="branch_id"]').isDisabled());
  assert(await accountForm.locator('[value="inventory.manage"]').isChecked());
  assert(await accountForm.locator('[value="employees.manage"]').isChecked());
  await page.goto('http://localhost/inventory.html');
  const stockForm = page.locator('[data-stock-form]');
  await stockForm.locator('[name="type"]').selectOption('transfer');
  assert(await stockForm.locator('[name="destination_id"]').isVisible());
  assert(await stockForm.locator('[name="unit_cost"]').isDisabled());
  await stockForm.locator('[name="type"]').selectOption('count');
  assert(await stockForm.locator('[name="expected_quantity"]').isVisible());
  await page.goto('http://localhost/purchases.html');
  const editor = page.locator('[data-line-editor]');
  await editor.locator('[data-line-rows] input[name$="[quantity]"]').fill('1.125');
  await editor.locator('[data-line-rows] input[name$="[unit_cost]"]').fill('12.34');
  assert.equal(await editor.locator('[data-line-total]').innerText(), '13.88 ج.م');
  await editor.locator('[data-add-line]').click();
  assert.equal(await editor.locator('[data-line-rows] [data-line-row]').count(), 2);
  await editor.locator('[data-line-rows] [data-remove-line]').last().click();
  assert.equal(await editor.locator('[data-line-rows] [data-line-row]').count(), 1);
  await page.goto('http://localhost/finance.html');
  await page.locator('details.card').first().locator('summary').click();
  const cashForm = page.locator('[data-cash-form]');
  await cashForm.locator('[name="type"]').selectOption('supplier_payment');
  assert(await cashForm.locator('[name="supplier_id"]').isVisible());
  assert(await cashForm.locator('[name="branch_id"]').isDisabled());
  await page.setViewportSize({ width: 390, height: 844 });
  for (const screen of ['home', 'inventory', 'employees', 'payroll', 'accounts', 'orders', 'purchases', 'production', 'finance', 'trial']) {
    await page.goto(`http://localhost/${screen}.html`);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${screen}: mobile page overflow`);
    await page.screenshot({ path: path.join(folder, `${screen}-mobile.png`), fullPage: true });
  }
  assert.deepEqual(errors, [], 'No JavaScript errors');
  await browser.close();
  console.log('12 desktop screens, 10 mobile screens, role permissions and operational form controls passed.');
})().catch(error => { console.error(error); process.exit(1); });
