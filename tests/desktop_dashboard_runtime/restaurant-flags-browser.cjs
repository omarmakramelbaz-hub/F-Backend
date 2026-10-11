'use strict';
// Real original restaurant checkboxes and their original onchange/form.submit path.
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE);
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
    const overlay=page.locator('.swal-overlay--show-modal');await overlay.waitFor({state:'visible'});
    assert.equal(await overlay.locator('.swal-title').textContent(),'تفعيل الصوت');
    await overlay.getByRole('button',{name:'تم',exact:true}).click();await overlay.waitFor({state:'hidden'});
    // The query is an original read filter. The checkbox forms have no page field.
    assert.equal((await page.goto(input.origin+'/admin/resturants?resturant_id=100')).status(),200);
    const initialPageErrors=[...errors],operations=[],retries=[],formControlDiagnostics=[];
    const fields=request=>new Response(request.postDataBuffer(),{headers:{'Content-Type':request.headers()['content-type']}}).formData();
    const formFor=path=>page.locator('form[action="'+input.origin+path+'"]');
    const originalForm=async(path,field)=>{
      const form=formFor(path),checkbox=form.locator('input[type="checkbox"][name="'+field+'"]');
      await form.locator('[name="_desktop_command"]').waitFor({state:'attached'});
      assert.equal(await form.getAttribute('method'),'post');
      assert.equal(await checkbox.getAttribute('onchange'),'this.form.submit()');
      const element=await form.elementHandle();assert.ok(element);
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
      assert.equal(await current.checkbox.isChecked(),true);assert.equal(await current.form.locator('[name="_desktop_command"]').inputValue(),current.command);
      // The untouched automatic checkbox has no retry button. Its opposite value
      // must fail under the saved UUID, then returning to the original value retries it.
      let [sentPromise,responsePromise]=sentAndResponse(path);
      await current.checkbox.uncheck();let sent=await sentPromise,response=await responsePromise,body=await fields(sent);
      assertBody(body,current,field,false);assert.equal(response.status(),409);
      await page.waitForFunction(()=>!document.body.classList.contains('dashboard-spa-loading'));
      assert.equal(await current.form.locator('[name="_desktop_command"]').inputValue(),current.command);
      assert.equal(await current.checkbox.isChecked(),false);
      await persistedStillChecked(path,field);
      [sentPromise,responsePromise]=sentAndResponse(path);
      await current.checkbox.check();sent=await sentPromise;response=await responsePromise;body=await fields(sent);
      assertBody(body,current,field,true);redirect(response);
      operations.push({route_name:route==='resturantchangeStatus'?'resturants.changeStatus':'resturants.changeUnderContract',field,checked:true,command:current.command,originalRedirect:true});
      retries.push({field,command:current.command,committedResponseLost:true,oppositeSameUuidStatus:409,persistedAfterOppositeStillChecked:true,originalValueSameUuidStatus:302,actualCheckboxControls:true});
      current=await acceptedNavigation(current,path,field);assert.equal(await current.checkbox.isChecked(),true);
      [sentPromise,responsePromise]=sentAndResponse(path);
      await current.checkbox.uncheck();sent=await sentPromise;response=await responsePromise;body=await fields(sent);
      assertBody(body,current,field,false);redirect(response);
      operations.push({route_name:route==='resturantchangeStatus'?'resturants.changeStatus':'resturants.changeUnderContract',field,checked:false,command:current.command,originalRedirect:true});
      current=await acceptedNavigation(current,path,field);assert.equal(await current.checkbox.isChecked(),false);
      await context.unroute(input.origin+path,interception);
    }
    assert.equal(new Set(operations.map(operation=>operation.command)).size,4);
    assert.deepEqual(errors,initialPageErrors);
    process.stdout.write('RESTAURANT_FLAGS_BROWSER_PROOF '+JSON.stringify({restaurant:100,operations,retries,originalForm:true,originalCheckboxOnchange:true,
      checkedAndUncheckedBothFlags:true,committedResponsesLost:2,oppositeSameUuidRejected:2,originalValueRetrySameUuid:2,
      freshUuidOnlyAfterAcceptedNavigation:true,originalQueryReadPreserved:true,originalFormsHaveNoPageField:true,
      actualAssociatedFormControls:true,exactSerializedCsrfAndUuid:true,formControlDiagnostics,
      initialOriginalPageErrors:initialPageErrors,newPageErrors:0,force:false,domInjection:false,
      sourceCommit:input.sourceCommit,fixtureRevision:input.fixtureRevision,browser:'Windows Edge',browserVersion:browser.version(),browserPlatform:process.platform})+'\n');
  }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
