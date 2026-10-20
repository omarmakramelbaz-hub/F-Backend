'use strict';
// Windows Electron, real isolated windows/preload/IPC and DPAPI-backed durable
// journal. The print adapter is intercepted: this never asserts physical paper.
const {app,BrowserWindow,safeStorage}=require('electron');
const fs=require('node:fs/promises'),fsSync=require('node:fs'),os=require('node:os'),path=require('node:path'),http=require('node:http');
const assert=require('node:assert/strict'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
assert.equal(process.platform,'win32','Run this fixture in actual Windows Electron.');
const root=fsSync.mkdtempSync(path.join(os.tmpdir(),'fasakhansta-native-print-'));
app.setPath('userData',path.join(root,'isolated-windows-profile'));
const sourceRoot=process.env.DESKTOP_TEST_SOURCE_MODULES||path.resolve(__dirname,'../src');
const {GenerationStore}=require(path.join(sourceRoot,'dashboard-generation.cjs'));
const {DashboardPrintEffects,sha256,receiptHash}=require(path.join(sourceRoot,'dashboard-print-effects.cjs'));
const createDashboard=require(path.join(sourceRoot,'dashboard.cjs'));
const events=[],burns=new Map(),invocations=[],effects=new Map(),receipts=[];
let quitting=false,server,dashboard,currentEffect,callbackToRelease,interceptResult=true,loadedReceipts=0;
const id=number=>number.toString(16).padStart(64,'0');
for(const [number,kind]of [[1,'phone_kitchen'],[2,'sale_receipt'],[3,'shift_report'],[4,'phone_kitchen'],[5,'dining_kitchen'],[6,'bill_receipt'],[7,'delivery_batch']]){
  const html='<html data-dashboard-receipt="'+kind+'" dir="rtl"><head><meta charset="utf-8"><style>body{font-family:Arial;font-size:16px}</style></head><body>فسخانستا — فاتورة اختبار '+number+'</body></html>';
  effects.set(id(number),{effect_id:id(number),device_id:'00000000-0000-4000-8000-000000000001',enrollment_id:'b'.repeat(64),
    origin_command_id:crypto.randomUUID(),kind,actor_id:10,branch:'f:100',revision:1,snapshot_sha256:'c'.repeat(64),html,render_sha256:sha256(html)});
}
// This interception is scoped to this test process and is never installed in
// production source. Real BrowserWindows, CSP, local requests and IPC still run.
app.on('web-contents-created',(_event,contents)=>{
  contents.getPrintersAsync=async()=>[{name:'Fixture-Only-Printer',isDefault:true}];
  contents.print=(options,callback)=>{
    assert.ok(currentEffect && burns.has(currentEffect),'The authoritative burn preceded Electron invocation.');
    assert.ok(events.includes('write:burnt:'+currentEffect),'The native journal was durably flushed before invocation.');
    assert.equal(options.silent,true);assert.equal(options.deviceName,'Fixture-Only-Printer');
    assert.equal(invocations.some(item=>item.effect_id===currentEffect),false,'An immutable effect entered the adapter twice.');
    invocations.push({effect_id:currentEffect,options});events.push('invoke:'+currentEffect);
    if(interceptResult==='hold')callbackToRelease=callback;else callback(interceptResult,'fixture rejection');
  };
});
async function run(){
  await app.whenReady();assert.equal(safeStorage.isEncryptionAvailable(),true);
  const store=new GenerationStore(path.join(root,'durable-ledger'),safeStorage);
  const metadata={read:key=>store.read(key),write:async(key,value)=>{await store.write(key,value);events.push('write:'+value.phase+':'+value.identity.effect_id);}};
  const control=async value=>{
    events.push(value.action+':'+(value.effect_id||''));
    if(value.action==='print-prepare'){assert.ok(effects.has(value.effect_id));return effects.get(value.effect_id);}
    if(value.action==='print-resolve'){
      assert.equal(value.explicit_reprint,false,'Original receipt URLs cannot request fresh management copies.');
      if(value.path==='/admin/dining/kitchen/55/print')return {effect_id:id(5)};
      if(value.path==='/admin/phone-orders/kitchen/44/print')return {effect_id:id(4)};
      assert.equal(value.path,'/admin/takeaway/receipts/22/print');assert.equal(value.query.initial_print,'fixture-grant');return {effect_id:id(2)};
    }
    if(value.action==='print-list')return {effects:[{effect_id:id(2),kind:'sale_receipt'},{effect_id:id(3),kind:'shift_report'},{effect_id:id(5),kind:'dining_kitchen'},{effect_id:id(6),kind:'bill_receipt'},{effect_id:id(7),kind:'delivery_batch'}],attention:false};
    if(value.action==='print-burn'){
      assert.equal(burns.has(value.effect_id),false);assert.equal(value.actor_id,10);assert.equal(value.branch,'f:100');
      const effect=effects.get(value.effect_id);assert.equal(value.render_sha256,effect.render_sha256);
      burns.set(value.effect_id,value);currentEffect=value.effect_id;
      return {effect_id:value.effect_id,invocation_id:value.invocation_id,permit:'d'.repeat(64),render_sha256:effect.render_sha256};
    }
    if(value.action==='print-outcome'){
      const {action,...receipt}=value;assert.equal(burns.get(value.effect_id).invocation_id,value.invocation_id);
      receipts.push(receipt);return {effect_id:value.effect_id,invocation_id:value.invocation_id,state:value.state,receipt_sha256:receiptHash(receipt)};
    }
    throw Error('Unexpected native control action.');
  };
  server=http.createServer((req,res)=>{
    if(req.url.startsWith('/admin/takeaway/')){loadedReceipts++;res.writeHead(500);res.end('Legacy GET must never be used.');return;}
    res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});res.end('<!doctype html><html><head><meta charset="utf-8"></head><body>داشبورد اختبار</body></html>');
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const origin='http://127.0.0.1:'+server.address().port;
  dashboard=createDashboard({origin,localToken:'fixture-browser-token',serverOrigin:'https://fasakhaninja.com',
    printControl:control,printMetadata:metadata,quitting:()=>quitting,quit:()=>{},printer:()=> 'Fixture-Only-Printer',offline:()=>{},offlineWindow:()=>BrowserWindow.getAllWindows()[0]});
  await dashboard.open();const window=BrowserWindow.getAllWindows().find(value=>value.webContents.getURL()===origin+'/admin/dashboard');assert.ok(window);
  const invoke=source=>window.webContents.executeJavaScript(source);
  assert.equal(await invoke('typeof window.FasakhanstaDesktop.printEffect'), 'function');
  await invoke('window.FasakhanstaDesktop.printEffect('+JSON.stringify(id(1))+')');
  await invoke('window.FasakhanstaDesktop.printEffect('+JSON.stringify(id(1))+')');assert.equal(invocations.length,1);
  await invoke('window.FasakhanstaDesktop.printReceipt('+JSON.stringify(origin+'/admin/takeaway/receipts/22/print?initial_print=fixture-grant')+')');
  assert.equal(loadedReceipts,0);assert.equal(invocations.length,2);
  const polled=await invoke('window.FasakhanstaDesktop.printPending()');assert.equal(polled.invoked,2);assert.equal(invocations.length,4);
  // Original UI receipt loading can follow native auto-polling; it must resolve
  // the same already consumed effect rather than minting an owner reprint.
  await invoke('window.FasakhanstaDesktop.printReceipt('+JSON.stringify(origin+'/admin/dining/kitchen/55/print')+')');assert.equal(invocations.length,4);
  await invoke('window.FasakhanstaDesktop.printEffect('+JSON.stringify(id(6))+')');
  await invoke('window.FasakhanstaDesktop.printEffect('+JSON.stringify(id(7))+')');assert.equal(invocations.length,6);
  // A real in-flight IPC action blocks dataset switch. Page/navigation cannot
  // turn a pending callback into a new permit or complete it on the renderer.
  interceptResult='hold';const pending=invoke('window.FasakhanstaDesktop.printEffect('+JSON.stringify(id(4))+')');
  for(let attempt=0;!callbackToRelease&&attempt<100;attempt++)await new Promise(resolve=>setTimeout(resolve,20));
  assert.ok(callbackToRelease);assert.equal(await dashboard.canRefresh(),false);
  await assert.rejects(dashboard.switchTo({origin,localToken:'fixture-browser-token'}),/انتظر/);
  callbackToRelease(false,'fixture rejected');await assert.rejects(pending,/مراجعة/);
  callbackToRelease(true);await assert.rejects(invoke('window.FasakhanstaDesktop.printEffect('+JSON.stringify(id(4))+')'),/مراجعة/);
  assert.equal(invocations.length,7);assert.equal(receipts.filter(value=>value.effect_id===id(4)).length,1);
  assert.equal(receipts.find(value=>value.effect_id===id(4)).state,'review');
  await assert.rejects(invoke('window.FasakhanstaDesktop.printReceipt('+JSON.stringify(origin+'/admin/phone-orders/kitchen/44/print')+')'),/مراجعة/);assert.equal(invocations.length,7);
  // Reconstruct from actual encrypted bytes. The printer adapter must never be
  // entered again, including when a new native session is created.
  const restarted=new DashboardPrintEffects({control,metadata:new GenerationStore(path.join(root,'durable-ledger'),safeStorage),
    prepareDocument:()=>{throw Error('A retained invocation must not prepare a second document.');}});
  assert.equal((await restarted.print(id(1))).already_recorded,true);await assert.rejects(restarted.print(id(4)),/مراجعة/);
  assert.equal(invocations.length,7);
  const raw=await fs.readFile(path.join(root,'durable-ledger','print-effects',id(1)+'.enc'));
  assert.equal(raw.includes(Buffer.from('d'.repeat(64))),false);assert.equal(raw.includes(Buffer.from('spooler_accepted')),false);
  const sourceRevision=execFileSync('git',['rev-parse','HEAD'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8'}).trim();
  const result={format:1,sourceRevision,platform:process.platform,
    electron:process.versions.electron,adapter:'intercepted Electron webContents.print',hardware:false,
    realPreloadIpc:true,immutableReceiptResolve:true,legacyReceiptRequests:loadedReceipts,invocations:invocations.length,
    accepted:receipts.filter(value=>value.state==='invoked').length,review:receipts.filter(value=>value.state==='review').length,
    refreshFence:true,encryptedRestartRecovery:true,lateCallbackDidNotRewrite:true,originalUrlAfterAutopollDidNotReprint:true};
  const destination=path.resolve(process.argv[2]||path.join(__dirname,'../dist/offline-print-native.json'));
  await fs.mkdir(path.dirname(destination),{recursive:true});await fs.writeFile(destination,JSON.stringify(result,null,2)+'\n');
  process.stdout.write('PASS real Windows Electron preload IPC, immutable print burn, terminal outcome and encrypted restart recovery (printer adapter intercepted)\n');
}
run().then(()=>finish(0),error=>{process.stderr.write(error.stack+'\n');return finish(1);});
async function finish(code){
  quitting=true;for(const window of BrowserWindow.getAllWindows())window.destroy();
  if(server)await new Promise(resolve=>server.close(resolve));
  // Chromium keeps Windows profile handles until process exit. The disposable
  // runner owns that profile; do not turn a correct probe into an EPERM failure
  // by deleting it while Electron is still alive. Our own journal files close
  // after every fsync and can be removed before exiting.
  await fs.rm(path.join(root,'durable-ledger'),{recursive:true,force:true});app.exit(code);
}
