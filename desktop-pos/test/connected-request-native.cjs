'use strict';
// Windows-only isolated HTTPS/Electron acceptance. The printer adapter is
// intercepted; no production server, account, provider or paper is involved.
const {app,BrowserWindow,safeStorage,session}=require('electron');
const fs=require('node:fs/promises'),fsSync=require('node:fs'),path=require('node:path'),os=require('node:os');
const https=require('node:https'),http=require('node:http'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
assert.equal(process.platform,'win32','Run this acceptance in actual Windows Electron.');
const lockedElectron=JSON.parse(fsSync.readFileSync(path.resolve(__dirname,'../package-lock.json'),'utf8')).packages['node_modules/electron'].version;
assert.equal(process.versions.electron,lockedElectron,'The actual Electron process must match its locked package.');
const root=fsSync.mkdtempSync(path.join(os.tmpdir(),'fasakhansta-connected-native-'));
app.setPath('userData',path.join(root,'isolated-profile'));
const sourceRoot=process.env.DESKTOP_TEST_SOURCE_MODULES||path.resolve(__dirname,'../src');
const createDashboard=require(path.join(sourceRoot,'dashboard.cjs'));
const {DashboardConnected,binding}=require(path.join(sourceRoot,'dashboard-connected.cjs'));
const RemoteAttempts=require(path.join(sourceRoot,'dashboard-remote-attempts.cjs'));
const RemoteState=require(path.join(sourceRoot,'dashboard-remote-state.cjs'));
const {GenerationStore}=require(path.join(sourceRoot,'dashboard-generation.cjs'));
const DashboardMode=require(path.join(sourceRoot,'dashboard-mode.cjs'));
const cert=fsSync.readFileSync(path.join(__dirname,'fixtures/connected-loopback-cert.pem'));
const key=fsSync.readFileSync(path.join(__dirname,'fixtures/connected-loopback-key.pem'));
const expectedFingerprint=new crypto.X509Certificate(cert).fingerprint256;
const sources=['legacy','store','service','partner_service'],captures=[],foreignCaptures=[],writtenKeys=new Set();
const authRequests=[],businessRequests=[],localControls=[];let signinActor=1,reauthSuccesses=0;
const networkEvents=[],metadataEvents=[],actualCompletions=[],completionObservers=new Set(),networkRegistrations={};
const diagnostic=()=>({networkEvents,metadataEvents,actualCompletions,networkRegistrations});
const recordDiagnostic=(list,value)=>{if(list.length<400)list.push(value);};
const errorIdentity=error=>({name:String(error?.name||'Error'),code:String(error?.code||''),
  message:String(error?.message||'').replace(/\b[a-f0-9]{64}\b/gi,'[redacted]').replace(/fixture-(?:private|auth)-password/g,'[redacted]').slice(0,500)});
let quitting=false,server,foreignServer,localServer,dashboard,protocol=1,loseNextPrint=false,invocations=0,certificateChecks=0,fixtureFailure;
const timer=setTimeout(()=>{process.stderr.write('Connected native acceptance exceeded its bounded deadline.\n');app.exit(1);},120000);
// Keep the fixture alive until awaited cleanup propagates its actual failure code.
app.on('window-all-closed',()=>{});
app.on('web-contents-created',(_event,contents)=>{
  contents.getPrintersAsync=async()=>[{name:'Fixture-Only-Printer',isDefault:true}];
  contents.print=(options,callback)=>{assert.equal(options.silent,true);assert.equal(options.deviceName,'Fixture-Only-Printer');invocations++;callback(true);};
});
async function run(){
  await app.whenReady();assert.equal(safeStorage.isEncryptionAvailable(),true);
  const store=new GenerationStore(path.join(root,'durable-audit'),safeStorage);
  const metadata={read:key=>store.read(key),write:async(key,value)=>{
    recordDiagnostic(metadataEvents,{key,phase:'start'});
    try{await store.write(key,value);writtenKeys.add(key);recordDiagnostic(metadataEvents,{key,phase:'success'});}
    catch(error){recordDiagnostic(metadataEvents,{key,phase:'failure',error:errorIdentity(error)});throw error;}
  }};
  const state=new RemoteState(metadata);
  const credential={deviceId:crypto.randomUUID(),actorId:1,token:crypto.randomBytes(32).toString('hex'),sourceRevision:'b'.repeat(40),
    sourceFingerprint:{format:1,sha256:'c'.repeat(64),framework:'8.83.29',files:100},snapshotId:crypto.randomUUID(),refreshId:crypto.randomUUID(),
    schemaHash:'d'.repeat(64),database:'fasakhansta_dashboard_stage_'+'e'.repeat(16)};
  const reply=(response,status,value)=>{response.writeHead(status,{'Content-Type':'application/json','Cache-Control':'no-store'});response.end(JSON.stringify(value));};
  foreignServer=http.createServer((request,response)=>{foreignCaptures.push(request.headers);reply(response,200,{ok:true});});
  await new Promise(resolve=>foreignServer.listen(0,'127.0.0.1',resolve));
  const foreignOrigin='http://127.0.0.1:'+foreignServer.address().port;
  const localToken='fixture-local-browser-token';
  localServer=http.createServer((request,response)=>{
    if(new URL(request.url,'http://127.0.0.1').pathname==='/admin/dashboard')assert.equal(request.headers['x-fasakhansta-desktop'],localToken);
    response.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'});
    response.end('<!doctype html><html><head><meta charset="utf-8"></head><body>نسخة الجهاز — اختبار</body></html>');
  });
  await new Promise(resolve=>localServer.listen(0,'127.0.0.1',resolve));
  const localOrigin='http://127.0.0.1:'+localServer.address().port;
  server=https.createServer({key,cert},async(request,response)=>{
    try{
      const url=new URL(request.url,credential.serverOrigin);
      const cookieActor=Number(/(?:^|;\s*)connected_fixture_actor=([0-9]+)(?:;|$)/.exec(request.headers.cookie||'')?.[1]||0);
      if(url.pathname==='/admin/desktop-dashboard/session'){
        assert.equal(request.headers.authorization,'Bearer '+credential.token);
        if(!cookieActor){reply(response,401,{message:'Unauthenticated.'});return;}
        reply(response,200,{actor_id:cookieActor,device_id:credential.deviceId,...(protocol===undefined?{}:{connected_request_protocol:protocol})});return;
      }
      if(url.pathname==='/admin/login'){
        assert.equal(request.method,'GET');authRequests.push({method:'GET',path:url.pathname});
        // Original form contract: document POST + CSRF + HttpOnly cookies. The
        // native modal never substitutes its own credential form or reads it.
        response.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store',
          'Set-Cookie':'connected_fixture_csrf=fixture-csrf; Path=/; Secure; HttpOnly; SameSite=Lax'});
        response.end('<!doctype html><html><head><meta charset="utf-8"><title>تسجيل دخول السيرفر</title></head><body><form action="/admin/signin" method="post"><input type="hidden" name="_token" value="fixture-csrf"><input name="email" autocomplete="off"><input name="password" type="password" autocomplete="new-password"><input type="checkbox" name="remember" value="1"><button type="submit">دخول</button></form></body></html>');return;
      }
      if(url.pathname==='/admin/signin'){
        assert.equal(request.method,'POST');let body='';for await(const part of request)body+=part;
        const form=new URLSearchParams(body);assert.equal(form.get('_token'),'fixture-csrf');
        assert.equal(form.get('email'),'fixture@example.test');assert.equal(form.get('password'),'fixture-auth-password');assert.equal(form.get('remember'),'1');
        assert.match(request.headers.cookie||'',/(?:^|;\s*)connected_fixture_csrf=fixture-csrf(?:;|$)/);
        assert.equal(Object.keys(request.headers).some(name=>/^x-fasakhansta-/i.test(name)),false);
        authRequests.push({method:'POST',path:url.pathname});response.writeHead(302,{'Location':'/admin/dashboard','Cache-Control':'no-store',
          'Set-Cookie':'connected_fixture_actor='+signinActor+'; Path=/; Secure; HttpOnly; SameSite=Lax'});response.end();return;
      }
      if(url.pathname==='/admin'||url.pathname.startsWith('/admin/'))businessRequests.push({method:request.method,path:url.pathname});
      const source=/^\/admin\/order-board\/(legacy|store|service|partner_service)\/20\/print$/.exec(url.pathname)?.[1];
      if(source||request.method==='POST'&&url.pathname==='/admin/users/20'){
        assert.equal(request.headers['x-fasakhansta-connected-device'],credential.deviceId);
        assert.equal(request.headers['x-fasakhansta-connected-token'],credential.token);
        assert.equal(request.headers['x-fasakhansta-connected-request'],undefined);
        assert.equal(request.headers['x-fasakhansta-remote-attempt'],undefined);
        assert.equal(request.headers['x-fasakhansta-remote-capability'],undefined);
        assert.match(request.headers.cookie||'',/connected_fixture_actor=1/);
        const pending=await state.read();assert.equal(pending.dirty,true);assert.equal(pending.pending.length,1);
        const record=await metadata.read('remote-central/'+pending.pending[0]);assert.equal(record.method,request.method);assert.equal(record.path,url.pathname);
        const item={method:request.method,path:url.pathname,source:source||null,attempt:pending.pending[0]};captures.push(item);
        if(source){
          assert.equal(request.method,'GET');assert.equal(url.hash,'');assert.equal(url.searchParams.get('dashboard_print'),'1');
          if(loseNextPrint){loseNextPrint=false;item.lost=true;response.writeHead(200,{'Content-Type':'text/html','Content-Length':'100000'});
            response.write('<html data-dashboard-receipt="order-board"><head></head><body>',()=>response.destroy());return;}
          response.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});response.end('<html data-dashboard-receipt="order-board"><head><meta charset="utf-8"></head><body>فسخانستا — اختبار متصل</body></html>');return;
        }
        let body='';for await(const part of request)body+=part;
        assert.deepEqual(JSON.parse(body),{name:'Original fixture name',password:'fixture-private-password',roles_name:['1']});reply(response,200,{ok:true});return;
      }
      response.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'});
      response.end('<!doctype html><html><head><meta charset="utf-8"></head><body>داشبورد اختبار</body></html>');
    }catch(error){fixtureFailure=error;if(!response.headersSent)reply(response,500,{ok:false});else response.destroy();}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));credential.serverOrigin='https://127.0.0.1:'+server.address().port;
  const browserSession=session.fromPartition('persist:fasakhansta-dashboard');
  browserSession.setCertificateVerifyProc((request,callback)=>{
    let fingerprint;try{fingerprint=new crypto.X509Certificate(request.certificate.data).fingerprint256;}catch{}
    const accepted=request.hostname==='127.0.0.1'&&fingerprint===expectedFingerprint;if(accepted)certificateChecks++;callback(accepted?0:-2);
  });
  // Observe the real interceptor registration once; never synthesize network
  // events or invoke its handler independently of Electron's actual session.
  for(const name of ['onBeforeSendHeaders','onCompleted','onErrorOccurred']){
    const register=browserSession.webRequest[name].bind(browserSession.webRequest);
    browserSession.webRequest[name]=(filter,handler)=>{
      networkRegistrations[name]=(networkRegistrations[name]||0)+1;
      return register(filter,(details,...rest)=>{
        let pathname;try{const value=new URL(details.url);pathname=['http:','https:'].includes(value.protocol)?value.pathname:value.protocol;}catch{pathname='invalid';}
        recordDiagnostic(networkEvents,{event:name,id:details.id,method:details.method,path:pathname,webContentsId:details.webContentsId,
          ...(details.error?{error:String(details.error).match(/ERR_[A-Z_]+/)?.[0]||'network-error'}:{})});
        if(name==='onBeforeSendHeaders'){
          const callback=rest[0];return handler(details,result=>{
            recordDiagnostic(networkEvents,{event:'headers-decision',id:details.id,method:details.method,path:pathname,webContentsId:details.webContentsId,cancel:result?.cancel===true});
            callback(result);
          });
        }
        return handler(details,...rest);
      });
    };
  }
  const runtime={metadata,origin:localOrigin,token:localToken,isPrepared:async()=>true,connection:async()=>credential,
    control:async value=>{localControls.push(value);throw Error('Native reauthentication must not touch the local write fence.');}};
  let dashboardMode;
  const access=new DashboardConnected({runtime,view:()=>dashboard,mode:()=>dashboardMode,remoteState:state,probe:async current=>{
    const response=await dashboard.session().fetch(current.serverOrigin+'/admin/desktop-dashboard/session',{credentials:'include',
      headers:{Accept:'application/json',Authorization:'Bearer '+current.token},redirect:'error',signal:AbortSignal.timeout(10000)});
    assert.equal(response.ok,true);return response.json();
  }});
  const options={state,metadata,credential:async()=>credential,direct:details=>access.authorizeDirect(details),request:async()=>{throw Error('Full connected originals must not use bounded reconciliation.');}};
  const attempts=new RemoteAttempts(options);
  const complete=attempts.complete.bind(attempts);
  attempts.complete=id=>{
    const observation={id,started:true,phase:'start'};actualCompletions.push(observation);let result;
    try{result=complete(id);}catch(error){observation.phase='failure';observation.error=errorIdentity(error);for(const notify of completionObservers)notify();throw error;}
    return Promise.resolve(result).then(value=>{observation.phase='success';for(const notify of completionObservers)notify();return value;},error=>{
      observation.phase='failure';observation.error=errorIdentity(error);for(const notify of completionObservers)notify();throw error;
    });
  };
  async function waitActualCompletion(id){
    await new Promise((resolve,reject)=>{
      const timer=setTimeout(()=>finish(Error('Actual Electron onCompleted did not complete its durable request within 30 seconds.')),30000);
      const finish=error=>{clearTimeout(timer);completionObservers.delete(check);error?reject(error):resolve();};
      const check=()=>{
        const actual=actualCompletions.find(value=>value.id===id);
        if(actual?.phase==='failure')finish(Error('Actual Electron completion rejected: '+JSON.stringify(actual.error)));
        else if(actual?.phase==='success')finish();
      };
      completionObservers.add(check);check();
    });
  }
  dashboard=createDashboard({origin:localOrigin,localToken,serverOrigin:credential.serverOrigin,remoteState:state,remoteAttempts:attempts,browse:()=>access.browse(),
    quitting:()=>quitting,quit:()=>{},offline:()=>{},offlineWindow:()=>BrowserWindow.getAllWindows()[0],printer:()=> 'Fixture-Only-Printer'});
  dashboardMode=new DashboardMode({runtime,view:dashboard,remoteState:state,remoteAttempts:attempts,
    generations:{run:async()=>{throw Error('Native reauthentication must not replace the local generation.');}}});
  await dashboard.open();const window=BrowserWindow.getAllWindows().find(value=>value.webContents.getURL()===localOrigin+'/admin/dashboard');assert.ok(window);
  assert.deepEqual(networkRegistrations,{onBeforeSendHeaders:1,onCompleted:1,onErrorOccurred:1});
  const invoke=source=>window.webContents.executeJavaScript(source);
  assert.equal(await invoke('typeof window.FasakhanstaDesktop.printReceipt'),'function');
  async function startAuthentication(){
    const outcome=access.reauthenticate().then(value=>({value}),error=>({error}));let modal;
    for(let count=0;count<200;count++){
      modal=BrowserWindow.getAllWindows().find(value=>value!==window&&!value.isDestroyed()&&value.isModal()&&value.webContents.getURL()===credential.serverOrigin+'/admin/login');
      if(modal)break;await new Promise(resolve=>setTimeout(resolve,25));
    }
    assert.ok(modal,'The native original-login modal loaded before bounded deadline.');
    assert.equal(await modal.webContents.executeJavaScript('typeof window.FasakhanstaDesktop'), 'undefined');
    assert.equal(await modal.webContents.executeJavaScript('typeof require'), 'undefined');
    return {modal,outcome};
  }
  async function submitAuthentication(modal){
    await modal.webContents.executeJavaScript("document.querySelector('[name=email]').value='fixture@example.test';document.querySelector('[name=password]').value='fixture-auth-password';document.querySelector('[name=remember]').checked=true;document.forms[0].requestSubmit();true");
  }
  // Start from the actual local HTTP view. Native authentication may renew
  // cookies, but must not enter central mode or touch a local generation/fence.
  const cleanLocalState=await state.read();assert.deepEqual(cleanLocalState,{format:1,dirty:false,pending:[]});
  assert.equal(await metadata.read('central-mode'),null);assert.equal(await metadata.read('return'),null);
  await assert.rejects(access.sameActor(credential));
  assert.deepEqual(await state.read(),cleanLocalState);
  const localAuth=await startAuthentication();await submitAuthentication(localAuth.modal);const localResult=await localAuth.outcome;
  assert.equal(localResult.error,undefined);assert.deepEqual(localResult.value,{authenticated:true});reauthSuccesses++;
  assert.equal(localAuth.modal.isDestroyed(),true);await access.sameActor(credential);
  assert.deepEqual(dashboard.current(),{origin:localOrigin,local:true});assert.equal(window.webContents.getURL(),localOrigin+'/admin/dashboard');
  assert.equal(await metadata.read('central-mode'),null);assert.equal(await metadata.read('return'),null);
  assert.deepEqual(await state.read(),cleanLocalState);assert.deepEqual(localControls,[]);
  assert.equal(authRequests.length,2);assert.equal(businessRequests.length,0);
  // Fixture setup for the remaining full-central tests. This explicit switch
  // does not claim a real local-generation activation or a production transition.
  await metadata.write('central-mode',{format:1,phase:'central',binding:binding(credential)});
  await dashboard.switchTo({origin:credential.serverOrigin,localToken:''});
  assert.deepEqual(dashboard.current(),{origin:credential.serverOrigin,local:false});
  assert.equal(window.webContents.getURL(),credential.serverOrigin+'/admin/dashboard');
  const original=await invoke(`fetch('/admin/users/20',{method:'POST',headers:{'Content-Type':'application/json','X-Fasakhansta-Connected-Device':'forged','X-Fasakhansta-Connected-Token':'forged','X-Fasakhansta-Remote-Capability':'forged'},body:JSON.stringify({name:'Original fixture name',password:'fixture-private-password',roles_name:['1']})}).then(response=>response.json())`);
  assert.equal(original.ok,true);assert.equal(fixtureFailure,undefined);
  // Wait for the actual production onCompleted -> attempts.complete promise,
  // not repeated decrypted reads and never a manual completion/recovery call.
  const originalAttempt=captures.find(value=>value.source===null)?.attempt;assert.ok(originalAttempt);
  await waitActualCompletion(originalAttempt);
  assert.deepEqual((await state.read()).pending,[],JSON.stringify(diagnostic()));
  assert.equal(metadataEvents.some(value=>value.phase==='failure'),false,JSON.stringify(diagnostic()));
  for(const source of sources)await invoke('window.FasakhanstaDesktop.printReceipt('+JSON.stringify(credential.serverOrigin+'/admin/order-board/'+source+'/20/print#receipt')+')');
  assert.equal(invocations,4);assert.equal(fixtureFailure,undefined);assert.equal(captures.length,5);
  const proof={'X-Fasakhansta-Connected-Device':credential.deviceId,'X-Fasakhansta-Connected-Token':credential.token,'X-Fasakhansta-Connected-Request':'f'.repeat(64)};
  // Actual native session.fetch requests without the private one-use allowlist
  // must fail before reaching the server, even when carrying a valid token.
  for(const headers of [proof,{'X-Fasakhansta-Connected-Device':credential.deviceId,'X-Fasakhansta-Connected-Token':credential.token}])
    await assert.rejects(browserSession.fetch(credential.serverOrigin+'/admin/order-board/legacy/20/print',{headers,redirect:'error',signal:AbortSignal.timeout(10000)}));
  assert.equal(captures.length,5);
  const foreign=await browserSession.fetch(foreignOrigin+'/capture',{headers:{...proof,'X-Fasakhansta-Remote-Attempt':'forged','X-Fasakhansta-Remote-Capability':'forged'},redirect:'error',signal:AbortSignal.timeout(10000)});
  assert.equal(foreign.ok,true);await foreign.text();assert.equal(foreignCaptures.length,1);
  assert.equal(Object.keys(foreignCaptures[0]).some(name=>/^x-fasakhansta-/i.test(name)),false);
  loseNextPrint=true;await assert.rejects(invoke('window.FasakhanstaDesktop.printReceipt('+JSON.stringify(credential.serverOrigin+'/admin/order-board/legacy/20/print#lost')+')'));
  assert.equal(invocations,4);assert.equal(fixtureFailure,undefined);assert.equal(captures.filter(value=>value.lost).length,1);
  const pending=(await state.read()).pending;assert.equal(pending.length,1);
  const restarted=new RemoteState(new GenerationStore(path.join(root,'durable-audit'),safeStorage));assert.deepEqual((await restarted.read()).pending,pending);
  await assert.rejects(new RemoteAttempts({...options,state:restarted}).recover(),/لم يتأكد رد/);
  // A lost business result survives explicit native server-session renewal.
  // This exercises actual modal windows and the original document signin POST,
  // without exposing enrollment credentials or enabling a renderer auth IPC.
  const originalMode=await metadata.read('central-mode'),originalReturn=await metadata.read('return');
  const beforeAuthBusiness=businessRequests.length,beforeAuthForeign=foreignCaptures.length;
  await browserSession.cookies.remove(credential.serverOrigin,'connected_fixture_actor');
  await assert.rejects(invoke('window.FasakhanstaDesktop.printReceipt('+JSON.stringify(credential.serverOrigin+'/admin/order-board/legacy/20/print')+')'));
  assert.equal(businessRequests.length,beforeAuthBusiness);assert.deepEqual((await state.read()).pending,pending);
  async function unchangedAuthenticationState(){
    assert.deepEqual(await metadata.read('central-mode'),originalMode);assert.deepEqual(await metadata.read('return'),originalReturn);
    assert.deepEqual((await state.read()).pending,pending);assert.equal(dashboard.current().local,false);
    assert.equal(window.webContents.getURL(),credential.serverOrigin+'/admin/dashboard');assert.deepEqual(localControls,[]);
    assert.equal(businessRequests.length,beforeAuthBusiness);assert.equal(foreignCaptures.length,beforeAuthForeign);
  }
  const firstAuth=await startAuthentication();
  for(const [method,path]of [['POST','/admin/users/20'],['GET','/admin/resturantControl'],['GET','/admin/dashboard']])
    await assert.rejects(firstAuth.modal.webContents.executeJavaScript('fetch('+JSON.stringify(path)+',{method:'+JSON.stringify(method)+'}).then(response=>response.text())'));
  await assert.rejects(firstAuth.modal.webContents.executeJavaScript('fetch('+JSON.stringify(foreignOrigin+'/auth-escape')+').then(response=>response.text())'));
  await assert.rejects(invoke("fetch('/admin/users/20',{method:'POST'}).then(response=>response.text())"));
  await assert.rejects(invoke('window.FasakhanstaDesktop.printReceipt('+JSON.stringify(credential.serverOrigin+'/admin/order-board/legacy/20/print')+')'));
  const forbiddenNavigation=new Promise(resolve=>firstAuth.modal.webContents.once('will-navigate',(event,url)=>{assert.equal(url,credential.serverOrigin+'/admin/resturantControl');assert.equal(event.defaultPrevented,true);resolve();}));
  await firstAuth.modal.webContents.executeJavaScript("location.href='/admin/resturantControl';true");await forbiddenNavigation;
  const beforePopup=BrowserWindow.getAllWindows().length;
  assert.equal(await firstAuth.modal.webContents.executeJavaScript("window.open('/admin/dashboard')===null"),true);
  assert.equal(BrowserWindow.getAllWindows().length,beforePopup);await unchangedAuthenticationState();
  await submitAuthentication(firstAuth.modal);const firstResult=await firstAuth.outcome;
  assert.equal(firstResult.error,undefined);assert.deepEqual(firstResult.value,{authenticated:true});reauthSuccesses++;
  assert.equal(firstAuth.modal.isDestroyed(),true);await access.sameActor(credential);
  assert.deepEqual(await access.open(),{central:true});await unchangedAuthenticationState();
  signinActor=2;const wrongActor=await startAuthentication();await submitAuthentication(wrongActor.modal);
  const wrongResult=await wrongActor.outcome;assert.match(wrongResult.error?.message||'',/لا يطابق/);assert.equal(wrongActor.modal.isDestroyed(),true);
  assert.equal((await browserSession.cookies.get({url:credential.serverOrigin})).some(value=>value.name==='connected_fixture_actor'),false);
  await unchangedAuthenticationState();
  signinActor=1;protocol=2;const oldProtocol=await startAuthentication();await submitAuthentication(oldProtocol.modal);
  const oldResult=await oldProtocol.outcome;assert.match(oldResult.error?.message||'',/لم يؤكد حماية/);assert.equal(oldProtocol.modal.isDestroyed(),true);
  assert.equal((await browserSession.cookies.get({url:credential.serverOrigin})).some(value=>value.name==='connected_fixture_actor'),false);
  await unchangedAuthenticationState();
  protocol=1;const cancelled=await startAuthentication();cancelled.modal.close();
  const cancelledResult=await cancelled.outcome;assert.ok(cancelledResult.error);assert.equal(cancelled.modal.isDestroyed(),true);await unchangedAuthenticationState();
  const finalAuth=await startAuthentication();await submitAuthentication(finalAuth.modal);const finalResult=await finalAuth.outcome;
  assert.equal(finalResult.error,undefined);assert.deepEqual(finalResult.value,{authenticated:true});reauthSuccesses++;
  await access.sameActor(credential);await unchangedAuthenticationState();
  assert.equal(authRequests.filter(value=>value.method==='GET').length,6);assert.equal(authRequests.filter(value=>value.method==='POST').length,5);
  assert.equal(reauthSuccesses,3);assert.equal(fixtureFailure,undefined);
  const beforeOld=captures.length;
  for(const unsupported of [undefined,0,2,'1',null]){
    protocol=unsupported;
    for(const source of sources)await assert.rejects(invoke('window.FasakhanstaDesktop.printReceipt('+JSON.stringify(credential.serverOrigin+'/admin/order-board/'+source+'/20/print')+')'),/لم يؤكد حماية/);
  }
  assert.equal(captures.length,beforeOld);assert.equal(invocations,4);assert.deepEqual((await state.read()).pending,pending);assert.ok(certificateChecks>0);
  const audit=[];for(const name of writtenKeys)if(name.startsWith('remote-central'))audit.push(await store.read(name));
  assert.equal(JSON.stringify(audit).includes(credential.token),false);assert.equal(JSON.stringify(audit).includes('fixture-private-password'),false);assert.equal(JSON.stringify(audit).includes('fixture-auth-password'),false);
  const encrypted=await fs.readFile(path.join(root,'durable-audit','remote-central',pending[0]+'.enc'));assert.equal(encrypted.includes(Buffer.from(credential.token)),false);
  const repo=path.resolve(__dirname,'../..'),sourceRevision=execFileSync('git',['rev-parse','HEAD'],{cwd:repo,encoding:'utf8'}).trim();
  const canonical=value=>value.toString('utf8').replace(/\r\n/g,'\n');
  for(const file of ['dashboard.cjs','dashboard-connected.cjs','dashboard-remote-attempts.cjs','dashboard-mode.cjs'])assert.equal(canonical(await fs.readFile(path.join(sourceRoot,file))),canonical(execFileSync('git',['show',sourceRevision+':desktop-pos/src/'+file],{cwd:repo})));
  const result={format:1,sourceRevision,platform:process.platform,electron:process.versions.electron,electronVersion:process.versions.electron,completed:true,hardware:false,
    adapter:'intercepted Electron webContents.print',actualElectronSessionFetch:true,actualWebRequest:true,actualDpapi:true,pinnedLoopbackHttps:true,
    pairedProofOnWire:true,internalMarkerStripped:true,foreignProofStripped:true,nativeReplayBlocked:true,fragmentNormalized:true,oldProtocolBlocked:true,
    lostReplyRetained:true,unboundedOriginalWrite:true,nativeReauthentication:true,expiredCookieBlocked:true,reauthSameActorProtocol:true,
    reauthWrongActorBlocked:true,reauthOldProtocolBlocked:true,reauthMutationBlocked:true,reauthCancelled:true,reauthPreservedMode:true,
    reauthNoPreload:true,reauthRedirectBlocked:true,nativeLocalReauthentication:true,localReauthentications:1,reauthentications:6,reauthOriginalSignins:5,reauthSuccesses:3,printedSources:4,originalWrites:1,invocations:4,lostPrintRequests:1};
  const destination=path.resolve(process.argv[2]||path.join(__dirname,'../dist/connected-request-native.json'));
  await fs.mkdir(path.dirname(destination),{recursive:true});await fs.writeFile(destination,JSON.stringify(result,null,2)+'\n');
  process.stdout.write('PASS actual Windows Electron connected proof on the wire, native HTTPS print IPC, isolated DPAPI and fail-closed requests (printer adapter intercepted)\n');
}
run().then(()=>finish(0),error=>{process.stderr.write((fixtureFailure||error).stack+'\n');process.stderr.write('CONNECTED_NATIVE_DIAGNOSTIC='+JSON.stringify(diagnostic())+'\n');return finish(1);});
async function finish(code){
  clearTimeout(timer);quitting=true;
  try{
    for(const window of BrowserWindow.getAllWindows())window.destroy();
    for(const active of [server,foreignServer,localServer])if(active){active.closeAllConnections?.();await new Promise(resolve=>active.close(resolve));}
    await fs.rm(path.join(root,'durable-audit'),{recursive:true,force:true});
  }catch(error){code=1;process.stderr.write('Connected native cleanup failed: '+JSON.stringify(errorIdentity(error))+'\n');}
  finally{app.exit(code);}
}
