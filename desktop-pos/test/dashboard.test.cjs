const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const {EventEmitter}=require('node:events');
const policy=require('../src/dashboard-policy.cjs');

function fixture(t, options = {}){
  let next=0,closing=false;const windows=[],handlers=new Map(),external=[],notices=[];let pageHTML='<html data-dashboard-receipt="takeaway"><head><style>body{color:black}</style><script>window.print()</script></head><body>فاتورة</body></html>';
  const session={cookies:{get:async()=>[],remove:async()=>{}},fetch:async(_url,options)=>{assert.equal(options.credentials,'include');return new Response(pageHTML,{headers:{'content-type':'text/html; charset=utf-8'}});},
    webRequest:{onCompleted(_filter,handler){this.completed=handler;},onErrorOccurred(_filter,handler){this.error=handler;},onBeforeSendHeaders(_filter,handler){this.headers=handler;}},setPermissionCheckHandler(fn){this.check=fn;},setPermissionRequestHandler(fn){this.permission=fn;}};
  class Window extends EventEmitter{
    constructor(options){super();this.options=options;this.visible=false;this.destroyed=false;this.webContents=new EventEmitter();const contents=this.webContents;
      contents.id=++next;contents.session=session;contents.setWindowOpenHandler=fn=>{contents.popup=fn;};
      contents.stop=()=>{contents.stopped=true;};contents.send=(name,value)=>{contents.lastMessage={name,value};};
      contents.getURL=()=>this.url;contents.getPrintersAsync=async()=>[{name:'BranchPrinter'}];
      contents.executeJavaScript=async()=>true;contents.print=(options,callback)=>{this.printOptions=options;callback?.(true);};windows.push(this);}
    maximize(){} async loadURL(url){this.url=url;if(this.failure)throw Error(this.failure);} show(){this.visible=true;} hide(){this.visible=false;} focus(){} isMinimized(){return false;} isDestroyed(){return this.destroyed;} isVisible(){return this.visible;} destroy(){this.destroyed=true;} reload(){}
    static getFocusedWindow(){return windows.find(w=>w.visible);}
  }
  const electron={BrowserWindow:Window,ipcMain:{handle:(name,fn)=>handlers.set(name,fn)},Menu:{buildFromTemplate:template=>template,setApplicationMenu:menu=>{electron.menu=menu;}},shell:{openExternal:url=>external.push(url)},dialog:{showMessageBox:async()=>({response:1})}};
  const module={exports:{}};vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../src/dashboard.cjs'),'utf8'),{module,__dirname:path.join(__dirname,'../src'),require:name=>name==='electron'?electron:require(name.startsWith('./')?path.join(__dirname,'../src',name):name),URL,AbortSignal,Set,Error,Promise,setTimeout,clearTimeout});
  const dashboard=module.exports({origin:policy.DEFAULT_ORIGIN,offline:message=>notices.push(message),offlineWindow:()=>windows[0],quitting:()=>closing,quit:()=>{},printer:()=> 'BranchPrinter',...options});
  return {dashboard,windows,handlers,external,notices,session,electron,html:value=>{pageHTML=value;},close:()=>{closing=true;}};
}

