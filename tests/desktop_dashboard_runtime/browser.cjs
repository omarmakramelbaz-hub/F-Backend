'use strict';
// Optional real browser checks on the disposable original application, with external requests blocked.
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.DESKTOP_TEST_BROWSER_MODULE);
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    await context.routeWebSocket('**/*', socket => socket.close());
    const externalStatic = new Set();
    const missingAssets = new Set();
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin !== input.origin) {
        if (['script', 'stylesheet', 'font'].includes(route.request().resourceType())) externalStatic.add(url.href);
        return route.abort();
      }
      return route.continue({ headers: { ...route.request().headers(), 'X-Fasakhansta-Desktop': input.token } });
    });
    const page = await context.newPage();
    page.on('response', response => {
      if (response.url().includes('/dashboard/vendor/desktop-external/') && response.status() !== 200) missingAssets.add(response.url());
    });
    await page.goto(input.origin + '/admin/login');
    const fonts = await page.evaluate(async () => {
      const faces = await document.fonts.load('400 16px "Almarai"', 'فسخانستا');
      return faces.map(face => ({ family: face.family, status: face.status }));
    });
    assert.ok(fonts.length > 0 && fonts.every(face => face.family.replace(/["']/g, '') === 'Almarai' && face.status === 'loaded'));
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
    assert.ok(await page.evaluate(async () => {
      const icons = await document.fonts.load('900 16px "Font Awesome 6 Free"', '\uf007');
      return icons.length > 0 && icons.every(face => face.status === 'loaded');
    }), 'Original Font Awesome 6 stylesheet and font must pass the unchanged integrity check.');
    assert.equal(await page.evaluate(() => CKEDITOR.version), '4.14.0');
    await page.evaluate(() => new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(Error('Original local editor did not become ready.')), 15000);
      const field = document.createElement('textarea');
      field.id = 'desktop-browser-editor-probe';
      document.querySelector('form input[name="_desktop_command"]').form.append(field);
      const editor = CKEDITOR.replace(field, { language: 'ar' });
      editor.on('instanceReady', () => { clearTimeout(timeout); editor.destroy(); field.remove(); resolve(); });
    }));
    await page.goto(input.origin + '/admin/categorys');
    assert.equal(await page.evaluate(() => typeof window.jQuery.fn.DataTable), 'function');
    assert.deepEqual([...externalStatic], [], 'Original layout and editor must not request external scripts, styles or fonts.');
    assert.deepEqual([...missingAssets], [], 'Bundled layout dependencies must load through the private HTTP gateway.');
    process.stdout.write('PASS original Arabic font, layout dependencies and Arabic editor load locally with external requests blocked\n');
  } finally { await browser.close(); }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
