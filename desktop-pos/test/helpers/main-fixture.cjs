'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path'),vm=require('node:vm');
const {EventEmitter}=require('node:events');
const Store=require('../../src/store.cjs');
const SyncSchedule=require('../../src/sync-schedule.cjs');
const {randomUUID}=require('node:crypto');
const settle=async()=>{for(let i=0;i<20;i++)await Promise.resolve();await new Promise(setImmediate);};
async function fixture(t,{paired=false,packaged=false,prepared=false,fetch,holdPrint=false,holdLoad=false,holdDashboardLoad=false,holdRuntimeStart=false,runtimeStartError,holdLogin=false,holdLoginHandoff=false,loginAccountId='default',loginOrigin='https://fasakhaninja.com',loginToken='',remoteDirty=false,brokerStopError,nativeClock=false,deltaBridge=false,deltaRun,runtimeControl,beforeUnload=false,holdRuntimeStop=false,windowsHost=false,holdCentralSelection=false,moduleOverrides}={}){
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
    constructor(){updaterConstructions++;this.state={status:'idle'};events.push('updater-create');}
    start(){updaterStarts++;events.push('updater-start');return Promise.resolve(this.state);}
    async authorizeInstalled(){events.push('updater-authorize');return true;}
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
    constructor(options={}){this.profile=options.profile;this.authorizeInstalled=options.authorizeInstalled;this.deviceId=randomUUID();this.origin='http://127.0.0.1:45123';this.token='owned-local';this.metadata={read:async name=>{
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
  const profileBindings=[];
  class Profiles{
    constructor({root}){this.root=root;}
    async selected(){return{id:'default',label:'Fixture account',profile:this.root,partition:'persist:fasakhansta-dashboard'};}
    async bind(...args){profileBindings.push(['bind',...args]);}async assertBinding(...args){profileBindings.push(['assert',...args]);}
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
  const nativeDeadline=nativeClock?vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../src/native-deadline.cjs'),'utf8')+'\n;module.exports',{module:{exports:{}},setTimeout:(fn,delay)=>{nativeTimers.set(fn,delay);return fn;},clearTimeout:fn=>nativeTimers.delete(fn)}):require('../../src/native-deadline.cjs');
  const resolveOverride=name=>moduleOverrides?.(name,{profile,events,UnpreparedRuntime,Updater,loginBrokerOptions});
  const mainState=vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../src/main.cjs'),'utf8')+'\n;({localOrders,notify,ownWorkspaceRuntime,markInstallingUpdate(){installingUpdate=true;}})',{require:name=>resolveOverride(name)|| (name==='electron'?electron:name==='./account-profiles.cjs'?Profiles:name==='./account-login.cjs'?{AccountLogin:LoginBroker}:name==='./login-window.cjs'?loginUI:name==='./account-picker.cjs'?async()=>null:name==='./native-deadline.cjs'?nativeDeadline:name==='./store.cjs'?TrackedStore:name==='./sync-schedule.cjs'?ScheduledClock:name==='./dashboard-updater.cjs'&&windowsHost?{DashboardUpdater:Updater}:name==='./local-runtime.cjs'&&packaged?UnpreparedRuntime:name==='./dashboard-preparation.cjs'&&packaged?{DashboardPreparation:Preparation}:name==='./dashboard-delta.cjs'&&deltaBridge?{DashboardDelta:DeltaBridge}:name==='./dashboard.cjs'?options=>{dashboardOptions=options;let local=Boolean(options.localToken);return {cancelAuthentication:()=>{dashboardCancels++;},open:async()=>{dashboardOpens++;events.push('dashboard-open');options.onVisible?.();if(holdDashboardLoad)await new Promise(resolve=>{finishDashboardLoad=resolve;});events.push('dashboard-ready');options.onVisible?.();},isVisible:()=>true,reveal(){dashboardReveals++;options.onVisible?.();},publish(){},current:()=>({local}),refresh:async work=>{events.push('dashboard-refresh');return work();},switchTo:async(target,work=async()=>{})=>{await work();local=Boolean(target.localToken);localSwitches++;}};}:require(name.startsWith('./')?'../../src/'+name.slice(2):name)),__dirname:path.join(__dirname,'../../src'),process:{env:{POS_TEST_PROFILE:profile},resourcesPath:profile,platform:windowsHost?'win32':undefined},URL,AbortController,AbortSignal,Response,Buffer,Promise,Set,console,setTimeout:(fn,delay)=>{timers.set(fn,delay);return fn;},clearTimeout:fn=>timers.delete(fn)});
  await settle();
  t.after(()=>{if(store?.db.isOpen)store.close();fs.rmSync(profile,{recursive:true,force:true});});
  return {app,profileBindings,get store(){return store;},get selectedLedgerFile(){return selectedLedgerFile;},get loginVisible(){return loginVisible;},get loginDisposals(){return loginDisposals;},get loginReveals(){return loginReveals;},get loginCancels(){return loginCancels;},get brokerCancels(){return brokerCancels;},get brokerStops(){return brokerStops;},get brokerHandoffs(){return brokerHandoffs;},get finishLogin(){return finishLogin;},get finishLoginHandoff(){return finishLoginHandoff;},get loginAdmission(){return loginAdmission;},get loginBrokerOptions(){return loginBrokerOptions;},profile,file,sale,order,windows,timers,nativeTimers,events,errors,get trace(){return JSON.parse(fs.readFileSync(path.join(profile,'lifecycle-trace.json'),'utf8'));},openLocalOrders:mainState.localOrders,notify:mainState.notify,ownWorkspaceRuntime:mainState.ownWorkspaceRuntime,markInstallingUpdate:mainState.markInstallingUpdate,get finishRuntimeStart(){return finishRuntimeStart;},get finishDashboardLoad(){return finishDashboardLoad;},get finishRuntimeStop(){return finishRuntimeStop;},get runtimeStarts(){return runtimeStarts;},get runtimeStops(){return runtimeStops;},get finishCentralSelection(){return finishCentralSelection;},get updaterStarts(){return updaterStarts;},get updaterConstructions(){return updaterConstructions;},async syncSlot(){assert.equal(timers.size,1);const [fn,delay]=[...timers][0];assert.equal(delay,30000);scheduleNow+=delay;timers.delete(fn);fn();await settle();},get localSwitches(){return localSwitches;},get dashboardOptions(){return dashboardOptions;},get preparationOptions(){return preparationOptions;},get deltaOptions(){return deltaOptions;},get deltaStops(){return deltaStops;},get dashboardOpens(){return dashboardOpens;},get dashboardReveals(){return dashboardReveals;},get dashboardCancels(){return dashboardCancels;},get printCallback(){return printCallback;},get finishLoad(){return finishLoad;},invoke:(name,...args)=>handlers.get('pos:'+name)({sender:windows[0].webContents,senderFrame:{url:'fasakhansta://pos/index.html'}},...args)};
}

module.exports={fixture,settle};
