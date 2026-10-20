'use strict';
// Real Electron BrowserWindow/preload/IPC and Windows DPAPI metadata. Only the
// private PHP boundary and modal answer adapter are explicit fixtures; the real
// MariaDB fixture separately exercises the source-bound PHP review controls.
const {app,BrowserWindow,safeStorage}=require('electron');
const fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const Review=require('../src/dashboard-outbox-review.cjs'),{GenerationStore}=require('../src/dashboard-generation.cjs');
const createDashboard=require('../src/dashboard.cjs');
app.disableHardwareAcceleration();
const root=require('node:fs').mkdtempSync(path.join(os.tmpdir(),'fasakhansta-outbox-review-'));
app.setPath('userData',path.join(root,'chromium-profile'));
let server,exitCode=0;
const deadline=setTimeout(()=>{process.stderr.write('Native outbox review deadline exceeded.\n');app.exit(1);},90000);deadline.unref();
app.whenReady().then(async()=>{
  assert.equal(process.platform,'win32');assert.equal(safeStorage.isEncryptionAvailable(),true,'The actual Windows account must provide DPAPI.');
  const sourceRevision=execFileSync('git',['rev-parse','HEAD'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8'}).trim();
  const deviceId='22222222-2222-4222-8222-222222222222',snapshotId='33333333-3333-4333-8333-333333333333',commandId='11111111-1111-4111-8111-111111111111';
  const metadata=new GenerationStore(path.join(root,'metadata'),safeStorage),auditId='44444444-4444-4444-8444-444444444444',auditWrites=[],dialogs=[];
  const persisted=metadata.write.bind(metadata);metadata.write=async(name,value)=>{await persisted(name,value);auditWrites.push(value.state);};
  let queued=0,sync=0;
  const runtime={manifest:{sourceHashes:{'app/Services/Dashboard/DesktopDashboardOutboxReview.php':'a'.repeat(64)}},metadata,
    connection:async()=>({deviceId,actorId:10,snapshotId,sourceRevision,database:'fasakhansta_dashboard_stage_1234567890abcdef'}),
    control:async value=>{
      if(value.action==='outbox-review')return {format:1,device_id:deviceId,actor_id:10,snapshot_id:snapshotId,held:false,
        counts:{pending:1,conflicts:1,acknowledged:2},first:{command_id:commandId,actor_id:10,sequence:3,route_name:'takeaway.checkout',status:'conflict',
          attempts:2,occurred_at:'2026-10-20 00:00:00',last_error:'تعارض محفوظ للمراجعة',review_token:'f'.repeat(64)}};
      assert.deepEqual(value,{action:'outbox-retry',command_id:commandId,review_token:'f'.repeat(64)});
      assert.equal((await metadata.read('outbox-review/'+auditId)).state,'requested','DPAPI audit intent must exist before retry is queued.');
      queued++;return {format:1,command_id:commandId,queued:true,counts:{pending:2,conflicts:0,acknowledged:2}};
    }};
  const review=new Review({runtime,dialog:{showMessageBox:async(_window,options)=>{dialogs.push(options);return {response:0};}},
    synchronize:async()=>sync++,randomUUID:()=>auditId});
  server=http.createServer((_request,response)=>{response.setHeader('Content-Type','text/html; charset=utf-8');response.end('<!doctype html><html><head><title>Native review fixture</title></head><body>مراجعة المزامنة</body></html>');});
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const origin='http://127.0.0.1:'+server.address().port;
  const dashboard=createDashboard({origin,localToken:'fixture-local',quitting:()=>false,quit:()=>{},offline:()=>{throw Error('Unexpected network fallback');},
    offlineWindow:()=>BrowserWindow.getAllWindows()[0],printer:()=>'',reviewSync:window=>review.open(window)});
  await dashboard.open();const window=BrowserWindow.getAllWindows()[0];assert.ok(window);
  const result=await window.webContents.executeJavaScript('window.FasakhanstaDesktop.reviewSync()');
  assert.equal(result.retried,true);assert.equal(queued,1);assert.equal(sync,1);assert.equal(dialogs.length,2);
  for(const options of dialogs){assert.equal(options.defaultId,1);assert.equal(options.cancelId,1);}
  assert.deepEqual(auditWrites,['requested','queued']);
  const raw=await fs.readFile(path.join(root,'metadata','outbox-review',auditId+'.enc'));
  assert.equal(raw.includes(Buffer.from(commandId)),false,'The native audit must not store command metadata as plaintext.');
  const audit=await metadata.read('outbox-review/'+auditId);assert.equal(audit.commandId,commandId);assert.equal(audit.state,'queued');
  assert.equal(audit.sourceRevision,sourceRevision);
  const proof={format:1,completed:true,platform:process.platform,sourceRevision,actualBrowserWindow:true,actualPreload:true,actualIpc:true,
    actualDpapiAudit:true,durableIntentBeforeRetry:true,explicitDefaultCancelledConfirmations:2,privateControlFixture:true,dialogAnswerFixture:true,
    unchangedCommandId:true,queued:1,sync:1,businessResolution:false};
  if(process.argv[2]){const receipt=path.resolve(process.argv[2]);await fs.mkdir(path.dirname(receipt),{recursive:true});await fs.writeFile(receipt,JSON.stringify(proof,null,2)+'\n');}
  process.stdout.write('DESKTOP_OUTBOX_NATIVE_COMPLETE '+JSON.stringify(proof)+'\n');
}).catch(error=>{process.stderr.write(error.stack+'\n');exitCode=1;}).finally(async()=>{
  for(const window of BrowserWindow.getAllWindows())window.destroy();
  if(server)await new Promise(resolve=>server.close(resolve));
  // Chromium's profile remains runner-owned until process exit on Windows.
  await fs.rm(path.join(root,'metadata'),{recursive:true,force:true});clearTimeout(deadline);app.exit(exitCode);
});
