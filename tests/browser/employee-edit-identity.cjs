'use strict';

// Production employee UI/CSS with synthetic photographs and isolated HTTP responses.
const { chromium } = require(process.env.DESKTOP_TEST_BROWSER_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const css = fs.readFileSync(path.join(root, 'public/dashboard/css/branch-operations.css'), 'utf8');
const script = fs.readFileSync(path.join(root, 'public/dashboard/js/branch-operations.js'), 'utf8');
const origin = 'http://employee.test';
const photo = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP9sAAAAASUVORK5CYII=', 'base64');
const regulation = { version: '2026-10-23', sha256: 'a'.repeat(64), acceptance: 'موافق على لائحة الاختبار',
  sections: [{ title: 'لائحة الاختبار', items: ['نص صناعي للاختبار فقط'] }] };

function fixtureData(manage) {
  const rule = { starts_at: '10:00', ends_at: '18:00', late_half_hour: '10.00', early_half_hour: '5.00', absence: '300.00', revision: 1 };
  const employee = { id: 7, branch: 'f:100', name: 'موظف الاختبار', phone: '', wallet_phone: '', job_title: 'كاشير', shift: 'صباحي',
    hired_on: '2026-10-01', left_on: null, notes: 'ملاحظة صناعية', revision: 1, active: true, attendance: null, day_closed: false,
    identity: { front: true, back: true }, regulation: { accepted: false, current: false, version: null, accepted_at: null },
    daily: { deduction: { count: 0, amount: '0.00' }, bonus: { count: 0, amount: '0.00' }, advance: { count: 0, amount: '0.00' } },
    statement: { net: '800.00', salary: '3100.00', status: 'draft', month: '2026-10', eligible_days: 8 } };
  return { success: true, can_manage_attendance: false, can_manage_employees: manage, can_award_bonuses: false,
    branches: [{ value: 'f:100', name: 'فرع الاختبار' }], attendance_rules: { 'f:100': { morning: rule, evening: rule } },
    options: { job_title: ['كاشير'], shift: ['صباحي'] }, filters: { branch: 'f:100', day: '2026-10-08', month: '2026-10' },
    pagination: { page: 1, last_page: 1, total: 1 }, summary: { employees: 1, net: '800.00' }, items: [employee] };
}

function html(data, online) {
  const urls = Object.fromEntries(['data', 'save', 'attendance', 'attendance-rules', 'entry', 'wallet', 'daily-notes', 'void-entry',
    'statement', 'entries', 'close', 'pay', 'export', 'recover', 'orders', 'identity-image', 'regulation'].map(key => [key, origin + '/' + key]));
  if (online) urls.identity = origin + '/identity';
  const boot = { module: 'employees', branches: data.branches, initial: data, today: '2026-10-08', actor_id: 1, regulation, urls };
  return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="synthetic-csrf">'
    + '<style>' + css + '</style></head><body><main id="branch-operations" class="op-workspace op-employees">'
    + '<button data-op-add>إضافة موظف</button><div data-op-message hidden></div><button data-op-retry hidden></button>'
    + '<select data-op-filter="branch"><option value="f:100">فرع الاختبار</option></select><input data-op-filter="search">'
    + '<select data-op-filter="shift"></select><select data-op-filter="job_title"></select>'
    + '<input data-op-filter="day" type="date" value="2026-10-08"><input data-op-filter="month" type="month" value="2026-10">'
    + '<button data-op-refresh>تحديث</button><section data-op-cards></section><div data-op-table></div>'
    + '<button data-op-prev>السابق</button><span data-op-pages></span><button data-op-next>التالي</button>'
    + '<dialog data-op-dialog><header><h2 data-op-title></h2><button data-op-close>×</button></header><div data-op-body></div></dialog>'
    + '</main><script id="branch-operations-bootstrap" type="application/json">' + JSON.stringify(boot) + '</script></body></html>';
}

async function scenario(browser, options = {}) {
  const manage = options.manage !== false, data = fixtureData(manage), events = [], errors = [];
  const page = await browser.newPage({ viewport: { width: 1366, height: 950 } });
  page.setDefaultTimeout(5000);
  page.on('pageerror', error => errors.push(error.message));
  let releaseUpload, observedUpload;
  const uploadSeen = new Promise(resolve => { observedUpload = resolve; });
  const uploadGate = new Promise(resolve => { releaseUpload = resolve; });
  await page.route(origin + '/**', async route => {
    const request = route.request(), endpoint = new URL(request.url()).pathname;
    if (request.resourceType() === 'document') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: html(data, options.online !== false) });
    if (endpoint === '/identity-image') return route.fulfill({ contentType: 'image/png', body: photo });
    if (request.method() === 'GET') return route.fulfill({ json: data });
    if (endpoint === '/save') {
      const payload = request.postDataJSON();events.push({ endpoint, payload });
      assert.equal(request.headers()['x-csrf-token'], 'synthetic-csrf');
      const savedId = payload.employee_id || 37;
      data.items[0].id = savedId;data.items[0].name = payload.name;data.items[0].revision++;
      if (Object.hasOwn(payload, 'regulation_accepted')) data.items[0].regulation.current = payload.regulation_accepted;
      return route.fulfill({ json: { success: true, employee: { id: savedId } } });
    }
    if (endpoint === '/identity') {
      const multipart = request.postDataBuffer().toString('latin1');
      const value = key => multipart.match(new RegExp('name="' + key + '"\\r\\n\\r\\n([^\\r]+)'))?.[1];
      events.push({ endpoint, employeeId: value('employee_id'), side: value('side'), csrf: request.headers()['x-csrf-token'] });
      if (options.holdFirst && events.filter(event => event.endpoint === '/identity').length === 1) {
        observedUpload();await uploadGate;
      }
      if (options.failUpload) return route.fulfill({ status: 503, json: { success: false, message: 'تعذّر رفع الصورة الصناعية.' } });
      return route.fulfill({ json: { success: true, identity: { front: true, back: true } } });
    }
    throw Error('Unexpected write: ' + endpoint);
  });
  await page.goto(origin + '/');await page.addScriptTag({ content: script });
  const dialog = page.locator('[data-op-dialog]');
  return { page, data, dialog, events, errors, uploadSeen, releaseUpload,
    open: async () => { await page.locator('.op-identity-badge').click();await dialog.waitFor({ state: 'visible' }); },
    choose: async side => { await dialog.locator('.op-identity-side').nth(side === 'front' ? 0 : 1).locator('input[type="file"]').setInputFiles({ name: 'synthetic-' + side + '.png', mimeType: 'image/png', buffer: photo }); },
    save: async () => { await dialog.locator('button[type="submit"]').click(); },
    finish: async () => { assert.deepEqual(errors, []);await page.close(); } };
}