test('installed app opens the original complete dashboard and keeps a persistent signed-in session',async t=>{
  const f=fixture(t);const opening=f.dashboard.open();const w=f.windows[0];assert.ok(w.visible,'dashboard frame and offline menu remain available during loading');await opening;
  assert.equal(w.url,'https://fasakhaninja.com/admin/dashboard');assert.ok(w.visible);
  assert.equal(w.options.title,'GO ERP — الداشبورد');
  let titlePrevented=false;w.webContents.emit('page-title-updated',{preventDefault(){titlePrevented=true;}},'Original business page');
  assert.equal(titlePrevented,true,'A dashboard page must not replace the native GO ERP title');
  assert.equal(w.options.webPreferences.partition,'persist:fasakhansta-dashboard');
  assert.equal(w.options.webPreferences.nodeIntegration,false);assert.equal(w.options.webPreferences.contextIsolation,true);
  assert.match(w.options.webPreferences.preload,/dashboard-preload/);
  await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/branch-expenses');assert.equal(f.windows.length,1);assert.match(w.url,/branch-expenses$/);
});
test('same-site dashboard pages/popups retain access; external links cannot become privileged app pages',async t=>{
  const f=fixture(t);await f.dashboard.open();const c=f.windows[0].webContents;
  assert.equal(c.popup({url:policy.DEFAULT_ORIGIN+'/admin/employees'}).action,'allow');
  assert.equal(c.popup({url:'https://example.com'}).action,'deny');assert.equal(f.external[0],'https://example.com');
  assert.equal(c.popup({url:'file:///etc/passwd'}).action,'deny');
  let prevented=false;c.emit('will-redirect',{preventDefault(){prevented=true;}},'javascript:alert(1)');assert.ok(prevented);
  assert.equal(f.external.length,1);
});
test('network failures reveal local orders while server/auth failures retain real dashboard responses',async t=>{
  const f=fixture(t);await f.dashboard.open();const w=f.windows[0];
  w.webContents.emit('did-fail-load',{},-106,'net::ERR_INTERNET_DISCONNECTED',w.url,true);assert.equal(f.notices.length,1);
  f.session.webRequest.error({error:'net::ERR_CONNECTION_RESET'});assert.equal(f.notices.length,2);
  f.session.webRequest.error({error:'HTTP 500'});assert.equal(f.notices.length,2);
  w.failure='net::ERR_NAME_NOT_RESOLVED';await f.dashboard.open();assert.equal(f.notices.length,3);
});
test('a remote form is persisted before transmission and a main-frame failure cannot hide its unknown result',async t=>{
  let release;const begun=[],completed=[],failures=[];
  const f=fixture(t,{remoteState:{begin:async id=>{begun.push(id);await new Promise(r=>release=r);},complete:async id=>completed.push(id)},
    offline:(_message,details)=>failures.push(details)});
  await f.dashboard.open();const w=f.windows[0];let decision;
  f.session.webRequest.headers({id:99,url:policy.DEFAULT_ORIGIN+'/admin/employees/save',method:'POST',webContentsId:w.webContents.id,requestHeaders:{}},value=>decision=value);
  await Promise.resolve();assert.equal(begun.length,1);assert.match(begun[0],/^[a-f0-9-]{36}$/);assert.equal(decision,undefined);
  w.webContents.emit('did-fail-load',{},-106,'net::ERR_INTERNET_DISCONNECTED',w.url,true);
  assert.equal(failures.at(-1).method,'UNKNOWN');release();await new Promise(r=>setImmediate(r));assert.ok(decision.requestHeaders);
  f.session.webRequest.error({id:99,url:policy.DEFAULT_ORIGIN+'/admin/employees/save',method:'POST',error:'net::ERR_CONNECTION_RESET'});
  assert.equal(failures.at(-1).method,'UNKNOWN');assert.deepEqual(completed,[]);
  f.session.webRequest.completed({id:99});await new Promise(r=>setImmediate(r));assert.deepEqual(completed,begun);
  w.webContents.emit('did-fail-load',{},-106,'net::ERR_INTERNET_DISCONNECTED',w.url,true);
  assert.notEqual(failures.at(-1).method,'UNKNOWN');
});
test('a lost original price calculation remains a read while an original GET mutation is retained as a write',async t=>{
  const begun=[],failures=[];const f=fixture(t,{remoteState:{begin:async id=>begun.push(id)},offline:(_message,details)=>failures.push(details)});
  await f.dashboard.open();const w=f.windows[0];
  const quote={id:12,url:policy.DEFAULT_ORIGIN+'/admin/phone-orders/delivery-quote',method:'POST',webContentsId:w.webContents.id,requestHeaders:{}};
  let decision;f.session.webRequest.headers(quote,value=>decision=value);assert.ok(decision.requestHeaders);assert.equal(begun.length,0);
  f.session.webRequest.error({...quote,error:'net::ERR_CONNECTION_RESET'});assert.equal(failures.at(-1).method,'GET');
  f.session.webRequest.headers({...quote,id:13,url:policy.DEFAULT_ORIGIN+'/admin/resturantControl',method:'GET'},()=>{});
  await new Promise(r=>setImmediate(r));assert.equal(begun.length,1);
  f.session.webRequest.error({...quote,error:'net::ERR_CONNECTION_RESET'});assert.equal(failures.at(-1).method,'UNKNOWN');
});
test('original address suggestions remain a read without a remote command reservation after a lost response',async t=>{
  const reservations=[],failures=[];const f=fixture(t,{remoteState:{begin:async id=>reservations.push(id)},
    remoteAttempts:{begin:async id=>{reservations.push(id);return {};}},offline:(_message,details)=>failures.push(details)});
  await f.dashboard.open();const request={id:14,url:policy.DEFAULT_ORIGIN+'/admin/phone-orders/address-suggestions',method:'POST',
    webContentsId:f.windows[0].webContents.id,requestHeaders:{}};let decision;
  f.session.webRequest.headers(request,value=>decision=value);
  assert.ok(decision.requestHeaders);assert.deepEqual(reservations,[]);
  f.session.webRequest.error({...request,error:'net::ERR_CONNECTION_RESET'});
  assert.equal(failures.at(-1).method,'GET');assert.deepEqual(reservations,[]);
});
test('an original account status GET is retained as an uncertain write after a lost native reply',async t=>{
  const begun=[],failures=[],f=fixture(t,{remoteState:{begin:async id=>begun.push(id)},offline:(_message,details)=>failures.push(details)});
  await f.dashboard.open();const request={id:15,url:policy.DEFAULT_ORIGIN+'/admin/change-status/20?status=declined',method:'GET',webContentsId:f.windows[0].webContents.id,requestHeaders:{}};
  f.session.webRequest.headers(request,()=>{});await new Promise(r=>setImmediate(r));assert.equal(begun.length,1);
  f.session.webRequest.error({...request,error:'net::ERR_CONNECTION_RESET'});assert.equal(failures.at(-1).method,'UNKNOWN');
});
test('a HEAD account-status probe is cancelled before the original server mutation route can execute',async t=>{
  const Attempts=require('../src/dashboard-remote-attempts.cjs'),attempts=new Attempts({});
  const f=fixture(t,{remoteAttempts:{begin:(...args)=>attempts.begin(...args)}});await f.dashboard.open();let decision;
  f.session.webRequest.headers({id:16,url:policy.DEFAULT_ORIGIN+'/admin/change-status/20?status=accepted',method:'HEAD',webContentsId:f.windows[0].webContents.id,requestHeaders:{}},value=>decision=value);
  await new Promise(r=>setImmediate(r));assert.equal(decision.cancel,true);
});
test('native remote proofs wait for reservation and cannot leak through foreign requests or be forged by a page',async t=>{
  let release;const finished=[],failed=[],f=fixture(t,{remoteAttempts:{begin:async id=>{await new Promise(r=>release=r);return {'X-Fasakhansta-Remote-Attempt':id,'X-Fasakhansta-Remote-Capability':'a'.repeat(64)};},
    complete:async id=>finished.push(id),failed:id=>failed.push(id)}});
  await f.dashboard.open();const request={id:10,url:policy.DEFAULT_ORIGIN+'/admin/products',method:'POST',webContentsId:f.windows[0].webContents.id,
    requestHeaders:{'X-Fasakhansta-Remote-Attempt':'forged','X-Fasakhansta-Remote-Capability':'forged'}};let decision;
  f.session.webRequest.headers(request,value=>decision=value);await Promise.resolve();assert.equal(decision,undefined);release();await new Promise(r=>setImmediate(r));
  const id=decision.requestHeaders['X-Fasakhansta-Remote-Attempt'];assert.match(id,/^[a-f0-9-]{36}$/);assert.equal(decision.requestHeaders['X-Fasakhansta-Remote-Capability'],'a'.repeat(64));
  f.session.webRequest.headers({...request,url:'https://foreign.test/api',requestHeaders:decision.requestHeaders},value=>decision=value);
  assert.equal(Object.keys(decision.requestHeaders).some(key=>/^x-fasakhansta-remote-/i.test(key)),false);
  f.session.webRequest.completed({id:10});await new Promise(r=>setImmediate(r));assert.deepEqual(finished,[id]);
  f.session.webRequest.headers({...request,id:11},()=>{});await Promise.resolve();release();await new Promise(r=>setImmediate(r));
  f.session.webRequest.error({...request,id:11,error:'net::ERR_CONNECTION_RESET'});assert.equal(failed.length,1);
});
test('a delayed remote settlement cannot discard a later write that reuses the network request ID',async t=>{
  let release;const failures=[],begun=[],f=fixture(t,{remoteAttempts:{begin:async id=>{begun.push(id);return {};},
    complete:async()=>new Promise(resolve=>release=resolve),failed:()=>{}},offline:(_message,details)=>failures.push(details)});
  await f.dashboard.open();const request={id:17,url:policy.DEFAULT_ORIGIN+'/admin/products',method:'POST',webContentsId:f.windows[0].webContents.id,requestHeaders:{}};
  f.session.webRequest.headers(request,()=>{});await new Promise(r=>setImmediate(r));f.session.webRequest.completed({id:17});await Promise.resolve();
  f.session.webRequest.headers(request,()=>{});await new Promise(r=>setImmediate(r));assert.notEqual(begun[0],begun[1]);release();await new Promise(r=>setImmediate(r));
  f.windows[0].webContents.emit('did-fail-load',{},-106,'net::ERR_INTERNET_DISCONNECTED',request.url,true);
  assert.equal(failures.at(-1).method,'UNKNOWN');
});
test('native receipt printing is silent, reuses dashboard authentication and runs receipt scripts under restrictive CSP',async t=>{
  const f=fixture(t);await f.dashboard.open();const sender=f.windows[0].webContents;
  const result=await f.handlers.get('dashboard:print-receipt')({sender,senderFrame:{url:sender.getURL()}},policy.DEFAULT_ORIGIN+'/admin/takeaway/1/print');
  assert.ok(result.ok);const print=f.windows[1];assert.ok(print.destroyed);assert.equal(print.printOptions.silent,true);assert.equal(print.printOptions.deviceName,'BranchPrinter');
  const html=decodeURIComponent(print.url.split(',').slice(1).join(','));assert.match(html,/script-src 'none'/);assert.match(html,/data-dashboard-receipt/);
});
test('native printing rejects foreign pages, unowned frames and non-receipts before any printer runs',async t=>{
  const f=fixture(t);await f.dashboard.open();const sender=f.windows[0].webContents,handler=f.handlers.get('dashboard:print-receipt'),event={sender,senderFrame:{url:sender.getURL()}};
  assert.equal((await handler(event,'https://example.com/admin/receipt')).ok,false);
  assert.equal((await handler({...event,senderFrame:{url:'https://example.com'}},policy.DEFAULT_ORIGIN+'/admin/receipt')).ok,false);
  assert.equal((await handler({...event,sender:{id:999}},policy.DEFAULT_ORIGIN+'/admin/receipt')).ok,false);
  f.html('<html><head></head><body>login</body></html>');assert.equal((await handler(event,policy.DEFAULT_ORIGIN+'/admin/login')).ok,false);
  assert.equal(f.windows.length,1);
});
test('remote dashboard exposes bounded preparation, status and receipt actions without the offline store or device token',()=>{
  let api,name;vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../src/dashboard-preload.cjs'),'utf8'),{require:()=>({contextBridge:{exposeInMainWorld:(n,a)=>{name=n;api=a;}},ipcRenderer:{invoke:async()=>({ok:true,value:true})}})});
  assert.equal(name,'FasakhanstaDesktop');assert.deepEqual(Object.keys(api),['printReceipt','printEffect','printPending','status','prepare','synchronize','reviewSync','onState']);
  assert.ok(policy.sameOrigin('https://fasakhaninja.com/admin/customers'));assert.equal(policy.sameOrigin('https://fasakhaninja.com.evil.test/admin'),false);
  assert.equal(policy.sameOrigin('https://user:password@fasakhaninja.com/admin'),false);assert.equal(policy.externalURL('file:///x'),false);
});

