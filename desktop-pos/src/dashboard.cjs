'use strict';
const path = require('node:path');
const crypto = require('node:crypto');
const { BrowserWindow, Menu, shell, dialog, ipcMain } = require('electron');
const getPolicy=require('./dashboard-get-policy.cjs');
const policy = require('./dashboard-policy.cjs');
const { DashboardPrintEffects } = require('./dashboard-print-effects.cjs');

/** Runs the existing dashboard unchanged. Remote pages receive no Node or POS bridge. */
module.exports = function dashboard({ origin, serverOrigin = null, offline, offlineWindow, quitting, quit, printer, localToken = '',
  prepare, connected, localMode, reviewCentral, browse, remoteState, remoteAttempts, archive, selectPrinter, reviewSync, printControl, printMetadata, status = async () => ({ available: false }), synchronize = async () => {}, accepted = fn => fn() }) {
  let home = origin + '/admin/dashboard';
  if (!serverOrigin && !localToken) serverOrigin = origin;
  let window, loading = false, refreshing = false, printing = 0;
  const owned = new Set();
  const children = new Set();
  const remoteWrites = new Map();
  const nativeConnectedPrints = new Map();
  const activeRequests = new Set();
  const effects = printControl && printMetadata ? new DashboardPrintEffects({control:printControl,metadata:printMetadata,
    available:()=>Boolean(localToken) && !refreshing && !quitting(),prepareDocument:prepareEffectDocument}) : null;
  async function prepareEffectDocument(prepared) {
    const html=prepared.html;
    const protection='<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'none\'; img-src data: '+origin+'; style-src \'unsafe-inline\' '+origin+'; font-src '+origin+'; connect-src \'none\'; frame-src \'none\'; object-src \'none\'; base-uri \'none\'; form-action \'none\'">';
    const printWindow=new BrowserWindow({show:false,width:480,height:800,
      webPreferences:{partition:'persist:fasakhansta-dashboard',sandbox:true,contextIsolation:true,nodeIntegration:false}});
    const contents=printWindow.webContents;owned.add(contents.id);let closed=false;
    const close=()=>{if(closed)return;closed=true;owned.delete(contents.id);printWindow.destroy();};
    contents.on('will-navigate',event=>event.preventDefault());contents.setWindowOpenHandler(()=>({action:'deny'}));
    let timer;
    try {
      await Promise.race([(async()=>{
        await printWindow.loadURL('data:text/html;charset=utf-8,'+encodeURIComponent(html.replace(/<head\b[^>]*>/i,match=>match+protection)));
        await contents.executeJavaScript('Promise.all([document.fonts.ready,...Array.from(document.images).map(i=>i.complete?(i.naturalWidth?Promise.resolve():Promise.reject(Error("Receipt image unavailable"))):new Promise((r,j)=>{i.onload=r;i.onerror=()=>j(Error("Receipt image unavailable"));}))]).then(()=>true)');
      })(),new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('تعذر تحميل مستند الطباعة كاملًا.')),25000);})]);
      const deviceName=printer()||'',printers=await window.webContents.getPrintersAsync();
      if(!printers.length || deviceName && !printers.some(value=>value.name===deviceName))throw Error('الطابعة غير متاحة. اختر الطابعة من قائمة البرنامج.');
      return {close,invoke:callback=>{
        if(closed)throw Error('Print document closed.');
        contents.print({silent:true,printBackground:true,deviceName,margins:{marginType:'none'}},(ok)=>callback(ok));
      }};
    }catch(error){close();throw error;}finally{clearTimeout(timer);}
  }
  function ownedPrint(event) {
    return !quitting() && !refreshing && owned.has(event.sender.id) && policy.sameOrigin(event.senderFrame.url,origin)
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
    return !quitting() && !refreshing && window && event.sender.id === window.webContents.id
      && policy.sameOrigin(event.senderFrame.url, origin) && new URL(event.senderFrame.url).pathname.startsWith('/admin/');
  }
  for (const [name, work] of Object.entries({
    status: () => status(),
    prepare: value => {
      if (!prepare || localToken || !origin.startsWith('https://')) throw Error('تجهيز الجهاز يحتاج حساب السيرفر المتصل.');
      return prepare(origin, typeof value?.csrf === 'string' ? value.csrf : '');
    },
    synchronize: () => synchronize(),
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
      if(proof['X-Fasakhansta-Connected-Device']&&proof['X-Fasakhansta-Connected-Token']){
        // Only this native fetch can retain its paired actor proof through the
        // shared interceptor. The one-use marker never leaves this process.
        nativeMarker=crypto.randomBytes(32).toString('hex');
        nativeConnectedPrints.set(nativeMarker,{url:url.href,proof});
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
        webPreferences: { partition: 'persist:fasakhansta-dashboard', sandbox: true, contextIsolation: true, nodeIntegration: false } });
      owned.add(printWindow.webContents.id);
      try {
        printWindow.webContents.on('will-navigate', event => event.preventDefault());
        printWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
        await printWindow.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html.replace(/<head\b[^>]*>/i, match => match + protection)));
        await printWindow.webContents.executeJavaScript('Promise.all([document.fonts.ready,...Array.from(document.images).map(i=>i.complete?Promise.resolve():new Promise((r,j)=>{i.onload=r;i.onerror=()=>j(Error("تعذر تحميل شعار الفاتورة"));}))]).then(()=>true)');
        const deviceName = printer() || '';
        const printers = await window.webContents.getPrintersAsync();
        if (!printers.length || (deviceName && !printers.some(item => item.name === deviceName))) throw Error('الطابعة غير متاحة. اختر الطابعة من قائمة البرنامج.');
        await new Promise((resolve, reject) => printWindow.webContents.print({ silent: true, printBackground: true, deviceName,
          margins: { marginType: 'none' } }, (ok, error) => ok ? resolve() : reject(Error('فشلت الطباعة: ' + error))));
        return { ok: true, value: true };
      } finally { owned.delete(printWindow.webContents.id); printWindow.destroy(); }
    } catch (error) { return { ok: false, error: error.message }; }
    finally { printing--; }
  }); } catch (error) { return { ok: false, error: error.message }; } });
  function reveal() {
    if (window && !window.isDestroyed()) { window.show(); if (window.isMinimized()) window.restore(); window.focus(); }
  }
  async function open(url = home) {
    if (loading || refreshing || quitting()) return;
    if (!policy.sameOrigin(url, origin)) throw Error('رابط الداشبورد غير صحيح.');
    loading = true;
    try {
      if (!window || window.isDestroyed()) {
        window = new BrowserWindow({ width: 1440, height: 950, minWidth: 1000, minHeight: 650, show: false,
          title: 'فسخانستا — الداشبورد', icon: path.join(__dirname, 'assets', 'app.ico'), backgroundColor: '#f4f5f8',
          webPreferences: { preload: path.join(__dirname, 'dashboard-preload.cjs'), partition: 'persist:fasakhansta-dashboard', sandbox: true, contextIsolation: true,
            nodeIntegration: false, webSecurity: true } });
        window.maximize();
        const contents = window.webContents;
        owned.add(contents.id);
        contents.session.webRequest.onBeforeSendHeaders({ urls: ['<all_urls>'] }, (details, callback) => {
            const headers = { ...details.requestHeaders };
            const supplied = Object.entries(headers).find(([name]) => name.toLowerCase() === 'x-fasakhansta-desktop')?.[1];
            const nativeMarker=Object.entries(headers).find(([name])=>name.toLowerCase()==='x-fasakhansta-connected-request')?.[1];
            const connectedSupplied=Object.keys(headers).some(name=>/^x-fasakhansta-connected-(?:device|token|request)$/i.test(name));
            for (const name of Object.keys(headers)) if (/^x-fasakhansta-(?:desktop|control|remote-attempt|remote-capability|connected-device|connected-token|connected-request)$/i.test(name)) delete headers[name];
            if (!policy.sameOrigin(details.url, origin)) {
              // An absolute legacy URL must not send a prepared local form or
              // dashboard poll directly to production outside the shared cycle.
              if (localToken && owned.has(details.webContentsId) && serverOrigin && policy.sameOrigin(details.url, serverOrigin)) {
                callback({ cancel: true }); return;
              }
              callback({ requestHeaders: headers }); return;
            }
            if (details.id != null && owned.has(details.webContentsId)) activeRequests.add(details.id);
            if (!localToken) {
              if (refreshing || quitting()) { callback({ cancel: true }); return; }
              const nativePrint=nativeConnectedPrints.get(nativeMarker);
              if((details.webContentsId==null||details.webContentsId<=0)&&String(details.method).toUpperCase()==='GET'&&nativePrint?.url===details.url){
                nativeConnectedPrints.delete(nativeMarker);callback({requestHeaders:{...headers,...nativePrint.proof}});return;
              }
              // A failed native binding must never become an unguarded original
              // server action after its proof was stripped.
              if((details.webContentsId==null||details.webContentsId<=0)&&connectedSupplied){callback({cancel:true});return;}
              if (!remoteWrite(details)) {
                if(browse&&owned.has(details.webContentsId)&&getPolicy.admin(new URL(details.url).pathname)&&['GET','HEAD'].includes(String(details.method||'GET').toUpperCase())){
                  accepted(()=>browse()).then(()=>callback({requestHeaders:headers}),error=>{callback({cancel:true});dialog.showMessageBox(window,{type:'error',title:'فسخانستا',message:error.message});});return;
                }
                callback({ requestHeaders: headers }); return;
              }
              const attempt = crypto.randomUUID(); remoteWrites.set(details.id, attempt);
              // Persist uncertainty before a request can leave the machine.
              accepted(() => remoteAttempts ? remoteAttempts.begin(attempt, details) : remoteState?.begin(attempt)).then(proof => callback({ requestHeaders: { ...headers, ...proof } }), error => {
                if(remoteWrites.get(details.id)===attempt)remoteWrites.delete(details.id); callback({ cancel: true });
                dialog.showMessageBox(window, { type: 'error', title: 'فسخانستا', message: error.message });
              });
              return;
            }
            const native = (details.webContentsId == null || details.webContentsId <= 0) && supplied === localToken;
            if (quitting() || refreshing || (!owned.has(details.webContentsId) && !native)) { callback({ cancel: true }); return; }
            callback({ requestHeaders: { ...headers, 'X-Fasakhansta-Desktop': localToken } });
        });
        contents.session.webRequest.onCompleted({ urls: ['<all_urls>'] }, details => {
          activeRequests.delete(details.id);
          if (!remoteWrites.has(details.id)) return;
          const attempt=remoteWrites.get(details.id);
          const forget=()=>{if(remoteWrites.get(details.id)===attempt)remoteWrites.delete(details.id);};
          accepted(() => remoteAttempts ? remoteAttempts.complete(attempt) : remoteState?.complete(attempt)).then(forget).catch(() => {})
            .finally(() => { if (remoteAttempts) forget(); });
        });
        contents.on('will-navigate', navigate);
        contents.on('will-redirect', navigate);
        contents.setWindowOpenHandler(({ url }) => {
          if (refreshing) return { action: 'deny' };
          if (policy.sameOrigin(url, origin)) {
            return { action: 'allow', overrideBrowserWindowOptions: { width: 1100, height: 850,
              icon: path.join(__dirname, 'assets', 'app.ico'),
              webPreferences: { preload: path.join(__dirname, 'dashboard-preload.cjs'), partition: 'persist:fasakhansta-dashboard', sandbox: true, contextIsolation: true,
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
          child.webContents.on('will-navigate', navigate);
          child.webContents.on('will-redirect', navigate);
          child.webContents.setWindowOpenHandler(({ url }) => {
            if (policy.sameOrigin(url, origin)) { open(url).catch(() => {}); }
            else if (policy.externalURL(url)) shell.openExternal(url);
            return { action: 'deny' };
          });
        });
        window.on('close', event => { if (!quitting()) { event.preventDefault(); quit(); } });
        contents.on('did-fail-load', (_event, _code, description, _url, mainFrame) => {
          if (!refreshing && mainFrame && policy.networkFailure(description)) {
            if (localToken) dialog.showMessageBox(window, { type:'error', title:'فسخانستا', message:'تعذر الاتصال بالداشبورد المحلية. بيانات الجهاز محفوظة؛ أعد فتح البرنامج.' });
            else offline('انقطع الاتصال بالداشبورد. الطلبات المحلية محفوظة على الجهاز.', failure());
          }
        });
        // Fetch failures during SPA navigation do not trigger did-fail-load.
        contents.session.webRequest.onErrorOccurred({ urls: ['<all_urls>'] }, details => {
          activeRequests.delete(details.id);
          if(remoteAttempts&&remoteWrites.has(details.id)){remoteAttempts.failed(remoteWrites.get(details.id));remoteWrites.delete(details.id);}
          if (!refreshing && !localToken && (!details.url || policy.sameOrigin(details.url, origin)) && policy.networkFailure(details.error))
            offline('انقطع الاتصال بالداشبورد. الطلبات المحلية محفوظة على الجهاز.', failure(details));
        });
        const grants = new Set(['notifications']);
        contents.session.setPermissionCheckHandler((_contents, permission, requestingOrigin) =>
          policy.sameOrigin(requestingOrigin, origin) && grants.has(permission));
        contents.session.setPermissionRequestHandler(async (sender, permission, callback, details) => {
          if (!policy.sameOrigin(sender.getURL(), origin) || !policy.sameOrigin(details.requestingUrl || sender.getURL(), origin)) { callback(false); return; }
          if (grants.has(permission)) { callback(true); return; }
          if (!['geolocation', 'media'].includes(permission)) { callback(false); return; }
          const result = await dialog.showMessageBox(window, { type: 'question', title: 'فسخانستا',
            message: permission === 'geolocation' ? 'السماح للداشبورد باستخدام موقع الجهاز؟' : 'السماح للداشبورد باستخدام الكاميرا أو الميكروفون؟',
            buttons: ['السماح', 'إلغاء'], defaultId: 1, cancelId: 1 });
          if (result.response === 0) grants.add(permission);
          callback(result.response === 0);
        });
      }
      // Keep the app and its local-order menu usable while a slow/offline server is being contacted.
      reveal();
      await window.loadURL(url);
      if (quitting()) return;
      reveal();
    } catch (error) {
      if (quitting()) return;
      if (localToken) {
        reveal(); await dialog.showMessageBox(window, { type:'error', title:'فسخانستا', message:'تعذر فتح الداشبورد المحلية. بيانات الجهاز محفوظة؛ أعد فتح البرنامج.' });
      }
      else if (policy.networkFailure(error.message)) offline('الداشبورد غير متصلة حاليًا. يمكنك العمل على الطلبات المحلية ثم العودة للداشبورد من قائمة البرنامج.', failure());
      else {
        // Server errors stay visible with their real status; they are not cached or called offline saves.
        reveal();
        await dialog.showMessageBox(window, { type: 'error', title: 'فسخانستا', message: 'تعذر فتح الداشبورد. أعد المحاولة من قائمة البرنامج.' });
      }
    } finally { loading = false; }
  }
  function navigate(event, url) {
    if (refreshing) { event.preventDefault(); return; }
    if (policy.sameOrigin(url, origin)) return;
    event.preventDefault();
    if (policy.externalURL(url)) shell.openExternal(url);
  }
  function focused() { return BrowserWindow.getFocusedWindow() || window || offlineWindow(); }
  Menu.setApplicationMenu(Menu.buildFromTemplate([
    { label: 'البرنامج', submenu: [
      { label: 'الداشبورد كاملة', accelerator: 'CmdOrCtrl+D', click: () => open().catch(() => {}) },
      ...(connected?[{label:'الداشبورد المتصلة كاملة',click:()=>accepted(()=>connected()).catch(error=>dialog.showMessageBox(window,{type:'error',title:'فسخانستا',message:error.message}))}]:[]),
      ...(localMode?[{label:'العودة لنسخة الجهاز بعد التحديث',click:()=>accepted(()=>localMode()).catch(error=>dialog.showMessageBox(window,{type:'error',title:'فسخانستا',message:error.message}))}]:[]),
      ...(reviewCentral?[{label:'مراجعة نتائج العمليات المتصلة',click:()=>accepted(()=>reviewCentral(window)).catch(error=>dialog.showMessageBox(window,{type:'error',title:'فسخانستا',message:error.message}))}]:[]),
      ...(prepare ? [{ label: 'تجهيز للعمل بدون إنترنت', click: () => {
        if (!window || localToken || quitting()) return;
        window.webContents.executeJavaScript('document.querySelector(\'meta[name="csrf-token"]\')?.content || ""')
          .then(csrf => accepted(() => prepare(origin, csrf)))
          .catch(error => dialog.showMessageBox(window, { type: 'error', title: 'فسخانستا', message: error.message }));
      } }] : []),
      { label: archive ? 'سجل النسخة السابقة' : 'الطلبات المحلية والطابعة', accelerator: 'CmdOrCtrl+L', click: () => archive ? archive() : offline('') },
      ...(reviewSync?[{label:'مراجعة المزامنة',click:()=>accepted(()=>reviewSync(window)).catch(error=>dialog.showMessageBox(window,{type:'error',title:'فسخانستا',message:error.message}))}]:[]),
      { label: 'اختيار الطابعة', click: () => {
        if (selectPrinter) { accepted(() => selectPrinter(window)).catch(error => dialog.showMessageBox(window, { type: 'error', title: 'فسخانستا', message: error.message })); return; }
        offline('');
        offlineWindow().webContents.executeJavaScript('document.getElementById("settings").click()').catch(() => {});
      } },
      { type: 'separator' }, { label: 'إغلاق البرنامج', role: 'quit' }
    ] },
    { label: 'تحرير', submenu: [{ label: 'تراجع', role: 'undo' }, { label: 'إعادة', role: 'redo' }, { type: 'separator' },
      { label: 'قص', role: 'cut' }, { label: 'نسخ', role: 'copy' }, { label: 'لصق', role: 'paste' }, { label: 'تحديد الكل', role: 'selectAll' }] },
    { label: 'عرض', submenu: [{ label: 'تحديث الصفحة', accelerator: 'CmdOrCtrl+R', click: () => focused()?.reload() },
      { label: 'تكبير', role: 'zoomIn' }, { label: 'تصغير', role: 'zoomOut' }, { label: 'الحجم الطبيعي', role: 'resetZoom' },
      { label: 'ملء الشاشة', role: 'togglefullscreen' }] },
    { label: 'طباعة', submenu: [{ label: 'طباعة الصفحة الحالية', accelerator: 'CmdOrCtrl+P', click: () => {
      if(localToken){dialog.showMessageBox(window,{type:'info',title:'فسخانستا',message:'استخدم زر طباعة الفاتورة داخل الداشبورد لحفظ نتيجة الطباعة ومنع تكرارها.'});return;}
      focused()?.webContents.print({ printBackground: true });
    } }] }
  ]));
  async function canRefresh() {
    if (loading || refreshing || quitting() || printing || children.size || remoteWrites.size || activeRequests.size) return false;
    if (!window || window.isDestroyed()) return true;
    const current = window.webContents.getURL();
    if (!policy.sameOrigin(current, origin) || new URL(current).pathname !== '/admin/dashboard') return false;
    // Home filters and visible dialogs can also contain unsaved work. A failed
    // probe defers replacement instead of guessing that the page is idle.
    try {
      return await window.webContents.executeJavaScript(`(() => {
        if (document.readyState !== 'complete') return false;
        const editable = 'input,textarea,select,[contenteditable]:not([contenteditable="false"])';
        if (document.activeElement?.matches(editable)) return false;
        if (document.querySelector('.modal.show,.modal.in,.swal2-container,[role="dialog"][aria-modal="true"]')) return false;
        return !Array.from(document.querySelectorAll('input:not([type="hidden"]),textarea,select')).some(field => {
          if (field.disabled || field.readOnly) return false;
          if (field.tagName === 'SELECT') return Array.from(field.options).some(option => option.selected !== option.defaultSelected);
          if (field.type === 'checkbox' || field.type === 'radio') return field.checked !== field.defaultChecked;
          return field.value !== field.defaultValue;
        });
      })()`) === true;
    } catch { return false; }
  }
  async function refresh(work) {
    if (!localToken || refreshing || quitting()) throw Error('تعذر تحديث الداشبورد الحالية.');
    if (!(await canRefresh())) return { deferred: true };
    return switchTo({ origin, localToken }, work, { preserveCurrent: true });
  }
  async function switchTo(target, work = async () => {}, { preserveCurrent = false } = {}) {
    if (refreshing || quitting() || printing || effects?.active) throw Error('انتظر انتهاء الطباعة أو انتقال الداشبورد بين الجهاز والسيرفر.');
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
  function publish(value) {
    if (window && !window.isDestroyed() && !quitting() && policy.sameOrigin(window.webContents.getURL(), origin))
      window.webContents.send('dashboard:state', value);
  }
  async function recoverPrintEffects() {
    if(!localToken || !effects)return {local:false};
    printing++;try{await effects.inspect();return {local:true};}finally{printing--;}
  }
  return { open, refresh, canRefresh, switchTo, publish, reveal, recoverPrintEffects, session: () => window?.webContents.session,
    current: () => ({ origin, local: Boolean(localToken) }), isVisible: () => Boolean(window && !window.isDestroyed() && window.isVisible()) };
};
