const {app,BrowserWindow,ipcMain,protocol,net,safeStorage,dialog,session}=require('electron');
const fs=require('node:fs');const path=require('node:path');const {pathToFileURL}=require('node:url');
const Store=require('./store.cjs'), Sync=require('./sync.cjs'), receipt=require('./receipt.cjs');
const Shutdown=require('./shutdown.cjs');
const LocalRuntime=require('./local-runtime.cjs');
const DashboardSync=require('./dashboard-sync.cjs');
const synchronizePrintOutcomes=require('./dashboard-print-sync.cjs');
const SyncSchedule=require('./sync-schedule.cjs');
const {DashboardGeneration}=require('./dashboard-generation.cjs');
const {DashboardDelta}=require('./dashboard-delta.cjs');
const {DashboardPreparation}=require('./dashboard-preparation.cjs');
const DashboardMode=require('./dashboard-mode.cjs');
const {DashboardConnected}=require('./dashboard-connected.cjs');
const DashboardConnectedReview=require('./dashboard-connected-review.cjs');
const DashboardRemoteState=require('./dashboard-remote-state.cjs');
const DashboardRemoteAttempts=require('./dashboard-remote-attempts.cjs');
const DashboardOutboxReview=require('./dashboard-outbox-review.cjs');
const {DashboardLiveSocial}=require('./dashboard-live-social.cjs');
const {DashboardUpdater}=require('./dashboard-updater.cjs');
const createUpdateInstall=require('./update-install.cjs');
const RuntimeArchive=require('./runtime-archive.cjs');
const {upgradeStartupSource}=require('./startup-source-upgrade.cjs');
const {inspectStartupIdle}=require('./startup-source-idle.cjs');
const readSnapshot=require('./dashboard-download.cjs');
const {withDeadline,printOnce}=require('./native-deadline.cjs');
const LifecycleTrace=require('./lifecycle-trace.cjs');
const AccountProfiles=require('./account-profiles.cjs');
const AccountSwitch=require('./account-switch.cjs');
const chooseAccount=require('./account-picker.cjs');
const createDashboard=require('./dashboard.cjs'),dashboardPolicy=require('./dashboard-policy.cjs');
const accountSignout=require('./account-signout.cjs');
app.setName('GO ERP');
protocol.registerSchemesAsPrivileged([{scheme:'fasakhansta',privileges:{standard:true,secure:true,supportFetchAPI:true}}]);
if(app.isPackaged&&/-preview\.[1-9][0-9]*$/.test(app.getVersion()))
  app.setPath('userData',path.join(app.getPath('appData'),'fasakhansta-dashboard-preview'));
