const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path'),vm=require('node:vm');
const {EventEmitter}=require('node:events');
const Store=require('../src/store.cjs');
const Shutdown=require('../src/shutdown.cjs');
const Sync=require('../src/sync.cjs');
const SyncSchedule=require('../src/sync-schedule.cjs');
const readSnapshot=require('../src/dashboard-download.cjs');
const {randomUUID}=require('node:crypto');
const settle=async()=>{for(let i=0;i<20;i++)await Promise.resolve();await new Promise(setImmediate);};

class LifecycleApp extends EventEmitter{
  constructor(){super();this.quitCalls=0;this.exited=false;this.relaunchCalls=0;}
  quit(){
    if(this.exited)return;
    this.quitCalls++;
    const event={prevented:false,preventDefault(){this.prevented=true;}};
    this.emit('before-quit',event);
    if(!event.prevented){this.emit('will-quit');this.exited=true;}
  }
  relaunch(){this.relaunchCalls++;}
}

test('a throwing cancellation hook still retains accepted work and reaches final cleanup after drain fails',async()=>{
  const app=new LifecycleApp(),order=[];let release;
  const stopError=Error('cancellation failed'),drainError=Error('native cleanup failed');
  const shutdown=new Shutdown(app,()=>{order.push('stop');throw stopError;},()=>order.push('close'),async()=>{order.push('drain');throw drainError;});
  const accepted=shutdown.run(async()=>{await new Promise(resolve=>release=resolve);order.push('accepted-write');});
  await settle();assert.doesNotThrow(()=>app.quit());await settle();
  assert.equal(app.exited,false);assert.deepEqual(order,['stop']);
  await assert.rejects(shutdown.run(()=>order.push('rejected-write')),/يُغلق/);
  release();await accepted;await settle();
  assert.equal(app.exited,true);assert.deepEqual(order,['stop','accepted-write','drain','close']);
  assert.deepEqual(shutdown.errors,[{phase:'stop',error:stopError},{phase:'drain',error:drainError}]);
});

test('asynchronous cancellation finishes before runtime drain and its rejection cannot strand quit',async()=>{
  const app=new LifecycleApp(),order=[];let rejectCancellation;
  const error=Error('async cancellation failed');
  const shutdown=new Shutdown(app,()=>new Promise((_resolve,reject)=>rejectCancellation=reject),()=>order.push('close'),async()=>order.push('drain'));
  app.quit();app.quit();await settle();assert.equal(app.exited,false);assert.deepEqual(order,[]);
  rejectCancellation(error);await settle();
  assert.equal(app.exited,true);assert.deepEqual(order,['drain','close']);
  assert.deepEqual(shutdown.errors,[{phase:'stop',error}]);
});

test('a failed final connection close returns to Electron quit and suppresses a queued relaunch',async()=>{
  const app=new LifecycleApp(),error=Error('connection close failed');let closes=0;
  const shutdown=new Shutdown(app,()=>{},()=>{closes++;throw error;});
  app.quit();app.emit('second-instance');await settle();
  assert.equal(app.exited,true);assert.equal(closes,1);assert.equal(app.relaunchCalls,0);
  assert.deepEqual(shutdown.errors,[{phase:'close',error}]);
});
test('an explicit account relaunch waits for accepted work, native drain and final ledger close',async()=>{
  const app=new LifecycleApp(),events=[];let release;
  app.relaunch=()=>{events.push('relaunch');app.relaunchCalls++;};
  const shutdown=new Shutdown(app,()=>events.push('stop'),()=>events.push('close'),async()=>events.push('drain'));
  const accepted=shutdown.run(()=>new Promise(resolve=>release=resolve));await settle();
  shutdown.requestRelaunch();app.quit();await settle();assert.equal(app.relaunchCalls,0);
  assert.throws(()=>shutdown.requestRelaunch(),/إعادة تشغيل/);
  release();await accepted;await settle();assert.deepEqual(events,['stop','drain','close','relaunch']);assert.equal(app.exited,true);
});
test('a failed native relaunch cannot strand the drained process after its ledger closes',async()=>{
  const app=new LifecycleApp(),error=Error('native relaunch failed');let closed=false;
  app.relaunch=()=>{assert.equal(closed,true);throw error;};
  const shutdown=new Shutdown(app,()=>{},()=>{closed=true;});shutdown.requestRelaunch();app.quit();await settle();
  assert.equal(app.exited,true);assert.deepEqual(shutdown.errors,[{phase:'relaunch',error}]);
});

