'use strict';
const { BrowserWindow, ipcMain, session } = require('electron');
const crypto = require('node:crypto');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const validId = value => typeof value === 'string' && (value === 'default' || /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value));

/** Select a profile only; the caller owns account switching and shutdown. */
async function chooseAccount({ parent, profiles, signal } = {}) {
  if (signal?.aborted || parent?.isDestroyed()) return null;
  if (!Array.isArray(profiles) || profiles.length > 64) throw Error('قائمة الحسابات غير صالحة.');
  const ids = new Set(); let selected = 0;
  const safe = profiles.map(value => {
    if (!value || !validId(value.id) || ids.has(value.id) || typeof value.label !== 'string'
        || !value.label.trim() || value.label.length > 160 || /[\x00-\x1f\x7f]/.test(value.label)
        || typeof value.selected !== 'boolean' || value.selected && ++selected > 1) throw Error('قائمة الحسابات غير صالحة.');
    ids.add(value.id); return { id: value.id, label: value.label, selected: value.selected };
  });
  const nonce = crypto.randomBytes(16).toString('hex'), channel = 'go-account-picker:' + nonce;
  const page = path.join(__dirname, 'account-picker.html'), pageURL = pathToFileURL(page).href;
  const allowed = new Set(['account-picker.html', 'account-picker.css', 'account-picker-renderer.js']
    .map(name => pathToFileURL(path.join(__dirname, name)).href));
  const isolated = session.fromPartition('go-account-picker-' + nonce, { cache: false });
  isolated.setPermissionRequestHandler((_sender, _permission, callback) => callback(false));
  isolated.setPermissionCheckHandler(() => false);
  isolated.setDevicePermissionHandler?.(() => false);
  const window = new BrowserWindow({
    parent, modal: Boolean(parent), width: 568, height: 650, minWidth: 480, minHeight: 500,
    resizable: false, minimizable: false, maximizable: false, show: false, autoHideMenuBar: true,
    title: 'GO ERP — اختيار الحساب', backgroundColor: '#f4f6fa',
    webPreferences: { session: isolated, sandbox: true, contextIsolation: true, nodeIntegration: false,
      webSecurity: true, webviewTag: false, preload: path.join(__dirname, 'account-picker-preload.cjs'),
      additionalArguments: ['--go-account-picker=' + nonce] }
  });
  window.removeMenu();
  const contents = window.webContents;
  contents.setWindowOpenHandler(() => ({ action: 'deny' }));
  isolated.webRequest.onBeforeRequest({ urls: ['<all_urls>'] }, (details, callback) => {
    callback({ cancel: details.webContentsId !== contents.id || details.method !== 'GET' || !allowed.has(details.url) });
  });
  return new Promise(resolve => {
    let settled = false;
    const prevent = event => event.preventDefault();
    const onClosed = () => finish(null), onAbort = () => finish(null);
    const onNavigated = (_event, url) => { if (url !== pageURL) finish(null); };
    const finish = result => {
      if (settled) return;
      settled = true;
      ipcMain.removeHandler(channel);
      signal?.removeEventListener('abort', onAbort);
      parent?.removeListener('closed', onClosed);
      window.removeListener('closed', onClosed);
      for (const name of ['will-navigate', 'will-frame-navigate', 'will-redirect', 'will-attach-webview']) contents.removeListener(name, prevent);
      contents.removeListener('did-navigate', onNavigated);
      contents.removeListener('render-process-gone', onClosed);
      if (!window.isDestroyed()) window.destroy();
      isolated.webRequest.onBeforeRequest(null);
      resolve(result);
    };
    ipcMain.handle(channel, (event, input) => {
      if (settled || window.isDestroyed() || event.sender !== contents || event.senderFrame !== contents.mainFrame
          || event.senderFrame?.url !== pageURL || contents.getURL() !== pageURL)
        throw Error('طلب اختيار الحساب غير متاح.');
      if (!input || typeof input !== 'object' || Array.isArray(input) || typeof input.action !== 'string') return false;
      const keys = Object.keys(input).sort().join(',');
      if (input.action === 'list' && keys === 'action') return { profiles: safe.map(value => ({ ...value })) };
      if (input.action === 'select' && keys === 'action,id' && validId(input.id) && ids.has(input.id)) {
        finish({ action: 'select', id: input.id }); return true;
      }
      if (input.action === 'add' && keys === 'action') { finish({ action: 'add' }); return true; }
      if (input.action === 'cancel' && keys === 'action') { finish(null); return true; }
      return false;
    });
    for (const name of ['will-navigate', 'will-frame-navigate', 'will-redirect', 'will-attach-webview']) contents.on(name, prevent);
    contents.on('did-navigate', onNavigated); contents.on('render-process-gone', onClosed);
    window.once('closed', onClosed); parent?.once('closed', onClosed);
    signal?.addEventListener('abort', onAbort, { once: true });
    if (signal?.aborted) { finish(null); return; }
    window.loadFile(page).then(() => {
      if (!settled && !window.isDestroyed()) { window.show(); window.focus(); }
    }).catch(() => finish(null));
  });
}

module.exports = chooseAccount;