test('preparation and status reject foreign frames and use only the original signed-in dashboard origin',async t=>{
  let accepted;const f=fixture(t,{prepare:async(origin,csrf)=>{accepted={origin,csrf};return {prepared:true};},status:async()=>({available:true,prepared:false})});
  await f.dashboard.open();const sender=f.windows[0].webContents,event={sender,senderFrame:{url:sender.getURL()}};
  assert.equal((await f.handlers.get('dashboard:status')(event)).value.available,true);
  assert.equal((await f.handlers.get('dashboard:prepare')({...event,senderFrame:{url:'https://foreign.test/admin/dashboard'}},{csrf:'token'})).ok,false);
  assert.equal((await f.handlers.get('dashboard:prepare')(event,{csrf:'token',origin:'https://foreign.test'})).ok,true);
  assert.deepEqual(accepted,{origin:policy.DEFAULT_ORIGIN,csrf:'token'});
  f.dashboard.publish({pending:2});assert.equal(sender.lastMessage.name,'dashboard:state');
});

test('sync review uses the native dialog callback and rejects foreign or child frames',async t=>{
  const calls=[],f=fixture(t,{reviewSync:async window=>{calls.push(window);return {retried:false};}});await f.dashboard.open();
  const sender=f.windows[0].webContents,event={sender,senderFrame:{url:sender.getURL()}},handler=f.handlers.get('dashboard:review-sync');
  assert.equal((await handler(event)).ok,true);assert.deepEqual(calls,[f.windows[0]]);
  assert.equal((await handler({...event,senderFrame:{url:'https://foreign.test/admin/dashboard'}})).ok,false);
  assert.equal((await handler({...event,sender:{id:999}})).ok,false);assert.equal(calls.length,1);
  assert.ok(f.electron.menu[0].submenu.some(value=>value.label==='مراجعة المزامنة'));
});

test('switching between original remote and local pages keeps one window and prevents stale forms or local header forwarding',async t=>{
  const local='http://127.0.0.1:45123',f=fixture(t);await f.dashboard.open();const window=f.windows[0];
  await f.dashboard.switchTo({origin:local,localToken:'private'});
  assert.equal(window.url,local+'/admin/dashboard');
  await f.dashboard.switchTo({origin:policy.DEFAULT_ORIGIN,localToken:''},async()=>{
    let decision;f.session.webRequest.headers({url:local+'/admin/save',webContentsId:window.webContents.id,requestHeaders:{}},value=>{decision=value;});
    assert.equal(decision.cancel,true);
  });
  assert.equal(window.url,policy.DEFAULT_ORIGIN+'/admin/dashboard');assert.equal(f.windows.length,1);
  let decision;f.session.webRequest.headers({url:policy.DEFAULT_ORIGIN+'/admin/dashboard',webContentsId:window.webContents.id,requestHeaders:{'X-Fasakhansta-Desktop':'private'}},value=>{decision=value;});
  assert.deepEqual(Object.keys(decision.requestHeaders),[]);
  await assert.rejects(f.dashboard.switchTo({origin:'https://foreign.test',localToken:''}));
});
test('quitting blocks late dashboard navigation and printing before local printer settings are accessed',async t=>{
  const f=fixture(t);await f.dashboard.open();const sender=f.windows[0].webContents;
  f.close();await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/employees');assert.equal(f.windows[0].url,policy.DEFAULT_ORIGIN+'/admin/dashboard');
  const result=await f.handlers.get('dashboard:print-receipt')({sender,senderFrame:{url:sender.getURL()}},policy.DEFAULT_ORIGIN+'/admin/takeaway/1/print');
  assert.equal(result.ok,false);assert.match(result.error,/يُغلق/);assert.equal(f.windows.length,1);
});

test('local dashboard credentials stay inside owned pages and native receipt requests',async t=>{
  const origin='http://127.0.0.1:43123',token='browser-only';
  const f=fixture(t,{origin,localToken:token});await f.dashboard.open();
  const request=(url,webContentsId,requestHeaders={})=>{let response;f.session.webRequest.headers({url,webContentsId,requestHeaders},value=>{response=value;});return response;};
  const owned=f.windows[0].webContents.id;
  assert.equal(request(origin+'/admin/dashboard',owned).requestHeaders['X-Fasakhansta-Desktop'],token);
  assert.equal(request(origin+'/admin/dashboard',987).cancel,true);
  assert.equal(request(origin+'/admin/takeaway/1/print',undefined,{'x-fasakhansta-desktop':token}).requestHeaders['X-Fasakhansta-Desktop'],token);
  assert.equal(request(origin+'/admin/takeaway/1/print',undefined).cancel,true);
  const external=request('https://external.example/asset',owned,{'X-Fasakhansta-Desktop':token,'X-Fasakhansta-Control':'never-forward',Accept:'text/html'});
  assert.deepEqual(Object.keys(external.requestHeaders),['Accept']);
  f.windows[0].webContents.emit('did-fail-load',{},-102,'net::ERR_CONNECTION_REFUSED',origin+'/admin/dashboard',true);
  assert.equal(f.notices.length,0);
});