async function fixture(t,{paired=false,packaged=false,prepared=false,fetch,holdPrint=false,holdLoad=false,holdDashboardLoad=false,holdRuntimeStart=false,runtimeStartError,holdLogin=false,holdLoginHandoff=false,loginAccountId='default',loginOrigin='https://fasakhaninja.com',loginToken='',remoteDirty=false,brokerStopError,nativeClock=false,deltaBridge=false,deltaRun,runtimeControl,beforeUnload=false,holdRuntimeStop=false,windowsHost=false,holdCentralSelection=false}={}){
  const profile=fs.mkdtempSync(path.join(os.tmpdir(),'pos-shutdown-')),file=path.join(profile,'fasakhansta-pos.sqlite');
  const seed=new Store(file),snapshot={id:randomUUID(),generated_at:new Date().toISOString(),branch:{value:'f:100',name:'اختبار'},actor:{id:10,name:'كاشير'},tax_bps:0,service_bps:0,can_discount:false,categories:[],products:[{id:1,name:'رنجة',unit:'piece',variants:[{option_id:'',label:'أساسي',unit_price_cents:10000,quantity_mode:'piece'}]}]};
  seed.saveSnapshot(snapshot);const order=seed.newOrder();seed.update(order.id,{...order.data,items:[{product_id:1,option_id:'',quantity_mode:'piece',quantity:'1'}],cash_received:'100.00'});const sale=seed.dispatch(order.id,'sale');
  if(paired)seed.set('connection',{origin:'https://fasakhaninja.com',device_id:1,token_cipher:Buffer.from('test-token').toString('base64')});seed.close();
  if(packaged){fs.mkdirSync(path.join(profile,'dashboard-runtime'));fs.writeFileSync(path.join(profile,'dashboard-runtime','manifest.json'),'{}');}
  const windows=[],handlers=new Map(),timers=new Map(),nativeTimers=new Map(),events=[],errors=[];let store,selectedLedgerFile,loginBrokerOptions,loginVisible=false,loginDisposals=0,loginReveals=0,loginCancels=0,brokerCancels=0,brokerStops=0,brokerHandoffs=0,finishLogin,finishLoginHandoff,rejectLogin,loginAdmission,printCallback,finishLoad,finishRuntimeStart,finishDashboardLoad,finishRuntimeStop,runtimeStarts=0,runtimeStops=0,dashboardOptions,preparationOptions,deltaOptions,deltaStops=0,dashboardOpens=0,dashboardReveals=0,dashboardCancels=0,runtimeReady=prepared,localSwitches=0,scheduleNow=0;
  class ScheduledClock extends SyncSchedule{constructor(options){super({...options,now:()=>scheduleNow});}}
  class TrackedStore extends Store{constructor(file){super(file);store=this;selectedLedgerFile=file;events.push('ledger-open');this.closeCalls=0;this.historyReads=0;}history(){this.historyReads++;return super.history();}close(){this.closeCalls++;super.close();}}
  class App extends EventEmitter{
    constructor(){super();this.isPackaged=packaged;this.quitCalls=0;this.exited=false;this.relaunchCalls=0;}
    getVersion(){return '0.3.0-preview.1';}setName(value){this.displayName=value;}setPath(name,value){(this.paths||=new Map()).set(name,value);}
    whenReady(){return Promise.resolve();}getPath(){return profile;}requestSingleInstanceLock(){return true;}
    relaunch(){assert.equal(store?.db.isOpen??false,false,'Relaunch is scheduled only after SQLite closes or before an account ledger is opened.');events.push('app-relaunch');this.relaunchCalls++;}
    quit(){if(this.exited)return;if(++this.quitCalls>10)throw Error('recursive quit');const event={prevented:false,preventDefault(){this.prevented=true;}};this.emit('before-quit',event);if(event.prevented)return;
      for(const window of [...windows])if(!window.destroyed)window.close();if(windows.some(window=>!window.destroyed))return;this.emit('will-quit',{});this.exited=true;}
  }
  const app=new App();
  let finishCentralSelection,updaterStarts=0,updaterConstructions=0;
  class Updater {
    constructor(){updaterConstructions++;this.state={status:'idle'};}
    start(){updaterStarts++;return Promise.resolve(this.state);}
    stop(){}
  }
  class Window extends EventEmitter{
    constructor(options={}){super();windows.push(this);this.destroyed=false;this.visible=Boolean(options.show);this.loadURLs=[];this.sent=[];this.showCalls=0;this.hideCalls=0;this.focusCalls=0;this.restoreCalls=0;this.webContents=new EventEmitter();this.webContents.session={setPermissionRequestHandler(){}};this.webContents.setWindowOpenHandler=()=>{};
      this.webContents.send=(...args)=>this.sent.push(args);this.webContents.getPrintersAsync=async()=>[{name:'Printer'}];this.webContents.executeJavaScript=async()=>true;
      this.webContents.print=(_options,callback)=>{printCallback=callback;if(!holdPrint)callback(true);};app.emit('web-contents-created',{},this.webContents);}
    async loadURL(url){this.url=url;this.loadURLs.push(url);events.push('load:'+url);if(holdLoad&&url.startsWith('fasakhansta:'))await new Promise(resolve=>{finishLoad=resolve;});}
    removeMenu(){}
    show(){this.visible=true;this.showCalls++;events.push('window-show');}hide(){this.visible=false;this.hideCalls++;events.push('window-hide');}focus(){this.focusCalls++;}restore(){this.minimized=false;this.restoreCalls++;}isMinimized(){return Boolean(this.minimized);}isDestroyed(){return this.destroyed;}
    close(){const event={prevented:false,preventDefault(){this.prevented=true;}};this.emit('close',event);if(event.prevented)return;
      if(beforeUnload){const unload={prevented:false,preventDefault(){this.prevented=true;}};this.webContents.emit('will-prevent-unload',unload);if(!unload.prevented)return;}this.destroy();}
    destroy(){if(this.destroyed)return;this.destroyed=true;this.emit('closed');if(windows.every(w=>w.destroyed))app.emit('window-all-closed');}
  }
  const electron={app,BrowserWindow:Window,ipcMain:{handle:(name,fn)=>handlers.set(name,fn)},protocol:{registerSchemesAsPrivileged(){},handle(){}},net:{fetch:fetch||(async()=>new Response('{}'))},safeStorage:{decryptString:buffer=>buffer.toString(),isEncryptionAvailable:()=>true,encryptString:value=>Buffer.from(value)},dialog:{showErrorBox:(title,message)=>errors.push({title,message}),showSaveDialog:async()=>({canceled:true})}};
  class UnpreparedRuntime{
    constructor(options={}){this.profile=options.profile;this.deviceId=randomUUID();this.origin='http://127.0.0.1:45123';this.token='owned-local';this.metadata={read:async name=>{
      if(holdCentralSelection&&name==='central-mode')return new Promise(resolve=>finishCentralSelection=()=>resolve(null));
      return remoteDirty&&name==='remote-writes'?{format:1,dirty:true,pending:[]}:null;
    },write:async()=>{}};}
    async isPrepared(){return runtimeReady;}async start(){runtimeStarts++;events.push('runtime-start');if(holdRuntimeStart)await new Promise(resolve=>{finishRuntimeStart=resolve;});if(runtimeStartError)throw Error(runtimeStartError);return {origin:this.origin,token:this.token};}
    async connection(){return {serverOrigin:'https://fasakhaninja.com',deviceId:this.deviceId,actorId:10};}async stop(){runtimeStops++;if(holdRuntimeStop)await new Promise(resolve=>{finishRuntimeStop=resolve;});}
    async control(input){return runtimeControl?runtimeControl(input,this):{commands:[],counts:{pending:0,conflicts:0}};}
  }
  class Preparation{
    constructor(options){preparationOptions=options;}async status(){return {available:true,prepared:runtimeReady};}
    async prepare(){runtimeReady=true;await preparationOptions.onPrepared(await preparationOptions.runtime.connection());return {available:true,prepared:true};}
  }
  class Profiles{
    constructor({root}){this.root=root;}
    async selected(){return{id:'default',label:'Fixture account',profile:this.root,partition:'persist:fasakhansta-dashboard'};}
    async bind(){}async assertBinding(){}
  }
  // These downstream lifecycle fixtures admit a trusted broker result without
  // exercising authentication. The actual broker/UI have separate tests; held
  // admission below verifies main never opens an account ledger prematurely.
  class LoginBroker{
    constructor(options){loginBrokerOptions=options;events.push('login-broker');}
    async admission(){
      const account=loginAccountId==='default'?await loginBrokerOptions.profiles.selected():{
        id:loginAccountId,label:'Other verified fixture account',profile:path.join(profile,'accounts',loginAccountId),partition:'persist:fasakhansta-dashboard-'+loginAccountId};
      return {account,runtime:loginBrokerOptions.createRuntime(account),origin:loginOrigin,localToken:loginToken,local:false};
    }
    async handoff(result){brokerHandoffs++;events.push('login-handoff');if(holdLoginHandoff)await new Promise(resolve=>finishLoginHandoff=resolve);return result;}
    cancel(){brokerCancels++;events.push('login-broker-cancel');}
    async stop(){brokerStops++;events.push('login-broker-stop');if(brokerStopError)throw Error(brokerStopError);}
  }
  const loginUI=({broker})=>{
    loginVisible=true;events.push('login-form-visible');
    const result=new Promise((resolve,reject)=>{rejectLogin=reject;finishLogin=async()=>{loginAdmission=await broker.admission();resolve(loginAdmission);};});
    if(!holdLogin)finishLogin();
    return {result,dispose(){loginVisible=false;loginDisposals++;events.push('login-form-dispose');},
      cancel(){loginVisible=false;loginCancels++;rejectLogin(Error('Synthetic account login cancelled'));},reveal(){loginVisible=true;loginReveals++;}};
  };
  class DeltaBridge{
    constructor(options){deltaOptions=options;this.state={phase:'idle'};this.running=null;}
    run(){this.running=Promise.resolve().then(()=>deltaRun?deltaRun():{unchanged:true}).finally(()=>{this.running=null;});return this.running;}stop(){deltaStops++;}
  }
  const nativeDeadline=nativeClock?vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../src/native-deadline.cjs'),'utf8')+'\n;module.exports',{module:{exports:{}},setTimeout:(fn,delay)=>{nativeTimers.set(fn,delay);return fn;},clearTimeout:fn=>nativeTimers.delete(fn)}):require('../src/native-deadline.cjs');
  const mainState=vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../src/main.cjs'),'utf8')+'\n;({localOrders,notify,markInstallingUpdate(){installingUpdate=true;}})',{require:name=>name==='electron'?electron:name==='./account-profiles.cjs'?Profiles:name==='./account-login.cjs'?{AccountLogin:LoginBroker}:name==='./login-window.cjs'?loginUI:name==='./account-picker.cjs'?async()=>null:name==='./native-deadline.cjs'?nativeDeadline:name==='./store.cjs'?TrackedStore:name==='./sync-schedule.cjs'?ScheduledClock:name==='./dashboard-updater.cjs'&&windowsHost?{DashboardUpdater:Updater}:name==='./local-runtime.cjs'&&packaged?UnpreparedRuntime:name==='./dashboard-preparation.cjs'&&packaged?{DashboardPreparation:Preparation}:name==='./dashboard-delta.cjs'&&deltaBridge?{DashboardDelta:DeltaBridge}:name==='./dashboard.cjs'?options=>{dashboardOptions=options;let local=Boolean(options.localToken);return {cancelAuthentication:()=>{dashboardCancels++;},open:async()=>{dashboardOpens++;events.push('dashboard-open');options.onVisible?.();if(holdDashboardLoad)await new Promise(resolve=>{finishDashboardLoad=resolve;});events.push('dashboard-ready');options.onVisible?.();},isVisible:()=>true,reveal(){dashboardReveals++;options.onVisible?.();},publish(){},current:()=>({local}),switchTo:async(target,work=async()=>{})=>{await work();local=Boolean(target.localToken);localSwitches++;}};}:require(name.startsWith('./')?'../src/'+name.slice(2):name),__dirname:path.join(__dirname,'../src'),process:{env:{POS_TEST_PROFILE:profile},resourcesPath:profile,platform:windowsHost?'win32':undefined},URL,AbortController,AbortSignal,Response,Buffer,Promise,Set,console,setTimeout:(fn,delay)=>{timers.set(fn,delay);return fn;},clearTimeout:fn=>timers.delete(fn)});
  await settle();
  t.after(()=>{if(store?.db.isOpen)store.close();fs.rmSync(profile,{recursive:true,force:true});});
  return {app,get store(){return store;},get selectedLedgerFile(){return selectedLedgerFile;},get loginVisible(){return loginVisible;},get loginDisposals(){return loginDisposals;},get loginReveals(){return loginReveals;},get loginCancels(){return loginCancels;},get brokerCancels(){return brokerCancels;},get brokerStops(){return brokerStops;},get brokerHandoffs(){return brokerHandoffs;},get finishLogin(){return finishLogin;},get finishLoginHandoff(){return finishLoginHandoff;},get loginAdmission(){return loginAdmission;},get loginBrokerOptions(){return loginBrokerOptions;},profile,file,sale,order,windows,timers,nativeTimers,events,errors,get trace(){return JSON.parse(fs.readFileSync(path.join(profile,'lifecycle-trace.json'),'utf8'));},openLocalOrders:mainState.localOrders,notify:mainState.notify,markInstallingUpdate:mainState.markInstallingUpdate,get finishRuntimeStart(){return finishRuntimeStart;},get finishDashboardLoad(){return finishDashboardLoad;},get finishRuntimeStop(){return finishRuntimeStop;},get runtimeStarts(){return runtimeStarts;},get runtimeStops(){return runtimeStops;},get finishCentralSelection(){return finishCentralSelection;},get updaterStarts(){return updaterStarts;},get updaterConstructions(){return updaterConstructions;},async syncSlot(){assert.equal(timers.size,1);const [fn,delay]=[...timers][0];assert.equal(delay,30000);scheduleNow+=delay;timers.delete(fn);fn();await settle();},get localSwitches(){return localSwitches;},get dashboardOptions(){return dashboardOptions;},get preparationOptions(){return preparationOptions;},get deltaOptions(){return deltaOptions;},get deltaStops(){return deltaStops;},get dashboardOpens(){return dashboardOpens;},get dashboardReveals(){return dashboardReveals;},get dashboardCancels(){return dashboardCancels;},get printCallback(){return printCallback;},get finishLoad(){return finishLoad;},invoke:(name,...args)=>handlers.get('pos:'+name)({sender:windows[0].webContents,senderFrame:{url:'fasakhansta://pos/index.html'}},...args)};
}

