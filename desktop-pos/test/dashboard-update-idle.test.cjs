'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const { EventEmitter } = require('node:events');
const policy = require('../src/dashboard-policy.cjs');

function fixture(options = {}) {
  const windows = [], listeners = new Map(); let next = 0, closing = false, afterProbe;
  const document = { readyState: 'complete', activeElement: { matches: () => false },
    querySelector: () => null, querySelectorAll: () => [] };
  const session = { webRequest: {
    onBeforeSendHeaders(_filter, work) { this.headers = work; },
    onCompleted(_filter, work) { this.completed = work; }, onErrorOccurred(_filter, work) { this.error = work; }
  }, setPermissionCheckHandler() {}, setPermissionRequestHandler() {} };
  const page = { location: { get href() { return windows[0].url; } },
    addEventListener(name, work) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(work); },
    removeEventListener(name, work) { listeners.get(name)?.delete(work); } };
  const renderer = vm.createContext({ window: page, document, URL });
  class Window extends EventEmitter {
    constructor(configuration) {
      super(); this.options = configuration; this.destroyed = false; this.loads = []; this.prints = 0; this.reloads = 0;
      const contents = this.webContents = new EventEmitter();
      contents.id = ++next; contents.session = session; contents.getURL = () => this.url;
      contents.setWindowOpenHandler = work => { contents.popup = work; };
      contents.executeJavaScript = async source => {
        const result = vm.runInContext(source, renderer);
        if (!source.includes('__goErpUpdateIdleGuard') && afterProbe) { const work = afterProbe; afterProbe = null; work(); }
        return result;
      };
      contents.print = (_configuration, callback) => { this.prints++; callback?.(true); };
      contents.stop = () => { this.stopped = true; }; contents.send = () => {};
      windows.push(this);
    }
    maximize() {} show() {} focus() {} isVisible() { return true; } isMinimized() { return false; }
    isDestroyed() { return this.destroyed; } destroy() { this.destroyed = true; }
    async loadURL(url) { this.loads.push(url); this.url = url; } reload() { this.reloads++; }
    static getFocusedWindow() { return windows[0]; }
  }
  const electron = { BrowserWindow: Window, ipcMain: { handle() {} }, shell: { openExternal() {} },
    dialog: { showMessageBox: async () => ({ response: 1 }) },
    Menu: { buildFromTemplate: value => value, setApplicationMenu: value => { electron.menu = value; } } };
  const module = { exports: {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../src/dashboard.cjs'), 'utf8'), {
    module, __dirname: path.join(__dirname, '../src'), URL, AbortSignal, setTimeout, clearTimeout,
    require: name => name === 'electron' ? electron : require(name.startsWith('./') ? path.join(__dirname, '../src', name) : name)
  });
  const dashboard = module.exports({ origin: policy.DEFAULT_ORIGIN, offline() {}, offlineWindow: () => windows[0],
    quitting: () => closing, quit() {}, printer: () => '', ...options });
  const event = () => ({ prevented: false, stopped: false,
    preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; } });
  return { dashboard, windows, document, page, session, electron, event,
    race: work => { afterProbe = work; }, close: () => { closing = true; },
    dispatch: name => { const value = event(); for (const work of listeners.get(name) || []) work(value); return value; },
    listenerCount: () => [...listeners.values()].reduce((sum, value) => sum + value.size, 0) };
}

test('update idle gate blocks input, forms, native requests, navigation and menu reload without replacing the page', async () => {
  const f = fixture(); await f.dashboard.open(); const w = f.windows[0], original = w.url, loads = w.loads.length;
  let finish, started; const entered = new Promise(resolve => { started = resolve; });
  const holding = f.dashboard.withUpdateIdle(async () => { started(); await new Promise(resolve => { finish = resolve; }); return 'installed'; });
  await entered;
  for (const name of ['pointerdown', 'keydown', 'beforeinput', 'paste', 'drop', 'submit']) {
    const value = f.dispatch(name); assert.equal(value.prevented, true, name); assert.equal(value.stopped, true, name);
  }
  const keyboard = f.event(); w.webContents.emit('before-input-event', keyboard, {}); assert.equal(keyboard.prevented, true);
  const navigation = f.event(); w.webContents.emit('will-navigate', navigation, policy.DEFAULT_ORIGIN + '/admin/employees');
  assert.equal(navigation.prevented, true); assert.equal(w.webContents.popup({ url: original }).action, 'deny');
  let decision; f.session.webRequest.headers({ id: 4, url: original, method: 'POST', webContentsId: w.webContents.id, requestHeaders: {} }, value => { decision = value; });
  assert.equal(decision.cancel, true); f.session.webRequest.completed({ id: 4 });
  f.electron.menu.find(value => value.label === 'عرض').submenu[0].click();
  f.electron.menu.find(value => value.label === 'طباعة').submenu[0].click();
  assert.equal(w.reloads, 0); assert.equal(w.prints, 0); assert.equal(await f.dashboard.canRefresh(), false);
  assert.equal(w.url, original); assert.equal(w.loads.length, loads); assert.equal(w.stopped, undefined);
  finish(); assert.equal(await holding, 'installed'); assert.equal(f.listenerCount(), 0);
  assert.equal(w.webContents.listenerCount('before-input-event'), 0); assert.equal(await f.dashboard.canRefresh(), true);
  assert.equal(w.loads.length, loads);
});

