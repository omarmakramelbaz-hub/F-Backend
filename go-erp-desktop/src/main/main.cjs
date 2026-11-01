'use strict';
// GO ERP desktop: works on its own database and syncs with the dashboard every 30 seconds while online.
const path = require('node:path');
const { app, BrowserWindow, WebContentsView, ipcMain, safeStorage, shell, Menu, session } = require('electron');
const db = require('./db.cjs');
const { Store } = require('./store.cjs');
const { Api } = require('./api.cjs');
const { SyncEngine } = require('./sync.cjs');
const auth = require('./auth.cjs');
const printing = require('./printing.cjs');
const rules = require('./rules.cjs');
const money = require('./money.cjs');
const { PageCache } = require('./offline/cache.cjs');
const { DashboardOffline, warmUpScript } = require('./offline/interceptor.cjs');
const { TakeawayOffline } = require('./offline/takeaway.cjs');
const { ExpensesOffline } = require('./offline/expenses.cjs');
const { EmployeesOffline } = require('./offline/employees.cjs');
const { StockOffline } = require('./offline/stock.cjs');
const { ShiftsOffline } = require('./offline/shifts.cjs');
const { ServiceOffline } = require('./offline/service.cjs');
const { DeliveryOffline } = require('./offline/delivery.cjs');

const SERVER = process.env.GOERP_SERVER || 'https://fasakhaninja.com';
// Lets the automatic tests run the app on a separate data folder.
if (process.env.GOERP_USER_DATA) app.setPath('userData', process.env.GOERP_USER_DATA);
const RENDERER = path.join(__dirname, '..', '..', 'dist', 'renderer', 'index.html');

if (!app.requestSingleInstanceLock()) app.quit();

let store;
let api;
let sync;
let pageCache; // the dashboard pages and files saved on this device
let offline; // answers the dashboard window from the device while the internet is down
let offlineMode = false;
let mainWindow = null;
let current = null; // { user, branch } of the signed-in account
// What the signed-in person typed, kept in memory only, to sign the online dashboard window in as the same account.
let dashboardLogin = null;
let dashboardTarget = null;
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

function sessionUser() {
  return current ? store.user(current.user.id) : null;
}

