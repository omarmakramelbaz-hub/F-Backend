'use strict';
// Real Windows Electron UI, preload and IPC. Authentication is an explicit
// synthetic broker; original Laravel authorization is verified separately.
const {app,BrowserWindow,session}=require('electron');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const createLoginWindow=require('../src/login-window.cjs');
const {originalSignin,installCookies}=require('../src/account-login.cjs');
assert.equal(process.platform,'win32');assert.equal(process.type,'browser');assert.equal(process.argv.length,3);
const repository=path.resolve(__dirname,'../..'),receiptFile=path.resolve(process.argv[2]);
const locked=JSON.parse(fs.readFileSync(path.join(repository,'desktop-pos/package-lock.json'),'utf8'));
assert.equal(process.versions.electron,locked.packages['node_modules/electron'].version);
const sourceRevision=process.env.GITHUB_SHA||execFileSync('git',['rev-parse','HEAD'],{cwd:repository,encoding:'utf8'}).trim();
assert.match(sourceRevision,/^[a-f0-9]{40}$/);
const sourceFiles=['desktop-pos/src/account-login.cjs','desktop-pos/src/native-deadline.cjs','desktop-pos/src/login-window.cjs','desktop-pos/src/login-preload.cjs','desktop-pos/src/login.js',
  'desktop-pos/src/login.html','desktop-pos/src/login.css','desktop-pos/src/assets/go-erp.svg',
  'desktop-pos/test/account-login-window-native.cjs','desktop-pos/test/run-electron-native.cjs'];