function originalLogin(f) {
  const form={method:'post',action:policy.DEFAULT_ORIGIN+'/admin/signin'};
  const fields=['email','password','remember'].map(name=>({form,name,tagName:'INPUT',type:name==='remember'?'checkbox':name,
    get value(){throw Error('Account switching must never read credentials.');},get checked(){throw Error('Account switching must not read remember choice.');},matches:()=>true}));
  f.document.querySelector=selector=>selector==='form[data-dashboard-login]'?form:null;
  f.document.querySelectorAll=selector=>selector.startsWith('.modal')?[]:fields;
  f.document.activeElement=fields[1];
  return {form,fields};
}

test('explicit account switching fences a filled original login form without reading credentials while automatic updates stay deferred',async()=>{
  const f=fixture();await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/login');originalLogin(f);
  assert.equal(await f.dashboard.canSwitchAccount(),true);assert.equal(await f.dashboard.canRefresh(),false);
  assert.equal((await f.dashboard.withUpdateIdle(()=>assert.fail('Automatic updates cannot retire a login form.'),{allowLogin:true})).deferred,true);
  let applied=0;assert.equal(await f.dashboard.withAccountIdle(async()=>{
    applied++;assert.equal(f.dispatch('submit').prevented,true);
    let decision;f.session.webRequest.headers({id:18,url:policy.DEFAULT_ORIGIN+'/admin/signin',method:'POST',webContentsId:f.windows[0].webContents.id,requestHeaders:{}},value=>decision=value);
    assert.equal(decision.cancel,true);f.session.webRequest.completed({id:18});return 'selected';
  }),'selected');
  assert.equal(applied,1);assert.equal(f.listenerCount(),0);assert.equal(f.windows[0].loads.length,1);
});

test('the account login exception rejects aliases, foreign routes and altered login form destinations',async()=>{
  for(const pathname of ['/admin/login/','/%61dmin/login','/admin/signin','/admin/login-other']){
    const f=fixture();await f.dashboard.open(policy.DEFAULT_ORIGIN+pathname);originalLogin(f);
    assert.equal(await f.dashboard.canSwitchAccount(),false,pathname);assert.equal((await f.dashboard.withAccountIdle(()=>assert.fail())).deferred,true);
  }
  for(const change of [{method:'get'},{action:'https://foreign.test/admin/signin'},{action:policy.DEFAULT_ORIGIN+'/admin/signin?actor=other'}]){
    const f=fixture();await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/login');Object.assign(originalLogin(f).form,change);
    assert.equal(await f.dashboard.canSwitchAccount(),false);assert.equal((await f.dashboard.withAccountIdle(()=>assert.fail())).deferred,true);
  }
});

test('account switching still retains other drafts and rechecks the original login form under its final input fence',async()=>{
  for(const kind of ['other-draft','changed-action','business-route']){
    const f=fixture();await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/login');const {form,fields}=originalLogin(f);
    f.race(()=>{
      if(kind==='other-draft')fields.push({tagName:'INPUT',name:'business-note',form:null,value:'unsaved work',defaultValue:''});
      else if(kind==='changed-action')form.action='https://foreign.test/admin/signin';
      else f.windows[0].url=policy.DEFAULT_ORIGIN+'/admin/takeaway';
    });
    let applied=false;assert.equal((await f.dashboard.withAccountIdle(()=>{applied=true;})).deferred,true,kind);
    assert.equal(applied,false);assert.equal(f.listenerCount(),0);assert.equal(f.windows[0].loads.length,1);
  }
});