const handlers = {
  'app:info': () => ({ version: app.getVersion(), server: SERVER, device: publicDevice(), branches: store.branches(),
    session: current, sync: sync.status(), print: printSettings(), autostart: autostart(), embed: EMBED, dashboard_shown: dashboardShown,
    offline_mode: offlineMode, dashboard_copy: current ? offline.hasCopy(sessionUser()) : false }),

  // The person's computer says the network is gone: answer the dashboard from the device right away.
  'app:network': (values) => {
    if (values.online === false) {
      setOfflineMode(true);
      sync.run().catch(() => {});
    } else {
      sync.kick();
    }
    return offlineMode;
  },

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
    pageCache.forgetAll();
    warmedAt.clear(); // the next sign-in saves the pages for offline use again
    current = null;
    dashboardLogin = null;
    resetDashboard();
    sync.state.device_revoked = false;
    return true;
  },

  /**
   * The person sets this device up again, e.g. with another account (another branch, or the owner's). Everything
   * recorded here goes to the dashboard first; with anything still unsent, or an order still open here, nothing
   * changes. Then the server stops the old setup and the app shows the setup screen.
   */
  'setup:release': async (values) => {
    const session = requireSession();
    if (!auth.verifyPassword(String((values && values.password) || ''), store.passwordHash(session.user.id))) {
      throw new Error('كلمة المرور غير صحيحة.');
    }
    const device = store.device();
    if (!device) throw new Error('الجهاز غير مجهز.');
    const offline = new Error('إعادة تجهيز الجهاز محتاجة إنترنت عشان الجهاز يبعت كل عملياته للداشبورد الأول. اتصل بالإنترنت وحاول تاني.');
    // Open dine-in and delivery orders move to the dashboard on one pass and are sent on the next.
    for (let pass = 0; pass < 4; pass++) {
      await sync.run();
      if (sync.state.device_revoked) break;
      if (sync.state.online === false) throw offline;
      const left = store.releaseBlockers();
      if (!left.pending && !left.open) break;
    }
    const left = store.releaseBlockers();
    if (left.open) {
      throw new Error(`فيه ${left.open === 1 ? 'طلب لسه مفتوح' : `${left.open} طلبات لسه مفتوحة`} على الجهاز (تحت). حصّله من مكانه أو ألغيه من هنا، `
        + 'وبعدين اضغط «إلغاء التجهيز الحالي» تاني، عشان مفيش حاجة تضيع.');
    }
    if (left.pending) {
      throw new Error(`فيه ${left.pending} عملية لسه ما اتبعتتش للداشبورد. استنى المزامنة (كل ٣٠ ثانية) وأعد المحاولة، عشان مفيش حاجة تضيع.`);
    }
    if (sync.state.device_revoked) {
      console.log('[goerp] set up again: the server had already stopped this device');
    } else {
      try {
        await api.release(device, openToken(device));
        console.log('[goerp] set up again: the server stopped the old setup');
      } catch (error) {
        console.log(`[goerp] set up again: the server answered ${error.status}`);
        if (error.status === 0) throw offline;
        if (error.status === 404) throw new Error('إعادة تجهيز الجهاز محتاجة تحديث السرفر الأول. لم يتغير أي شيء على الجهاز.');
        if (error.status !== 401) throw error; // 401: the server had already stopped it
      }
    }
    store.forgetDevice();
    pageCache.forgetAll();
    warmedAt.clear(); // the next sign-in saves the pages for offline use again
    current = null;
    dashboardLogin = null;
    await resetDashboard();
    sync.state.device_revoked = false;
    return { done: true };
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

  // The orders still open on this device (they hold back setting it up again), and cancelling one of them.
  'setup:open-orders': () => {
    requireSession();
    return store.openOrders();
  },

  'setup:cancel-order': (values) => {
    const session = requireSession();
    const done = store.dropOpenOrder(session.user, String((values && values.id) || ''));
    if (done) console.log('[goerp] an open order was cancelled before setting the device up again');
    return done;
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
      if (dashboardView) installDirectPrint(dashboardView.webContents);
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

  // The live dashboard, signed in as the same account, fills the window while online: the same pages,
  // menus and permissions as on the server.
  'dashboard:open': (values) => {
    requireSession();
    return showDashboard(values.page);
  },

  // The device's own screens: used while offline, or whenever the person picks them.
  'dashboard:hide': () => {
    hideDashboard();
    return true;
  },
};

// The dashboard's «برنامج الكمبيوتر» page: inside the app it also shows this device's part (setup, orders, printers).
const PROGRAM_PAGE = '/admin/desktop-dashboard';
const DASHBOARD_PAGES = { home: '/admin/dashboard', shifts: '/admin/branch-shifts', expenses: '/admin/branch-expenses', program: PROGRAM_PAGE };
const BAR_HEIGHT = 34; // the app's status bar stays visible under the dashboard
const EMBED = process.env.GOERP_DASHBOARD_VIEW !== 'off';
let dashboardView = null;
let dashboardShown = false;
let dashboardSignedIn = false; // a dashboard page loaded after signing in
let dashboardLoggingOut = false; // the person used the dashboard's own sign-out
let dashboardLastAutoLogin = 0;
const dashboardPopups = new Set();

function notifyDashboard(event) {
  if (mainWindow && !mainWindow.isDestroyed()) mainWindow.webContents.send('dashboard:event', event);
}

function sameServer(url) {
  try { return new URL(url).origin === new URL(SERVER).origin; } catch { return false; }
}

function layoutDashboard() {
  if (!dashboardView || !mainWindow || mainWindow.isDestroyed()) return;
  const [width, height] = mainWindow.getContentSize();
  dashboardView.setBounds({ x: 0, y: 0, width, height: Math.max(0, height - BAR_HEIGHT) });
}

/**
 * Online or offline. Offline the dashboard window keeps working from the device (saved pages, the cashier page's
 * answers); the 30-second sync decides when the internet is back.
 */
function setOfflineMode(value, reason = '') {
  if (offlineMode === value) return;
  console.log(`[goerp] ${new Date().toISOString().slice(11, 19)} ${value ? 'offline' : 'online'}${reason ? ': ' + reason : ''}`);
  offlineMode = value;
  offline.setOffline(value).catch(() => {});
  notifyDashboard({ type: 'connection', online: !value });
  sendDeviceBranches();
  if (!value) {
    refreshSince = Date.now();
    refreshWhenIdle();
  }
}

// Windows whose current page came from the device's copy: reloaded from the dashboard once it is idle online.
const fromCopy = new WeakSet();
let refreshTimer = null;
let refreshSince = 0;

function refreshWhenIdle() {
  clearTimeout(refreshTimer);
  const contents = dashboardView && dashboardView.webContents;
  if (offlineMode || !contents || contents.isDestroyed() || !fromCopy.has(contents)) return;
  // Dine-in orders opened offline move to the dashboard first (a few seconds), so the reloaded floor shows them on their
  // tables and nobody seats a table that is already taken. Never more than a minute.
  if (store.waitingMoves() > 0 && Date.now() - refreshSince < 60000) { refreshTimer = setTimeout(refreshWhenIdle, 2000); return; }
  contents.executeJavaScript('!window.DashboardSPA || window.DashboardSPA.isIdle()').then((idle) => {
    if (offlineMode || contents.isDestroyed()) return;
    if (!idle) { refreshTimer = setTimeout(refreshWhenIdle, 15000); return; }
    dashboardTarget = contents.getURL();
    contents.reload();
  }).catch(() => { refreshTimer = setTimeout(refreshWhenIdle, 15000); });
}

// Each account's dashboard pages are saved for offline use once it opens the dashboard online (again after 6 hours).
const warmedAt = new Map();

function warmUp(contents) {
  const user = sessionUser();
  if (!user || offlineMode || Date.now() - (warmedAt.get(user.id) || 0) < 6 * 3600 * 1000) return;
  warmedAt.set(user.id, Date.now());
  setTimeout(() => {
    if (!contents.isDestroyed() && !offlineMode && current && current.user.id === user.id) contents.executeJavaScript(warmUpScript()).catch(() => {});
  }, 4000);
}

/** Pictures of a receipt (the logo) from the device's copy, so it prints the same with the internet down. */
function inlineImages(html, base) {
  return html.replace(/(<img\b[^>]*?\bsrc=")([^"]+)(")/gi, (match, start, src, end) => {
    let url;
    try { url = new URL(src.replace(/&amp;/g, '&'), base).href; } catch { return match; }
    if (url.startsWith('data:')) return match;
    const copy = offline.printCopyOf(url);
    return copy ? `${start}data:${copy.contentType};base64,${copy.body.toString('base64')}${end}` : match;
  });
}

