'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const {EventEmitter}=require('node:events');
const {DashboardLiveSocial}=require('../src/dashboard-live-social.cjs');
const origin='https://fasakhaninja.com',device='12345678-1234-4234-8234-123456789abc';
const settle=async()=>{for(let i=0;i<10;i++)await Promise.resolve();await new Promise(setImmediate);};
function fixture(t,{actor=10,protocol=1,prepared=true,cookies,probeError,loadFailure=false,holdProbe=false,confirmMicrophone=async()=>false}={}){
  let sessionActor=actor,nextId=0,quitting=false,current={serverOrigin:origin,deviceId:device,actorId:10,token:'a'.repeat(64)},runtimePrepared=prepared;
  let releaseProbe;const probeWait=holdProbe?new Promise(resolve=>releaseProbe=resolve):null;
  const windows=[],sessions=[],notices=[],cookieReads=[],probeCalls=[],local={origin:'http://127.0.0.1:45000',token:'local-private',mode:'local'},writes=[];
  const source={cookies:{get:async filter=>{cookieReads.push(filter);return cookies||[
    {name:'session',value:'owned-login',domain:'fasakhaninja.com',hostOnly:true,path:'/',httpOnly:true,secure:true,sameSite:'lax'},
    {name:'foreign',value:'foreign-login',domain:'evil.test',hostOnly:true,path:'/',httpOnly:true,secure:true}
  ];}}};
  const runtime={isPrepared:async()=>runtimePrepared,connection:async()=>structuredClone(current)};
  function createSession(partition){
    const hooks={},copied=[],session={partition,hooks,copied,clearCount:0,cookies:{set:async value=>copied.push(value),get:async()=>[],remove:async()=>{}},
      clearStorageData:async()=>{session.clearCount++;},clearCache:async()=>{},
      fetch:async(url,options)=>{probeCalls.push({url,options});if(probeWait)await probeWait;if(probeError)throw probeError;return new Response(JSON.stringify({actor_id:sessionActor,device_id:device,connected_request_protocol:protocol}),{headers:{'Content-Type':'application/json'}});},
      webRequest:{onBeforeSendHeaders:(_filter,fn)=>{hooks.headers=fn;},onBeforeRequest:(_filter,fn)=>{hooks.before=fn;},onCompleted:(_filter,fn)=>{hooks.completed=fn;},onErrorOccurred:(_filter,fn)=>{hooks.error=fn;},onHeadersReceived:(_filter,fn)=>{hooks.response=fn;}},
      setPermissionRequestHandler:fn=>{hooks.permission=fn;},setPermissionCheckHandler:fn=>{hooks.check=fn;}};
    sessions.push(session);return session;
  }
  function createWindow(options){
    const window=new EventEmitter();window.options=options;window.destroyed=false;window.visible=false;window.loads=[];window.focuses=0;
    const contents=new EventEmitter();contents.id=++nextId;contents.session=options.webPreferences.session||sessions.at(-1);
    contents.setWindowOpenHandler=fn=>{contents.popup=fn;};contents.executeJavaScript=async code=>{window.injected=code;return true;};
    contents.insertCSS=async css=>{window.css=css;};contents.getURL=()=>window.url||'';contents.stop=()=>{window.stopped=true;};
    window.webContents=contents;window.loadURL=async url=>{window.url=url;window.loads.push(url);if(loadFailure&&url.startsWith('https:'))throw Error('net::ERR_INTERNET_DISCONNECTED');contents.emit('did-finish-load');};window.show=()=>{window.visible=true;};window.focus=()=>{window.focuses++;};
    window.maximize=()=>{};window.isMinimized=()=>false;window.restore=()=>{};window.isDestroyed=()=>window.destroyed;
    window.destroy=()=>{if(window.destroyed)return;window.destroyed=true;window.emit('closed');};window.close=()=>window.destroy();
    windows.push(window);return window;
  }
  const social=new DashboardLiveSocial({runtime,sourceSession:()=>source,createSession,createWindow,quitting:()=>quitting,notify:message=>notices.push(message),confirmMicrophone});
  t.after(async()=>{await social.close();});
  async function request(url,method='GET',headers={},details={}){
    const session=sessions.at(-1),window=windows.at(-1),input={id:Math.random(),url,method,resourceType:'xhr',webContentsId:window.webContents.id,requestHeaders:headers,...details};
    const result=await new Promise((resolve,reject)=>{try{const pending=session.hooks.headers(input,resolve);pending?.catch(reject);}catch(error){reject(error);}});
    if(!result.cancel&&method==='POST')writes.push(url);return result;
  }
  return {social,runtime,windows,sessions,source,notices,cookieReads,probeCalls,local,writes,request,
    releaseProbe:()=>releaseProbe?.(),change:value=>{current={...current,...value};},setProbeError:value=>probeError=value,setActor:value=>sessionActor=value,setPrepared:value=>runtimePrepared=value,setQuitting:()=>{quitting=true;}};
}

