'use strict';
// Actual Windows Electron windows and single-instance lock, with only a
// disposable SQLite row and a loopback HTML page. No business account is loaded.
const {app,BrowserWindow}=require('electron');
const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const crypto=require('node:crypto'),assert=require('node:assert/strict');
const {DatabaseSync}=require('node:sqlite');
const {execFileSync}=require('node:child_process');
const Shutdown=require('../src/shutdown.cjs');
const createDashboard=require('../src/dashboard.cjs');

assert.equal(process.platform,'win32','Run native shutdown acceptance in actual Windows.');
const lockedElectron=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../package-lock.json'),'utf8')).packages['node_modules/electron'].version;
assert.equal(process.versions.electron,lockedElectron,'Native shutdown must use the exact locked Electron binary.');
assert.equal(process.argv.length,5,'The isolated phase, profile and receipt are required.');
const [phase,profile,receiptFile]=process.argv.slice(2);
assert.ok(['close','reopen'].includes(phase));
assert.ok(path.isAbsolute(profile)&&path.isAbsolute(receiptFile));
assert.equal(fs.readFileSync(path.join(profile,'shutdown-fixture-profile.marker'),'utf8'),'GO_ERP_SYNTHETIC_SHUTDOWN_ONLY\n');
const sourceRevision=process.env.GITHUB_SHA||execFileSync('git',['rev-parse','HEAD'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8'}).trim();
assert.match(sourceRevision,/^[a-f0-9]{40}$/);
const sha=file=>crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const sourceHashes={shutdown:sha(path.join(__dirname,'../src/shutdown.cjs')),dashboard:sha(path.join(__dirname,'../src/dashboard.cjs')),
  fixture:sha(__filename)};
app.setName('GO ERP native shutdown fixture');
app.setPath('userData',profile);
const primaryInstance=app.requestSingleInstanceLock();
assert.equal(primaryInstance,true,'A fresh launch must acquire its actual private single-instance lock.');
let db,server,quitting=false,failed=false,mainWindow,acceptedCommitted=phase==='reopen',drainCompleted=false;
let vetoObserved=0,vetoAfterDrain=true,windowClosed=false,sqliteClosed=false,retainedBeforeCommit=phase==='reopen',savedRowVerified=false;
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const shutdown=new Shutdown(app,()=>{quitting=true;server?.close();},()=>{
  if(db?.isOpen)db.close();sqliteClosed=true;
},async()=>{
  if(!failed)assert.equal(acceptedCommitted,true,'Accepted SQLite work must finish before final shutdown.');
  await delay(50);drainCompleted=true;
});
// This observer writes evidence only; it never prevents quit or destroys a
// successful window. The production Shutdown class owns final unload authority.
app.once('will-quit',()=>{
  if(failed)return;
  try{
    assert.equal(drainCompleted,true);assert.equal(acceptedCommitted,true);
    assert.equal(sqliteClosed,true);assert.equal(windowClosed,true);
    assert.ok(vetoObserved>=1,'The actual renderer must attempt to veto unload.');
    assert.equal(vetoAfterDrain,true,'Unload authority must be used only after accepted work and drain.');
    assert.equal(BrowserWindow.getAllWindows().length,0);
    fs.writeFileSync(receiptFile,JSON.stringify({format:1,kind:'go-erp-native-shutdown-phase',phase,sourceRevision,sourceHashes,
      electronVersion:process.versions.electron,platform:process.platform,pid:process.pid,hardware:false,productionProfile:false,
      primaryInstance,actualDashboardWindow:true,actualBeforeUnloadVeto:true,acceptedWriteCommitted:acceptedCommitted,
      retainedBeforeCommit,drainedBeforeUnload:vetoAfterDrain,sqliteClosed,nativeWindowClosed:windowClosed,
      savedRowVerified,naturalQuit:true,completed:true})+'\n',{flag:'wx'});
  }catch(error){failed=true;process.exitCode=1;process.stderr.write(error.stack+'\n');}
});

async function run(){
  await app.whenReady();
  db=new DatabaseSync(path.join(profile,'shutdown-fixture.sqlite'));
  if(phase==='close'){
    db.exec('PRAGMA journal_mode=WAL; CREATE TABLE accepted_rows (id TEXT PRIMARY KEY, value TEXT NOT NULL)');
    assert.equal(db.prepare('SELECT count(*) AS n FROM accepted_rows').get().n,0);
  }else{
    assert.deepEqual({...db.prepare('SELECT id,value FROM accepted_rows').get()},{id:'accepted-before-close',value:'survives-native-reopen'});
    assert.equal(db.prepare('SELECT count(*) AS n FROM accepted_rows').get().n,1);savedRowVerified=true;
  }
  server=http.createServer((request,response)=>{
    assert.equal(request.method,'GET');assert.equal(request.headers['x-fasakhansta-desktop'],'synthetic-native-shutdown-token');
    response.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'});
    response.end('<!doctype html><html><head><meta charset="utf-8"></head><body>Native close fixture<script>window.onbeforeunload=()=>false;</script></body></html>');
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const localOrigin='http://127.0.0.1:'+server.address().port;
  const dashboard=createDashboard({origin:localOrigin,serverOrigin:'https://fixture.invalid',localToken:'synthetic-native-shutdown-token',
    offline:()=>{throw Error('The isolated native page must not use an offline fallback.');},offlineWindow:()=>mainWindow,
    quitting:()=>quitting,quit:()=>app.quit(),printer:()=>'',accepted:work=>shutdown.run(work)});
  await dashboard.open();
  const windows=BrowserWindow.getAllWindows();assert.equal(windows.length,1);mainWindow=windows[0];
  assert.equal(mainWindow.isVisible(),true);
  assert.equal(await mainWindow.webContents.executeJavaScript('typeof window.onbeforeunload === "function" && window.onbeforeunload() === false',true),true);
  mainWindow.webContents.on('will-prevent-unload',()=>{vetoObserved++;vetoAfterDrain&&=shutdown.ready&&drainCompleted&&acceptedCommitted;});
  mainWindow.once('closed',()=>{windowClosed=true;});
  if(phase==='close'){
    const accepted=shutdown.run(async()=>{
      await delay(250);
      db.prepare('INSERT INTO accepted_rows(id,value) VALUES(?,?)').run('accepted-before-close','survives-native-reopen');
      acceptedCommitted=true;
    });
    await new Promise(setImmediate);
    assert.equal(shutdown.pending.size,1);
    mainWindow.close();
    assert.equal(shutdown.stopping,true);assert.equal(shutdown.ready,false);
    await delay(50);
    retainedBeforeCommit=!mainWindow.isDestroyed()&&db.isOpen&&!acceptedCommitted;
    assert.equal(retainedBeforeCommit,true,'Native X-close must retain accepted work before the delayed commit.');
    await accepted;
  }else mainWindow.close();
}
run().catch(error=>{
  failed=true;process.exitCode=1;process.stderr.write(error.stack+'\n');
  server?.closeAllConnections?.();server?.close();
  // Failure cleanup is not a passing shutdown path. No forced-exit API is used.
  for(const window of BrowserWindow.getAllWindows())window.destroy();
  app.quit();
});