test('a closing supervisor returns the normal native printer rejection',async t=>{
  const f=fixture(t,{accepted:()=>Promise.reject(Error('closing'))});await f.dashboard.open();
  const sender=f.windows[0].webContents;
  const result=await f.handlers.get('dashboard:print-receipt')({sender,senderFrame:{url:sender.getURL()}},policy.DEFAULT_ORIGIN+'/admin/takeaway/1/print');
  assert.equal(result.ok,false);assert.equal(result.error,'closing');assert.equal(f.windows.length,1);
});

test('generation activation blocks old form requests, closes old popups and reopens the original dashboard window',async t=>{
  const origin='http://127.0.0.1:43123',f=fixture(t,{origin,localToken:'private'});await f.dashboard.open();
  const window=f.windows[0],child=new window.constructor(window.options);
  window.webContents.emit('did-create-window',child);
  await f.dashboard.switchTo({origin,localToken:'private'},async()=>{
    let decision;f.session.webRequest.headers({url:origin+'/admin/save',webContentsId:window.webContents.id,requestHeaders:{}},value=>{decision=value;});
    assert.equal(decision.cancel,true);assert.ok(child.destroyed);assert.ok(window.webContents.stopped);
    assert.equal(window.webContents.popup({url:origin+'/admin/dashboard'}).action,'deny');
  });
  assert.equal(window.url,origin+'/admin/dashboard');assert.equal(f.windows.filter(value=>!value.destroyed).length,1);
  let decision;f.session.webRequest.headers({url:origin+'/admin/dashboard',webContentsId:window.webContents.id,requestHeaders:{}},value=>{decision=value;});
  assert.equal(decision.requestHeaders['X-Fasakhansta-Desktop'],'private');
});

test('bulk background replacement waits for active forms, popups and outstanding page requests',async t=>{
  const origin='http://127.0.0.1:43123',f=fixture(t,{origin,localToken:'private'});await f.dashboard.open();
  const window=f.windows[0];let updates=0;
  await f.dashboard.open(origin+'/admin/takeaway');
  assert.equal((await f.dashboard.refresh(async()=>updates++)).deferred,true);
  assert.equal(window.url,origin+'/admin/takeaway');assert.equal(updates,0);assert.equal(window.webContents.stopped,undefined);
  await f.dashboard.open();const child=new window.constructor(window.options);window.webContents.emit('did-create-window',child);
  assert.equal((await f.dashboard.refresh(async()=>updates++)).deferred,true);assert.equal(child.destroyed,false);
  child.emit('closed');
  f.session.webRequest.headers({id:42,url:origin+'/admin/dashboard',method:'GET',webContentsId:window.webContents.id,requestHeaders:{}},()=>{});
  assert.equal(await f.dashboard.canRefresh(),false);f.session.webRequest.completed({id:42});
  assert.equal(await f.dashboard.canRefresh(),true);await f.dashboard.refresh(async()=>updates++);assert.equal(updates,1);
});
test('a settled home refresh keeps the selected report URL and blocks navigation throughout staging',async t=>{
  const origin='http://127.0.0.1:43123',f=fixture(t,{origin,localToken:'private'});await f.dashboard.open(origin+'/admin/dashboard?period=week');
  const window=f.windows[0];await f.dashboard.refresh(async()=>{
    let blocked=false;window.webContents.emit('will-navigate',{preventDefault(){blocked=true;}},origin+'/admin/employees');assert.equal(blocked,true);
  });
  assert.equal(window.url,origin+'/admin/dashboard?period=week');
});
test('focused or changed home fields and visible dialogs defer snapshots while settled read content permits them',async t=>{
  const origin='http://127.0.0.1:43123',f=fixture(t,{origin,localToken:'private'});await f.dashboard.open();
  const document={readyState:'complete',activeElement:{matches:()=>false},querySelector:()=>null,querySelectorAll:()=>[]};
  f.windows[0].webContents.executeJavaScript=async source=>vm.runInNewContext(source,{document});
  assert.equal(await f.dashboard.canRefresh(),true);
  document.activeElement.matches=()=>true;assert.equal(await f.dashboard.canRefresh(),false);document.activeElement.matches=()=>false;
  document.querySelector=()=>({});assert.equal(await f.dashboard.canRefresh(),false);document.querySelector=()=>null;
  document.querySelectorAll=()=>[{tagName:'INPUT',type:'text',value:'changed',defaultValue:''}];assert.equal(await f.dashboard.canRefresh(),false);
  document.querySelectorAll=()=>[{tagName:'SELECT',options:[{selected:true,defaultSelected:false}]}];assert.equal(await f.dashboard.canRefresh(),false);
  document.querySelectorAll=()=>[];document.readyState='loading';assert.equal(await f.dashboard.canRefresh(),false);
});
test('prepared local pages cannot submit absolute production requests outside scheduled native reconciliation',async t=>{
  const origin='http://127.0.0.1:43123',f=fixture(t,{origin,localToken:'private',serverOrigin:policy.DEFAULT_ORIGIN});await f.dashboard.open();
  const sender=f.windows[0].webContents;let decision;
  f.session.webRequest.headers({url:policy.DEFAULT_ORIGIN+'/admin/employees/save',method:'POST',webContentsId:sender.id,requestHeaders:{}},value=>decision=value);
  assert.equal(decision.cancel,true);
  f.session.webRequest.headers({url:policy.DEFAULT_ORIGIN+'/admin/order-board/live',method:'GET',webContentsId:sender.id,requestHeaders:{}},value=>decision=value);
  assert.equal(decision.cancel,true);
  f.session.webRequest.headers({url:origin+'/admin/employees/save',method:'POST',webContentsId:sender.id,requestHeaders:{}},value=>decision=value);
  assert.equal(decision.requestHeaders['X-Fasakhansta-Desktop'],'private');
});