test('quit during saved-mode selection cannot create an updater after cancellation already ran',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,windowsHost:true,holdCentralSelection:true});
  assert.equal(typeof f.finishCentralSelection,'function');
  assert.equal(f.dashboardOpens,0);
  f.app.quit();await settle();
  assert.equal(f.app.exited,true);
  assert.equal(f.runtimeStops,1);
  f.finishCentralSelection();await settle();
  assert.equal(f.updaterConstructions,0);
  assert.equal(f.updaterStarts,0);
  assert.equal(f.dashboardOpens,0);
  assert.equal(f.errors.length,0);
});

test('the packaged main presents ordinary login before selecting a ledger or starting any local service',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,holdLogin:true});
  assert.equal(f.loginVisible,true);assert.equal(f.store,undefined);assert.equal(f.selectedLedgerFile,undefined);
  assert.equal(f.runtimeStarts,0);assert.equal(f.windows.length,0);assert.equal(f.dashboardOpens,0);assert.equal(f.timers.size,0);
  assert.equal(f.trace.events.at(-1).phase,'startup-ready');assert.equal(f.events.at(-1),'login-form-visible');
  f.app.emit('second-instance');assert.equal(f.loginReveals,1);assert.equal(f.windows.length,0);
  f.app.quit();await settle();
  assert.equal(f.loginCancels,1);assert.equal(f.brokerCancels,1);assert.equal(f.brokerStops,1);
  assert.equal(f.app.exited,true);assert.equal(f.store,undefined);assert.equal(f.runtimeStops,0);assert.equal(f.errors.length,0);
  assert.equal(f.trace.events.at(-1).phase,'shutdown-complete');
  const retained=new Store(f.file);try{assert.equal(retained.pending()[0].id,f.sale.id);}finally{retained.close();}
});

test('reopening during login cancellation queues one clean relaunch even though no account ledger was opened',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,holdLogin:true});
  f.app.quit();f.app.emit('second-instance');f.app.emit('second-instance');await settle();
  assert.equal(f.app.exited,true);assert.equal(f.app.relaunchCalls,1);assert.equal(f.store,undefined);
  assert.equal(f.loginCancels,1);assert.equal(f.brokerStops,1);assert.equal(f.windows.length,0);assert.equal(f.dashboardOpens,0);
  assert.ok(f.events.indexOf('login-broker-stop')<f.events.indexOf('app-relaunch'));
});

