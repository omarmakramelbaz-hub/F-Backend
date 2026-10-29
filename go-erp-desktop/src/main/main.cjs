'use strict';
// GO ERP desktop: works on its own database and syncs with the dashboard every 30 seconds while online.
const path = require('node:path');
const { app, BrowserWindow, ipcMain, safeStorage, shell, Menu, session } = require('electron');
const db = require('./db.cjs');
const { Store } = require('./store.cjs');
const { Api } = require('./api.cjs');
const { SyncEngine } = require('./sync.cjs');
const auth = require('./auth.cjs');
const printing = require('./printing.cjs');
const rules = require('./rules.cjs');
const money = require('./money.cjs');

const SERVER = process.env.GOERP_SERVER || 'https://fasakhaninja.com';
// Lets the automatic tests run the app on a separate data folder.
if (process.env.GOERP_USER_DATA) app.setPath('userData', process.env.GOERP_USER_DATA);
const RENDERER = path.join(__dirname, '..', '..', 'dist', 'renderer', 'index.html');

if (!app.requestSingleInstanceLock()) app.quit();

let store;
let api;
let sync;
let mainWindow = null;
let dashboardWindow = null;
let current = null; // { user, branch } of the signed-in account
// A cashier PC's clock is often wrong. Sales and the working day use the server's time from the last sync.
let clockOffset = 0;
const now = () => new Date(Date.now() + clockOffset);

function sealToken(token) {
  if (safeStorage.isEncryptionAvailable()) return { sealed: safeStorage.encryptString(token).toString('base64') };
  return { plain: token };
}

function openToken(device) {
  if (device.token && device.token.sealed) return safeStorage.decryptString(Buffer.from(device.token.sealed, 'base64'));
  return device.token && device.token.plain;
}

function publicDevice() {
  const device = store.device();
  return device ? { id: device.id, name: device.name, code: device.code, server: device.server } : null;
}

function requireSession() {
  if (!current) throw new Error('سجّل الدخول أولًا.');
  // Permissions are re-read from the latest synced accounts on every action.
  const user = store.user(current.user.id);
  if (!user || !user.branches.includes(current.branch)) {
    current = null;
    throw new Error('تغيرت صلاحيات الحساب. سجّل الدخول مرة أخرى.');
  }
  current.user = user;
  return current;
}

function printSettings() {
  return store.get('print', { printer: '', width: 72, kitchen_printer: '', auto_print: true });
}

function branchInfo(value) {
  return store.branches().find((branch) => branch.value === value) || { value, name: '', address: '', phone: '' };
}

