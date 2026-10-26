const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const {EventEmitter}=require('node:events');
const policy=require('../src/dashboard-policy.cjs');
const recallerName='remember_admin_'+require('node:crypto').createHash('sha1').update('Illuminate\\Auth\\SessionGuard').digest('hex');
const nativeCookie=(name,changes={})=>({name,value:'private-cookie-bytes',domain:'fasakhaninja.com',path:'/',hostOnly:true,httpOnly:true,
  session:true,secure:true,...changes});

function fixture(t, options = {}){
  let next=0,closing=false;const windows=[],handlers=new Map(),external=[],notices=[];let pageHTML='<html data-dashboard-receipt="takeaway"><head><style>body{color:black}</style><script>window.print()</script></head><body>فاتورة</body></html>';
  const session={cookies:{get:async()=>[],remove:async()=>{},flushStore:async()=>{}},fetch:async(_url,options)=>{assert.equal(options.credentials,'include');return new Response(pageHTML,{headers:{'content-type':'text/html; charset=utf-8'}});},
    webRequest:{onCompleted(_filter,handler){this.completed=handler;},onErrorOccurred(_filter,handler){this.error=handler;},onBeforeSendHeaders(_filter,handler){this.headers=handler;}},setPermissionCheckHandler(fn){this.check=fn;},setPermissionRequestHandler(fn){this.permission=fn;}};
  class Window extends EventEmitter{
    constructor(options){super();this.options=options;this.visible=false;this.showCalls=0;this.destroyed=false;this.webContents=new EventEmitter();const contents=this.webContents;
      contents.id=++next;contents.session=session;contents.setWindowOpenHandler=fn=>{contents.popup=fn;};
      const owner=this;contents.mainFrame={get url(){return owner.url;}};
      contents.stop=()=>{contents.stopped=true;};contents.send=(name,value)=>{contents.lastMessage={name,value};};
      contents.getURL=()=>this.url;contents.getPrintersAsync=async()=>[{name:'BranchPrinter'}];
      contents.executeJavaScript=async()=>true;contents.print=(options,callback)=>{this.printOptions=options;callback?.(true);};windows.push(this);}
    maximize(){} async loadURL(url){this.url=url;(this.loads||=[]).push(url);if(this.failure)throw Error(this.failure);if(options.loadURL)await options.loadURL(this,url);} show(){this.visible=true;this.showCalls++;} hide(){this.visible=false;} focus(){} isMinimized(){return false;} isDestroyed(){return this.destroyed;} isVisible(){return this.visible;} destroy(){this.destroyed=true;} reload(){}
    static getFocusedWindow(){return windows.find(w=>w.visible);}
  }
  const electron={BrowserWindow:Window,ipcMain:{handle:(name,fn)=>handlers.set(name,fn)},Menu:{buildFromTemplate:template=>template,setApplicationMenu:menu=>{electron.menu=menu;}},shell:{openExternal:url=>external.push(url)},dialog:{showMessageBox:async()=>({response:1})}};
  const module={exports:{}};vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../src/dashboard.cjs'),'utf8'),{module,__dirname:path.join(__dirname,'../src'),require:name=>name==='electron'?electron:require(name.startsWith('./')?path.join(__dirname,'../src',name):name),URL,AbortSignal,Set,Error,Promise,setTimeout,clearTimeout});
  const dashboard=module.exports({origin:policy.DEFAULT_ORIGIN,offline:message=>notices.push(message),offlineWindow:()=>windows[0],quitting:()=>closing,quit:()=>{},printer:()=> 'BranchPrinter',...options});
  return {dashboard,windows,handlers,external,notices,session,electron,html:value=>{pageHTML=value;},close:()=>{closing=true;}};
}