test('only a trusted login result admits its account ledger and the normal form remains until real page visibility',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,holdLogin:true,holdRuntimeStart:true});
  assert.equal(f.store,undefined);await f.finishLogin();await settle();
  assert.equal(f.brokerHandoffs,1);assert.equal(f.selectedLedgerFile,f.file);assert.equal(f.runtimeStarts,1);
  assert.equal(f.loginVisible,true);assert.equal(f.loginDisposals,0);assert.equal(f.dashboardOpens,0);assert.equal(f.windows[0].visible,false);
  assert.ok(f.events.indexOf('login-form-visible')<f.events.indexOf('login-handoff'));
  assert.ok(f.events.indexOf('login-handoff')<f.events.indexOf('ledger-open'));
  assert.ok(f.events.indexOf('ledger-open')<f.events.indexOf('runtime-start'));
  f.finishRuntimeStart();await settle();
  assert.equal(f.dashboardOptions.origin,'http://127.0.0.1:45123');assert.equal(f.dashboardOptions.localToken,'owned-local');
  assert.equal(f.loginVisible,false);assert.equal(f.loginDisposals,1);assert.equal(f.dashboardOpens,1);assert.equal(f.timers.size,1);
  f.app.quit();await settle();assert.equal(f.runtimeStops,1);assert.equal(f.brokerStops,1);assert.equal(f.store.closeCalls,1);
});

test('verified ordinary login opens only the returned account ledger and partition while the previous sale remains intact',async t=>{
  const id='12345678-1234-4321-8765-123456789abc';
  const f=await fixture(t,{packaged:true,loginAccountId:id});
  const selected=path.join(f.profile,'accounts',id,'fasakhansta-pos.sqlite');
  assert.equal(f.selectedLedgerFile,selected);assert.equal(f.loginAdmission.runtime.profile,path.dirname(selected));
  assert.equal(f.dashboardOptions.partition,'persist:fasakhansta-dashboard-'+id);
  assert.equal(f.dashboardOptions.origin,'https://fasakhaninja.com');assert.equal(f.dashboardOptions.localToken,'');
  assert.equal(f.store.pending().length,0);assert.equal(f.loginDisposals,1);assert.equal(f.brokerHandoffs,1);
  const previous=new Store(f.file);try{assert.equal(previous.pending()[0].id,f.sale.id);assert.equal(previous.order(f.order.id).status,'paid');}finally{previous.close();}
  f.app.quit();await settle();assert.equal(f.app.exited,true);assert.equal(f.store.closeCalls,1);
});

test('a quit racing trusted runtime handoff waits for ownership and drains without opening any account ledger',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,holdLoginHandoff:true});
  assert.equal(f.brokerHandoffs,1);assert.equal(f.store,undefined);assert.equal(f.runtimeStarts,0);assert.equal(f.loginVisible,true);
  f.app.quit();await settle();assert.equal(f.app.exited,false);assert.equal(f.runtimeStops,0);assert.equal(f.brokerStops,0);
  f.finishLoginHandoff();await settle();
  assert.equal(f.app.exited,true);assert.equal(f.runtimeStops,1);assert.equal(f.brokerStops,1);assert.equal(f.store,undefined);
  assert.equal(f.dashboardOpens,0);assert.equal(f.windows.length,0);assert.equal(f.timers.size,0);assert.equal(f.errors.length,0);
});

test('a failed broker drain still stops its handed runtime and closes the admitted ledger once',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,brokerStopError:'synthetic broker drain failed'});
  f.app.quit();await settle();
  assert.equal(f.app.exited,true);assert.equal(f.runtimeStops,1);assert.equal(f.brokerStops,1);assert.equal(f.store.closeCalls,1);
  assert.equal(f.timers.size,0);assert.equal(f.trace.events.at(-1).phase,'shutdown-complete');
});

test('a server recovery branch never forwards a retained local capability into an HTTPS dashboard',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,remoteDirty:true,loginToken:'local-capability-must-not-forward',
    runtimeControl:async(input,runtime)=>input.action==='refresh-begin'?{held:true,device_id:runtime.deviceId,actor_id:10}: {commands:[],counts:{pending:0,conflicts:0}}});
  assert.equal(f.dashboardOptions.origin,'https://fasakhaninja.com');assert.equal(f.dashboardOptions.localToken,'');
  assert.equal(f.brokerHandoffs,1);assert.equal(f.runtimeStarts,1);assert.equal(f.loginDisposals,1);
  f.app.quit();await settle();assert.equal(f.app.exited,true);assert.equal(f.store.closeCalls,1);
});

test('prepared startup keeps its internal bridge hidden while verified local services start',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,holdRuntimeStart:true});
  const splash=f.windows[0];
  assert.equal(splash.visible,false);assert.equal(splash.showCalls,0);
  assert.deepEqual(splash.loadURLs,['fasakhansta://pos/startup.html']);
  assert.equal(f.runtimeStarts,1);assert.equal(f.dashboardOpens,0);assert.equal(f.timers.size,0);
  f.notify();assert.equal(f.store.historyReads,0);assert.deepEqual(splash.sent,[]);
  f.finishRuntimeStart();await settle();
  assert.equal(f.dashboardOpens,1);assert.equal(splash.visible,false);assert.equal(splash.hideCalls,1);
  assert.deepEqual(splash.loadURLs,['fasakhansta://pos/startup.html']);assert.equal(f.store.historyReads,0);
  assert.equal(f.timers.size,1);f.app.quit();await settle();assert.equal(f.app.exited,true);
});

test('the actual dashboard can be interactive before subresources finish without exposing the internal bridge',async t=>{
  const f=await fixture(t,{holdDashboardLoad:true}),splash=f.windows[0];
  assert.equal(f.dashboardOpens,1);assert.equal(splash.visible,false);assert.equal(splash.showCalls,0);
  assert.equal(f.timers.size,0);assert.equal(f.store.historyReads,0);
  assert.equal(f.trace.events.at(-1).phase,'dashboard-visible');
  f.finishDashboardLoad();await settle();
  assert.equal(splash.visible,false);assert.equal(splash.showCalls,0);assert.equal(f.timers.size,1);
  assert.equal(f.events.includes('window-show'),false,'The bootstrap bridge is never a visible application page.');
  f.app.quit();await settle();assert.equal(f.app.exited,true);assert.equal(f.timers.size,0);
});

test('a second launch during bootstrap never reveals the hidden bridge and later foregrounds the real dashboard',async t=>{
  const f=await fixture(t,{holdLoad:true}),splash=f.windows[0];
  splash.minimized=true;splash.visible=false;
  f.app.emit('second-instance');await settle();
  assert.equal(f.windows.length,1);assert.equal(splash.visible,false);assert.equal(splash.restoreCalls,0);assert.equal(splash.focusCalls,0);assert.equal(splash.showCalls,0);
  assert.equal(f.dashboardReveals,0);assert.deepEqual(splash.loadURLs,['fasakhansta://pos/startup.html']);
  f.finishLoad();await settle();f.app.emit('second-instance');await settle();
  assert.equal(f.dashboardReveals,1);assert.equal(splash.visible,false);assert.equal(f.store.historyReads,0);
  assert.deepEqual(splash.loadURLs,['fasakhansta://pos/startup.html']);f.app.quit();await settle();
});

