'use strict';
const path = require('node:path');
const { BrowserWindow, Menu, shell, dialog, ipcMain } = require('electron');
const policy = require('./dashboard-policy.cjs');

/** Runs the existing dashboard unchanged. Remote pages receive no Node or POS bridge. */
module.exports = function dashboard({ origin, offline, offlineWindow, quitting, quit, printer, localToken = '', accepted = fn => fn() }) {
  const home = origin + '/admin/dashboard';
  let window, loading = false;
  const owned = new Set();
  ipcMain.handle('dashboard:print-receipt', async (event, value) => { try { return await accepted(async () => {
    try {
      if (quitting()) throw Error('البرنامج يُغلق الآن.');
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
    if (loading || quitting()) return;
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
        if (localToken) {
          contents.session.webRequest.onBeforeSendHeaders({ urls: ['<all_urls>'] }, (details, callback) => {
            const headers = { ...details.requestHeaders };
            const supplied = Object.entries(headers).find(([name]) => name.toLowerCase() === 'x-fasakhansta-desktop')?.[1];
            for (const name of Object.keys(headers)) if (/^x-fasakhansta-(?:desktop|control)$/i.test(name)) delete headers[name];
            if (!policy.sameOrigin(details.url, origin)) { callback({ requestHeaders: headers }); return; }
            const native = (details.webContentsId == null || details.webContentsId <= 0) && supplied === localToken;
            if (quitting() || (!owned.has(details.webContentsId) && !native)) { callback({ cancel: true }); return; }
            callback({ requestHeaders: { ...headers, 'X-Fasakhansta-Desktop': localToken } });
          });
        }
        contents.on('will-navigate', navigate);
        contents.on('will-redirect', navigate);
        contents.setWindowOpenHandler(({ url }) => {
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
          child.on('closed', () => owned.delete(childId));
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
          if (mainFrame && policy.networkFailure(description)) {
            if (localToken) dialog.showMessageBox(window, { type:'error', title:'فسخانستا', message:'تعذر الاتصال بالداشبورد المحلية. بيانات الجهاز محفوظة؛ أعد فتح البرنامج.' });
            else offline('انقطع الاتصال بالداشبورد. الطلبات المحلية محفوظة على الجهاز.');
          }
        });
        // Fetch failures during SPA navigation do not trigger did-fail-load.
        contents.session.webRequest.onErrorOccurred({ urls: [origin + '/*'] }, details => {
          if (!localToken && policy.networkFailure(details.error)) offline('انقطع الاتصال بالداشبورد. الطلبات المحلية محفوظة على الجهاز.');
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
      else if (policy.networkFailure(error.message)) offline('الداشبورد غير متصلة حاليًا. يمكنك العمل على الطلبات المحلية ثم العودة للداشبورد من قائمة البرنامج.');
      else {
        // Server errors stay visible with their real status; they are not cached or called offline saves.
        reveal();
        await dialog.showMessageBox(window, { type: 'error', title: 'فسخانستا', message: 'تعذر فتح الداشبورد. أعد المحاولة من قائمة البرنامج.' });
      }
    } finally { loading = false; }
  }
  function navigate(event, url) {
    if (policy.sameOrigin(url, origin)) return;
    event.preventDefault();
    if (policy.externalURL(url)) shell.openExternal(url);
  }
  function focused() { return BrowserWindow.getFocusedWindow() || window || offlineWindow(); }
  Menu.setApplicationMenu(Menu.buildFromTemplate([
    { label: 'البرنامج', submenu: [
      { label: 'الداشبورد كاملة', accelerator: 'CmdOrCtrl+D', click: () => open().catch(() => {}) },
      { label: 'الطلبات المحلية والطابعة', accelerator: 'CmdOrCtrl+L', click: () => offline('') },
      { label: 'اختيار الطابعة', click: () => {
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
  return { open, reveal, isVisible: () => Boolean(window && !window.isDestroyed() && window.isVisible()) };
};
