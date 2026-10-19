'use strict';
// Real original restaurant checkboxes, number input and their original form submission paths.
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE);
const verifyDashboardSoundControl=require('./browser-sound.cjs');
const input=JSON.parse(fs.readFileSync(0,'utf8'));
(async()=>{
  assert.equal(process.platform,'win32','Mandatory restaurant flag browser acceptance uses actual Windows Edge.');
  const browser=await chromium.launch({channel:'msedge',headless:true});
  try{
    process.once('SIGTERM',async()=>{await browser.close();process.exit(1);});
    const context=await browser.newContext({serviceWorkers:'block'});
    await context.routeWebSocket('**/*',socket=>socket.close());
    await context.route('**/*',route=>new URL(route.request().url()).origin===input.origin
      ?route.continue({headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}}):route.abort());
    const page=await context.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(input.origin+'/admin/login');
    await page.locator('#dashboard-login-email').fill('owner@test.invalid');await page.locator('#dashboard-login-password').fill('Fixture123');
    await Promise.all([page.waitForURL('**/admin/dashboard'),page.locator('.dashboard-login-submit').click()]);
    await verifyDashboardSoundControl(page);
    // The query is an original read filter. The checkbox forms have no page field.
    assert.equal((await page.goto(input.origin+'/admin/resturants?resturant_id=100')).status(),200);
    const initialPageErrors=[...errors],operations=[],retries=[],formControlDiagnostics=[];
    const fields=request=>new Response(request.postDataBuffer(),{headers:{'Content-Type':request.headers()['content-type']}}).formData();
    const formFor=path=>page.locator('form[action="'+input.origin+path+'"]');
    const ownedCommandValue=current=>current.form.evaluate(actual=>{
      const commands=Array.from(actual.elements).filter(control=>control.form===actual&&control.name==='_desktop_command');
      if(commands.length!==1)throw Error('The original form must own exactly one operation UUID.');
      return commands[0].value;
    });
    const waitForOwnedCommand=async element=>{
      await page.waitForFunction(actual=>Array.from(actual.elements)
        .filter(control=>control.form===actual&&control.name==='_desktop_command').length===1,element);
    };
    const originalForm=async(path,field)=>{
      const form=formFor(path),checkbox=form.locator('input[type="checkbox"][name="'+field+'"]');
      const element=await form.elementHandle();assert.ok(element);await waitForOwnedCommand(element);
      assert.equal(await form.getAttribute('method'),'post');
      assert.equal(await checkbox.getAttribute('onchange'),'this.form.submit()');
      // The original featured cell has an unclosed label before the sorting form.
      // Browser repair can nest that form in the DOM. Actual association and
      // native FormData determine submitted controls, not arbitrary descendants.
      const association=await form.evaluate((actual,field)=>{
        const associated=Array.from(actual.elements),own=associated.filter(control=>control.form===actual),data=new FormData(actual);
        const named=name=>own.filter(control=>control.name===name);
        const describe=control=>({name:control.name,type:control.type||null,tag:control.tagName,
          formAction:control.form?new URL(control.form.action,location.href).pathname:null,owned:control.form===actual});
        const flag=named(field);
        return {allElementsOwned:associated.every(control=>control.form===actual),tokenControls:named('_token').length,commandControls:named('_desktop_command').length,
          pageOrMethodControls:named('page').length+named('_method').length,flagControls:flag.length,
          tokenTypes:named('_token').map(control=>control.type),commandTypes:named('_desktop_command').map(control=>control.type),
          flagType:flag[0]?.type,checked:flag[0]?.checked,command:named('_desktop_command')[0]?.value,csrf:named('_token')[0]?.value,
          serializedTokenCount:data.getAll('_token').length,serializedCommandCount:data.getAll('_desktop_command').length,
          serializedCommand:data.get('_desktop_command'),serializedCsrf:data.get('_token'),serializedNames:Array.from(data.keys()).sort(),
          serializedFlag:data.getAll(field),controls:own.map(describe),descendantControls:Array.from(actual.querySelectorAll('input,select,textarea,button')).map(describe)};
      },field);
      assert.equal(association.allElementsOwned,true);assert.equal(association.tokenControls,1);assert.equal(association.commandControls,1);
      assert.equal(association.pageOrMethodControls,0);assert.equal(association.flagControls,1);
      assert.deepEqual(association.tokenTypes,['hidden']);assert.deepEqual(association.commandTypes,['hidden']);assert.equal(association.flagType,'checkbox');
      assert.equal(await checkbox.evaluate((control,actual)=>control.form===actual,element),true);
      assert.equal(association.serializedTokenCount,1);assert.equal(association.serializedCommandCount,1);
      assert.ok(typeof association.csrf==='string'&&association.csrf.length>0&&association.serializedCsrf===association.csrf,'the actual associated CSRF must serialize once and unchanged');
      const command=association.command;assert.match(command,/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i);
      assert.equal(association.serializedCommand,command);
      assert.deepEqual(association.serializedNames,['_token','_desktop_command',...(association.checked?[field]:[])].sort());
      assert.deepEqual(association.serializedFlag,association.checked?['on']:[]);
      formControlDiagnostics.push({path,field,associatedControls:association.controls,descendantControls:association.descendantControls,
        associatedCsrfCount:association.tokenControls,associatedCommandCount:association.commandControls,associatedPageOrMethodCount:association.pageOrMethodControls,
        serializedCsrfCount:association.serializedTokenCount,serializedCommandCount:association.serializedCommandCount,serializedNames:association.serializedNames});
      return {form,checkbox,command,element,csrf:association.csrf};
    };
    const assertBody=(body,current,field,checked)=>{
      assert.deepEqual(Array.from(body.keys()).sort(),['_token','_desktop_command',...(checked?[field]:[])].sort());
      assert.equal(body.getAll('_token').length,1);assert.ok(body.get('_token')===current.csrf,'the original bound CSRF must be posted unchanged');
      assert.deepEqual(body.getAll('_desktop_command'),[current.command]);assert.deepEqual(body.getAll(field),checked?['on']:[]);
    };
    const sentAndResponse=path=>[
      page.waitForRequest(request=>new URL(request.url()).pathname===path&&request.method()==='POST'),
      page.waitForResponse(response=>new URL(response.url()).pathname===path&&response.request().method()==='POST')
    ];
    const redirect=response=>{
      assert.equal(response.status(),302);
      const destination=new URL(response.headers().location,input.origin);
      assert.equal(destination.origin,input.origin);assert.equal(destination.pathname+destination.search,'/admin/resturants');
    };
    const persistedStillChecked=async(path,field)=>{
      // A separate real original GET observes the database after the409 while
      // retaining the untouched failed form and its UUID in the visible page.
      const response=await context.request.get(input.origin+'/admin/resturants',{headers:{'X-Fasakhansta-Desktop':input.token}});
      assert.equal(response.status(),200);const html=await response.text();
      const escape=value=>value.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
      const forms=[...html.matchAll(new RegExp('<form\\b[^>]*action="'+escape(input.origin+path)+'"[^>]*>([\\s\\S]*?)<\\/form>','g'))];
      assert.equal(forms.length,1);
      const inputs=[...forms[0][1].matchAll(/<input\b[^>]*>/g)].map(match=>match[0]);
      const checkbox=inputs.filter(tag=>new RegExp('\\bname="'+escape(field)+'"').test(tag));
      assert.equal(checkbox.length,1);assert.match(checkbox[0],/\bchecked(?:\s*=|\s|>)/);
    };
    const acceptedNavigation=async(previous,path,field)=>{
      // A locator would resolve the replacement form with the identical action.
      // Hold the original node and observe its detachment before locating the new form.
      await previous.element.waitForElementState('hidden');
      assert.equal(await previous.element.evaluate(element=>element.isConnected),false);
      await page.waitForURL(url=>url.pathname==='/admin/resturants'&&url.search==='');
      const current=await originalForm(path,field);assert.notEqual(current.command,previous.command);
      return current;
    };
    const originalSortForm=async()=>{
      const path='/admin/update_sorting_is_featured/100',form=formFor(path),number=form.locator('[name="sortby_is_featured"]');
      const element=await form.elementHandle();assert.ok(element);await waitForOwnedCommand(element);await number.waitFor({state:'visible'});
      assert.equal(await form.getAttribute('method'),'post');assert.equal(await number.getAttribute('type'),'number');
      assert.equal(await number.getAttribute('min'),'1');assert.equal(await number.getAttribute('step'),'1');
      const association=await form.evaluate(actual=>{
        const own=Array.from(actual.elements).filter(control=>control.form===actual),data=new FormData(actual);
        return {allElementsOwned:Array.from(actual.elements).every(control=>control.form===actual),
          csrf:data.getAll('_token'),commands:data.getAll('_desktop_command'),page:data.getAll('page'),values:data.getAll('sortby_is_featured'),
          serializedNames:Array.from(data.keys()).sort(),controls:own.map(control=>({name:control.name,type:control.type||null,tag:control.tagName,
            formAction:new URL(control.form.action,location.href).pathname,owned:control.form===actual}))};
      });
      assert.equal(association.allElementsOwned,true);assert.equal(association.csrf.length,1);assert.equal(association.commands.length,1);
      assert.deepEqual(association.page,['']);assert.equal(association.values.length,1);
      assert.deepEqual(association.serializedNames,['_desktop_command','_token','page','sortby_is_featured'].sort());
      assert.equal(await number.evaluate((control,actual)=>control.form===actual,element),true);
      const command=association.commands[0];assert.match(command,/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i);
      assert.ok(typeof association.csrf[0]==='string'&&association.csrf[0].length>0);
      formControlDiagnostics.push({path,field:'sortby_is_featured',associatedControls:association.controls,serializedNames:association.serializedNames});
      return {path,form,number,element,command,csrf:association.csrf[0],button:form.locator('button[type="submit"]')};
    };
    const assertSortBody=(body,current,value)=>{
      assert.deepEqual(Array.from(body.keys()).sort(),['_token','_desktop_command','page','sortby_is_featured'].sort());
      assert.deepEqual(body.getAll('_token'),[current.csrf]);assert.deepEqual(body.getAll('_desktop_command'),[current.command]);
      assert.deepEqual(body.getAll('page'),['']);assert.deepEqual(body.getAll('sortby_is_featured'),[value]);
    };
    const acceptedSortNavigation=async previous=>{
      await previous.element.waitForElementState('hidden');assert.equal(await previous.element.evaluate(element=>element.isConnected),false);
      await page.waitForURL(url=>url.pathname==='/admin/resturants'&&url.search==='');
      const current=await originalSortForm();assert.notEqual(current.command,previous.command);return current;
    };
    const exerciseSort=async()=>{
      let current=await originalSortForm(),intercept=true,resolveCommit,sortTransport;
      const commitPromise=new Promise(resolve=>{resolveCommit=resolve;});
      const interception=async route=>{
        if(intercept&&route.request().method()==='POST'){
          sortTransport={resourceType:route.request().resourceType(),isNavigationRequest:route.request().isNavigationRequest()};
          intercept=false;const actual=await route.fetch({maxRedirects:0,headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}});
          redirect(actual);resolveCommit(await fields(route.request()));await route.abort();
        }else await route.fallback();
      };
      await context.route(input.origin+current.path,interception);
      const lost=page.waitForEvent('requestfailed',{predicate:request=>new URL(request.url()).pathname===current.path&&request.method()==='POST'});
      await current.number.fill('7');await current.button.click();assertSortBody(await commitPromise,current,'7');await lost;
      await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));
      const afterLoss=await page.evaluate(()=>({url:location.href,spaReady:window.DashboardSPA?.ready(),dashboardPage:Boolean(document.querySelector('[data-dashboard-page]')),
        forms:Array.from(document.forms).map(form=>({path:new URL(form.action,location.href).pathname,
          ownCommands:Array.from(form.elements).filter(control=>control.form===form&&control.name==='_desktop_command').length}))}));
      process.stdout.write('RESTAURANT_SORT_LOST_TRANSPORT '+JSON.stringify({...sortTransport,afterLoss})+'\n');
      assert.equal(sortTransport.isNavigationRequest,false,'The original sort button must use the SPA fetch, preserving the original form after an uncertain result.');
      assert.equal(sortTransport.resourceType,'fetch');assert.equal(afterLoss.spaReady,true);assert.equal(afterLoss.dashboardPage,true);
      assert.equal(await current.element.evaluate(element=>element.isConnected),true,'The original sorting form must remain connected after the failed fetch.');
      assert.equal(await ownedCommandValue(current),current.command);
      let [sentPromise,responsePromise]=sentAndResponse(current.path);await current.number.fill('8');await current.button.click();
      let sent=await sentPromise,response=await responsePromise;assertSortBody(await fields(sent),current,'8');assert.equal(response.status(),409);
      await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));
      assert.equal(await ownedCommandValue(current),current.command);
      const persisted=await context.request.get(input.origin+'/admin/resturants',{headers:{'X-Fasakhansta-Desktop':input.token}});
      assert.equal(persisted.status(),200);const html=await persisted.text();
      const sortForms=[...html.matchAll(/<form\b[^>]*action="[^"]*\/admin\/update_sorting_is_featured\/100"[^>]*>([\s\S]*?)<\/form>/g)];
      assert.equal(sortForms.length,1);assert.match(sortForms[0][1],/<input\b[^>]*name="sortby_is_featured"[^>]*value="7"/);
      [sentPromise,responsePromise]=sentAndResponse(current.path);await current.number.fill('7');await current.button.click();
      sent=await sentPromise;response=await responsePromise;assertSortBody(await fields(sent),current,'7');redirect(response);
      operations.push({route_name:'update_sorting_is_featured',field:'sortby_is_featured',value:7,command:current.command,originalRedirect:true});
      const retry={field:'sortby_is_featured',command:current.command,committedResponseLost:true,changedSameUuidStatus:409,
        persistedAfterChangedStillSeven:true,originalValueSameUuidStatus:302,actualNumberAndSubmitControls:true,
        isNavigationRequest:sortTransport.isNavigationRequest,resourceType:sortTransport.resourceType,originalFormRetainedAfterLoss:true};
      current=await acceptedSortNavigation(current);assert.equal(await current.number.inputValue(),'7');
      [sentPromise,responsePromise]=sentAndResponse(current.path);await current.number.fill('8');await current.button.click();
      sent=await sentPromise;response=await responsePromise;assertSortBody(await fields(sent),current,'8');redirect(response);
      operations.push({route_name:'update_sorting_is_featured',field:'sortby_is_featured',value:8,command:current.command,originalRedirect:true});
      current=await acceptedSortNavigation(current);assert.equal(await current.number.inputValue(),'8');
      await context.unroute(input.origin+current.path,interception);return retry;
    };
    let sortingRetry;
    for(const [route,field] of [['resturantchangeStatus','is_featured'],['resturantchangeUnderContract','under_contract']]){
      const path='/admin/'+route+'/100';let current=await originalForm(path,field);
      assert.equal(await current.checkbox.isChecked(),false);
      let intercept=true,resolveCommit;
      const commitPromise=new Promise(resolve=>{resolveCommit=resolve;});
      const interception=async route=>{
        if(intercept&&route.request().method()==='POST'){
          intercept=false;const actual=await route.fetch({maxRedirects:0,headers:{...route.request().headers(),'X-Fasakhansta-Desktop':input.token}});
          redirect(actual);resolveCommit(await fields(route.request()));await route.abort();
        }else await route.fallback();
      };
      await context.route(input.origin+path,interception);
      const lost=page.waitForEvent('requestfailed',{predicate:request=>new URL(request.url()).pathname===path&&request.method()==='POST'});
      await current.checkbox.check();const firstBody=await commitPromise;await lost;
      assertBody(firstBody,current,field,true);
      await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));
      assert.equal(await current.checkbox.isChecked(),true);assert.equal(await ownedCommandValue(current),current.command);
      // The untouched automatic checkbox has no retry button. Its opposite value
      // must fail under the saved UUID, then returning to the original value retries it.
      let [sentPromise,responsePromise]=sentAndResponse(path);
      await current.checkbox.uncheck();let sent=await sentPromise,response=await responsePromise,body=await fields(sent);
      assertBody(body,current,field,false);assert.equal(response.status(),409);
      await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));
      assert.equal(await ownedCommandValue(current),current.command);
      assert.equal(await current.checkbox.isChecked(),false);
      await persistedStillChecked(path,field);
      [sentPromise,responsePromise]=sentAndResponse(path);
      await current.checkbox.check();sent=await sentPromise;response=await responsePromise;body=await fields(sent);
      assertBody(body,current,field,true);redirect(response);
      operations.push({route_name:route==='resturantchangeStatus'?'resturants.changeStatus':'resturants.changeUnderContract',field,checked:true,command:current.command,originalRedirect:true});
      retries.push({field,command:current.command,committedResponseLost:true,oppositeSameUuidStatus:409,persistedAfterOppositeStillChecked:true,originalValueSameUuidStatus:302,actualCheckboxControls:true});
      current=await acceptedNavigation(current,path,field);assert.equal(await current.checkbox.isChecked(),true);
      if(field==='is_featured'){
        sortingRetry=await exerciseSort();current=await originalForm(path,field);assert.equal(await current.checkbox.isChecked(),true);
      }
      [sentPromise,responsePromise]=sentAndResponse(path);
      await current.checkbox.uncheck();sent=await sentPromise;response=await responsePromise;body=await fields(sent);
      assertBody(body,current,field,false);redirect(response);
      operations.push({route_name:route==='resturantchangeStatus'?'resturants.changeStatus':'resturants.changeUnderContract',field,checked:false,command:current.command,originalRedirect:true});
      current=await acceptedNavigation(current,path,field);assert.equal(await current.checkbox.isChecked(),false);
      await context.unroute(input.origin+path,interception);
    }
    assert.equal(new Set(operations.map(operation=>operation.command)).size,6);
    assert.deepEqual(errors,initialPageErrors);
    process.stdout.write('RESTAURANT_FLAGS_BROWSER_PROOF '+JSON.stringify({restaurant:100,operations,retries,sortingRetry,originalForm:true,originalCheckboxOnchange:true,
      checkedAndUncheckedBothFlags:true,committedResponsesLost:2,oppositeSameUuidRejected:2,originalValueRetrySameUuid:2,
      originalFeaturedOrderingSubmit:true,featuredOrderingCommands:2,featuredOrderingFinalValue:8,
      freshUuidOnlyAfterAcceptedNavigation:true,originalQueryReadPreserved:true,originalCheckboxFormsHaveNoPageField:true,originalSortingHasPageField:true,
      actualAssociatedFormControls:true,exactSerializedCsrfAndUuid:true,formControlDiagnostics,
      initialOriginalPageErrors:initialPageErrors,newPageErrors:0,force:false,domInjection:false,
      sourceCommit:input.sourceCommit,fixtureRevision:input.fixtureRevision,browser:'Windows Edge',browserVersion:browser.version(),browserPlatform:process.platform})+'\n');
  }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
