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
// What the signed-in person typed, kept in memory only, to sign the online dashboard window in as the same account.
let dashboardLogin = null;
let dashboardTarget = null;
let dashboardAutoLogin = false;
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
    dashboardLogin = null;
    resetDashboard();
    throw new Error('تغيرت صلاحيات الحساب. سجّل الدخول مرة أخرى.');
  }
  current.user = user;
  return current;
}

function printSettings() {
  return { printer: '', width: 72, kitchen_printer: '', auto_print: true, kitchen_auto: false, ...store.get('print', {}) };
}

function autostart() {
  try { return app.getLoginItemSettings().openAtLogin; } catch { return false; }
}

/** A copy of the device database once a day, kept for a week, next to the database itself. */
function backupDatabase() {
  try { db.backup(store.db, path.join(app.getPath('userData'), 'backups'), rules.operatingDay(now())); } catch { /* the next run tries again */ }
}

function branchInfo(value) {
  return store.branches().find((branch) => branch.value === value) || { value, name: '', address: '', phone: '' };
}

const handlers = {
  'app:info': () => ({ version: app.getVersion(), server: SERVER, device: publicDevice(), branches: store.branches(),
    session: current, sync: sync.status(), print: printSettings(), autostart: autostart() }),

  'app:autostart': (values) => {
    requireSession();
    if (app.isPackaged) app.setLoginItemSettings({ openAtLogin: !!values.enabled });
    return autostart();
  },

  'customers:lookup': (values) => {
    requireSession();
    return store.customer(values.phone);
  },

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
    dashboardLogin = null;
    resetDashboard();
    sync.state.device_revoked = false;
    return true;
  },

  'auth:login': async (values) => {
    const user = auth.login(store, values.login, values.password);
    const usable = user.branches.filter((value) => store.catalog(value));
    const branch = usable.includes(values.branch) ? values.branch : (usable[0] || user.branches[0]);
    // A previous account's dashboard session never carries over to the next person.
    await resetDashboard();
    current = { user, branch };
    // The dashboard finds an account by email, or by its mobile typed with one extra leading zero.
    dashboardLogin = { login: user.email ? String(user.email) : '0' + String(user.mobile), password: String(values.password || '') };
    return current;
  },

  'auth:logout': async () => {
    current = null;
    dashboardLogin = null;
    await resetDashboard();
    return true;
  },

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
    const settings = printSettings();
    if (settings.auto_print) printed = await printing.print(BrowserWindow, printing.receipt(ticket, branchInfo(ticket.branch), settings), settings);
    if (settings.kitchen_auto) {
      await printing.print(BrowserWindow, printing.kitchen(ticket, ticket.totals, branchInfo(ticket.branch), settings),
        { ...settings, printer: settings.kitchen_printer || settings.printer });
    }
    return { ticket, printed };
  },

  // The bill before payment; for a delivery order it also records that the order left with the driver.
  'tickets:bill': async (values) => {
    const session = requireSession();
    let ticket = store.ticket(values.id);
    if (!ticket || ticket.branch !== session.branch || ticket.status !== 'open') throw new Error('الفاتورة غير موجودة.');
    if (values.out) ticket = store.markOut(session.user, ticket.id, values.data || {});
    const catalog = store.catalog(ticket.branch) || {};
    const quote = store.quoteTicket(ticket.branch, ticket.channel, ticket.data).quote;
    const settings = printSettings();
    const printed = await printing.print(BrowserWindow, printing.bill(ticket, quote, branchInfo(ticket.branch),
      { ...settings, companies: catalog.delivery_companies || [] }), settings);
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

  'attendance:board': () => {
    const session = requireSession();
    const day = rules.operatingDay(now());
    return { day, now: now().toISOString(), shifts: store.attendanceShifts(session.branch), items: store.attendanceBoard(session.branch, day) };
  },

  'attendance:punch': (values) => {
    const session = requireSession();
    const entry = store.recordPunch(session.user, session.branch, values);
    sync.kick();
    return entry;
  },

  'reports:sales': (values) => {
    const session = requireSession();
    if (!session.user.can_manage) throw new Error('التقارير متاحة للمالك والإدارة فقط.');
    const today = rules.operatingDay(now());
    const from = values.from || today;
    const to = values.to || from;
    const report = store.salesReport(session.branch, from, to);
    if (from === to) report.cash = store.cashSummary(session.branch, from);
    return { today, report };
  },

  'reports:print': async (values) => {
    const session = requireSession();
    const { report } = handlers['reports:sales'](values);
    return printing.print(BrowserWindow, printing.report(report, branchInfo(session.branch), printSettings()), printSettings());
  },

  'print:printers': async () => {
    const list = mainWindow ? await mainWindow.webContents.getPrintersAsync() : [];
    return list.map((printer) => ({ name: printer.name, label: printer.displayName || printer.name, default: !!printer.isDefault }));
  },

  'print:settings': (values) => {
    if (values && Object.keys(values).length) {
      const name = (value) => String(value || '').slice(0, 200);
      store.set('print', { printer: name(values.printer), kitchen_printer: name(values.kitchen_printer),
        width: Number(values.width) === 58 ? 58 : 72, auto_print: values.auto_print !== false, kitchen_auto: values.kitchen_auto === true });
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

  // Online-only parts of the dashboard (shift closing, expense review, and every other page) open in their own window,
  // signed in as the same account.
  'dashboard:open': (values) => {
    openDashboard(values.page);
    return true;
  },
};

const DASHBOARD_PAGES = { home: '/admin/dashboard', shifts: '/admin/branch-shifts', expenses: '/admin/branch-expenses' };

function openDashboard(page) {
  const target = SERVER + (DASHBOARD_PAGES[page] || DASHBOARD_PAGES.home);
  dashboardTarget = page && page !== 'home' ? target : null;
  if (dashboardWindow && !dashboardWindow.isDestroyed()) {
    if (page) dashboardWindow.loadURL(target);
    dashboardWindow.focus();
    return;
  }
  dashboardAutoLogin = false;
  dashboardWindow = new BrowserWindow({ width: 1366, height: 860, title: 'الداشبورد - GO ERP', autoHideMenuBar: true,
    webPreferences: { partition: 'persist:dashboard', sandbox: true, contextIsolation: true } });
  const contents = dashboardWindow.webContents;
  contents.on('did-finish-load', () => onDashboardLoad(contents));
  contents.on('did-fail-load', (event, code, description, url, mainFrame) => {
    if (!mainFrame || code === -3) return; // -3: the load was replaced by another one
    contents.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(`<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8">
      <body style="font-family:Tahoma,Arial,sans-serif;display:grid;place-items:center;height:90vh;color:#182335">
      <div style="text-align:center"><h2>الداشبورد يحتاج إنترنت</h2><p>الكاشير والخزنة والمصروفات شغالين من غير نت من البرنامج نفسه.</p>
      <p><a href="${target}">إعادة المحاولة</a></p></div></body></html>`));
  });
  dashboardWindow.on('closed', () => { dashboardWindow = null; });
  dashboardWindow.loadURL(target);
}

/** Fills the dashboard's own login form once per window; a manual logout inside the dashboard is respected. */
async function onDashboardLoad(contents) {
  if (!dashboardWindow || dashboardWindow.isDestroyed()) return;
  let url;
  try { url = new URL(contents.getURL()); } catch { return; }
  if (url.origin !== new URL(SERVER).origin) return;
  if (url.pathname.replace(/\/+$/, '') === '/admin/login') {
    if (dashboardAutoLogin || !dashboardLogin) return;
    dashboardAutoLogin = true;
    const { login, password } = dashboardLogin;
    await contents.executeJavaScript(`(() => {
      const form = document.querySelector('[data-dashboard-login]') || document.querySelector('form[action*="signin"]');
      if (!form) return false;
      form.querySelector('[name="email"]').value = ${JSON.stringify(login)};
      form.querySelector('[name="password"]').value = ${JSON.stringify(password)};
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
      return true;
    })()`).catch(() => {});
    return;
  }
  if (dashboardTarget && !url.href.startsWith(dashboardTarget)) {
    const target = dashboardTarget;
    dashboardTarget = null;
    contents.loadURL(target);
    return;
  }
  dashboardTarget = null;
}

async function resetDashboard() {
  if (dashboardWindow && !dashboardWindow.isDestroyed()) dashboardWindow.destroy();
  dashboardWindow = null;
  dashboardTarget = null;
  try { await session.fromPartition('persist:dashboard').clearStorageData(); } catch { /* nothing stored yet */ }
}

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
  backupDatabase();
  setInterval(backupDatabase, 6 * 60 * 60 * 1000);
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
