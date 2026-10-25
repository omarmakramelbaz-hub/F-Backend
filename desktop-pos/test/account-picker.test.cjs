'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {EventEmitter}=require('node:events'),{createRequire}=require('node:module'),{pathToFileURL}=require('node:url');
const file=path.join(__dirname,'../src/account-picker.cjs'),localRequire=createRequire(file);
const profiles=[{id:'default',label:'الحساب الحالي',selected:true},{id:'12345678-1234-4234-9234-123456789abc',label:'فرع آخر',selected:false}];
function fixture(){
  const handlers=new Map(),windows=[],sessions=[];
  class Window extends EventEmitter{
    constructor(options){super();this.options=options;this.destroyed=false;this.shown=false;windows.push(this);this.webContents=new EventEmitter();
      Object.assign(this.webContents,{id:windows.length,url:'about:blank',mainFrame:{url:'about:blank'},getURL(){return this.url;},setWindowOpenHandler(value){this.popup=value;}});}
    isDestroyed(){return this.destroyed;}
    removeMenu(){this.menuRemoved=true;}
    loadFile(value){this.file=value;this.webContents.url=pathToFileURL(value).href;this.webContents.mainFrame.url=this.webContents.url;return Promise.resolve();}
    show(){this.shown=true;}focus(){this.focused=true;}
    destroy(){if(!this.destroyed){this.destroyed=true;this.emit('closed');}}
  }
  const electron={BrowserWindow:Window,ipcMain:{handle:(name,handler)=>{assert.equal(handlers.has(name),false);handlers.set(name,handler);},removeHandler:name=>handlers.delete(name)},
    session:{fromPartition(partition,options){const isolated={partition,options,webRequest:{onBeforeRequest(...args){isolated.request=args.length===1?args[0]:args[1];}},
      setPermissionRequestHandler(value){this.permission=value;},setPermissionCheckHandler(value){this.check=value;},setDevicePermissionHandler(value){this.device=value;}};sessions.push(isolated);return isolated;}}};
  const module={exports:{}};vm.runInNewContext(fs.readFileSync(file,'utf8'),{module,exports:module.exports,__dirname:path.dirname(file),require:name=>name==='electron'?electron:localRequire(name),Promise,Set,Error},{filename:file});
  const start=(options={})=>{const result=module.exports({profiles,...options}),window=windows.at(-1);const channel=[...handlers.keys()].at(-1),handler=handlers.get(channel);
    return {result,window,channel,handler,invoke:(input,event)=>Promise.resolve().then(()=>handler(event||{sender:window.webContents,senderFrame:window.webContents.mainFrame},input))};};
  return {choose:module.exports,start,windows,sessions,handlers};
}
class Signal extends EventTarget{
  constructor(){super();this.aborted=false;this.listeners=0;}
  addEventListener(type,listener,options){if(type==='abort')this.listeners++;super.addEventListener(type,listener,options);}
  removeEventListener(type,listener){if(type==='abort')this.listeners--;super.removeEventListener(type,listener);}
  abort(){this.aborted=true;this.dispatchEvent(new Event('abort'));}
}
test('the picker uses a sandboxed ephemeral session and exposes only its own labels to its main frame',async()=>{
  const f=fixture(),parent=new EventEmitter();parent.isDestroyed=()=>false;const signal=new Signal(),opened=f.start({parent,signal});
  await Promise.resolve();const options=opened.window.options,isolated=f.sessions[0];
  assert.equal(options.parent,parent);assert.equal(options.modal,true);assert.equal(options.webPreferences.sandbox,true);
  assert.equal(options.webPreferences.contextIsolation,true);assert.equal(options.webPreferences.nodeIntegration,false);assert.equal(options.webPreferences.webSecurity,true);
  assert.equal(options.webPreferences.webviewTag,false);assert.equal(options.webPreferences.session,isolated);
  assert.match(isolated.partition,/^go-account-picker-[a-f0-9]{32}$/);assert.equal(isolated.options.cache,false);assert.equal(opened.window.menuRemoved,true);
  const listed=await opened.invoke({action:'list'});assert.equal(JSON.stringify(listed),JSON.stringify({profiles}));
  assert.equal(isolated.check(),false);assert.equal(isolated.device(),false);let granted;
  isolated.permission({},'media',value=>{granted=value;});assert.equal(granted,false);
  assert.equal(JSON.stringify(opened.window.webContents.popup()),JSON.stringify({action:'deny'}));
  for(const name of ['will-navigate','will-frame-navigate','will-redirect','will-attach-webview']){let prevented=false;opened.window.webContents.emit(name,{preventDefault(){prevented=true;}});assert.equal(prevented,true);}
  for(const [url,method,id,expected] of [[opened.window.webContents.url,'GET',1,false],['https://example.test','GET',1,true],[opened.window.webContents.url,'POST',1,true],[opened.window.webContents.url,'GET',2,true]]){
    let answer;isolated.request({url,method,webContentsId:id},value=>{answer=value.cancel;});assert.equal(answer,expected);
  }
  assert.equal(await opened.invoke({action:'select',id:profiles[1].id}),true);
  assert.equal(JSON.stringify(await opened.result),JSON.stringify({action:'select',id:profiles[1].id}));
  assert.equal(opened.window.destroyed,true);assert.equal(f.handlers.size,0);assert.equal(signal.listeners,0);assert.equal(parent.listenerCount('closed'),0);
  assert.equal(opened.window.webContents.listenerCount('will-navigate'),0);assert.equal(isolated.request,null);
});
test('foreign contents, child frames, and nonexact file URLs cannot read or choose an account',async()=>{
  const f=fixture(),opened=f.start(),contents=opened.window.webContents;
  await assert.rejects(opened.invoke({action:'list'},{sender:new EventEmitter(),senderFrame:contents.mainFrame}));
  await assert.rejects(opened.invoke({action:'select',id:'default'},{sender:contents,senderFrame:{url:contents.getURL()}}));
  const url=contents.url;contents.url=url+'?foreign';await assert.rejects(opened.invoke({action:'list'}));contents.url=url;
  contents.mainFrame.url=url+'#foreign';await assert.rejects(opened.invoke({action:'add'}));contents.mainFrame.url=url;
  assert.equal(await opened.invoke({action:'cancel'}),true);assert.equal(await opened.result,null);
});
test('known exact IDs and narrow one-shot messages are required',async()=>{
  const f=fixture(),opened=f.start();
  for(const input of [null,[],{}, {action:'unknown'},{action:'select',id:'../other'}, {action:'select',id:'ffffffff-ffff-4fff-9fff-ffffffffffff'},
    {action:'select',id:'default',password:'must-not-be-accepted'}, {action:'add',id:'default'}, {action:'list',extra:true}]) assert.equal(await opened.invoke(input),false);
  assert.equal(await opened.invoke({action:'add'}),true);assert.equal(JSON.stringify(await opened.result),JSON.stringify({action:'add'}));
  await assert.rejects(opened.invoke({action:'select',id:'default'}));assert.equal(f.handlers.size,0);
});
test('abort before creation and while open cancels and removes the shutdown listener',async()=>{
  const f=fixture(),before=new Signal();before.abort();assert.equal(await f.choose({profiles,signal:before}),null);assert.equal(f.windows.length,0);
  const signal=new Signal(),opened=f.start({signal});assert.equal(signal.listeners,1);signal.abort();assert.equal(await opened.result,null);
  assert.equal(opened.window.destroyed,true);assert.equal(signal.listeners,0);assert.equal(f.handlers.size,0);
});
test('close, owner close, unexpected navigation, and renderer exit all settle once and clean IPC',async()=>{
  for(const reason of ['close','parent','navigation','renderer']){
    const f=fixture(),parent=new EventEmitter();parent.isDestroyed=()=>false;const signal=new Signal(),opened=f.start({parent,signal});
    if(reason==='close')opened.window.destroy();if(reason==='parent')parent.emit('closed');
    if(reason==='navigation')opened.window.webContents.emit('did-navigate',{},'https://example.test');
    if(reason==='renderer')opened.window.webContents.emit('render-process-gone',{},{});
    assert.equal(await opened.result,null);assert.equal(f.handlers.size,0);assert.equal(signal.listeners,0);assert.equal(parent.listenerCount('closed'),0);
    signal.abort();assert.equal(opened.window.destroyed,true);
  }
});
test('invalid registries create no window and separate picker calls get distinct partitions and channels',async()=>{
  const f=fixture();for(const value of [[...profiles,profiles[0]], [{id:'../bad',label:'Bad',selected:false}], Array.from({length:65},()=>profiles[0]),
    [{id:'default',label:'',selected:true}],profiles.map(value=>({...value,selected:true}))])await assert.rejects(f.choose({profiles:value}));
  assert.equal(f.windows.length,0);const first=f.start(),second=f.start();assert.notEqual(first.channel,second.channel);assert.notEqual(f.sessions[0].partition,f.sessions[1].partition);
  await first.invoke({action:'cancel'});await second.invoke({action:'cancel'});await Promise.all([first.result,second.result]);assert.equal(f.handlers.size,0);
});
