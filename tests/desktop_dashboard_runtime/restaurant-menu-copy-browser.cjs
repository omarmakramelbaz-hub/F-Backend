'use strict';
// Actual original parent-menu checkboxes, multipart submission and image HTTP responses.
const fs=require('node:fs'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE);
const input=JSON.parse(fs.readFileSync(0,'utf8'));
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
(async()=>{
  assert.equal(process.platform,'win32','Mandatory menu-copy acceptance uses actual Windows Edge.');
  const browser=await chromium.launch({channel:'msedge',headless:true});
  try{
    process.once('SIGTERM',async()=>{await browser.close();process.exit(1);});
    const context=await browser.newContext({serviceWorkers:'block'});
    await context.routeWebSocket('**/*',socket=>socket.close());
    await context.route('**/*',route=>new URL(route.request().url()).origin===input.origin
      ?route.continue({headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}}):route.abort());
    const page=await context.newPage(),errors=[],operations=[],formDiagnostics=[],pageErrorDetails=[];let stage='login';
    page.on('pageerror',error=>{errors.push(error.message);const detail={stage,url:page.url(),message:error.message,stack:error.stack};pageErrorDetails.push(detail);
      process.stdout.write('RESTAURANT_MENU_COPY_PAGE_ERROR '+JSON.stringify(detail)+'\n');});
    await page.goto(input.origin+'/admin/login');await page.locator('#dashboard-login-email').fill('owner@test.invalid');await page.locator('#dashboard-login-password').fill('Fixture123');
    await Promise.all([page.waitForURL('**/admin/resturants'),page.locator('.dashboard-login-submit').click()]);
    const overlay=page.locator('.swal-overlay--show-modal');await overlay.waitFor({state:'visible'});assert.equal(await overlay.locator('.swal-title').textContent(),'تفعيل الصوت');
    await overlay.getByRole('button',{name:'تم',exact:true}).click();await overlay.waitFor({state:'hidden'});
    stage='original-show';assert.equal((await page.goto(input.origin+input.returnPath)).status(),200);await page.waitForLoadState('networkidle');const initialPageErrors=[...errors];
    const path='/admin/resturant/copy/items',fields=request=>new Response(request.postDataBuffer(),{headers:{'Content-Type':request.headers()['content-type']}}).formData();
    const originalForm=async()=>{
      stage='open-original-copy';await page.locator('#clickbtn').click();await page.locator('#chooseAddWay').waitFor({state:'visible'});
      await page.locator('#chooseAddWay button[data-bs-target="#copyMenue"]').click();
      const form=page.locator('#copyMenue form[action="'+input.origin+path+'"]');await form.waitFor({state:'visible'});
      const element=await form.elementHandle();await page.waitForFunction(actual=>Array.from(actual.elements).filter(control=>control.form===actual&&control.name==='_desktop_command').length===1,element);
      const state=await form.evaluate(actual=>{const own=Array.from(actual.elements).filter(control=>control.form===actual),data=new FormData(actual);
        return {method:actual.method,enctype:actual.enctype,allOwned:Array.from(actual.elements).every(control=>control.form===actual),
          csrf:data.getAll('_token'),commands:data.getAll('_desktop_command'),target:data.getAll('resturant_id'),overrides:data.getAll('_method'),
          checkboxValues:own.filter(control=>control.name==='restraunt_product[]').map(control=>control.value),
          checkboxTypes:own.filter(control=>control.name==='restraunt_product[]').map(control=>control.type),
          submitters:own.filter(control=>control.type==='submit').map(control=>({owned:control.form===actual,disabled:control.disabled,name:control.name})),
          uploadCount:own.filter(control=>control.type==='file').length};});
      assert.equal(state.method,'post');assert.equal(state.enctype,'multipart/form-data');assert.equal(state.allOwned,true);
      assert.equal(state.csrf.length,1);assert.ok(state.csrf[0]);assert.equal(state.commands.length,1);assert.match(state.commands[0],/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i);
      assert.deepEqual(state.target,['100']);assert.deepEqual(state.overrides,[]);assert.deepEqual(state.checkboxValues,input.sources.map(String));assert.deepEqual(state.checkboxTypes,['checkbox','checkbox','checkbox','checkbox']);
      assert.deepEqual(state.submitters,[{owned:true,disabled:false,name:''}]);assert.equal(state.uploadCount,0);formDiagnostics.push(state);
      return {form,element,command:state.commands[0],csrf:state.csrf[0],button:form.locator('button[type="submit"]')};
    };
    const choose=async(current,ids)=>{for(const id of input.sources){const checkbox=current.form.locator('input[type="checkbox"][name="restraunt_product[]"][value="'+id+'"]');
      if(ids.includes(id))await checkbox.check();else await checkbox.uncheck();}};
    const body=async(request,current,ids)=>{const data=await fields(request);assert.equal(request.resourceType(),'fetch');assert.equal(request.isNavigationRequest(),false);
      assert.deepEqual(Array.from(data.keys()).sort(),['_desktop_command','_token','resturant_id',...ids.map(()=> 'restraunt_product[]')].sort());
      assert.deepEqual(data.getAll('_token'),[current.csrf]);assert.deepEqual(data.getAll('_desktop_command'),[current.command]);assert.deepEqual(data.getAll('resturant_id'),['100']);assert.deepEqual(data.getAll('restraunt_product[]'),ids.map(String));
      return {names:Array.from(data.keys()).sort(),selection:ids};};
    const redirect=response=>{assert.equal(response.status(),302);const destination=new URL(response.headers().location,input.origin);assert.equal(destination.origin,input.origin);assert.equal(destination.pathname+destination.search,input.returnPath);};
    const ready=async current=>{await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));
      assert.equal(await current.element.evaluate(actual=>actual.isConnected),true);assert.equal(await current.button.isEnabled(),true);
      assert.equal(await current.form.evaluate(actual=>Array.from(actual.elements).find(control=>control.form===actual&&control.name==='_desktop_command').value),current.command);};
    const sent=async current=>{stage='original-submit';const pending=Promise.all([
      page.waitForRequest(request=>new URL(request.url()).pathname===path&&request.method()==='POST'),
      page.waitForResponse(response=>new URL(response.url()).pathname===path&&response.request().method()==='POST')]).then(values=>({values}),error=>({error}));
      await current.button.click();const result=await pending;if(result.error)throw result.error;return result.values;};
    const accepted=async current=>{stage='accepted-navigation';await page.waitForFunction(actual=>!actual.isConnected,current.element);
      await page.waitForURL(url=>url.pathname+url.search===input.returnPath);await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));};
    let current=await originalForm();await choose(current,input.sources);let intercept=true,resolveCommit,rejectCommit,lossRequest;
    const committed=new Promise((resolve,reject)=>{resolveCommit=resolve;rejectCommit=reject;});
    const interception=async route=>{if(intercept&&route.request().method()==='POST'){
      intercept=false;lossRequest=route.request();try{const actual=await route.fetch({timeout:20000,maxRedirects:0,headers:{...lossRequest.headers(),'X-Fasakhansta-Desktop':input.token}});
        process.stdout.write('RESTAURANT_MENU_COPY_COMMITTED_RESPONSE '+JSON.stringify({status:actual.status(),location:actual.headers().location,resourceType:lossRequest.resourceType(),isNavigationRequest:lossRequest.isNavigationRequest()})+'\n');
        redirect(actual);const serialized=await body(lossRequest,current,input.sources);await route.abort();resolveCommit(serialized);
      }catch(error){rejectCommit(error);try{await route.abort();}catch{}}
    }else await route.fallback();};
    await context.route(input.origin+path,interception);const failed=page.waitForEvent('requestfailed',{predicate:request=>new URL(request.url()).pathname===path&&request.method()==='POST'});
    const outcome=Promise.all([committed,failed]).then(values=>({values}),error=>({error}));stage='copy-response-loss';await current.button.click();const loss=await outcome;if(loss.error)throw loss.error;await ready(current);
    const changed=input.sources.slice(0,-1);await choose(current,changed);let [request,response]=await sent(current);await body(request,current,changed);assert.equal(response.status(),409);await ready(current);
    await choose(current,input.sources);[request,response]=await sent(current);const firstBody=await body(request,current,input.sources);redirect(response);
    operations.push({command:current.command,route_name:'copy_menu',selection:input.sources,body:firstBody,originalRedirect:true});
    const retry={command:current.command,committedResponseLost:true,changedSameUuidStatus:409,originalSelectionSameUuidStatus:302,originalFormRetained:true,
      originalCheckboxes:true,resourceType:lossRequest.resourceType(),isNavigationRequest:lossRequest.isNavigationRequest()};await accepted(current);await context.unroute(input.origin+path,interception);
    const visibleIds=[];for(const name of input.clonedNames){
      const actual=page.locator('button.edit-item[data-info*="'+name+'"]');assert.equal(await actual.count(),1);visibleIds.push(Number((await actual.getAttribute('data-info')).split(',')[0]));}
    assert.equal(new Set(visibleIds).size,3);const imageResponses=[];
    for(let index=0;index<2;index++){const info=(await page.locator('button.edit-item[data-info*="'+input.clonedNames[index]+'"]') .getAttribute('data-info')).split(',');
      const url=new URL(info[13]);assert.equal(url.origin,input.origin);assert.match(url.pathname,/^\/storage\/products\/[1-9][0-9]*\//);
      const image=await context.request.get(url.href,{headers:{'X-Fasakhansta-Desktop':input.token}});assert.equal(image.status(),200);assert.equal(sha(await image.body()),input.imageHashes[index]);imageResponses.push({url:url.pathname,sha256:input.imageHashes[index],status:200});}
    current=await originalForm();assert.notEqual(current.command,operations[0].command);await choose(current,input.sources);[request,response]=await sent(current);const secondBody=await body(request,current,input.sources);redirect(response);
    operations.push({command:current.command,route_name:'copy_menu',selection:input.sources,body:secondBody,originalRedirect:true});await accepted(current);
    for(let index=0;index<input.clonedNames.length;index++){const actual=page.locator('button.edit-item[data-info*="'+input.clonedNames[index]+'"]');assert.equal(await actual.count(),1);assert.equal(Number((await actual.getAttribute('data-info')).split(',')[0]),visibleIds[index]);}
    assert.deepEqual(errors,initialPageErrors);
    process.stdout.write('RESTAURANT_MENU_COPY_BROWSER_PROOF '+JSON.stringify({restaurant:100,parent:101,operations,retry,visibleIds,imageResponses,formDiagnostics,
      originalForm:true,originalChooseAddWay:true,originalCopyModal:true,actualAssociatedFormControls:true,exactSerializedCsrfAndUuid:true,
      originalCheckboxOrder:true,fullImagesCopied:true,existingPriceOnly:true,noImageClone:true,freshUuidOnlyAfterAcceptedNavigation:true,
      initialOriginalPageErrors:initialPageErrors,pageErrorDetails,newPageErrors:0,force:false,domInjection:false,
      sourceCommit:input.sourceCommit,fixtureRevision:input.fixtureRevision,browser:'Windows Edge',browserVersion:browser.version(),browserPlatform:process.platform})+'\n');
  }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
