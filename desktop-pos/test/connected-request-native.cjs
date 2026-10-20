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
const cert=fsSync.readFileSync(path.join(__dirname,'fixtures/connected-loopback-cert.pem'));
const key=fsSync.readFileSync(path.join(__dirname,'fixtures/connected-loopback-key.pem'));
const expectedFingerprint=new crypto.X509Certificate(cert).fingerprint256;
const sources=['legacy','store','service','partner_service'],captures=[],foreignCaptures=[],writtenKeys=new Set();
let quitting=false,server,foreignServer,dashboard,protocol=1,loseNextPrint=false,invocations=0,certificateChecks=0,fixtureFailure;
const timer=setTimeout(()=>{process.stderr.write('Connected native acceptance exceeded its bounded deadline.\n');app.exit(1);},120000);
app.on('web-contents-created',(_event,contents)=>{
  contents.getPrintersAsync=async()=>[{name:'Fixture-Only-Printer',isDefault:true}];
  contents.print=(options,callback)=>{assert.equal(options.silent,true);assert.equal(options.deviceName,'Fixture-Only-Printer');invocations++;callback(true);};
});
async function run(){
  await app.whenReady();assert.equal(safeStorage.isEncryptionAvailable(),true);
  const store=new GenerationStore(path.join(root,'durable-audit'),safeStorage);
  const metadata={read:key=>store.read(key),write:async(key,value)=>{await store.write(key,value);writtenKeys.add(key);}};
  const state=new RemoteState(metadata);
  const credential={deviceId:crypto.randomUUID(),actorId:1,token:crypto.randomBytes(32).toString('hex'),sourceRevision:'b'.repeat(40),
    sourceFingerprint:{format:1,sha256:'c'.repeat(64),framework:'8.83.29',files:100},snapshotId:crypto.randomUUID(),refreshId:crypto.randomUUID(),
    schemaHash:'d'.repeat(64),database:'fasakhansta_dashboard_stage_'+'e'.repeat(16)};
  const reply=(response,status,value)=>{response.writeHead(status,{'Content-Type':'application/json','Cache-Control':'no-store'});response.end(JSON.stringify(value));};
  foreignServer=http.createServer((request,response)=>{foreignCaptures.push(request.headers);reply(response,200,{ok:true});});
  await new Promise(resolve=>foreignServer.listen(0,'127.0.0.1',resolve));
  const foreignOrigin='http://127.0.0.1:'+foreignServer.address().port;
  server=https.createServer({key,cert},async(request,response)=>{
    try{
      const url=new URL(request.url,credential.serverOrigin);
      if(url.pathname==='/admin/desktop-dashboard/session'){
        assert.equal(request.headers.authorization,'Bearer '+credential.token);
        assert.match(request.headers.cookie||'',/connected_fixture_actor=1/);
        reply(response,200,{actor_id:1,device_id:credential.deviceId,...(protocol===undefined?{}:{connected_request_protocol:protocol})});return;
      }
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
        const item={method:request.method,path:url.pathname,source:source||null};captures.push(item);
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
  await browserSession.cookies.set({url:credential.serverOrigin,name:'connected_fixture_actor',value:'1',secure:true,httpOnly:true,sameSite:'lax'});
  await metadata.write('central-mode',{format:1,phase:'central',binding:binding(credential)});
  const runtime={metadata,isPrepared:async()=>true,connection:async()=>credential};
  const access=new DashboardConnected({runtime,remoteState:state,probe:async current=>{
    const response=await dashboard.session().fetch(current.serverOrigin+'/admin/desktop-dashboard/session',{credentials:'include',
      headers:{Accept:'application/json',Authorization:'Bearer '+current.token},redirect:'error',signal:AbortSignal.timeout(10000)});
    assert.equal(response.ok,true);return response.json();
  }});
  const options={state,metadata,credential:async()=>credential,direct:details=>access.authorizeDirect(details),request:async()=>{throw Error('Full connected originals must not use bounded reconciliation.');}};
  const attempts=new RemoteAttempts(options);
  dashboard=createDashboard({origin:credential.serverOrigin,serverOrigin:credential.serverOrigin,remoteState:state,remoteAttempts:attempts,browse:()=>access.browse(),
    quitting:()=>quitting,quit:()=>{},offline:()=>{},offlineWindow:()=>BrowserWindow.getAllWindows()[0],printer:()=> 'Fixture-Only-Printer'});
  await dashboard.open();const window=BrowserWindow.getAllWindows().find(value=>value.webContents.getURL()===credential.serverOrigin+'/admin/dashboard');assert.ok(window);
  const invoke=source=>window.webContents.executeJavaScript(source);
  assert.equal(await invoke('typeof window.FasakhanstaDesktop.printReceipt'),'function');
  const original=await invoke(`fetch('/admin/users/20',{method:'POST',headers:{'Content-Type':'application/json','X-Fasakhansta-Connected-Device':'forged','X-Fasakhansta-Connected-Token':'forged','X-Fasakhansta-Remote-Capability':'forged'},body:JSON.stringify({name:'Original fixture name',password:'fixture-private-password',roles_name:['1']})}).then(response=>response.json())`);
  assert.equal(original.ok,true);assert.equal(fixtureFailure,undefined);
  // onCompleted performs an accepted durable write asynchronously after fetch.
  for(let count=0;(await state.read()).pending.length&&count<200;count++)await new Promise(resolve=>setTimeout(resolve,25));
  assert.deepEqual((await state.read()).pending,[]);
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
  const beforeOld=captures.length;
  for(const unsupported of [undefined,0,2,'1',null]){
    protocol=unsupported;
    for(const source of sources)await assert.rejects(invoke('window.FasakhanstaDesktop.printReceipt('+JSON.stringify(credential.serverOrigin+'/admin/order-board/'+source+'/20/print')+')'),/لم يؤكد حماية/);
  }
  assert.equal(captures.length,beforeOld);assert.equal(invocations,4);assert.deepEqual((await state.read()).pending,pending);assert.ok(certificateChecks>0);
  const audit=[];for(const name of writtenKeys)if(name.startsWith('remote-central'))audit.push(await store.read(name));
  assert.equal(JSON.stringify(audit).includes(credential.token),false);assert.equal(JSON.stringify(audit).includes('fixture-private-password'),false);
  const encrypted=await fs.readFile(path.join(root,'durable-audit','remote-central',pending[0]+'.enc'));assert.equal(encrypted.includes(Buffer.from(credential.token)),false);
  const repo=path.resolve(__dirname,'../..'),sourceRevision=execFileSync('git',['rev-parse','HEAD'],{cwd:repo,encoding:'utf8'}).trim();
  const canonical=value=>value.toString('utf8').replace(/\r\n/g,'\n');
  for(const file of ['dashboard.cjs','dashboard-connected.cjs','dashboard-remote-attempts.cjs'])assert.equal(canonical(await fs.readFile(path.join(sourceRoot,file))),canonical(execFileSync('git',['show',sourceRevision+':desktop-pos/src/'+file],{cwd:repo})));
  const result={format:1,sourceRevision,platform:process.platform,electron:process.versions.electron,electronVersion:process.versions.electron,completed:true,hardware:false,
    adapter:'intercepted Electron webContents.print',actualElectronSessionFetch:true,actualWebRequest:true,actualDpapi:true,pinnedLoopbackHttps:true,
    pairedProofOnWire:true,internalMarkerStripped:true,foreignProofStripped:true,nativeReplayBlocked:true,fragmentNormalized:true,oldProtocolBlocked:true,
    lostReplyRetained:true,unboundedOriginalWrite:true,printedSources:4,originalWrites:1,invocations:4,lostPrintRequests:1};
  const destination=path.resolve(process.argv[2]||path.join(__dirname,'../dist/connected-request-native.json'));
  await fs.mkdir(path.dirname(destination),{recursive:true});await fs.writeFile(destination,JSON.stringify(result,null,2)+'\n');
  process.stdout.write('PASS actual Windows Electron connected proof on the wire, native HTTPS print IPC, isolated DPAPI and fail-closed requests (printer adapter intercepted)\n');
}
run().then(()=>finish(0),error=>{process.stderr.write((fixtureFailure||error).stack+'\n');return finish(1);});
async function finish(code){
  clearTimeout(timer);quitting=true;for(const window of BrowserWindow.getAllWindows())window.destroy();
  for(const active of [server,foreignServer])if(active){active.closeAllConnections?.();await new Promise(resolve=>active.close(resolve));}
  await fs.rm(path.join(root,'durable-audit'),{recursive:true,force:true});app.exit(code);
}