test('a second launch foregrounds the interactive dashboard while images load and never restores the hidden bridge',async t=>{
  const f=await fixture(t,{holdDashboardLoad:true}),splash=f.windows[0],showCalls=splash.showCalls;
  assert.equal(splash.visible,false);assert.equal(f.timers.size,0);
  f.app.emit('second-instance');await settle();
  assert.equal(f.dashboardReveals,1);assert.equal(splash.visible,false);assert.equal(splash.showCalls,showCalls);assert.equal(splash.focusCalls,0);
  assert.deepEqual(splash.loadURLs,['fasakhansta://pos/startup.html']);assert.equal(f.store.historyReads,0);
  f.finishDashboardLoad();await settle();f.app.quit();await settle();assert.equal(f.app.exited,true);
});

test('navigation tracing records fixed stages without accepting page details and late navigation cannot overwrite shutdown',async t=>{
  const f=await fixture(t,{holdDashboardLoad:true}),secret='PRIVATE_CUSTOMER_TOKEN_QUERY';
  f.dashboardOptions.onNavigation({phase:'response',path:'/admin/'+secret,mode:secret,token:secret,status:200});
  assert.equal(f.trace.events.at(-1).phase,'dashboard-response');assert.equal(JSON.stringify(f.trace).includes(secret),false);
  f.dashboardOptions.onNavigation({phase:'response',path:'/admin/dashboard',mode:'local',token:secret,status:419});
  assert.equal(f.trace.events.at(-1).status,419);assert.equal(f.trace.events.at(-1).mode,'local');assert.equal(f.trace.events.at(-1).path,'/admin/dashboard');
  f.app.quit();await settle();assert.equal(f.trace.events.at(-1).phase,'shutdown-complete');
  f.dashboardOptions.onNavigation({phase:'loaded'});f.finishDashboardLoad();await settle();
  assert.equal(f.trace.events.at(-1).phase,'shutdown-complete');assert.equal(f.timers.size,0);
});

test('a main-frame login response after startup still records its exact local/server classification and status',async t=>{
  const f=await fixture(t);assert.equal(f.trace.events.at(-1).phase,'startup-complete');
  f.dashboardOptions.onNavigation({phase:'response',path:'/admin/signin',mode:'server',status:419,cookie:'PRIVATE_COOKIE'});
  const response=f.trace.events.at(-1);assert.equal(response.phase,'dashboard-response');assert.equal(response.mode,'server');assert.equal(response.status,419);assert.equal(response.path,'/admin/signin');
  assert.equal(JSON.stringify(f.trace).includes('PRIVATE_COOKIE'),false);f.app.quit();await settle();
});

test('a quit during dashboard navigation cannot restart background work after navigation settles',async t=>{
  const f=await fixture(t,{holdDashboardLoad:true}),splash=f.windows[0];
  f.app.quit();await settle();assert.equal(f.app.exited,true);assert.equal(f.store.closeCalls,1);assert.equal(f.timers.size,0);
  f.finishDashboardLoad();await settle();
  assert.equal(splash.destroyed,true);assert.equal(splash.hideCalls,1);assert.equal(f.timers.size,0);
  assert.equal(f.dashboardOpens,1);assert.equal(f.store.historyReads,0);
});

test('closing during accepted runtime startup drains that attempt and never opens a dashboard or background schedule afterward',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,holdRuntimeStart:true});
  assert.equal(f.trace.events.at(-1).phase,'runtime-start');
  f.app.quit();await settle();
  assert.equal(f.app.exited,false);assert.equal(f.store.db.isOpen,true);assert.equal(f.runtimeStops,0);
  assert.equal(f.trace.events.at(-1).phase,'shutdown-wait-accepted');
  f.finishRuntimeStart();await settle();
  assert.equal(f.app.exited,true);assert.equal(f.runtimeStops,1);assert.equal(f.store.closeCalls,1);
  assert.equal(f.dashboardOpens,0);assert.equal(f.timers.size,0);assert.equal(f.store.historyReads,0);
  assert.equal(f.trace.events.at(-1).phase,'shutdown-complete');
  const shutdownIndex=f.trace.events.findIndex(event=>event.phase==='shutdown-requested');
  assert.ok(f.trace.events.slice(shutdownIndex).every(event=>event.phase.startsWith('shutdown-')),'A late startup completion must not overwrite the shutdown location.');
  const reopened=new Store(f.file);try{assert.equal(reopened.pending()[0].id,f.sale.id);}finally{reopened.close();}
});

test('a failed local-service start shows the native error and exits without exposing a bootstrap page',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,runtimeStartError:'synthetic local service failure'});
  assert.equal(f.dashboardOpens,0);assert.equal(f.windows[0].showCalls,0);
  assert.deepEqual(f.errors,[{title:'تعذر فتح برنامج GO ERP',message:'synthetic local service failure'}]);
  assert.equal(f.runtimeStops,1);assert.equal(f.store.closeCalls,1);assert.equal(f.app.exited,true);
  assert.equal(f.timers.size,0);assert.equal(f.trace.events.at(-1).phase,'shutdown-complete');
  const reopened=new Store(f.file);try{assert.equal(reopened.pending()[0].id,f.sale.id);}finally{reopened.close();}
});

test('legacy orders are loaded once on demand and history publication starts only after that renderer is ready',async t=>{
  const f=await fixture(t),splash=f.windows[0];
  assert.equal(f.store.historyReads,0);assert.equal(splash.visible,false);
  await Promise.all([f.openLocalOrders(),f.openLocalOrders()]);
  assert.deepEqual(splash.loadURLs,['fasakhansta://pos/startup.html','fasakhansta://pos/index.html']);
  assert.equal(splash.visible,true);f.notify();assert.equal(f.store.historyReads,1);
  assert.equal(splash.sent.filter(([name])=>name==='pos:state').length,1);
  f.app.quit();await settle();assert.equal(f.app.exited,true);
});

test('opening the saved orders archive during dashboard navigation keeps that archive visible when startup completes',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,holdDashboardLoad:true}),archive=f.windows[0];
  assert.equal(f.dashboardOpens,1);assert.equal(f.timers.size,0);
  await f.dashboardOptions.archive();
  assert.equal(archive.visible,true);assert.deepEqual(archive.loadURLs,['fasakhansta://pos/startup.html','fasakhansta://pos/index.html']);
  f.finishDashboardLoad();await settle();
  assert.equal(archive.visible,true);assert.equal(archive.hideCalls,1);assert.equal(f.timers.size,1);
  assert.deepEqual(archive.loadURLs,['fasakhansta://pos/startup.html','fasakhansta://pos/index.html']);
  archive.close();await settle();assert.equal(f.app.exited,true);assert.equal(f.store.closeCalls,1);assert.equal(f.timers.size,0);
});