test('prepared local receipts resolve to a typed durable effect and never fetch the consuming legacy GET',async t=>{
  const {sha256,receiptHash}=require('../src/dashboard-print-effects.cjs'),records=new Map(),calls=[];
  const effect='a'.repeat(64),html='<html data-dashboard-receipt="takeaway"><head></head><body>فاتورة</body></html>';
  const control=async value=>{
    calls.push(value);
    if(value.action==='print-resolve')return {effect_id:effect};
    if(value.action==='print-prepare')return {effect_id:effect,device_id:'00000000-0000-4000-8000-000000000001',
      enrollment_id:'b'.repeat(64),origin_command_id:'00000000-0000-4000-8000-000000000002',kind:'sale_receipt',
      actor_id:10,branch:'f:100',revision:1,snapshot_sha256:'c'.repeat(64),html,render_sha256:sha256(html)};
    if(value.action==='print-burn')return {effect_id:effect,invocation_id:value.invocation_id,permit:'d'.repeat(64),render_sha256:sha256(html)};
    if(value.action==='print-outcome'){const{action,...receipt}=value;return {effect_id:effect,invocation_id:value.invocation_id,state:value.state,receipt_sha256:receiptHash(receipt)};}
    throw Error('Unexpected control operation');
  };
  const origin='http://127.0.0.1:43123',f=fixture(t,{origin,localToken:'private',printControl:control,
    printMetadata:{read:async key=>records.get(key)||null,write:async(key,value)=>records.set(key,value)}});
  await f.dashboard.open();f.session.fetch=()=>{throw Error('Legacy consuming GET must not be fetched.');};
  const sender=f.windows[0].webContents,event={sender,senderFrame:{url:sender.getURL()}},handler=f.handlers.get('dashboard:print-receipt');
  assert.equal((await handler(event,origin+'/admin/takeaway/receipts/1/print?initial_print=original-grant')).ok,true);
  assert.equal(calls[0].action,'print-resolve');assert.equal(calls[0].path,'/admin/takeaway/receipts/1/print');assert.equal(calls[0].query.initial_print,'original-grant');
  assert.equal(f.windows[1].printOptions.silent,true);assert.ok(f.windows[1].destroyed);
  const printed=await handler(event,origin+'/admin/takeaway/receipts/1/print?initial_print=original-grant');
  assert.equal(printed.ok,true);assert.equal(printed.value.already_recorded,true);assert.equal(f.windows.length,2);
  assert.equal(calls.filter(value=>value.action==='print-burn').length,1);
});
test('local legacy URLs cannot bypass a missing print protocol; remote polling retains the original receiver',async t=>{
  const origin='http://127.0.0.1:43123',f=fixture(t,{origin,localToken:'private'});await f.dashboard.open();
  const sender=f.windows[0].webContents,event={sender,senderFrame:{url:sender.getURL()}};
  const result=await f.handlers.get('dashboard:print-receipt')(event,origin+'/admin/takeaway/receipts/1/print');
  assert.equal(result.ok,false);assert.match(result.error,/تحديث البرنامج/);assert.equal(f.windows.length,1);
  const remote=fixture(t);await remote.dashboard.open();const contents=remote.windows[0].webContents;
  const state=await remote.handlers.get('dashboard:print-pending')({sender:contents,senderFrame:{url:contents.getURL()}});
  assert.equal(state.ok,true);assert.equal(state.value.local,false);
});
test('effect printing rejects unowned or foreign frames and raw effect identities on the remote dashboard',async t=>{
  const f=fixture(t);await f.dashboard.open();const sender=f.windows[0].webContents,handler=f.handlers.get('dashboard:print-effect');
  assert.equal((await handler({sender,senderFrame:{url:sender.getURL()}},'a'.repeat(64))).ok,false);
  assert.equal((await handler({sender:{id:999},senderFrame:{url:sender.getURL()}},'a'.repeat(64))).ok,false);
  assert.equal((await handler({sender,senderFrame:{url:'https://foreign.test/admin/'}},'a'.repeat(64))).ok,false);
  assert.equal(f.windows.length,1);
});
test('local generic print menu directs users to the audited receipt action',async t=>{
  const f=fixture(t,{origin:'http://127.0.0.1:43123',localToken:'private'});await f.dashboard.open();
  const item=f.electron.menu.find(value=>value.label==='طباعة').submenu[0];item.click();
  assert.equal(f.windows[0].printOptions,undefined);
});
test('full-central native menu actions keep human review outside renderer IPC and preserve captured cookies while awaiting durable writes',async t=>{
  const menu=[],requests=[];let release;
  const f=fixture(t,{connected:async()=>menu.push('connected'),localMode:async()=>menu.push('local'),reviewCentral:async()=>menu.push('review'),
    remoteAttempts:{begin:async(id,details)=>{requests.push({id,details});await new Promise(resolve=>release=resolve);return {};}}});
  await f.dashboard.open();const submenu=f.electron.menu[0].submenu;
  for(const name of ['الداشبورد المتصلة كاملة','العودة لنسخة الجهاز بعد التحديث','مراجعة نتائج العمليات المتصلة'])await submenu.find(item=>item.label===name).click();
  assert.deepEqual(menu,['connected','local','review']);for(const name of ['dashboard:connected','dashboard:local','dashboard:review-central'])assert.equal(f.handlers.has(name),false);
  const request={id:800,url:policy.DEFAULT_ORIGIN+'/%61dmin/users/20',method:'POST',webContentsId:f.windows[0].webContents.id,
    requestHeaders:{Cookie:'original-session','X-Fasakhansta-Remote-Attempt':'forged','X-Fasakhansta-Remote-Capability':'forged'}};let decision;
  f.session.webRequest.headers(request,value=>decision=value);await Promise.resolve();assert.equal(requests.length,1);assert.equal(decision,undefined);
  request.requestHeaders.Cookie='later-session';release();await new Promise(resolve=>setImmediate(resolve));
  assert.equal(decision.requestHeaders.Cookie,'original-session');assert.equal(Object.keys(decision.requestHeaders).some(key=>/^x-fasakhansta-/i.test(key)),false);
});
test('full-central browse records precede transmission and known aliased GET mutations reach the guarded native attempt path',async t=>{
  let release;const attempts=[],f=fixture(t,{browse:async()=>new Promise(resolve=>release=resolve),remoteAttempts:{begin:async(id,details)=>{
    attempts.push(details);if(details.method==='HEAD')throw Error('HEAD mutation denied');return {};}}});await f.dashboard.open();const contents=f.windows[0].webContents;
  const base={id:801,method:'GET',webContentsId:contents.id,requestHeaders:{}};let decision;
  f.session.webRequest.headers({...base,url:policy.DEFAULT_ORIGIN+'/%61dmin/dashboard'},value=>decision=value);await Promise.resolve();assert.equal(decision,undefined);release();await new Promise(resolve=>setImmediate(resolve));assert.ok(decision.requestHeaders);
  for(const pathname of ['/admin/change-status/020','/admin/change-status/20/','/admin/change-status/%32%30','/test-test/']){
    decision=undefined;f.session.webRequest.headers({...base,id:802,url:policy.DEFAULT_ORIGIN+pathname,method:'HEAD'},value=>decision=value);
    await new Promise(resolve=>setImmediate(resolve));assert.equal(decision.cancel,true);assert.equal(attempts.at(-1).url,policy.DEFAULT_ORIGIN+pathname);
  }
});
test('native central print fetch flushes uncertainty before the consuming GET and retains a lost response without invoking the printer',async t=>{
  const operations=[];let release,lost=false;
  const f=fixture(t,{remoteAttempts:{begin:async(id,details)=>{operations.push(['begin',id,details]);await new Promise(resolve=>release=resolve);return {};},
    complete:async id=>operations.push(['complete',id]),failed:id=>operations.push(['failed',id])}});await f.dashboard.open();const sender=f.windows[0].webContents;
  f.session.fetch=async(url,options)=>{operations.push(['fetch',url,options]);if(lost)throw Error('lost receipt reply');
    return new Response('<html data-dashboard-receipt="takeaway"><head></head><body>فاتورة</body></html>',{headers:{'content-type':'text/html'}});};
  const event={sender,senderFrame:{url:sender.getURL()}},handler=f.handlers.get('dashboard:print-receipt'),url=policy.DEFAULT_ORIGIN+'/admin/takeaway/receipts/20/print?initial_print=original';
  const first=handler(event,url);await Promise.resolve();assert.equal(operations.length,1);assert.equal(operations[0][0],'begin');assert.equal(f.windows.length,1);release();assert.equal((await first).ok,true);
  assert.deepEqual(operations.map(item=>item[0]),['begin','fetch','complete']);assert.equal(operations[0][2].method,'GET');assert.match(operations[0][2].url,/dashboard_print=1/);
  assert.deepEqual(Object.keys(operations[1][2].headers),['Accept']);assert.equal(f.windows.length,2);
  lost=true;operations.length=0;const second=handler(event,url);await Promise.resolve();release();const failure=await second;assert.equal(failure.ok,false);assert.match(failure.error,/lost receipt reply/);
  assert.deepEqual(operations.map(item=>item[0]),['begin','fetch','failed']);assert.equal(f.windows.length,2,'No print invocation is allowed after an unknown receipt response.');
});
test('original board print routes and their aliases reach native GET reservations while HEAD never reaches the server',async t=>{
  const getPolicy=require('../src/dashboard-get-policy.cjs'),attempts=[],f=fixture(t,{remoteAttempts:{begin:async(id,details)=>{
    attempts.push(details);if(details.method==='HEAD'&&getPolicy.mutation(new URL(details.url).pathname))throw Error('Consuming HEAD denied');return {};}}});
  await f.dashboard.open();const base={webContentsId:f.windows[0].webContents.id,requestHeaders:{}};let networkId=900;
  for(const source of ['legacy','store','service','partner_service'])for(const pathname of ['/admin/order-board/'+source+'/20/print',
    '/admin/order-board/'+source+'/020/print/','/%61dmin/order-board/'+source+'/%32%30/print']){
    let decision;const request={...base,id:networkId++,url:policy.DEFAULT_ORIGIN+pathname,method:'GET'};
    f.session.webRequest.headers(request,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));assert.ok(decision.requestHeaders);assert.equal(attempts.at(-1).url,request.url);
    decision=undefined;f.session.webRequest.headers({...request,id:networkId++,method:'HEAD'},value=>decision=value);await new Promise(resolve=>setImmediate(resolve));assert.equal(decision.cancel,true);
  }
});
test('native board-print IPC persists real direct pending state before fetch and retains lost replies for every original source',async t=>{
  const crypto=require('node:crypto'),RemoteState=require('../src/dashboard-remote-state.cjs'),RemoteAttempts=require('../src/dashboard-remote-attempts.cjs'),{binding}=require('../src/dashboard-connected.cjs');
  for(const source of ['legacy','store','service','partner_service']){
    const records=new Map(),metadata={read:async key=>structuredClone(records.get(key)||null),write:async(key,value)=>records.set(key,structuredClone(value))},state=new RemoteState(metadata);
    const credential={deviceId:crypto.randomUUID(),actorId:1,token:'a'.repeat(64),serverOrigin:policy.DEFAULT_ORIGIN,sourceRevision:'b'.repeat(40),
      sourceFingerprint:{format:1,sha256:'c'.repeat(64),framework:'8.83.29',files:100},snapshotId:crypto.randomUUID(),refreshId:crypto.randomUUID(),schemaHash:'d'.repeat(64),database:'fasakhansta_dashboard_stage_'+'e'.repeat(16)};
    const options={state,metadata,credential:async()=>credential,direct:async()=>binding(credential),request:async()=>{throw Error('No bounded capability may be sent for original board printing.');}};
    const attempts=new RemoteAttempts(options),f=fixture(t,{remoteAttempts:attempts});await f.dashboard.open();let lost=false,identity;
    const pathname='/admin/order-board/'+source+'/020/print/';
    f.session.fetch=async(url,request)=>{
      assert.equal(new URL(url).pathname,pathname);assert.equal(new URL(url).hash,'');assert.equal(request.redirect,'error');let outgoing;
      f.session.webRequest.headers({id:989,url:'https://foreign.test/admin/print',method:'GET',webContentsId:-1,requestHeaders:request.headers},value=>outgoing=value);
      assert.equal(Object.keys(outgoing.requestHeaders).some(name=>/^x-fasakhansta-/i.test(name)),false);
      f.session.webRequest.headers({id:992,url:url.replace('/020/','/21/'),method:'GET',webContentsId:-1,requestHeaders:request.headers},value=>outgoing=value);
      assert.equal(outgoing.cancel,true,'A mismatched native URL cannot fall through without the actor proof.');
      const withoutMarker={...request.headers};delete withoutMarker['X-Fasakhansta-Connected-Request'];
      f.session.webRequest.headers({id:993,url,method:'GET',webContentsId:-1,requestHeaders:withoutMarker},value=>outgoing=value);
      assert.equal(outgoing.cancel,true,'A missing native marker cannot downgrade a paired request into an unguarded request.');
      f.session.webRequest.headers({id:994,url,method:'POST',webContentsId:-1,requestHeaders:request.headers},value=>outgoing=value);
      assert.equal(outgoing.cancel,true,'A native print marker cannot authorize a different HTTP method.');
      f.session.webRequest.headers({id:990,url,method:'GET',webContentsId:-1,requestHeaders:request.headers},value=>outgoing=value);
      assert.equal(outgoing.requestHeaders['X-Fasakhansta-Connected-Device'],credential.deviceId);assert.equal(outgoing.requestHeaders['X-Fasakhansta-Connected-Token'],credential.token);
      assert.equal('X-Fasakhansta-Connected-Request' in outgoing.requestHeaders,false);
      f.session.webRequest.headers({id:991,url,method:'GET',webContentsId:-1,requestHeaders:request.headers},value=>outgoing=value);
      assert.equal(outgoing.cancel,true,'A consumed native print marker cannot fall through as an unguarded server request.');
      const remote=await state.read();assert.equal(remote.dirty,true);assert.equal(remote.pending.length,1);identity=remote.pending[0];
      assert.equal(records.get('remote-central/'+identity).path,pathname);assert.equal(records.get('remote-central/'+identity).method,'GET');
      if(lost)throw Error('lost original board print reply');return new Response('<html data-dashboard-receipt="order-board"><head></head><body>فاتورة</body></html>',{headers:{'content-type':'text/html'}});
    };
    const sender=f.windows[0].webContents,event={sender,senderFrame:{url:sender.getURL()}},handler=f.handlers.get('dashboard:print-receipt');
    assert.equal((await handler(event,policy.DEFAULT_ORIGIN+pathname+'#receipt')).ok,true);assert.deepEqual((await state.read()).pending,[]);assert.equal((await state.read()).dirty,true);
    assert.equal(records.get('remote-central-responses/'+identity).observation,'response-received');assert.equal(f.windows.length,2);
    lost=true;const result=await handler(event,policy.DEFAULT_ORIGIN+pathname+'#receipt');assert.equal(result.ok,false);assert.match(result.error,/lost original board print reply/);
    assert.deepEqual((await state.read()).pending,[identity]);assert.equal(records.has('remote-central-responses/'+identity),false);assert.equal(f.windows.length,2);
    await assert.rejects(new RemoteAttempts(options).recover(),/لم يتأكد رد/);await assert.rejects(state.snapshot(async()=>{}),/غير مؤكدة/);
    assert.equal(JSON.stringify([...records]).includes(credential.token),false);
  }
});
test('connected actor proof replaces renderer forgeries only after native authorization, strips foreign copies and cancels unowned proof forgeries',async t=>{
  const device='00000000-0000-4000-8000-000000000001',token='a'.repeat(64),proof={'X-Fasakhansta-Connected-Device':device,'X-Fasakhansta-Connected-Token':token};
  let release;const f=fixture(t,{remoteAttempts:{begin:async()=>{await new Promise(resolve=>release=resolve);return proof;}}});await f.dashboard.open();
  const request={id:1000,url:policy.DEFAULT_ORIGIN+'/admin/users',method:'POST',webContentsId:f.windows[0].webContents.id,
    requestHeaders:{Cookie:'captured-other-actor-cookie','x-fasakhansta-connected-device':'forged','X-Fasakhansta-Connected-Token':'forged',
      'x-fasakhansta-connected-request':'forged-marker','X-Fasakhansta-Remote-Attempt':'forged','X-Fasakhansta-Remote-Capability':'forged'}};let outgoing;
  f.session.webRequest.headers(request,value=>outgoing=value);await Promise.resolve();assert.equal(outgoing,undefined);release();await new Promise(resolve=>setImmediate(resolve));
  assert.equal(outgoing.requestHeaders.Cookie,'captured-other-actor-cookie');assert.equal(outgoing.requestHeaders['X-Fasakhansta-Connected-Device'],device);assert.equal(outgoing.requestHeaders['X-Fasakhansta-Connected-Token'],token);
  assert.equal(Object.keys(outgoing.requestHeaders).some(name=>/Remote-(?:Attempt|Capability)|Connected-Request/i.test(name)),false);
  for(const value of [{...request,id:1001,url:'https://foreign.test/admin/users'},
    {...request,id:1002,webContentsId:-1},{...request,id:1003,webContentsId:9999}]){
    f.session.webRequest.headers({...value,requestHeaders:{...outgoing.requestHeaders,'X-Fasakhansta-Connected-Request':'invented-marker'}},result=>outgoing=result);
    if(value.webContentsId===-1)assert.equal(outgoing.cancel,true);
    else assert.equal(Object.keys(outgoing.requestHeaders).some(name=>/^x-fasakhansta-/i.test(name)),false);
  }
});
test('native print IPC cannot bypass a matching actor whose retained server lacks the exact connected request guard protocol',async t=>{
  const crypto=require('node:crypto'),RemoteState=require('../src/dashboard-remote-state.cjs'),RemoteAttempts=require('../src/dashboard-remote-attempts.cjs'),{DashboardConnected,binding}=require('../src/dashboard-connected.cjs');
  for(const protocol of [undefined,0,2,'1',null,true]){
    const credential={deviceId:crypto.randomUUID(),actorId:1,token:'a'.repeat(64),serverOrigin:policy.DEFAULT_ORIGIN,sourceRevision:'b'.repeat(40),
      sourceFingerprint:{format:1,sha256:'c'.repeat(64),framework:'8.83.29',files:100},snapshotId:crypto.randomUUID(),refreshId:crypto.randomUUID(),schemaHash:'d'.repeat(64),database:'fasakhansta_dashboard_stage_'+'e'.repeat(16)};
    const records=new Map([['central-mode',{format:1,phase:'central',binding:binding(credential)}]]),metadata={read:async key=>structuredClone(records.get(key)||null),write:async(key,value)=>records.set(key,structuredClone(value))};
    const state=new RemoteState(metadata),runtime={metadata,connection:async()=>credential},access=new DashboardConnected({runtime,
      probe:async()=>({actor_id:1,device_id:credential.deviceId,...(protocol===undefined?{}:{connected_request_protocol:protocol})})});
    const attempts=new RemoteAttempts({state,metadata,credential:async()=>credential,direct:details=>access.authorizeDirect(details),request:async()=>{throw Error('No bounded fallback is permitted.');}});
    const f=fixture(t,{remoteAttempts:attempts});await f.dashboard.open();let fetched=0;f.session.fetch=async()=>{fetched++;throw Error('Old server must not receive a consuming print GET.');};
    const sender=f.windows[0].webContents,event={sender,senderFrame:{url:sender.getURL()}},handler=f.handlers.get('dashboard:print-receipt');
    for(const source of ['legacy','store','service','partner_service']){
      const result=await handler(event,policy.DEFAULT_ORIGIN+'/admin/order-board/'+source+'/20/print');assert.equal(result.ok,false);assert.match(result.error,/لم يؤكد حماية/);
    }
    assert.equal(fetched,0);assert.equal(f.windows.length,1);await state.assertClean();assert.equal([...records.keys()].some(key=>key.startsWith('remote-central')),false);
  }
});

