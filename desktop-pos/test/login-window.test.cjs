'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {EventEmitter}=require('node:events'),{createRequire}=require('node:module');
const file=path.join(__dirname,'../src/login-window.cjs'),localRequire=createRequire(file);

function fixture(t,{remembered=async()=>null}={}){
  const handlers=new Map(),windows=[];let quitting=false;
  const broker={editing:0,cancels:0,calls:0,onState:()=>{},resumeRemembered:remembered,
    userEditing(){this.editing++;},cancel(){this.cancels++;},async authenticate(){this.calls++;return {};}};
  class Window extends EventEmitter{
    constructor(options){super();this.options=options;this.fullscreen=options.fullscreen;this.toggles=[];this.destroyed=false;
      this.webContents=new EventEmitter();Object.assign(this.webContents,{url:'about:blank',mainFrame:{url:'about:blank'},
        getURL(){return this.url;},setWindowOpenHandler(){},send(){},session:{setPermissionRequestHandler(){},setPermissionCheckHandler(){},webRequest:{onBeforeRequest(){}}}});
      windows.push(this);}
    removeMenu(){this.menuRemoved=true;}
    async loadURL(url){this.webContents.url=url;this.webContents.mainFrame.url=url;}
    show(){}focus(){}isMinimized(){return false;}isDestroyed(){return this.destroyed;}
    isFullScreen(){return this.fullscreen;}
    setFullScreen(value){this.toggles.push(value);this.fullscreen=value;}
    destroy(){if(!this.destroyed){this.destroyed=true;this.emit('closed');}}
  }
  const electron={BrowserWindow:Window,ipcMain:{handle:(name,handler)=>handlers.set(name,handler),removeHandler:name=>handlers.delete(name)}};
  const module={exports:{}};vm.runInNewContext(fs.readFileSync(file,'utf8'),{module,__dirname:path.dirname(file),require:name=>name==='electron'?electron:localRequire(name),Promise,Set,Error},{filename:file});
  const login=module.exports({broker,quitting:()=>quitting}),window=windows[0];
  t.after(()=>login.cancel());
  function key(input){let prevented=0;window.webContents.emit('before-input-event',{preventDefault(){prevented++;}},input);return prevented;}
  const submit=input=>handlers.get([...handlers.keys()].find(name=>!name.endsWith(':intent')))({sender:window.webContents,senderFrame:window.webContents.mainFrame},input);
  return {login,window,broker,key,submit,quit:()=>{quitting=true;}};
}

test('F12 exits and restores native login full screen once per press without changing account input',t=>{
  const f=fixture(t);assert.equal(f.window.menuRemoved,true);assert.equal(f.window.fullscreen,true);
  assert.equal(f.key({key:'F12',type:'keyDown',isAutoRepeat:false}),1);
  assert.equal(f.window.fullscreen,false);
  assert.equal(f.key({key:'F12',type:'keyUp',isAutoRepeat:false}),1);
  assert.equal(f.key({key:'F12',type:'keyDown',isAutoRepeat:true}),1);
  for(const modifier of ['alt','control','meta','shift'])assert.equal(f.key({key:'F12',type:'keyDown',[modifier]:true}),1);
  assert.deepEqual(f.window.toggles,[false],'release, hold and modified keys must not retoggle the window');
  assert.equal(f.key({key:'F12',type:'keyDown',isAutoRepeat:false}),1);
  assert.deepEqual(f.window.toggles,[false,true]);assert.equal(f.broker.editing,0);assert.equal(f.broker.calls,0);
});

test('F12 preserves a pending remembered login while normal typing still latches account editing',async t=>{
  let resolve;const remembered=new Promise(yes=>{resolve=yes;}),f=fixture(t,{remembered:()=>remembered});
  f.window.webContents.emit('dom-ready');await Promise.resolve();
  f.key({key:'F12',type:'keyDown'});f.key({key:'F12',type:'keyUp'});
  const result={account:{id:'remembered-owned-account'}};resolve(result);
  assert.equal(await f.login.result,result);assert.equal(f.broker.editing,0);

  const typed=fixture(t);assert.equal(typed.key({key:'a',type:'keyDown'}),0);assert.equal(typed.broker.editing,1);
  typed.key({key:'a',type:'keyUp'});typed.key({key:'F12',type:'keyDown'});
  assert.equal(typed.broker.editing,1,'view commands must not invoke account intent a second time');
});

test('a closing or disposed login cannot change native window state through F12',t=>{
  const f=fixture(t);f.quit();assert.equal(f.key({key:'F12',type:'keyDown'}),1);assert.deepEqual(f.window.toggles,[]);
  f.login.dispose();assert.equal(f.key({key:'F12',type:'keyDown'}),1);assert.deepEqual(f.window.toggles,[]);
});

test('a local account failure does not claim the Internet is disconnected or expose private error details',async t=>{
  const f=fixture(t);
  f.broker.authenticate=async()=>{throw Error('PRIVATE_PASSWORD PRIVATE_ACCOUNT_PATH');};
  const input={email:'owner@example.test',password:'transient-password',remember:false};
  const result=await f.submit(input);
  assert.equal(result.ok,false);assert.match(result.error,/تعذر إكمال تسجيل الدخول/);
  assert.doesNotMatch(result.error,/الإنترنت|إنترنت|PRIVATE_|transient-password/);
  assert.equal(input.password,'');assert.equal(f.window.destroyed,false);
});