test('live messages open in a separate ephemeral unprivileged surface without changing local work or waiting for the ERP cycle',async t=>{
  const f=fixture(t),before=structuredClone(f.local);await f.social.open('messages');
  assert.equal(f.windows.length,1);const w=f.windows[0],session=f.sessions[0];
  assert.equal(w.url,origin+'/admin/meta');assert.equal(w.options.webPreferences.nodeIntegration,false);assert.equal(w.options.webPreferences.contextIsolation,true);assert.equal(w.options.webPreferences.sandbox,true);
  assert.equal(w.options.webPreferences.preload,undefined);assert.ok(!session.partition.startsWith('persist:'));
  assert.deepEqual(f.local,before);assert.equal(f.probeCalls[0].url,origin+'/admin/desktop-dashboard/session');assert.equal(f.probeCalls[0].options.redirect,'error');
  const sent=await f.request(origin+'/admin/meta/conversations/25/replies','POST',{'X-Fasakhansta-Connected-Token':'forged'});
  assert.equal(sent.cancel,undefined);assert.equal(sent.requestHeaders['X-Fasakhansta-Connected-Device'],device);assert.equal(sent.requestHeaders['X-Fasakhansta-Connected-Token'],'a'.repeat(64));
  assert.deepEqual(f.writes,[origin+'/admin/meta/conversations/25/replies']);assert.deepEqual(f.local,before);
});

test('only pinned-origin login cookies seed the isolated social session',async t=>{
  const f=fixture(t);await f.social.open('messages');
  assert.deepEqual(f.cookieReads,[{url:origin}]);const copied=f.sessions[0].copied;
  assert.equal(copied.some(c=>c.name==='foreign'),false);assert.equal(copied.length,1);assert.equal(copied[0].url,origin+'/');
  assert.equal(copied[0].value,'owned-login');assert.ok(copied.every(c=>!c.domain||c.domain==='fasakhaninja.com'));
});

test('ERP routes, account changes, Meta order dispatch and route aliases cannot write through the live social surface',async t=>{
  const f=fixture(t);await f.social.open('messages');
  for(const [path,method] of [
    ['/admin/orders','POST'],['/admin/employees/save','POST'],['/admin/change-status/20','GET'],['/admin/meta/orders/7/dispatch','POST'],
    ['/admin/meta/connection/start','POST'],['/admin/meta/ai-control','POST'],
    ['/admin/%6deta/orders/7/dispatch','POST'],['/admin/meta/conversations/%32%35/replies','POST'],
    ['/dashboard/%2f..%2fadmin%2fchange-status%2f20','GET'],['/admin/meta/conversations/25/replies','HEAD'],['/admin/meta/conversations/25/replies','DELETE']
  ])assert.equal((await f.request(origin+path,method)).cancel,true,path+' '+method);
  assert.deepEqual(f.writes,[]);
});