test('a missing native print callback reaches its deadline, keeps the receipt unconfirmed and lets quit close the saved ledger',async t=>{
  const f=await fixture(t,{holdPrint:true,nativeClock:true});
  const printing=f.invoke('print',f.sale.id);await settle();
  const lateCallback=f.printCallback;
  assert.equal(typeof lateCallback,'function');assert.equal(f.nativeTimers.size,1);
  const [expire,delay]=[...f.nativeTimers][0];assert.equal(delay,45000);
  f.app.quit();await settle();assert.equal(f.app.exited,false);assert.equal(f.store.db.isOpen,true);
  expire();const result=await printing;await settle();
  assert.equal(result.ok,false);assert.match(result.error,/لم تصل نتيجة الطباعة/);
  assert.equal(f.app.exited,true);assert.equal(f.store.closeCalls,1);assert.equal(f.nativeTimers.size,0);assert.equal(f.timers.size,0);
  assert.equal(f.windows[1].destroyed,true);
  lateCallback(true);lateCallback(false,'late spooler failure');await settle();
  assert.equal(f.store.closeCalls,1,'Late native responses cannot reopen or use the closed store.');
  const reopened=new Store(f.file);try{
    assert.equal(reopened.history()[0].printed,0);assert.equal(reopened.pending()[0].id,f.sale.id);assert.equal(reopened.order(f.order.id).status,'paid');
  }finally{reopened.close();}
});

test('closing the last app window does not re-enter quit or close SQLite twice, and the ledger reopens intact',async t=>{
  const f=await fixture(t);f.windows[0].close();await settle();
  assert.ok(f.app.exited);assert.equal(f.store.closeCalls,1);assert.equal(f.timers.size,0);assert.equal(f.dashboardCancels,1);
  const reopened=new Store(f.file);try{assert.equal(reopened.order(f.order.id).status,'paid');assert.equal(reopened.pending()[0].id,f.sale.id);}finally{reopened.close();}
});

test('repeated quit and last-window notifications close the database only once',async t=>{
  const f=await fixture(t);f.app.quit();f.app.quit();f.app.emit('window-all-closed');f.app.emit('window-all-closed');await settle();
  assert.ok(f.app.exited);assert.equal(f.store.closeCalls,1);assert.equal(f.timers.size,0);
});

test('a renderer unload veto protects normal navigation but cannot strand the final drained quit',async t=>{
  const f=await fixture(t,{beforeUnload:true});
  const navigation={prevented:false,preventDefault(){this.prevented=true;}};
  f.windows[0].webContents.emit('will-prevent-unload',navigation);
  assert.equal(navigation.prevented,false,'Normal page navigation retains its unsaved-change guard.');
  f.app.emit('second-instance');
  f.windows[0].close();await settle();
  assert.equal(f.app.exited,true);assert.equal(f.windows[0].destroyed,true);
  assert.equal(f.store.closeCalls,1);assert.equal(f.app.relaunchCalls,0);
  const reopened=new Store(f.file);try{assert.equal(reopened.pending()[0].id,f.sale.id);}finally{reopened.close();}
});

test('X drains an accepted SQLite acknowledgement and local runtime before one queued reopen survives the unload veto',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,beforeUnload:true,holdPrint:true,holdRuntimeStop:true});
  const printing=f.invoke('print',f.sale.id);await settle();
  f.windows[0].close();f.app.emit('second-instance');f.app.emit('second-instance');await settle();
  assert.equal(f.app.exited,false);assert.equal(f.app.relaunchCalls,0);
  assert.equal(f.store.db.isOpen,true);assert.equal(f.runtimeStops,0);
  const whileDraining={prevented:false,preventDefault(){this.prevented=true;}};
  f.windows[0].webContents.emit('will-prevent-unload',whileDraining);
  assert.equal(whileDraining.prevented,false,'No renderer is discarded before accepted work finishes.');
  assert.equal((await f.invoke('printer','RejectedDuringQuit')).ok,false);
  f.printCallback(true);assert.equal((await printing).ok,true);await settle();
  assert.equal(f.runtimeStops,1);assert.equal(f.app.exited,false);
  assert.equal(f.trace.events.at(-1).phase,'shutdown-stop-runtime');
  assert.equal(f.store.db.isOpen,true);assert.equal(f.app.relaunchCalls,0);
  f.app.emit('second-instance');f.finishRuntimeStop();await settle();
  assert.equal(f.app.exited,true);assert.equal(f.app.relaunchCalls,1);
  assert.equal(f.trace.events.at(-1).phase,'shutdown-complete');
  assert.equal(f.store.closeCalls,1);assert.equal(f.timers.size,0);
  const reopened=new Store(f.file);try{
    assert.equal(reopened.history()[0].printed,1);assert.equal(reopened.pending()[0].id,f.sale.id);
    assert.equal(reopened.order(f.order.id).status,'paid');assert.equal(reopened.get('printer'),null);
  }finally{reopened.close();}
});

test('an installer handoff suppresses queued reopen both at request time and at final clean quit',async t=>{
  for(const installerStartsAfterRequest of [false,true])await t.test(String(installerStartsAfterRequest),async t=>{
    const f=await fixture(t,{packaged:true,prepared:true,beforeUnload:true,holdRuntimeStop:true});
    if(!installerStartsAfterRequest)f.markInstallingUpdate();
    f.windows[0].close();await settle();assert.equal(f.runtimeStops,1);
    f.app.emit('second-instance');f.app.emit('second-instance');
    if(installerStartsAfterRequest)f.markInstallingUpdate();
    f.finishRuntimeStop();await settle();
    assert.equal(f.app.exited,true);assert.equal(f.store.closeCalls,1);assert.equal(f.app.relaunchCalls,0);
  });
});

test('the actual native sync button queues one 30-second cycle and returns without blocking local work',async t=>{
  const sent=[];let f;
  f=await fixture(t,{paired:true,fetch:async(url,options)=>{
    sent.push(url);
    if(url.endsWith('/sync'))return new Response(JSON.stringify({id:JSON.parse(options.body).event.id}));
    return new Response(JSON.stringify(url.endsWith('/snapshot')?f.store.snapshot():{ok:true}));
  }});
  const first=await f.invoke('sync'),second=await f.invoke('sync');
  assert.equal(first.ok,true);assert.equal(first.value.sync_queued,true);assert.equal(second.value.sync_queued,true);
  assert.equal(sent.length,0);assert.equal((await f.invoke('new')).ok,true);
  await f.syncSlot();assert.equal(sent.filter(url=>url.endsWith('/sync')).length,1);
  assert.equal(f.store.counts().pending,0);f.app.quit();await settle();
  assert.ok(f.app.exited);assert.equal(f.timers.size,0);
});

test('pairing the actual main process bootstraps read-only data and retains paid writes for the next slot',async t=>{
  const sent=[];let f;
  f=await fixture(t,{fetch:async(url,options)=>{
    sent.push(url);
    const value=url.endsWith('/pair')?{device_id:1,token:'paired-token'}:url.endsWith('/snapshot')?f.store.snapshot():url.endsWith('/sync')?{id:JSON.parse(options.body).event.id}:{ok:true};
    return new Response(JSON.stringify(value));
  }});
  const paired=await f.invoke('pair','https://fasakhaninja.com','test-code');
  assert.equal(paired.ok,true);assert.equal(sent.filter(url=>url.endsWith('/sync')).length,0);
  assert.equal(f.store.pending()[0].id,f.sale.id);await f.syncSlot();
  assert.equal(sent.filter(url=>url.endsWith('/sync')).length,1);assert.equal(f.store.counts().pending,0);
  f.app.quit();await settle();assert.ok(f.app.exited);
});