const handlers = {
  'app:info': () => ({ version: app.getVersion(), server: SERVER, device: publicDevice(), branches: store.branches(),
    session: current, sync: sync.status(), print: printSettings() }),

  'setup:enroll': async (values) => {
    if (store.device()) throw new Error('هذا الجهاز مجهز بالفعل.');
    let result;
    try {
      result = await api.enroll({ login: values.login, password: values.password, device_name: values.device_name,
        branches: values.branches || undefined });
    } catch (error) {
      if (error.status === 0) throw new Error('لا يوجد اتصال بالإنترنت. تجهيز الجهاز أول مرة يحتاج إنترنت.');
      throw error;
    }
    if (result.choose_branches) return { choose: result.branches };
    const code = result.device.id.replace(/[^a-f0-9]/gi, '').slice(-3).toUpperCase();
    store.saveDevice({ id: result.device.id, name: values.device_name || 'GO ERP', code, server: SERVER,
      token: sealToken(result.token), enrolled_at: new Date().toISOString() }, result.device.branches);
    sync.state.device_revoked = false;
    await sync.run({ refresh: true });
    return { done: true, users: store.userCount() };
  },

  // Only a device the server stopped can be set up again; its saved invoices stay on the device.
  'setup:reset': () => {
    if (!sync.state.device_revoked) throw new Error('الجهاز يعمل بشكل طبيعي.');
    store.forgetDevice();
    current = null;
    sync.state.device_revoked = false;
    return true;
  },

  'auth:login': (values) => {
    const user = auth.login(store, values.login, values.password);
    const usable = user.branches.filter((value) => store.catalog(value));
    const branch = usable.includes(values.branch) ? values.branch : (usable[0] || user.branches[0]);
    current = { user, branch };
    return current;
  },

  'auth:logout': () => { current = null; return true; },

  'auth:branch': (values) => {
    const session = requireSession();
    if (!session.user.branches.includes(values.branch)) throw new Error('الفرع غير متاح لهذا الحساب.');
    session.branch = values.branch;
    return session;
  },

  'catalog:get': () => {
    const session = requireSession();
    const catalog = store.catalog(session.branch);
    if (!catalog) throw new Error('لم يتم تنزيل مينيو هذا الفرع بعد. اتصل بالإنترنت للمزامنة مرة واحدة.');
    return catalog;
  },

  'tickets:open': () => store.openTickets(requireSession().branch),

  'tickets:save': (values) => {
    const session = requireSession();
    return store.saveTicket(session.user, { ...values, branch: session.branch });
  },

  'tickets:delete': (values) => store.deleteTicket(requireSession().user, values.id),

  'tickets:quote': (values) => {
    const session = requireSession();
    return store.quoteTicket(session.branch, values.channel, values.data).quote;
  },

  'tickets:pay': async (values) => {
    const session = requireSession();
    const ticket = store.payTicket(session.user, values.id, { cash_received: values.cash_received, data: values.data });
    sync.kick();
    let printed = null;
    if (printSettings().auto_print) printed = await printing.print(BrowserWindow, printing.receipt(ticket, branchInfo(ticket.branch), printSettings()), printSettings());
    return { ticket, printed };
  },

  'tickets:kitchen': async (values) => {
    const session = requireSession();
    const ticket = store.ticket(values.id);
    if (!ticket || ticket.branch !== session.branch) throw new Error('الفاتورة غير موجودة.');
    const quote = store.quoteTicket(ticket.branch, ticket.channel, ticket.data).quote;
    const settings = printSettings();
    return printing.print(BrowserWindow, printing.kitchen(ticket, quote, branchInfo(ticket.branch), settings),
      { ...settings, printer: settings.kitchen_printer || settings.printer });
  },

  'receipts:list': (values) => {
    const session = requireSession();
    const day = values.day || rules.operatingDay(now());
    return { day, today: rules.operatingDay(now()), summary: store.daySummary(session.branch, day), items: store.receipts(session.branch, day) };
  },

  'receipts:print': async (values) => {
    const session = requireSession();
    const ticket = store.ticket(values.id);
    if (!ticket || ticket.status !== 'paid' || ticket.branch !== session.branch) throw new Error('الفاتورة غير موجودة.');
    if (!session.user.can_reprint) throw new Error('إعادة طباعة الفواتير متاحة للمالك والإدارة فقط.');
    return printing.print(BrowserWindow, printing.receipt(ticket, branchInfo(ticket.branch), { ...printSettings(), copy: true }), printSettings());
  },

  'cash:summary': (values) => {
    const session = requireSession();
    const day = values.day || rules.operatingDay(now());
    const user = session.user;
    return { day, today: rules.operatingDay(now()), summary: store.cashSummary(session.branch, day), items: store.journal(session.branch, day),
      categories: store.expenseCategories(session.branch),
      can: { expense: user.can_checkout, approve: user.can_approve_expenses, till: user.can_manage, shift: user.can_close_shift } };
  },

  'cash:expense': (values) => {
    const session = requireSession();
    const entry = store.recordExpense(session.user, session.branch, values);
    sync.kick();
    return entry;
  },

  'cash:till': (values) => {
    const session = requireSession();
    const entry = store.recordTill(session.user, session.branch, values);
    sync.kick();
    return entry;
  },

  'print:printers': async () => {
    const list = mainWindow ? await mainWindow.webContents.getPrintersAsync() : [];
    return list.map((printer) => ({ name: printer.name, label: printer.displayName || printer.name, default: !!printer.isDefault }));
  },

  'print:settings': (values) => {
    if (values && Object.keys(values).length) {
      const name = (value) => String(value || '').slice(0, 200);
      store.set('print', { printer: name(values.printer), kitchen_printer: name(values.kitchen_printer),
        width: Number(values.width) === 58 ? 58 : 72, auto_print: values.auto_print !== false });
    }
    return printSettings();
  },

  'print:test': async () => {
    const session = requireSession();
    const branch = branchInfo(session.branch);
    const html = printing.receipt({ local_number: 'تجربة', paid_at: now().toISOString(), actor_name: session.user.name, channel: 'takeaway',
      data: { customer_phone: '-', customer_name: '', notes: 'طباعة تجريبية' },
      totals: { items: [{ name: 'صنف تجريبي', option_label: '', quantity_mode: 'piece', quantity_millis: 1000, unit_price_cents: 100, total_cents: 100 }],
        subtotal_cents: 100, discount_cents: 0, service_cents: 0, delivery_cents: 0, tax_cents: 0, total_cents: 100, cash_received_cents: 100, change_cents: 0 } },
    branch, printSettings());
    return printing.print(BrowserWindow, html, printSettings());
  },

  'sync:status': () => ({ ...sync.status(), rejected_list: store.rejectedOperations() }),

  'sync:now': async () => { await sync.run({ refresh: true }); return sync.status(); },

  // Online-only parts of the dashboard (shift closing, expense review, and every other page) open in their own window.
  'dashboard:open': (values) => {
    const pages = { home: '/admin/login', shifts: '/admin/branch-shifts', expenses: '/admin/branch-expenses' };
    const url = SERVER + (pages[values.page] || pages.home);
    if (dashboardWindow && !dashboardWindow.isDestroyed()) {
      if (values.page) dashboardWindow.loadURL(url);
      dashboardWindow.focus();
      return true;
    }
    dashboardWindow = new BrowserWindow({ width: 1366, height: 860, title: 'الداشبورد - GO ERP', autoHideMenuBar: true,
      webPreferences: { partition: 'persist:dashboard', sandbox: true, contextIsolation: true } });
    dashboardWindow.loadURL(url);
    return true;
  },
};

