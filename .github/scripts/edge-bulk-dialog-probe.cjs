'use strict';
// Narrow browser scheduling diagnostic. HTTP and commits are simulated, NOT Laravel acceptance.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http'),crypto=require('node:crypto');
const {once}=require('node:events');
const root=path.resolve(__dirname,'../..'),modulePath=process.env.DESKTOP_TEST_BROWSER_MODULE;
assert.ok(modulePath,'DESKTOP_TEST_BROWSER_MODULE must point to pinned Playwright.');
const playwrightVersion=require(path.join(modulePath,'package.json')).version;
assert.equal(playwrightVersion,'1.62.1');
assert.equal(process.platform,'win32','Run this diagnostic on actual Windows Edge.');
const {chromium}=require(modulePath);
const footer=fs.readFileSync(path.join(root,'resources/views/admin/layouts/footer.blade.php'),'utf8');
const jquery=fs.readFileSync(path.join(root,'public/dashboard/plugins/jquery/jquery.min.js'));
const match=footer.match(/\$\('\.delete_all'\)\.on\('click', function\(e\) \{([\s\S]*?)\n        \}\);/);
assert.ok(match,'Extract the actual original shared bulk handler.');
const localConditional=/@unless\(config\('desktop_dashboard\.local'\)\)[\s\S]*?@endunless/g;
assert.equal([...match[1].matchAll(localConditional)].length,1,'Render only the existing desktop-local conditional.');
const handlerBody=match[1].replace(localConditional,''),sha=value=>crypto.createHash('sha256').update(value).digest('hex');
const receipt={scope:'Windows Edge native dialog/event ordering against a mock HTTP server; NOT Laravel acceptance',
  source:process.env.GITHUB_SHA||null,playwrightVersion,footerSha256:sha(footer),jquerySha256:sha(jquery),handlerSha256:sha(match[1]),cases:[]};
const states=new Map(),pageErrors=[];
const html='<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="mock-csrf"></head><body>'+
  '<button class="delete_all" data-url="/admin/rolesDeleteAll">Delete selected</button><table><tbody>'+
  ['12','3'].map(id=>'<tr data-row-id="'+id+'"><td><input type="checkbox" class="sub_chk" data-id="'+id+'" checked></td></tr>').join('')+
  '</tbody></table><script src="/jquery.js"></script><script>window.DashboardSPA={reload:function(){window.mockReloads=(window.mockReloads||0)+1;}};'+
  "$('.delete_all').on('click', function(e) {"+handlerBody+'\n});</script></body></html>';