test('the actual packaged dashboard button queues immediately and preparation joins that same scheduled cycle',async t=>{
  const sent=[];let f;
  f=await fixture(t,{paired:true,packaged:true,fetch:async(url,options)=>{
    sent.push(url);
    return new Response(JSON.stringify(url.endsWith('/snapshot')?f.store.snapshot():url.endsWith('/sync')?{id:JSON.parse(options.body).event.id}:{ok:true}));
  }});
  const state=await f.dashboardOptions.synchronize();assert.equal(state.sync_queued,true);assert.equal(state.sync_interval_seconds,30);
  let prepared=false;const preflight=f.preparationOptions.beforePrepare().then(()=>{prepared=true;});
  await settle();assert.equal(sent.length,0);assert.equal(prepared,false);assert.equal(f.store.pending()[0].id,f.sale.id);
  await f.syncSlot();await preflight;assert.equal(prepared,true);
  assert.equal(sent.filter(url=>url.endsWith('/sync')).length,1);assert.equal(f.store.counts().pending,0);
  f.app.quit();await settle();assert.ok(f.app.exited);
});

test('the actual prepared packaged app opens local dashboard pages even when production is online',async t=>{
  const calls=[];const f=await fixture(t,{packaged:true,prepared:true,fetch:async url=>{calls.push(url);throw Error('unexpected startup request');}});
  assert.equal(f.app.displayName,'GO ERP');assert.equal(f.app.paths.get('userData'),path.join(path.dirname(f.file),'fasakhansta-dashboard-preview'));
  assert.equal(f.dashboardOptions.origin,'http://127.0.0.1:45123');assert.equal(f.dashboardOptions.localToken,'owned-local');
  assert.equal(f.dashboardOptions.serverOrigin,'https://fasakhaninja.com');assert.deepEqual(calls,[]);assert.equal(f.dashboardOpens,1);
  f.app.quit();await settle();assert.ok(f.app.exited);
});
test('the actual preparation handler switches local only after clearing the protected snapshot gate',async t=>{
  const f=await fixture(t,{packaged:true});assert.equal(f.preparationOptions.allowPreview,true);assert.equal(f.localSwitches,0);
  const ready=await f.dashboardOptions.prepare('https://fasakhaninja.com','csrf-token');
  assert.equal(ready.prepared,true);assert.equal(f.localSwitches,1);
  // DashboardMode.local asserts clean remote state, so this succeeds only after
  // the outer preparation snapshot has flushed its clean encrypted record.
  f.app.quit();await settle();assert.ok(f.app.exited);
});

function deltaIndex(){
  return {format:1,kind:'dashboard-delta-index',device_id:randomUUID(),actor_id:10,
    source:{format:1,files:20,sha256:'a'.repeat(64),framework:'8.83.29'},
    tables:{users:{schema_sha256:'b'.repeat(64),count:1,hashes:{['c'.repeat(64)]:1}}}};
}

test('the actual packaged delta transport posts only the bounded index and preserves original numeric wire bytes',async t=>{
  const requests=[],wire='{"format":1,"kind":"dashboard-data-delta","tables":{"users":{"delta":{"add":[{"id":9223372036854775807,"amount":1.234567890123456789e-7,"integral":1.0}],"remove":{}}}}}';
  const f=await fixture(t,{packaged:true,prepared:true,deltaBridge:true,fetch:async(url,options)=>{
    requests.push({url,options});return new Response(wire,{headers:{'Content-Type':'application/json','Content-Length':String(Buffer.byteLength(wire))}});
  }});
  assert.ok(f.deltaOptions);assert.equal(requests.length,0);const index=deltaIndex(),credential={serverOrigin:'https://fasakhaninja.com',token:'d'.repeat(64),
    capture_token:'private-native-capture-token',capture_database:'private-capture-database'};
  const result=await f.deltaOptions.download(index,credential);assert.equal(requests.length,1);
  const sent=requests[0];assert.equal(sent.url,'https://fasakhaninja.com/api/desktop-dashboard/delta');assert.equal(sent.options.method,'POST');
  assert.equal(sent.options.headers.Accept,'application/json');assert.equal(sent.options.headers['Content-Type'],'application/octet-stream');
  assert.equal(sent.options.headers.Authorization,'Bearer '+credential.token);assert.equal(sent.options.redirect,'error');
  assert.equal(sent.options.body,JSON.stringify(index));assert.deepEqual(JSON.parse(sent.options.body),index);
  assert.equal(sent.options.body.includes('private-native-capture-token'),false);assert.equal(sent.options.body.includes('private-capture-database'),false);
  assert.equal(Object.hasOwn(JSON.parse(sent.options.body),'rows'),false);assert.equal(Object.hasOwn(JSON.parse(sent.options.body),'capture'),false);
  assert.equal(readSnapshot.input(result),wire);assert.notEqual(JSON.stringify(result),wire,'The importer retains the original PHP float/int representation.');
  assert.equal(f.dashboardOptions.origin,'http://127.0.0.1:45123');assert.equal(f.dashboardOptions.localToken,'owned-local');
  f.app.quit();await settle();assert.equal(f.deltaStops,1);assert.ok(f.app.exited);
});

test('the actual delta transport cancels rejected private response bodies and reports only its fixed safe message',async t=>{
  const secret='PRIVATE_ACCOUNT_ORDER_AND_TOKEN';let cancelled=0,read=0;
  const f=await fixture(t,{packaged:true,prepared:true,deltaBridge:true,fetch:async()=>({ok:false,status:403,
    body:{cancel:async()=>{cancelled++;}},json:async()=>{read++;return {message:secret};},text:async()=>{read++;return secret;}})});
  let caught;try{await f.deltaOptions.download(deltaIndex(),{serverOrigin:'https://fasakhaninja.com',token:'e'.repeat(64)});}catch(error){caught=error;}
  assert.ok(caught);assert.equal(caught.status,403);assert.equal(caught.message,'تعذر استقبال تغييرات السيرفر؛ العمل المحلي مستمر.');
  assert.equal(caught.message.includes(secret),false);assert.equal(cancelled,1);assert.equal(read,0);
  assert.equal(f.dashboardOptions.origin,'http://127.0.0.1:45123');f.app.quit();await settle();assert.equal(f.deltaStops,1);
});

test('the actual delta reader refuses a declared response above its limit without allocating that body',async t=>{
  const f=await fixture(t,{packaged:true,prepared:true,deltaBridge:true,fetch:async()=>new Response('{}',{
    headers:{'Content-Type':'application/json','Content-Length':String(256*1024*1024+1)}})});
  await assert.rejects(f.deltaOptions.download(deltaIndex(),{serverOrigin:'https://fasakhaninja.com',token:'f'.repeat(64)}));
  assert.equal(f.dashboardOptions.localToken,'owned-local');f.app.quit();await settle();assert.ok(f.app.exited);
});

test('quitting stops the actual incoming delta bridge and aborts its active network transport once',async t=>{
  let aborted=0,requested=0;
  const f=await fixture(t,{packaged:true,prepared:true,deltaBridge:true,fetch:async(_url,options)=>{
    requested++;return new Promise((_resolve,reject)=>options.signal.addEventListener('abort',()=>{aborted++;reject(options.signal.reason);},{once:true}));
  }});
  const pending=f.deltaOptions.download(deltaIndex(),{serverOrigin:'https://fasakhaninja.com',token:'1'.repeat(64)}),rejected=assert.rejects(pending);
  await settle();assert.equal(requested,1);f.app.quit();f.app.quit();await rejected;await settle();
  assert.equal(f.deltaStops,1);assert.equal(aborted,1);assert.ok(f.app.exited);assert.equal(f.timers.size,0);
  const reopened=new Store(f.file);try{assert.equal(reopened.pending()[0].id,f.sale.id);assert.equal(reopened.order(f.order.id).status,'paid');}finally{reopened.close();}
});