test('installed app opens the original complete dashboard and keeps a persistent signed-in session',async t=>{
  const f=fixture(t);const opening=f.dashboard.open();const w=f.windows[0];assert.equal(w.visible,false,'The first blank frame must not be exposed.');await opening;
  assert.equal(w.url,'https://fasakhaninja.com/admin/dashboard');assert.ok(w.visible);
  assert.equal(w.options.title,'GO ERP — الداشبورد');
  assert.equal(w.options.fullscreen,true,'the dashboard starts in native full screen');
  let titlePrevented=false;w.webContents.emit('page-title-updated',{preventDefault(){titlePrevented=true;}},'Original business page');
  assert.equal(titlePrevented,true,'A dashboard page must not replace the native GO ERP title');
  assert.equal(w.options.webPreferences.partition,'persist:fasakhansta-dashboard');
  assert.equal(w.options.webPreferences.nodeIntegration,false);assert.equal(w.options.webPreferences.contextIsolation,true);
  assert.match(w.options.webPreferences.preload,/dashboard-preload/);
  await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/branch-expenses');assert.equal(f.windows.length,1);assert.match(w.url,/branch-expenses$/);
});
test('a native account partition is validated and shared by dashboard, child, receipt and reauthentication windows',async t=>{
  const partition='persist:fasakhansta-dashboard-01234567-89ab-4cde-8fab-0123456789ab';
  for(const invalid of ['',null,'temporary','persist:other','persist:fasakhansta-dashboard-0123456789abcdef',partition.toUpperCase(),partition+'/other'])
    assert.throws(()=>fixture(t,{partition:invalid}),/ملف جلسة/);
  const f=fixture(t,{partition});await f.dashboard.open();const contents=f.windows[0].webContents;
  assert.equal(f.windows[0].options.webPreferences.partition,partition);
  assert.equal(contents.popup({url:policy.DEFAULT_ORIGIN+'/admin/employees'}).overrideBrowserWindowOptions.webPreferences.partition,partition);
  const printed=await f.handlers.get('dashboard:print-receipt')({sender:contents,senderFrame:{url:contents.getURL()}},policy.DEFAULT_ORIGIN+'/admin/takeaway/receipts/1/print');
  assert.equal(printed.ok,true);assert.equal(f.windows[1].options.webPreferences.partition,partition);
  const renewing=f.dashboard.reauthenticate(policy.DEFAULT_ORIGIN,async()=>{});await new Promise(resolve=>setImmediate(resolve));
  assert.equal(f.windows[2].options.webPreferences.partition,partition);f.dashboard.cancelAuthentication();
  await assert.rejects(renewing,/إلغاء/);
});
test('account switching exposes only an optional native menu and argument-free owned login-frame action',async t=>{
  let switches=0,finish;const f=fixture(t,{switchAccount:()=>{switches++;return new Promise(resolve=>finish=resolve);}});await f.dashboard.open();
  const contents=f.windows[0].webContents,available=f.handlers.get('dashboard:account-switch-available'),invoke=f.handlers.get('dashboard:switch-account');
  const event={sender:contents,senderFrame:contents.mainFrame};
  assert.equal(available(event).value,false);assert.equal((await invoke(event)).ok,false);
  await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/login');assert.equal(available(event).value,true);
  for(const [sender,value] of [[{sender:contents,senderFrame:{url:contents.mainFrame.url}},undefined],[event,{account:'forged'}]]){
    assert.equal((await invoke(sender,value)).ok,false);
  }
  const switching=invoke(event);await Promise.resolve();assert.equal(switches,1);
  assert.equal((await invoke(event)).ok,false,'one native account selection may run at a time');finish('selected');
  assert.equal((await switching).value,'selected');
  const menu=f.electron.menu[0].submenu.find(item=>item.label==='تبديل الحساب');assert.ok(menu);const selected=menu.click();await Promise.resolve();
  assert.equal(switches,2);finish('selected again');await selected;
  f.close();assert.equal((await invoke(event)).ok,false);assert.equal(switches,2);
  const ordinary=fixture(t);await ordinary.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/login');
  assert.equal(ordinary.electron.menu[0].submenu.some(item=>item.label==='تبديل الحساب'),false);
  const sender=ordinary.windows[0].webContents;assert.equal(ordinary.handlers.get('dashboard:account-switch-available')({sender,senderFrame:sender.mainFrame}).value,false);
});
test('the first real dashboard stays hidden until DOM readiness and does not wait for fonts and images',async t=>{
  let finishLoad,visible=0;const events=[];
  const f=fixture(t,{onVisible:()=>visible++,onNavigation:entry=>events.push(entry),loadURL:()=>new Promise(resolve=>finishLoad=resolve)});
  let settled=false;const opening=f.dashboard.open().then(()=>{settled=true;});
  assert.equal(visible,0);assert.equal(f.windows[0].visible,false);
  f.dashboard.reveal();assert.equal(f.windows[0].visible,false,'A second launch cannot show a first blank frame.');
  await Promise.resolve();assert.equal(settled,false);
  f.windows[0].webContents.emit('dom-ready');await opening;
  assert.equal(settled,true);assert.equal(f.windows[0].visible,true);assert.ok(visible>=1);assert.ok(events.some(event=>event.phase==='interactive'));
  const shown=visible;f.dashboard.reveal();assert.equal(visible,shown+1,'Once real content is ready, normal foregrounding remains available.');
  finishLoad();await Promise.resolve();assert.equal(events.filter(event=>event.phase==='loaded').length,0);
});
test('an initial empty document cannot reveal a first blank frame before a real login redirect is ready',async t=>{
  let finishLoad;const f=fixture(t,{loadURL:()=>new Promise(resolve=>finishLoad=resolve)});
  t.after(()=>finishLoad?.());
  let settled=false;const opening=f.dashboard.open().then(()=>settled=true);await Promise.resolve();
  const window=f.windows[0];window.url='about:blank';window.webContents.emit('dom-ready');
  f.dashboard.reveal();await Promise.resolve();assert.equal(window.visible,false);assert.equal(settled,false);
  window.url=policy.DEFAULT_ORIGIN+'/admin/login';window.webContents.emit('dom-ready');await new Promise(setImmediate);
  assert.equal(settled,true,'The real login DOM must settle readiness without waiting for the first GET deadline.');await opening;
  assert.equal(window.visible,true);assert.equal(window.url,policy.DEFAULT_ORIGIN+'/admin/login');
  finishLoad();
});
test('a failed first navigation reveals its native failure surface without exposing the bootstrap loader or stranding the app',async t=>{
  for(const message of ['net::ERR_INTERNET_DISCONNECTED','net::ERR_HTTP_RESPONSE_CODE_FAILURE']){
    const dialogs=[];const f=fixture(t,{loadURL:async()=>{throw Error(message);}});
    f.electron.dialog.showMessageBox=async(_window,details)=>{dialogs.push(details);return {response:1};};
    const opening=f.dashboard.open();assert.equal(f.windows[0].visible,false);await opening;
    assert.equal(f.windows[0].visible,true);assert.equal(f.windows[0].showCalls,1);
    assert.deepEqual(f.windows[0].loads,[policy.DEFAULT_ORIGIN+'/admin/dashboard']);
    assert.equal(message.includes('INTERNET')?f.notices.length:dialogs.length,1);
    assert.equal(f.windows[0].loads.some(url=>url.includes('startup.html')),false);
  }
});
test('an initial GET which never returns reaches a finite deadline and keeps its native window available',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});const events=[],dialogs=[];
  const f=fixture(t,{onNavigation:entry=>events.push(entry),loadURL:()=>new Promise(()=>{})});
  f.electron.dialog.showMessageBox=async(_window,details)=>{dialogs.push(details);return {response:1};};
  const opening=f.dashboard.open();await Promise.resolve();t.mock.timers.tick(45000);await opening;
  assert.equal(f.windows[0].webContents.stopped,true);assert.equal(f.windows[0].visible,true);
  assert.ok(events.some(event=>event.phase==='timeout'));assert.equal(dialogs.length,1);
});
test('initial navigation supervision hands off before an original POST and never stops or replays that request',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});let finishLoad;const events=[];
  const f=fixture(t,{onNavigation:entry=>events.push(entry),remoteState:{begin:async()=>{}},loadURL:()=>new Promise(resolve=>finishLoad=resolve)});
  const opening=f.dashboard.open();await Promise.resolve();const window=f.windows[0];let decision;
  f.session.webRequest.headers({id:15,url:policy.DEFAULT_ORIGIN+'/admin/signin',method:'POST',resourceType:'mainFrame',
    webContentsId:window.webContents.id,requestHeaders:{Cookie:'private-session','X-CSRF-TOKEN':'private-csrf'}},value=>decision=value);
  await opening;await new Promise(resolve=>setImmediate(resolve));
  assert.equal(window.visible,false,'A supervised POST handoff does not make a first unrendered document visible.');
  window.webContents.emit('dom-ready');assert.equal(window.visible,true,'The real response can reveal itself after supervision hands off.');
  assert.equal(decision.requestHeaders.Cookie,'private-session');assert.equal(decision.requestHeaders['X-CSRF-TOKEN'],'private-csrf');
  t.mock.timers.tick(45000);assert.equal(window.webContents.stopped,undefined);
  assert.deepEqual(window.loads,[policy.DEFAULT_ORIGIN+'/admin/dashboard']);assert.ok(events.some(event=>event.phase==='user-navigation'));
  finishLoad();
});
test('main-frame response traces contain only a mode, safe route class, elapsed time and HTTP status',async t=>{
  const events=[],f=fixture(t,{onNavigation:entry=>events.push(entry)});await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/dashboard?token=secret-token');
  const window=f.windows[0];window.url=policy.DEFAULT_ORIGIN+'/admin/reset/secret-token?password=private';
  window.webContents.emit('did-navigate',{},window.url,403,'Forbidden');
  const response=events.at(-1);assert.equal(response.phase,'response');assert.equal(response.mode,'server');assert.equal(response.path,'/admin/other');assert.equal(response.status,403);
  assert.deepEqual(Object.keys(response).sort(),['elapsed_ms','mode','path','phase','status']);
  assert.equal(/secret|password|private/.test(JSON.stringify(events)),false);
});
test('a main-frame 419 offers an explicit fresh GET login without clearing cookies or replaying the rejected POST',async t=>{
  for(const accept of [false,true]){
    const f=fixture(t);await f.dashboard.open();const window=f.windows[0],cookies=[];let prompts=0;
    f.session.cookies.remove=async(...args)=>cookies.push(args);
    f.electron.dialog.showMessageBox=async(_window,details)=>{prompts++;assert.match(details.message,/انتهت جلسة/);return {response:accept?0:1};};
    window.url=policy.DEFAULT_ORIGIN+'/admin/signin';window.webContents.emit('did-navigate',{},window.url,419,'Page Expired');
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(prompts,1);assert.deepEqual(cookies,[]);
    assert.deepEqual(window.loads,accept?[policy.DEFAULT_ORIGIN+'/admin/dashboard',policy.DEFAULT_ORIGIN+'/admin/login']:[policy.DEFAULT_ORIGIN+'/admin/dashboard']);
    assert.equal(window.loads.some(url=>url.endsWith('/admin/signin')),false);
  }
});
test('a stale 419 dialog cannot replace a newer page or navigate after shutdown begins',async t=>{
  for(const closing of [false,true]){
    let choose;const f=fixture(t);await f.dashboard.open();const window=f.windows[0];
    f.electron.dialog.showMessageBox=()=>new Promise(resolve=>choose=resolve);
    window.url=policy.DEFAULT_ORIGIN+'/admin/signin';window.webContents.emit('did-navigate',{},window.url,419,'Page Expired');
    await Promise.resolve();
    if(closing)f.close();else{window.url=policy.DEFAULT_ORIGIN+'/admin/employees';window.webContents.emit('did-navigate',{},window.url,200,'OK');}
    choose({response:0});await new Promise(resolve=>setImmediate(resolve));
    assert.deepEqual(window.loads,[policy.DEFAULT_ORIGIN+'/admin/dashboard']);
  }
});
test('closing a still-loading dashboard releases readiness without reading a destroyed native view',async t=>{
  const f=fixture(t,{loadURL:()=>new Promise(()=>{})});const opening=f.dashboard.open();await Promise.resolve();
  const window=f.windows[0];f.close();window.destroy();
  window.webContents.getURL=()=>{throw Error('Object has been destroyed');};
  assert.doesNotThrow(()=>window.emit('closed'));await opening;
});
test('explicit remembered logout preserves the original request, then clears scoped sessions and recaller before quitting',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});let quits=0,flushed=false,marked=false;const removed=[];
  const remember=nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000+86400,value:'encrypted-original-recaller'});
  const cookies=[nativeCookie('fasakhaninja_session'),remember,nativeCookie('unrelated-preference',{httpOnly:false}),
    nativeCookie('foreign_session',{domain:'other.test'}),nativeCookie('persistent_session',{session:false,expirationDate:Date.now()/1000+86400})];
  const f=fixture(t,{beforeLogout:async()=>{marked=true;},quit:()=>{assert.equal(flushed,true);quits++;}});await f.dashboard.open();
  f.session.cookies.get=async filter=>filter.url.startsWith(policy.DEFAULT_ORIGIN+'/')?cookies:[];
  f.session.cookies.remove=async(...args)=>removed.push(args);f.session.cookies.flushStore=async()=>{flushed=true;};
  const request={id:501,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',
    webContentsId:f.windows[0].webContents.id,requestHeaders:{Cookie:'original-session-and-recaller'}};
  let decision;f.session.webRequest.headers(request,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));
  assert.equal(marked,true);assert.equal(decision.requestHeaders.Cookie,'original-session-and-recaller');assert.equal(quits,0);assert.deepEqual(removed,[]);
  f.session.webRequest.completed({...request,url:policy.DEFAULT_ORIGIN+'/admin/login',statusCode:200});await new Promise(resolve=>setImmediate(resolve));
  assert.equal(quits,1);assert.deepEqual(removed,[[policy.DEFAULT_ORIGIN+'/','fasakhaninja_session'],[policy.DEFAULT_ORIGIN+'/',recallerName],[policy.DEFAULT_ORIGIN+'/','persistent_session']]);
  assert.equal(await f.dashboard.canRefresh(),false);
});
test('explicit logout retires Laravel expire_on_close=false session cookies and the protected recaller while preserving XSRF',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});const removed=[];let flushed=false,quits=0;
  const originalSession=nativeCookie('fasakhaninja_session',{session:false,expirationDate:Date.now()/1000+7*86400});
  const protectedRecaller=nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000+30*86400,value:'protected-original-recaller'});
  const f=fixture(t,{quit:()=>{assert.equal(flushed,true);quits++;}});await f.dashboard.open();
  f.session.cookies.get=async filter=>filter.url.startsWith(policy.DEFAULT_ORIGIN+'/')?[originalSession,protectedRecaller,nativeCookie('XSRF-TOKEN',{httpOnly:false})]:[];
  f.session.cookies.remove=async(...args)=>removed.push(args);f.session.cookies.flushStore=async()=>{flushed=true;};
  let decision;const request={id:527,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',
    webContentsId:f.windows[0].webContents.id,requestHeaders:{Cookie:'not-recorded'}};
  f.session.webRequest.headers(request,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));
  assert.ok(decision.requestHeaders);assert.equal(quits,0);
  f.windows[0].url=policy.DEFAULT_ORIGIN+'/admin/login';f.windows[0].webContents.emit('did-navigate',{},f.windows[0].url,200,'OK');await new Promise(resolve=>setImmediate(resolve));
  assert.equal(quits,1);assert.deepEqual(removed,[[policy.DEFAULT_ORIGIN+'/','fasakhaninja_session'],[policy.DEFAULT_ORIGIN+'/',recallerName]]);
  assert.equal(removed.some(([,name])=>name==='XSRF-TOKEN'),false);
});