const primaryInstance=Boolean(process.env.POS_TEST_PROFILE&&!app.isPackaged)||app.requestSingleInstanceLock();
if(!primaryInstance)app.quit();
let win,store,sync,dashboard,localRuntime,localSync,generations,incomingDelta,preparation,dashboardMode,connectedAccess,connectedReview,remoteState,remoteAttempts,outboxReview,liveSocial,updater,lifecycleTrace,accountProfiles,activeAccount,accountSwitch,accountLogin,loginWindow,quitting=false,installingUpdate=false,profileSwitching=false,startupComplete=false,startupHidden=false,legacyLoading,legacyReady=false;
const requests=new Set();
const syncSchedule=new SyncSchedule({work:syncCycle,setTimer:setTimeout,clearTimer:clearTimeout});
const shutdown=new Shutdown(app,()=>{
  lifecycleTrace?.mark('shutdown-requested');quitting=true;
  loginWindow?.cancel();accountLogin?.cancel();accountSwitch?.cancel();localRuntime?.cancelStartup?.();updater?.stop();incomingDelta?.stop();liveSocial?.close();dashboard?.cancelAuthentication();syncSchedule.stop();sync?.stop();localSync?.stop();for(const controller of requests)controller.abort();
  lifecycleTrace?.mark('shutdown-wait-accepted');
},()=>{
  lifecycleTrace?.mark('shutdown-close-ledger');
  try{store?.close();lifecycleTrace?.mark('shutdown-complete');}
  catch(error){lifecycleTrace?.mark('shutdown-ledger-failed');throw error;}
},async()=>{
  lifecycleTrace?.mark('shutdown-accepted-settled');lifecycleTrace?.mark('shutdown-drain-updater');await updater?.running;
  lifecycleTrace?.mark('shutdown-stop-runtime');
  try{await accountLogin?.stop();}finally{await localRuntime?.stop();}
  lifecycleTrace?.mark('shutdown-runtime-stopped');
},{canRelaunch:()=>!installingUpdate});
function startupPhase(phase,details){if(!quitting&&!startupComplete)lifecycleTrace?.mark(phase,details);}
function hideStartup(){if(!legacyLoading&&!startupHidden&&win&&!win.isDestroyed()){win.hide();startupHidden=true;}}
function dashboardShown(){
  if(quitting)return;
  startupPhase('dashboard-visible');hideStartup();loginWindow?.dispose();loginWindow=null;
}
function dashboardNavigation(event={}){
  if(quitting)return;
  lifecycleTrace?.mark({started:'dashboard-navigation',response:'dashboard-response',interactive:'dashboard-interactive',loaded:'dashboard-loaded',
    failed:'dashboard-navigation-failed',timeout:'dashboard-navigation-timeout','user-navigation':'dashboard-user-navigation'}[event.phase],event);
}
function origin(value) {const u=new URL(String(value));if(u.protocol!=='https:'||u.username||u.password)throw Error('اكتب رابط الداشبورد الصحيح ويبدأ بـ https://');return u.origin;}
async function request(method,endpoint,body,credential=store.get('connection')) {
  if(!credential)throw Error('الجهاز يحتاج ربطًا بالداشبورد.');
  const headers={'Accept':'application/json','Content-Type':'application/json'};
  if(credential.token_cipher)headers.Authorization='Bearer '+safeStorage.decryptString(Buffer.from(credential.token_cipher,'base64'));
  const controller=new AbortController();requests.add(controller);
  try {
  const res=await net.fetch(origin(credential.origin)+'/api/desktop-pos/'+endpoint,{method,headers,body:body?JSON.stringify(body):undefined,redirect:'error',signal:AbortSignal.any([controller.signal,AbortSignal.timeout(12000)])});
  let data;try{data=await res.json();}catch{throw Error('تعذر قراءة رد الداشبورد. العمليات محفوظة على الجهاز.');}
  if(!res.ok){const error=Error(data.message||Object.values(data.errors||{}).flat()[0]||'تعذر المزامنة. العمليات محفوظة على الجهاز.');error.status=res.status;throw error;}
  return data;
  }finally{requests.delete(controller);}
}
function state() {
  const c=store.get('connection');return {paired:Boolean(c),origin:c?.origin||'',snapshot:store.snapshot(),orders:store.openOrders(),history:store.history(),counts:store.counts(),online:sync.online,error:sync.error,last_synced:store.get('last_synced'),printer:store.get('printer')||'',sync_interval_seconds:syncSchedule.intervalMs/1000,sync_queued:Boolean(syncSchedule.waiting||syncSchedule.refreshPending),sync_running:Boolean(syncSchedule.running)};
}
async function dashboardRequest(command, bootstrap=false, suppliedCredential, endpoint='commands') {
  const credential=suppliedCredential||await localRuntime.connection();
  const controller=new AbortController();requests.add(controller);
  try{
    const response=await net.fetch(origin(credential.serverOrigin)+'/api/desktop-dashboard/'+(bootstrap?'bootstrap':endpoint),{
      method:bootstrap?'GET':'POST',headers:{Accept:'application/json','Content-Type':'application/json',Authorization:'Bearer '+credential.token},
      body:bootstrap?undefined:JSON.stringify(command),redirect:'error',signal:AbortSignal.any([controller.signal,AbortSignal.timeout(bootstrap?120000:25000)])});
    const data=bootstrap?await readSnapshot(response):await response.json();
    if(!response.ok){const error=Error(data.message||'العملية المحلية محفوظة ولم يؤكدها السيرفر بعد.');error.status=response.status;throw error;}
    return data;
  }finally{requests.delete(controller);}
}
async function dashboardMediaRequest(ticket) {
  const credential=await preparation.credential(),controller=new AbortController();requests.add(controller);
  try{
    const response=await net.fetch(origin(credential.serverOrigin)+'/api/desktop-dashboard/media?ticket='+encodeURIComponent(ticket),{
      method:'GET',headers:{Accept:'image/*',Authorization:'Bearer '+credential.token},redirect:'error',
      signal:AbortSignal.any([controller.signal,AbortSignal.timeout(60000)])});
    if(!response.ok){const error=Error('لم يؤكد السيرفر تنزيل صورة الحساب؛ بيانات الجهاز الحالية محفوظة.');error.status=response.status;throw error;}
    // Keep shutdown's abort controller until the image stream finishes, not only its headers.
    const reader=response.body?.getReader();
    if(!reader){requests.delete(controller);return response;}
    const stream=new ReadableStream({
      async pull(target){try{const part=await reader.read();if(part.done){requests.delete(controller);reader.releaseLock();target.close();}else target.enqueue(part.value);}
        catch(error){requests.delete(controller);reader.releaseLock();target.error(error);}},
      async cancel(){try{await reader.cancel();}finally{requests.delete(controller);reader.releaseLock();}}
    });
    return new Response(stream,{status:response.status,headers:response.headers});
  }catch(error){requests.delete(controller);throw error;}
}
async function dashboardDeltaRequest(index,credential,{signal}={}) {
  const input=JSON.stringify(index);
  if(Buffer.byteLength(input)>64*1024*1024)throw Error('فهرس تغييرات الجهاز أكبر من الحد المسموح؛ العمل المحلي مستمر.');
  const controller=new AbortController();requests.add(controller);
  try{
    const requestSignal=AbortSignal.any([controller.signal,AbortSignal.timeout(128000),...(signal?[signal]:[])]);
    const response=await net.fetch(origin(credential.serverOrigin)+'/api/desktop-dashboard/delta',{
      method:'POST',headers:{Accept:'application/json','Content-Type':'application/octet-stream',Authorization:'Bearer '+credential.token},
      body:input,redirect:'error',signal:requestSignal});
    if(!response.ok){await response.body?.cancel().catch(()=>{});const error=Error('تعذر استقبال تغييرات السيرفر؛ العمل المحلي مستمر.');error.status=response.status;throw error;}
    return await readSnapshot(response,256*1024*1024,{signal:requestSignal});
  }finally{requests.delete(controller);}
}
async function dashboardEnrollment(serverOrigin, input, csrf) {
  if(!dashboard?.session())throw Error('افتح حسابك على الداشبورد أولًا.');
  const controller=new AbortController();requests.add(controller);
  try {
    const response=await dashboard.session().fetch(origin(serverOrigin)+'/admin/desktop-dashboard/enroll',{
      method:'POST',credentials:'include',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},
      body:JSON.stringify(input),redirect:'error',signal:AbortSignal.any([controller.signal,AbortSignal.timeout(25000)])});
    const data=await response.json();
    if(!response.ok){const error=Error(data.message||'تعذر ربط الجهاز بحساب الداشبورد.');error.status=response.status;throw error;}
    return data;
  } finally {requests.delete(controller);}
}
async function dashboardState() {
  if(!preparation)return {available:false};
  const remote=remoteState?await remoteState.read():{pending:[],dirty:false};
  return {...await preparation.status(),mode:dashboard?.current().local?'local':'server',full_central:connectedAccess?await connectedAccess.selected():false,remote_pending:remote.pending.length,remote_dirty:remote.dirty,...(localSync?.state||{}),
    pending:(localSync?.state.pending||0)+(store?.counts().pending||0),update:updater?.state,
    data_refresh:incomingDelta?.state||{phase:'idle'},sync_interval_seconds:syncSchedule.intervalMs/1000,sync_queued:Boolean(syncSchedule.waiting||syncSchedule.refreshPending),sync_running:Boolean(syncSchedule.running)};
}
async function updateReady(insideTransition=false) {
  if(!startupComplete||quitting||installingUpdate||liveSocial?.busy()||incomingDelta?.running||!dashboardMode||syncSchedule.running||syncSchedule.waiting||generations?.running
    ||shutdown.pending.size>(insideTransition?1:0)||(!insideTransition&&dashboardMode.accepted.size)
    ||!(await localRuntime.isPrepared())||!(await dashboard.canRefresh()))return false;
  const old=store.counts(),remote=await remoteState.read();
  if(old.pending||old.conflicts||store.openOrders().length||remote.pending.length)return false;
  const local=await localRuntime.control({action:'pending'});
  return local.counts.pending===0&&local.counts.conflicts===0;
}
function connectUpdater(profile) {
  if(quitting||updater||!app.isPackaged||process.platform!=='win32'||!localRuntime)return;
  const install=createUpdateInstall({profile});
  updater=new DashboardUpdater({profile,metadata:localRuntime.metadata,fetch:(...args)=>net.fetch(...args),
    credential:async()=>await localRuntime.isPrepared()?localRuntime.connection():null,
    currentVersion:app.getVersion(),
    currentSource:async()=>(await new RuntimeArchive(profile,localRuntime.metadata).manifest(localRuntime.installedBundle)).sourceFingerprint,
    currentRevision:async()=>(await new RuntimeArchive(profile,localRuntime.metadata).manifest(localRuntime.installedBundle)).sourceRevision,
    canApply:()=>updateReady(),
    apply:async candidate=>shutdown.run(()=>dashboardMode.serialize('installer-update',async()=>{
      if(!(await updateReady(true)))return {deferred:true};
      const wasLocal=dashboard.current().local;
      return dashboard.withUpdateIdle(async()=>{
        // The view now blocks writes, navigation and printing. Stop background
        // work before taking the same durable database fence used by snapshots.
        if(quitting||liveSocial?.busy()||incomingDelta?.running||syncSchedule.running||generations?.running||shutdown.pending.size>1)return {deferred:true};
        syncSchedule.stop();installingUpdate=true;
        try {
          const remote=await remoteState.read(),local=await localRuntime.control({action:'pending'});
          if(quitting)throw Error('أُوقف تثبيت التحديث أثناء إغلاق البرنامج.');
          if(remote.pending.length||local.counts.pending||local.counts.conflicts)throw Error('انتظر تسوية العمليات قبل تثبيت التحديث.');
          await dashboardMode.hold();
          if(quitting)throw Error('أُوقف تثبيت التحديث أثناء إغلاق البرنامج.');
          await install(candidate);
          // Setup waits for this process to exit. Shutdown drains native work and
          // stops MariaDB before any installed executable can be replaced.
          app.quit();return {installing:true};
        }catch(error){
          installingUpdate=false;
          if(wasLocal)await dashboardMode.cancelHold();
          if(!quitting)syncSchedule.start();
          throw error;
        }
      });
    })),onState:()=>{dashboardState().then(value=>dashboard?.publish(value)).catch(()=>{});}});
  updater.start();
}
async function accountSwitchReady(){
  if(quitting||profileSwitching||installingUpdate||!dashboard||preparation?.running||liveSocial?.busy()
    ||incomingDelta?.running||syncSchedule.running||syncSchedule.waiting||generations?.running
    ||shutdown.pending.size>1||(dashboardMode&&dashboardMode.accepted.size>1))return false;
  const old=store.counts();
  if(old.pending||old.conflicts||store.openOrders().length)return false;
  const remote=remoteState?await remoteState.read():{pending:[],dirty:false};
  if(remote.dirty||remote.pending.length)return false;
  if(localRuntime&&await localRuntime.isPrepared()){
    const local=await localRuntime.control({action:'pending'});
    if(local.counts.pending||local.counts.conflicts)return false;
    if(localRuntime.supportsPrintEffects()){
      const prints=await localRuntime.control({action:'print-outcomes'});
      if(!prints||!Array.isArray(prints.outcomes)||!Number.isSafeInteger(prints.pending)||prints.pending<0
        ||prints.outcomes.length||prints.pending)return false;
    }
  }
  return !quitting&&!profileSwitching&&!syncSchedule.running&&!incomingDelta?.running;
}
function switchAccount(){
  if(!accountSwitch){
    accountSwitch=new AccountSwitch({profiles:accountProfiles,currentId:()=>activeAccount.id,available:accountSwitchReady,
      hold:work=>dashboard.withAccountIdle(work),
      serialize:work=>dashboardMode?dashboardMode.serialize('account-switch',work):work(),
      choose:(profiles,signal)=>chooseAccount({parent:BrowserWindow.getFocusedWindow()||win,profiles,signal}),
      pause:()=>syncSchedule.stop(),resume:()=>{if(!quitting&&!profileSwitching)syncSchedule.start();},
      flush:()=>withDeadline(()=>dashboard.session().cookies.flushStore(),5000,'تعذر حفظ جلسة الحساب الحالية؛ أعد المحاولة.'),
      restart:()=>{
        profileSwitching=true;
        // The next process is queued only after runtime drain and SQLite close.
        try{shutdown.requestRelaunch();app.quit();}
        catch(error){shutdown.cancelRelaunch();profileSwitching=false;throw error;}
      }});
  }
  return accountSwitch.request();
}
async function dashboardSession(credential) {
  const controller=new AbortController();requests.add(controller);
  try {
    const response=await dashboard.session().fetch(origin(credential.serverOrigin)+'/admin/desktop-dashboard/session',{
      credentials:'include',headers:{Accept:'application/json',Authorization:'Bearer '+credential.token},redirect:'error',
      signal:AbortSignal.any([controller.signal,AbortSignal.timeout(12000)])});
    if(!response.ok)throw Error('حساب السيرفر يحتاج تسجيل الدخول أو مراجعة الصلاحيات؛ استمر على الجهاز.');
    return await response.json();
  } finally {requests.delete(controller);}
}
async function dashboardOffline(message, failure) {
  if(!localRuntime)return localOrders(message);
  try {
    if(connectedAccess&&await connectedAccess.selected())throw Error('الداشبورد المتصلة تحتاج الإنترنت. استخدم العودة لنسخة الجهاز بعد اكتمال التحديث؛ سجلاتك محفوظة.');
    if(!dashboardMode)throw Error('جهّز الجهاز من حساب السيرفر قبل العمل بدون إنترنت.');
    await shutdown.run(()=>dashboardMode.local(failure));
  } catch(error) {if(!quitting)dialog.showErrorBox('GO ERP',error.message);}
}
function connectDashboardSync() {
  if(localSync)return;
  // DashboardMode holds the settled home page for the whole guarded replacement.
  // Individual activation must not nest another window refresh.
  generations=new DashboardGeneration({runtime:localRuntime,metadata:localRuntime.metadata,download:()=>dashboardRequest(null,true),
    allowPreview:app.isPackaged&&/-preview\.[1-9][0-9]*$/.test(app.getVersion())});
  dashboardMode=new DashboardMode({runtime:localRuntime,generations,view:dashboard,probe:dashboardSession,remoteState,remoteAttempts,
    onState:()=>{dashboardState().then(value=>dashboard?.publish(value)).catch(()=>{});}});
  incomingDelta=new DashboardDelta({runtime:localRuntime,metadata:localRuntime.metadata,view:dashboard,
    download:dashboardDeltaRequest,recover:()=>generations.recover(),remoteState,
    handoff:work=>localSync.withRefreshHandoff(work),
    allowPreview:app.isPackaged&&/-preview\.[1-9][0-9]*$/.test(app.getVersion()),
    onState:()=>{dashboardState().then(value=>dashboard?.publish(value)).catch(()=>{});}});
  localSync=new DashboardSync({local:value=>localRuntime.control(value),remote:dashboardRequest,backgroundRefresh:true,
    effects:async({printProtocol})=>{
      if(!synchronizePrintOutcomes.activeProtocol(printProtocol))return {pending:0,deferred:false};
      if(!localRuntime.supportsPrintEffects())throw Error('سجل الطباعة لا يطابق مصدر البرنامج؛ العمليات محفوظة.');
      if(dashboard?.current().local)await dashboard.recoverPrintEffects();
      return synchronizePrintOutcomes({local:value=>localRuntime.control(value),
        remote:value=>dashboardRequest(value,false,undefined,'print-outcomes'),stopped:()=>quitting});
    },refresh:()=>shutdown.run(async()=>{
    if(connectedAccess&&await connectedAccess.deferRefresh())return {deferred:true};
    const old=store.counts();if(old.pending)throw Error('توجد عمليات محفوظة من النسخة السابقة؛ يلزم تأكيدها قبل تحديث بيانات الجهاز.');
    return dashboardMode.serialize('incoming-delta',()=>incomingDelta.run());}),
    onState:()=>{dashboardState().then(value=>dashboard?.publish(value)).catch(()=>{});}});
}
function notify() {if(!quitting&&legacyReady&&win&&!win.isDestroyed())win.webContents.send('pos:state',state());}
function localOrders(message='') {
  if(quitting||!win||win.isDestroyed())return;
  // The previous POS renderer reads and renders its saved ledger. Open it only
  // when requested, rather than doing that work behind every dashboard launch.
  if(!legacyLoading)legacyLoading=win.loadURL('fasakhansta://pos/index.html').then(()=>{legacyReady=true;});
  return legacyLoading.then(()=>{if(quitting||win.isDestroyed())return;win.show();if(win.isMinimized())win.restore();win.focus();if(message)win.webContents.send('pos:notice',message);})
    .catch(error=>{legacyLoading=null;if(!quitting)dialog.showErrorBox('GO ERP',error.message);});
}
function writable() {
  if(localSync)throw Error('استخدم أقسام الداشبورد للعمل؛ سجل النسخة السابقة محفوظ للاطلاع والطباعة.');
  if(store.get('authorization_blocked'))throw Error('ربط الجهاز متوقف من الإدارة. العمليات السابقة محفوظة؛ يلزم إعادة تفعيل الربط.');
}
async function syncCycle({refresh=false}={}) {
  if(!primaryInstance||quitting)return;
  try{await shutdown.run(async()=>{
    if(localSync){
      if(store.get('connection'))await sync.run();if(quitting)return;
      try{await remoteAttempts?.recover();}catch(error){localSync.report({online:Boolean(error.status),error:error.message});return;}
      if(refresh)localSync.nextRefresh=0;
      await localSync.run();return;
    }
    if(store.get('connection')) {await sync.run();if(quitting)return;if(sync.online&&!sync.error)store.set('last_synced',new Date().toISOString());notify();}
  });}catch(error){if(!quitting){sync.error=error.message;notify();}}
}
function handle(name,fn) {
  ipcMain.handle('pos:'+name,async(e,...args)=>{if(e.sender!==win.webContents||!e.senderFrame.url.startsWith('fasakhansta://pos/'))throw Error('مصدر غير مسموح.');try{return {ok:true,value:await shutdown.run(()=>fn(...args))};}catch(error){return {ok:false,error:error.message};}});
}
async function printEvent(id) {
  const event=store.event(id), order=store.order(event.order_id);
  if(!['kitchen','bill','sale'].includes(event.kind))throw Error('هذه العملية لا تحتاج طباعة.');
  const w=new BrowserWindow({show:false,width:420,height:700,webPreferences:{sandbox:true,contextIsolation:true,nodeIntegration:false}});
  try {
    const printers=await withDeadline(()=>win.webContents.getPrintersAsync(),15000,'تعذر قراءة الطابعات في الوقت المحدد. الفاتورة محفوظة.');if(!printers.length)throw Error('لا توجد طابعة متاحة. الفاتورة محفوظة ويمكن إعادة طباعتها.');
    await withDeadline(async()=>{
      await w.loadURL('data:text/html;charset=utf-8,'+encodeURIComponent(receipt(event,order.snapshot)));
      await w.webContents.executeJavaScript('document.fonts.ready.then(()=>true)');
    },15000,'تعذر تجهيز الفاتورة للطباعة في الوقت المحدد. الفاتورة محفوظة.');
    const deviceName=store.get('printer')||'';if(deviceName&&!printers.some(p=>p.name===deviceName))throw Error('الطابعة المختارة غير متاحة. الفاتورة محفوظة.');
    await printOnce(w.webContents,{silent:true,printBackground:true,deviceName,margins:{marginType:'none'}});
    store.markPrinted(id);notify();
  }finally{w.destroy();}
}
app.whenReady().then(async()=>{
  if(!primaryInstance||quitting)return;
  const baseProfile=process.env.POS_TEST_PROFILE&&!app.isPackaged?process.env.POS_TEST_PROFILE:app.getPath('userData');fs.mkdirSync(baseProfile,{recursive:true});
  lifecycleTrace=new LifecycleTrace(baseProfile);startupPhase('startup-ready');
  accountProfiles=new AccountProfiles({root:baseProfile,safeStorage});
  const localBundle=path.join(process.resourcesPath||'', 'dashboard-runtime');
  const bundledRuntime=app.isPackaged&&fs.existsSync(path.join(localBundle,'manifest.json'));
  const createRuntime=account=>new LocalRuntime({bundle:localBundle,profile:account.profile,safeStorage,downloadMedia:dashboardMediaRequest,onPhase:startupPhase,
    authorizeInstalled:manifest=>updater?updater.authorizeInstalled(manifest):false,
    onFailure:()=>{if(!quitting)dialog.showErrorBox('GO ERP','خدمة الداشبورد المحلية توقفت. بيانات الجهاز محفوظة؛ أعد فتح البرنامج.');}});
  let loginResult;
  if(bundledRuntime){
    const {AccountLogin}=require('./account-login.cjs');
    const createLoginWindow=require('./login-window.cjs');
    accountLogin=new AccountLogin({profiles:accountProfiles,createRuntime,createSession:partition=>session.fromPartition(partition),
      serverOrigin:dashboardPolicy.DEFAULT_ORIGIN,stopped:()=>quitting,online:()=>net.isOnline()});
    // Show the normal one-form sign-in before reading or booting an account's
    // runtime. Only the verified account result can open its retained ledger.
    loginWindow=createLoginWindow({broker:accountLogin,quitting:()=>quitting,quit:()=>app.quit()});
    loginResult=await shutdown.run(()=>loginWindow.result);
    if(quitting)return;
    activeAccount=loginResult.account;localRuntime=loginResult.runtime;
    // The shutdown owner is installed before the broker retires other native
    // services, including a quit that races this asynchronous handoff.
    await shutdown.run(()=>accountLogin.handoff(loginResult));
    if(quitting)return;
  }else activeAccount=await accountProfiles.selected();
  const profile=activeAccount.profile;fs.mkdirSync(profile,{recursive:true});startupPhase('ledger-open');
  store=new Store(path.join(profile,'fasakhansta-pos.sqlite'));sync=new Sync(store,request);
  const allowed=new Set(['index.html','renderer.js','style.css','domain.js','startup.html']);
  protocol.handle('fasakhansta',r=>{const u=new URL(r.url),name=u.pathname.slice(1);if(u.hostname!=='pos'||!allowed.has(name))return new Response('',{status:404});return net.fetch(pathToFileURL(path.join(__dirname,name)).toString());});
  win=new BrowserWindow({width:1380,height:870,minWidth:1000,minHeight:650,show:false,fullscreen:true,title:'GO ERP',icon:path.join(__dirname,'assets','app.ico'),backgroundColor:'#f5f5f7',webPreferences:{preload:path.join(__dirname,'preload.cjs'),nodeIntegration:false,contextIsolation:true,sandbox:true}});
  win.removeMenu();
  win.webContents.on('page-title-updated',event=>event.preventDefault());
  win.on('close',e=>{if(!quitting){e.preventDefault();app.quit();}});
  win.webContents.setWindowOpenHandler(()=>({action:'deny'}));win.webContents.on('will-navigate',(e,url)=>{if(!url.startsWith('fasakhansta://pos/'))e.preventDefault();});
  win.webContents.session.setPermissionRequestHandler((_wc,_permission,callback)=>callback(false));
  // This trusted page is only the hidden IPC bridge for the retained POS
  // ledger. The first visible app window is the original dashboard document.
  handle('state',()=>state());handle('new',()=>{writable();if(store.openOrders().filter(o=>o.status==='draft').length>=5)throw Error('الحد الأقصى ٥ فواتير مفتوحة؛ أكمل واحدة أولًا.');return store.newOrder();});
  handle('discard',id=>{writable();return store.discard(id);});
  handle('order',id=>store.order(id));handle('update',(id,data)=>{writable();return store.update(id,data);});
  handle('action',(id,kind,reason)=>{writable();const e=store.dispatch(id,kind,reason);notify();return e;});handle('print',id=>printEvent(id));
  handle('sync',()=>{syncSchedule.request().catch(()=>{});notify();return state();});
  handle('pair',async(url,code)=>{
    writable();
    if(store.get('connection'))throw Error('الجهاز مرتبط بالفعل. حافظ على بياناته وعملياته.');
    if(!safeStorage.isEncryptionAvailable())throw Error('تعذر حفظ ربط الجهاز بشكل آمن في حساب ويندوز الحالي.');
    const o=origin(url), c=await request('POST','pair',{code:String(code)},{origin:o});
    store.set('connection',{origin:o,device_id:c.device_id,token_cipher:safeStorage.encryptString(c.token).toString('base64')});
    await sync.run({bootstrapOnly:true});notify();return state();
  });
  handle('printers',()=>win.webContents.getPrintersAsync());handle('printer',name=>{store.set('printer',String(name));return true;});
  handle('dashboard',async()=>{await dashboard.open();return true;});
  handle('backup',async()=>{
    const r=await dialog.showSaveDialog(win,{title:'نسخة من سجل عمليات الجهاز',defaultPath:'GO-ERP-Backup.sqlite',filters:[{name:'SQLite',extensions:['sqlite']}]});
    if(r.canceled)return false;const {backup}=require('node:sqlite');await backup(store.db,r.filePath);return true;
  });
  startupPhase('splash-loading');await win.loadURL('fasakhansta://pos/startup.html');
  if(quitting)return;
  startupPhase('splash-loaded');
  let dashboardOrigin=loginResult?.origin||(!app.isPackaged&&process.env.POS_TEST_DASHBOARD_ORIGIN?new URL(process.env.POS_TEST_DASHBOARD_ORIGIN).origin:origin(store.get('connection')?.origin||dashboardPolicy.DEFAULT_ORIGIN));
  let localToken='';
  if(bundledRuntime) {
    remoteState=new DashboardRemoteState(localRuntime.metadata);
    liveSocial=new DashboardLiveSocial({runtime:localRuntime,sourceSession:()=>dashboard?.session(),
      createSession:partition=>session.fromPartition(partition),createWindow:options=>new BrowserWindow(options),quitting:()=>quitting||installingUpdate,
      confirmMicrophone:async window=>(await dialog.showMessageBox(window,{type:'question',title:'GO ERP',message:'السماح للرسائل المتصلة باستخدام الميكروفون لتسجيل رسالة صوتية؟',buttons:['السماح','إلغاء'],defaultId:1,cancelId:1})).response===0,
      notify:message=>{if(!quitting)dialog.showErrorBox('GO ERP',message);}});
    connectedAccess=new DashboardConnected({runtime:localRuntime,view:()=>dashboard,mode:()=>dashboardMode,generations:()=>generations,remoteState,
      remoteAttempts:{recover:()=>remoteAttempts.recover()},probe:dashboardSession,
      synchronize:async()=>{await syncSchedule.request();const counts=await localRuntime.control({action:'pending'});if(counts.counts.pending||counts.counts.conflicts)throw Error('توجد عمليات محلية معلقة أو متعارضة؛ أكمل مراجعتها ومزامنتها قبل الداشبورد المتصلة.');},
      onState:()=>dashboardState().then(value=>dashboard?.publish(value)).catch(()=>{})});
    outboxReview=new DashboardOutboxReview({runtime:localRuntime,dialog,synchronize:async()=>{syncSchedule.request().catch(()=>{});}});
    remoteAttempts=new DashboardRemoteAttempts({state:remoteState,metadata:localRuntime.metadata,
      credential:async()=>{if(!(await localRuntime.isPrepared()))return null;return localRuntime.connection();},
      request:(value,credential)=>dashboardRequest(value,false,credential,'remote-attempts'),direct:details=>connectedAccess.authorizeDirect(details)});
    connectedReview=new DashboardConnectedReview({attempts:remoteAttempts,connected:connectedAccess,runtime:localRuntime,dialog});
    preparation=new DashboardPreparation({runtime:localRuntime,enroll:dashboardEnrollment,download:value=>dashboardRequest(null,true,value),
      allowPreview:app.isPackaged&&/-preview\.[1-9][0-9]*$/.test(app.getVersion()),
      beforePrepare:async()=>{if(store.get('connection'))await syncSchedule.request();const old=store.counts();if(old.pending||store.openOrders().length)throw Error('أكمل فواتير النسخة السابقة وأكد مزامنتها قبل تجهيز الداشبورد؛ جميعها محفوظة.');},
      onState:()=>{dashboardState().then(value=>dashboard?.publish(value)).catch(()=>{});},
      onEnrolled:value=>accountProfiles.bind(activeAccount.id,{actorId:value.actorId,serverOrigin:value.serverOrigin}),
      onPrepared:async(value,details)=>{
        await accountProfiles.bind(activeAccount.id,{actorId:value.actorId,serverOrigin:value.serverOrigin,...(details?.accountLabel?{label:details.accountLabel}:{})});
        for(const name of details?.accountLogins||[])await accountProfiles.rememberLogin(activeAccount.id,
          {actorId:value.actorId,serverOrigin:value.serverOrigin},name);
        connectDashboardSync();
      }});
    startupPhase('prepared-check');if(await localRuntime.isPrepared()) {
      await accountProfiles.assertBinding(activeAccount.id,await localRuntime.connection());
      startupPhase('runtime-start');
      const local=await shutdown.run(()=>localRuntime.start());
      if(quitting)return;
      startupPhase('runtime-ready');startupPhase('remote-state');
      const remote=await remoteState.read();
      startupPhase('central-selection');
      const centralSelected=await connectedAccess.selected({recover:true});
      if(quitting)return;
      if(remote.dirty||remote.pending.length||centralSelected){dashboardOrigin=(await localRuntime.connection()).serverOrigin;localToken='';}
      else {dashboardOrigin=local.origin;localToken=local.token;}
      // Upgrade an older retained source through the original generation/delta
      // protocol before a business view exists. Later opens of the same source
      // do not contact the server or capture the database here.
      if(localToken){
        connectUpdater(profile);
        if(updater){
          const controller=new AbortController();requests.add(controller);
          try{
            await shutdown.run(async()=>{
              const metadata=localRuntime.metadata;
              const recovery=new DashboardGeneration({runtime:localRuntime,metadata,
                download:()=>dashboardRequest(null,true),
                allowPreview:app.isPackaged&&/-preview\.[1-9][0-9]*$/.test(app.getVersion())});
              const installedManifest=await new RuntimeArchive(profile,metadata).manifest(localRuntime.installedBundle);
              // Use the original capabilities to recover an interrupted local
              // return before a new generation is considered. The normal
              // dashboard recovery below remains authoritative on every path.
              await recovery.recover();
              const startupMode=new DashboardMode({runtime:localRuntime,generations:recovery,remoteState,remoteAttempts});
              await startupMode.recover();
              if(quitting)return;
              startupPhase('source-upgrade-check');
              const result=await upgradeStartupSource({runtime:localRuntime,metadata,installedManifest,
                online:()=>net.isOnline(),viewAbsent:()=>!dashboard&&!startupComplete&&!quitting,
                inspectIdle:()=>inspectStartupIdle({store,runtime:localRuntime,metadata}),remoteState,
                authorizeInstalled:(manifest,options)=>updater.authorizeInstalled(manifest,options),
                download:dashboardDeltaRequest,recover:()=>recovery.recover(),signal:controller.signal,
                allowPreview:app.isPackaged&&/-preview\.[1-9][0-9]*$/.test(app.getVersion()),
                onState:value=>startupPhase('source-upgrade-'+value.phase)});
              startupPhase('source-upgrade-'+result.status);
              dashboardOrigin=localRuntime.origin;localToken=localRuntime.token;
            });
          }finally{requests.delete(controller);}
        }
      }
    }
  }
  if(quitting)return;
  startupPhase('dashboard-create');
  dashboard=createDashboard({origin:dashboardOrigin,localToken,partition:activeAccount.partition,switchAccount,
    beforeLogout:localRuntime?()=>accountSignout.signedOut(localRuntime):undefined,
    serverOrigin:localToken?(await localRuntime.connection()).serverOrigin:dashboardOrigin,
    onVisible:dashboardShown,onNavigation:dashboardNavigation,
    prepare:preparation?async(serverOrigin,csrf)=>{
      const ready=await remoteState.snapshot(()=>preparation.prepare(serverOrigin,csrf));
      // The encrypted snapshot gate is clean only after preparation returns.
      // Start using original local forms immediately, even while online.
      if(ready.prepared)await dashboardMode.local({method:'GET'});
      return ready;
    }:undefined,status:dashboardState,remoteState,remoteAttempts,
    liveSocial:liveSocial?kind=>liveSocial.open(kind):undefined,
    connected:connectedAccess?async()=>{if(!(await localRuntime.isPrepared()))return dashboard.open();return connectedAccess.open();}:undefined,
    reauthenticate:connectedAccess?()=>connectedAccess.reauthenticate():undefined,
    localMode:connectedAccess?()=>connectedAccess.local():undefined,
    reviewCentral:connectedReview?window=>connectedReview.open(window):undefined,
    browse:connectedAccess?()=>connectedAccess.browse():undefined,
    printControl:localRuntime?value=>{
      if(!localRuntime.supportsPrintEffects())throw Error('الطباعة المحلية تحتاج اكتمال تحديث نسخة بيانات الجهاز.');
      return localRuntime.control(value);
    }:undefined,printMetadata:localRuntime?.metadata,
    synchronize:()=>{syncSchedule.request({refresh:true}).catch(()=>{});return dashboardState();},
    archive:localRuntime?()=>localOrders():undefined,
    updateStatus:()=>updater?.state||{status:'unavailable',interval_seconds:60},
    reviewSync:outboxReview?async window=>{
      if(!(await localRuntime.isPrepared()))throw Error('جهّز الجهاز أولًا لمراجعة سجل المزامنة.');
      return outboxReview.open(window);
    }:undefined,
    selectPrinter:async window=>{
      const printers=await window.webContents.getPrintersAsync();
      if(!printers.length)throw Error('لا توجد طابعة متاحة على الجهاز.');
      const names=printers.map(value=>value.name),cancel=names.length;
      const result=await dialog.showMessageBox(window,{type:'question',title:'GO ERP — اختيار الطابعة',message:'اختر الطابعة المستخدمة للفواتير',
        buttons:[...names,'إلغاء'],defaultId:Math.max(0,names.indexOf(store.get('printer'))),cancelId:cancel,noLink:true});
      if(result.response<cancel)store.set('printer',names[result.response]);
    },
    accepted:fn=>shutdown.run(fn),offline:dashboardOffline,offlineWindow:()=>win,quitting:()=>quitting,quit:()=>app.quit(),printer:()=>{if(quitting)throw Error('البرنامج يُغلق الآن.');return store.get('printer')||'';}});
  startupPhase('recovery-check');if(localRuntime&&await localRuntime.isPrepared()){
    connectDashboardSync();await shutdown.run(async()=>{startupPhase('generation-recovery');await generations.recover();
      if(localToken){startupPhase('local-fence-recovery');await dashboardMode.recover();}
      else{startupPhase('central-fence-recovery');await dashboardMode.hold();}});
  }
  startupPhase('dashboard-navigation');
  await dashboard.open();if(quitting)return;
  startupPhase('dashboard-open-settled');hideStartup();startupPhase('startup-complete');startupComplete=true;syncSchedule.start();connectUpdater(profile);
}).catch(error=>{if(!quitting){startupPhase('startup-failed');dialog.showErrorBox('تعذر فتح برنامج GO ERP',error.message);app.quit();}});
// Opening GO ERP again must not expose the hidden POS bridge or a blank first
// dashboard document while the verified local services are still starting.
app.on('second-instance',()=>{
  if(quitting)return;
  if(loginWindow){loginWindow.reveal();return;}
  if(!startupComplete){dashboard?.reveal();return;}
  if(dashboard)dashboard.reveal();else localOrders();
});
