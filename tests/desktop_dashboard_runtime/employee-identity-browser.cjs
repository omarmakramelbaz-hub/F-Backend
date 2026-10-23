'use strict';
// Real Blade/JavaScript, multipart browser uploads and local journal writes.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE);
const input=JSON.parse(fs.readFileSync(0,'utf8'));
const digest=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
(async()=>{
  const browser=await chromium.launch(process.env.DESKTOP_TEST_BROWSER_EXECUTABLE
    ?{executablePath:process.env.DESKTOP_TEST_BROWSER_EXECUTABLE,headless:true}:{channel:'msedge',headless:true});
  const deadline=setTimeout(()=>browser.close().finally(()=>process.exit(1)),130000);
  process.once('SIGTERM',()=>browser.close().finally(()=>process.exit(143)));
  try{
    const context=await browser.newContext({serviceWorkers:'block'}),errors=[];let externalRequestsBlocked=0;
    await context.routeWebSocket('**/*',socket=>socket.close());
    await context.route('**/*',route=>{
      if(new URL(route.request().url()).origin!==input.origin){externalRequestsBlocked++;return route.abort();}
      return route.continue({headers:{...route.request().headers(),...(input.token?{'X-Fasakhansta-Desktop':input.token}:{})}});
    });
    if(input.token)await context.setExtraHTTPHeaders({'X-Fasakhansta-Desktop':input.token});
    const employeeConsoleErrors=[];
    const observe=(page,label)=>{let document=0;page.on('request',request=>{if(request.isNavigationRequest()&&request.frame()===page.mainFrame())document++;});
      page.on('pageerror',error=>errors.push({message:error.message,stack:error.stack,url:page.url(),document:label+':'+document}));
      page.on('console',message=>{if(message.type()==='error'&&/\/dashboard\/js\/branch-operations\.js/.test(message.location().url))employeeConsoleErrors.push({message:message.text(),location:message.location()});});};
    const page=await context.newPage();observe(page,'owner');
    const login=async(email='owner@test.invalid')=>{
      await page.goto(input.origin+'/admin/login');await page.locator('#dashboard-login-email').fill(email);
      await page.locator('#dashboard-login-password').fill('Fixture123');
      await Promise.all([page.waitForURL('**/admin/dashboard'),page.locator('.dashboard-login-submit').click()]);
    };
    await login();assert.equal((await page.goto(input.origin+'/admin/employees?branch=f:100')).status(),200);
    const root=page.locator('#branch-operations'),dialog=root.locator('[data-op-dialog]');
    const boot=JSON.parse(await page.locator('#branch-operations-bootstrap').textContent());
    assert.equal(boot.module,'employees');assert.equal(boot.regulation.version,input.regulation.version);
    assert.equal(boot.regulation.sha256,input.regulation.sha256);assert.equal(boot.initial.can_manage_employees,true);
    assert.equal(await page.locator('script[src*="/dashboard/js/branch-operations.js"]').count(),1);
    await page.waitForTimeout(150);
    // Online layout CDN globals fail when this private fixture blocks external I/O.
    // Record the original baseline; new employee-script exceptions always fail.
    const legacyMessages=new Set(['firebase is not defined','$(...).jstree is not a function','CKEDITOR is not defined','toastr is not defined','$(...).rateYo is not a function']);
    const signature=error=>{
      const first=error.stack.match(/https?:\/\/[^\s)]+/)?.[0]?.replace(/:\d+:\d+$/,'');
      const location=first?new URL(first):null;return error.message+'\n'+(location?location.origin+location.pathname:'<no-source>');
    };
    const initialOriginalPageErrors=[...errors],baselineCounts=new Map();
    // Fixed reviewed layout caps. The employee page cannot teach this test to
    // accept a newly introduced error, missing source URL or larger count.
    if(input.phase==='central')for(const pathname of ['/admin/dashboard','/admin/employees'])for(const message of legacyMessages)
      baselineCounts.set(message+'\n'+input.origin+pathname,message==='firebase is not defined'?2:1);
    assert.ok(initialOriginalPageErrors.every(error=>baselineCounts.has(signature(error))&&!error.stack.includes('/dashboard/js/branch-operations.js')));
    for(const item of boot.initial.items)for(const key of ['id_front_path','id_back_path','id_front_hash','id_back_hash','id_front_mime','id_back_mime','regulation_accepted_by'])assert.equal(Object.hasOwn(item,key),false);
    const row=()=>root.locator('[data-op-employee="7"]'),writes=[],photoWrites=[];
    const savePath=new URL(boot.urls.save,input.origin).pathname;
    page.on('request',request=>{if(request.method()==='POST'&&new URL(request.url()).pathname===savePath)
      writes.push({payload:request.postDataJSON(),csrf:!!request.headers()['x-csrf-token']});});
    page.on('request',request=>{if(request.method()==='POST'&&boot.urls.identity&&new URL(request.url()).pathname===new URL(boot.urls.identity,input.origin).pathname)
      photoWrites.push({csrf:!!request.headers()['x-csrf-token']});});
    const csrf=await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const headers={'Accept':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'};
    const readEmployee=async()=>{
      const url=new URL(boot.urls.data,input.origin);url.searchParams.set('branch','f:100');
      const response=await context.request.get(url.href);assert.equal(response.status(),200);
      return (await response.json()).items.find(x=>x.id===7);
    };
    const saveValues=employee=>({branch:'f:100',employee_id:7,name:employee.name,phone:employee.phone||'',job_title:employee.job_title,
      shift:employee.shift||'',hired_on:employee.hired_on,left_on:employee.left_on||'',active:true,notes:employee.notes||'',
      salary:employee.statement.salary,effective_month:boot.initial.filters.month,expected_revision:employee.revision,
      idempotency_key:crypto.randomUUID()});
    const editConsent=async checked=>{
      await row().locator('summary[aria-label^="إجراءات"]').click();await row().getByRole('button',{name:'تعديل الموظف',exact:true}).click();
      await dialog.waitFor({state:'visible'});await dialog.locator('.op-regulation-field input[type="checkbox"]').setChecked(checked);
      const response=page.waitForResponse(r=>new URL(r.url()).pathname===savePath&&r.request().method()==='POST');
      await dialog.locator('button[type="submit"]').click();assert.equal((await response).status(),200);
      await dialog.waitFor({state:'hidden'});await row().waitFor({state:'visible'});
      // Wait for the original refresh to publish the new revision before another edit.
      await page.reload();await row().waitFor({state:'visible'});
    };
    const policy=await context.request.get(boot.urls.regulation);assert.equal(policy.status(),200);
    const policyHtml=await policy.text();assert.ok(policyHtml.includes(input.regulation.title));
    assert.ok(policyHtml.includes(input.regulation.version));
    for(const section of boot.regulation.sections){assert.ok(policyHtml.includes(section.title));for(const item of section.items)assert.ok(policyHtml.includes(item.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;')));}
    const proof={phase:input.phase,sourceCommit:input.sourceCommit,fixtureRevision:input.fixtureRevision,
      browser:process.env.DESKTOP_TEST_BROWSER_EXECUTABLE?'Chrome headless shell':'Windows Edge',browserPlatform:process.platform,
      regulationRendered:true,domInjection:false,providersReplaced:false};
    const imageUrl=side=>input.origin+'/admin/employees/identity-image?branch=f:100&employee_id=7&side='+side;
    const verifyImage=async(side,bytes,mime)=>{
      const response=await context.request.get(imageUrl(side));assert.equal(response.status(),200);
      assert.equal(response.headers()['content-type'],mime);
      const contentOptions=response.headers()['x-content-type-options'].split(',').map(value=>value.trim());
      assert.ok(contentOptions.length>0&&contentOptions.every(value=>value==='nosniff'));
      assert.match(response.headers()['cache-control'],/no-store/);assert.equal(Number(response.headers()['content-length']),bytes.length);
      assert.equal(digest(await response.body()),digest(bytes));
    };
    const png=fs.readFileSync(input.png),jpg=fs.readFileSync(input.jpg);
    if(input.phase==='central'){
      const before=await readEmployee(),stale=saveValues(before);stale.regulation_accepted=true;stale.regulation_version='2000-01-01';stale.regulation_hash='0'.repeat(64);
      const rejected=await context.request.post(boot.urls.save,{headers,data:stale});assert.equal(rejected.status(),422);
      assert.match((await rejected.json()).message,/نسخة لائحة العمل تغيرت/);assert.deepEqual(await readEmployee(),before);
      await editConsent(true);let approved=await readEmployee();assert.equal(approved.regulation.current,true);const firstDate=approved.regulation.accepted_at;
      const same=saveValues(approved);Object.assign(same,{regulation_accepted:true,regulation_version:input.regulation.version,regulation_hash:input.regulation.sha256});
      const repeated=await context.request.post(boot.urls.save,{headers,data:same});assert.equal(repeated.status(),200);
      assert.equal((await readEmployee()).regulation.accepted_at,firstDate);
      await page.reload();await editConsent(false);const cleared=await readEmployee();assert.equal(cleared.regulation.accepted,false);assert.equal(cleared.regulation.accepted_at,null);
      await editConsent(true);
      const upload=async(side,file)=>{
        await row().locator('.op-identity-badge').click();await dialog.waitFor({state:'visible'});
        assert.equal(await dialog.locator('.op-form .op-identity-field').count(),1);
        assert.equal(await dialog.locator('.op-form .op-regulation-field').count(),1);
        const uploadsBefore=photoWrites.length;
        await dialog.locator('.op-identity-side').nth(side==='front'?0:1).locator('input[type="file"]').setInputFiles(file);
        await dialog.locator('.op-identity-side').nth(side==='front'?0:1).locator('img[src^="data:"]').waitFor();
        assert.equal(photoWrites.length,uploadsBefore,'Choosing a photograph previews it until the employee form is saved.');
        const response=page.waitForResponse(r=>new URL(r.url()).pathname===new URL(boot.urls.identity).pathname&&r.request().method()==='POST');
        const save=page.waitForResponse(r=>new URL(r.url()).pathname===savePath&&r.request().method()==='POST');
        await dialog.locator('button[type="submit"]').click();
        assert.equal((await save).status(),200);assert.equal((await response).status(),200);
        await dialog.waitFor({state:'hidden'});await page.reload();await row().waitFor({state:'visible'});
      };
      await upload('front',input.png);await verifyImage('front',png,'image/png');
      await upload('front',input.jpg);await verifyImage('front',jpg,'image/jpeg');
      await upload('front',input.png);await verifyImage('front',png,'image/png');
      await upload('back',input.jpg);await verifyImage('back',jpg,'image/jpeg');
      assert.equal(await row().locator('.op-identity-badge').innerText(),'الملف كامل');
      assert.equal(photoWrites.length,4);assert.ok(photoWrites.every(x=>x.csrf));
      Object.assign(proof,{staleConsentRejected:true,firstAcceptanceDatePreserved:true,withdrawal:true,realUpload:true,immutableABA:true});
      // Separate original branch login; read access never grants profile/upload authority.
      const branch=await browser.newContext({serviceWorkers:'block'});
      await branch.routeWebSocket('**/*',socket=>socket.close());
      await branch.route('**/*',route=>new URL(route.request().url()).origin===input.origin?route.continue():route.abort());
      const reader=await branch.newPage();observe(reader,'branch');
      const loginForm=await branch.request.get(input.origin+'/admin/login'),loginHtml=await loginForm.text();
      const loginCsrf=loginHtml.match(/name="_token" value="([^"]+)"/)?.[1];assert.ok(loginCsrf);
      const signedIn=await branch.request.post(input.origin+'/admin/signin',{form:{_token:loginCsrf,email:'branch@test.invalid',password:'Fixture123'},maxRedirects:0});assert.equal(signedIn.status(),302);
      assert.equal((await reader.goto(input.origin+'/admin/employees?branch=f:100')).status(),200);
      const readerBoot=JSON.parse(await reader.locator('#branch-operations-bootstrap').textContent());assert.equal(readerBoot.initial.can_manage_employees,false);
      const branchImage=await branch.request.get(imageUrl('front'));assert.equal(branchImage.status(),200);assert.equal(digest(await branchImage.body()),digest(png));
      const branchCsrf=await reader.locator('meta[name="csrf-token"]').getAttribute('content');
      const denied=await branch.request.post(boot.urls.identity,{headers:{Accept:'application/json','X-CSRF-TOKEN':branchCsrf},multipart:{branch:'f:100',employee_id:'7',side:'front',photo:{name:'synthetic.png',mimeType:'image/png',buffer:png}}});assert.equal(denied.status(),403);
      assert.equal((await branch.request.get(input.origin+'/admin/employees/identity-image?branch=f:101&employee_id=8&side=front')).status(),404);
      const profile=await branch.request.post(boot.urls.save,{headers:{Accept:'application/json','X-CSRF-TOKEN':branchCsrf},data:saveValues(await readEmployee())});assert.equal(profile.status(),403);
      Object.assign(proof,{branchReadOnly:true,outOfScopeDenied:true});await branch.close();
    }else{
      assert.equal(Object.hasOwn(boot.urls,'identity'),false);
      await row().locator('.op-identity-badge').click();await dialog.waitFor({state:'visible'});
      assert.equal(await dialog.locator('.op-form .op-identity-field').count(),1);
      assert.equal(await dialog.locator('input[type="file"]').count(),0);
      assert.match(await dialog.innerText(),/من المتصفح/);
      assert.equal(await dialog.locator('.op-identity-side img').count(),2);
      await verifyImage('front',png,'image/png');await verifyImage('back',jpg,'image/jpeg');
      await page.waitForFunction(()=>{const images=[...document.querySelectorAll('[data-op-dialog] .op-identity-side img')];return images.length===2&&images.every(image=>image.complete&&image.naturalWidth>0);});
      for(const [index,side] of ['front','back'].entries()){
        const shown=new URL(await dialog.locator('.op-identity-side img').nth(index).evaluate(image=>image.currentSrc));
        assert.equal(shown.origin,input.origin);assert.equal(shown.pathname,'/admin/employees/identity-image');
        assert.equal(shown.searchParams.get('branch'),'f:100');assert.equal(shown.searchParams.get('employee_id'),'7');assert.equal(shown.searchParams.get('side'),side);
      }
      await dialog.getByRole('button',{name:'إغلاق',exact:true}).click();
      await editConsent(false);await editConsent(true);
      assert.equal(writes.length,2);assert.equal(writes[0].payload.regulation_accepted,false);assert.equal(writes[1].payload.regulation_accepted,true);
      assert.equal(writes[1].payload.regulation_version,input.regulation.version);assert.equal(writes[1].payload.regulation_hash,input.regulation.sha256);
      assert.notEqual(writes[0].payload.idempotency_key,writes[1].payload.idempotency_key);assert.ok(writes.every(x=>x.csrf));
      Object.assign(proof,{privatePhotos:true,imagesDecoded:true,displayedPhotoSourcesBound:true,onlineUploadExplanation:true,consentJournal:true});
    }
    const guessed=input.origin+'/storage/branch-expenses/employee-identity/7/front-'+digest(png)+'.png';
    const publicImage=await context.request.get(guessed);assert.equal(publicImage.status(),404);assert.notEqual(digest(await publicImage.body()),digest(png));
    const observedCounts=new Map(),newErrors=errors.filter(error=>{
      const key=error.document+'\0'+signature(error),count=(observedCounts.get(key)||0)+1;observedCounts.set(key,count);
      return !legacyMessages.has(error.message)||/\/dashboard\/js\/branch-operations\.js/.test(error.stack)||count>(baselineCounts.get(signature(error))||0);
    });
    assert.deepEqual(employeeConsoleErrors,[]);if(input.phase==='local')assert.equal(externalRequestsBlocked,0);
    assert.deepEqual(newErrors,[]);proof.privatePublicUrlDenied=true;proof.newPageErrors=newErrors.length;
    proof.initialOriginalPageErrors=initialOriginalPageErrors;proof.legacyBaselineLimits=Object.fromEntries(baselineCounts);proof.observedLegacyPageErrors=errors.length;proof.newEmployeeConsoleErrors=employeeConsoleErrors.length;
    proof.listingRedacted=true;proof.externalRequestsBlocked=externalRequestsBlocked;proof.writes=writes;
    await page.screenshot({path:path.join(input.artifacts,'employee-identity-'+input.phase+'.png'),fullPage:true});
    process.stdout.write('EMPLOYEE_IDENTITY_BROWSER_PROOF '+JSON.stringify(proof)+'\n');
  }finally{clearTimeout(deadline);await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