test('explicit logout marks before transmission and clears only this account local and server auth after the original response',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});
  for(const origin of ['http://127.0.0.1:43123',policy.DEFAULT_ORIGIN]){
    const local='http://127.0.0.1:43123',stages=[],removed=[];let quits=0,flushed=0,marks=0;
    let cookies=[nativeCookie('laravel_session',{domain:'127.0.0.1',secure:false}),
      nativeCookie(recallerName,{domain:'127.0.0.1',secure:false,session:false,expirationDate:Date.now()/1000+86400}),
      nativeCookie('laravel_session'),nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000+86400}),
      nativeCookie('preference',{httpOnly:false}),nativeCookie('XSRF-TOKEN',{httpOnly:false}),
      nativeCookie(recallerName,{domain:'foreign.test',session:false,expirationDate:Date.now()/1000+86400})];
    const otherAccountCookies=[nativeCookie(recallerName,{value:'other-private-profile-cookie'})],otherBefore=structuredClone(otherAccountCookies);
    const f=fixture(t,{origin,serverOrigin:policy.DEFAULT_ORIGIN,localToken:origin===local?'private-token':'',
      beforeLogout:async()=>{marks++;stages.push('durable-intent');},
      accepted:async work=>{stages.push('accepted');return work();},quit:()=>{assert.equal(flushed,1);quits++;stages.push('quit');}});
    await f.dashboard.open();const window=f.windows[0],contents=window.webContents;
    f.session.cookies.get=async({url})=>cookies.filter(cookie=>cookie.domain===new URL(url).hostname);
    f.session.cookies.remove=async(url,name)=>{removed.push([url,name]);cookies=cookies.filter(cookie=>!(cookie.domain===new URL(url).hostname&&cookie.name===name));};
    f.session.cookies.flushStore=async()=>{flushed++;stages.push('flush');};
    const request={id:528,url:origin+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',webContentsId:contents.id,
      requestHeaders:{Cookie:'the-original-authenticated-session'}};
    Object.defineProperty(request,'uploadData',{get(){throw Error('No credentials are read during logout.');}});
    let decision;f.session.webRequest.headers(request,value=>{stages.push('transmitted');decision=value;});await new Promise(resolve=>setImmediate(resolve));
    assert.equal(marks,1);assert.ok(stages.indexOf('durable-intent')<stages.indexOf('transmitted'));
    assert.equal(decision.requestHeaders.Cookie,'the-original-authenticated-session');assert.equal(removed.length,0);assert.equal(quits,0);
    f.session.webRequest.completed({...request,url:origin+'/admin/login',statusCode:200});
    window.url=origin+'/admin/login';contents.emit('did-navigate',{},window.url,200,'OK');await new Promise(resolve=>setImmediate(resolve));
    assert.equal(quits,1,'request and navigation completion share one cleanup/quit');assert.equal(flushed,1);assert.equal(removed.length,4);
    assert.deepEqual(cookies.map(cookie=>[cookie.domain,cookie.name]),[['fasakhaninja.com','preference'],['fasakhaninja.com','XSRF-TOKEN'],['foreign.test',recallerName]]);
    assert.deepEqual(otherAccountCookies,otherBefore);assert.equal(f.dashboard.isVisible(),false);
  }
});
test('a durable logout-intent failure blocks the original request and does not quit or mutate cookies',async t=>{
  let quits=0,reads=0;const notices=[],f=fixture(t,{beforeLogout:async()=>{throw Error('تعذر حفظ تسجيل الخروج.');},quit:()=>quits++});await f.dashboard.open();
  f.electron.dialog.showMessageBox=async(_window,details)=>{notices.push(details.message);return {response:1};};
  f.session.cookies.get=async()=>{reads++;return [];};
  let decision;f.session.webRequest.headers({id:529,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',
    webContentsId:f.windows[0].webContents.id,requestHeaders:{Cookie:'original-authentication'}},value=>decision=value);
  await new Promise(resolve=>setImmediate(resolve));assert.equal(decision.cancel,true);assert.equal(reads,0);assert.equal(quits,0);
  assert.equal(f.dashboard.isVisible(),true);assert.deepEqual(notices,['تعذر حفظ تسجيل الخروج.']);
});
test('an auth-cookie flush failure after confirmed logout stays visible and cannot race a fresh sign-in',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});let quits=0,marked=false;const notices=[],f=fixture(t,{beforeLogout:async()=>{marked=true;},quit:()=>quits++});await f.dashboard.open();
  f.electron.dialog.showMessageBox=async(_window,details)=>{notices.push(details.message);return {response:1};};
  f.session.cookies.get=async({url})=>url.startsWith(policy.DEFAULT_ORIGIN+'/')?[nativeCookie('laravel_session')]:[];
  f.session.cookies.flushStore=async()=>{throw Error('تعذر تأكيد ملفات الجلسة.');};
  const request={id:530,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',webContentsId:f.windows[0].webContents.id,requestHeaders:{}};
  f.session.webRequest.headers(request,()=>{});await new Promise(resolve=>setImmediate(resolve));
  f.session.webRequest.completed({...request,url:policy.DEFAULT_ORIGIN+'/admin/login',statusCode:200});await new Promise(resolve=>setImmediate(resolve));
  assert.equal(marked,true);assert.equal(quits,0);assert.equal(f.dashboard.isVisible(),true);assert.equal(notices.length,1);assert.equal(await f.dashboard.canRefresh(),false);
  let signin;f.session.webRequest.headers({...request,id:531,url:policy.DEFAULT_ORIGIN+'/admin/signin',method:'POST'},value=>signin=value);assert.equal(signin.cancel,true);
});
test('closing the window without explicit logout preserves Remember me and never marks or clears the account',async t=>{
  let quits=0,marks=0,reads=0,removed=0;const f=fixture(t,{beforeLogout:async()=>marks++,quit:()=>quits++});await f.dashboard.open();
  f.session.cookies.get=async()=>{reads++;return [nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000+86400})];};
  f.session.cookies.remove=async()=>removed++;
  let prevented=false;f.windows[0].emit('close',{preventDefault(){prevented=true;}});
  assert.equal(prevented,true);assert.equal(quits,1);assert.equal(marks,0);assert.equal(reads,0);assert.equal(removed,0);
});
test('native logout without a persistent protected admin recaller retains ordinary server logout and then quits',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});
  for(const cookie of [null,nativeCookie(recallerName),nativeCookie(recallerName,{session:false,httpOnly:false,expirationDate:Date.now()/1000+86400}),
    nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000-1}),nativeCookie(recallerName,{session:false,domain:'foreign.test',expirationDate:Date.now()/1000+86400})]){
    let quits=0,removed=0;const f=fixture(t,{quit:()=>quits++});await f.dashboard.open();
    f.session.cookies.get=async()=>cookie?[cookie]:[];f.session.cookies.remove=async()=>removed++;
    let decision;const window=f.windows[0];f.session.webRequest.headers({id:502,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',
      webContentsId:window.webContents.id,requestHeaders:{Cookie:'original-session'}},value=>decision=value);
    await new Promise(resolve=>setImmediate(resolve));assert.equal(decision.requestHeaders.Cookie,'original-session');assert.equal(removed,0);assert.equal(quits,0);
    window.url=policy.DEFAULT_ORIGIN+'/admin/login';window.webContents.emit('did-navigate',{},window.url,200,'OK');await new Promise(resolve=>setImmediate(resolve));assert.equal(quits,1);
  }
});
test('ordinary local logout retains the required native token header after cookie inspection',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});const origin='http://127.0.0.1:43123';
  const f=fixture(t,{origin,localToken:'private-runtime-token',serverOrigin:policy.DEFAULT_ORIGIN});await f.dashboard.open();
  let decision;f.session.webRequest.headers({id:510,url:origin+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',
    webContentsId:f.windows[0].webContents.id,requestHeaders:{'X-Fasakhansta-Desktop':'forged','Cookie':'laravel_session=original-session'}},value=>decision=value);
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(decision.requestHeaders['X-Fasakhansta-Desktop'],'private-runtime-token');
  assert.equal(decision.requestHeaders.Cookie,'laravel_session=original-session');
});
test('explicit logout never decrypts or trusts an invalid recaller and preserves the original logout without a credential POST',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});let quits=0;const removed=[],f=fixture(t,{quit:()=>quits++});await f.dashboard.open();const window=f.windows[0];
  f.session.cookies.get=async filter=>filter.url.startsWith(policy.DEFAULT_ORIGIN+'/')?[nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000+86400,value:'invalid-not-authenticated'})]:[];
  f.session.cookies.remove=async(...args)=>removed.push(args);
  let decision;const request={id:503,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',
    webContentsId:window.webContents.id,requestHeaders:{}};
  f.session.webRequest.headers(request,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));assert.ok(decision.requestHeaders);assert.equal(quits,0);
  f.session.webRequest.completed({...request,url:policy.DEFAULT_ORIGIN+'/admin/login',statusCode:200});await new Promise(resolve=>setImmediate(resolve));
  assert.equal(quits,1);assert.deepEqual(removed,[[policy.DEFAULT_ORIGIN+'/',recallerName]]);
  assert.deepEqual(window.loads,[policy.DEFAULT_ORIGIN+'/admin/dashboard'],'no login POST or forced session restoration is invented');
});
test('foreign, unowned and subframe logout requests cannot read or change native session cookies',async t=>{
  let reads=0,quits=0;const f=fixture(t,{quit:()=>quits++});await f.dashboard.open();f.session.cookies.get=async()=>{reads++;return [];};
  const base={id:504,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',webContentsId:f.windows[0].webContents.id,requestHeaders:{}};
  for(const request of [{...base,url:'https://foreign.test/admin/adminLogout'},{...base,webContentsId:999},{...base,resourceType:'subFrame'}]){
    let decision;f.session.webRequest.headers(request,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));assert.ok(decision.requestHeaders);
  }
  assert.equal(reads,0);assert.equal(quits,0);
});
test('a quit arriving during the durable logout intent cancels transmission without inspecting or mutating session cookies',async t=>{
  let mark,reads=0,removed=0,quits=0;const f=fixture(t,{beforeLogout:()=>new Promise(resolve=>mark=resolve),quit:()=>quits++});await f.dashboard.open();
  f.session.cookies.get=async()=>{reads++;return [];};f.session.cookies.remove=async()=>removed++;
  let decision;f.session.webRequest.headers({id:505,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',
    webContentsId:f.windows[0].webContents.id,requestHeaders:{}},value=>decision=value);
  await Promise.resolve();f.close();mark();await new Promise(resolve=>setImmediate(resolve));
  assert.equal(decision.cancel,true);assert.equal(reads,0);assert.equal(removed,0);assert.equal(quits,0);
});
test('the original SPA logout fetch keeps authentication until its response, then flushes logout before hiding owned views',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});let quits=0,flushed=false,inspect;const removed=[];
  const f=fixture(t,{quit:()=>{assert.equal(flushed,true);assert.equal(f.dashboard.isVisible(),false);quits++;}});await f.dashboard.open();const contents=f.windows[0].webContents;
  const child=new f.windows[0].constructor(f.windows[0].options);child.show();contents.emit('did-create-window',child);
  f.session.cookies.get=filter=>filter.url.startsWith(policy.DEFAULT_ORIGIN+'/')?new Promise(resolve=>inspect=resolve):Promise.resolve([]);
  f.session.cookies.remove=async(...args)=>removed.push(args);f.session.cookies.flushStore=async()=>{flushed=true;};
  const request={id:520,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'xhr',
    webContentsId:contents.id,frame:contents.mainFrame,requestHeaders:{Accept:'text/html','X-Dashboard-SPA':'1',Cookie:'original-authentication'}};
  let decision;f.session.webRequest.headers(request,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));
  assert.equal(decision.requestHeaders.Cookie,'original-authentication');assert.equal(quits,0);assert.equal(inspect,undefined);
  assert.equal(f.dashboard.isVisible(),true);assert.equal(child.isVisible(),true);
  f.session.webRequest.completed({...request,url:policy.DEFAULT_ORIGIN+'/admin/login',statusCode:200});await Promise.resolve();
  assert.equal(typeof inspect,'function');assert.equal(quits,0);assert.equal(f.dashboard.isVisible(),true);
  inspect([nativeCookie('fasakhaninja_session'),nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000+86400})]);await new Promise(resolve=>setImmediate(resolve));
  assert.equal(quits,1);assert.deepEqual(removed,[[policy.DEFAULT_ORIGIN+'/','fasakhaninja_session'],[policy.DEFAULT_ORIGIN+'/',recallerName]]);
  assert.equal(child.isVisible(),false);assert.equal(child.isDestroyed(),false,'accepted work may still drain in the hidden windows');
  assert.deepEqual(f.windows[0].loads,[policy.DEFAULT_ORIGIN+'/admin/dashboard']);
});
test('ordinary SPA logout retains the native header and quits only for its successful original request completion',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});let quits=0;const origin='http://127.0.0.1:43123';
  const f=fixture(t,{origin,localToken:'private-runtime-token',serverOrigin:policy.DEFAULT_ORIGIN,quit:()=>quits++});await f.dashboard.open();
  const contents=f.windows[0].webContents;
  const request={id:521,url:origin+'/admin/adminLogout',method:'GET',resourceType:'xhr',webContentsId:contents.id,frame:contents.mainFrame,
    requestHeaders:{'X-Dashboard-SPA':'1',Cookie:'laravel_session=original-session'}};
  let decision;f.session.webRequest.headers(request,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));
  assert.equal(decision.requestHeaders['X-Fasakhansta-Desktop'],'private-runtime-token');assert.equal(quits,0);
  assert.equal(f.dashboard.isVisible(),true,'ordinary logout stays visible until its original response succeeds');
  contents.emit('did-navigate',{},origin+'/admin/dashboard',200,'OK');assert.equal(quits,0);
  for(const details of [{...request,id:522,statusCode:200},{...request,webContentsId:999,statusCode:200}])f.session.webRequest.completed(details);
  assert.equal(quits,0);
  f.session.webRequest.completed({...request,url:origin+'/admin/login',statusCode:200});await new Promise(resolve=>setImmediate(resolve));assert.equal(quits,1);
  assert.equal(f.dashboard.isVisible(),false,'the successful response hides the SPA while native shutdown drains');
  t.mock.timers.tick(15000);assert.equal(quits,1);
});
test('SPA logout requires the native owning top frame, original SPA marker, exact GET route and current origin',async t=>{
  let reads=0,quits=0;const f=fixture(t,{quit:()=>quits++,remoteState:{begin:async()=>{}}});await f.dashboard.open();const contents=f.windows[0].webContents;
  f.session.cookies.get=async()=>{reads++;return [];};
  const base={id:523,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'xhr',webContentsId:contents.id,
    frame:contents.mainFrame,requestHeaders:{'X-Dashboard-SPA':'1'}};
  for(const request of [{...base,frame:null},{...base,frame:{url:contents.mainFrame.url}},
    {...base,requestHeaders:{}},{...base,requestHeaders:{'X-Dashboard-SPA':'true'}},{...base,resourceType:'subFrame'},
    {...base,resourceType:'image'},{...base,webContentsId:999},{...base,url:'https://foreign.test/admin/adminLogout'},
    {...base,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout/other'},{...base,method:'POST'}]){
    let decision;f.session.webRequest.headers(request,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));assert.ok(decision.requestHeaders);
  }
  f.windows[0].url='https://foreign.test/admin/dashboard';let decision;
  f.session.webRequest.headers(base,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));assert.ok(decision.requestHeaders);
  assert.equal(reads,0);assert.equal(quits,0);
});
test('failed or timed-out original SPA logout offers native close, never treats unrelated responses as logout and blocks a late sign-in race',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});
  for(const failure of ['timeout','network','server','foreign']){
    let quits=0;const dialogs=[],f=fixture(t,{quit:()=>quits++});await f.dashboard.open();const contents=f.windows[0].webContents;
    f.electron.dialog.showMessageBox=async(_window,details)=>{dialogs.push(details);return {response:1};};
    const request={id:524,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'xhr',webContentsId:contents.id,
      frame:contents.mainFrame,requestHeaders:{'x-dashboard-spa':'1'}};
    let decision;f.session.webRequest.headers(request,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));assert.ok(decision.requestHeaders);
    if(failure==='timeout')t.mock.timers.tick(15000);
    else if(failure==='network')f.session.webRequest.error({...request,error:'net::ERR_CONNECTION_RESET'});
    else f.session.webRequest.completed({...request,url:failure==='foreign'?'https://foreign.test/admin/login':request.url,statusCode:failure==='server'?500:200});
    await new Promise(resolve=>setImmediate(resolve));assert.equal(dialogs.length,1);assert.match(dialogs[0].message,/تأكيد تسجيل الخروج/);
    assert.equal(f.dashboard.isVisible(),true,'a failed or uncertain logout keeps its native error visible');
    assert.equal(quits,0);assert.equal(await f.dashboard.canRefresh(),false);
    let signin;f.session.webRequest.headers({...request,id:525,url:policy.DEFAULT_ORIGIN+'/admin/signin',resourceType:'mainFrame',method:'POST'},value=>signin=value);
    assert.equal(signin.cancel,true);
    f.session.webRequest.completed({...request,url:policy.DEFAULT_ORIGIN+'/admin/login',statusCode:200});assert.equal(quits,0,'late responses cannot finish a failed intent');
  }
});
test('a fresh original sign-in discards only the old recaller, preserves CSRF/session headers and retains the ordinary native reservation',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});
  for(const rememberNewAccount of [false,true]){
    let quits=0,flushed=0;const removed=[],stages=[];
    let cookies=[nativeCookie('fasakhaninja_session',{value:'current-csrf-session'}),
      nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000+86400,value:'old-account-recaller'}),
      nativeCookie('XSRF-TOKEN',{httpOnly:false,value:'current-xsrf'})];
    const f=fixture(t,{quit:()=>quits++,remoteAttempts:{begin:async()=>{stages.push('reservation');assert.equal(cookies.some(cookie=>cookie.name===recallerName),false);return {'X-Fasakhansta-Remote-Attempt':'original-reservation'};}}});
    await f.dashboard.open();f.session.cookies.get=async()=>cookies;
    f.session.cookies.remove=async(url,name)=>{removed.push([url,name]);cookies=cookies.filter(cookie=>cookie.name!==name);};
    f.session.cookies.flushStore=async()=>{flushed++;stages.push('flush');};
    const request={id:506,url:policy.DEFAULT_ORIGIN+'/admin/signin',method:'POST',resourceType:'mainFrame',webContentsId:f.windows[0].webContents.id,
      requestHeaders:{Cookie:'fasakhaninja_session=current-csrf-session; '+recallerName+'=old-account-recaller; XSRF-TOKEN=current-xsrf','X-CSRF-TOKEN':'original-csrf'}};
    Object.defineProperty(request,'uploadData',{get(){throw Error('Passwords/request bodies must never be read.');}});
    let decision;f.session.webRequest.headers(request,value=>decision=value);await new Promise(resolve=>setImmediate(resolve));
    assert.deepEqual(removed,[[policy.DEFAULT_ORIGIN+'/',recallerName]]);assert.deepEqual(stages,['flush','reservation']);
    assert.equal(decision.requestHeaders.Cookie,'fasakhaninja_session=current-csrf-session; XSRF-TOKEN=current-xsrf');
    assert.equal(decision.requestHeaders['X-CSRF-TOKEN'],'original-csrf');assert.equal(decision.requestHeaders['X-Fasakhansta-Remote-Attempt'],'original-reservation');
    if(rememberNewAccount)cookies.push(nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000+86400,value:'new-account-recaller'}));
    let logout;f.session.webRequest.headers({...request,id:507,method:'GET',url:policy.DEFAULT_ORIGIN+'/admin/adminLogout'},value=>logout=value);
    await new Promise(resolve=>setImmediate(resolve));
    assert.ok(logout.requestHeaders);assert.equal(quits,0);
    f.session.webRequest.completed({...request,id:507,method:'GET',url:policy.DEFAULT_ORIGIN+'/admin/login',statusCode:200});await new Promise(resolve=>setImmediate(resolve));
    assert.equal(quits,1);assert.equal(cookies.some(cookie=>cookie.name===recallerName),false);assert.equal(flushed,2);
  }
});
test('a timed-out fresh-login cookie cleanup never transmits credentials or removes cookies after its deadline',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});let inspected,removed=0;const f=fixture(t);await f.dashboard.open();
  f.session.cookies.get=()=>new Promise(resolve=>inspected=resolve);f.session.cookies.remove=async()=>removed++;
  const request={id:508,url:policy.DEFAULT_ORIGIN+'/admin/signin',method:'POST',resourceType:'mainFrame',webContentsId:f.windows[0].webContents.id,requestHeaders:{Cookie:'original-session'}};
  let decision;f.session.webRequest.headers(request,value=>decision=value);await Promise.resolve();t.mock.timers.tick(5000);
  await new Promise(resolve=>setImmediate(resolve));assert.equal(decision.cancel,true);
  inspected([nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000+86400})]);await new Promise(resolve=>setImmediate(resolve));assert.equal(removed,0);
  decision=null;f.session.webRequest.headers({...request,id:509},value=>decision=value);assert.equal(decision.cancel,true,'a timed-out deletion cannot race a fresh login until a new native process');
  assert.equal(await f.dashboard.canRefresh(),false,'a modal renewal must not race the same pending removal');
});
test('a timed-out logout session cleanup keeps fresh login and mode changes blocked until the process closes',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});let releaseRemoval,reads=0,quits=0;const f=fixture(t,{quit:()=>quits++});await f.dashboard.open();
  f.session.cookies.get=async()=>{reads++;return [nativeCookie('fasakhaninja_session'),nativeCookie(recallerName,{session:false,expirationDate:Date.now()/1000+86400})];};
  f.session.cookies.remove=()=>new Promise(resolve=>releaseRemoval=resolve);
  const base={id:511,url:policy.DEFAULT_ORIGIN+'/admin/adminLogout',method:'GET',resourceType:'mainFrame',webContentsId:f.windows[0].webContents.id,requestHeaders:{}};
  let logout;f.session.webRequest.headers(base,value=>logout=value);await new Promise(resolve=>setImmediate(resolve));assert.ok(logout.requestHeaders);
  f.session.webRequest.completed({...base,url:policy.DEFAULT_ORIGIN+'/admin/login',statusCode:200});await new Promise(resolve=>setImmediate(resolve));
  assert.equal(typeof releaseRemoval,'function');t.mock.timers.tick(5000);await new Promise(resolve=>setImmediate(resolve));
  assert.equal(await f.dashboard.canRefresh(),false);
  let signin;f.session.webRequest.headers({...base,id:512,url:policy.DEFAULT_ORIGIN+'/admin/signin',method:'POST'},value=>signin=value);
  assert.equal(signin.cancel,true);await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/login');
  assert.deepEqual(f.windows[0].loads,[policy.DEFAULT_ORIGIN+'/admin/dashboard']);assert.equal(reads,2);
  releaseRemoval();await new Promise(resolve=>setImmediate(resolve));assert.equal(quits,0);
});
test('F12 toggles the focused native app window without changing dashboard navigation',async t=>{
  const f=fixture(t);await f.dashboard.open();const w=f.windows[0];
  const view=f.electron.menu.find(item=>item.label==='عرض');
  const fullscreen=view.submenu.find(item=>item.role==='togglefullscreen');
  assert.equal(fullscreen.accelerator,'F12');
  assert.equal(fullscreen.click,undefined,'Electron handles this local menu role for the focused app window');
  await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/branch-expenses');
  assert.equal(f.windows.length,1,'navigation retains the same window and its chosen presentation');
  assert.equal(w.url,policy.DEFAULT_ORIGIN+'/admin/branch-expenses');
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
test('a missing Windows print callback releases the accepted receipt and cannot report late success',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});
  const f=fixture(t);await f.dashboard.open();const sender=f.windows[0].webContents;
  let callback,calls=0;
  const Original=f.electron.BrowserWindow.prototype;
  // The print window is created after fetch/preparation. Wait for its native
  // callback stage while leaving the actual dashboard handler unchanged.
  f.session.fetch=async()=>{
    const originalLoad=Original.loadURL;
    Original.loadURL=async function(url){
      await originalLoad.call(this,url);
      if(url.startsWith('data:'))this.webContents.print=(_options,complete)=>{calls++;callback=complete;};
    };
    return new Response('<html data-dashboard-receipt="takeaway"><head></head><body>saved receipt</body></html>',{headers:{'content-type':'text/html'}});
  };
  const printing=f.handlers.get('dashboard:print-receipt')({sender,senderFrame:{url:sender.getURL()}},policy.DEFAULT_ORIGIN+'/admin/takeaway/1/print');
  await new Promise(resolve=>setImmediate(resolve));assert.equal(calls,1);
  t.mock.timers.tick(45000);const result=await printing;
  assert.equal(result.ok,false);assert.match(result.error,/لن تُعاد تلقائيًا/);assert.equal(f.windows[1].destroyed,true);
  callback(true);await Promise.resolve();assert.equal(calls,1);assert.equal(result.ok,false);
  assert.equal(await f.dashboard.canRefresh(),true,'a stalled callback no longer holds the dashboard idle gate');
});
test('a stalled receipt image or font cannot hold the accepted print forever',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});
  const f=fixture(t);await f.dashboard.open();const sender=f.windows[0].webContents;
  const originalLoad=f.electron.BrowserWindow.prototype.loadURL;
  f.electron.BrowserWindow.prototype.loadURL=async function(url){
    await originalLoad.call(this,url);
    if(url.startsWith('data:'))this.webContents.executeJavaScript=()=>new Promise(()=>{});
  };
  const printing=f.handlers.get('dashboard:print-receipt')({sender,senderFrame:{url:sender.getURL()}},policy.DEFAULT_ORIGIN+'/admin/takeaway/1/print');
  await new Promise(resolve=>setImmediate(resolve));t.mock.timers.tick(25000);
  const result=await printing;assert.equal(result.ok,false);assert.match(result.error,/تعذر تحميل مستند/);
  assert.equal(f.windows[1].destroyed,true);assert.equal(f.windows[1].printOptions,undefined);
  assert.equal(await f.dashboard.canRefresh(),true);
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
  assert.equal(name,'FasakhanstaDesktop');assert.deepEqual(Object.keys(api),['printReceipt','printEffect','printPending','status','prepare','synchronize','reviewSync','openLive','switchAccount','onState']);
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