test('native server renewal shares only the login cookie partition and blocks every business request until verified',async t=>{
  const local='http://127.0.0.1:43219',f=fixture(t,{origin:local,serverOrigin:policy.DEFAULT_ORIGIN,localToken:'local-private',reauthenticate:async()=>{}});
  await f.dashboard.open();let verified=0,release;const removals=[];
  f.session.cookies.get=async filter=>{assert.equal(filter.url,policy.DEFAULT_ORIGIN+'/admin/login');return [{name:'server-session',path:'/'}];};
  f.session.cookies.remove=async(...args)=>removals.push(args);
  const renewing=f.dashboard.reauthenticate(policy.DEFAULT_ORIGIN,async()=>{verified++;await new Promise(resolve=>release=resolve);});
  await new Promise(resolve=>setImmediate(resolve));const auth=f.windows[1],main=f.windows[0];
  assert.equal(auth.url,policy.DEFAULT_ORIGIN+'/admin/login');assert.equal(auth.options.modal,true);assert.equal(auth.options.parent,main);
  assert.equal(auth.options.webPreferences.partition,'persist:fasakhansta-dashboard');assert.equal(auth.options.webPreferences.preload,undefined);
  assert.equal(auth.options.webPreferences.nodeIntegration,false);assert.equal(auth.options.webPreferences.contextIsolation,true);
  assert.deepEqual(removals,[[policy.DEFAULT_ORIGIN+'/','server-session']]);
  const headers=(url,method='GET',id=auth.webContents.id)=>{let result;f.session.webRequest.headers({url,method,webContentsId:id,requestHeaders:{'X-Fasakhansta-Desktop':'stolen','X-Fasakhansta-Connected-Token':'stolen'}},value=>result=value);return result;};
  for(const pathname of ['/admin/login','/dashboard/dist/css/adminlte.min.css','/dashboard/branding/dashboard-brand.css?v=1','/dashboard/plugins/fontawesome-free/webfonts/fa-solid-900.woff2'])
    assert.deepEqual(Object.keys(headers(policy.DEFAULT_ORIGIN+pathname).requestHeaders),[]);
  for(const [url,method] of [[policy.DEFAULT_ORIGIN+'/admin/resturantControl','GET'],[policy.DEFAULT_ORIGIN+'/admin/users/20','POST'],
    ...['legacy','store','service','partner_service'].map(source=>[policy.DEFAULT_ORIGIN+'/admin/order-board/'+source+'/20/print','GET']),
    [policy.DEFAULT_ORIGIN+'/admin/login/','GET'],[policy.DEFAULT_ORIGIN+'/%61dmin/signin','POST'],[policy.DEFAULT_ORIGIN+'/dashboard/../admin/resturantControl','GET'],
    [policy.DEFAULT_ORIGIN+'/admin/route.css','GET'],['https://user:pass@fasakhaninja.com/admin/login','GET'],['https://foreign.test/asset.css','GET'],
    [policy.DEFAULT_ORIGIN+'/admin/signin','GET'],[policy.DEFAULT_ORIGIN+'/admin/login','POST']])assert.equal(headers(url,method).cancel,true,url);
  assert.equal(headers(local+'/admin/employees/save','POST',main.webContents.id).cancel,true);
  assert.equal(auth.webContents.popup({url:policy.DEFAULT_ORIGIN+'/admin/dashboard'}).action,'deny');
  let prevented=false;auth.webContents.emit('will-navigate',{preventDefault(){prevented=true;}},policy.DEFAULT_ORIGIN+'/admin/signin');assert.equal(prevented,false,'Original POST navigation must reach the verb guard.');
  assert.deepEqual(Object.keys(headers(policy.DEFAULT_ORIGIN+'/admin/signin','POST').requestHeaders),[]);
  assert.equal(headers(policy.DEFAULT_ORIGIN+'/admin/signin','POST').cancel,true,'A second signin cannot race the first actor probe.');
  auth.webContents.emit('will-redirect',{preventDefault(){prevented=true;}},policy.DEFAULT_ORIGIN+'/admin/applies-orders');
  await Promise.resolve();assert.equal(prevented,true);assert.equal(verified,1);assert.equal(await f.dashboard.canRefresh(),false);
  assert.equal(headers(policy.DEFAULT_ORIGIN+'/admin/signin','POST').cancel,true);
  f.session.webRequest.error({url:policy.DEFAULT_ORIGIN+'/admin/login',error:'net::ERR_CONNECTION_RESET'});assert.equal(f.notices.length,0);
  const sender=main.webContents,event={sender,senderFrame:{url:sender.getURL()}};
  assert.equal((await f.handlers.get('dashboard:status')(event)).ok,false);
  assert.equal((await f.handlers.get('dashboard:print-receipt')(event,local+'/admin/takeaway/1/print')).ok,false);
  await assert.rejects(f.dashboard.switchTo({origin:policy.DEFAULT_ORIGIN,localToken:''}),/تسجيل الدخول/);
  release();assert.equal((await renewing).authenticated,true);assert.equal(auth.destroyed,true);assert.equal(f.dashboard.current().origin,local);assert.equal(f.dashboard.current().local,true);
  assert.equal(headers(local+'/admin/dashboard','GET',main.webContents.id).requestHeaders['X-Fasakhansta-Desktop'],'local-private');
  assert.ok(f.electron.menu[0].submenu.some(value=>value.label==='تجديد تسجيل دخول السيرفر'));
  assert.equal([...f.handlers.keys()].some(name=>/reauth|login/.test(name)),false);
});