const server=http.createServer(async(req,res)=>{
  try{
    if(req.method==='GET'&&req.url==='/jquery.js'){res.writeHead(200,{'Content-Type':'application/javascript'});return res.end(jquery);}
    if(req.method==='GET'&&req.url.startsWith('/case/')){res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});return res.end(html);}
    if(req.method==='DELETE'&&req.url==='/admin/rolesDeleteAll'){
      const state=states.get(req.headers['x-probe-case']);assert.ok(state,'Known isolated mock case.');
      let body='';for await(const chunk of req)body+=chunk;
      assert.equal(body,'ids=12,3');assert.equal(req.headers['x-csrf-token'],'mock-csrf');
      state.requests++;if(!state.committed){state.committed=true;state.commits++;}
      res.writeHead(200,{'Content-Type':'application/json'});return res.end(JSON.stringify({success:'Mock committed deletion'}));
    }
    res.writeHead(404);res.end();
  }catch(error){pageErrors.push('Mock server: '+error.stack);res.writeHead(500);res.end('Mock assertion failed');}
});
let browser;
const watchdog=setTimeout(()=>{process.stderr.write('EDGE_DIALOG_PROBE_TIMEOUT\n');process.exit(1);},90000);
(async()=>{
  server.listen(0,'127.0.0.1');await once(server,'listening');
  const origin='http://127.0.0.1:'+server.address().port;
  browser=await chromium.launch({channel:'msedge',headless:true});receipt.browserVersion=browser.version();
  for(const mode of ['old-promise-all','accept-alert-first']){
    const state={requests:0,commits:0,committed:false};states.set(mode,state);
    const context=await browser.newContext({serviceWorkers:'block',extraHTTPHeaders:{'X-Probe-Case':mode}});
    try{
      await context.route('**/*',route=>{
        assert.equal(new URL(route.request().url()).origin,origin,'Only local mock requests.');
        return route.continue();
      });
      const page=await context.newPage();page.setDefaultTimeout(15000);
      page.on('pageerror',error=>pageErrors.push(error.stack));
      await page.goto(origin+'/case/'+mode);
      const userAgent=await page.evaluate(()=>navigator.userAgent);assert.match(userAgent,/Edg\//);receipt.userAgent=userAgent;
      assert.equal(await page.evaluate(()=>jQuery.fn.jquery),'3.4.1');
      let intercepted=0;
      await page.route('**/admin/rolesDeleteAll',async route=>{
        intercepted++;
        const response=await route.fetch();assert.equal(response.status(),200);assert.ok((await response.json()).success);
        if(intercepted===1)return route.abort('failed'); // Mock commits first; browser loses only its response.
        return route.fulfill({response});
      });
      const events=[],start=Date.now();let requestSettled=false,pairSettled=false;
      const stamp=name=>events.push({name,ms:Date.now()-start});
      const outcome=page.waitForEvent('requestfailed',{predicate:r=>new URL(r.url()).pathname==='/admin/rolesDeleteAll'});
      outcome.then(()=>{requestSettled=true;stamp('requestfailed');});
      const confirmationPromise=page.waitForEvent('dialog'),clicked=page.locator('.delete_all').click();
      const confirmation=await confirmationPromise;
      assert.equal(confirmation.type(),'confirm');assert.equal(confirmation.message(),'Are you sure you want to delete this row?');stamp('confirm-open');
      const alertPromise=page.waitForEvent('dialog').then(dialog=>{stamp('alert-open');return dialog;});
      const oldPair=mode==='old-promise-all'?Promise.all([outcome,alertPromise]).then(value=>{pairSettled=true;stamp('old-pair-settled');return value;}):null;
      await confirmation.accept();
      const alert=await alertPromise;assert.equal(alert.type(),'alert');
      let held=null;
      if(mode==='old-promise-all'){
        // Observe the old wait while its real native alert remains open, without browser commands.
        await new Promise(resolve=>setTimeout(resolve,350));
        held={alertOpen:true,requestSettled,pairSettled,mockCommits:state.commits};stamp('old-pair-observed-pending');
      }
      stamp('accept-alert-start');await alert.accept();stamp('accept-alert-done');
      const failed=await outcome;assert.match(failed.failure().errorText,/ERR_FAILED/);
      if(oldPair)await oldPair;await clicked;
      assert.equal(await page.locator('.sub_chk:checked').count(),2,'Lost mock response retains both selected rows.');
      assert.equal(await page.locator('tr').count(),2);assert.equal(state.commits,1,'Simulated server committed before response loss.');
      if(held){assert.equal(held.requestSettled,false,'Edge defers requestfailed while the native alert is held.');assert.equal(held.pairSettled,false,'Old Promise.all cannot reach alert.accept.');}
      else assert.ok(events.findIndex(e=>e.name==='accept-alert-start')<events.findIndex(e=>e.name==='requestfailed'),'Accept-first unblocks the already-attached failure event.');
      // Mock acknowledgement shows the original UI transition, not Laravel idempotency.
      const retryResponse=page.waitForResponse(r=>new URL(r.url()).pathname==='/admin/rolesDeleteAll'&&r.request().method()==='DELETE');
      const retryConfirmPromise=page.waitForEvent('dialog'),retryClick=page.locator('.delete_all').click();
      const retryConfirm=await retryConfirmPromise;assert.equal(retryConfirm.type(),'confirm');
      const retryAlertPromise=page.waitForEvent('dialog');await retryConfirm.accept();
      const retryAlert=await retryAlertPromise;assert.equal(retryAlert.message(),'Mock committed deletion');
      await retryAlert.accept();assert.equal((await retryResponse).status(),200);await retryClick;
      assert.equal(await page.locator('tr').count(),0);assert.equal(await page.evaluate(()=>window.mockReloads),1);
      assert.equal(state.requests,2);assert.equal(state.commits,1);
      const proof={mode,events,held,mockRequests:state.requests,mockCommits:state.commits,retainedSelectionAfterLoss:2,rowsAfterAcknowledgement:0};
      receipt.cases.push(proof);process.stdout.write('EDGE_DIALOG_ORDER_PROOF '+JSON.stringify(proof)+'\n');
    }finally{await context.close();}
  }
  assert.deepEqual(pageErrors,[]);receipt.accepted=true;
  if(process.env.EDGE_DIALOG_PROBE_RECEIPT)fs.writeFileSync(process.env.EDGE_DIALOG_PROBE_RECEIPT,JSON.stringify(receipt,null,2)+'\n');
  process.stdout.write('PASS actual Windows Edge held alert blocks old wait; accept-first unblocks failure event. Mock HTTP scope only.\n');
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exitCode=1;}).finally(async()=>{
  clearTimeout(watchdog);if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));
});