test('the actual delta download remains abortable after response headers while its body is still streaming',async t=>{
  let headersReturned=false,aborted=0;
  const f=await fixture(t,{packaged:true,prepared:true,deltaBridge:true,fetch:async(_url,options)=>{
    const body=new ReadableStream({start(controller){
      controller.enqueue(Buffer.from('{"format":1,'));
      options.signal.addEventListener('abort',()=>{aborted++;controller.error(options.signal.reason);},{once:true});
    }});
    headersReturned=true;return new Response(body,{headers:{'Content-Type':'application/json'}});
  }});
  const pending=f.deltaOptions.download(deltaIndex(),{serverOrigin:'https://fasakhaninja.com',token:'2'.repeat(64)}),rejected=assert.rejects(pending);
  await settle();assert.equal(headersReturned,true);assert.equal(aborted,0);f.app.quit();await rejected;await settle();
  assert.equal(aborted,1);assert.equal(f.deltaStops,1);assert.ok(f.app.exited);assert.equal(f.timers.size,0);
});

test('the actual packaged background refresh is independently admitted and shutdown drains it after its schedule slot finishes',async t=>{
  let entered=0,release;const f=await fixture(t,{packaged:true,prepared:true,deltaBridge:true,
    deltaRun:async()=>{entered++;await new Promise(resolve=>release=resolve);return {unchanged:true};}});
  f.store.acknowledge(f.sale.id,{id:f.sale.id});await f.syncSlot();assert.equal(entered,1);
  // This final handoff is available only after the upload run has finished;
  // an awaited whole refresh would still own DashboardSync.running here.
  assert.equal(await f.deltaOptions.handoff(async()=> 'short-cutover'),'short-cutover');
  f.app.quit();await settle();assert.equal(f.deltaStops,1);assert.equal(f.app.exited,false);assert.equal(f.store.db.isOpen,true);
  release();await settle();assert.equal(f.app.exited,true);assert.equal(f.store.closeCalls,1);assert.equal(f.timers.size,0);
});

test('the actual packaged next slot uploads a new command while its previous incoming preparation remains unresolved',async t=>{
  const queue=[],sent=[];let release,started=0;
  const f=await fixture(t,{packaged:true,prepared:true,deltaBridge:true,
    deltaRun:async()=>{started++;await new Promise(resolve=>release=resolve);return {unchanged:true};},
    runtimeControl:async input=>{if(input.action==='acknowledge')queue.shift();return {commands:queue.slice(0,1),counts:{pending:queue.length,conflicts:0}};},
    fetch:async(url,options)=>{assert.ok(url.endsWith('/desktop-dashboard/commands'));sent.push(JSON.parse(options.body).command_id);return new Response('{"committed":true}');}});
  f.store.acknowledge(f.sale.id,{id:f.sale.id});await f.syncSlot();assert.equal(started,1);
  queue.push({command_id:'sale-accepted-during-background-copy'});await f.syncSlot();
  assert.deepEqual(sent,['sale-accepted-during-background-copy']);assert.equal(queue.length,0);assert.equal(started,1);
  f.app.quit();release();await settle();assert.equal(f.app.exited,true);
});

test('quitting during an unknown sync response aborts transport and replays the same saved sale safely after restart',async t=>{
  const committed=new Map();let aborted=0,collections=0;
  const f=await fixture(t,{paired:true,fetch:(_url,options)=>{
    const event=JSON.parse(options.body).event;if(!committed.has(event.id)){collections++;committed.set(event.id,{id:event.id});}
    return new Promise((_resolve,reject)=>options.signal.addEventListener('abort',()=>{aborted++;reject(options.signal.reason);},{once:true}));
  }});
  assert.equal(committed.size,0);await f.syncSlot();assert.equal(committed.size,1);f.app.quit();f.app.quit();await settle();
  assert.equal(aborted,1);assert.ok(f.app.exited);assert.equal(f.store.closeCalls,1);assert.equal(f.timers.size,0);
  const reopened=new Store(f.file);
  try{
    assert.equal(reopened.pending()[0].id,f.sale.id);assert.equal(reopened.pending()[0].attempts,0);assert.equal(reopened.pending()[0].last_error,null);assert.equal(reopened.get('connection').device_id,1);
    await new Sync(reopened,async(_method,endpoint,body)=>endpoint==='sync'?committed.get(body.event.id):endpoint==='snapshot'?reopened.snapshot():{ok:true}).run();
    assert.equal(reopened.counts().pending,0);assert.equal(collections,1);assert.equal(reopened.history().length,1);
  }finally{reopened.close();}
});

test('quitting waits for the accepted printer callback, blocks new writes and preserves print acknowledgement',async t=>{
  const f=await fixture(t,{holdPrint:true});const printing=f.invoke('print',f.sale.id);await settle();assert.equal(typeof f.printCallback,'function');
  f.app.quit();f.app.quit();assert.equal(f.app.exited,false);assert.ok(f.store.db.isOpen);
  const rejected=await f.invoke('printer','ChangedDuringQuit');assert.equal(rejected.ok,false);assert.match(rejected.error,/يُغلق/);
  f.printCallback(true);assert.equal((await printing).ok,true);await settle();assert.ok(f.app.exited);assert.equal(f.store.closeCalls,1);
  const reopened=new Store(f.file);try{assert.equal(reopened.history()[0].printed,1);assert.equal(reopened.history().length,1);assert.equal(reopened.order(f.order.id).revision,1);assert.equal(reopened.get('printer'),null);}finally{reopened.close();}
});

test('a rejected printer callback during quit retains the original sale for reprinting and still finishes shutdown',async t=>{
  const f=await fixture(t,{holdPrint:true});const printing=f.invoke('print',f.sale.id);await settle();f.app.quit();f.printCallback(false,'printer disconnected');
  assert.equal((await printing).ok,false);await settle();assert.ok(f.app.exited);assert.equal(f.store.closeCalls,1);
  const reopened=new Store(f.file);try{assert.equal(reopened.history()[0].printed,0);assert.equal(reopened.pending()[0].id,f.sale.id);assert.equal(reopened.order(f.order.id).status,'paid');}finally{reopened.close();}
});

test('quitting before the local page finishes loading cannot reopen the dashboard or schedule sync after SQLite closes',async t=>{
  const f=await fixture(t,{holdLoad:true});f.app.quit();await settle();assert.ok(f.app.exited);assert.equal(f.store.closeCalls,1);
  f.finishLoad();await settle();assert.equal(f.dashboardOpens,0);assert.equal(f.timers.size,0);
});

test('repeated Store.close is harmless and the persisted sale remains readable in a new connection',async t=>{
  const f=await fixture(t);assert.doesNotThrow(()=>{f.store.close();f.store.close();});
  const reopened=new Store(f.file);try{assert.equal(reopened.pending()[0].id,f.sale.id);assert.equal(reopened.order(f.order.id).status,'paid');}finally{reopened.close();}
});
