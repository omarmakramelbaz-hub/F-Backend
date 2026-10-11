'use strict';
// Real original restaurant product modals, multipart uploads and confirmed delete controls.
const fs=require('node:fs'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE);
const input=JSON.parse(fs.readFileSync(0,'utf8'));
const addonNames=['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'];
const imageA={name:'رنجة محلية.png',mimeType:'image/png',buffer:Buffer.from(input.imageA,'base64')};
const imageB={name:'صورة بديلة.png',mimeType:'image/png',buffer:Buffer.from(input.imageB,'base64')};
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
(async()=>{
  assert.equal(process.platform,'win32','Mandatory restaurant product acceptance uses actual Windows Edge.');
  const browser=await chromium.launch({channel:'msedge',headless:true});
  try{
    process.once('SIGTERM',async()=>{await browser.close();process.exit(1);});
    const context=await browser.newContext({serviceWorkers:'block'});
    await context.routeWebSocket('**/*',socket=>socket.close());
    await context.route('**/*',route=>new URL(route.request().url()).origin===input.origin
      ?route.continue({headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}}):route.abort());
    const page=await context.newPage(),errors=[],operations=[],formDiagnostics=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.goto(input.origin+'/admin/login');
    await page.locator('#dashboard-login-email').fill('owner@test.invalid');await page.locator('#dashboard-login-password').fill('Fixture123');
    await Promise.all([page.waitForURL('**/admin/dashboard'),page.locator('.dashboard-login-submit').click()]);
    const overlay=page.locator('.swal-overlay--show-modal');await overlay.waitFor({state:'visible'});
    assert.equal(await overlay.locator('.swal-title').textContent(),'تفعيل الصوت');
    await overlay.getByRole('button',{name:'تم',exact:true}).click();await overlay.waitFor({state:'hidden'});
    assert.equal((await page.goto(input.origin+input.returnPath)).status(),200);
    await page.waitForLoadState('networkidle');const initialPageErrors=[...errors];
    const fields=request=>new Response(request.postDataBuffer(),{headers:{'Content-Type':request.headers()['content-type']}}).formData();
    const associated=async(form,method)=>{
      const element=await form.elementHandle();assert.ok(element);
      await page.waitForFunction(actual=>Array.from(actual.elements).filter(control=>control.form===actual&&control.name==='_desktop_command').length===1,element);
      const state=await form.evaluate(actual=>{
        const own=Array.from(actual.elements).filter(control=>control.form===actual),data=new FormData(actual);
        return {allElementsOwned:Array.from(actual.elements).every(control=>control.form===actual),method:actual.method,enctype:actual.enctype,path:new URL(actual.action,location.href).pathname,
          commands:data.getAll('_desktop_command'),csrf:data.getAll('_token'),methods:data.getAll('_method'),
          products:data.getAll('product_id'),serializedNames:Array.from(data.keys()).sort(),
          controls:own.map(control=>({name:control.name,type:control.type||null,tag:control.tagName,owned:control.form===actual}))};
      });
      assert.equal(state.allElementsOwned,true);assert.equal(state.method,'post');assert.equal(state.csrf.length,1);assert.ok(state.csrf[0]);assert.equal(state.commands.length,1);
      assert.match(state.commands[0],/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i);
      assert.deepEqual(state.methods,method?[method]:[]);formDiagnostics.push(state);
      return {form,element,path:state.path,command:state.commands[0],csrf:state.csrf[0],method,
        button:form.locator('button[type="submit"]'),upload:form.locator('input[type="file"][name="product_image"]')};
    };
    const currentCommand=current=>current.form.evaluate(actual=>{
      const own=Array.from(actual.elements).filter(control=>control.form===actual&&control.name==='_desktop_command');
      if(own.length!==1)throw Error('An actual original form must own exactly one UUID.');return own[0].value;
    });
    const ready=async current=>{await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));
      assert.equal(await current.element.evaluate(actual=>actual.isConnected),true);assert.equal(await currentCommand(current),current.command);
      assert.equal(await current.button.isEnabled(),true,'An uncertain original submit must leave the real retry control enabled.');};
    const redirect=response=>{assert.equal(response.status(),302);const target=new URL(response.headers().location,input.origin);
      assert.equal(target.origin,input.origin);assert.equal(target.pathname+target.search,input.returnPath);};
    const accepted=async current=>{await page.waitForFunction(actual=>!actual.isConnected,current.element);
      await page.waitForURL(url=>url.pathname+url.search===input.returnPath);await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));};
    const posted=path=>[page.waitForRequest(request=>new URL(request.url()).pathname===path&&request.method()==='POST'),
      page.waitForResponse(response=>new URL(response.url()).pathname===path&&response.request().method()==='POST')];
    const assertBody=async(request,current,expected,file)=>{
      const body=await fields(request);assert.equal(request.resourceType(),'fetch');assert.equal(request.isNavigationRequest(),false);
      assert.deepEqual(body.getAll('_token'),[current.csrf]);assert.deepEqual(body.getAll('_desktop_command'),[current.command]);
      assert.deepEqual(body.getAll('_method'),current.method?[current.method]:[]);
      for(const [name,value]of Object.entries(expected))assert.deepEqual(body.getAll(name),Array.isArray(value)?value:[value],name);
      const files=body.getAll('product_image');assert.equal(files.length,1);
      if(file){assert.equal(files[0].name,file.name);assert.equal(files[0].type,file.mimeType);assert.equal(sha(Buffer.from(await files[0].arrayBuffer())),sha(file.buffer));}
      else{assert.equal(files[0].size,0);assert.equal(files[0].name,'');}
      return {names:Array.from(body.keys()).sort(),productIds:body.getAll('product_id'),file:file?{name:file.name,mime:file.mimeType,sha256:sha(file.buffer)}:null};
    };
    const fill=async(current,name,price,description)=>{
      await current.form.locator('[name="product_name"]').fill(name);await current.form.locator('[name="product_price"]').fill(price);
      await current.form.locator('[name="product_description"]').fill(description);
      for(let index=0;index<addonNames.length;index++)await current.form.locator('[name="'+addonNames[index]+'"]').fill(String(index+1));
    };
    await page.locator('#clickbtn').click();const addForm=page.locator('#addItem form[action="'+input.origin+'/admin/resturant_products"]');
    await addForm.waitFor({state:'visible'});await addForm.locator('select[name="category_id"]').selectOption('1');
    const subcategorySelect=addForm.locator('select[name="subcategory_id"]');await subcategorySelect.waitFor({state:'visible'});await subcategorySelect.selectOption('2');
    const productSelect=addForm.locator('select[name="product_id"]');await productSelect.waitFor({state:'visible'});await productSelect.selectOption('1');
    for(const field of addonNames)await addForm.locator('[name="'+field+'"]').waitFor({state:'visible'});
    let current=await associated(addForm,null);assert.equal(await addForm.getAttribute('enctype'),'multipart/form-data');
    const originalName='رنجة منتج المتصفح',description='وصف المنتج من الشاشة الأصلية';
    await fill(current,originalName,'123',description);await current.upload.setInputFiles(imageA);
    const base={added_by:'1',resturant_id:'100',category_id:'1',subcategory_id:'2',product_id:'1',product_name:originalName,product_price:'123',product_description:description,
      ...Object.fromEntries(addonNames.map((name,index)=>[name,String(index+1)]))};
    let intercept=true,resolveCommit,lossRequest;
    const committed=new Promise(resolve=>{resolveCommit=resolve;});
    const interception=async route=>{if(intercept&&route.request().method()==='POST'){
      intercept=false;lossRequest=route.request();const actual=await route.fetch({maxRedirects:0,headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}});
      redirect(actual);resolveCommit(await assertBody(route.request(),current,base,imageA));await route.abort();
    }else await route.fallback();};
    await context.route(input.origin+current.path,interception);
    const lost=page.waitForEvent('requestfailed',{predicate:request=>new URL(request.url()).pathname===current.path&&request.method()==='POST'});
    await current.button.click();const createBody=await committed;await lost;await ready(current);
    const afterLoss=await page.evaluate(()=>({url:location.href,spaReady:window.DashboardSPA?.ready(),dashboardPage:Boolean(document.querySelector('[data-dashboard-page]'))}));
    assert.equal(afterLoss.url,input.origin+input.returnPath);assert.equal(afterLoss.spaReady,true);assert.equal(afterLoss.dashboardPage,true);
    let [sentPromise,responsePromise]=posted(current.path);await current.form.locator('[name="product_name"]').fill(originalName+' مختلف');await current.button.click();
    let sent=await sentPromise,response=await responsePromise;await assertBody(sent,current,{...base,product_name:originalName+' مختلف'},imageA);assert.equal(response.status(),409);await ready(current);
    const persisted=await context.request.get(input.origin+input.returnPath,{headers:{'X-Fasakhansta-Desktop':input.token}});assert.equal(persisted.status(),200);
    const persistedHtml=await persisted.text();assert.ok(persistedHtml.includes(originalName));assert.ok(!persistedHtml.includes(originalName+' مختلف'));
    [sentPromise,responsePromise]=posted(current.path);await current.form.locator('[name="product_name"]').fill(originalName);await current.button.click();
    sent=await sentPromise;response=await responsePromise;await assertBody(sent,current,base,imageA);redirect(response);
    operations.push({route_name:'resturant_products.store',command:current.command,product_name:originalName,product_price:'123',body:createBody,originalRedirect:true});
    const lostProof={command:current.command,committedResponseLost:true,changedSameUuidStatus:409,originalFileSameUuidStatus:302,
      originalFormRetained:true,originalFileRetained:true,persistedNameUnchanged:true,resourceType:lossRequest.resourceType(),isNavigationRequest:lossRequest.isNavigationRequest(),afterLoss};
    await accepted(current);await context.unroute(input.origin+current.path,interception);
    const createdRow=()=>page.locator('tr').filter({has:page.locator('button.edit-item[data-info*="'+originalName+'"]')});
    assert.equal(await createdRow().count(),1);const info=(await createdRow().locator('button.edit-item').getAttribute('data-info')).split(',');
    const productId=Number(info[0]);assert.ok(Number.isSafeInteger(productId)&&productId>1);
    const edit=async()=>{
      await createdRow().locator('button.edit-item').click();const form=page.locator('#editItem form[action="'+input.origin+'/admin/resturant_products/update"]');
      await form.waitFor({state:'visible'});await form.locator('select[name="product_id"]').waitFor({state:'visible'});
      for(const name of addonNames)await form.locator('[name="'+name+'"]').waitFor({state:'visible'});
      const result=await associated(form,'put');assert.equal(await form.locator('[name="id"]').inputValue(),String(productId));
      assert.equal(await form.locator('[name="product_name"]').inputValue(),originalName);return result;
    };
    current=await edit();assert.notEqual(current.command,operations[0].command);await fill(current,originalName,'124',description);
    [sentPromise,responsePromise]=posted(current.path);await current.button.click();sent=await sentPromise;response=await responsePromise;
    const noImageBody=await assertBody(sent,current,{...base,product_price:'124',id:String(productId),product_id:['1','1'],subcatid:'2'},null);redirect(response);
    operations.push({route_name:'resturant_products.update',command:current.command,product_name:originalName,product_price:'124',body:noImageBody,originalRedirect:true});await accepted(current);
    current=await edit();assert.notEqual(current.command,operations[1].command);await fill(current,originalName,'125',description);await current.upload.setInputFiles(imageB);
    [sentPromise,responsePromise]=posted(current.path);await current.button.click();sent=await sentPromise;response=await responsePromise;
    const replacementBody=await assertBody(sent,current,{...base,product_price:'125',id:String(productId),product_id:['1','1'],subcatid:'2'},imageB);redirect(response);
    operations.push({route_name:'resturant_products.update',command:current.command,product_name:originalName,product_price:'125',body:replacementBody,originalRedirect:true});await accepted(current);
    const deleteForm=createdRow().locator('form[action="'+input.origin+'/admin/resturant_products/'+productId+'"]');
    current=await associated(deleteForm,'DELETE');assert.notEqual(current.command,operations[2].command);
    [sentPromise,responsePromise]=posted(current.path);await current.button.click();await overlay.waitFor({state:'visible'});
    assert.equal(await overlay.locator('.swal-title').textContent(),'هل انت متاكد من حذف هذا العنصر ؟');await overlay.getByRole('button',{name:'نعم',exact:true}).click();
    sent=await sentPromise;response=await responsePromise;const deletionBody=await fields(sent);
    assert.deepEqual(Array.from(deletionBody.keys()).sort(),['_desktop_command','_method','_token'].sort());
    assert.deepEqual(deletionBody.getAll('_desktop_command'),[current.command]);assert.deepEqual(deletionBody.getAll('_token'),[current.csrf]);assert.deepEqual(deletionBody.getAll('_method'),['DELETE']);
    assert.equal(sent.resourceType(),'fetch');assert.equal(sent.isNavigationRequest(),false);redirect(response);
    operations.push({route_name:'resturant_products.destroy',command:current.command,body:{names:Array.from(deletionBody.keys()).sort()},originalRedirect:true});await accepted(current);
    assert.equal(await createdRow().count(),0);assert.equal(new Set(operations.map(operation=>operation.command)).size,4);
    assert.deepEqual(errors,initialPageErrors);
    process.stdout.write('RESTAURANT_PRODUCT_CRUD_BROWSER_PROOF '+JSON.stringify({restaurant:100,productId,operations,lostProof,formDiagnostics,
      originalForm:true,actualAssociatedFormControls:true,exactSerializedCsrfAndUuid:true,retainedFileAfterLoss:true,
      changedSameUuid409:true,originalRetry302:true,createUpdateReplaceDelete:true,
      originalAddModal:true,originalEditModal:true,originalConfirmedDelete:true,originalMultipartFiles:true,originalAjaxCatalogAndAddons:true,
      noImageUpdate:true,replacementImageUpdate:true,originalQueryReadPreserved:true,freshUuidOnlyAfterAcceptedNavigation:true,
      initialOriginalPageErrors:initialPageErrors,newPageErrors:0,force:false,domInjection:false,
      sourceCommit:input.sourceCommit,fixtureRevision:input.fixtureRevision,browser:'Windows Edge',browserVersion:browser.version(),browserPlatform:process.platform})+'\n');
  }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
