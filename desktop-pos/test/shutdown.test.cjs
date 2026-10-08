const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path'),vm=require('node:vm');
const {EventEmitter}=require('node:events');
const Store=require('../src/store.cjs');
const Sync=require('../src/sync.cjs');
const {randomUUID}=require('node:crypto');
const settle=async()=>{for(let i=0;i<20;i++)await Promise.resolve();await new Promise(setImmediate);};

async function fixture(t,{paired=false,fetch,holdPrint=false,holdLoad=false}={}){
  const profile=fs.mkdtempSync(path.join(os.tmpdir(),'pos-shutdown-')),file=path.join(profile,'fasakhansta-pos.sqlite');
  const seed=new Store(file),snapshot={id:randomUUID(),generated_at:new Date().toISOString(),branch:{value:'f:100',name:'اختبار'},actor:{id:10,name:'كاشير'},tax_bps:0,service_bps:0,can_discount:false,categories:[],products:[{id:1,name:'رنجة',unit:'piece',variants:[{option_id:'',label:'أساسي',unit_price_cents:10000,quantity_mode:'piece'}]}]};
  seed.saveSnapshot(snapshot);const order=seed.newOrder();seed.update(order.id,{...order.data,items:[{product_id:1,option_id:'',quantity_mode:'piece',quantity:'1'}],cash_received:'100.00'});const sale=seed.dispatch(order.id,'sale');
  if(paired)seed.set('connection',{origin:'https://fasakhaninja.com',device_id:1,token_cipher:Buffer.from('test-token').toString('base64')});seed.close();
  const windows=[],handlers=new Map(),timers=new Set();let store,printCallback,finishLoad,dashboardOpens=0;
  class TrackedStore extends Store{constructor(file){super(file);store=this;this.closeCalls=0;}close(){this.closeCalls++;super.close();}}
  class App extends EventEmitter{
    constructor(){super();this.isPackaged=false;this.quitCalls=0;this.exited=false;}
    whenReady(){return Promise.resolve();}getPath(){return profile;}requestSingleInstanceLock(){return true;}
    quit(){if(this.exited)return;if(++this.quitCalls>10)throw Error('recursive quit');const event={prevented:false,preventDefault(){this.prevented=true;}};this.emit('before-quit',event);if(event.prevented)return;
      for(const window of [...windows])if(!window.destroyed)window.close();this.emit('will-quit',{});this.exited=true;}
  }
  const app=new App();
  class Window extends EventEmitter{
    constructor(){super();windows.push(this);this.destroyed=false;this.webContents=new EventEmitter();this.webContents.session={setPermissionRequestHandler(){}};this.webContents.setWindowOpenHandler=()=>{};
      this.webContents.send=()=>{};this.webContents.getPrintersAsync=async()=>[{name:'Printer'}];this.webContents.executeJavaScript=async()=>true;
      this.webContents.print=(_options,callback)=>{printCallback=callback;if(!holdPrint)callback(true);};}
    async loadURL(url){this.url=url;if(holdLoad&&url.startsWith('fasakhansta:'))await new Promise(resolve=>{finishLoad=resolve;});}show(){}focus(){}isMinimized(){return false;}isDestroyed(){return this.destroyed;}
    close(){const event={prevented:false,preventDefault(){this.prevented=true;}};this.emit('close',event);if(!event.prevented)this.destroy();}
    destroy(){if(this.destroyed)return;this.destroyed=true;this.emit('closed');if(windows.every(w=>w.destroyed))app.emit('window-all-closed');}
  }
  const electron={app,BrowserWindow:Window,ipcMain:{handle:(name,fn)=>handlers.set(name,fn)},protocol:{registerSchemesAsPrivileged(){},handle(){}},net:{fetch:fetch||(async()=>new Response('{}'))},safeStorage:{decryptString:buffer=>buffer.toString()},dialog:{showErrorBox(){},showSaveDialog:async()=>({canceled:true})}};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../src/main.cjs'),'utf8'),{require:name=>name==='electron'?electron:name==='./store.cjs'?TrackedStore:name==='./dashboard.cjs'?()=>({open:async()=>{dashboardOpens++;},isVisible:()=>true,reveal(){}}):require(name.startsWith('./')?'../src/'+name.slice(2):name),__dirname:path.join(__dirname,'../src'),process:{env:{POS_TEST_PROFILE:profile}},URL,AbortController,AbortSignal,Response,Buffer,Promise,Set,console,setTimeout:fn=>{timers.add(fn);return fn;},clearTimeout:fn=>timers.delete(fn)});
  await settle();
  t.after(()=>{if(store.db.isOpen)store.close();fs.rmSync(profile,{recursive:true,force:true});});
  return {app,store,file,sale,order,windows,timers,get dashboardOpens(){return dashboardOpens;},get printCallback(){return printCallback;},get finishLoad(){return finishLoad;},invoke:(name,...args)=>handlers.get('pos:'+name)({sender:windows[0].webContents,senderFrame:{url:'fasakhansta://pos/index.html'}},...args)};
}

test('closing the last app window does not re-enter quit or close SQLite twice, and the ledger reopens intact',async t=>{
  const f=await fixture(t);f.windows[0].close();await settle();
  assert.ok(f.app.exited);assert.equal(f.store.closeCalls,1);assert.equal(f.timers.size,0);
  const reopened=new Store(f.file);try{assert.equal(reopened.order(f.order.id).status,'paid');assert.equal(reopened.pending()[0].id,f.sale.id);}finally{reopened.close();}
});

test('repeated quit and last-window notifications close the database only once',async t=>{
  const f=await fixture(t);f.app.quit();f.app.quit();f.app.emit('window-all-closed');f.app.emit('window-all-closed');await settle();
  assert.ok(f.app.exited);assert.equal(f.store.closeCalls,1);assert.equal(f.timers.size,0);
});

test('quitting during an unknown sync response aborts transport and replays the same saved sale safely after restart',async t=>{
  const committed=new Map();let aborted=0,collections=0;
  const f=await fixture(t,{paired:true,fetch:(_url,options)=>{
    const event=JSON.parse(options.body).event;if(!committed.has(event.id)){collections++;committed.set(event.id,{id:event.id});}
    return new Promise((_resolve,reject)=>options.signal.addEventListener('abort',()=>{aborted++;reject(options.signal.reason);},{once:true}));
  }});
  assert.equal(committed.size,1);f.app.quit();f.app.quit();await settle();
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