test('only the owned local dashboard may open the two live social pages without switching its origin',async t=>{
  const calls=[],local='http://127.0.0.1:45123',f=fixture(t,{origin:local,localToken:'private',serverOrigin:policy.DEFAULT_ORIGIN,liveSocial:async kind=>{calls.push(kind);return {live:true,kind};}});
  await f.dashboard.open();const sender=f.windows[0].webContents,event={sender,senderFrame:{url:sender.getURL()}},handler=f.handlers.get('dashboard:live-social');
  assert.equal((await handler(event,'messages')).ok,true);assert.equal((await handler(event,'comments')).ok,true);
  assert.equal((await handler(event,'orders')).ok,false);assert.equal((await handler({...event,sender:{id:999}},'messages')).ok,false);
  assert.equal((await handler({...event,senderFrame:{url:policy.DEFAULT_ORIGIN+'/admin/dashboard'}},'messages')).ok,false);
  assert.equal(f.dashboard.current().local,true);assert.equal(f.windows[0].url,local+'/admin/dashboard');assert.deepEqual(calls,['messages','comments']);
  await f.dashboard.switchTo({origin:policy.DEFAULT_ORIGIN,localToken:''});assert.equal((await handler({sender,senderFrame:{url:sender.getURL()}},'messages')).ok,false);
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
test('the fallback printer menu waits for the lazily opened legacy page and cancels its settings action during quit',async t=>{
  for(const quitting of [false,true]){
    let loaded,executed=0;
    const localWindow={webContents:{executeJavaScript:async script=>{
      assert.equal(script,'document.getElementById("settings").click()');executed++;
    }}};
    const f=fixture(t,{offline:()=>new Promise(resolve=>loaded=resolve),offlineWindow:()=>localWindow});
    await f.dashboard.open();
    const opening=f.electron.menu[0].submenu.find(item=>item.label==='اختيار الطابعة').click();
    await Promise.resolve();assert.equal(executed,0,'settings must not run against the startup screen');
    if(quitting)f.close();loaded();await opening;
    assert.equal(executed,quitting?0:1);
  }
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
  const probe=window.webContents.executeJavaScript;window.webContents.executeJavaScript=async()=>false;
  assert.equal((await f.dashboard.refresh(async()=>updates++)).deferred,true);
  assert.equal(window.url,origin+'/admin/takeaway');assert.equal(updates,0);assert.equal(window.webContents.stopped,undefined);
  await f.dashboard.open();window.webContents.executeJavaScript=probe;
  const child=new window.constructor(window.options);window.webContents.emit('did-create-window',child);
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
  let fields=[],dialogs=[];
  const document={readyState:'complete',activeElement:{matches:()=>false},querySelector:()=>null,
    querySelectorAll:selector=>selector.startsWith('.modal')?dialogs:fields};
  f.windows[0].webContents.executeJavaScript=async source=>vm.runInNewContext(source,{document});
  assert.equal(await f.dashboard.canRefresh(),true);
  document.activeElement.matches=()=>true;assert.equal(await f.dashboard.canRefresh(),false);document.activeElement.matches=()=>false;
  dialogs=[{closest:()=>null,getClientRects:()=>[{}]}];assert.equal(await f.dashboard.canRefresh(),false);
  dialogs=[{closest:()=>({}),getClientRects:()=>[{}]}];assert.equal(await f.dashboard.canRefresh(),true);
  dialogs=[{closest:()=>null,getClientRects:()=>[]}];assert.equal(await f.dashboard.canRefresh(),true);dialogs=[];
  fields=[{tagName:'INPUT',type:'text',value:'changed',defaultValue:''}];assert.equal(await f.dashboard.canRefresh(),false);
  fields=[{tagName:'SELECT',options:[{selected:true,defaultSelected:false}]}];assert.equal(await f.dashboard.canRefresh(),false);
  fields=[];document.readyState='loading';assert.equal(await f.dashboard.canRefresh(),false);
});
test('business routes require their scoped idle proof while status and home pages accept settled content',async t=>{
  const origin='http://127.0.0.1:43123',f=fixture(t,{origin,localToken:'private'});
  await f.dashboard.open(origin+'/admin/desktop-dashboard');
  assert.equal(await f.dashboard.canRefresh(),true);
  let applied=0;await f.dashboard.withUpdateIdle(async()=>{applied++;});assert.equal(applied,1);
  for(const path of ['/admin/desktop-dashboard/download','/admin/orders','/admin/branch-stock/adjust']){
    await f.dashboard.open(origin+path);assert.equal(await f.dashboard.canRefresh(),false);
    assert.equal((await f.dashboard.withUpdateIdle(async()=>{applied++;})).deferred,true);
  }
  assert.equal(applied,1);
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
  const partition='persist:fasakhansta-dashboard-01234567-89ab-4cde-8fab-0123456789ab';
  const origin='http://127.0.0.1:43123',f=fixture(t,{origin,partition,localToken:'private',printControl:control,
    printMetadata:{read:async key=>records.get(key)||null,write:async(key,value)=>records.set(key,value)}});
  await f.dashboard.open();f.session.fetch=()=>{throw Error('Legacy consuming GET must not be fetched.');};
  const sender=f.windows[0].webContents,event={sender,senderFrame:{url:sender.getURL()}},handler=f.handlers.get('dashboard:print-receipt');
  assert.equal((await handler(event,origin+'/admin/takeaway/receipts/1/print?initial_print=original-grant')).ok,true);
  assert.equal(calls[0].action,'print-resolve');assert.equal(calls[0].path,'/admin/takeaway/receipts/1/print');assert.equal(calls[0].query.initial_print,'original-grant');
  assert.equal(f.windows[1].printOptions.silent,true);assert.ok(f.windows[1].destroyed);
  assert.equal(f.windows[1].options.webPreferences.partition,partition,'durable local printing stays in the selected account partition');
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
  for(const [owner,scope] of [[main,local],[auth,policy.DEFAULT_ORIGIN]]){
    let decision;f.session.webRequest.headers({id:526,url:scope+'/admin/adminLogout',method:'GET',resourceType:'xhr',
      webContentsId:owner.webContents.id,frame:owner.webContents.mainFrame,requestHeaders:{'X-Dashboard-SPA':'1'}},value=>decision=value);
    assert.equal(decision.cancel,true,'neither the obscured dashboard nor the login modal may start a native SPA logout during renewal');
  }
  assert.equal(removals.length,1);
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
test('shutdown login cancellation settles when Chromium never returns cookie cleanup',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});
  const Shutdown=require('../src/shutdown.cjs'),f=fixture(t),app=new EventEmitter();let drained=false;
  app.quit=()=>app.emit('before-quit',{preventDefault(){}});
  const shutdown=new Shutdown(app,()=>{f.close();f.dashboard.cancelAuthentication();},()=>{},async()=>{drained=true;});
  await f.dashboard.open();
  const renewal=shutdown.run(()=>f.dashboard.reauthenticate(policy.DEFAULT_ORIGIN,async()=>{}));
  const rejected=assert.rejects(renewal,/إلغاء/);
  await new Promise(resolve=>setImmediate(resolve));
  let lateCookies;const removed=[];
  f.session.cookies.get=()=>new Promise(resolve=>lateCookies=resolve);
  f.session.cookies.remove=async(...args)=>removed.push(args);
  app.quit();await Promise.resolve();
  assert.equal(drained,false);assert.equal(shutdown.pending.size,1);
  t.mock.timers.tick(5000);await rejected;await new Promise(resolve=>setImmediate(resolve));
  assert.equal(shutdown.pending.size,0);assert.equal(drained,true);assert.equal(shutdown.ready,true);
  assert.equal(f.windows[1].destroyed,true);
  lateCookies([{name:'newer-session',path:'/'}]);await new Promise(resolve=>setImmediate(resolve));
  assert.equal(removed.length,0,'a late cleanup read must not remove a later session after its deadline');
});