test('failed or cancelled renewal clears new cookies before resuming the old view and shutdown settles pending login',async t=>{
  for(const failure of ['wrong-actor','cancel','shutdown','load','destroyed']){
    const f=fixture(t),removed=[];await f.dashboard.open();let reads=0,release;
    f.session.cookies.get=async()=>[{name:'server-session',path:'/'}];
    f.session.cookies.remove=async(url,name)=>{removed.push([url,name]);if(++reads===2&&failure==='wrong-actor')await new Promise(resolve=>release=resolve);};
    const renewal=f.dashboard.reauthenticate(policy.DEFAULT_ORIGIN,async()=>{throw Error('wrong actor');});
    const rejected=assert.rejects(renewal,/wrong actor|إلغاء|تعذر تحميل/);await new Promise(resolve=>setImmediate(resolve));const auth=f.windows[1];
    if(failure==='wrong-actor'){
      f.session.webRequest.headers({url:policy.DEFAULT_ORIGIN+'/admin/signin',method:'POST',webContentsId:auth.webContents.id,requestHeaders:{}},()=>{});
      auth.webContents.emit('will-redirect',{preventDefault(){}},policy.DEFAULT_ORIGIN+'/admin/dashboard');
      await new Promise(resolve=>setImmediate(resolve));assert.equal(auth.destroyed,false);assert.equal(await f.dashboard.canRefresh(),false);
      let result;f.session.webRequest.headers({url:policy.DEFAULT_ORIGIN+'/admin/users/20',method:'POST',webContentsId:f.windows[0].webContents.id,requestHeaders:{}},value=>result=value);assert.equal(result.cancel,true);release();
    }else if(failure==='cancel')auth.emit('close',{preventDefault(){}});
    else if(failure==='shutdown'){f.close();await f.dashboard.cancelAuthentication();}
    else if(failure==='load')auth.webContents.emit('did-fail-load',{},-106,'net::ERR_INTERNET_DISCONNECTED',auth.url,true);
    else {auth.destroy();auth.emit('closed');}
    await rejected;assert.equal(removed.length,2);assert.equal(auth.destroyed,true);assert.equal(f.dashboard.current().origin,policy.DEFAULT_ORIGIN);
    if(failure!=='shutdown')assert.equal(await f.dashboard.canRefresh(),true);
  }
});

test('actual Shutdown drains an accepted native login cancellation before final quit',async t=>{
  const Shutdown=require('../src/shutdown.cjs'),f=fixture(t),app=new EventEmitter();let quitCount=0,drained=false;
  app.quit=()=>{quitCount++;app.emit('before-quit',{preventDefault(){}});};
  const shutdown=new Shutdown(app,()=>{f.close();f.dashboard.cancelAuthentication();},()=>{},async()=>{drained=true;});
  await f.dashboard.open();const renewal=shutdown.run(()=>f.dashboard.reauthenticate(policy.DEFAULT_ORIGIN,async()=>{}));
  const rejected=assert.rejects(renewal,/إلغاء/);await new Promise(resolve=>setImmediate(resolve));assert.equal(shutdown.pending.size,1);
  app.quit();await rejected;await new Promise(resolve=>setImmediate(resolve));
  assert.equal(shutdown.pending.size,0);assert.equal(shutdown.ready,true);assert.equal(drained,true);assert.equal(quitCount,2);assert.equal(f.windows[1].destroyed,true);
});
