'use strict';
// Optional real browser checks on the disposable original application, with external requests blocked.
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.DESKTOP_TEST_BROWSER_MODULE);
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const context = await browser.newContext();
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin !== input.origin) return route.abort();
      return route.continue({ headers: { ...route.request().headers(), 'X-Fasakhansta-Desktop': input.token } });
    });
    const page = await context.newPage();
    await page.goto(input.origin + '/admin/login');
    await page.locator('#dashboard-login-email').fill('owner@test.invalid');
    await page.locator('#dashboard-login-password').fill('Fixture123');
    await Promise.all([page.waitForURL('**/admin/dashboard'), page.locator('.dashboard-login-submit').click()]);
    process.stdout.write('PASS real browser signs in to the original imported dashboard with external requests blocked\n');
    let previous;
    for (const module of ['categorys', 'products']) {
      await page.goto(input.origin + '/admin/' + module + '/create');
      const field = page.locator('form input[name="_desktop_command"]');
      await field.waitFor({ state: 'attached' });
      const uuid = await field.inputValue();
      assert.match(uuid, /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i);
      assert.notEqual(uuid, previous);
      await page.locator('[name="name_ar"]').fill('اختبار النموذج الأصلي');
      await page.evaluate(() => {
        const form = document.querySelector('form input[name="_desktop_command"]').form;
        form.append(document.createElement('span'));
      });
      assert.equal(await field.inputValue(), uuid);
      process.stdout.write('PASS original ' + module + ' form keeps its operation UUID while inputs and DOM change\n');
      previous = uuid;
    }
  } finally { await browser.close(); }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