test('actual Meta/WhatsApp reads, sends and comments retain their original immediate methods',async t=>{
  const f=fixture(t);await f.social.open('comments');assert.equal(f.windows[0].url,origin+'/admin/meta/comments');
  for(const [path,method] of [
    ['/admin/meta','GET'],['/admin/meta/conversations','GET'],['/admin/meta/conversations/25/messages','GET'],['/admin/meta/conversations/25/reply-state','GET'],
    ['/admin/meta/conversations/25/profile','POST'],['/admin/meta/conversations/25/read','POST'],['/admin/meta/conversations/25/replies','POST'],['/admin/meta/conversations/25/voice','POST'],['/admin/meta/conversations/25/attachments','POST'],
    ['/admin/meta/comments/posts','GET'],['/admin/meta/comments/replies','GET'],['/admin/meta/comments/reply','POST'],
    ['/admin/whatsapp/conversations/25/messages','GET'],['/admin/whatsapp/conversations/25/replies','POST'],['/admin/whatsapp/conversations/25/attachment','POST'],
    ['/admin/whatsapp/conversations/25/read','POST']
  ])assert.equal((await f.request(origin+path,method)).cancel,undefined,path+' '+method);
});

test('foreign origins, subframes, redirects and static requests never receive native proofs or expose the local runtime',async t=>{
  const f=fixture(t);await f.social.open('messages');const token='secret-forged';
  for(const url of ['https://evil.test/collect',origin+'.evil.test/admin/meta','http://127.0.0.1:45000/admin/meta','file:///etc/passwd','https://user:pass@fasakhaninja.com/admin/meta'])
    assert.equal((await f.request(url,'GET',{'X-Fasakhansta-Connected-Token':token})).cancel,true,url);
  assert.equal((await f.request(origin+'/admin/meta','GET',{}, {webContentsId:99999})).cancel,true);
  const staticResult=await f.request(origin+'/dashboard/plugins/jquery/jquery.min.js','GET',{'X-Fasakhansta-Connected-Token':token,'X-Fasakhansta-Desktop':'local-private',Authorization:'Bearer secret'}, {resourceType:'script'});
  assert.equal(staticResult.cancel,undefined);assert.equal(Object.keys(staticResult.requestHeaders).some(k=>/^x-fasakhansta-|^authorization$/i.test(k)),false);
  assert.equal((await f.request(origin+'/dashboard/plugins/jquery/jquery.min.js','POST',{}, {resourceType:'script'})).cancel,true);
  const contents=f.windows[0].webContents;assert.equal(contents.popup({url:origin+'/admin/orders'}).action,'deny');
  let prevented=false;contents.emit('will-redirect',{preventDefault(){prevented=true;}},'https://evil.test/collect');assert.equal(prevented,true);
});

test('an expired, mismatched or unsupported actual social session cannot load a privileged page or send a message',async t=>{
  for(const options of [{actor:11},{protocol:0},{protocol:'1'},{probeError:Error('login expired')}]){
    const f=fixture(t,options);await assert.rejects(f.social.open('messages'));
    assert.equal(f.windows.some(w=>w.loads.length),false);assert.ok(f.sessions.every(s=>s.clearCount>=1));
  }
});

test('changed enrollment and shutdown refuse subsequent requests without switching or deleting the main local data',async t=>{
  const f=fixture(t);await f.social.open('messages');const before=structuredClone(f.local);f.change({actorId:11});
  assert.equal((await f.request(origin+'/admin/meta/conversations/25/replies','POST')).cancel,true);assert.deepEqual(f.local,before);assert.deepEqual(f.writes,[]);
});

test('lost live responses are never automatically retried or queued in the ERP scheduler',async t=>{
  const f=fixture(t);await f.social.open('messages');const url=origin+'/admin/meta/conversations/25/replies';
  await f.request(url,'POST');const session=f.sessions[0];session.hooks.error?.({url,method:'POST',error:'net::ERR_CONNECTION_RESET',webContentsId:f.windows[0].webContents.id});
  await settle();assert.deepEqual(f.writes,[url]);assert.equal(f.windows[0].loads.length,1);assert.deepEqual(f.local,{origin:'http://127.0.0.1:45000',token:'local-private',mode:'local'});
});

