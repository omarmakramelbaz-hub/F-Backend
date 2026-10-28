'use strict';
const path = require('node:path');
const crypto = require('node:crypto');
const { BrowserWindow, Menu, shell, dialog, ipcMain } = require('electron');
const getPolicy=require('./dashboard-get-policy.cjs');
const policy = require('./dashboard-policy.cjs');
const preparationClock = require('node:fs').readFileSync(path.join(__dirname, 'preparation-clock.js'), 'utf8');
const { DashboardPrintEffects } = require('./dashboard-print-effects.cjs');
const { withDeadline, printOnce } = require('./native-deadline.cjs');
// config/auth.php uses the original Laravel session guard named admin.
// An explicit logout removes its recaller from this account's native session.
const adminRecallerName='remember_admin_'+crypto.createHash('sha1').update('Illuminate\\Auth\\SessionGuard').digest('hex');

/** Runs the existing dashboard unchanged. Remote pages receive no Node or POS bridge. */
module.exports = function dashboard({ origin, serverOrigin = null, offline, offlineWindow, quitting, quit, printer, localToken = '', partition = 'persist:fasakhansta-dashboard',
  prepare, connected, reauthenticate, localMode, reviewCentral, browse, remoteState, remoteAttempts, archive, selectPrinter, reviewSync, updateStatus, printControl, printMetadata, liveSocial, switchAccount,
  onVisible = () => {}, onNavigation = () => {}, beforeLogout = async () => {}, status = async () => ({ available: false }), synchronize = async () => {}, accepted = fn => fn() }) {
  const workspacePartition=typeof partition==='string'&&/^persist:go-erp-workspace-[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}-([1-9][0-9]{0,15})$/.exec(partition);
  if(typeof partition!=='string'||!/^persist:fasakhansta-dashboard(?:-[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})?$/.test(partition)
    &&!(workspacePartition&&Number.isSafeInteger(Number(workspacePartition[1]))))throw Error('ملف جلسة الداشبورد غير صالح.');
  let home = origin + '/admin/dashboard';
  if (!serverOrigin && !localToken) serverOrigin = origin;
  let window, presentationReady = false, loading = false, refreshing = false, printing = 0, authentication = null, loggingOut = false;
  let firstRead = null, firstReadStarted = false, navigationStartedAt = Date.now(), lastResponse = null, sessionRecovery = false, pendingLogin = null;
  let logoutDecision=null,logoutFinishing=null,spaLogout=null,logoutNavigation=false,signinCookieCleanupBlocked=false,accountSwitchPending=false;
  const owned = new Set();
  const children = new Set();
  const remoteWrites = new Map();
  const nativeConnectedPrints = new Map();
  const activeRequests = new Set();
  function hideLogoutWindows() {
    // The SPA treats an intentionally cancelled fetch as a navigation failure.
    // Once logout is confirmed, close its visible surface before shutdown drains.
    for(const target of [window,...children])if(target&&!target.isDestroyed())target.hide();
  }
  function ownedSpaLogout(details,contents) {
    if(details.resourceType!=='xhr'||!Number.isInteger(details.id)
      ||!Object.entries(details.requestHeaders).some(([name,value])=>name.toLowerCase()==='x-dashboard-spa'&&value==='1'))return false;
    const owner=details.webContentsId===contents.id?contents:[...children].find(child=>!child.isDestroyed()&&child.webContents.id===details.webContentsId)?.webContents;
    try{return Boolean(owner&&details.frame&&details.frame===owner.mainFrame&&policy.sameOrigin(details.frame.url,origin)
      &&getPolicy.admin(new URL(details.frame.url).pathname));}catch{return false;}
  }
  function failSpaLogout(message) {
    if(!spaLogout)return;
    clearTimeout(spaLogout.timer);spaLogout=null;
    // The original logout may still complete after a transport timeout. Keep a
    // fresh sign-in from racing its eventual cookie/session invalidation.
    signinCookieCleanupBlocked=true;loggingOut=false;logoutDecision=null;
    if(!quitting())Promise.resolve(dialog.showMessageBox(window,{type:'error',title:'GO ERP',message,
      buttons:['إغلاق البرنامج','البقاء في الصفحة'],defaultId:0,cancelId:1,noLink:true}))
      .then(choice=>{if(choice.response===0&&!quitting())quit();}).catch(()=>{});
  }
  function navigationTrace(phase, value, status) {
    if(!policy.sameOrigin(value,origin))return;
    const pathname=new URL(value).pathname;
    const path=['/admin/dashboard','/admin/login','/admin/signin'].includes(pathname)?pathname:'/admin/other';
    const entry={phase,mode:localToken?'local':'server',path,elapsed_ms:Math.min(Number.MAX_SAFE_INTEGER,Math.max(0,Date.now()-navigationStartedAt))};
    if(Number.isInteger(status)&&status>=100&&status<=599)entry.status=status;
    try{Promise.resolve(onNavigation(entry)).catch(()=>{});}catch{}
  }
  async function loadFirstRead(target,url) {
    // Readiness must not wait for remote fonts/images to finish. This deadline
    // belongs only to the initial native GET, never to an original form POST.
    const contents=target.webContents;
    return new Promise((resolve,reject)=>{
      let settled=false,timer;
      const finish=(error,phase)=>{
        if(settled)return;settled=true;clearTimeout(timer);
        contents.removeListener('dom-ready',interactive);target.removeListener('closed',closed);
        firstRead=null;navigationTrace(phase,target.isDestroyed()?url:(contents.getURL()||url));
        error?reject(error):resolve(phase);
      };
      const interactive=()=>{if(policy.sameOrigin(contents.getURL(),origin))finish(null,'interactive');},closed=()=>finish(null,'failed');
      firstRead={handoff:()=>finish(null,'user-navigation')};
      contents.on('dom-ready',interactive);target.once('closed',closed);
      timer=setTimeout(()=>{
        finish(Error('تحميل صفحة الداشبورد أخذ وقتًا أطول من المسموح. أعد فتح صفحة الدخول من قائمة البرنامج.'),'timeout');
        if(!target.isDestroyed())contents.stop();
      },45000);
      Promise.resolve().then(()=>target.loadURL(url)).then(()=>finish(null,'loaded'),error=>finish(error,'failed'));
    });
  }
  function resumeFreshLogin() {
    if(!pendingLogin||loading)return;
    const expected=pendingLogin;pendingLogin=null;
    if(quitting()||refreshing||authentication||!window||window!==expected.window||window.isDestroyed()
      ||lastResponse!==expected.response||window.webContents.getURL()!==expected.url)return;
    // A new GET obtains the current CSRF token. Never reload/replay the rejected
    // POST, clear a device profile, or weaken Laravel's CSRF/session checks.
    open(origin+'/admin/login').catch(()=>{});
  }
  function expiredSession(target,url,response) {
    if(sessionRecovery||quitting()||refreshing||authentication||loggingOut)return;
    sessionRecovery=true;firstRead?.handoff();
    Promise.resolve().then(()=>dialog.showMessageBox(target,{type:'warning',title:'GO ERP — جلسة الصفحة',
      message:'انتهت جلسة الصفحة. افتح صفحة دخول جديدة ثم أدخل بياناتك مرة أخرى. البيانات المحفوظة على الجهاز لم تتغير.',
      buttons:['فتح صفحة دخول جديدة','البقاء في الصفحة'],defaultId:0,cancelId:1,noLink:true}))
      .then(choice=>{if(choice.response===0){pendingLogin={window:target,url,response};resumeFreshLogin();}})
      .catch(()=>{}).finally(()=>{sessionRecovery=false;});
  }
  async function clearLogoutSession(cookieSession,scope) {
    let active=true;
    const current=()=>active&&!quitting()&&!refreshing&&!authentication&&origin===scope;
    try{return await withDeadline(async()=>{
      // Local and connected pages share only this selected account's partition.
      // Remove its original auth cookies in both modes; preserve preferences,
      // other hosts and all other account partitions, journals and profiles.
      const scopes=new Map();
      const add=value=>{
        const url=new URL(value);
        if(url.origin!==value||url.username||url.password||!['https:','http:'].includes(url.protocol))throw Error('تعذر تحديد جلسة الحساب الحالية.');
        scopes.set(url.protocol+'//'+url.hostname,value);
      };
      add(scope);
      if(serverOrigin){
        const remote=new URL(serverOrigin);
        if(remote.protocol!=='https:')throw Error('تعذر تحديد جلسة السيرفر الحالية.');
        add(serverOrigin);
      }
      if(!scopes.has('http://127.0.0.1'))add('http://127.0.0.1');
      const removals=[];
      for(const value of scopes.values()){
        const cookies=await cookieSession.cookies.get({url:value+'/admin/dashboard'});
        if(!current())return {cancelled:true};
        const host=new URL(value).hostname.toLowerCase();
        const scoped=cookie=>typeof cookie.domain==='string'&&cookie.path==='/'
          &&cookie.domain.replace(/^\./,'').toLowerCase()===host;
        const auth=cookies.filter(cookie=>scoped(cookie)&&(cookie.name===adminRecallerName
          ||cookie.httpOnly===true&&/^[a-zA-Z0-9_-]{1,120}_session$/.test(cookie.name)));
        if(auth.length>16)throw Error('تعذر تحديد جلسة الحساب الحالية.');
        for(const cookie of auth)removals.push([value+'/',cookie.name]);
      }
      for(const [url,name] of removals){
        if(!current())return {cancelled:true};
        await cookieSession.cookies.remove(url,name);
      }
      if(!current())return {cancelled:true};
      if(removals.length)await cookieSession.cookies.flushStore();
      return {cancelled:!current()};
    },5000,'تعذر تأكيد تسجيل الخروج. أغلق البرنامج ثم أعد فتحه.');}
    finally{active=false;}
  }
  function finishLogout(cookieSession,scope) {
    if(logoutFinishing)return logoutFinishing;
    if(spaLogout){clearTimeout(spaLogout.timer);spaLogout=null;}
    logoutFinishing=accepted(()=>clearLogoutSession(cookieSession,scope)).then(result=>{
      if(result.cancelled||quitting()||origin!==scope)return;
      hideLogoutWindows();quit();
    },error=>{
      // A durable signed-out marker is already committed. Keep this uncertain
      // browser process from racing a fresh sign-in or late cookie removal.
      signinCookieCleanupBlocked=true;
      if(!quitting())dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message});
    });
    return logoutFinishing;
  }
  async function clearPreviousRecaller(cookieSession,scope) {
    let active=true;
    const current=()=>active&&!quitting()&&!refreshing&&!authentication&&origin===scope;
    try{return await withDeadline(async()=>{
      const cookies=await cookieSession.cookies.get({url:scope+'/admin/login'});
      if(!current())return {cancelled:true};
      const host=new URL(scope).hostname.toLowerCase();
      const previous=cookies.filter(cookie=>cookie.name===adminRecallerName&&cookie.path==='/'&&typeof cookie.domain==='string'
        &&(cookie.domain.replace(/^\./,'').toLowerCase()===host
          ||cookie.hostOnly===false&&host.endsWith('.'+cookie.domain.replace(/^\./,'').toLowerCase())));
      for(const cookie of previous){
        if(!current())return {cancelled:true};
        await cookieSession.cookies.remove(scope+cookie.path,cookie.name);
      }
      if(!current())return {cancelled:true};
      if(previous.length)await cookieSession.cookies.flushStore();
      return {cancelled:!current()};
    },5000,'تعذر إكمال تجهيز جلسة الدخول. أغلق البرنامج ثم أعد فتحه.');}
    finally{active=false;}
  }
  function withoutPreviousRecaller(headers) {
    for(const name of Object.keys(headers))if(name.toLowerCase()==='cookie'){
      if(typeof headers[name]!=='string')throw Error('تعذر التحقق من جلسة الدخول.');
      const retained=headers[name].split(';').map(value=>value.trim()).filter(value=>value&&value.slice(0,value.indexOf('='))!==adminRecallerName);
      if(retained.length)headers[name]=retained.join('; ');else delete headers[name];
    }
  }
  function selectChanged(field) {
    const options = Array.from(field.options), defaults = options.filter(option => option.defaultSelected);
    if (field.multiple) return options.some(option => option.selected !== option.defaultSelected);
    let original = defaults[defaults.length - 1];
    if (!original && !(Number(field.size) > 1)) original = options.find(option => {
      const group = option.parentElement;
      return !option.disabled && !(group?.tagName === 'OPTGROUP' && group.disabled);
    });
    return options.some(option => option.selected !== (option === original));
  }
  function settledBoardFilters() {
    const none = () => new Set(), current = new URL(window.location.href);
    if (current.pathname !== '/admin/applies-orders') return none();
    const form = document.querySelector('form#ob-filters');
    if (!form || form.id !== 'ob-filters' || String(form.method).toUpperCase() !== 'GET'
        || (form.target && form.target !== '_self')) return none();
    const target = new URL(form.getAttribute('action'), current);
    if (target.origin !== current.origin || target.pathname !== current.pathname
        || target.username || target.password || target.search || target.hash) return none();
    const fields = new Set(), names = new Set();
    for (const field of Array.from(form.elements)) {
      if (!field.name) continue;
      if (field.form !== form || names.has(field.name)) return none();
      names.add(field.name);
      if (/^page_(?:new|preparing|courier|completed)$/.test(field.name)) {
        if (field.tagName !== 'INPUT' || field.type !== 'hidden') return none();
      } else if (['app', 'branch'].includes(field.name)) {
        if (field.tagName !== 'SELECT' || field.multiple || Number(field.size) > 1) return none();
        fields.add(field);
      } else if (['search', 'date'].includes(field.name)) {
        if (field.tagName !== 'INPUT' || field.type !== field.name) return none();
        fields.add(field);
      } else return none();
    }
    if (!['search', 'app', 'date'].every(name => names.has(name))) return none();
    const query = current.searchParams, submitted = new URLSearchParams(new FormData(form));
    for (const params of [query, submitted]) if (Array.from(params.keys()).some(name => !names.has(name))
        || Array.from(params.keys()).length !== new Set(params.keys()).size) return none();
    const canonical = params => JSON.stringify(Array.from(params.entries()).filter(([name, value]) => value
      && !(/^page_(?:new|preparing|courier|completed)$/.test(name) && value === '1')).sort());
    // Only already-applied GET filters may differ from their original HTML
    // defaults. Every order-action/editor field keeps the normal draft guard.
    return canonical(submitted) === canonical(query) ? fields : none();
  }
  const businessPage = url => ['/admin/takeaway','/admin/applies-orders','/admin/branch-expenses','/admin/branch-stock','/admin/branch-shifts'].includes(new URL(url).pathname);
  const idlePage = url => ['/admin/dashboard','/admin/desktop-dashboard'].includes(new URL(url).pathname) || businessPage(url);
  const effects = printControl && printMetadata ? new DashboardPrintEffects({control:printControl,metadata:printMetadata,
    available:()=>Boolean(localToken) && !refreshing && !quitting(),prepareDocument:prepareEffectDocument}) : null;
  async function prepareEffectDocument(prepared) {
    const html=prepared.html;
    const protection='<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'none\'; img-src data: '+origin+'; style-src \'unsafe-inline\' '+origin+'; font-src '+origin+'; connect-src \'none\'; frame-src \'none\'; object-src \'none\'; base-uri \'none\'; form-action \'none\'">';
    const printWindow=new BrowserWindow({show:false,width:480,height:800,
      webPreferences:{partition,sandbox:true,contextIsolation:true,nodeIntegration:false}});
    const contents=printWindow.webContents;owned.add(contents.id);let closed=false;
    const close=()=>{if(closed)return;closed=true;owned.delete(contents.id);printWindow.destroy();};
    contents.on('will-navigate',event=>event.preventDefault());contents.setWindowOpenHandler(()=>({action:'deny'}));
    try {
      const {deviceName}=await withDeadline(async()=>{
        await printWindow.loadURL('data:text/html;charset=utf-8,'+encodeURIComponent(html.replace(/<head\b[^>]*>/i,match=>match+protection)));
        await contents.executeJavaScript('Promise.all([document.fonts.ready,...Array.from(document.images).map(i=>i.complete?(i.naturalWidth?Promise.resolve():Promise.reject(Error("Receipt image unavailable"))):new Promise((r,j)=>{i.onload=r;i.onerror=()=>j(Error("Receipt image unavailable"));}))]).then(()=>true)');
        const deviceName=printer()||'',printers=await window.webContents.getPrintersAsync();
        if(!printers.length || deviceName && !printers.some(value=>value.name===deviceName))throw Error('الطابعة غير متاحة. اختر الطابعة من قائمة البرنامج.');
        return {deviceName};
      },25000,'تعذر تحميل مستند الطباعة أو قراءة طابعات ويندوز.');
      return {close,invoke:callback=>{
        if(closed)throw Error('Print document closed.');
        contents.print({silent:true,printBackground:true,deviceName,margins:{marginType:'none'}},(ok)=>callback(ok));
      }};
    }catch(error){close();throw error;}
  }
  function ownedPrint(event) {
    return !quitting() && !refreshing && !authentication && owned.has(event.sender.id) && policy.sameOrigin(event.senderFrame.url,origin)
      && new URL(event.senderFrame.url).pathname.startsWith('/admin/');
  }
  for(const [name,work] of Object.entries({
    'print-effect':value=>{if(!localToken || !effects)throw Error('الطباعة المحلية تحتاج تجهيز الجهاز.');return effects.print(value);},
    'print-pending':()=>localToken?(effects?effects.pending():Promise.reject(Error('الطباعة المحلية تحتاج تحديث البرنامج.'))):{local:false}
  }))ipcMain.handle('dashboard:'+name,async(event,value)=>{
    try{return await accepted(async()=>{
      if(!ownedPrint(event))throw Error('مصدر الطباعة غير مسموح.');printing++;
      try{return {ok:true,value:await work(value)};}finally{printing--;}
    });}catch(error){return {ok:false,error:error.message};}
  });

  const readPosts = new Set(['/admin/takeaway/quote', '/admin/dining/quote', '/admin/phone-orders/quote', '/admin/phone-orders/delivery-quote',
    '/admin/phone-orders/address-suggestions',
    '/admin/fetch-subcategory', '/admin/fetch-product', '/admin/fetch-feature']);
  function remoteWrite(details) {
    if (localToken || !owned.has(details.webContentsId) || !policy.sameOrigin(details.url, origin)) return false;
    const pathname = new URL(details.url).pathname;
    if (readPosts.has(pathname)) return false;
    return getPolicy.mutation(pathname)||(getPolicy.admin(pathname) && (!['GET', 'HEAD'].includes(String(details.method || 'GET').toUpperCase())
      || /(?:delete|destroy|update|save|send|accept|finish|clear-cache|test-notification|resturantControl)/i.test(pathname)));
  }
  function failure(details = {}) {
    if (remoteWrites.size) return { ...details, method: 'UNKNOWN' };
    if (details.url && policy.sameOrigin(details.url, origin) && readPosts.has(new URL(details.url).pathname)) return { ...details, method: 'GET' };
    return details;
  }
  function trusted(event) {
    return !quitting() && !refreshing && !authentication && window && event.sender.id === window.webContents.id
      && policy.sameOrigin(event.senderFrame.url, origin) && new URL(event.senderFrame.url).pathname.startsWith('/admin/');
  }
  function loginAccountRequest(event) {
    try{return trusted(event)&&event.senderFrame===event.sender.mainFrame
      &&new URL(event.senderFrame.url).pathname==='/admin/login'&&typeof switchAccount==='function';}catch{return false;}
  }
  async function requestAccountSwitch() {
    if(typeof switchAccount!=='function'||accountSwitchPending||quitting()||refreshing||authentication||loggingOut||signinCookieCleanupBlocked)
      throw Error('تبديل الحساب غير متاح الآن. أكمل العملية الحالية ثم أعد المحاولة.');
    accountSwitchPending=true;
    try{return await accepted(()=>switchAccount());}finally{accountSwitchPending=false;}
  }
  ipcMain.handle('dashboard:account-switch-available',(event,value)=>({ok:true,value:value===undefined&&loginAccountRequest(event)}));
  ipcMain.handle('dashboard:switch-account',async(event,value)=>{
    try{
      if(value!==undefined||!loginAccountRequest(event))throw Error('مصدر تبديل الحساب غير مسموح.');
      return {ok:true,value:await requestAccountSwitch()};
    }catch(error){return {ok:false,error:error.message};}
  });
  for (const [name, work] of Object.entries({
    status: () => status(),
    prepare: value => {
      if (!prepare || localToken || !origin.startsWith('https://')) throw Error('تجهيز الجهاز يحتاج حساب السيرفر المتصل.');
      return prepare(origin, typeof value?.csrf === 'string' ? value.csrf : '');
    },
    synchronize: () => synchronize(),
    'live-social': kind => {
      if(!localToken||!liveSocial||!['messages','comments'].includes(kind))throw Error('افتح الرسائل والتعليقات من نسخة الجهاز المجهّزة.');
      return liveSocial(kind);
    },
    'review-sync': () => {
      if(!reviewSync)throw Error('جهّز الجهاز أولًا لمراجعة سجل المزامنة.');
      return reviewSync(window);
    }
  })) ipcMain.handle('dashboard:' + name, async (event, value) => {
    try {
      if (!trusted(event)) throw Error('مصدر الداشبورد غير مسموح.');
      return { ok: true, value: await accepted(() => work(value)) };
    } catch (error) { return { ok: false, error: error.message }; }
  });
  ipcMain.handle('dashboard:print-receipt', async (event, value) => { try { return await accepted(async () => {
    printing++;
    try {
      if (quitting()) throw Error('البرنامج يُغلق الآن.');
      if (authentication) throw Error('أكمل تسجيل دخول السيرفر أو أغلق نافذته قبل الطباعة.');
      if (refreshing) throw Error('يجري تحديث بيانات الداشبورد؛ أعد المحاولة بعد اكتماله.');
      const printUrl=typeof value==='string'?value:value?.url;
      if (!owned.has(event.sender.id) || !policy.sameOrigin(event.senderFrame.url, origin)
          || !policy.sameOrigin(printUrl, origin)) throw Error('مصدر الطباعة غير مسموح.');
      const url = new URL(printUrl);
      if (!url.pathname.startsWith('/admin/')) throw Error('رابط الفاتورة غير صحيح.');
      // Fragments never reach HTTP. Bind the native proof to the actual wire URL.
      url.hash='';
      if(localToken){
        if(!effects)throw Error('الطباعة المحلية تحتاج تحديث البرنامج.');
        return {ok:true,value:await effects.receipt(url.pathname,Object.fromEntries(url.searchParams),typeof value==='object'?value.request_id:undefined)};
      }
      url.searchParams.set('dashboard_print', '1');
      const attempt=remoteAttempts?crypto.randomUUID():null;let response,html,nativeMarker;
      const proof=attempt?await remoteAttempts.begin(attempt,{url:url.href,method:'GET'}):{};
      const printHeaders={Accept:'text/html',...proof};
      const groupNames=require('./workspace-protocol.cjs').WORKSPACE_HEADERS;
      const groupProof=groupNames.every(name=>typeof proof[name]==='string'&&proof[name].length>0)
        &&Object.keys(proof).every(name=>groupNames.includes(name)||['X-Fasakhansta-Workspace-Attempt','X-Fasakhansta-Workspace-Capability'].includes(name))
        &&Boolean(proof['X-Fasakhansta-Workspace-Attempt'])===Boolean(proof['X-Fasakhansta-Workspace-Capability']);
      if(groupProof||proof['X-Fasakhansta-Connected-Device']&&proof['X-Fasakhansta-Connected-Token']){
        // Only this native fetch can retain its paired actor proof through the
        // shared interceptor. The one-use marker never leaves this process.
        nativeMarker=crypto.randomBytes(32).toString('hex');
        nativeConnectedPrints.set(nativeMarker,{url:url.href,method:'GET',proof});
        printHeaders['X-Fasakhansta-Connected-Request']=nativeMarker;
      }
      try{
        response=await event.sender.session.fetch(url.href,{credentials:'include',headers:printHeaders,redirect:'error',signal:AbortSignal.timeout(25000)});
        html=await response.text();if(attempt)await remoteAttempts.complete(attempt);
      }catch(error){if(attempt)remoteAttempts.failed(attempt);throw error;}
      finally{if(nativeMarker)nativeConnectedPrints.delete(nativeMarker);}
      if (!response.ok || !/text\/html/i.test(response.headers.get('content-type') || '')) throw Error('تعذر تحميل الفاتورة.');
      if (quitting()) throw Error('البرنامج يُغلق الآن.');
      if (html.length > 3 * 1024 * 1024 || !/\bdata-dashboard-receipt\s*=/.test(html)) throw Error('الصفحة غير صالحة لطباعة فاتورة.');
      const localSource = localToken ? ' ' + origin : '';
      const protection = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'none\'; img-src https: data:' + localSource + '; style-src \'unsafe-inline\' https:' + localSource + '; font-src https:' + localSource + '; connect-src \'none\'; frame-src \'none\'; object-src \'none\'; base-uri \'none\'; form-action \'none\'">';
      if (!/<head\b/i.test(html)) throw Error('الصفحة غير صالحة لطباعة فاتورة.');
      const printWindow = new BrowserWindow({ show: false, width: 480, height: 800,
        webPreferences: { partition, sandbox: true, contextIsolation: true, nodeIntegration: false } });
      owned.add(printWindow.webContents.id);
      try {
        printWindow.webContents.on('will-navigate', event => event.preventDefault());
        printWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
        const {deviceName}=await withDeadline(async()=>{
          await printWindow.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html.replace(/<head\b[^>]*>/i, match => match + protection)));
          await printWindow.webContents.executeJavaScript('Promise.all([document.fonts.ready,...Array.from(document.images).map(i=>i.complete?Promise.resolve():new Promise((r,j)=>{i.onload=r;i.onerror=()=>j(Error("تعذر تحميل شعار الفاتورة"));}))]).then(()=>true)');
          const deviceName = printer() || '';
          const printers = await window.webContents.getPrintersAsync();
          if (!printers.length || (deviceName && !printers.some(item => item.name === deviceName))) throw Error('الطابعة غير متاحة. اختر الطابعة من قائمة البرنامج.');
          return {deviceName};
        },25000,'تعذر تحميل مستند الطباعة أو قراءة طابعات ويندوز.');
        if(quitting())throw Error('البرنامج يُغلق الآن. مستند الفاتورة محفوظ.');
        await printOnce(printWindow.webContents,{ silent: true, printBackground: true, deviceName,
          margins: { marginType: 'none' } });
        return { ok: true, value: true };
      } finally { owned.delete(printWindow.webContents.id); printWindow.destroy(); }
    } catch (error) { return { ok: false, error: error.message }; }
    finally { printing--; }
  }); } catch (error) { return { ok: false, error: error.message }; } });
  function reveal() {
    if (presentationReady && !quitting() && window && !window.isDestroyed()) {
      window.show(); if (window.isMinimized()) window.restore(); window.focus();
      try{Promise.resolve(onVisible()).catch(()=>{});}catch{}
    }
  }
  function revealFailure() {
    // A failed first navigation must leave the original error and the native
    // retry/quit menu available, rather than a process with no visible surface.
    presentationReady = true;
    reveal();
  }
  async function open(url = home) {
    if (loading || refreshing || authentication || loggingOut || signinCookieCleanupBlocked || quitting()) return;
    if (!policy.sameOrigin(url, origin)) throw Error('رابط الداشبورد غير صحيح.');
    loading = true;
    try {
      if (!window || window.isDestroyed()) {
        presentationReady = false;
        window = new BrowserWindow({ width: 1440, height: 950, minWidth: 1000, minHeight: 650, show: false, fullscreen: true,
          title: 'GO ERP — الداشبورد', icon: path.join(__dirname, 'assets', 'app.ico'), backgroundColor: '#f4f5f8',
          webPreferences: { preload: path.join(__dirname, 'dashboard-preload.cjs'), partition, sandbox: true, contextIsolation: true,
            nodeIntegration: false, webSecurity: true } });
        const contents = window.webContents;
        contents.on('dom-ready',()=>{
          if(quitting()||!policy.sameOrigin(contents.getURL(),origin)
            ||new URL(contents.getURL()).pathname!=='/admin/desktop-dashboard')return;
          contents.executeJavaScript(preparationClock).catch(()=>{});
        });
        contents.on('dom-ready',()=>{
          if(presentationReady||quitting()||!window||window.isDestroyed()||window.webContents!==contents
            ||!policy.sameOrigin(contents.getURL(),origin))return;
          presentationReady=true;
          reveal();
        });
        contents.on('page-title-updated',event=>event.preventDefault());
        contents.on('did-start-navigation',(event,url,_sameDocument,isMainFrame)=>{
          const target=event.url||url,main=event.isMainFrame??isMainFrame;
          if(main===false||!policy.sameOrigin(target,origin))return;
          lastResponse=null;navigationStartedAt=Date.now();navigationTrace('started',target);
        });
        contents.on('did-navigate',(_event,url,status)=>{
          if(!policy.sameOrigin(url,origin))return;
          lastResponse={url,status};navigationTrace('response',url,status);
          if(status===419)expiredSession(window,url,lastResponse);
        });
        owned.add(contents.id);
        contents.session.webRequest.onBeforeSendHeaders({ urls: ['<all_urls>'] }, (details, callback) => {
            if(details.webContentsId===contents.id&&details.resourceType==='mainFrame'
              &&(!['GET','HEAD'].includes(String(details.method||'GET').toUpperCase())
                ||getPolicy.mutation(new URL(details.url).pathname)))firstRead?.handoff();
            const headers = { ...details.requestHeaders };
            const supplied = Object.entries(headers).find(([name]) => name.toLowerCase() === 'x-fasakhansta-desktop')?.[1];
            const nativeMarker=Object.entries(headers).find(([name])=>name.toLowerCase()==='x-fasakhansta-connected-request')?.[1];
            const connectedSupplied=Object.keys(headers).some(name=>/^x-fasakhansta-(?:connected-(?:device|token|request)|workspace-.+)$/i.test(name));
            for (const name of Object.keys(headers)) if (/^x-fasakhansta-(?:desktop|control|remote-attempt|remote-capability|connected-.+|workspace-.+)$/i.test(name)) delete headers[name];
            if(authentication&&authentication.contentsId===details.webContentsId){
              if(!authentication.finishing&&!authentication.checking&&authentication.allowed(details.url,details.method)
                &&!(authentication.submitted&&String(details.method).toUpperCase()==='POST')){
                if(String(details.method).toUpperCase()==='POST')authentication.submitted=true;
                callback({requestHeaders:headers});
              }else callback({cancel:true});
              return;
            }
            if(authentication&&owned.has(details.webContentsId)){callback({cancel:true});return;}
            if (!policy.sameOrigin(details.url, origin)) {
              // An absolute legacy URL must not send a prepared local form or
              // dashboard poll directly to production outside the shared cycle.
              if (localToken && owned.has(details.webContentsId) && serverOrigin && policy.sameOrigin(details.url, serverOrigin)) {
                callback({ cancel: true }); return;
              }
              callback({ requestHeaders: headers }); return;
            }
            const spaLogoutRequest=ownedSpaLogout(details,contents);
            if(owned.has(details.webContentsId)&&(details.resourceType==='mainFrame'||spaLogoutRequest)
              &&String(details.method).toUpperCase()==='GET'&&getPolicy.normalized(new URL(details.url).pathname)==='/admin/adminLogout'){
              if(logoutDecision||quitting()||refreshing||authentication){callback({cancel:true});return;}
              const scope=origin;loggingOut=true;
              // Commit the selected account's signed-out intent before the
              // original authenticated logout can reach PHP or shutdown.
              logoutDecision=accepted(async()=>{
                await beforeLogout();
                return {cancelled:quitting()||refreshing||authentication||origin!==scope};
              });
              Promise.resolve(logoutDecision).then(result=>{
                if(result.cancelled||quitting()||origin!==scope){callback({cancel:true});return;}
                // Preserve the original session until Laravel invalidates it
                // and revokes its remember token, including remembered users.
                spaLogout={id:details.id,contentsId:details.webContentsId,scope,
                  timer:setTimeout(()=>failSpaLogout('لم يصل تأكيد تسجيل الخروج. أغلق البرنامج ثم أعد فتحه قبل تسجيل دخول جديد.'),15000)};
                logoutNavigation=!spaLogoutRequest;
                authorizeRequest();
              },error=>{
                callback({cancel:true});
                if(error.code==='NATIVE_OPERATION_TIMEOUT'){
                  // Cookie removal has no native cancellation primitive. Do not
                  // permit a newer sign-in to race an outstanding old removal.
                  signinCookieCleanupBlocked=true;
                }else{logoutDecision=null;loggingOut=false;}
                if(!quitting())dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message});
              });
              return;
            }
            function authorizeRequest(){
            if (details.id != null && owned.has(details.webContentsId)) activeRequests.add(details.id);
            if (!localToken) {
              if (refreshing || quitting()) { callback({ cancel: true }); return; }
              const nativePrint=nativeConnectedPrints.get(nativeMarker);
              if((details.webContentsId==null||details.webContentsId<=0)&&String(details.method).toUpperCase()===nativePrint?.method&&nativePrint?.url===details.url){
                nativeConnectedPrints.delete(nativeMarker);callback({requestHeaders:{...headers,...nativePrint.proof}});return;
              }
              // A failed native binding must never become an unguarded original
              // server action after its proof was stripped.
              if((details.webContentsId==null||details.webContentsId<=0)&&connectedSupplied){callback({cancel:true});return;}
              if (!remoteWrite(details)) {
                if(browse&&owned.has(details.webContentsId)&&getPolicy.admin(new URL(details.url).pathname)&&['GET','HEAD'].includes(String(details.method||'GET').toUpperCase())){
                  accepted(()=>browse()).then(()=>callback({requestHeaders:headers}),error=>{callback({cancel:true});dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message});});return;
                }
                callback({ requestHeaders: headers }); return;
              }
              const attempt = crypto.randomUUID(); remoteWrites.set(details.id, attempt);
              // Persist uncertainty before a request can leave the machine.
              accepted(() => remoteAttempts ? remoteAttempts.begin(attempt, details) : remoteState?.begin(attempt)).then(proof => callback({ requestHeaders: { ...headers, ...proof } }), error => {
                if(remoteWrites.get(details.id)===attempt)remoteWrites.delete(details.id); callback({ cancel: true });
                dialog.showMessageBox(window, { type: 'error', title: 'GO ERP', message: error.message });
              });
              return;
            }
            const native = (details.webContentsId == null || details.webContentsId <= 0) && supplied === localToken;
            if (quitting() || refreshing || (!owned.has(details.webContentsId) && !native)) { callback({ cancel: true }); return; }
            callback({ requestHeaders: { ...headers, 'X-Fasakhansta-Desktop': localToken } });
            }
            if(owned.has(details.webContentsId)&&details.resourceType==='mainFrame'&&String(details.method).toUpperCase()==='POST'
              &&getPolicy.normalized(new URL(details.url).pathname)==='/admin/signin'){
              if(signinCookieCleanupBlocked||quitting()||refreshing||authentication){callback({cancel:true});return;}
              const scope=origin;
              // A fresh original sign-in must not inherit another remembered
              // account. The request body/password is never read or reconstructed.
              accepted(()=>clearPreviousRecaller(contents.session,scope)).then(result=>{
                if(result.cancelled||quitting()||origin!==scope){callback({cancel:true});return;}
                try{withoutPreviousRecaller(headers);authorizeRequest();}
                catch(error){callback({cancel:true});if(!quitting())dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message});}
              },error=>{
                if(error.code==='NATIVE_OPERATION_TIMEOUT')signinCookieCleanupBlocked=true;
                callback({cancel:true});if(!quitting())dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message});
              });return;
            }
            authorizeRequest();
        });
        contents.session.webRequest.onCompleted({ urls: ['<all_urls>'] }, details => {
          activeRequests.delete(details.id);
          if(spaLogout&&spaLogout.id===details.id&&spaLogout.contentsId===details.webContentsId){
            const expected=spaLogout;
            if(origin===expected.scope&&policy.sameOrigin(details.url,expected.scope)&&String(details.method).toUpperCase()==='GET'
              &&['/admin/adminLogout','/admin/login'].includes(getPolicy.normalized(new URL(details.url).pathname))
              &&details.statusCode>=200&&details.statusCode<400){
              if(!quitting())finishLogout(contents.session,expected.scope);
            }else failSpaLogout('تعذر تأكيد تسجيل الخروج من السيرفر. أغلق البرنامج ثم أعد فتحه قبل تسجيل دخول جديد.');
          }
          if (!remoteWrites.has(details.id)) return;
          const attempt=remoteWrites.get(details.id);
          const forget=()=>{if(remoteWrites.get(details.id)===attempt)remoteWrites.delete(details.id);};
          accepted(() => remoteAttempts ? remoteAttempts.complete(attempt) : remoteState?.complete(attempt)).then(forget).catch(() => {})
            .finally(() => { if (remoteAttempts) forget(); });
        });
        contents.on('will-navigate', navigate);
        contents.on('will-navigate',(_event,url)=>{if(policy.sameOrigin(url,origin))firstRead?.handoff();});
        contents.on('will-redirect', navigate);
        contents.setWindowOpenHandler(({ url }) => {
          if (refreshing) return { action: 'deny' };
          if (policy.sameOrigin(url, origin)) {
            return { action: 'allow', overrideBrowserWindowOptions: { width: 1100, height: 850, title: 'GO ERP — الداشبورد',
              icon: path.join(__dirname, 'assets', 'app.ico'),
              webPreferences: { preload: path.join(__dirname, 'dashboard-preload.cjs'), partition, sandbox: true, contextIsolation: true,
                nodeIntegration: false, webSecurity: true } } };
          }
          if (policy.externalURL(url)) shell.openExternal(url);
          return { action: 'deny' };
        });
        contents.on('did-create-window', child => {
          const childId = child.webContents.id;
          owned.add(childId);
          children.add(child);
          child.on('closed', () => { owned.delete(childId); children.delete(child); });
          child.webContents.on('page-title-updated',event=>event.preventDefault());
          child.webContents.on('will-navigate', navigate);
          child.webContents.on('will-redirect', navigate);
          child.webContents.setWindowOpenHandler(({ url }) => {
            if (policy.sameOrigin(url, origin)) { open(url).catch(() => {}); }
            else if (policy.externalURL(url)) shell.openExternal(url);
            return { action: 'deny' };
          });
        });
        window.on('close', event => { if (!quitting()) { event.preventDefault(); quit(); } });
        // The normal original logout and its cookie cleanup complete before
        // the native view closes, independently of a prior Remember me choice.
        contents.on('did-navigate', (_event,url,status) => {
          if(loggingOut&&logoutNavigation&&!quitting()&&policy.sameOrigin(url,origin)
            &&['/admin/adminLogout','/admin/login'].includes(getPolicy.normalized(new URL(url).pathname))
            &&status>=200&&status<400)finishLogout(contents.session,origin);
        });
        contents.on('did-fail-load', (_event, _code, description, _url, mainFrame) => {
          if (!refreshing && mainFrame && policy.networkFailure(description)) {
            if (localToken) dialog.showMessageBox(window, { type:'error', title:'GO ERP', message:'تعذر الاتصال بالداشبورد المحلية. بيانات الجهاز محفوظة؛ أعد فتح البرنامج.' });
            else offline('انقطع الاتصال بالداشبورد. الطلبات المحلية محفوظة على الجهاز.', failure());
          }
        });
        // Fetch failures during SPA navigation do not trigger did-fail-load.
        contents.session.webRequest.onErrorOccurred({ urls: ['<all_urls>'] }, details => {
          activeRequests.delete(details.id);
          if(spaLogout&&spaLogout.id===details.id&&spaLogout.contentsId===details.webContentsId){
            failSpaLogout('تعذر تأكيد تسجيل الخروج بسبب الاتصال. أغلق البرنامج ثم أعد فتحه قبل تسجيل دخول جديد.');return;
          }
          if(remoteAttempts&&remoteWrites.has(details.id)){remoteAttempts.failed(remoteWrites.get(details.id));remoteWrites.delete(details.id);}
          if (!refreshing && !authentication && !localToken && (!details.url || policy.sameOrigin(details.url, origin)) && policy.networkFailure(details.error))
            offline('انقطع الاتصال بالداشبورد. الطلبات المحلية محفوظة على الجهاز.', failure(details));
        });
        const grants = new Set(['notifications']);
        contents.session.setPermissionCheckHandler((sender, permission, requestingOrigin) =>
          (!authentication||authentication.contentsId!==sender?.id) && policy.sameOrigin(requestingOrigin, origin) && grants.has(permission));
        contents.session.setPermissionRequestHandler(async (sender, permission, callback, details) => {
          if(authentication?.contentsId===sender.id){callback(false);return;}
          if (!policy.sameOrigin(sender.getURL(), origin) || !policy.sameOrigin(details.requestingUrl || sender.getURL(), origin)) { callback(false); return; }
          if (grants.has(permission)) { callback(true); return; }
          if (!['geolocation', 'media'].includes(permission)) { callback(false); return; }
          const result = await dialog.showMessageBox(window, { type: 'question', title: 'GO ERP',
            message: permission === 'geolocation' ? 'السماح للداشبورد باستخدام موقع الجهاز؟' : 'السماح للداشبورد باستخدام الكاميرا أو الميكروفون؟',
            buttons: ['السماح', 'إلغاء'], defaultId: 1, cancelId: 1 });
          if (result.response === 0) grants.add(permission);
          callback(result.response === 0);
        });
      }
      // Existing documents stay usable during navigation. A new window stays
      // hidden until the original page is interactive, without a loading page.
      reveal();
      navigationStartedAt=Date.now();navigationTrace('started',url);
      let readPhase='loaded';
      if((!firstReadStarted&&new URL(url).pathname==='/admin/dashboard')||new URL(url).pathname==='/admin/login'){
        firstReadStarted=true;readPhase=await loadFirstRead(window,url);
      }else await window.loadURL(url);
      if (quitting()) return;
      if(['loaded','interactive'].includes(readPhase)&&window&&!window.isDestroyed()
        &&policy.sameOrigin(window.webContents.getURL(),origin))presentationReady=true;
      reveal();
    } catch (error) {
      navigationTrace('failed',url);
      if (quitting()) return;
      if (localToken) {
        revealFailure(); await dialog.showMessageBox(window, { type:'error', title:'GO ERP', message:'تعذر فتح الداشبورد المحلية. بيانات الجهاز محفوظة؛ أعد فتح البرنامج.' });
      }
      else if (policy.networkFailure(error.message)) {
        revealFailure();offline('الداشبورد غير متصلة حاليًا. يمكنك العمل على الطلبات المحلية ثم العودة للداشبورد من قائمة البرنامج.', failure());
      }
      else {
        // Server errors stay visible with their real status; they are not cached or called offline saves.
        revealFailure();
        await dialog.showMessageBox(window, { type: 'error', title: 'GO ERP', message: 'تعذر فتح الداشبورد. أعد المحاولة من قائمة البرنامج.' });
      }
    } finally { loading = false;resumeFreshLogin(); }
  }
  function navigate(event, url) {
    if (refreshing || authentication) { event.preventDefault(); return; }
    if (policy.sameOrigin(url, origin)) return;
    event.preventDefault();
    if (policy.externalURL(url)) shell.openExternal(url);
  }
  function focused() { return BrowserWindow.getFocusedWindow() || window || offlineWindow(); }
  Menu.setApplicationMenu(Menu.buildFromTemplate([
    { label: 'البرنامج', submenu: [
      { label: 'الداشبورد كاملة', accelerator: 'CmdOrCtrl+D', click: () => open().catch(() => {}) },
      ...(switchAccount?[{label:'تبديل الحساب',click:()=>requestAccountSwitch().catch(error=>dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message}))}]:[]),
      ...(liveSocial?[
        {label:'الرسائل المتصلة مباشرة',click:()=>accepted(()=>liveSocial('messages')).catch(error=>dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message}))},
        {label:'تعليقات السوشيال المتصلة مباشرة',click:()=>accepted(()=>liveSocial('comments')).catch(error=>dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message}))}
      ]:[]),
      ...(connected?[{label:'الداشبورد المتصلة كاملة',click:()=>accepted(()=>connected()).catch(error=>dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message}))}]:[]),
      ...(reauthenticate?[{label:'تجديد تسجيل دخول السيرفر',click:()=>accepted(()=>reauthenticate()).catch(error=>dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message}))}]:[]),
      ...(localMode?[{label:'العودة لنسخة الجهاز بعد التحديث',click:()=>accepted(()=>localMode()).catch(error=>dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message}))}]:[]),
      ...(reviewCentral?[{label:'مراجعة نتائج العمليات المتصلة',click:()=>accepted(()=>reviewCentral(window)).catch(error=>dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message}))}]:[]),
      ...(prepare ? [{ label: 'تجهيز للعمل بدون إنترنت', click: () => {
        if (!window || localToken || quitting()) return;
        window.webContents.executeJavaScript('document.querySelector(\'meta[name="csrf-token"]\')?.content || ""')
          .then(csrf => accepted(() => prepare(origin, csrf)))
          .catch(error => dialog.showMessageBox(window, { type: 'error', title: 'GO ERP', message: error.message }));
      } }] : []),
      { label: archive ? 'سجل النسخة السابقة' : 'الطلبات المحلية والطابعة', accelerator: 'CmdOrCtrl+L', click: () => archive ? archive() : offline('') },
      ...(reviewSync?[{label:'مراجعة المزامنة',click:()=>accepted(()=>reviewSync(window)).catch(error=>dialog.showMessageBox(window,{type:'error',title:'GO ERP',message:error.message}))}]:[]),
      ...(updateStatus?[{label:'حالة تحديث البرنامج',click:()=>{
        const value=updateStatus(),messages={current:'البرنامج المثبت يطابق الإصدار المنشور على السيرفر.',checking:'جارٍ فحص تحديثات السيرفر.',downloading:'جارٍ تحميل تحديث البرنامج.',staged:'التحديث جاهز وينتظر انتهاء العمل وتسوية العمليات.',installing:'جارٍ تثبيت التحديث.',unavailable:'لم يُنشر تحديث متوافق بعد. سيُعاد الفحص تلقائيًا.',error:'تعذر إكمال فحص التحديث. سيُعاد الفحص تلقائيًا.'};
        dialog.showMessageBox(window,{type:'info',title:'GO ERP — تحديث البرنامج',message:messages[value.status]||'فحص التحديثات التلقائي يعمل كل دقيقة.',detail:value.version?'الإصدار: '+value.version:'فحص تلقائي كل ٦٠ ثانية.'});
      }}]:[]),
      { label: 'اختيار الطابعة', click: () => {
        if (selectPrinter) { accepted(() => selectPrinter(window)).catch(error => dialog.showMessageBox(window, { type: 'error', title: 'GO ERP', message: error.message })); return; }
        // The previous POS renderer is loaded only when requested. Wait for it
        // before opening settings, and never run page work after quit begins.
        return Promise.resolve().then(()=>offline('')).then(()=>{
          if(!quitting())return offlineWindow().webContents.executeJavaScript('document.getElementById("settings").click()');
        }).catch(() => {});
      } },
      { type: 'separator' }, { label: 'إغلاق البرنامج', role: 'quit' }
    ] },
    { label: 'تحرير', submenu: [{ label: 'تراجع', role: 'undo' }, { label: 'إعادة', role: 'redo' }, { type: 'separator' },
      { label: 'قص', role: 'cut' }, { label: 'نسخ', role: 'copy' }, { label: 'لصق', role: 'paste' }, { label: 'تحديد الكل', role: 'selectAll' }] },
    { label: 'عرض', submenu: [{ label: 'تحديث الصفحة', accelerator: 'CmdOrCtrl+R', click: () => { if (!refreshing && !quitting()) focused()?.reload(); } },
      { label: 'تكبير', role: 'zoomIn' }, { label: 'تصغير', role: 'zoomOut' }, { label: 'الحجم الطبيعي', role: 'resetZoom' },
      { label: 'ملء الشاشة', role: 'togglefullscreen', accelerator: 'F12' }] },
    { label: 'طباعة', submenu: [{ label: 'طباعة الصفحة الحالية', accelerator: 'CmdOrCtrl+P', click: () => {
      if (refreshing || quitting()) return;
      if(localToken){dialog.showMessageBox(window,{type:'info',title:'GO ERP',message:'استخدم زر طباعة الفاتورة داخل الداشبورد لحفظ نتيجة الطباعة ومنع تكرارها.'});return;}
      const contents = focused()?.webContents;
      if (!contents) return;
      printing++;
      let finished = false;
      const finish = () => { if (!finished) { finished = true; printing--; } };
      try { contents.print({ printBackground: true }, finish); } catch (error) { finish(); throw error; }
    } }] }
  ]));
  async function canRefresh() {return canIdle(false);}
  async function canSwitchAccount() {return canIdle(true);}
  async function canIdle(allowLogin) {
    if (loading || refreshing || authentication || loggingOut || signinCookieCleanupBlocked || quitting() || printing || children.size || remoteWrites.size || activeRequests.size) return false;
    if (!window || window.isDestroyed()) return true;
    const current = window.webContents.getURL();
    if (!policy.sameOrigin(current, origin)) return false;
    const login=allowLogin&&new URL(current).pathname==='/admin/login';
    if (!idlePage(current)&&!login) return false;
    // Business pages provide pure, synchronous checks for drafts and pending
    // actions. Renderer failures always defer the short final handoff.
    try {
      return await window.webContents.executeJavaScript(`(() => {
        const selectChanged = (${selectChanged.toString()});
        const settledBoardFilters = (${settledBoardFilters.toString()});
        if (document.readyState !== 'complete') return false;
        const form = ${login} ? document.querySelector('form[data-dashboard-login]') : null;
        const originalLogin = ${login} && form && String(form.method).toUpperCase() === 'POST'
          && new URL(form.action, window.location.href).href === new URL('/admin/signin', window.location.href).href;
        if (${login} && !originalLogin) return false;
        const loginField = field => originalLogin && field?.form === form && field.tagName === 'INPUT'
          && ['email','password','remember','_token'].includes(field.name);
        if (${businessPage(current)} && window.DashboardSPA?.isIdle?.() !== true) return false;
        const appliedFilters = ${new URL(current).pathname === '/admin/applies-orders'} ? settledBoardFilters() : new Set();
        const editable = 'input,textarea,select,[contenteditable]:not([contenteditable="false"])';
        if (document.activeElement?.matches(editable) && !loginField(document.activeElement)) return false;
        if (Array.from(document.querySelectorAll('.modal.show,.modal.in,.swal2-container,[role="dialog"][aria-modal="true"],dialog[open]'))
          .some(element => !element.closest('[hidden]') && element.getClientRects().length > 0)) return false;
        return !Array.from(document.querySelectorAll('input:not([type="hidden"]),textarea,select')).some(field => {
          if (loginField(field) || appliedFilters.has(field)) return false;
          if (field.disabled || field.readOnly) return false;
          if (field.tagName === 'SELECT') return selectChanged(field);
          if (field.type === 'checkbox' || field.type === 'radio') return field.checked !== field.defaultChecked;
          return field.value !== field.defaultValue;
        });
      })()`) === true;
    } catch { return false; }
  }
  async function refresh(work) {
    if (!localToken || refreshing || quitting()) throw Error('تعذر تحديث الداشبورد الحالية.');
    const returnURL = window?.webContents.getURL();let entered=false;
    try{
      return await withUpdateIdle(async()=>{
        entered=true;
        return await work();
      },{retireCurrent:true});
    }finally{
      if(entered&&!refreshing&&window&&!window.isDestroyed()&&!quitting())await open(policy.sameOrigin(returnURL,origin)?returnURL:home);
    }
  }
  async function withUpdateIdle(work,{retireCurrent=false}={}) {return withIdle(work,{retireCurrent});}
  async function withAccountIdle(work) {return withIdle(work,{allowLogin:true});}
  async function withIdle(work,{retireCurrent=false,allowLogin=false}={}) {
    if (typeof work !== 'function') throw Error('عملية تحديث البرنامج غير صالحة.');
    if (!(await canIdle(allowLogin))) return { deferred: true };
    const heldWindow = window, contents = heldWindow?.webContents;
    const currentURL = contents?.getURL();
    const login=allowLogin&&currentURL&&new URL(currentURL).pathname==='/admin/login';
    const idle = () => !loading && !authentication && !loggingOut && !signinCookieCleanupBlocked && !quitting() && !printing && !effects?.active
      && !children.size && !remoteWrites.size && !activeRequests.size
      && heldWindow && window === heldWindow && !heldWindow.isDestroyed()
      && contents.getURL() === currentURL && policy.sameOrigin(currentURL, origin)
      && (idlePage(currentURL)||login);
    // Recheck native work after the first asynchronous renderer probe. No child
    // window is closed and no page is stopped, replaced, or reloaded by this gate.
    if (refreshing || !idle()) return { deferred: true };
    refreshing = true;
    let retirementFailed=false;
    const id = crypto.randomUUID();
    const blockInput = event => event.preventDefault();
    contents.on('before-input-event', blockInput);
    try {
      const held = await contents.executeJavaScript(`(() => {
        const selectChanged = (${selectChanged.toString()});
        const settledBoardFilters = (${settledBoardFilters.toString()});
        const id = ${JSON.stringify(id)}, expectedURL = ${JSON.stringify(currentURL)};
        const key = '__goErpUpdateIdleGuard';
        if (window[key]) return false;
        const events = ['pointerdown','pointerup','mousedown','mouseup','click','dblclick','contextmenu',
          'wheel','touchstart','touchmove','touchend','keydown','keypress','keyup','beforeinput','input',
          'change','paste','cut','drop','dragstart','submit','reset'];
        const block = event => { event.preventDefault(); event.stopImmediatePropagation(); };
        const release = () => {
          for (const name of events) window.removeEventListener(name, block, true);
          if (window[key]?.id === id) delete window[key];
        };
        // Capturing blockers and the idle test execute in one renderer turn, so
        // input cannot slip between the test and the start of the native work.
        for (const name of events) window.addEventListener(name, block, {capture:true,passive:false});
        window[key] = {id,release};
        try {
          const form = ${Boolean(login)} ? document.querySelector('form[data-dashboard-login]') : null;
          const originalLogin = ${Boolean(login)} && form && String(form.method).toUpperCase() === 'POST'
            && new URL(form.action, window.location.href).href === new URL('/admin/signin', window.location.href).href;
          const loginField = field => originalLogin && field?.form === form && field.tagName === 'INPUT'
            && ['email','password','remember','_token'].includes(field.name);
          const appliedFilters = ${new URL(currentURL).pathname === '/admin/applies-orders'} ? settledBoardFilters() : new Set();
          const editable = 'input,textarea,select,[contenteditable]:not([contenteditable="false"])';
          if (window.location.href !== expectedURL || document.readyState !== 'complete'
            || (${Boolean(login)} && !originalLogin)
            || (${businessPage(currentURL)} && window.DashboardSPA?.isIdle?.() !== true)
            || (document.activeElement?.matches(editable) && !loginField(document.activeElement))
            || Array.from(document.querySelectorAll('.modal.show,.modal.in,.swal2-container,[role="dialog"][aria-modal="true"],dialog[open]'))
              .some(element => !element.closest('[hidden]') && element.getClientRects().length > 0)
            || document.querySelector('[contenteditable]:not([contenteditable="false"])')
            || Array.from(document.querySelectorAll('input:not([type="hidden"]),textarea,select')).some(field => {
              if (loginField(field) || appliedFilters.has(field)) return false;
              if (field.disabled || field.readOnly) return false;
              if (field.tagName === 'SELECT') return selectChanged(field);
              if (field.type === 'checkbox' || field.type === 'radio') return field.checked !== field.defaultChecked;
              return field.value !== field.defaultValue;
            })) { release(); return false; }
          return true;
        } catch { release(); return false; }
      })()`);
      if (held !== true || !idle()) return { deferred: true };
      try{return await work();}
      finally{
        // Retire the old form before unlocking input and native requests. It
        // must never submit into the generation that has just been activated.
        if(retireCurrent&&!quitting()&&!heldWindow.isDestroyed()){
          try{await heldWindow.loadURL('about:blank');}
          catch{retirementFailed=true;}
        }
      }
    } finally {
      if(retirementFailed)throw Object.assign(Error('تعذر استرجاع الصفحة بعد تحديث البيانات؛ أعد فتح البرنامج. مصادر العمليات محفوظة.'),{code:'DASHBOARD_HANDOFF_RESTART_REQUIRED'});
      if (!quitting() && !heldWindow.isDestroyed()) {
        try {
          const released = await contents.executeJavaScript(`(() => {
            const guard = window.__goErpUpdateIdleGuard;
            if (!guard) return true;
            if (guard.id !== ${JSON.stringify(id)}) return false;
            guard.release(); return !window.__goErpUpdateIdleGuard;
          })()`);
          // A failed cleanup keeps native writes fenced. A half-unlocked page
          // must not accept a form after an uncertain renderer transition.
          if (released !== true) throw Error('تعذر استرجاع الصفحة بعد فحص التحديث؛ أعد فتح البرنامج.');
        } catch (error) {
          throw Error('تعذر استرجاع الصفحة بعد فحص التحديث؛ أعد فتح البرنامج.');
        }
        contents.removeListener('before-input-event', blockInput);
        refreshing = false;
      }
    }
  }
  async function switchTo(target, work = async () => {}, { preserveCurrent = false } = {}) {
    if (refreshing || authentication || quitting() || printing || effects?.active) throw Error('انتظر انتهاء الطباعة أو تسجيل الدخول أو انتقال الداشبورد بين الجهاز والسيرفر.');
    const destination = new URL(target.origin);
    const local = destination.protocol === 'http:' && destination.hostname === '127.0.0.1' && destination.port && target.localToken;
    const remote = destination.protocol === 'https:' && target.origin === serverOrigin && !target.localToken;
    if ((!local && !remote) || destination.username || destination.password || destination.origin !== target.origin)
      throw Error('وجهة الداشبورد غير مسموحة.');
    const currentURL = window?.webContents.getURL();
    const returnURL = preserveCurrent && policy.sameOrigin(currentURL, origin)
      ? target.origin + new URL(currentURL).pathname + new URL(currentURL).search : target.origin + '/admin/dashboard';
    // Do not let an old form or child tab post into a newly activated generation.
    refreshing = true;
    window?.webContents.stop();
    for (const child of children) { owned.delete(child.webContents.id); child.destroy(); }
    children.clear();
    try {
      const result = await work();
      origin = target.origin; localToken = target.localToken || ''; home = origin + '/admin/dashboard';
      return result;
    }
    finally {
      // Navigate to a read page, never reload a previously submitted POST.
      if (window && !window.isDestroyed() && !quitting()) await window.loadURL('about:blank').catch(() => {});
      refreshing = false;
      if (window && !window.isDestroyed() && !quitting()) await open(policy.sameOrigin(returnURL, origin) ? returnURL : home);
    }
  }
  async function renewServerLogin(destination,verify){
    if(destination!==serverOrigin||!destination.startsWith('https://')||typeof verify!=='function'||!(await canRefresh()))
      throw Error('انتقل للصفحة الرئيسية وأكمل أي تعديل أو طباعة قبل تجديد تسجيل الدخول.');
    if(authentication||quitting())throw Error('نافذة تسجيل الدخول غير متاحة الآن.');
    const authWindow=new BrowserWindow({parent:window,modal:true,show:false,width:980,height:760,title:'GO ERP — تسجيل دخول السيرفر',icon:path.join(__dirname,'assets','app.ico'),
      webPreferences:{partition,sandbox:true,contextIsolation:true,nodeIntegration:false,webSecurity:true}});
    authWindow.webContents.on('page-title-updated',event=>event.preventDefault());
    const staticPaths=new Set(['/dashboard/plugins/fontawesome-free/css/all.min.css','/dashboard/dist/css/adminlte.min.css',
      '/dashboard/branding/dashboard-brand.css','/dashboard/branding/fasakhansta-logo-transparent.png']);
    const allowed=(value,method)=>{
      if(!policy.sameOrigin(value,destination))return false;
      const pathname=new URL(value).pathname,verb=String(method||'GET').toUpperCase();
      return verb==='POST'?pathname==='/admin/signin':['GET','HEAD'].includes(verb)&&(pathname==='/admin/login'||staticPaths.has(pathname)
        ||/^\/dashboard\/plugins\/fontawesome-free\/webfonts\/fa-(?:brands-400|regular-400|solid-900)\.(?:eot|svg|ttf|woff|woff2)$/.test(pathname));
    };
    return new Promise((resolve,reject)=>{
      const cookieSession=authWindow.webContents.session;
      const clearCookies=async()=>{
        let active=true;
        try{return await withDeadline(async()=>{
          const cookies=await cookieSession.cookies.get({url:destination+'/admin/login'});
          for(const cookie of cookies){
            // A delayed cookie read must never remove a later login's session.
            if(!active)return;
            await cookieSession.cookies.remove(destination+(cookie.path||'/'),cookie.name);
          }
        },5000,'تعذر مسح جلسة السيرفر في الوقت المحدد.');}
        finally{active=false;}
      };
      const record={window:authWindow,contentsId:authWindow.webContents.id,allowed,submitted:false,checking:false,finishing:false};authentication=record;
      const finish=async error=>{
        if(authentication!==record||record.finishing)return;
        record.finishing=true;if(!authWindow.isDestroyed())authWindow.webContents.stop();
        if(error){
          try{await clearCookies();}
          catch(cleanupError){
            if(!quitting()){
              const timedOut=cleanupError.code==='NATIVE_OPERATION_TIMEOUT';
              const choice=await dialog.showMessageBox(authWindow,{type:'error',title:'GO ERP',
                message:timedOut?'تعذر إكمال مسح جلسة السيرفر. أغلق البرنامج ثم أعد فتحه.':'تعذر مسح جلسة السيرفر. الداشبورد متوقفة لحين إعادة المحاولة أو إغلاق البرنامج.',
                buttons:timedOut?['إغلاق البرنامج']:['إعادة المحاولة','إغلاق البرنامج'],defaultId:0,cancelId:timedOut?0:1});
              if(!timedOut&&choice.response===0){record.finishing=false;return finish(error);}
              reject(cleanupError);quit();return;
            }
          }
        }
        if(authentication!==record)return;
        authentication=null;if(!authWindow.isDestroyed())authWindow.destroy();
        error?reject(error):resolve({authenticated:true});
      };
      record.cancel=()=>{
        if(quitting()&&record.finishing){
          authentication=null;if(!authWindow.isDestroyed())authWindow.destroy();reject(Error('تم إلغاء تسجيل دخول السيرفر.'));return;
        }
        return finish(Error('تم إلغاء تسجيل دخول السيرفر.'));
      };
      const navigateAuth=(event,url,redirect=false)=>{
        if(policy.sameOrigin(url,destination)&&new URL(url).pathname==='/admin/login'){
          if(redirect&&!record.checking)record.submitted=false;
          return;
        }
        if(policy.sameOrigin(url,destination)&&new URL(url).pathname==='/admin/signin')return;
        event.preventDefault();
        if(record.submitted&&policy.sameOrigin(url,destination)&&!record.checking){
          record.checking=true;Promise.resolve().then(verify).then(()=>finish(),finish);
        }
      };
      authWindow.webContents.on('will-navigate',navigateAuth);authWindow.webContents.on('will-redirect',(event,url)=>navigateAuth(event,url,true));
      authWindow.webContents.setWindowOpenHandler(()=>({action:'deny'}));
      authWindow.on('close',event=>{event.preventDefault();record.cancel();});
      authWindow.on('closed',()=>record.cancel());
      authWindow.webContents.on('did-fail-load',(_event,code,message,_url,main)=>{
        if(main&&!(code===-3&&record.checking))finish(Error('تعذر تحميل تسجيل دخول السيرفر: '+message));
      });
      // Explicit native renewal clears only this app's cookies for the pinned
      // server login URL. Passwords remain in the original server form.
      (async()=>{
        await clearCookies();
        if(authentication!==record||record.finishing||quitting())return record.cancel();
        authWindow.show();await authWindow.loadURL(destination+'/admin/login');
      })().catch(error=>{if(!record.checking)finish(error);});
    });
  }
  function publish(value) {
    if (window && !window.isDestroyed() && !quitting() && policy.sameOrigin(window.webContents.getURL(), origin))
      window.webContents.send('dashboard:state', value);
  }
  async function recoverPrintEffects() {
    if(!localToken || !effects)return {local:false};
    printing++;try{await effects.inspect();return {local:true};}finally{printing--;}
  }
  return { open, refresh, canRefresh, canSwitchAccount, withUpdateIdle, withAccountIdle, switchTo, reauthenticate:renewServerLogin, cancelAuthentication:()=>authentication?.cancel(), publish, reveal, recoverPrintEffects, session: () => window?.webContents.session,
    current: () => ({ origin, local: Boolean(localToken) }), isVisible: () => Boolean(window && !window.isDestroyed() && window.isVisible()) };
};