(async () => {
  const browser = await chromium.launch({ headless: true,
    ...(process.env.DESKTOP_TEST_BROWSER_EXECUTABLE ? { executablePath: process.env.DESKTOP_TEST_BROWSER_EXECUTABLE } : {}) });
  try {
    const owner = await scenario(browser, { holdFirst: true });
    await owner.open();
    assert.equal(await owner.dialog.locator('.op-identity-field img').count(), 2);
    await owner.page.waitForFunction(() => [...document.querySelectorAll('.op-identity-field img')].every(image => image.complete && image.naturalWidth > 0));
    assert.equal(await owner.dialog.locator('.op-regulation-field input[type="checkbox"]').count(), 1);
    for (const selector of ['.op-identity-field', '.op-regulation-field']) {
      assert.equal(await owner.dialog.locator(selector).evaluate(element => getComputedStyle(element).gridColumn), '1 / -1');
    }
    assert.ok(await owner.dialog.locator('.op-identity-field').evaluate(element => element.getBoundingClientRect().top >= document.querySelector('[name="notes"]').parentElement.getBoundingClientRect().bottom));
    await owner.choose('front');await owner.choose('back');
    assert.equal(owner.events.length, 0, 'Selecting photographs previews them without uploading before Save.');
    assert.equal(await owner.dialog.locator('.op-identity-preview img[src^="data:"]').count(), 2);
    await owner.dialog.locator('.op-regulation-field input').check();
    await owner.save();await owner.uploadSeen;
    assert.equal(owner.events[0].endpoint, '/save');
    assert.equal(owner.events[0].payload.regulation_version, regulation.version);
    assert.equal(owner.events[0].payload.regulation_hash, regulation.sha256);
    assert.equal(owner.events[0].payload.regulation_accepted, true);
    assert.equal(owner.events.length, 2, 'Back photograph waits for the front response.');
    assert.equal(await owner.page.locator('[data-op-close]').isDisabled(), true, 'The pending photograph keeps the employee dialog locked.');
    assert.equal(await owner.page.locator('[data-op-add]').isDisabled(), true);
    await owner.page.locator('[data-op-close]').dispatchEvent('click');
    await owner.dialog.evaluate(element => element.dispatchEvent(new Event('cancel', { cancelable: true })));
    assert.equal(await owner.dialog.evaluate(element => element.open), true, 'Pending upload cannot close its original dialog.');
    owner.releaseUpload();await owner.dialog.waitFor({ state: 'hidden' });
    assert.deepEqual(owner.events.slice(1).map(event => [event.employeeId, event.side, event.csrf]), [
      ['7', 'front', 'synthetic-csrf'], ['7', 'back', 'synthetic-csrf']]);
    assert.match(await owner.page.locator('[data-op-message]').innerText(), /تم الحفظ ورفع صور البطاقة/);
    await owner.finish();

    const created = await scenario(browser);
    await created.page.locator('[data-op-add]').click();await created.dialog.waitFor({ state: 'visible' });
    await created.dialog.locator('[name="name"]').fill('موظف جديد للاختبار');
    await created.dialog.locator('[name="job_title"]').fill('كاشير');
    await created.choose('front');await created.save();await created.dialog.waitFor({ state: 'hidden' });
    assert.equal(Object.hasOwn(created.events[0].payload, 'employee_id'), false);
    assert.deepEqual(created.events.filter(event => event.endpoint === '/identity').map(event => event.employeeId), ['37']);
    await created.finish();

    const failed = await scenario(browser, { failUpload: true });
    await failed.open();await failed.choose('front');await failed.save();await failed.dialog.waitFor({ state: 'hidden' });
    assert.match(await failed.page.locator('[data-op-message]').innerText(), /اتحفظت بيانات الموظف، لكن رفع صورة البطاقة ما كملش/);
    assert.equal(await failed.page.locator('[data-op-message]').evaluate(element => element.classList.contains('is-success')), false);
    assert.equal(failed.events.filter(event => event.endpoint === '/save').length, 1);
    await failed.finish();

    const branch = await scenario(browser, { manage: false });
    await branch.open();assert.equal(await branch.dialog.locator('img').count(), 2);
    assert.equal(await branch.dialog.locator('input[type="file"],form').count(), 0);
    assert.equal(await branch.page.getByRole('button', { name: 'تعديل الموظف', exact: true }).count(), 0);
    await branch.finish();

    const device = await scenario(browser, { online: false });
    await device.open();assert.equal(await device.dialog.locator('.op-identity-field img').count(), 2);
    assert.equal(await device.dialog.locator('input[type="file"]').count(), 0);
    assert.match(await device.dialog.innerText(), /من المتصفح/);
    await device.finish();
    console.log('Employee edit photographs: preview, save-before-upload, consent binding, upload lock/failure, new ID, branch read-only and device display passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