const sha=file=>crypto.createHash('sha256').update(fs.readFileSync(path.join(repository,file))).digest('hex');
const sourceHashes=Object.fromEntries(sourceFiles.map(file=>[file,sha(file)]));
const root=fs.mkdtempSync(path.join(fs.realpathSync.native(os.tmpdir()),'go-erp-login-ui-'));
app.setPath('userData',path.join(root,'chromium-profile'));app.disableHardwareAcceleration();
app.on('window-all-closed',()=>{}); // The fixture has three sequential UI phases.
let failed=false,complete=false,quitting=false,proof,active,authServer,foreignServer;
const deadline=setTimeout(()=>{failed=true;process.stderr.write('Actual login UI exceeded its native deadline.\n');app.exit(1);},60000);
const waitFor=async(predicate,message)=>{const until=Date.now()+10000;while(Date.now()<until){const value=await predicate();if(value)return value;await new Promise(resolve=>setTimeout(resolve,20));}throw Error(message);};
const loginURL=require('node:url').pathToFileURL(path.resolve(__dirname,'../src/login.html')).href;
async function actualWindow(){
  const window=await waitFor(()=>BrowserWindow.getAllWindows().find(value=>value.webContents.getURL()===loginURL),'The actual login document did not load.');
  await waitFor(()=>window.isVisible(),'The actual login form never became visible.');
  await waitFor(()=>window.webContents.executeJavaScript('document.readyState === "complete" && typeof window.GOERPLogin?.authenticate === "function"',true),'The original preload or document did not settle.');
  const preferences=window.webContents.getLastWebPreferences();
  assert.equal(preferences.sandbox,true);assert.equal(preferences.contextIsolation,true);assert.equal(preferences.nodeIntegration,false);assert.equal(preferences.webSecurity,true);
  return window;
}
function broker(){
  let resolve;const value={calls:0,cancels:0,editing:0,onState:()=>{},resumeRemembered:async()=>null,userEditing(){value.editing++;},
    authenticate(input){value.calls++;value.input=input;return new Promise(yes=>{resolve=yes;});},
    cancel(){value.cancels++;},release(result){resolve(result);}};
  return value;
}
async function actualOriginalSessionClient(){
  const token='f'.repeat(64),csrf='a'.repeat(40),password='synthetic-original-client-only';let origin,foreignOrigin,serverFailure,foreignWire=0,repeatedPosts=0;
  const requests={logins:0,signins:0,dashboards:0};
  foreignServer=require('node:http').createServer((_request,response)=>{foreignWire++;response.end('A foreign authentication redirect must never arrive.');});
  await new Promise(resolve=>foreignServer.listen(0,'127.0.0.1',resolve));foreignOrigin='http://127.0.0.1:'+foreignServer.address().port;
  authServer=require('node:http').createServer(async(request,response)=>{
    try{
      assert.equal(request.headers['x-fasakhansta-desktop'],token);
      response.setHeader('Content-Type','text/html; charset=utf-8');response.setHeader('Cache-Control','no-store');
      if(request.method==='GET'&&request.url==='/admin/login'){
        requests.logins++;response.setHeader('Set-Cookie','native_session=synthetic-before-signin; Path=/; HttpOnly; SameSite=Lax');
        response.end('<body><form method="POST" action="'+origin+'/admin/signin" data-dashboard-login><input name="_token" value="'+csrf+'"></form></body>');return;
      }
      if(request.method==='POST'&&request.url==='/admin/signin'){
        requests.signins++;assert.equal(request.headers.cookie,'native_session=synthetic-before-signin');
        assert.equal(request.headers.origin,origin);assert.equal(request.headers.referer,origin+'/admin/login');assert.equal(request.headers['x-csrf-token'],csrf);
        const chunks=[];let size=0;for await(const chunk of request){size+=chunk.length;assert.ok(size<8192);chunks.push(chunk);}
        const input=new URLSearchParams(Buffer.concat(chunks).toString());assert.equal(input.get('_token'),csrf);assert.equal(input.get('email'),'native.owner@example.test');
        if(input.get('password')==='foreign-redirect-only'){response.writeHead(302,{Location:foreignOrigin+'/collect'});response.end();return;}
        if(input.get('password')==='repeat-post-redirect-only'){repeatedPosts++;response.writeHead(307,{Location:origin+'/admin/signin'});response.end();return;}
        if(input.get('password')!==password){response.writeHead(302,{Location:origin+'/admin/login'});response.end();return;}
        response.setHeader('Set-Cookie','native_session=synthetic-authenticated-session; Path=/; HttpOnly; SameSite=Lax');
        response.writeHead(302,{Location:origin+'/admin/dashboard'});response.end();return;
      }
      assert.equal(request.method,'GET');assert.equal(request.url,'/admin/dashboard');requests.dashboards++;
      assert.equal(request.headers.cookie,'native_session=synthetic-authenticated-session');response.end('<body data-dashboard-actor="42:admin">Original client authenticated fixture</body>');
    }catch(error){serverFailure=error;response.statusCode=500;response.end('Synthetic session client rejected its contract.');}
  });
  await new Promise(resolve=>authServer.listen(0,'127.0.0.1',resolve));origin='http://127.0.0.1:'+authServer.address().port;
  try{
    const browser=session.fromPartition('native-auth-client-'+crypto.randomUUID()),target=session.fromPartition('native-auth-target-'+crypto.randomUUID());
    const actor=await originalSignin({browser,origin,localToken:token,email:'native.owner@example.test',password,remember:false,signal:AbortSignal.timeout(10000)});
    assert.deepEqual(actor,{actorId:42,role:'admin'});assert.equal(serverFailure,undefined);
    await installCookies(browser,target,origin,true);
    const home=await target.fetch(origin+'/admin/dashboard',{credentials:'include',redirect:'error',headers:{'X-Fasakhansta-Desktop':token}});
    assert.equal(home.status,200);assert.match(await home.text(),/data-dashboard-actor="42:admin"/);
    await assert.rejects(originalSignin({browser:session.fromPartition('native-auth-rejected-'+crypto.randomUUID()),origin,localToken:token,email:'native.owner@example.test',password:'wrong-synthetic-only',remember:false,signal:AbortSignal.timeout(10000)}),error=>error.code==='ACCOUNT_CREDENTIALS_REJECTED');
    assert.equal(serverFailure,undefined);assert.deepEqual(requests,{logins:3,signins:2,dashboards:2});
    for(const candidate of ['foreign-redirect-only','repeat-post-redirect-only']){
      await assert.rejects(originalSignin({browser:session.fromPartition('native-auth-blocked-'+crypto.randomUUID()),origin,localToken:token,email:'native.owner@example.test',password:candidate,remember:false,signal:AbortSignal.timeout(10000)}));
    }
    assert.equal(serverFailure,undefined);assert.equal(foreignWire,0);assert.equal(repeatedPosts,1);assert.deepEqual(requests,{logins:5,signins:4,dashboards:2});
    return {actualOriginalSessionAuthClient:true,actualSessionCookieJar:true,originalCsrfHeadersObserved:true,guardedRedirectCookiesPreserved:true,authOnlyRedirectAdmission:true,foreignRedirectNotTransmitted:true,repeatedPostRedirectBlocked:true,actualCookieHandoff:true,wrongPasswordRejected:true};
  }catch(error){throw serverFailure||error;}finally{authServer.closeAllConnections?.();await new Promise(resolve=>authServer.close(resolve));authServer=null;foreignServer.closeAllConnections?.();await new Promise(resolve=>foreignServer.close(resolve));foreignServer=null;}
}
app.once('will-quit',()=>{
  clearTimeout(deadline);if(failed)return;
  try{
    assert.equal(complete,true);assert.equal(BrowserWindow.getAllWindows().length,0);
    for(const [file,expected]of Object.entries(sourceHashes))assert.equal(sha(file),expected);
    fs.mkdirSync(path.dirname(receiptFile),{recursive:true});
    fs.writeFileSync(receiptFile,JSON.stringify({...proof,naturalQuit:true,forcedSuccessExit:false,completed:true},null,2)+'\n',{flag:'wx'});
    process.stdout.write('ACCOUNT_LOGIN_WINDOW_NATIVE_COMPLETE '+JSON.stringify({sourceRevision,actualElectron:true,actualIpc:true,naturalQuit:true,completed:true})+'\n');
  }catch(error){process.exitCode=1;process.stderr.write(error.stack+'\n');}
});
app.whenReady().then(async()=>{
  const originalClient=await actualOriginalSessionClient();
  let releaseRemembered;const ordinary=broker();ordinary.resumeRemembered=()=>new Promise(resolve=>{releaseRemembered=resolve;});active=createLoginWindow({broker:ordinary,quitting:()=>quitting});
  const window=await actualWindow();
  const layout=await window.webContents.executeJavaScript(`(() => {
    const visible=id=>{const r=document.getElementById(id).getBoundingClientRect();return r.width>0&&r.height>0&&r.top>=0&&r.bottom<=innerHeight;};
    return {forms:document.forms.length,buttons:Array.from(document.querySelectorAll('button')).map(value=>value.id),
      visible:['email','password','show-password','remember','submit'].every(visible),
      noNode:typeof require==='undefined'&&typeof process==='undefined',bridge:Object.keys(window.GOERPLogin).sort(),
      passwordEmpty:document.getElementById('password').value==='',bodyText:document.body.textContent};
  })()`,true);
  assert.equal(layout.forms,1);assert.deepEqual(layout.buttons,['show-password','submit']);assert.equal(layout.visible,true);assert.equal(layout.noNode,true);
  assert.deepEqual(layout.bridge,['authenticate','onState','userEditing']);assert.equal(layout.passwordEmpty,true);assert.equal(/تبديل الحساب|اختيار حساب|جاري فتح الداشبورد|جارٍ فتح الداشبورد/.test(layout.bodyText),false);
  await window.webContents.executeJavaScript('document.fonts.ready.then(()=>true)',true);
  const capture=await window.webContents.capturePage(),size=capture.getSize();assert.ok(size.width>=1000&&size.height>=650);
  const captureFile=receiptFile.replace(/\.json$/,'')+'-login.png';fs.mkdirSync(path.dirname(captureFile),{recursive:true});fs.writeFileSync(captureFile,capture.toPNG());
  let passiveAccepted=false;active.result.then(()=>passiveAccepted=true,()=>{});
  await waitFor(()=>typeof releaseRemembered==='function','The passive remembered-account fixture did not start.');
  await window.webContents.executeJavaScript(`(() => {document.getElementById('email').value='native.branch@example.test';document.getElementById('email').dispatchEvent(new Event('input',{bubbles:true}));return window.GOERPLogin.userEditing();})()`,true);
  assert.equal(ordinary.editing,1);
  releaseRemembered({account:{id:'passive-owner-must-not-override-editing'},runtime:{token:'PRIVATE-PASSIVE-RESULT'}});
  await new Promise(resolve=>setTimeout(resolve,30));assert.equal(passiveAccepted,false);assert.equal(window.isVisible(),true);
  const syntheticPassword='NATIVE-FIXTURE-ONLY-NO-REAL-PASSWORD';
  const submitted=await window.webContents.executeJavaScript(`(() => {
    document.getElementById('email').value='native.owner@example.test';document.getElementById('password').value=${JSON.stringify(syntheticPassword)};
    document.getElementById('show-password').click();const visible=document.getElementById('password').type==='text';
    document.getElementById('remember').checked=true;document.getElementById('login').requestSubmit();
    return {visible,cleared:document.getElementById('password').value==='',masked:document.getElementById('password').type==='password',disabled:document.getElementById('submit').disabled};
  })()`,true);
  assert.deepEqual(submitted,{visible:true,cleared:true,masked:true,disabled:true});
  await waitFor(()=>ordinary.calls===1,'Actual form submission did not reach the owned native IPC handler.');
  assert.equal(ordinary.input.email,'native.owner@example.test');assert.equal(ordinary.input.password,syntheticPassword);assert.equal(ordinary.input.remember,true);
  ordinary.onState({phase:'authenticating'});
  assert.equal(await window.webContents.executeJavaScript('document.getElementById("submit").disabled && document.getElementById("password").value === ""',true),true);
  const mainResult={account:{id:'synthetic-account'},runtime:{token:'PRIVATE-RESULT-MUST-STAY-MAIN'},localToken:'PRIVATE-NATIVE-CAPABILITY'};
  ordinary.release(mainResult);assert.equal(await active.result,mainResult);
  await waitFor(()=>window.webContents.executeJavaScript('document.getElementById("status").textContent.includes("تم تسجيل الدخول")',true),'The renderer did not receive its bounded success response.');
  assert.equal(ordinary.input.password,'','The main IPC input must be cleared when authentication settles.');
  const settled=await window.webContents.executeJavaScript(`({disabled:document.getElementById('submit').disabled,password:document.getElementById('password').value,
    privateVisible:document.documentElement.outerHTML.includes('PRIVATE-RESULT-MUST-STAY-MAIN')||document.documentElement.outerHTML.includes('PRIVATE-NATIVE-CAPABILITY'),
    storedPassword:Object.values(localStorage).some(value=>value.includes(${JSON.stringify(syntheticPassword)}))})`,true);
  assert.deepEqual(settled,{disabled:true,password:'',privateVisible:false,storedPassword:false});assert.equal(window.isVisible(),true);
  active.dispose();assert.equal(window.isDestroyed(),true);

  // A second actual owned IPC call makes the return envelope observable. The
  // full account/runtime result belongs to main; only {ok:true} reaches renderer.
  const direct=broker();active=createLoginWindow({broker:direct,quitting:()=>quitting});const directWindow=await actualWindow();
  const rendererResult=directWindow.webContents.executeJavaScript('window.GOERPLogin.authenticate({email:"native.owner@example.test",password:"synthetic-direct-only",remember:false})',true);
  await waitFor(()=>direct.calls===1,'The second actual IPC request did not arrive.');direct.release(mainResult);
  assert.equal(await active.result,mainResult);assert.deepEqual(await rendererResult,{ok:true});active.dispose();assert.equal(directWindow.isDestroyed(),true);

  const cancelled=broker();let quitRequests=0;active=createLoginWindow({broker:cancelled,quitting:()=>quitting,quit:()=>quitRequests++});
  const cancelledWindow=await actualWindow(),cancelledResult=active.result;
  cancelledWindow.close();await assert.rejects(cancelledResult,error=>error.code==='ACCOUNT_LOGIN_CANCELLED');
  assert.equal(cancelled.cancels,1);assert.equal(quitRequests,1);assert.equal(cancelledWindow.isDestroyed(),true);assert.equal(cancelled.calls,0);
  proof={format:1,kind:'go-erp-login-window-native',sourceRevision,sourceHashes,electronVersion:process.versions.electron,platform:process.platform,
    actualElectron:true,actualBrowserWindow:true,actualPreload:true,actualIpc:true,sandbox:true,trustedFile:true,
    singleOrdinaryForm:true,passwordClearedBeforeAuthenticationSettles:true,passwordNeverPersisted:true,rendererReceivesOnlyBoundedEnvelope:true,
    fullResultStaysInMain:true,formRetainedDisabledUntilHandoff:true,editingPreventsRememberedOverride:true,actualNativeCloseCancels:true,quitCallbackRequested:true,
    capture:{width:size.width,height:size.height,sha256:crypto.createHash('sha256').update(fs.readFileSync(captureFile)).digest('hex')},
    ...originalClient,syntheticHttpAuthServer:true,syntheticBroker:true,quitCallbackFixture:true,actualPhpAuthorization:false,actualProductionMain:false,productionProfile:false,hardware:false};
  complete=true;quitting=true;app.quit();
}).catch(error=>{failed=true;process.exitCode=1;clearTimeout(deadline);process.stderr.write(error.stack+'\n');try{active?.cancel();}catch{}authServer?.closeAllConnections?.();authServer?.close();foreignServer?.closeAllConnections?.();foreignServer?.close();for(const window of BrowserWindow.getAllWindows())window.destroy();app.quit();});