test('account switching retains the same native printing, active request and business idle barriers',async()=>{
  const f=fixture();await f.dashboard.open();let applied=0,finishPrint;
  f.windows[0].webContents.print=(_options,callback)=>finishPrint=callback;
  f.electron.menu.find(value=>value.label==='طباعة').submenu[0].click();
  assert.equal((await f.dashboard.withAccountIdle(()=>applied++)).deferred,true);finishPrint(true);
  f.session.webRequest.headers({id:19,url:f.windows[0].url,method:'GET',webContentsId:f.windows[0].webContents.id,requestHeaders:{}},()=>{});
  assert.equal((await f.dashboard.withAccountIdle(()=>applied++)).deferred,true);f.session.webRequest.completed({id:19});
  await f.dashboard.open(policy.DEFAULT_ORIGIN+'/admin/takeaway');
  f.page.DashboardSPA={isIdle:()=>false};assert.equal((await f.dashboard.withAccountIdle(()=>applied++)).deferred,true);
  f.page.DashboardSPA.isIdle=()=>true;assert.equal(await f.dashboard.withAccountIdle(()=>++applied),1);
});

test('an edit arriving after the first idle probe defers update and preserves the form', async () => {
  const f = fixture(); await f.dashboard.open(); const w = f.windows[0], field = { tagName: 'INPUT', type: 'text', value: '', defaultValue: '' };
  f.document.querySelectorAll = selector => selector.startsWith('.modal') ? [] : [field]; f.race(() => { field.value = 'new unsaved work'; });
  let applied = false; const result = await f.dashboard.withUpdateIdle(async () => { applied = true; });
  assert.equal(result.deferred, true); assert.equal(applied, false); assert.equal(field.value, 'new unsaved work');
  assert.equal(w.loads.length, 1); assert.equal(f.listenerCount(), 0); assert.equal(w.stopped, undefined);
  assert.equal(w.webContents.listenerCount('before-input-event'), 0);
});

test('navigation or a new child between probes is retained instead of closed or reset', async () => {
  for (const kind of ['navigation', 'child']) {
    const f = fixture(); await f.dashboard.open(); const w = f.windows[0]; let child, applied = false;
    f.race(() => {
      if (kind === 'navigation') w.url = policy.DEFAULT_ORIGIN + '/admin/takeaway';
      else { child = new w.constructor(w.options); w.webContents.emit('did-create-window', child); }
    });
    const result = await f.dashboard.withUpdateIdle(async () => { applied = true; });
    assert.equal(result.deferred, true); assert.equal(applied, false); assert.equal(w.loads.length, 1);
    if (child) assert.equal(child.destroyed, false);
    else assert.match(w.url, /\/admin\/takeaway$/);
    assert.equal(f.listenerCount(), 0); assert.equal(w.stopped, undefined);
  }
});

test('update idle gate unlocks the original page after work fails', async () => {
  const f = fixture(); await f.dashboard.open(); const w = f.windows[0], url = w.url;
  await assert.rejects(f.dashboard.withUpdateIdle(async () => { throw Error('installer refused'); }), /installer refused/);
  assert.equal(f.listenerCount(), 0); assert.equal(w.webContents.listenerCount('before-input-event'), 0);
  assert.equal(await f.dashboard.canRefresh(), true); assert.equal(w.url, url); assert.equal(w.loads.length, 1);
});

test('active native printing and page requests defer the automatic installer', async () => {
  const f = fixture(); await f.dashboard.open(); const w = f.windows[0]; let finishPrint, applied = 0;
  w.webContents.print = (_configuration, callback) => { finishPrint = callback; };
  f.electron.menu.find(value => value.label === 'طباعة').submenu[0].click();
  assert.equal((await f.dashboard.withUpdateIdle(async () => applied++)).deferred, true); finishPrint(true);
  f.session.webRequest.headers({ id: 5, url: w.url, method: 'GET', webContentsId: w.webContents.id, requestHeaders: {} }, () => {});
  assert.equal((await f.dashboard.withUpdateIdle(async () => applied++)).deferred, true); assert.equal(applied, 0);
  f.session.webRequest.completed({ id: 5 }); await f.dashboard.withUpdateIdle(async () => applied++); assert.equal(applied, 1);
});

test('a completed HTTP write still blocks installation until its durable outcome settles', async () => {
  let settle;
  const f = fixture({ remoteAttempts: { begin: async () => ({}), complete: () => new Promise(resolve => { settle = resolve; }) } });
  await f.dashboard.open(); const w = f.windows[0]; let applied = false;
  f.session.webRequest.headers({ id: 6, url: policy.DEFAULT_ORIGIN + '/admin/employees/save', method: 'POST',
    webContentsId: w.webContents.id, requestHeaders: {} }, () => {});
  await new Promise(resolve => setImmediate(resolve)); f.session.webRequest.completed({ id: 6 });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal((await f.dashboard.withUpdateIdle(async () => { applied = true; })).deferred, true);
  assert.equal(applied, false); settle(); await new Promise(resolve => setImmediate(resolve));
  await f.dashboard.withUpdateIdle(async () => { applied = true; }); assert.equal(applied, true);
});

