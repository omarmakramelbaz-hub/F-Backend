const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const {EventEmitter}=require('node:events');
const policy=require('../src/dashboard-policy.cjs');

function fixture(t, options = {}){
  let next=0,closing=false;const windows=[],handlers=new Map(),external=[],notices=[];let pageHTML='<html data-dashboard-receipt="takeaway"><head><style>body{color:black}</style><script>window.print()</script></head><body>فاتورة</body></html>';
  const session={fetch:async(_url,options)=>{assert.equal(options.credentials,'include');return new Response(pageHTML,{headers:{'content-type':'text/html; charset=utf-8'}});},
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
  const module={exports:{}};vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../src/dashboard.cjs'),'utf8'),{module,__dirname:path.join(__dirname,'../src'),require:name=>name==='electron'?electron:require(name==='./dashboard-policy.cjs'?'../src/dashboard-policy.cjs':name),URL,AbortSignal,Set,Error,Promise});
  const dashboard=module.exports({origin:policy.DEFAULT_ORIGIN,offline:message=>notices.push(message),offlineWindow:()=>windows[0],quitting:()=>closing,quit:()=>{},printer:()=> 'BranchPrinter',...options});
  return {dashboard,windows,handlers,external,notices,session,electron,html:value=>{pageHTML=value;},close:()=>{closing=true;}};
}

test('installed app opens the original complete dashboard and keeps a persistent signed-in session',async t=>{
  const f=fixture(t);const opening=f.dashboard.open();const w=f.windows[0];assert.ok(w.visible,'dashboard frame and offline menu remain available during loading');await opening;
  assert.equal(w.url,'https://fasakhaninja.com/admin/dashboard');assert.ok(w.visible);
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
  assert.equal(name,'FasakhanstaDesktop');assert.deepEqual(Object.keys(api),['printReceipt','status','prepare','synchronize','onState']);
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
  await f.dashboard.refresh(async()=>{
    let decision;f.session.webRequest.headers({url:origin+'/admin/save',webContentsId:window.webContents.id,requestHeaders:{}},value=>{decision=value;});
    assert.equal(decision.cancel,true);assert.ok(child.destroyed);assert.ok(window.webContents.stopped);
    assert.equal(window.webContents.popup({url:origin+'/admin/dashboard'}).action,'deny');
  });
  assert.equal(window.url,origin+'/admin/dashboard');assert.equal(f.windows.filter(value=>!value.destroyed).length,1);
  let decision;f.session.webRequest.headers({url:origin+'/admin/dashboard',webContentsId:window.webContents.id,requestHeaders:{}},value=>{decision=value;});
  assert.equal(decision.requestHeaders['X-Fasakhansta-Desktop'],'private');
});
