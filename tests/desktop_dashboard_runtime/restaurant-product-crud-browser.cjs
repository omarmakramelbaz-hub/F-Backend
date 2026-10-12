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
    const page=await context.newPage(),errors=[],operations=[],formDiagnostics=[],pageErrorDetails=[];let actionStage='login';
    page.on('pageerror',error=>{
      errors.push(error.message);const detail={stage:actionStage,url:page.url(),message:error.message,stack:error.stack};pageErrorDetails.push(detail);
      process.stdout.write('RESTAURANT_PRODUCT_PAGE_ERROR '+JSON.stringify(detail)+'\n');
    });
    await page.goto(input.origin+'/admin/login');
    await page.locator('#dashboard-login-email').fill('owner@test.invalid');await page.locator('#dashboard-login-password').fill('Fixture123');
    await Promise.all([page.waitForURL('**/admin/dashboard'),page.locator('.dashboard-login-submit').click()]);
    const overlay=page.locator('.swal-overlay--show-modal');await overlay.waitFor({state:'visible'});
    assert.equal(await overlay.locator('.swal-title').textContent(),'تفعيل الصوت');
    await overlay.getByRole('button',{name:'تم',exact:true}).click();await overlay.waitFor({state:'hidden'});
    actionStage='original-show';assert.equal((await page.goto(input.origin+input.returnPath)).status(),200);
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
    const accepted=async current=>{actionStage='accepted-navigation:'+current.path;await page.waitForFunction(actual=>!actual.isConnected,current.element);
      await page.waitForURL(url=>url.pathname+url.search===input.returnPath);await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));
      actionStage='navigation-complete:'+current.path;};
    const posted=path=>[page.waitForRequest(request=>new URL(request.url()).pathname===path&&request.method()==='POST'),
      page.waitForResponse(response=>new URL(response.url()).pathname===path&&response.request().method()==='POST')];
    const transmit=async(current,action=()=>current.button.click())=>{
      actionStage='submit:'+current.path;
      const outcome=Promise.all(posted(current.path)).then(values=>({values}),error=>({error}));
      try{await action();}catch(error){process.stdout.write('RESTAURANT_PRODUCT_ACTION_FAILED '+JSON.stringify({path:current.path,error:error.message})+'\n');throw error;}
      const result=await outcome;if(result.error)throw result.error;return result.values;
    };
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
    const settledOriginalAjax=()=>page.waitForFunction(()=>window.jQuery&&window.jQuery.active===0);
    actionStage='open-add';await page.locator('#clickbtn').click();const addForm=page.locator('#addItem form[action="'+input.origin+'/admin/resturant_products"]');
    await addForm.waitFor({state:'visible'});await addForm.locator('select[name="category_id"]').selectOption('1');
    // The original category change starts several AJAX reads. Their getproducts
    // callbacks replace both modal selects, so visibility alone is not completion.
    await settledOriginalAjax();
    const subcategorySelect=addForm.locator('select[name="subcategory_id"]');await subcategorySelect.waitFor({state:'visible'});await subcategorySelect.selectOption('2');
    await settledOriginalAjax();
    const productSelect=addForm.locator('select[name="product_id"]');await productSelect.waitFor({state:'visible'});await productSelect.selectOption('1');
    await settledOriginalAjax();
    for(const field of addonNames)await addForm.locator('[name="'+field+'"]').waitFor({state:'visible'});
    let current=await associated(addForm,null);assert.equal(await addForm.getAttribute('enctype'),'multipart/form-data');
    const originalName='رنجة منتج المتصفح',description='وصف المنتج من الشاشة الأصلية';
    await fill(current,originalName,'123',description);await current.upload.setInputFiles(imageA);
    const base={added_by:'1',resturant_id:'100',category_id:'1',subcategory_id:'2',product_id:'1',product_name:originalName,product_price:'123',product_description:description,
      ...Object.fromEntries(addonNames.map((name,index)=>[name,String(index+1)]))};
    const beforeSubmit=await current.form.evaluate(actual=>({url:location.href,spaReady:window.DashboardSPA?.ready(),ajaxActive:window.jQuery?.active,
      buttonOwned:Array.from(actual.elements).filter(control=>control.tagName==='BUTTON'&&control.type==='submit').every(control=>control.form===actual&&!control.disabled),
      controls:Array.from(actual.elements).filter(control=>control.form===actual).map(control=>({name:control.name,type:control.type,required:control.required,
        value:['_token','_desktop_command'].includes(control.name)?'[bound]':control.value,
        valid:!control.willValidate||control.validity.valid,validationMessage:control.validationMessage||'',
        files:control.type==='file'?Array.from(control.files).map(file=>({name:file.name,type:file.type,size:file.size})):undefined}))}));
    process.stdout.write('RESTAURANT_PRODUCT_CREATE_BEFORE_SUBMIT '+JSON.stringify(beforeSubmit)+'\n');
    assert.equal(beforeSubmit.spaReady,true);assert.equal(beforeSubmit.ajaxActive,0);assert.equal(beforeSubmit.buttonOwned,true);
    assert.deepEqual(beforeSubmit.controls.filter(control=>!control.valid),[],'The original add form must be natively valid after its actual catalog AJAX reads settle.');
    assert.equal(await productSelect.inputValue(),'1');assert.equal(await subcategorySelect.inputValue(),'2');
    let intercept=true,resolveCommit,rejectCommit,lossRequest;
    const committed=new Promise((resolve,reject)=>{resolveCommit=resolve;rejectCommit=reject;});
    const interception=async route=>{if(intercept&&route.request().method()==='POST'){
      intercept=false;lossRequest=route.request();
      const transport={method:lossRequest.method(),resourceType:lossRequest.resourceType(),isNavigationRequest:lossRequest.isNavigationRequest(),
        contentType:lossRequest.headers()['content-type'],bodyBytes:lossRequest.postDataBuffer()?.length};
      process.stdout.write('RESTAURANT_PRODUCT_CREATE_INTERCEPTED '+JSON.stringify(transport)+'\n');
      try{
        const actual=await route.fetch({timeout:20000,maxRedirects:0,headers:{...lossRequest.headers(),'X-Fasakhansta-Desktop':input.token}});
        process.stdout.write('RESTAURANT_PRODUCT_CREATE_COMMITTED_RESPONSE '+JSON.stringify({status:actual.status(),location:actual.headers().location})+'\n');
        redirect(actual);const body=await assertBody(lossRequest,current,base,imageA);await route.abort();resolveCommit(body);
      }catch(error){
        process.stdout.write('RESTAURANT_PRODUCT_CREATE_INTERCEPTION_FAILED '+JSON.stringify({transport,error:error.message})+'\n');
        rejectCommit(error);try{await route.abort();}catch{};
      }
    }else await route.fallback();};
    await context.route(input.origin+current.path,interception);
    const lost=page.waitForEvent('requestfailed',{predicate:request=>new URL(request.url()).pathname===current.path&&request.method()==='POST'});
    const lossOutcome=Promise.all([committed,lost]).then(values=>({values}),error=>({error}));
    actionStage='create-response-loss';await current.button.click();const outcome=await lossOutcome;if(outcome.error)throw outcome.error;const [createBody]=outcome.values;await ready(current);
    const afterLoss=await page.evaluate(()=>({url:location.href,spaReady:window.DashboardSPA?.ready(),dashboardPage:Boolean(document.querySelector('[data-dashboard-page]'))}));
    assert.equal(afterLoss.url,input.origin+input.returnPath);assert.equal(afterLoss.spaReady,true);assert.equal(afterLoss.dashboardPage,true);
    await current.form.locator('[name="product_name"]').fill(originalName+' مختلف');let [sent,response]=await transmit(current);
    await assertBody(sent,current,{...base,product_name:originalName+' مختلف'},imageA);assert.equal(response.status(),409);await ready(current);
    const persisted=await context.request.get(input.origin+input.returnPath,{headers:{'X-Fasakhansta-Desktop':input.token}});assert.equal(persisted.status(),200);
    const persistedHtml=await persisted.text();assert.ok(persistedHtml.includes(originalName));assert.ok(!persistedHtml.includes(originalName+' مختلف'));
    await current.form.locator('[name="product_name"]').fill(originalName);[sent,response]=await transmit(current);
    await assertBody(sent,current,base,imageA);redirect(response);
    operations.push({route_name:'resturant_products.store',command:current.command,product_name:originalName,product_price:'123',body:createBody,originalRedirect:true});
    const lostProof={command:current.command,committedResponseLost:true,changedSameUuidStatus:409,originalFileSameUuidStatus:302,
      originalFormRetained:true,originalFileRetained:true,persistedNameUnchanged:true,resourceType:lossRequest.resourceType(),isNavigationRequest:lossRequest.isNavigationRequest(),afterLoss};
    await accepted(current);await context.unroute(input.origin+current.path,interception);
    const createdRow=()=>page.locator('tr').filter({has:page.locator('button.edit-item[data-info*="'+originalName+'"]')});
    assert.equal(await createdRow().count(),1);const info=(await createdRow().locator('button.edit-item').getAttribute('data-info')).split(',');
    const productId=Number(info[0]);assert.ok(Number.isSafeInteger(productId)&&productId>1);
    const edit=async()=>{
      actionStage='open-edit';
      await createdRow().locator('button.edit-item').click();const form=page.locator('#editItem form[action="'+input.origin+'/admin/resturant_products/update"]');
      await form.waitFor({state:'visible'});await settledOriginalAjax();await form.locator('select[name="product_id"]').waitFor({state:'visible'});
      for(const name of addonNames)await form.locator('[name="'+name+'"]').waitFor({state:'visible'});
      const result=await associated(form,'put');assert.equal(await form.locator('[name="id"]').inputValue(),String(productId));
      assert.equal(await form.locator('[name="product_name"]').inputValue(),originalName);return result;
    };
    const validOriginalEdit=async(current,stage)=>{
      const state=await current.form.evaluate(actual=>{
        const buttons=Array.from(actual.elements).filter(control=>control.tagName==='BUTTON'&&control.type==='submit'),
          footer=actual.closest('.modal')?.querySelector('.modal-footer button[type="submit"]');
        return {spaReady:window.DashboardSPA?.ready(),ajaxActive:window.jQuery?.active,buttonCount:buttons.length,
          ownButtonCount:buttons.filter(button=>button.form===actual).length,footerExists:Boolean(footer),footerOwned:footer?.form===actual,footerDescendant:actual.contains(footer),
          controls:Array.from(actual.elements).filter(control=>control.form===actual).map(control=>({name:control.name,type:control.type,
            value:['_token','_desktop_command'].includes(control.name)?'[bound]':control.value,valid:!control.willValidate||control.validity.valid,
            validationMessage:control.validationMessage||'',files:control.type==='file'?Array.from(control.files).map(file=>({name:file.name,type:file.type,size:file.size})):undefined}))};
      });
      process.stdout.write('RESTAURANT_PRODUCT_EDIT_BEFORE_SUBMIT '+JSON.stringify({stage,...state})+'\n');
      assert.equal(state.spaReady,true);assert.equal(state.ajaxActive,0);assert.equal(state.buttonCount,1);assert.equal(state.ownButtonCount,1);
      assert.equal(state.footerExists,true);assert.equal(state.footerOwned,true);assert.equal(state.footerDescendant,true);
      assert.deepEqual(state.controls.filter(control=>!control.valid),[],'The original edit controls must be natively valid.');
      assert.equal(await current.button.count(),1);assert.equal(await current.button.evaluate((button,actual)=>button.form===actual,current.element),true);
      assert.equal(await current.button.isEnabled(),true);
    };
    current=await edit();assert.notEqual(current.command,operations[0].command);await fill(current,originalName,'124',description);
    await validOriginalEdit(current,'no-image');[sent,response]=await transmit(current);
    const noImageBody=await assertBody(sent,current,{...base,product_price:'124',id:String(productId),product_id:['1','1'],subcatid:'2'},null);redirect(response);
    operations.push({route_name:'resturant_products.update',command:current.command,product_name:originalName,product_price:'124',body:noImageBody,originalRedirect:true});await accepted(current);
    current=await edit();assert.notEqual(current.command,operations[1].command);await fill(current,originalName,'125',description);await current.upload.setInputFiles(imageB);
    await validOriginalEdit(current,'replacement-image');[sent,response]=await transmit(current);
    const replacementBody=await assertBody(sent,current,{...base,product_price:'125',id:String(productId),product_id:['1','1'],subcatid:'2'},imageB);redirect(response);
    operations.push({route_name:'resturant_products.update',command:current.command,product_name:originalName,product_price:'125',body:replacementBody,originalRedirect:true});await accepted(current);
    const deleteForm=createdRow().locator('form[action="'+input.origin+'/admin/resturant_products/'+productId+'"]');
    current=await associated(deleteForm,'DELETE');assert.notEqual(current.command,operations[2].command);
    [sent,response]=await transmit(current,async()=>{await current.button.click();await overlay.waitFor({state:'visible'});
      assert.equal(await overlay.locator('.swal-title').textContent(),'هل انت متاكد من حذف هذا العنصر ؟');await overlay.getByRole('button',{name:'نعم',exact:true}).click();});
    const deletionBody=await fields(sent);
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
      initialOriginalPageErrors:initialPageErrors,pageErrorDetails,newPageErrors:0,force:false,domInjection:false,
      sourceCommit:input.sourceCommit,fixtureRevision:input.fixtureRevision,browser:'Windows Edge',browserVersion:browser.version(),browserPlatform:process.platform})+'\n');
  }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
