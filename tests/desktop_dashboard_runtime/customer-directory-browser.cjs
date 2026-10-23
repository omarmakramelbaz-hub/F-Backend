'use strict';
// Original CRM page, JavaScript and HTTP writes against a private imported dataset.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE);
const verifyDashboardSoundControl=require('./browser-sound.cjs');
const input=JSON.parse(fs.readFileSync(0,'utf8'));
(async()=>{
  const browser=await chromium.launch(process.env.DESKTOP_TEST_BROWSER_EXECUTABLE
    ?{executablePath:process.env.DESKTOP_TEST_BROWSER_EXECUTABLE,headless:true}:{channel:'msedge',headless:true});
  // Close Chromium before the PHP parent deadline, including on a stuck response.
  const lifecycleDeadline=setTimeout(()=>{
    console.error('CRM browser deadline exceeded; closing the real browser.');
    browser.close().finally(()=>process.exit(1));
  },130000);
  process.once('SIGTERM',()=>browser.close().finally(()=>process.exit(143)));
  try{
    const context=await browser.newContext({serviceWorkers:'block'});
    await context.routeWebSocket('**/*',socket=>socket.close());
    let externalRequestsBlocked=0;
    await context.route('**/*',route=>{
      if(new URL(route.request().url()).origin!==input.origin){externalRequestsBlocked++;return route.abort();}
      return route.continue({headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}});
    });
    const page=await context.newPage(),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.goto(input.origin+'/admin/login');
    await page.locator('#dashboard-login-email').fill('owner@test.invalid');
    await page.locator('#dashboard-login-password').fill('Fixture123');
    await Promise.all([page.waitForURL('**/admin/dashboard'),page.locator('.dashboard-login-submit').click()]);
    const hud=page.locator('#go-hud');
    await page.locator('#go-hud.ready').waitFor({state:'visible'});
    await hud.getByRole('button',{name:'GO — الدخول على الرئيسية',exact:true}).click();
    await hud.waitFor({state:'hidden'});
    await verifyDashboardSoundControl(page);
    assert.equal((await page.goto(input.origin+'/admin/customers?branch=f:100')).status(),200);
    const root=page.locator('#branch-operations'),dialog=root.locator('[data-op-dialog]');
    const boot=JSON.parse(await page.locator('#branch-operations-bootstrap').textContent());
    assert.equal(boot.module,'customers');assert.equal(boot.initial.registry,true);assert.equal(boot.can_message,false);
    assert.equal(await root.locator('[data-op-marketing]').count(),0);
    for(const key of ['messaging-status','messaging-configure','feedback-template-prepare','marketing-template-prepare','campaign-queue'])assert.equal(Object.hasOwn(boot.urls,key),false);
    assert.equal(await page.locator('script[src*="/dashboard/js/customer-crm.js"]').count(),1);
    const savePath=new URL(boot.urls['directory-save'],input.origin).pathname;
    const consentPath=new URL(boot.urls.consent,input.origin).pathname;
    const recoverPath=new URL(boot.urls.recover,input.origin).pathname;
    const writes=[];let createPostCount=0;
    page.on('request',request=>{
      if(request.method()==='POST'&&[savePath,consentPath].includes(new URL(request.url()).pathname)){
        const payload=request.postDataJSON();writes.push({path:new URL(request.url()).pathname,payload,csrf:!!request.headers()['x-csrf-token']});
        if(new URL(request.url()).pathname===savePath&&!payload.directory_id)createPostCount++;
      }
    });
    let resolveCommitted,rejectCommitted;
    const committed=new Promise((resolve,reject)=>{resolveCommitted=resolve;rejectCommitted=reject;});
    let loseCreate=true;
    await context.route('**'+savePath,async route=>{
      if(loseCreate&&route.request().method()==='POST'){
        loseCreate=false;
        try{
          const response=await route.fetch({maxRedirects:0,headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}});
          assert.equal(response.status(),200);const result=await response.json();assert.equal(result.success,true);
          resolveCommitted({payload:route.request().postDataJSON(),result});await route.abort();
        }catch(error){rejectCommitted(error);await route.abort();}
      }else await route.fallback();
    });
    await root.locator('[data-op-add]').click();await dialog.waitFor({state:'visible'});
    await dialog.locator('[name="branch"]').selectOption('f:100');
    await dialog.locator('[name="name"]').fill('عميل متصفح تجريبي');
    await dialog.locator('[name="phone"]').fill('01012667788');
    await dialog.locator('[name="address"]').fill('عنوان تجريبي أول');
    await dialog.locator('[name="area"]').fill('المنصورة');
    const lost=page.waitForEvent('requestfailed',{predicate:r=>new URL(r.url()).pathname===savePath&&r.method()==='POST'});
    await dialog.locator('button[type="submit"]').click();const first=await committed;await lost;
    const createUuid=first.payload.idempotency_key;assert.match(createUuid,/^[0-9a-f-]{36}$/i);
    assert.equal(Object.hasOwn(first.payload,'service_consent'),false);assert.equal(Object.hasOwn(first.payload,'marketing_consent'),false);
    const retry=dialog.locator('[data-op-retry]');await retry.waitFor({state:'visible'});
    assert.equal(await dialog.locator('[name="name"]').isDisabled(),true);
    assert.equal(await dialog.locator('button[type="submit"]').isDisabled(),true);
    const recovery=page.waitForResponse(r=>new URL(r.url()).pathname===recoverPath&&r.request().method()==='GET');
    await retry.click();const recovered=await recovery;
    assert.equal(recovered.status(),200);assert.equal(new URL(recovered.url()).searchParams.get('idempotency_key'),createUuid);
    const recoveredBody=await recovered.json();assert.equal(recoveredBody.found,true);assert.equal(recoveredBody.success,true);
    await dialog.waitFor({state:'hidden'});assert.equal(createPostCount,1);
    const row=()=>root.locator('tbody tr').filter({hasText:'عميل متصفح تجريبي'});
    await row().waitFor({state:'visible'});
    assert.match(await row().innerText(),/متابعة: غير موافق/);assert.match(await row().innerText(),/عروض: غير موافق/);
    await row().getByRole('button',{name:'تعديل',exact:true}).click();await dialog.waitFor({state:'visible'});
    assert.equal(await dialog.locator('[name="phone"]').getAttribute('readonly'),'');
    await dialog.locator('[name="name"]').fill('عميل متصفح تجريبي معدل');
    await dialog.locator('[name="address"]').fill('عنوان تجريبي معدل');
    const editedResponse=page.waitForResponse(r=>new URL(r.url()).pathname===savePath&&r.request().method()==='POST');
    await dialog.locator('button[type="submit"]').click();assert.equal((await editedResponse).status(),200);
    await dialog.waitFor({state:'hidden'});
    await row().filter({hasText:'عنوان تجريبي معدل'}).waitFor({state:'visible'});
    const edit=writes[1].payload;assert.equal(edit.directory_id,first.result.customer.id);assert.notEqual(edit.idempotency_key,createUuid);
    assert.ok(edit.expected_updated_at);assert.equal(Object.hasOwn(edit,'service_consent'),false);assert.equal(Object.hasOwn(edit,'marketing_consent'),false);
    const approveResponse=page.waitForResponse(r=>new URL(r.url()).pathname===consentPath&&r.request().method()==='POST');
    await row().getByRole('button',{name:'وافق على المتابعة والعروض',exact:true}).click();assert.equal((await approveResponse).status(),200);
    await row().getByRole('button',{name:'إيقاف رسائل واتساب',exact:true}).waitFor({state:'visible'});
    assert.match(await row().innerText(),/متابعة: موافق/);assert.match(await row().innerText(),/عروض: موافق/);
    await row().locator('.op-consent-options summary').click();
    const withdrawResponse=page.waitForResponse(r=>new URL(r.url()).pathname===consentPath&&r.request().method()==='POST');
    await row().getByRole('button',{name:'إيقاف المتابعة',exact:true}).click();assert.equal((await withdrawResponse).status(),200);
    await row().getByRole('button',{name:'وافق على المتابعة والعروض',exact:true}).waitFor({state:'visible'});
    assert.match(await row().innerText(),/متابعة: غير موافق/);assert.match(await row().innerText(),/عروض: موافق/);
    const reopenResponse=page.waitForResponse(r=>new URL(r.url()).pathname===consentPath&&r.request().method()==='POST');
    await row().getByRole('button',{name:'وافق على المتابعة والعروض',exact:true}).click();assert.equal((await reopenResponse).status(),409);
    await root.locator('[data-op-message]').filter({hasText:'موافقة'}).waitFor({state:'visible'});
    assert.match(await row().innerText(),/متابعة: غير موافق/);assert.match(await row().innerText(),/عروض: موافق/);
    assert.equal(writes.length,5);assert.equal(new Set(writes.map(x=>x.payload.idempotency_key)).size,5);assert.ok(writes.every(x=>x.csrf));
    assert.deepEqual(errors,[]);
    if(input.artifacts)await page.screenshot({path:path.join(input.artifacts,'customer-directory.png'),fullPage:true});
    process.stdout.write('CUSTOMER_DIRECTORY_BROWSER_PROOF '+JSON.stringify({sourceCommit:input.sourceCommit,fixtureRevision:input.fixtureRevision,
      browser:process.env.DESKTOP_TEST_BROWSER_EXECUTABLE?'Chrome headless shell':'Windows Edge',browserPlatform:process.platform,
      create:true,edit:true,explicitConsent:true,scopedWithdrawal:true,recordedWithdrawalReopenRejected:true,
      committedResponseLost:true,recoveredSameUuid:true,createPostCount,directoryId:first.result.customer.id,writes,
      externalRequestsBlocked,newPageErrors:errors.length,domInjection:false,providersReplaced:false})+'\n');
  }finally{clearTimeout(lifecycleDeadline);await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