test('unknown social pages and unprepared or closing devices fail before cookie copying or window creation',async t=>{
  for(const [options,page] of [[{},'orders'],[{prepared:false},'messages']]){
    const f=fixture(t,options);await assert.rejects(f.social.open(page));assert.equal(f.windows.length,0);assert.equal(f.cookieReads.length,0);
  }
  const f=fixture(t);f.setQuitting();await assert.rejects(f.social.open('messages'));assert.equal(f.windows.length,0);assert.equal(f.cookieReads.length,0);
});

test('a first social navigation failure shows a restricted Internet page and requires a user reconnect',async t=>{
  const f=fixture(t,{loadFailure:true});await f.social.open('messages');const w=f.windows[0];
  assert.equal(w.loads.length,2);assert.ok(w.loads[1].startsWith('data:text/html;'));
  const page=decodeURIComponent(w.loads[1].slice(w.loads[1].indexOf(',')+1));
  assert.match(page,/Content-Security-Policy/);assert.match(page,/إعادة الاتصال/);assert.match(page,/عمل الجهاز المحلي مستمر/);
  assert.doesNotMatch(page,/owned-login|local-private|a{64}/);assert.deepEqual(f.writes,[]);
});

test('loaded social pages retain drafts on an Internet error and do not reload or replay their pending send',async t=>{
  const f=fixture(t);await f.social.open('messages');const w=f.windows[0],url=origin+'/admin/meta/conversations/25/replies';
  await f.request(url,'POST');f.sessions[0].hooks.error({url,method:'POST',error:'net::ERR_CONNECTION_RESET',webContentsId:w.webContents.id});await settle();
  assert.equal(w.loads.length,1);assert.match(w.injected,/go-live-internet-note/);assert.match(w.injected,/لن تُرسل رسالة تلقائيًا/);assert.deepEqual(f.writes,[url]);
});

test('social permissions and popups remain denied, closing aborts its probe and clears only its ephemeral storage',async t=>{
  const f=fixture(t);await f.social.open('messages');const session=f.sessions[0],w=f.windows[0];let allowed;
  await session.hooks.permission(w.webContents,'media',value=>allowed=value);assert.equal(allowed,false);assert.equal(w.webContents.popup({url:origin+'/admin/meta/comments'}).action,'deny');
  const signal=f.probeCalls[0].options.signal;assert.equal(signal.aborted,false);await f.social.close();await settle();
  assert.equal(signal.aborted,true);assert.ok(session.clearCount>=1);assert.equal(w.destroyed,true);assert.deepEqual(f.local,{origin:'http://127.0.0.1:45000',token:'local-private',mode:'local'});
});

test('ordinary source refresh does not change the enrolled social identity but a changed token immediately cancels sends',async t=>{
  const f=fixture(t);await f.social.open('messages');f.change({sourceRevision:'b'.repeat(40),snapshotId:'new-generation'});
  assert.equal((await f.request(origin+'/admin/meta/conversations/25/replies','POST')).cancel,undefined);
  f.change({token:'c'.repeat(64)});assert.equal((await f.request(origin+'/admin/meta/conversations/25/replies','POST')).cancel,true);assert.equal(f.writes.length,1);
});

test('closing while its actor probe settles cannot resurrect a live social window or reauthorize the disposed session',async t=>{
  const f=fixture(t,{holdProbe:true});const opened=f.social.open('messages');await settle();assert.equal(f.probeCalls.length,1);
  f.social.close();f.releaseProbe();await assert.rejects(opened);assert.ok(f.windows.every(w=>w.destroyed&&!w.loads.some(url=>url.startsWith('https:'))));assert.equal(f.probeCalls[0].options.signal.aborted,true);
});

