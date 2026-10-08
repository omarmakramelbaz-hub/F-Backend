const {app,BrowserWindow,ipcMain,protocol,net,safeStorage,dialog}=require('electron');
const fs=require('node:fs');const path=require('node:path');const {pathToFileURL}=require('node:url');
const Store=require('./store.cjs'), Sync=require('./sync.cjs'), receipt=require('./receipt.cjs');
const Shutdown=require('./shutdown.cjs');
const LocalRuntime=require('./local-runtime.cjs');
const DashboardSync=require('./dashboard-sync.cjs');
const {DashboardGeneration}=require('./dashboard-generation.cjs');
const {DashboardPreparation}=require('./dashboard-preparation.cjs');
const readSnapshot=require('./dashboard-download.cjs');
const createDashboard=require('./dashboard.cjs'),dashboardPolicy=require('./dashboard-policy.cjs');
protocol.registerSchemesAsPrivileged([{scheme:'fasakhansta',privileges:{standard:true,secure:true,supportFetchAPI:true}}]);
const primaryInstance=Boolean(process.env.POS_TEST_PROFILE&&!app.isPackaged)||app.requestSingleInstanceLock();
if(!primaryInstance)app.quit();
let win,store,sync,dashboard,localRuntime,localSync,generations,preparation,timer,retryMs=5000,quitting=false;
const requests=new Set();
const shutdown=new Shutdown(app,()=>{quitting=true;clearTimeout(timer);sync?.stop();localSync?.stop();for(const controller of requests)controller.abort();},()=>store?.close(),async()=>localRuntime?.stop());
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
  const c=store.get('connection');return {paired:Boolean(c),origin:c?.origin||'',snapshot:store.snapshot(),orders:store.openOrders(),history:store.history(),counts:store.counts(),online:sync.online,error:sync.error,last_synced:store.get('last_synced'),printer:store.get('printer')||''};
}
async function dashboardRequest(command, bootstrap=false, suppliedCredential) {
  const credential=suppliedCredential||await localRuntime.connection();
  const controller=new AbortController();requests.add(controller);
  try{
    const response=await net.fetch(origin(credential.serverOrigin)+'/api/desktop-dashboard/'+(bootstrap?'bootstrap':'commands'),{
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
  return {...await preparation.status(),mode:dashboard?.current().local?'local':'server',...(localSync?.state||{})};
}
function connectDashboardSync() {
  if(localSync)return;
  generations=new DashboardGeneration({runtime:localRuntime,metadata:localRuntime.metadata,download:()=>dashboardRequest(null,true),
    transition:work=>dashboard.current().local?dashboard.refresh(work):work()});
  localSync=new DashboardSync({local:value=>localRuntime.control(value),remote:dashboardRequest,refresh:()=>generations.run(),
    onState:()=>{dashboardState().then(value=>dashboard?.publish(value)).catch(()=>{});}});
}
function notify() {if(!quitting&&win&&!win.isDestroyed())win.webContents.send('pos:state',state());}
function localOrders(message='') {if(!quitting&&win&&!win.isDestroyed()){win.show();if(win.isMinimized())win.restore();win.focus();if(message)win.webContents.send('pos:notice',message);}}
function writable() {if(store.get('authorization_blocked'))throw Error('ربط الجهاز متوقف من الإدارة. العمليات السابقة محفوظة؛ يلزم إعادة تفعيل الربط.');}
async function tick() {
  if(!primaryInstance||quitting)return;
  try{await shutdown.run(async()=>{
    if(localSync){const result=await localSync.run();retryMs=result.error?Math.min(retryMs*2,60000):5000;return;}
    if(store.get('connection')) {await sync.run();if(quitting)return;if(sync.online&&!sync.error){retryMs=5000;store.set('last_synced',new Date().toISOString());}else retryMs=Math.min(retryMs*2,60000);notify();}
  });}catch(error){if(!quitting){sync.error=error.message;notify();}}
  if(!quitting){clearTimeout(timer);timer=setTimeout(tick,retryMs);}
}
function handle(name,fn) {
  ipcMain.handle('pos:'+name,async(e,...args)=>{if(e.sender!==win.webContents||!e.senderFrame.url.startsWith('fasakhansta://pos/'))throw Error('مصدر غير مسموح.');try{return {ok:true,value:await shutdown.run(()=>fn(...args))};}catch(error){return {ok:false,error:error.message};}});
}
async function printEvent(id) {
  const event=store.event(id), order=store.order(event.order_id);
  if(!['kitchen','bill','sale'].includes(event.kind))throw Error('هذه العملية لا تحتاج طباعة.');
  const w=new BrowserWindow({show:false,width:420,height:700,webPreferences:{sandbox:true,contextIsolation:true,nodeIntegration:false}});
  try {
    const printers=await win.webContents.getPrintersAsync();if(!printers.length)throw Error('لا توجد طابعة متاحة. الفاتورة محفوظة ويمكن إعادة طباعتها.');
    await w.loadURL('data:text/html;charset=utf-8,'+encodeURIComponent(receipt(event,order.snapshot)));
    await w.webContents.executeJavaScript('document.fonts.ready.then(()=>true)');
    const deviceName=store.get('printer')||'';if(deviceName&&!printers.some(p=>p.name===deviceName))throw Error('الطابعة المختارة غير متاحة. الفاتورة محفوظة.');
    await new Promise((resolve,reject)=>w.webContents.print({silent:true,printBackground:true,deviceName,margins:{marginType:'none'}},(ok,error)=>ok?resolve():reject(Error('فشلت الطباعة: '+error))));
    store.markPrinted(id);notify();
  }finally{w.destroy();}
}
app.whenReady().then(async()=>{
  if(!primaryInstance||quitting)return;
  const profile=process.env.POS_TEST_PROFILE&&!app.isPackaged?process.env.POS_TEST_PROFILE:app.getPath('userData');fs.mkdirSync(profile,{recursive:true});
  store=new Store(path.join(profile,'fasakhansta-pos.sqlite'));sync=new Sync(store,request);
  const allowed=new Set(['index.html','renderer.js','style.css','domain.js']);
  protocol.handle('fasakhansta',r=>{const u=new URL(r.url),name=u.pathname.slice(1);if(u.hostname!=='pos'||!allowed.has(name))return new Response('',{status:404});return net.fetch(pathToFileURL(path.join(__dirname,name)).toString());});
  win=new BrowserWindow({width:1380,height:870,minWidth:1000,minHeight:650,show:false,title:'فسخانستا — الطلبات المحلية',icon:path.join(__dirname,'assets','app.ico'),backgroundColor:'#f5f5f7',webPreferences:{preload:path.join(__dirname,'preload.cjs'),nodeIntegration:false,contextIsolation:true,sandbox:true}});
  win.on('close',e=>{if(!quitting){e.preventDefault();app.quit();}});
  win.webContents.setWindowOpenHandler(()=>({action:'deny'}));win.webContents.on('will-navigate',(e,url)=>{if(!url.startsWith('fasakhansta://pos/'))e.preventDefault();});
  win.webContents.session.setPermissionRequestHandler((_wc,_permission,callback)=>callback(false));
  handle('state',()=>state());handle('new',()=>{writable();if(store.openOrders().filter(o=>o.status==='draft').length>=5)throw Error('الحد الأقصى ٥ فواتير مفتوحة؛ أكمل واحدة أولًا.');return store.newOrder();});
  handle('discard',id=>store.discard(id));
  handle('order',id=>store.order(id));handle('update',(id,data)=>store.update(id,data));
  handle('action',(id,kind,reason)=>{writable();const e=store.dispatch(id,kind,reason);notify();return e;});handle('print',id=>printEvent(id));
  handle('sync',async()=>{await sync.run();notify();return state();});
  handle('pair',async(url,code)=>{
    if(store.get('connection'))throw Error('الجهاز مرتبط بالفعل. حافظ على بياناته وعملياته.');
    if(!safeStorage.isEncryptionAvailable())throw Error('تعذر حفظ ربط الجهاز بشكل آمن في حساب ويندوز الحالي.');
    const o=origin(url), c=await request('POST','pair',{code:String(code)},{origin:o});
    store.set('connection',{origin:o,device_id:c.device_id,token_cipher:safeStorage.encryptString(c.token).toString('base64')});
    await sync.run();notify();return state();
  });
  handle('printers',()=>win.webContents.getPrintersAsync());handle('printer',name=>{store.set('printer',String(name));return true;});
  handle('dashboard',async()=>{await dashboard.open();return true;});
  handle('backup',async()=>{
    const r=await dialog.showSaveDialog(win,{title:'نسخة من سجل عمليات الجهاز',defaultPath:'Fasakhansta-POS-Backup.sqlite',filters:[{name:'SQLite',extensions:['sqlite']}]});
    if(r.canceled)return false;const {backup}=require('node:sqlite');await backup(store.db,r.filePath);return true;
  });
  await win.loadURL('fasakhansta://pos/index.html');
  if(quitting)return;
  let dashboardOrigin=!app.isPackaged&&process.env.POS_TEST_DASHBOARD_ORIGIN?new URL(process.env.POS_TEST_DASHBOARD_ORIGIN).origin:origin(store.get('connection')?.origin||dashboardPolicy.DEFAULT_ORIGIN);
  let localToken='';
  const localBundle=path.join(process.resourcesPath||'', 'dashboard-runtime');
  if(app.isPackaged&&fs.existsSync(path.join(localBundle,'manifest.json'))) {
    localRuntime=new LocalRuntime({bundle:localBundle,profile,safeStorage,downloadMedia:dashboardMediaRequest,onFailure:()=>{if(!quitting)dialog.showErrorBox('فسخانستا','خدمة الداشبورد المحلية توقفت. بيانات الجهاز محفوظة؛ أعد فتح البرنامج.');}});
    preparation=new DashboardPreparation({runtime:localRuntime,enroll:dashboardEnrollment,download:value=>dashboardRequest(null,true,value),
      onState:()=>{dashboardState().then(value=>dashboard?.publish(value)).catch(()=>{});},onPrepared:async()=>{connectDashboardSync();}});
    if(await localRuntime.isPrepared()) {
      const local=await shutdown.run(()=>localRuntime.start());dashboardOrigin=local.origin;localToken=local.token;
    }
  }
  if(quitting)return;
  dashboard=createDashboard({origin:dashboardOrigin,localToken,serverOrigin:localToken?(await localRuntime.connection()).serverOrigin:dashboardOrigin,
    prepare:preparation?(serverOrigin,csrf)=>preparation.prepare(serverOrigin,csrf):undefined,status:dashboardState,
    synchronize:async()=>{if(localSync)await localSync.run();return dashboardState();},
    accepted:fn=>shutdown.run(fn),offline:localOrders,offlineWindow:()=>win,quitting:()=>quitting,quit:()=>app.quit(),printer:()=>{if(quitting)throw Error('البرنامج يُغلق الآن.');return store.get('printer')||'';}});
  if(localToken){connectDashboardSync();await shutdown.run(()=>generations.recover());}
  tick();await dashboard.open();
}).catch(error=>{if(!quitting){dialog.showErrorBox('تعذر فتح برنامج فسخانستا',error.message);app.quit();}});
app.on('second-instance',()=>{if(quitting)return;if(dashboard?.isVisible())dashboard.reveal();else localOrders();});
