'use strict';
// Actual Windows Electron cookie persistence and the production logout gate.
// The HTTP account is synthetic; real Laravel recaller checks run separately.
const {app,BrowserWindow,safeStorage}=require('electron');
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const Shutdown=require('../src/shutdown.cjs'),createDashboard=require('../src/dashboard.cjs');
const {GenerationStore}=require('../src/dashboard-generation.cjs'),{AccountLogin}=require('../src/account-login.cjs');
const signout=require('../src/account-signout.cjs');
assert.equal(process.platform,'win32','Remember-session acceptance requires actual Windows.');
assert.equal(process.argv.length,6);
const [scenario,phase,profile,receiptFile]=process.argv.slice(2);
assert.ok(['remember','plain','invalid','window-close'].includes(scenario));assert.ok(['close','reopen'].includes(phase));
assert.ok(path.isAbsolute(profile)&&path.isAbsolute(receiptFile));
assert.equal(fs.readFileSync(path.join(profile,'remember-session-fixture.marker'),'utf8'),'GO_ERP_SYNTHETIC_REMEMBER_ONLY\n');
const locked=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../package-lock.json'),'utf8')).packages['node_modules/electron'].version;
assert.equal(process.versions.electron,locked);
const sourceRevision=process.env.GITHUB_SHA||execFileSync('git',['rev-parse','HEAD'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8'}).trim();
assert.match(sourceRevision,/^[a-f0-9]{40}$/);
const sha=file=>crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const spaFile=path.join(__dirname,'../../public/dashboard/js/dashboard-spa.js'),navbarFile=path.join(__dirname,'../../resources/views/admin/layouts/navbar.blade.php');
const spaSource=fs.readFileSync(spaFile),navbar=fs.readFileSync(navbarFile,'utf8');
const originalLogoutAnchor=navbar.match(/<a\b[^>]*href="\{\{\s*url\('\/admin\/adminLogout'\)\s*\}\}"[^>]*>[\s\S]*?<\/a>/)?.[0];
assert.ok(originalLogoutAnchor,'The fixture must use the original navbar logout anchor.');assert.equal(/data-spa-off/.test(originalLogoutAnchor),false);
const logoutAnchor=originalLogoutAnchor.replace(/\{\{\s*url\('\/admin\/adminLogout'\)\s*\}\}/,'/admin/adminLogout').replace(/@lang\('main.logout'\)/,'خروج');
const sourceHashes={dashboard:sha(path.join(__dirname,'../src/dashboard.cjs')),shutdown:sha(path.join(__dirname,'../src/shutdown.cjs')),
  fixture:sha(__filename),spa:sha(spaFile),navbar:sha(navbarFile),accountLogin:sha(path.join(__dirname,'../src/account-login.cjs')),
  signout:sha(path.join(__dirname,'../src/account-signout.cjs')),generation:sha(path.join(__dirname,'../src/dashboard-generation.cjs'))};
const recaller='remember_admin_'+crypto.createHash('sha1').update('Illuminate\\Auth\\SessionGuard').digest('hex');
const validRemember='synthetic-signed-recaller-'+crypto.createHmac('sha256','disposable-fixture-key').update('account-42').digest('hex');
const selectedRemember=scenario==='invalid'?'invalid-synthetic-recaller':validRemember;
app.setName('GO ERP remembered-session native fixture');app.setPath('userData',profile);
assert.equal(app.requestSingleInstanceLock(),true);
let server,dashboard,window,quitting=false,failed=false,validating=false,logoutRequests=0,posts=0,requests=0,drained=false;
let scopedSessionEnded=false,recallerPreserved=false,cookieFlagsChecked=false,automaticSameAccount=false,invalidRejected=false,anonymousAfterReopen=false;
let spaSourceLoaded=false,productionSpaReady=false,originalNavbarLogout=false,ownedMainFrameFetch=false,ordinaryLogoutResponse=false,logoutWindowHidden=false;
let spaLogoutRequests=0,mainFrameLogoutRequests=0,originalLogoutId=null;
let explicitLogoutRequiresPassword=false,encryptedSignoutSurvivesReopen=false,lateCookieCannotResume=false,windowCloseKeepsRemembered=false;
const explicit=scenario!=='window-close',runtime={metadata:new GenerationStore(path.join(profile,'dashboard'),safeStorage)};
const markerFile=path.join(profile,'dashboard','explicit-signout.enc');
const fail=error=>{
  failed=true;process.stderr.write(error.stack+'\n');
  // Failure cleanup may exit; no successful receipt can use this path.
  app.exit(1);
};
const watchdog=setTimeout(()=>fail(Error('Native remembered-session fixture timed out.')),30000);
const shutdown=new Shutdown(app,()=>{quitting=true;server?.close();},()=>{},async()=>{drained=true;});
app.once('will-quit',()=>{
  clearTimeout(watchdog);if(failed)return;
  try{
    assert.equal(drained,true);assert.equal(BrowserWindow.getAllWindows().length,0);
    fs.writeFileSync(receiptFile,JSON.stringify({format:1,kind:'go-erp-remember-session-phase',scenario,phase,sourceRevision,sourceHashes,
      electronVersion:process.versions.electron,platform:process.platform,pid:process.pid,actualElectron:true,
      hardware:false,productionProfile:false,syntheticServer:true,primaryInstance:true,requests,posts,logoutRequests,
      cookieFlagsChecked,scopedSessionEnded,recallerPreserved,automaticSameAccount,invalidRejected,anonymousAfterReopen,
      spaSourceLoaded,productionSpaReady,originalNavbarLogout,ownedMainFrameFetch,ordinaryLogoutResponse,logoutWindowHidden,spaLogoutRequests,mainFrameLogoutRequests,
      explicitLogoutRequiresPassword,encryptedSignoutSurvivesReopen,lateCookieCannotResume,windowCloseKeepsRemembered,
      naturalQuit:true,forcedSuccessExit:false,completed:true})+'\n',{flag:'wx'});
  }catch(error){process.exitCode=1;process.stderr.write(error.stack+'\n');}
});
async function requestQuit(){
  if(quitting||validating)return;validating=true;
  try{
    if(phase==='close'&&explicit){
      assert.equal(window.isVisible(),false,'Intentional SPA logout must disappear before accepted shutdown work drains.');
      assert.equal(BrowserWindow.getAllWindows().every(target=>!target.isVisible()),true);logoutWindowHidden=true;
      assert.equal(productionSpaReady,true);assert.equal(originalNavbarLogout,true);assert.equal(ownedMainFrameFetch,true);
      assert.equal(spaLogoutRequests,1);assert.equal(mainFrameLogoutRequests,0);
      assert.equal(ordinaryLogoutResponse,true);
      const cookies=await dashboard.session().cookies.get({url:server.origin+'/admin/dashboard'});
      assert.equal(cookies.some(cookie=>cookie.name==='fixture_session'),false);
      scopedSessionEnded=true;
      const remembered=cookies.find(cookie=>cookie.name===recaller);
      assert.equal(remembered,undefined);
      assert.equal(await signout.requirePassword(runtime),true);explicitLogoutRequiresPassword=true;
      assert.equal(fs.readFileSync(markerFile).includes(Buffer.from('go-erp-explicit-signout')),false,'The sign-out marker must use real Windows DPAPI.');
      const central=await dashboard.session().cookies.get({url:'https://fixture.invalid/admin/dashboard'});
      assert.equal(central.some(cookie=>cookie.name==='fixture_session'||cookie.name===recaller),false,'Explicit logout must clear the paired HTTPS authentication too.');
      assert.equal(cookies.find(cookie=>cookie.name==='unrelated-persistent').value,'synthetic-preference');
      assert.equal((await dashboard.session().cookies.get({url:'http://other.invalid/'})).find(cookie=>cookie.name==='foreign_session').value,'synthetic-foreign');
      assert.equal(logoutRequests,1);
    }else if(phase==='close'){
      assert.equal(logoutRequests,0);assert.equal(spaLogoutRequests,0);
      assert.equal(await signout.requirePassword(runtime),false,'Ordinary window close must not record explicit logout.');
      const remembered=(await dashboard.session().cookies.get({url:server.origin})).find(cookie=>cookie.name===recaller);
      assert.ok(remembered);assert.equal(remembered.value,validRemember);assert.equal(remembered.httpOnly,true);assert.equal(remembered.session,false);
      recallerPreserved=true;windowCloseKeepsRemembered=true;
    }
    assert.equal(posts,0);app.quit();
  }catch(error){fail(error);}
}
async function run(){
  await app.whenReady();
  server=http.createServer((request,response)=>{
    try{
      requests++;assert.equal(request.headers['x-fasakhansta-desktop'],'synthetic-remember-native-header');
      if(request.method!=='GET'){posts++;throw Error('The native fixture must never submit credentials or replay a POST.');}
      const pathname=new URL(request.url,'http://127.0.0.1').pathname;
      if(pathname==='/dashboard/js/dashboard-spa.js'){
        spaSourceLoaded=true;response.writeHead(200,{'Content-Type':'application/javascript','Cache-Control':'no-store'});response.end(spaSource);return;
      }
      const cookies=Object.fromEntries(String(request.headers.cookie||'').split(/;\s*/).filter(Boolean).map(item=>{const at=item.indexOf('=');return [item.slice(0,at),item.slice(at+1)];}));
      if(pathname==='/admin/adminLogout'){
        assert.equal(explicit,true);assert.equal(cookies.fixture_session,'synthetic-current-session','The original logout controller must receive its authenticated session.');
        if(scenario!=='plain')assert.equal(cookies[recaller],selectedRemember);
        assert.equal(fs.existsSync(markerFile),true,'Explicit sign-out must be durable before transmitting original logout.');
        assert.equal(JSON.parse(safeStorage.decryptString(fs.readFileSync(markerFile))).requirePassword,true);
        logoutRequests++;response.writeHead(302,{'Location':'/admin/login','Set-Cookie':['fixture_session=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax',recaller+'=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax']});response.end();return;
      }
      // The X-close reopen must prove its persistent recaller, independently
      // of Chromium's optional restoration of an old session cookie.
      const authenticated=(!(scenario==='window-close'&&phase==='reopen')&&cookies.fixture_session==='synthetic-current-session')||cookies[recaller]===validRemember;
      if(pathname==='/admin/dashboard'&&!authenticated){response.writeHead(302,{'Location':'/admin/login'});response.end();return;}
      response.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'private, no-store'});
      response.end('<!doctype html><html dir="rtl"><head><meta charset="utf-8"><script src="/dashboard/js/dashboard-spa.js"></script></head>'
        +'<body data-account="'+(authenticated?'account-42':'anonymous')+'"><nav class="main-header">'+logoutAnchor+'</nav>'
        +'<main data-dashboard-page><h1>اختبار الداشبورد</h1></main></body></html>');
    }catch(error){response.writeHead(500);response.end();fail(error);}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));server.origin='http://127.0.0.1:'+server.address().port;
  dashboard=createDashboard({origin:server.origin,serverOrigin:'https://fixture.invalid',localToken:'synthetic-remember-native-header',
    beforeLogout:()=>signout.signedOut(runtime),
    offline:()=>{throw Error('Synthetic native dashboard must stay local.');},offlineWindow:()=>window,quitting:()=>quitting,
    quit:()=>{requestQuit().catch(fail);},printer:()=>'',accepted:work=>shutdown.run(work)});
  // The production partition is inspected through the actual native session.
  const {session}=require('electron'),browser=session.fromPartition('persist:fasakhansta-dashboard');
  for(const name of ['onBeforeSendHeaders','onCompleted']){
    const register=browser.webRequest[name].bind(browser.webRequest);
    browser.webRequest[name]=(filter,handler)=>register(filter,(details,...rest)=>{
      try{
        if(name==='onBeforeSendHeaders'&&new URL(details.url).pathname==='/admin/adminLogout'){
          if(details.resourceType==='mainFrame')mainFrameLogoutRequests++;
          assert.equal(details.resourceType,'xhr','The original navbar must use the real production SPA fetch.');
          assert.equal(details.webContentsId,window.webContents.id);assert.equal(details.frame,window.webContents.mainFrame);
          assert.equal(new URL(details.frame.url).origin,server.origin);
          assert.equal(Object.entries(details.requestHeaders).find(([name])=>name.toLowerCase()==='x-dashboard-spa')?.[1],'1');
          spaLogoutRequests++;ownedMainFrameFetch=true;originalLogoutId=details.id;
        }
        if(name==='onCompleted'&&details.id===originalLogoutId){
          assert.equal(details.webContentsId,window.webContents.id);assert.equal(new URL(details.url).origin,server.origin);
          assert.equal(new URL(details.url).pathname,'/admin/login');assert.equal(details.statusCode,200);ordinaryLogoutResponse=true;
        }
        return handler(details,...rest);
      }catch(error){if(name==='onBeforeSendHeaders')rest[0]({cancel:true});fail(error);}
    });
  }
  if(phase==='close'){
    await browser.cookies.set({url:server.origin,name:'fixture_session',value:'synthetic-current-session',path:'/',httpOnly:true,sameSite:'lax'});
    await browser.cookies.set({url:server.origin,name:'unrelated-persistent',value:'synthetic-preference',path:'/',expirationDate:Date.now()/1000+86400,sameSite:'lax'});
    await browser.cookies.set({url:'http://other.invalid',name:'foreign_session',value:'synthetic-foreign',path:'/',httpOnly:true,expirationDate:Date.now()/1000+86400,sameSite:'lax'});
    if(scenario!=='plain')await browser.cookies.set({url:server.origin,name:recaller,value:selectedRemember,path:'/',httpOnly:true,expirationDate:Date.now()/1000+86400,sameSite:'lax'});
    await browser.cookies.set({url:'https://fixture.invalid',name:'fixture_session',value:'synthetic-central-session',path:'/',httpOnly:true,secure:true,expirationDate:Date.now()/1000+86400,sameSite:'lax'});
    await browser.cookies.set({url:'https://fixture.invalid',name:recaller,value:validRemember,path:'/',httpOnly:true,secure:true,expirationDate:Date.now()/1000+86400,sameSite:'lax'});
    await browser.cookies.flushStore();
    const initial=await browser.cookies.get({url:server.origin+'/admin/dashboard'}),active=initial.find(cookie=>cookie.name==='fixture_session');
    assert.equal(active.httpOnly,true);assert.equal(active.session,true);
    if(scenario!=='plain'){const cookie=initial.find(value=>value.name===recaller);assert.equal(cookie.httpOnly,true);assert.equal(cookie.session,false);assert.equal(cookie.value,selectedRemember);}
    cookieFlagsChecked=true;
  }else{
    const retained=await browser.cookies.get({url:server.origin+'/admin/dashboard'});
    const remembered=retained.find(cookie=>cookie.name===recaller);
    if(explicit){
      assert.equal(retained.some(cookie=>cookie.name==='fixture_session'),false,'The previous active session must not survive explicit logout.');
      assert.equal(remembered,undefined);scopedSessionEnded=true;
      assert.equal(await signout.requirePassword(runtime),true);explicitLogoutRequiresPassword=true;encryptedSignoutSurvivesReopen=true;
      // Simulate a late Set-Cookie after accepted work drains. The actual
      // broker must stop at the durable sign-out marker before touching auth.
      await browser.cookies.set({url:server.origin,name:recaller,value:validRemember,path:'/',httpOnly:true,expirationDate:Date.now()/1000+86400,sameSite:'lax'});
      const account={id:'default',actorId:42,serverOrigin:'https://fixture.invalid',partition:'persist:fasakhansta-dashboard',profile};
      let preparedChecks=0,sessionReads=0;
      const broker=new AccountLogin({profiles:{selected:async()=>account,account:async()=>account},serverOrigin:account.serverOrigin,
        createRuntime:()=>({...runtime,isPrepared:async()=>{preparedChecks++;throw Error('Explicit logout must not boot a remembered runtime.');},stop:async()=>{}}),
        createSession:()=>{sessionReads++;return browser;}});
      const before=requests;assert.equal(await broker.resumeRemembered(),null);assert.equal(preparedChecks,0);assert.equal(sessionReads,0);assert.equal(requests,before);
      await broker.stop();lateCookieCannotResume=true;
      await browser.cookies.remove(server.origin+'/',recaller);
      // The invalid scenario still independently proves that the synthetic
      // original HTTP guard does not authenticate an invalid recaller.
      if(scenario==='invalid')await browser.cookies.set({url:server.origin,name:recaller,value:selectedRemember,path:'/',httpOnly:true,expirationDate:Date.now()/1000+86400,sameSite:'lax'});
    }else{
      assert.ok(remembered);assert.equal(remembered.value,validRemember);assert.equal(remembered.httpOnly,true);assert.equal(remembered.session,false);recallerPreserved=true;
      assert.equal(await signout.requirePassword(runtime),false);windowCloseKeepsRemembered=true;
    }
    cookieFlagsChecked=true;
  }
  await dashboard.open();window=BrowserWindow.getAllWindows()[0];assert.ok(window&&window.isVisible());
  const account=await window.webContents.executeJavaScript('document.body.dataset.account',true);
  assert.equal(await window.webContents.executeJavaScript('window.DashboardSPA.ready() && document.documentElement.dataset.dashboardSpa === "ready"',true),true);
  assert.equal(spaSourceLoaded,true);productionSpaReady=true;
  if(phase==='close'){
    assert.equal(account,'account-42');
    if(explicit){
      assert.equal(await window.webContents.executeJavaScript('document.querySelector(\'.main-header a[href="/admin/adminLogout"]\').hasAttribute("data-spa-off")',true),false);
      originalNavbarLogout=true;
      await window.webContents.executeJavaScript('document.querySelector(\'.main-header a[href="/admin/adminLogout"]\').click();true',true);
    }else window.close();
  }else{
    if(scenario==='window-close'){assert.equal(account,'account-42');automaticSameAccount=true;windowCloseKeepsRemembered=true;}
    else{assert.equal(account,'anonymous');anonymousAfterReopen=true;if(scenario==='invalid')invalidRejected=true;}
    await requestQuit();
  }
}
run().catch(fail);
