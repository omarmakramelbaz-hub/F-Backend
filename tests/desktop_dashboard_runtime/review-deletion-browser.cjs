'use strict';
// Actual original Blade/form/SweetAlert interaction on a private original runtime.
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE);
const input=JSON.parse(fs.readFileSync(0,'utf8'));
(async()=>{
  const browser=await chromium.launch(process.env.DESKTOP_TEST_BROWSER_EXECUTABLE
    ?{executablePath:process.env.DESKTOP_TEST_BROWSER_EXECUTABLE,headless:true}:{channel:'msedge',headless:true});
  try{
    const context=await browser.newContext({serviceWorkers:'block'});
    await context.routeWebSocket('**/*',socket=>socket.close());
    await context.route('**/*',route=>new URL(route.request().url()).origin===input.origin
      ?route.continue({headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}}):route.abort());
    const page=await context.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(input.origin+'/admin/login');
    await page.locator('#dashboard-login-email').fill('owner@test.invalid');await page.locator('#dashboard-login-password').fill('Fixture123');
    await Promise.all([page.waitForURL('**/admin/dashboard'),page.locator('.dashboard-login-submit').click()]);
    const overlay=page.locator('.swal-overlay--show-modal');await overlay.waitFor({state:'visible'});
    assert.equal(await overlay.locator('.swal-title').textContent(),'تفعيل الصوت');
    await overlay.getByRole('button',{name:'تم',exact:true}).click();await overlay.waitFor({state:'hidden'});
    assert.equal((await page.goto(input.origin+'/admin/resturants/100')).status(),200);
    await page.locator('#pills-resturant_reviews-tab').click();
    // Compare against the untouched original modal script's errors on this initial GET.
    const initialPageErrors=[...errors];
    const form=page.locator('form[action="'+input.origin+'/admin/resturant_reviews/94001"]');
    await form.locator('[name="_desktop_command"]').waitFor({state:'attached'});
    const command=await form.locator('[name="_desktop_command"]').inputValue();assert.match(command,/^[0-9a-f-]{36}$/i);
    assert.equal(await form.locator('[name="_method"]').inputValue(),'DELETE');
    assert.equal(await form.locator('[name="_token"]').count(),1);assert.equal(await form.locator('[name="_desktop_command"]').count(),1);
    const clickDelete=async()=>{
      await form.locator('.show_confirm').click();await overlay.waitFor({state:'visible'});
      assert.equal(await overlay.locator('.swal-title').textContent(),'هل انت متاكد من حذف هذا العنصر ؟');
      await overlay.getByRole('button',{name:'نعم',exact:true}).click();
    };
    const fields=request=>new Response(request.postDataBuffer(),{headers:{'Content-Type':request.headers()['content-type']}}).formData();
    let intercepted=true,committed;const commitPromise=new Promise(resolve=>{committed=resolve;});
    await context.route('**/admin/resturant_reviews/94001',async route=>{
      if(intercepted&&route.request().method()==='POST'){
        intercepted=false;const actual=await route.fetch({maxRedirects:0,headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}});
        assert.equal(actual.status(),302);assert.equal(new URL(actual.headers().location,input.origin).pathname,'/admin/resturants/100');
        committed(await fields(route.request()));await route.abort();
      }else await route.fallback();
    });
    const lost=page.waitForEvent('requestfailed',{predicate:r=>new URL(r.url()).pathname==='/admin/resturant_reviews/94001'&&r.method()==='POST'});
    await clickDelete();const firstBody=await commitPromise;await lost;
    assert.equal(firstBody.get('_desktop_command'),command);assert.equal(firstBody.get('_method'),'DELETE');
    await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));
    assert.equal(await form.locator('[name="_desktop_command"]').inputValue(),command);
    assert.equal(await form.locator('.show_confirm').isEnabled(),true);
    const sentPromise=page.waitForRequest(r=>new URL(r.url()).pathname==='/admin/resturant_reviews/94001'&&r.method()==='POST');
    const responsePromise=page.waitForResponse(r=>new URL(r.url()).pathname==='/admin/resturant_reviews/94001'&&r.request().method()==='POST');
    await clickDelete();
    const sent=await sentPromise,response=await responsePromise,body=await fields(sent);
    assert.equal(body.get('_desktop_command'),command);assert.equal(body.get('_method'),'DELETE');assert.ok(body.get('_token'));
    assert.equal(response.status(),302);assert.equal(new URL(response.headers().location,input.origin).pathname,'/admin/resturants/100');
    await form.waitFor({state:'detached'});await page.waitForURL('**/admin/resturants/100');
    assert.equal(await page.locator('form[action="'+input.origin+'/admin/resturant_reviews/94001"]').count(),0);
    assert.deepEqual(errors,initialPageErrors);
    process.stdout.write('REVIEW_DELETE_BROWSER_PROOF '+JSON.stringify({command,review:94001,originalForm:true,originalConfirmation:true,originalRedirect:true,committedResponseLost:true,originalFormRetrySameUuid:true,initialOriginalPageErrors:initialPageErrors,newPageErrors:0,force:false,domInjection:false,sourceCommit:input.sourceCommit,browser:process.env.DESKTOP_TEST_BROWSER_EXECUTABLE?'Chrome headless shell':'Windows Edge'})+'\n');
  }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