test('an unfocused rich editor defers automatic installation because it has no reliable default value', async () => {
  const f = fixture(); await f.dashboard.open(); let applied = false;
  f.document.querySelector = selector => selector.startsWith('[contenteditable]') ? {} : null;
  const result = await f.dashboard.withUpdateIdle(async () => { applied = true; });
  assert.equal(result.deferred, true); assert.equal(applied, false); assert.equal(f.listenerCount(), 0);
});

test('shutdown keeps the update input fence until the window is destroyed', async () => {
  const f = fixture(); await f.dashboard.open();
  await f.dashboard.withUpdateIdle(async () => { f.close(); return { installing: true }; });
  assert.ok(f.listenerCount() > 0); assert.equal(f.dispatch('submit').prevented, true);
  assert.equal(f.windows[0].loads.length, 1);
});

test('ordinary business pages require an exact synchronous scoped idle result before both handoff probes', async () => {
  for (const route of ['takeaway','branch-expenses','branch-stock','branch-shifts']) {
    const f = fixture(); await f.dashboard.open(policy.DEFAULT_ORIGIN + '/admin/' + route);
    let applied = 0;
    assert.equal(await f.dashboard.canRefresh(), false);
    for (const probe of [() => false, () => 1, () => Promise.resolve(true), () => { throw Error('page busy'); }]) {
      f.page.DashboardSPA = { isIdle: probe };
      assert.equal((await f.dashboard.withUpdateIdle(async () => applied++)).deferred, true);
    }
    f.page.DashboardSPA = { isIdle: () => true };
    assert.equal(await f.dashboard.canRefresh(), true);
    f.race(() => { f.page.DashboardSPA.isIdle = () => false; });
    assert.equal((await f.dashboard.withUpdateIdle(async () => applied++)).deferred, true);
    assert.equal(applied, 0); assert.equal(f.listenerCount(), 0);
    f.page.DashboardSPA.isIdle = () => true;
    await f.dashboard.withUpdateIdle(async () => applied++);
    assert.equal(applied, 1); assert.equal(f.listenerCount(), 0);
  }
});

test('hidden modal templates permit a handoff while an open visible HTML dialog preserves work', async () => {
  const f = fixture(); await f.dashboard.open(); let applied = 0;
  let hidden = true, rendered = true;
  f.document.querySelectorAll = selector => selector.startsWith('.modal')
    ? [{ closest: () => hidden ? {} : null, getClientRects: () => rendered ? [{}] : [] }] : [];
  await f.dashboard.withUpdateIdle(async () => applied++); assert.equal(applied, 1);
  hidden = false; rendered = false;
  await f.dashboard.withUpdateIdle(async () => applied++); assert.equal(applied, 2);
  rendered = true;
  assert.equal((await f.dashboard.withUpdateIdle(async () => applied++)).deferred, true);
  assert.equal(applied, 2); assert.equal(f.listenerCount(), 0);
});

test('data refresh uses the atomic renderer fence and retires the old form before unlocking', async () => {
  const origin='http://127.0.0.1:43123',f=fixture({origin,localToken:'private'});
  await f.dashboard.open(origin+'/admin/takeaway');f.page.DashboardSPA={isIdle:()=>true};
  let finish,started;const entered=new Promise(resolve=>{started=resolve;});
  const refreshing=f.dashboard.refresh(async()=>{started();await new Promise(resolve=>{finish=resolve;});return 'activated';});
  await entered;
  assert.equal(f.dispatch('beforeinput').prevented,true);assert.equal(f.dispatch('submit').prevented,true);
  let decision;f.session.webRequest.headers({id:77,url:origin+'/admin/takeaway/save',method:'POST',webContentsId:f.windows[0].webContents.id,requestHeaders:{}},value=>{decision=value;});
  assert.equal(decision.cancel,true);assert.equal(f.windows[0].loads.length,1);
  finish();assert.equal(await refreshing,'activated');
  assert.deepEqual(f.windows[0].loads,[origin+'/admin/takeaway','about:blank',origin+'/admin/takeaway']);
  assert.equal(f.listenerCount(),0);assert.equal(f.dispatch('beforeinput').prevented,false);
});

test('a failed old-document retirement retains the fence and asks for restart',async()=>{
  const origin='http://127.0.0.1:43123',f=fixture({origin,localToken:'private'});
  await f.dashboard.open();const w=f.windows[0],url=w.url;
  w.loadURL=async()=>{throw Error('fixture renderer failed');};
  await assert.rejects(f.dashboard.refresh(async()=> 'activated'),error=>error.code==='DASHBOARD_HANDOFF_RESTART_REQUIRED');
  assert.equal(w.url,url);assert.ok(f.listenerCount()>0);assert.equal(f.dispatch('submit').prevented,true);
  assert.equal(await f.dashboard.canRefresh(),false);
});