function registerHandlers() {
  for (const [channel, handler] of Object.entries(handlers)) {
    ipcMain.handle(channel, async (event, values) => {
      if (event.senderFrame.url !== mainWindow.webContents.getURL()) return { ok: false, error: 'طلب غير مسموح.' };
      try {
        return { ok: true, value: await handler(values || {}) };
      } catch (error) {
        return { ok: false, error: error && error.message ? error.message : 'حدث خطأ غير متوقع.' };
      }
    });
  }
}

function createWindow() {
  mainWindow = new BrowserWindow({ width: 1366, height: 860, minWidth: 1024, minHeight: 680, title: 'GO ERP', autoHideMenuBar: true,
    backgroundColor: '#f4f6fb', show: false,
    webPreferences: { preload: path.join(__dirname, 'preload.cjs'), contextIsolation: true, sandbox: true, nodeIntegration: false } });
  mainWindow.once('ready-to-show', () => { mainWindow.maximize(); mainWindow.show(); });
  mainWindow.webContents.setWindowOpenHandler(({ url }) => { if (/^https:\/\//.test(url)) shell.openExternal(url); return { action: 'deny' }; });
  mainWindow.webContents.on('will-navigate', (event) => event.preventDefault());
  mainWindow.loadFile(RENDERER);
}

app.whenReady().then(() => {
  Menu.setApplicationMenu(null);
  session.defaultSession.setPermissionRequestHandler((webContents, permission, callback) => callback(false));
  store = new Store(db.open(path.join(app.getPath('userData'), 'goerp.sqlite')), { now });
  const readOffset = () => { const value = Number(store.get('server_offset_ms')); clockOffset = Number.isFinite(value) ? value : 0; };
  readOffset();
  api = new Api({ baseUrl: SERVER, fetchImpl: (url, init) => require('electron').net.fetch(url, init) });
  sync = new SyncEngine({ store, api, token: () => openToken(store.device()) });
  sync.on('status', (status) => {
    readOffset();
    if (mainWindow && !mainWindow.isDestroyed()) mainWindow.webContents.send('sync:status', status);
  });
  registerHandlers();
  createWindow();
  sync.start();
  if (app.isPackaged) checkForUpdates();
});

/** New versions download in the background from GitHub and install the next time the app is closed. */
function checkForUpdates() {
  let updater;
  try { updater = require('electron-updater').autoUpdater; } catch { return; }
  const check = () => updater.checkForUpdatesAndNotify().catch(() => { /* offline or no release yet */ });
  check();
  setInterval(check, 4 * 60 * 60 * 1000);
}

app.on('second-instance', () => { if (mainWindow) { if (mainWindow.isMinimized()) mainWindow.restore(); mainWindow.focus(); } });
app.on('window-all-closed', () => { sync && sync.stop(); app.quit(); });

module.exports = { handlers, money };
