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
    const pageErrors = [];
    page.on('pageerror', error => { pageErrors.push(error.message); process.stderr.write('BROWSER_SCRIPT_ERROR '+error.stack+'\n'); });
    page.on('response', response => {
      if (new URL(response.url()).origin === input.origin && response.url().includes('/js/desktop-') && response.status() !== 200)
        process.stderr.write('BROWSER_JOURNAL_SCRIPT_RESPONSE '+JSON.stringify({path:new URL(response.url()).pathname,status:response.status()})+'\n');
    });
    const journalField = async (field, label, response) => {
      try {
        if(response) assert.equal(response.status(), 200, label+' must render successfully.');
        await field.waitFor({ state: 'attached' });
      } catch (error) {
        // Synthetic CI pages only. Capture state, never input values or credentials.
        const state = await page.evaluate(() => ({
          path: location.pathname, ready:document.readyState, local:document.body?.dataset.dashboardLocal,
          journalAjax:Boolean(window.jQuery?.fasakhanstaCatalogJournal),
          journalScripts:[...document.scripts].filter(script=>script.src.includes('desktop-dashboard.js')).map(script=>({path:new URL(script.src).pathname,defer:script.defer,type:script.type})),
          forms:[...document.forms].map(form=>({path:new URL(form.action).pathname,
            method:form.querySelector('[name="_method"]')?.value||form.method,
            generation:form.dataset.notificationGeneration,notificationRead:form.hasAttribute('data-desktop-notification-read'),
            journaled:Boolean(form.querySelector('[name="_desktop_command"]'))})),
          text:document.body?.innerText.slice(-1500)
        }));
        process.stderr.write('JOURNAL_FORM_DIAGNOSTIC '+JSON.stringify({label,status:response?.status(),...state,pageErrors})+'\n');
        throw error;
      }
    };
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
    for (const module of ['areas', 'question_answers', 'features', 'contracts', 'categorys', 'products']) {
      await page.goto(input.origin + '/admin/' + module + '/create');
      const field = page.locator('form input[name="_desktop_command"]');
      await journalField(field, module+' create form');
      const uuid = await field.inputValue();
      assert.match(uuid, /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i);
      assert.notEqual(uuid, previous);
      const name = ['areas','features'].includes(module) ? 'title_ar' : module === 'question_answers' ? 'question_ar' : 'name_ar';
      if(module==='contracts')await page.locator('select[name="type"]').selectOption('delegate');
      else await page.locator('[name="' + name + '"]').fill('اختبار النموذج الأصلي');
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
    const contactResponse = await page.goto(input.origin + '/admin/contacts');
    const contactCommand = page.locator('form input[name="_desktop_command"]').first();
    await journalField(contactCommand, 'original contact index', contactResponse);
    const contactUuid = await contactCommand.inputValue();
    assert.match(contactUuid, /^[a-f0-9-]{36}$/i);
    await page.evaluate(() => document.querySelector('form input[name="_desktop_command"]').form.append(document.createElement('span')));
    assert.equal(await contactCommand.inputValue(), contactUuid);
    process.stdout.write('PASS original contact deletion form retains its operation UUID while the DOM changes\n');
    const historyResponse=await page.goto(input.origin + '/admin/notifications');
    const historyField = page.locator('form[data-desktop-notification-read][action$="/read/all/notification"] input[name="_desktop_command"]');
    await journalField(historyField,'original notification history',historyResponse);
    const historyUuid = await historyField.inputValue();
    const historyIds = await page.locator('form[data-desktop-notification-read][action$="/read/all/notification"] input[name="desktop_notification_ids"]').inputValue();
    assert.match(historyUuid, /^[a-f0-9-]{36}$/i);
    assert.match(await historyField.evaluate(field=>field.form.dataset.notificationGeneration), /^[a-f0-9-]{36}$/i);
    assert.ok(JSON.parse(historyIds).length > 0);
    await page.reload();
    await journalField(historyField,'reloaded original notification history');
    assert.equal(await historyField.inputValue(), historyUuid);
    assert.equal(await page.locator('form[data-desktop-notification-read][action$="/read/all/notification"] input[name="desktop_notification_ids"]').inputValue(), historyIds);
    const singleHistoryField = page.locator('form[data-desktop-notification-read] input[name="_method"][value="PUT"]').first();
    await singleHistoryField.waitFor({ state: 'attached' });
    assert.match(await singleHistoryField.evaluate(field => field.form.querySelector('[name="_desktop_command"]').value), /^[a-f0-9-]{36}$/i);
    process.stdout.write('PASS original notification history forms retain their read snapshots and operation UUID through page reload\n');
    await page.goto(input.origin + '/admin/categorys');
    assert.equal(await page.evaluate(() => typeof window.jQuery.fn.DataTable), 'function');
    const bulkNotice = page.locator('.swal-overlay--show-modal .swal-button').first();
    if (await bulkNotice.count()) await bulkNotice.click();
    await page.locator('.swal-overlay--show-modal').waitFor({ state: 'hidden' });
    const orderRequests=[];
    await page.route('**/admin/post-sortable',async route=>{
      orderRequests.push({command:route.request().headers()['x-fasakhansta-command'],data:route.request().postData()});
      if(orderRequests.length===1)return route.abort();
      return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({status:'success'})});
    });
    const originalOrder=async()=>page.evaluate(()=>{
      const table=document.querySelector('#tablecontents');
      if(table.querySelectorAll('tr.row1').length===1){
        const row=table.querySelector('tr.row1').cloneNode(true);row.dataset.id='999999';row.querySelector('.sub_chk').dataset.id='999999';table.append(row);
      }
      window.desktopOrderCompletions=0;
      jQuery(document).ajaxComplete((_event,_xhr,options)=>{if(new URL(options.url,location.href).pathname==='/admin/post-sortable')window.desktopOrderCompletions++;});
      const assertGeneration=table.dataset.desktopCategoryGeneration;
      if(!/^[a-f0-9-]{36}$/i.test(assertGeneration))throw Error('The original drag table is not bound to its imported generation.');
      const update=jQuery(table).sortable('option','update');
      if(typeof update!=='function')throw Error('The original sortable widget did not initialize.');
      update.call(table);
    });
    await originalOrder();await page.waitForFunction(()=>window.desktopOrderCompletions===1);
    assert.match(orderRequests[0].command,/^[a-f0-9-]{36}$/i);
    await page.reload();await originalOrder();await page.waitForFunction(()=>window.desktopOrderCompletions===1);
    assert.equal(orderRequests[1].command,orderRequests[0].command,'A reloaded original drag table retries the same operation after a lost reply.');
    await page.evaluate(()=>jQuery('#tablecontents').sortable('option','update').call(document.querySelector('#tablecontents')));
    await page.waitForFunction(()=>window.desktopOrderCompletions===2);
    assert.notEqual(orderRequests[2].command,orderRequests[0].command,'The parsed JSON acknowledgement releases the original drag operation.');
    await page.unroute('**/admin/post-sortable');
    process.stdout.write('PASS original category sortable widget retains its UUID after a lost reply and reload, and accepts its JSON acknowledgement\n');
    // Exercise the unchanged bulk-delete button while simulating a lost network reply.
    await page.evaluate(() => {
      const first = document.querySelector('.sub_chk');
      if (document.querySelectorAll('.sub_chk').length === 1) {
        const row = first.closest('tr').cloneNode(true); row.querySelector('.sub_chk').dataset.id = '999999';
        first.closest('tbody').append(row); // UI-only second selection; the mocked endpoint performs no business writes.
      }
      document.querySelectorAll('.sub_chk').forEach(input => { input.closest('tr').dataset.rowId = input.dataset.id; });
    });
    const bulkRequests = [];
    await page.route('**/admin/categorysDeleteAll', async route => {
      bulkRequests.push({ command: route.request().headers()['x-fasakhansta-command'], data: route.request().postData() });
      if (bulkRequests.length === 1) return route.abort();
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: 'Fixture UI acknowledgement' }) });
    });
    const selected = page.locator('.sub_chk');
    assert.ok(await selected.count() >= 2);
    const selectedIds = await selected.evaluateAll(inputs => inputs.slice(0, 2).map(input => input.dataset.id));
    await page.evaluate(() => {
      window.alert = () => {}; window.confirm = () => true;
      window.DashboardSPA = { reload: () => {} };
      window.desktopBulkCompletions = 0;
      jQuery(document).ajaxComplete(() => window.desktopBulkCompletions++);
      document.querySelectorAll('.sub_chk').forEach((input, index) => { input.checked = index < 2; });
    });
    await page.locator('.delete_all').click();
    await page.waitForFunction(() => window.desktopBulkCompletions === 1);
    assert.equal(await page.locator('.sub_chk:checked').count(), 2, 'A failed response must leave the selected rows visible.');
    await page.locator('.sub_chk:checked').evaluateAll(inputs => {
      const first = inputs[0].dataset.id; inputs[0].dataset.id = inputs[1].dataset.id; inputs[1].dataset.id = first;
    });
    await page.locator('.delete_all').click();
    await page.waitForFunction(() => window.desktopBulkCompletions === 2);
    assert.match(bulkRequests[0].command, /^[a-f0-9-]{36}$/i);
    assert.equal(bulkRequests[1].command, bulkRequests[0].command, 'Reordering selected IDs after a lost reply must retain the operation UUID.');
    assert.equal(await page.locator('.sub_chk:checked').count(), 0, 'Only an acknowledged delete removes selected rows.');
    await page.evaluate(ids => jQuery.ajax({ url: '/admin/categorysDeleteAll', type: 'DELETE', data: { ids: ids.join(',') } }), selectedIds);
    await page.waitForFunction(() => window.desktopBulkCompletions === 3);
    assert.notEqual(bulkRequests[2].command, bulkRequests[0].command, 'An acknowledged request releases its UUID for a later operation.');
    await page.unroute('**/admin/categorysDeleteAll');
    process.stdout.write('PASS original bulk-delete button retains rows and its UUID after a lost reply, including reordered selections\n');
    // UI contract only: the real native preparation/supervisor is tested separately on Windows.
    assert.equal(await page.getByRole('button', { name: 'تجهيز بدون إنترنت' }).count(), 0);
    await page.evaluate(() => {
      const state = { available: true, prepared: false, mode: 'server', phase: 'idle', progress: 0, error: '' };
      window.FasakhanstaDesktop = { status: async () => state, onState: () => () => {},
        prepare: async csrf => {
          if (!csrf || csrf !== document.querySelector('meta[name="csrf-token"]').content) throw Error('Missing original CSRF token.');
          return { ...state, phase: 'failed', error: '<img src=x onerror=alert(1)>' };
        } };
    });
    await page.addScriptTag({ url: input.origin + '/dashboard/js/desktop-client.js' });
    // Original flash notices use SweetAlert's modal overlay; dismiss its actual button first.
    const originalNotice = page.locator('.swal-overlay--show-modal .swal-button').first();
    if (await originalNotice.count()) await originalNotice.click();
    await page.locator('.swal-overlay--show-modal').waitFor({ state: 'hidden' });
    await page.getByRole('button', { name: 'تجهيز بدون إنترنت' }).click();
    assert.equal(await page.locator('dialog').isVisible(), true);
    await page.getByRole('button', { name: 'تجهيز الجهاز', exact: true }).click();
    await page.locator('[data-error]').filter({ hasText: '<img src=x onerror=alert(1)>' }).waitFor();
    assert.equal(await page.locator('[data-error] img').count(), 0);
    await page.locator('dialog').getByRole('button', { name: 'إغلاق', exact: true }).click();
    assert.equal(await page.locator('dialog').isVisible(), false);
    process.stdout.write('PASS original dashboard preparation dialog uses its current CSRF token and renders native errors as text\n');
    assert.deepEqual([...externalStatic], [], 'Original layout and editor must not request external scripts, styles or fonts.');
    assert.deepEqual([...missingAssets], [], 'Bundled layout dependencies must load through the private HTTP gateway.');
    process.stdout.write('PASS original Arabic font, layout dependencies and Arabic editor load locally with external requests blocked\n');
  } finally { await browser.close(); }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
