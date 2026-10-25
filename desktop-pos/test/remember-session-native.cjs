'use strict';
// Actual Windows Electron cookie persistence and the production logout gate.
// The HTTP account is synthetic; real Laravel recaller checks run separately.
const {app,BrowserWindow}=require('electron');
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const Shutdown=require('../src/shutdown.cjs'),createDashboard=require('../src/dashboard.cjs');
assert.equal(process.platform,'win32','Remember-session acceptance requires actual Windows.');
assert.equal(process.argv.length,6);
const [scenario,phase,profile,receiptFile]=process.argv.slice(2);
assert.ok(['remember','plain','invalid'].includes(scenario));assert.ok(['close','reopen'].includes(phase));
assert.ok(path.isAbsolute(profile)&&path.isAbsolute(receiptFile));
assert.equal(fs.readFileSync(path.join(profile,'remember-session-fixture.marker'),'utf8'),'GO_ERP_SYNTHETIC_REMEMBER_ONLY\n');
const locked=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../package-lock.json'),'utf8')).packages['node_modules/electron'].version;
assert.equal(process.versions.electron,locked);
const sourceRevision=process.env.GITHUB_SHA||execFileSync('git',['rev-parse','HEAD'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8'}).trim();
assert.match(sourceRevision,/^[a-f0-9]{40}$/);
const sha=file=>crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const sourceHashes={dashboard:sha(path.join(__dirname,'../src/dashboard.cjs')),shutdown:sha(path.join(__dirname,'../src/shutdown.cjs')),fixture:sha(__filename)};
const recaller='remember_admin_'+crypto.createHash('sha1').update('Illuminate\\Auth\\SessionGuard').digest('hex');
const validRemember='synthetic-signed-recaller-'+crypto.createHmac('sha256','disposable-fixture-key').update('account-42').digest('hex');
const selectedRemember=scenario==='invalid'?'invalid-synthetic-recaller':validRemember;
app.setName('GO ERP remembered-session native fixture');app.setPath('userData',profile);
assert.equal(app.requestSingleInstanceLock(),true);
let server,dashboard,window,quitting=false,failed=false,validating=false,logoutRequests=0,posts=0,requests=0,drained=false;
let scopedSessionEnded=false,recallerPreserved=false,cookieFlagsChecked=false,automaticSameAccount=false,invalidRejected=false,anonymousAfterReopen=false;
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
      naturalQuit:true,forcedSuccessExit:false,completed:true})+'\n',{flag:'wx'});
  }catch(error){process.exitCode=1;process.stderr.write(error.stack+'\n');}
});
async function requestQuit(){
  if(quitting||validating)return;validating=true;
  try{
    if(phase==='close'){
      const cookies=await dashboard.session().cookies.get({url:server.origin+'/admin/dashboard'});
      assert.equal(cookies.some(cookie=>cookie.name==='fixture_session'),false);
      scopedSessionEnded=true;
      const remembered=cookies.find(cookie=>cookie.name===recaller);
      if(scenario==='plain')assert.equal(remembered,undefined);
      else{
        assert.ok(remembered);assert.equal(remembered.value,selectedRemember);assert.equal(remembered.httpOnly,true);
        assert.equal(remembered.session,false);assert.ok(remembered.expirationDate>Date.now()/1000);recallerPreserved=true;
      }
      assert.equal(cookies.find(cookie=>cookie.name==='unrelated-persistent').value,'synthetic-preference');
      assert.equal((await dashboard.session().cookies.get({url:'http://other.invalid/'})).find(cookie=>cookie.name==='foreign_session').value,'synthetic-foreign');
      assert.equal(logoutRequests,scenario==='plain'?1:0);
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
      const cookies=Object.fromEntries(String(request.headers.cookie||'').split(/;\s*/).filter(Boolean).map(item=>{const at=item.indexOf('=');return [item.slice(0,at),item.slice(at+1)];}));
      if(pathname==='/admin/adminLogout'){
        logoutRequests++;response.writeHead(302,{'Location':'/admin/login','Set-Cookie':['fixture_session=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax',recaller+'=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax']});response.end();return;
      }
      const authenticated=cookies.fixture_session==='synthetic-current-session'||cookies[recaller]===validRemember;
      if(pathname==='/admin/dashboard'&&!authenticated){response.writeHead(302,{'Location':'/admin/login'});response.end();return;}
      response.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'private, no-store'});
      response.end('<!doctype html><html><head><meta charset="utf-8"></head><body data-account="'+(authenticated?'account-42':'anonymous')+'"><a id="logout" href="/admin/adminLogout">Close</a></body></html>');
    }catch(error){response.writeHead(500);response.end();fail(error);}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));server.origin='http://127.0.0.1:'+server.address().port;
  dashboard=createDashboard({origin:server.origin,serverOrigin:'https://fixture.invalid',localToken:'synthetic-remember-native-header',
    offline:()=>{throw Error('Synthetic native dashboard must stay local.');},offlineWindow:()=>window,quitting:()=>quitting,
    quit:()=>{requestQuit().catch(fail);},printer:()=>'',accepted:work=>shutdown.run(work)});
  // The production partition is inspected through the actual native session.
  const {session}=require('electron'),browser=session.fromPartition('persist:fasakhansta-dashboard');
  if(phase==='close'){
    await browser.cookies.set({url:server.origin,name:'fixture_session',value:'synthetic-current-session',path:'/',httpOnly:true,sameSite:'lax'});
    await browser.cookies.set({url:server.origin,name:'unrelated-persistent',value:'synthetic-preference',path:'/',expirationDate:Date.now()/1000+86400,sameSite:'lax'});
    await browser.cookies.set({url:'http://other.invalid',name:'foreign_session',value:'synthetic-foreign',path:'/',httpOnly:true,expirationDate:Date.now()/1000+86400,sameSite:'lax'});
    if(scenario!=='plain')await browser.cookies.set({url:server.origin,name:recaller,value:selectedRemember,path:'/',httpOnly:true,expirationDate:Date.now()/1000+86400,sameSite:'lax'});
    const initial=await browser.cookies.get({url:server.origin+'/admin/dashboard'}),active=initial.find(cookie=>cookie.name==='fixture_session');
    assert.equal(active.httpOnly,true);assert.equal(active.session,true);
    if(scenario!=='plain'){const cookie=initial.find(value=>value.name===recaller);assert.equal(cookie.httpOnly,true);assert.equal(cookie.session,false);assert.equal(cookie.value,selectedRemember);}
    cookieFlagsChecked=true;
  }else{
    const retained=await browser.cookies.get({url:server.origin+'/admin/dashboard'});
    assert.equal(retained.some(cookie=>cookie.name==='fixture_session'),false,'The previous active session must not survive the fresh process.');
    const remembered=retained.find(cookie=>cookie.name===recaller);
    if(scenario==='plain')assert.equal(remembered,undefined);else{assert.ok(remembered);assert.equal(remembered.value,selectedRemember);assert.equal(remembered.httpOnly,true);assert.equal(remembered.session,false);recallerPreserved=true;}
    cookieFlagsChecked=true;scopedSessionEnded=true;
  }
  await dashboard.open();window=BrowserWindow.getAllWindows()[0];assert.ok(window&&window.isVisible());
  const account=await window.webContents.executeJavaScript('document.body.dataset.account',true);
  if(phase==='close'){
    assert.equal(account,'account-42');
    await window.webContents.executeJavaScript('document.getElementById("logout").click();true',true);
  }else{
    if(scenario==='remember'){assert.equal(account,'account-42');automaticSameAccount=true;}
    else{assert.equal(account,'anonymous');anonymousAfterReopen=true;if(scenario==='invalid')invalidRejected=true;}
    await requestQuit();
  }
}
run().catch(fail);