test('allowed external static assets have no cookies, bearer token, native proofs or local headers even after a redirect',async t=>{
  const f=fixture(t);await f.social.open('messages');const headers={Cookie:'server-session=private',Origin:origin,Referer:origin+'/admin/meta',Authorization:'Bearer secret',
    'X-Fasakhansta-Connected-Device':device,'X-Fasakhansta-Connected-Token':'a'.repeat(64),'X-Fasakhansta-Desktop':'local-private'};
  const asset='https://cdn.jsdelivr.net/npm/axios@1.6.7/dist/axios.min.js';
  const result=await f.request(asset,'GET',headers,{resourceType:'script'});assert.equal(result.cancel,undefined);assert.deepEqual(result.requestHeaders,{});
  assert.equal((await f.request(asset,'POST',headers,{resourceType:'script'})).cancel,true);assert.equal((await f.request(asset,'GET',headers,{resourceType:'xhr'})).cancel,true);
});

test('actor expiry or drift after opening blocks a new send using the social session itself',async t=>{
  const f=fixture(t);await f.social.open('messages');f.setActor(11);
  assert.equal((await f.request(origin+'/admin/meta/conversations/25/replies','POST')).cancel,true);assert.deepEqual(f.writes,[]);
  assert.ok(f.probeCalls.length>=2);assert.ok(f.probeCalls.every(call=>call.url===origin+'/admin/desktop-dashboard/session'));
  assert.deepEqual(f.local,{origin:'http://127.0.0.1:45000',token:'local-private',mode:'local'});
});

test('a renderer cannot borrow the native session probe or keep its bearer/forged native headers',async t=>{
  const f=fixture(t);await f.social.open('messages');
  assert.equal((await f.request(origin+'/admin/desktop-dashboard/session','GET',{Authorization:'Bearer renderer-token'})).cancel,true);
  const result=await f.request(origin+'/admin/meta/conversations','GET',{'X-Fasakhansta-Desktop':'local-secret','X-Fasakhansta-Remote-Attempt':'forged',Authorization:'Bearer renderer-token'});
  assert.equal(result.cancel,undefined);assert.equal(result.requestHeaders.Authorization,undefined);assert.equal(result.requestHeaders['X-Fasakhansta-Desktop'],undefined);assert.equal(result.requestHeaders['X-Fasakhansta-Remote-Attempt'],undefined);
  assert.equal(result.requestHeaders['X-Fasakhansta-Connected-Token'],'a'.repeat(64));
});

test('losing the same-request authorization protocol after opening cancels sends before outbound customer work',async t=>{
  const f=fixture(t);await f.social.open('comments');const session=f.sessions[0];
  session.fetch=async()=>new Response(JSON.stringify({actor_id:10,device_id:device,connected_request_protocol:0}),{headers:{'Content-Type':'application/json'}});
  assert.equal((await f.request(origin+'/admin/meta/comments/reply','POST')).cancel,true);assert.deepEqual(f.writes,[]);
});

test('an initial Internet probe failure shows a restricted page whose explicit reconnect verifies the session afresh',async t=>{
  const f=fixture(t,{probeError:Error('net::ERR_INTERNET_DISCONNECTED')});await f.social.open('messages');const w=f.windows[0];
  assert.equal(w.loads.length,1);assert.ok(w.url.startsWith('data:text/html;'));const page=decodeURIComponent(w.url.slice(w.url.indexOf(',')+1));
  assert.match(page,/إعادة الاتصال/);assert.match(page,/Content-Security-Policy/);assert.doesNotMatch(page,/owned-login|local-private|a{64}/);
  f.setProbeError(null);let prevented=false;w.webContents.emit('will-navigate',{preventDefault(){prevented=true;}},origin+'/admin/meta');await settle();
  assert.equal(prevented,true);assert.equal(w.url,origin+'/admin/meta');assert.equal(f.probeCalls.length,2);assert.deepEqual(f.writes,[]);
});

test('a failed outbound preflight keeps its send cancelled while an explicit later read can restore connectivity without replay',async t=>{
  const f=fixture(t);await f.social.open('messages');f.setProbeError(Error('net::ERR_CONNECTION_RESET'));
  const send=origin+'/admin/meta/conversations/25/replies';assert.equal((await f.request(send,'POST')).cancel,true);assert.deepEqual(f.writes,[]);
  f.setProbeError(null);const read=await f.request(origin+'/admin/meta/conversations/25/messages');
  assert.equal(read.cancel,undefined);assert.equal(read.requestHeaders['X-Fasakhansta-Connected-Device'],device);assert.deepEqual(f.writes,[]);assert.equal(f.windows[0].loads.length,1);
});