function createDashboardView() {
  const dashboardSession = session.fromPartition('persist:dashboard');
  const allowed = new Set(['notifications', 'geolocation', 'clipboard-sanitized-write', 'fullscreen']);
  dashboardSession.setPermissionRequestHandler((webContents, permission, callback) => callback(allowed.has(permission)));
  // backgroundThrottling off: new-order alerts keep sounding while the device's screens are in front.
  // The GO opening greets the person after signing in, as in a browser: the app signs the dashboard in itself, without a
  // click inside it, so its sound may play without one.
  const dashboardPreferences = { partition: 'persist:dashboard', sandbox: true, contextIsolation: true, backgroundThrottling: false,
    autoplayPolicy: 'no-user-gesture-required', preload: path.join(__dirname, 'dashboard-preload.cjs') };
  dashboardView = new WebContentsView({ webPreferences: dashboardPreferences });
  dashboardView.setBackgroundColor('#091320'); // the dashboard's own dark background, never a white flash
  dashboardSignedIn = false;
  dashboardLoggingOut = false;
  dashboardLastAutoLogin = 0;
  const contents = dashboardView.webContents;
  fullScreenKey(contents);
  contents.setWindowOpenHandler(({ url }) => {
    // Print views and other dashboard pop-ups open as app windows with the same session; other sites open in the browser.
    if (sameServer(url)) {
      return { action: 'allow', overrideBrowserWindowOptions: { autoHideMenuBar: true, webPreferences: dashboardPreferences } };
    }
    if (/^https?:\/\//.test(url)) shell.openExternal(url);
    return { action: 'deny' };
  });
  contents.on('did-create-window', (popup) => {
    offline.attach(popup.webContents).catch(() => {});
    dashboardPopups.add(popup);
    popup.on('closed', () => dashboardPopups.delete(popup));
    popup.webContents.on('dom-ready', () => installDirectPrint(popup.webContents));
  });
  contents.on('dom-ready', () => installDirectPrint(contents));
  // The dashboard's own sign-out link (navbar: /admin/adminLogout).
  contents.on('will-navigate', (event, url) => {
    if (sameServer(url) && /^\/admin\/(adminLogout|logout)\/?$/i.test(new URL(url).pathname)) {
      dashboardLoggingOut = true;
      console.log('[goerp] the dashboard\'s own sign-out');
    }
  });
  contents.on('did-finish-load', () => onDashboardLoad(contents));
  contents.on('did-fail-load', (event, code, description, url, mainFrame) => {
    if (!mainFrame || code === -3) return; // -3: replaced by another load
    // Neither the dashboard nor a saved copy could show the page: the app's own screens take over.
    clearTimeout(dashboardWaiting);
    dashboardWaiting = null;
    notifyDashboard({ type: 'unavailable' });
  });
}

/**
 * The dashboard prints a receipt from a hidden frame (DashboardPrint) or from a print page. With a printer chosen
 * in the app's settings, that receipt goes straight to the printer, as the branches' direct-print Chrome launcher
 * did; without one, the usual print window opens.
 */
function directPrintScript() {
  return `(() => {
    window.__goerpDirectPrint = ${!!printSettings().printer};
    if (window.__goerpPrintHook) return;
    window.__goerpPrintHook = true;
    const send = (doc) => window.postMessage({ goerp: 'print-receipt', html: '<!doctype html>\\n' + doc.documentElement.outerHTML, base: doc.baseURI }, '*');
    const install = (frame) => {
      let child;
      try { child = frame.contentWindow; } catch (_) { return; }
      if (!child || child.__goerpHooked) return;
      child.__goerpHooked = true;
      const original = child.print.bind(child);
      child.print = function () {
        if (!window.__goerpDirectPrint) return original();
        try { send(child.document); } catch (_) { return original(); }
        // The dashboard finishes its print job as after a closed print window.
        setTimeout(() => { try { child.dispatchEvent(new Event('afterprint')); window.dispatchEvent(new Event('afterprint')); } catch (_) {} }, 0);
      };
    };
    // Capture runs before the dashboard's own load handler, so the frame is ready before it prints.
    document.addEventListener('load', (event) => { if (event.target && event.target.tagName === 'IFRAME') install(event.target); }, true);
    const ownPrint = window.print.bind(window);
    window.print = function () {
      if (!window.__goerpDirectPrint) return ownPrint();
      send(document);
      setTimeout(() => window.dispatchEvent(new Event('afterprint')), 0);
    };
  })()`;
}

function installDirectPrint(contents) {
  if (contents && !contents.isDestroyed() && sameServer(contents.getURL())) contents.executeJavaScript(directPrintScript()).catch(() => {});
}

async function showDashboard(page) {
  if (!EMBED || !current || !mainWindow) return false;
  const user = sessionUser();
  let path = DASHBOARD_PAGES[page] || DASHBOARD_PAGES.home;
  if (offlineMode) {
    // Offline the dashboard opens from this account's saved pages; without any, the app's own screens are used.
    if (!offline.hasCopy(user)) return false;
    if (page === 'program' && !offline.hasPage(user, PROGRAM_PAGE)) return false;
    if (!page || page === 'home') path = offline.landing(user) || path;
  }
  const target = SERVER + path;
  dashboardTarget = page && page !== 'home' ? target : null;
  if (!dashboardView) {
    createDashboardView();
    await offline.attach(dashboardView.webContents);
    dashboardView.webContents.loadURL(target);
  } else if (page || dashboardView.webContents.getURL() === '') {
    dashboardView.webContents.loadURL(target);
  }
  if (!current || !mainWindow || mainWindow.isDestroyed() || !dashboardView) return false;
  if (!dashboardShown && !dashboardSignedIn) {
    // Shown once its first page is there: the app signs the dashboard in first, so neither an empty window nor the
    // dashboard's sign-in page shows in between. Never more than 15 seconds.
    notifyDashboard({ type: 'opening' });
    clearTimeout(dashboardWaiting);
    dashboardWaiting = setTimeout(attachDashboard, 15000);
    return true;
  }
  return attachDashboard();
}

let dashboardWaiting = null; // a dashboard window opening, not shown yet

function attachDashboard() {
  clearTimeout(dashboardWaiting);
  dashboardWaiting = null;
  if (!current || !mainWindow || mainWindow.isDestroyed() || !dashboardView) return false;
  if (!dashboardShown) {
    mainWindow.contentView.addChildView(dashboardView);
    dashboardShown = true;
  }
  layoutDashboard();
  dashboardView.webContents.focus();
  notifyDashboard({ type: 'shown' });
  return true;
}

function hideDashboard() {
  clearTimeout(dashboardWaiting);
  dashboardWaiting = null;
  if (dashboardView && dashboardShown && mainWindow && !mainWindow.isDestroyed()) mainWindow.contentView.removeChildView(dashboardView);
  dashboardShown = false;
  notifyDashboard({ type: 'hidden' });
}

/** Signs the dashboard in as the app's account; a sign-out chosen inside the dashboard signs out of the app too. */
async function onDashboardLoad(contents) {
  let url;
  try { url = new URL(contents.getURL()); } catch { return; }
  if (!sameServer(url.href)) return;
  // The app answered the sign-out itself (no internet) and is already signing out.
  if (/^\/admin\/(adminLogout|logout)\/?$/i.test(url.pathname)) return;
  if (url.pathname.replace(/\/+$/, '') === '/admin/login') {
    if (dashboardLoggingOut) {
      dashboardLoggingOut = false;
      console.log('[goerp] signed out of the app with the dashboard');
      notifyDashboard({ type: 'signed-out' });
      return;
    }
    if (offlineMode) {
      // No signing in without the internet: back to a saved page; the dashboard signs in again once online.
      const landing = offline.landing(sessionUser() || { id: 0, can_checkout: false });
      if (landing) contents.loadURL(SERVER + landing);
      return;
    }
    // First sign-in, or the dashboard session expired: sign in again, at most once a minute.
    if (!dashboardLogin || Date.now() - dashboardLastAutoLogin < 60000) return;
    dashboardLastAutoLogin = Date.now();
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
  dashboardSignedIn = true;
  notifyDashboard({ type: 'loaded' });
  warmUp(contents);
  if (dashboardTarget && !url.href.startsWith(dashboardTarget)) {
    const target = dashboardTarget;
    dashboardTarget = null;
    contents.loadURL(target);
    return;
  }
  dashboardTarget = null;
  if (dashboardWaiting && dashboardView && contents === dashboardView.webContents) attachDashboard();
}

async function resetDashboard() {
  clearTimeout(dashboardWaiting);
  dashboardWaiting = null;
  for (const popup of dashboardPopups) if (!popup.isDestroyed()) popup.destroy();
  dashboardPopups.clear();
  if (dashboardView) {
    if (dashboardShown && mainWindow && !mainWindow.isDestroyed()) mainWindow.contentView.removeChildView(dashboardView);
    if (!dashboardView.webContents.isDestroyed()) dashboardView.webContents.close();
  }
  dashboardView = null;
  dashboardShown = false;
  dashboardSignedIn = false;
  dashboardTarget = null;
  try { await session.fromPartition('persist:dashboard').clearStorageData(); } catch { /* nothing stored yet */ }
}

// The button on the "nothing saved for this account yet" page.
ipcMain.on('dashboard:device-screens', (event) => {
  if (!dashboardView || event.sender !== dashboardView.webContents) return;
  hideDashboard();
});

/**
 * Without the internet the dashboard pages run from this device, which has only the branches it was set up for: the
 * dashboard preload marks the other branches in the pages' branch lists as not set up here.
 */
function deviceBranches() {
  return { offline: offlineMode, branches: store.device() ? store.branches().map((branch) => branch.value) : [] };
}

function sendDeviceBranches() {
  const contents = dashboardView && dashboardView.webContents;
  if (contents && !contents.isDestroyed()) contents.send('goerp:branches', deviceBranches());
}

ipcMain.handle('dashboard:branches', (event) => {
  if (!dashboardView || event.sender !== dashboardView.webContents) return { offline: false, branches: [] };
  return deviceBranches();
});

/**
 * This device's part of the «برنامج الكمبيوتر» page, drawn there by the dashboard preload: only from that page in
 * the app's dashboard window, and only these actions.
 */
const DEVICE_ACTIONS = {
  state: () => {
    const session = requireSession();
    const status = sync.status();
    return { version: app.getVersion(), device: publicDevice(), branches: store.branches().map((branch) => ({ value: branch.value, name: branch.name })),
      user: { id: session.user.id, name: session.user.name }, sync: { online: status.online, last_sync_at: status.last_sync_at, pending: status.pending || 0,
        rejected: status.rejected || 0, last_error: status.last_error || '' }, rejected: store.rejectedOperations(), orders: store.openOrders(),
      print: printSettings(), autostart: autostart() };
  },
  sync: () => handlers['sync:now'](),
  'cancel-order': (values) => handlers['setup:cancel-order'](values),
  release: async (values) => {
    const result = await handlers['setup:release'](values);
    notifyDashboard({ type: 'device-reset' });
    return result;
  },
  printers: () => handlers['print:printers'](),
  'print-settings': (values) => { requireSession(); return handlers['print:settings'](values); },
  'print-test': () => handlers['print:test'](),
  autostart: (values) => handlers['app:autostart'](values),
};

ipcMain.handle('dashboard:device', async (event, request) => {
  const refused = { ok: false, error: 'طلب غير مسموح.' };
  if (!dashboardView || event.sender !== dashboardView.webContents || !event.senderFrame || event.senderFrame.parent) return refused;
  let url;
  try { url = new URL(event.senderFrame.url); } catch { return refused; }
  if (!sameServer(url.href) || url.pathname.replace(/\/+$/, '') !== PROGRAM_PAGE) return refused;
  const action = DEVICE_ACTIONS[String((request && request.action) || '')];
  if (!action) return { ok: false, error: 'طلب غير معروف.' };
  try {
    return { ok: true, value: await action((request && request.values) || {}) };
  } catch (error) {
    return { ok: false, error: error && error.message ? error.message : 'حدث خطأ غير متوقع.' };
  }
});

ipcMain.on('dashboard:print-receipt', async (event, values) => {
  const sender = event.sender;
  const fromDashboard = (dashboardView && sender === dashboardView.webContents)
    || [...dashboardPopups].some((popup) => !popup.isDestroyed() && popup.webContents === sender);
  if (!fromDashboard || !values || !sameServer(values.base) || typeof values.html !== 'string') return;
  const settings = printSettings();
  if (process.env.GOERP_DEBUG_SYNC) console.log('[goerp] print request, printer:', settings.printer || '(none)');
  if (!settings.printer) return;
  const html = inlineImages(values.html.replace(/<script\b[\s\S]*?<\/script\s*>/gi, ''), values.base);
  const result = await printing.print(BrowserWindow, html, settings, { partition: 'persist:dashboard', baseURL: values.base });
  if (!result.ok && result.error) notifyDashboard({ type: 'print-error', error: result.error });
});

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

/** F11 switches full screen on and off wherever the keyboard is (the dashboard or the app's own screens). */
function fullScreenKey(contents) {
  contents.on('before-input-event', (event, input) => {
    if (input.type !== 'keyDown' || input.key !== 'F11') return;
    event.preventDefault();
    if (input.isAutoRepeat || !mainWindow || mainWindow.isDestroyed()) return;
    mainWindow.setFullScreen(!mainWindow.isFullScreen());
  });
}

function createWindow() {
  mainWindow = new BrowserWindow({ width: 1366, height: 860, minWidth: 1024, minHeight: 680, title: 'GO ERP', autoHideMenuBar: true,
    backgroundColor: '#091320', show: false, fullscreen: true,
    webPreferences: { preload: path.join(__dirname, 'preload.cjs'), contextIsolation: true, sandbox: true, nodeIntegration: false } });
  // Opens full screen straight away; F11 leaves it (to the maximized window) and goes back to it.
  mainWindow.once('ready-to-show', () => mainWindow.show());
  mainWindow.on('leave-full-screen', () => { if (!mainWindow.isMaximized()) mainWindow.maximize(); });
  fullScreenKey(mainWindow.webContents);
  for (const event of ['resize', 'maximize', 'unmaximize', 'enter-full-screen', 'leave-full-screen']) mainWindow.on(event, layoutDashboard);
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
  const origin = new URL(SERVER).origin;
  pageCache = new PageCache(path.join(app.getPath('userData'), 'dashboard-copy.sqlite'), { now });
  const stockPage = new StockOffline({ store, cache: pageCache, origin, now, kick: () => sync.kick() });
  const dinePage = new ServiceOffline({ store, origin, now, kick: () => sync.kick(), channel: 'dine' });
  const deliveryPage = new DeliveryOffline({ store, origin, now, kick: () => sync.kick() });
  const shiftsPage = new ShiftsOffline({ store, cache: pageCache, origin, now, kick: () => sync.kick(), services: [dinePage, deliveryPage] });
  offline = new DashboardOffline({ origin, cache: pageCache, user: sessionUser,
    pages: [new TakeawayOffline({ store, origin, now, kick: () => sync.kick() }),
      new ExpensesOffline({ store, cache: pageCache, origin, now, kick: () => sync.kick() }),
      stockPage,
      new EmployeesOffline({ store, cache: pageCache, origin, now, kick: () => sync.kick(), recoverers: [(entry, user) => stockPage.recovered(entry, user)] }),
      dinePage, deliveryPage, shiftsPage],
    onNetworkFailure: (reason, url) => { setOfflineMode(true, `${reason} ${url}`); sync.run().catch(() => {}); },
    onSignOut: (now) => {
      if (!dashboardLoggingOut) console.log('[goerp] the dashboard\'s own sign-out');
      dashboardLoggingOut = true;
      if (!now) return; // online: once the sign-in page opens
      // Without the internet the sign-in page never opens: the app signs out right away.
      dashboardLoggingOut = false;
      console.log('[goerp] signed out of the app with the dashboard (without the internet)');
      notifyDashboard({ type: 'signed-out' });
    },
    onMissing: (url) => notifyDashboard({ type: 'missing-page', url }),
    onFromCopy: (contents) => fromCopy.add(contents),
    onLive: (contents) => fromCopy.delete(contents) });
  sync.on('status', (status) => {
    readOffset();
    // Only a finished sync says whether the dashboard answers (a starting one still carries the previous result).
    if (!status.running) {
      if (status.online === false) setOfflineMode(true, 'sync: ' + (status.last_error || ''));
      else if (status.online === true) setOfflineMode(false, 'sync');
      // Saved pages of accounts no longer on this device are removed.
      if (store.userCount()) {
        for (const scope of pageCache.scopes()) if (scope.startsWith('u:') && !store.user(Number(scope.slice(2)))) pageCache.forgetScope(scope);
      }
    }
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
