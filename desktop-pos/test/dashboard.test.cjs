const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const {EventEmitter}=require('node:events');
const policy=require('../src/dashboard-policy.cjs');

function fixture(t, options = {}){
  let next=0,closing=false;const windows=[],handlers=new Map(),external=[],notices=[];let pageHTML='<html data-dashboard-receipt="takeaway"><head><style>body{color:black}</style><script>window.print()</script></head><body>فاتورة</body></html>';
  const session={fetch:async(_url,options)=>{assert.equal(options.credentials,'include');return new Response(pageHTML,{headers:{'content-type':'text/html; charset=utf-8'}});},
    webRequest:{onErrorOccurred(_filter,handler){this.error=handler;},onBeforeSendHeaders(_filter,handler){this.headers=handler;}},setPermissionCheckHandler(fn){this.check=fn;},setPermissionRequestHandler(fn){this.permission=fn;}};
  class Window extends EventEmitter{
    constructor(options){super();this.options=options;this.visible=false;this.destroyed=false;this.webContents=new EventEmitter();const contents=this.webContents;
      contents.id=++next;contents.session=session;contents.setWindowOpenHandler=fn=>{contents.popup=fn;};
      contents.stop=()=>{contents.stopped=true;};
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
test('remote dashboard exposes only native receipt printing and never the offline store or device token',()=>{
  let api,name;vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../src/dashboard-preload.cjs'),'utf8'),{require:()=>({contextBridge:{exposeInMainWorld:(n,a)=>{name=n;api=a;}},ipcRenderer:{invoke:async()=>({ok:true,value:true})}})});
  assert.equal(name,'FasakhanstaDesktop');assert.deepEqual(Object.keys(api),['printReceipt']);
  assert.ok(policy.sameOrigin('https://fasakhaninja.com/admin/customers'));assert.equal(policy.sameOrigin('https://fasakhaninja.com.evil.test/admin'),false);
  assert.equal(policy.sameOrigin('https://user:password@fasakhaninja.com/admin'),false);assert.equal(policy.externalURL('file:///x'),false);
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