test('closing while authorization awaits its current enrollment cannot release the request after disposal',async t=>{
  const f=fixture(t);await f.social.open('messages');const original=f.runtime.connection;let release;
  f.runtime.connection=()=>new Promise(resolve=>release=async()=>resolve(await original()));
  const pending=f.request(origin+'/admin/meta/conversations/25/messages');await settle();assert.equal(typeof release,'function');
  f.social.close();await release();assert.deepEqual(await pending,{cancel:true});assert.deepEqual(f.writes,[]);
});

test('opening another social section preserves the existing draft until its window is explicitly closed',async t=>{
  const f=fixture(t);await f.social.open('messages');const w=f.windows[0],before=[...w.loads];
  const result=await f.social.open('comments');assert.equal(result.kind,'messages');assert.equal(f.windows.length,1);assert.deepEqual(w.loads,before);assert.match(f.notices.at(-1),/مسودتك/);
});

test('microphone capture requires an explicit native approval for owned audio on the verified message page',async t=>{
  let approvals=0;const f=fixture(t,{confirmMicrophone:async()=>{approvals++;return true;}});await f.social.open('messages');const w=f.windows[0],s=f.sessions[0];
  const permission=details=>new Promise(resolve=>s.hooks.permission(w.webContents,'media',resolve,details));
  assert.equal(s.hooks.check(w.webContents,'media',origin,{mediaType:'audio'}),false);
  for(const details of [{mediaTypes:['video']},{mediaTypes:['audio','video']},{mediaTypes:['audio'],requestingUrl:'https://evil.test'}])assert.equal(await permission(details),false);
  assert.equal(approvals,0);assert.equal(await permission({mediaTypes:['audio'],requestingUrl:origin+'/admin/meta'}),true);assert.equal(approvals,1);
  assert.equal(s.hooks.check(w.webContents,'media',origin,{mediaType:'audio'}),true);assert.equal(s.hooks.check(w.webContents,'media',origin,{mediaType:'video'}),false);
  assert.equal(s.hooks.check(w.webContents,'media','https://evil.test',{mediaType:'audio'}),false);
});

test('closing a pending microphone prompt denies the late approval and keeps the local dashboard intact',async t=>{
  let approve;const f=fixture(t,{confirmMicrophone:()=>new Promise(resolve=>approve=resolve)});await f.social.open('messages');const w=f.windows[0],s=f.sessions[0];
  const pending=new Promise(resolve=>s.hooks.permission(w.webContents,'media',resolve,{mediaTypes:['audio'],requestingUrl:origin+'/admin/meta'}));await settle();assert.equal(typeof approve,'function');
  f.social.close();approve(true);assert.equal(await pending,false);assert.deepEqual(f.local,{origin:'http://127.0.0.1:45000',token:'local-private',mode:'local'});
});

test('verified Meta image thumbnails strip private headers while CDN scripts and writes remain blocked',async t=>{
  const f=fixture(t);await f.social.open('messages');const headers={Cookie:'session=private',Authorization:'Bearer secret','X-Fasakhansta-Connected-Token':'a'.repeat(64)};
  for(const host of ['scontent.fcai1-2.fna.fbcdn.net','scontent.cdninstagram.com']){
    const url='https://'+host+'/thumbnail.jpg';const image=await f.request(url,'GET',headers,{resourceType:'image'});
    assert.equal(image.cancel,undefined);assert.deepEqual(image.requestHeaders,{});assert.equal((await f.request(url,'POST',headers,{resourceType:'image'})).cancel,true);
    assert.equal((await f.request(url,'GET',headers,{resourceType:'script'})).cancel,true);
  }
  assert.equal((await f.request('https://fbcdn.net.evil.test/thumbnail.jpg','GET',headers,{resourceType:'image'})).cancel,true);
});
