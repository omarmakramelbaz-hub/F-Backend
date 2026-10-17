'use strict';
const path = require('node:path');
const crypto = require('node:crypto');
const { BrowserWindow, Menu, shell, dialog, ipcMain } = require('electron');
const policy = require('./dashboard-policy.cjs');

/** Runs the existing dashboard unchanged. Remote pages receive no Node or POS bridge. */
module.exports = function dashboard({ origin, serverOrigin = null, offline, offlineWindow, quitting, quit, printer, localToken = '',
  prepare, remoteState, remoteAttempts, archive, selectPrinter, status = async () => ({ available: false }), synchronize = async () => {}, accepted = fn => fn() }) {
  let home = origin + '/admin/dashboard';
  if (!serverOrigin && !localToken) serverOrigin = origin;
  let window, loading = false, refreshing = false;
  const owned = new Set();
  const children = new Set();
  const remoteWrites = new Map();
  const readPosts = new Set(['/admin/takeaway/quote', '/admin/dining/quote', '/admin/phone-orders/quote', '/admin/phone-orders/delivery-quote',
    '/admin/fetch-subcategory', '/admin/fetch-product', '/admin/fetch-feature']);
  function remoteWrite(details) {
    if (localToken || !owned.has(details.webContentsId) || !policy.sameOrigin(details.url, origin)) return false;
    const pathname = new URL(details.url).pathname;
    if (readPosts.has(pathname)) return false;
    return pathname.startsWith('/admin/') && (!['GET', 'HEAD'].includes(String(details.method || 'GET').toUpperCase())
      || /(?:delete|destroy|update|save|send|accept|finish|clear-cache|test-notification|resturantControl)/i.test(pathname));
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
    synchronize: () => synchronize()
  })) ipcMain.handle('dashboard:' + name, async (event, value) => {
    try {
      if (!trusted(event)) throw Error('مصدر الداشبورد غير مسموح.');
      return { ok: true, value: await accepted(() => work(value)) };
    } catch (error) { return { ok: false, error: error.message }; }
  });
  ipcMain.handle('dashboard:print-receipt', async (event, value) => { try { return await accepted(async () => {
    try {
      if (quitting()) throw Error('البرنامج يُغلق الآن.');
      if (refreshing) throw Error('يجري تحديث بيانات الداشبورد؛ أعد المحاولة بعد اكتماله.');
      if (!owned.has(event.sender.id) || !policy.sameOrigin(event.senderFrame.url, origin)
          || !policy.sameOrigin(value, origin)) throw Error('مصدر الطباعة غير مسموح.');
      const url = new URL(value);
      if (!url.pathname.startsWith('/admin/')) throw Error('رابط الفاتورة غير صحيح.');
      url.searchParams.set('dashboard_print', '1');
      const response = await event.sender.session.fetch(url.href, { credentials: 'include', headers: { Accept: 'text/html', ...(localToken ? { 'X-Fasakhansta-Desktop': localToken } : {}) }, redirect: 'error', signal: AbortSignal.timeout(25000) });
      if (!response.ok || !/text\/html/i.test(response.headers.get('content-type') || '')) throw Error('تعذر تحميل الفاتورة.');
      const html = await response.text();
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
            for (const name of Object.keys(headers)) if (/^x-fasakhansta-(?:desktop|control|remote-attempt|remote-capability)$/i.test(name)) delete headers[name];
            if (!policy.sameOrigin(details.url, origin)) { callback({ requestHeaders: headers }); return; }
            if (!localToken) {
              if (refreshing || quitting()) { callback({ cancel: true }); return; }
              if (!remoteWrite(details)) { callback({ requestHeaders: headers }); return; }
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
      ...(prepare ? [{ label: 'تجهيز للعمل بدون إنترنت', click: () => {
        if (!window || localToken || quitting()) return;
        window.webContents.executeJavaScript('document.querySelector(\'meta[name="csrf-token"]\')?.content || ""')
          .then(csrf => accepted(() => prepare(origin, csrf)))
          .catch(error => dialog.showMessageBox(window, { type: 'error', title: 'فسخانستا', message: error.message }));
      } }] : []),
      { label: archive ? 'سجل النسخة السابقة' : 'الطلبات المحلية والطابعة', accelerator: 'CmdOrCtrl+L', click: () => archive ? archive() : offline('') },
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
    { label: 'طباعة', submenu: [{ label: 'طباعة الصفحة الحالية', accelerator: 'CmdOrCtrl+P', click: () => focused()?.webContents.print({ printBackground: true }) }] }
  ]));
  async function refresh(work) {
    if (!localToken || refreshing || quitting()) throw Error('تعذر تحديث الداشبورد الحالية.');
    return switchTo({ origin, localToken }, work);
  }
  async function switchTo(target, work = async () => {}) {
    if (refreshing || quitting()) throw Error('الداشبورد تنتقل بين نسختي الجهاز والسيرفر.');
    const destination = new URL(target.origin);
    const local = destination.protocol === 'http:' && destination.hostname === '127.0.0.1' && destination.port && target.localToken;
    const remote = destination.protocol === 'https:' && target.origin === serverOrigin && !target.localToken;
    if ((!local && !remote) || destination.username || destination.password || destination.origin !== target.origin)
      throw Error('وجهة الداشبورد غير مسموحة.');
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
      if (window && !window.isDestroyed() && !quitting()) await open(home);
    }
  }
  function publish(value) {
    if (window && !window.isDestroyed() && !quitting() && policy.sameOrigin(window.webContents.getURL(), origin))
      window.webContents.send('dashboard:state', value);
  }
  return { open, refresh, switchTo, publish, reveal, session: () => window?.webContents.session,
    current: () => ({ origin, local: Boolean(localToken) }), isVisible: () => Boolean(window && !window.isDestroyed() && window.isVisible()) };
};
