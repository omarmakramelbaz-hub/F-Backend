'use strict';
// Optional real browser checks on the disposable original application, with external requests blocked.
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.DESKTOP_TEST_BROWSER_MODULE);
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
let stage = 'launch';
const watchdog = setTimeout(() => {
  process.stderr.write('BROWSER_TEST_TIMEOUT '+JSON.stringify({stage})+'\n');
  process.exit(1);
}, 240000);
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
    const scriptLoads = [];
    page.on('framenavigated', frame => {
      if (frame === page.mainFrame()) process.stderr.write('BROWSER_NAVIGATION '+JSON.stringify({stage,path:new URL(frame.url()).pathname})+'\n');
    });
    page.on('requestfailed', request => {
      if (request.resourceType() === 'script') {
        const failure={stage,path:new URL(request.url()).pathname,error:request.failure()?.errorText};
        scriptLoads.push(failure);process.stderr.write('BROWSER_SCRIPT_REQUEST_FAILED '+JSON.stringify(failure)+'\n');
      }
    });
    page.on('pageerror', error => { pageErrors.push(error.message); process.stderr.write('BROWSER_SCRIPT_ERROR '+error.stack+'\n'); });
    page.on('response', response => {
      if (response.request().resourceType() === 'script') {
        const loaded={stage,path:new URL(response.url()).pathname,status:response.status(),type:response.headers()['content-type']};
        scriptLoads.push(loaded);
        if(response.status()!==200)process.stderr.write('BROWSER_SCRIPT_RESPONSE '+JSON.stringify(loaded)+'\n');
      }
      if (new URL(response.url()).origin === input.origin && response.url().includes('/js/desktop-') && response.status() !== 200)
        process.stderr.write('BROWSER_JOURNAL_SCRIPT_RESPONSE '+JSON.stringify({path:new URL(response.url()).pathname,status:response.status()})+'\n');
    });
    const journalField = async (field, label, response) => {
      stage = label;
      try {
        if(response) assert.equal(response.status(), 200, label+' must render successfully.');
        await field.waitFor({ state: 'attached' });
      } catch (error) {
        // Synthetic CI pages only. Capture state, never input values or credentials.
        const state = await Promise.race([page.evaluate(() => ({
          path: location.pathname, ready:document.readyState, local:document.body?.dataset.dashboardLocal,
          journalAjax:Boolean(window.jQuery?.fasakhanstaCatalogJournal),
          journalScripts:[...document.scripts].filter(script=>script.src.includes('desktop-dashboard.js')).map(script=>({path:new URL(script.src).pathname,defer:script.defer,type:script.type})),
          forms:[...document.forms].map(form=>({path:new URL(form.action).pathname,
            method:form.querySelector('[name="_method"]')?.value||form.method,
            generation:form.dataset.notificationGeneration,notificationRead:form.hasAttribute('data-desktop-notification-read'),
            journaled:Boolean(form.querySelector('[name="_desktop_command"]'))})),
          text:document.body?.innerText.slice(-1500)
        })), new Promise(resolve => setTimeout(() => resolve({diagnosticError:'The renderer did not answer within five seconds.'}), 5000))]);
        process.stderr.write('JOURNAL_FORM_DIAGNOSTIC '+JSON.stringify({label,status:response?.status(),...state,pageErrors,scriptLoads})+'\n');
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
    const soundPrompt=page.locator('.swal-overlay--show-modal');
    await soundPrompt.waitFor({state:'visible'});
    assert.equal(await soundPrompt.locator('.swal-title').textContent(),'تفعيل الصوت');
    await soundPrompt.getByRole('button',{name:'تم',exact:true}).click();
    await soundPrompt.waitFor({state:'hidden'});
    await page.waitForFunction(()=>localStorage.getItem('notificationSoundAlertShown')==='true');
    process.stdout.write('PASS original notification sound prompt acknowledges through its real button before dashboard form interactions\n');
    assert.equal(await page.evaluate(() => typeof window.jQuery?.fn.summernote), 'function', 'The original editor must load after jQuery and Bootstrap.');
    process.stdout.write('PASS real browser signs in to the original imported dashboard with external requests blocked\n');
    let previous;
    for (const module of ['areas', 'question_answers', 'features', 'contracts', 'categorys', 'products', 'roles']) {
      await page.goto(input.origin + '/admin/' + module + '/create');
      const field = page.locator('form input[name="_desktop_command"]');
      await journalField(field, module+' create form');
      const uuid = await field.inputValue();
      assert.match(uuid, /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i);
      assert.notEqual(uuid, previous);
      const name = ['areas','features'].includes(module) ? 'title_ar' : module === 'question_answers' ? 'question_ar' : module === 'roles' ? 'name' : 'name_ar';
      if(module==='contracts')await page.locator('select[name="type"]').selectOption('delegate');
      else await page.locator('[name="' + name + '"]').fill('اختبار النموذج الأصلي');
      if(module==='roles'){
        const permission=page.locator('input[name="permission[]"]').first();
        const header=permission.locator('xpath=ancestor::div[contains(@class,"accordion-item")][1]').locator('button[data-bs-toggle="collapse"]');
        if(await header.getAttribute('aria-expanded')!=='true')await header.click();
        await permission.waitFor({state:'visible'});
        await permission.check();assert.equal(await permission.isChecked(),true);
        assert.match(await permission.inputValue(),/^[1-9][0-9]*$/);
      }
      await page.evaluate(() => {
        const form = document.querySelector('form input[name="_desktop_command"]').form;
        form.append(document.createElement('span'));
      });
      assert.equal(await field.inputValue(), uuid);
      process.stdout.write('PASS original ' + module + ' form keeps its operation UUID while inputs and DOM change\n');
      previous = uuid;
    }
    await page.goto(input.origin+'/admin/roles/1/edit');
    const roleUpdate=page.locator('form input[name="_desktop_command"]');await journalField(roleUpdate,'original role update form');
    const roleUpdateUUID=await roleUpdate.inputValue();await page.locator('form input[name="name"]').fill('تعديل نموذج الدور الأصلي');
    const rolePermission=page.locator('input[name="permission[]"]').first();
    const roleHeader=rolePermission.locator('xpath=ancestor::div[contains(@class,"accordion-item")][1]').locator('button[data-bs-toggle="collapse"]');
    if(await roleHeader.getAttribute('aria-expanded')!=='true')await roleHeader.click();
    await rolePermission.waitFor({state:'visible'});await rolePermission.uncheck();await rolePermission.check();
    assert.equal(await roleUpdate.inputValue(),roleUpdateUUID);assert.equal(await page.locator('form input[name="_method"]').inputValue(),'PUT');
    await page.goto(input.origin+'/admin/roles');
    const roleDeleteForm=page.locator('form').filter({has:page.locator('input[name="_method"][value="DELETE"]')}).first();
    const roleDelete=roleDeleteForm.locator('input[name="_desktop_command"]');await journalField(roleDelete,'original role delete form');
    const roleDeleteUUID=await roleDelete.inputValue();assert.notEqual(roleDeleteUUID,roleUpdateUUID);
    await roleDeleteForm.evaluate(form=>form.append(document.createElement('span')));assert.equal(await roleDelete.inputValue(),roleDeleteUUID);
    process.stdout.write('PASS original role create/update/delete forms retain their permission controls and immutable operation UUIDs\n');
    for(const branch of ['f:100','gs:60']){
      stage='original '+branch+' menu availability';
      await page.goto(input.origin+'/admin/applies-orders?branch='+encodeURIComponent(branch));
      const menuToggle=page.locator('[data-menu-toggle]');await menuToggle.waitFor({state:'visible'});
      const menuNotice=page.locator('.swal-overlay--show-modal');
      if(await menuNotice.isVisible()){await menuNotice.getByRole('button',{name:'تم',exact:true}).click();await menuNotice.waitFor({state:'hidden'});}
      if(await page.locator('#branch-menu').evaluate(panel=>panel.hidden))await menuToggle.click();
      const button=page.locator('[data-menu-availability="1"]');await button.waitFor({state:'visible'});
      const path='/admin/order-board/menu/'+branch.replace(':','/')+'/products/1/availability', requests=[], replies=[];
      await page.route('**'+path,async route=>{
        const values=route.request().postDataJSON();requests.push(values);
        const actual=await route.fetch({headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}});assert.equal(actual.status(),200);replies.push(await actual.json());
        if(requests.length===1)return route.abort('failed'); // PHP committed before the browser loses its response.
        return route.fulfill({response:actual});
      });
      await button.click();await button.waitFor({state:'visible'});await page.waitForFunction(()=>!document.querySelector('[data-menu-availability="1"]').disabled);
      assert.match(requests[0].idempotency_key,/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i);
      const [refreshed]=await Promise.all([page.waitForResponse(response=>response.request().method()==='GET'&&response.url().includes('/admin/order-board/menu?')),page.locator('[data-menu-refresh]').click()]);
      const refreshedItem=(await refreshed.json()).items.find(item=>item.id===1);assert.equal(refreshedItem.available,false);
      if(branch.startsWith('gs:'))assert.equal(refreshedItem.revision,2);
      await page.waitForFunction(()=>document.querySelector('[data-menu-items]').getAttribute('aria-busy')==='false');await button.waitFor({state:'visible'});
      await button.click();await page.waitForFunction(()=>!document.querySelector('[data-menu-availability="1"]').disabled);
      assert.deepEqual(requests[1],requests[0],'The actual menu button keeps its UUID and desired payload after an ambiguous reply and original menu refresh.');
      assert.deepEqual(replies[1],replies[0],'The actual PHP cached reply retains the first result and revision after refresh.');
      await button.click();await page.waitForFunction(()=>!document.querySelector('[data-menu-availability="1"]').disabled);
      assert.notEqual(requests[2].idempotency_key,requests[0].idempotency_key,'A confirmed availability result releases its UUID for a later toggle.');
      assert.equal(replies[2].item.available,true);if(branch.startsWith('gs:'))assert.equal(replies[2].item.revision,3);
      await page.unroute('**'+path);
      process.stdout.write('PASS original '+branch+' menu button commits through actual PHP, loses reply, retains UUID/payload across changed-state refresh and receives the exact first result\n');
    }
    await page.goto(input.origin+'/admin/roles');
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
    stage='original bulk role deletion';
    assert.ok(Array.isArray(input.bulkRoleIds)&&input.bulkRoleIds.length===2,'The disposable original roles page must contain two real bulk fixtures.');
    const bulkRoleIds=input.bulkRoleIds.map(String);
    assert.equal(new Set(bulkRoleIds).size,2);assert.ok(bulkRoleIds.every(id=>/^[1-9][0-9]*$/.test(id)));
    const roleBulkButton=page.locator('.delete_all[data-url$="/admin/rolesDeleteAll"]');
    assert.equal(await roleBulkButton.count(),1,'The original permission-gated bulk role button remains available.');
    for(const id of bulkRoleIds){
      const checkbox=page.locator('.sub_chk[data-id="'+id+'"]');
      assert.equal(await checkbox.count(),1,'Each selected role must be an actual original table row.');
      await checkbox.check();
    }
    const roleBulkRequests=[];
    await page.route('**/admin/rolesDeleteAll',async route=>{
      roleBulkRequests.push({command:route.request().headers()['x-fasakhansta-command'],data:route.request().postData(),method:route.request().method()});
      if(roleBulkRequests.length===1){
        const committed=await route.fetch({headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}});
        assert.equal(committed.status(),200);assert.ok((await committed.json()).success);
        return route.abort(); // Lose a real committed response while retaining the original UI selection.
      }
      return route.continue({headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}});
    });
    const clickRoleBulk=async outcome=>{
      const confirmationPromise=page.waitForEvent('dialog'),clicked=roleBulkButton.click();
      const confirmation=await confirmationPromise;
      assert.equal(confirmation.type(),'confirm');assert.equal(confirmation.message(),'Are you sure you want to delete this row?');
      const alertPromise=page.waitForEvent('dialog');
      await confirmation.accept();
      const alert=await alertPromise;
      assert.equal(alert.type(),'alert');const message=alert.message();
      // Release the native modal before waiting for transport events that it can defer.
      await alert.accept();const [result]=await Promise.all([outcome,clicked]);
      return {result,message};
    };
    await clickRoleBulk(page.waitForEvent('requestfailed',{predicate:request=>new URL(request.url()).pathname==='/admin/rolesDeleteAll'}));
    assert.equal(await page.locator('.sub_chk:checked').count(),2,'A lost bulk role response keeps both original selected rows visible.');
    const roleResponse=await clickRoleBulk(page.waitForResponse(response=>new URL(response.url()).pathname==='/admin/rolesDeleteAll'&&response.request().method()==='DELETE'));
    assert.equal(roleResponse.result.status(),200);const roleResult=await roleResponse.result.json();
    assert.equal(typeof roleResult.success,'string');assert.ok(roleResult.success.length>0);assert.equal(roleResponse.message,roleResult.success);
    assert.equal(roleBulkRequests.length,2);assert.ok(roleBulkRequests.every(request=>request.method==='DELETE'));
    assert.match(roleBulkRequests[0].command,/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i);
    assert.equal(roleBulkRequests[1].command,roleBulkRequests[0].command,'The real original bulk role button retries its stable UUID after a lost reply.');
    for(const request of roleBulkRequests)assert.deepEqual(new URLSearchParams(request.data).get('ids').split(',').sort(),[...bulkRoleIds].sort());
    await page.unroute('**/admin/rolesDeleteAll');
    const rolesAfterBulk=await page.goto(input.origin+'/admin/roles');assert.equal(rolesAfterBulk.status(),200);
    for(const id of bulkRoleIds)assert.equal(await page.locator('.sub_chk[data-id="'+id+'"]').count(),0,'A fresh original index confirms the selected role was deleted.');
    process.stdout.write('BROWSER_ROLE_BULK_PROOF '+JSON.stringify({command:roleBulkRequests[0].command,ids:bulkRoleIds})+'\n');
    process.stdout.write('PASS real original bulk role checkboxes, confirm and alert dialogs delete both roles once after retrying the same UUID\n');
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
    stage='category index';
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
  clearTimeout(watchdog);
})().catch(error => { clearTimeout(watchdog); process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
