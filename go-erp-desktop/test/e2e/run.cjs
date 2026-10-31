'use strict';
// End-to-end check of the desktop app against a local copy of the dashboard API:
// first setup, sign-in, an online sale, a sale with the internet down, sync when it is back,
// another account signing in offline, an open invoice surviving a restart, and an owner device with two branches.
//   npm run build && xvfb-run -a node test/e2e/run.cjs     (needs PHP and the dashboard's vendor folder)
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn, execFileSync } = require('node:child_process');
const { DatabaseSync } = require('node:sqlite');
const { _electron: electron } = require('playwright-core');

const APP = path.resolve(__dirname, '..', '..');
const ROOT = path.resolve(APP, '..');
const WORK = process.env.GOERP_E2E_DIR || fs.mkdtempSync(path.join(os.tmpdir(), 'goerp-e2e-'));
const SHOTS = path.join(WORK, 'shots');
const PORT = Number(process.env.GOERP_E2E_PORT || 8099);
const SERVER = `http://127.0.0.1:${PORT}`;
// Several PHP workers, like a real server: the dashboard's pages poll while the app syncs.
const PHP_ENV = { ...process.env, GOERP_E2E_DIR: WORK, PHP_CLI_SERVER_WORKERS: '4' };
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let server = null;
let currentPage = null; // shown in the failure report
let appOutput = ''; // the app's own console output, also for the failure report

async function startServer() {
  const log = fs.openSync(path.join(WORK, 'php-server.log'), 'a');
  // Its own process group, so stopping it also stops its worker processes.
  server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(__dirname, 'router.php')],
    { stdio: ['ignore', log, log], env: PHP_ENV, detached: true });
  await until(async () => {
    try {
      const response = await fetch(SERVER + '/api/desktop-sync/v1/sync', { method: 'POST' });
      await response.arrayBuffer(); // read it fully so no half-read connection is left open
      return true;
    } catch { return false; }
  }, 'local dashboard starts', 15000);
}

/** The internet goes down: the server and all its workers stop, and nothing answers any more. */
async function stopServer() {
  if (server) {
    try { process.kill(-server.pid, 'SIGKILL'); } catch { server.kill('SIGKILL'); }
  }
  server = null;
  await until(async () => {
    try {
      const response = await fetch(SERVER + '/api/desktop-sync/v1/sync', { method: 'POST', signal: AbortSignal.timeout(1000) });
      await response.arrayBuffer();
      return false;
    } catch { return true; }
  }, 'local dashboard stops', 15000);
}

function serverRows(sql) {
  // The local dashboard may be writing at the same moment; wait for it instead of failing.
  const db = new DatabaseSync(path.join(WORK, 'server.sqlite'), { timeout: 10000 });
  try { return db.prepare(sql).all(); } finally { db.close(); }
}

const orderCount = () => serverRows('SELECT COUNT(*) AS c FROM takeaway_orders')[0].c;

async function until(check, label, timeout = 20000) {
  const start = Date.now();
  for (;;) {
    const value = await check();
    if (value) return value;
    if (Date.now() - start > timeout) throw new Error('Timed out: ' + label);
    await wait(250);
  }
}

// Resolving the path downloads Electron on first use; done once, before any server starts.
const ELECTRON = require(path.join(APP, 'node_modules', 'electron'));

// embed: the live dashboard opens inside the window after signing in; off: the device's own screens.
async function launch(userData, executablePath = ELECTRON, args = [APP], embed = false) {
  const app = await electron.launch({ executablePath, args: [...args, '--no-sandbox', '--disable-gpu'], cwd: APP,
    env: { ...process.env, GOERP_SERVER: SERVER, GOERP_USER_DATA: userData, GOERP_DASHBOARD_VIEW: embed ? 'on' : 'off', GOERP_DEBUG_SYNC: '1' } });
  const keep = (chunk) => { appOutput = (appOutput + chunk).slice(-3000); };
  app.process().stdout.on('data', keep);
  app.process().stderr.on('data', keep);
  return app;
}

async function firstWindow(app) {
  currentPage = await app.firstWindow();
  return currentPage;
}

async function shot(page, name) {
  await page.waitForTimeout(1700); // the GO ERP logo settles after its opening animation
  await page.screenshot({ path: path.join(SHOTS, name + '.png') });
}

/** The dashboard window itself (it lies over the app's window, which page.screenshot does not include). */
async function dashboardShot(app, name) {
  await wait(1200);
  const png = await app.evaluate(async ({ webContents }) => {
    const dashboard = webContents.getAllWebContents().find((contents) => /^https?:\/\/[^/]+\/admin\//.test(contents.getURL()));
    return dashboard ? (await dashboard.capturePage()).toPNG().toString('base64') : null;
  });
  if (png) fs.writeFileSync(path.join(SHOTS, name + '.png'), Buffer.from(png, 'base64'));
}

async function addItem(page, name, quantity) {
  if (quantity) await page.getByPlaceholder('مثال: 1.250 أو 3').fill(quantity);
  await page.locator('.product', { hasText: name }).click();
}

async function grandTotal(page, text) {
  await until(async () => (await page.locator('.totals .grand').textContent().catch(() => '')).includes(text), 'invoice total ' + text);
}

async function dashboardUrls(app) {
  return app.evaluate(({ webContents }) => webContents.getAllWebContents().map((contents) => contents.getURL()));
}

/** Runs a script inside the dashboard window (the view under the app's status bar). */
async function inDashboard(app, script) {
  return app.evaluate(({ webContents }, code) => {
    const dashboard = webContents.getAllWebContents().find((contents) => /^https?:\/\/[^/]+\/admin\//.test(contents.getURL()));
    return dashboard ? dashboard.executeJavaScript(code) : null;
  }, script);
}

function deviceRows(userData, file, sql) {
  const db = new DatabaseSync(path.join(userData, file), { readOnly: true, timeout: 10000 });
  try { return db.prepare(sql).all(); } finally { db.close(); }
}

async function signIn(page, login, password, landing = '.pos') {
  await page.getByLabel('البريد الإلكتروني أو رقم الموبايل').fill(login);
  await page.getByLabel('كلمة المرور', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'تسجيل دخول' }).click();
  await page.locator(landing).waitFor();
}

async function pay(page) {
  await page.getByRole('button', { name: 'إنهاء البيع وطباعة الفاتورة' }).click();
  return page.locator('.notice', { hasText: 'تم حفظ الفاتورة' }).textContent();
}

async function cashierDevice() {
  const userData = path.join(WORK, 'cashier-device');
  let app = await launch(userData);
  let page = await firstWindow(app);
  const problems = [];
  page.on('pageerror', (error) => problems.push(error.message));

  // First run: set up once with the branch cashier's dashboard account.
  await page.getByLabel('البريد الإلكتروني أو رقم الموبايل').fill('01000000004');
  await page.getByLabel('كلمة المرور', { exact: true }).fill('cashier-pass');
  await page.getByLabel('اسم الجهاز').fill('كاشير المعادي 1');
  await shot(page, '01-setup');
  await page.getByRole('button', { name: 'تجهيز الجهاز' }).click();
  await page.getByRole('heading', { name: 'الدخول إلى لوحة التحكم' }).waitFor({ timeout: 20000 });
  await shot(page, '02-login');

  // Online sale: 1.250 x 180 + 1 x 40 + 2 x 25 = 315.00.
  await signIn(page, '01000000004', 'cashier-pass');
  assert.equal(await page.getByRole('button', { name: 'التقارير' }).count(), 0, 'Reports are for the owner and managers');
  // A printer that does not exist: prints fail quickly instead of opening the system print dialog.
  await page.evaluate(() => window.goerp.call('print:settings', { printer: 'e2e-no-printer', kitchen_printer: '', width: 72, auto_print: false }));
  await addItem(page, 'بوري مشوي', '1.250');
  await addItem(page, 'أرز صيادية');
  await addItem(page, 'سلطة طحينة', '2');
  await page.getByLabel('هاتف العميل').fill('01012345678');
  await page.getByLabel('اسم العميل').fill('أحمد علي');
  await grandTotal(page, '315.00');
  await page.getByLabel('المبلغ المستلم').fill('400');
  await shot(page, '03-cashier');
  assert.match(await pay(page), /الباقي 85\.00/);
  await until(() => orderCount() === 1, 'online sale reaches the dashboard');
  const first = serverRows('SELECT total_cents, actor_id FROM takeaway_orders')[0];
  assert.deepEqual({ ...first }, { total_cents: 31500, actor_id: 4 });
  await page.getByRole('button', { name: 'فواتير اليوم' }).click();
  await page.locator('.pill.ok').first().waitFor({ timeout: 15000 });
  await shot(page, '04-receipts');

  // The internet goes down: a dine-in sale is kept on the device. 0.5 x 650 + 2 x 95.50 = 516.00.
  await stopServer();
  await page.locator('.sync-badge').first().click();
  await page.locator('.sync-badge.offline').first().waitFor({ timeout: 30000 });
  await page.getByRole('button', { name: 'الكاشير' }).click();
  await page.getByRole('button', { name: 'صالة' }).click();
  await addItem(page, 'جمبري جامبو', '0.5');
  await addItem(page, 'شوربة سي فود', '2');
  await page.getByLabel('هاتف العميل').fill('٠١١٢٢٣٣٤٤٥٥');
  await page.getByLabel('رقم الطاولة').fill('5');
  await grandTotal(page, '516.00');
  await pay(page);
  await page.getByRole('button', { name: 'فواتير اليوم' }).click();
  await page.locator('.pill.warn').first().waitFor();
  await shot(page, '05-offline-receipts');
  assert.equal(orderCount(), 1);

  // Still offline: the cashier records an expense paid from the drawer. A cashier cannot move cash.
  await page.getByRole('button', { name: 'الخزنة والمصروفات' }).click();
  await page.locator('.cash').waitFor();
  assert.equal(await page.locator('.forms form').count(), 1, 'A cashier only records expenses');
  const cashierExpense = page.locator('form', { hasText: 'تسجيل مصروف من الدرج' });
  await cashierExpense.locator('select').selectOption('electricity');
  await cashierExpense.getByLabel('المبلغ').fill('120.50');
  await cashierExpense.getByLabel('الوصف').fill('فاتورة كهرباء');
  await cashierExpense.getByRole('button', { name: 'حفظ المصروف' }).click();
  await page.locator('.notice', { hasText: 'تم حفظ المصروف' }).waitFor();
  await page.locator('.list .pill.warn').first().waitFor();

  // Still offline: an employee checks in; the device's time is what the dashboard will record.
  await page.getByRole('button', { name: 'الحضور' }).click();
  await page.locator('.attendance tr', { hasText: 'سعيد' }).waitFor();
  page.once('dialog', (dialog) => dialog.accept());
  const punchedAt = Date.now();
  await page.locator('.attendance tr', { hasText: 'سعيد' }).getByRole('button', { name: 'حضور صباحي' }).click();
  await page.locator('.notice', { hasText: 'تم تسجيل حضور سعيد' }).waitFor();
  await page.locator('.attendance tr', { hasText: 'سعيد' }).getByRole('button', { name: 'انصراف' }).waitFor();
  await shot(page, '05b-attendance-offline');

  // The internet is back: the waiting sale is sent without being duplicated.
  await startServer();
  await page.locator('.sync-badge').first().click();
  await until(() => orderCount() === 2, 'offline sale reaches the dashboard', 40000);
  await until(() => serverRows('SELECT COUNT(*) AS c FROM branch_expenses')[0].c === 1, 'offline expense reaches the dashboard', 40000);
  await until(() => serverRows('SELECT COUNT(*) AS c FROM branch_employee_days')[0].c === 1, 'offline punch reaches the dashboard', 40000);
  const punch = serverRows('SELECT status, checked_in_at FROM branch_employee_days')[0];
  assert.equal(punch.status, 'morning');
  const recorded = Date.parse(punch.checked_in_at.replace(' ', 'T') + 'Z');
  assert.ok(Math.abs(recorded - punchedAt) < 60000, 'The dashboard keeps the time the device recorded, not the time it synced');
  await page.getByRole('button', { name: 'فواتير اليوم' }).click();
  await page.getByRole('button', { name: 'تحديث' }).click();
  await until(async () => (await page.locator('.pill.warn').count()) === 0, 'all receipts synced');
  assert.deepEqual(serverRows('SELECT total_cents FROM takeaway_orders ORDER BY id').map((row) => row.total_cents), [31500, 51600]);
  await page.getByRole('button', { name: 'الإعدادات والمزامنة' }).click();
  await page.locator('.settings').waitFor();
  await shot(page, '06-settings');

  // Another account signs in on the same device with no internet and no new setup.
  // The owner's delivery order: 240 - 10 discount + 20 delivery = 250.00.
  await page.getByRole('button', { name: 'خروج' }).click();
  await stopServer();
  await signIn(page, '01000000001', 'root-pass');
  await page.getByRole('button', { name: 'دليفري' }).click();
  await addItem(page, 'كابوريا', '1');
  await page.getByLabel('هاتف العميل').fill('01099887766');
  await page.getByLabel('اسم العميل').fill('منى');
  await page.getByLabel('العنوان').fill('١٢ شارع النصر - المعادي');
  await page.getByLabel('رسوم التوصيل').fill('20');
  await page.getByLabel('خصم', { exact: true }).fill('10');
  await page.getByLabel('سبب الخصم').fill('عميل دائم');
  await grandTotal(page, '250.00');
  // The order leaves with the delivery company's driver and is paid when the driver returns.
  await page.getByLabel('شركة التوصيل').selectOption({ label: 'شركة سريع' });
  await page.getByLabel('اسم المندوب').fill('حسن');
  await page.getByRole('button', { name: 'خرج مع المندوب' }).click();
  await page.locator('.notice', { hasText: 'خرج مع المندوب' }).waitFor();
  await page.locator('.open-tickets button', { hasText: 'مع المندوب' }).waitFor();
  await shot(page, '07-owner-offline-delivery');
  await pay(page);

  // The owner, still offline, approves an expense on the spot and moves cash in and out of the drawer.
  await page.getByRole('button', { name: 'الخزنة والمصروفات' }).click();
  const ownerExpense = page.locator('form', { hasText: 'تسجيل مصروف من الدرج' });
  await ownerExpense.locator('select').selectOption('packaging');
  await ownerExpense.getByLabel('المبلغ').fill('75');
  await ownerExpense.getByLabel('اعتماد المصروف الآن').check();
  await ownerExpense.getByRole('button', { name: 'حفظ المصروف' }).click();
  await page.locator('.notice', { hasText: 'تم حفظ المصروف' }).waitFor();
  const drawer = page.locator('form', { hasText: 'إيداع أو سحب من الخزنة' });
  await drawer.getByLabel('المبلغ').fill('500');
  await drawer.getByLabel('السبب').fill('فكة');
  await drawer.getByRole('button', { name: 'تسجيل الإيداع' }).click();
  await page.locator('.notice', { hasText: 'تم تسجيل الإيداع' }).waitFor();
  await drawer.getByRole('button', { name: 'سحب', exact: true }).click();
  await drawer.getByLabel('المبلغ').fill('100');
  await drawer.getByLabel('السبب').fill('توريد للمالك');
  await drawer.getByRole('button', { name: 'تسجيل السحب' }).click();
  await page.locator('.notice', { hasText: 'تم تسجيل السحب' }).waitFor();
  await until(async () => (await page.locator('.list tbody tr').count()) === 4, 'four drawer entries listed');
  await shot(page, '08-cash-offline');

  // The owner's report of this device works offline: 315 + 516 + 250 = 1,081.00 from three invoices.
  await page.getByRole('button', { name: 'التقارير' }).click();
  await page.locator('.reports .stat.total', { hasText: '1,081.00' }).waitFor();
  assert.equal(await page.locator('.reports .stat').first().locator('b').textContent(), '3');
  await shot(page, '09-reports-offline');
  await page.getByRole('button', { name: 'الكاشير' }).click();

  // A returning customer's name and address fill in from the phone number, and the open
  // invoice is saved on the device and is back after the app restarts.
  await page.getByRole('button', { name: 'دليفري' }).click();
  await addItem(page, 'بلطي مقلي', '1');
  await page.getByLabel('هاتف العميل').fill('01099887766');
  await page.getByLabel('هاتف العميل').press('Tab');
  await page.locator('.known', { hasText: 'عميل سابق' }).waitFor();
  assert.equal(await page.getByLabel('اسم العميل').inputValue(), 'منى');
  assert.equal(await page.getByLabel('العنوان').inputValue(), '١٢ شارع النصر - المعادي');
  await page.locator('.open-tickets button', { hasText: 'منى' }).waitFor();
  await app.close();
  app = await launch(userData);
  page = await firstWindow(app);
  page.on('pageerror', (error) => problems.push(error.message));
  await page.getByRole('heading', { name: 'الدخول إلى لوحة التحكم' }).waitFor({ timeout: 20000 });
  await signIn(page, 'u1@example.test', 'root-pass');
  await page.locator('.line', { hasText: 'بلطي مقلي' }).waitFor();

  await startServer();
  await page.locator('.sync-badge').first().click();
  await until(() => serverRows("SELECT COUNT(*) AS c FROM desktop_sync_operations WHERE status = 'applied'")[0].c === 8, 'every operation reaches the dashboard', 40000);
  assert.deepEqual({ ...serverRows('SELECT total_cents, actor_id FROM takeaway_orders ORDER BY id DESC LIMIT 1')[0] }, { total_cents: 25000, actor_id: 1 });
  const deliveryContext = JSON.parse(serverRows('SELECT context_snapshot FROM takeaway_orders ORDER BY id DESC LIMIT 1')[0].context_snapshot);
  assert.equal(deliveryContext.delivery_company.name, 'شركة سريع');
  assert.equal(deliveryContext.driver, 'حسن');
  assert.deepEqual(serverRows("SELECT status, COUNT(*) AS c FROM desktop_sync_operations GROUP BY status").map((row) => ({ ...row })), [{ status: 'applied', c: 8 }]);
  assert.deepEqual(serverRows('SELECT category, amount_cents, status, actor_id FROM branch_expenses ORDER BY id').map((row) => ({ ...row })),
    [{ category: 'electricity', amount_cents: 12050, status: 'pending', actor_id: 4 }, { category: 'packaging', amount_cents: 7500, status: 'approved', actor_id: 1 }]);
  assert.deepEqual(serverRows("SELECT kind, amount_cents FROM takeaway_till_entries WHERE kind IN ('expense', 'cash_in', 'cash_out') ORDER BY id")
    .map((row) => ({ ...row })), [{ kind: 'expense', amount_cents: -7500 }, { kind: 'cash_in', amount_cents: 50000 }, { kind: 'cash_out', amount_cents: -10000 }]);
  await app.close();
  assert.deepEqual(problems, []);
}

async function ownerDevice() {
  // The owner sets up another device for two branches. On this device the live dashboard opens inside the window.
  const app = await launch(path.join(WORK, 'owner-device'), process.env.GOERP_E2E_EXECUTABLE || undefined,
    process.env.GOERP_E2E_EXECUTABLE ? [] : [APP], true);
  const page = await firstWindow(app);
  await page.getByLabel('البريد الإلكتروني أو رقم الموبايل').fill('u1@example.test');
  await page.getByLabel('كلمة المرور', { exact: true }).fill('root-pass');
  await page.getByRole('button', { name: 'تجهيز الجهاز' }).click();
  await page.getByText('اختر فروع هذا الجهاز').waitFor({ timeout: 20000 });
  await page.getByLabel('فرع المعادي').check();
  await page.getByLabel('فرع مدينة نصر').check();
  await page.getByRole('button', { name: 'تأكيد الفروع' }).click();
  await page.getByRole('heading', { name: 'الدخول إلى لوحة التحكم' }).waitFor({ timeout: 20000 });
  await shot(page, '10-login-like-the-dashboard');

  // A branch cashier signs in: the dashboard opens in the window, signed in as the same account.
  await signIn(page, '01000000005', 'other-pass', '.status-bar');
  try {
    await until(async () => (await dashboardUrls(app)).some((url) => /\/admin\/dashboard/.test(url)), 'dashboard opens signed in');
  } catch (error) {
    console.log('DEBUG urls', JSON.stringify(await dashboardUrls(app)));
    console.log('DEBUG app', appOutput.split('\n').filter((line) => line.includes('[goerp]')).join(' | '));
    console.log('DEBUG state', JSON.stringify(await app.evaluate(({ webContents }) => webContents.getAllWebContents().map((c) => [c.getURL(), c.isLoading()]))));
    console.log('DEBUG page', String(await inDashboard(app, "JSON.stringify({ ready: document.readyState, email: (document.querySelector('[name=email]') || {}).value, resources: performance.getEntriesByType('resource').map((r) => r.name.slice(0, 90) + ' ' + Math.round(r.duration)).slice(0, 40) })").catch((e) => 'ERR ' + e.message)));
    throw error;
  }
  // The device's own screens are one click away; a branch cashier only sees its own branch there.
  await page.getByRole('button', { name: 'شاشات الجهاز' }).click();
  await page.locator('.pos').waitFor();
  assert.equal(await page.locator('.side-branch').count(), 0, 'A branch cashier only sees its own branch');
  await shot(page, '11-device-screens');
  // Signing out of the app closes the dashboard and its session.
  await page.getByRole('button', { name: 'خروج' }).click();
  await until(async () => !(await dashboardUrls(app)).some((url) => url.includes('/admin/')), 'dashboard closes with the app sign-out');

  // The owner: the dashboard's own sign-out link signs out of the app as well.
  await signIn(page, '01000000001', 'root-pass', '.status-bar');
  await until(async () => (await dashboardUrls(app)).some((url) => /\/admin\/dashboard/.test(url)), 'dashboard opens for the owner');
  await app.evaluate(({ webContents }) => {
    const dashboard = webContents.getAllWebContents().find((contents) => contents.getURL().includes('/admin/dashboard'));
    return dashboard.executeJavaScript("location.href = '/admin/adminLogout'");
  });
  await page.getByRole('heading', { name: 'الدخول إلى لوحة التحكم' }).waitFor({ timeout: 20000 });

  // Back in on the device's screens: the owner switches between the two branches' menus.
  await signIn(page, '01000000001', 'root-pass', '.status-bar');
  // A receipt the dashboard prints goes straight to the printer chosen in the app (here a missing one).
  await until(async () => (await dashboardUrls(app)).some((url) => /\/admin\/dashboard/.test(url)), 'dashboard opens again');
  await page.evaluate(() => window.goerp.call('print:settings', { printer: 'e2e-no-printer', kitchen_printer: '', width: 72, auto_print: true }));
  await app.evaluate(({ webContents }) => {
    const dashboard = webContents.getAllWebContents().find((contents) => contents.getURL().includes('/admin/dashboard'));
    return dashboard.executeJavaScript(`new Promise((resolve) => {
      const frame = document.createElement('iframe');
      frame.setAttribute('sandbox', 'allow-same-origin allow-modals');
      frame.addEventListener('load', () => { frame.contentWindow.print(); resolve(true); });
      frame.srcdoc = '<!doctype html><html data-dashboard-receipt="1"><body><h1>فاتورة تجريبية</h1></body></html>';
      document.body.appendChild(frame);
    })`);
  });
  await page.locator('.status-error', { hasText: 'الطابعة المختارة مش موجودة' }).waitFor({ timeout: 20000 });
  await page.getByRole('button', { name: 'شاشات الجهاز' }).click();
  await page.locator('.side-branch').selectOption({ label: 'فرع مدينة نصر' });
  await page.locator('.product', { hasText: 'بوري مشوي' }).waitFor();
  assert.deepEqual(await page.locator('.product .name').allTextContents(), ['بوري مشوي']);
  await shot(page, '12-owner-two-branches');
  // And back to the dashboard from the side menu.
  await page.getByRole('button', { name: 'الداشبورد' }).click();
  await page.locator('.status-bar').waitFor();
  await app.close();
}

/**
 * The dashboard's own cashier page with the internet down: it opens from the device's copy, sells from the device's
 * menu, prints the dashboard's receipt, and the sale reaches the dashboard once the internet is back.
 */
async function dashboardOffline() {
  const userData = path.join(WORK, 'owner-device');
  const app = await launch(userData, process.env.GOERP_E2E_EXECUTABLE || undefined, process.env.GOERP_E2E_EXECUTABLE ? [] : [APP], true);
  const page = await firstWindow(app);
  await page.getByRole('heading', { name: 'الدخول إلى لوحة التحكم' }).waitFor({ timeout: 20000 });
  await signIn(page, '01000000004', 'cashier-pass', '.status-bar');
  await until(async () => (await dashboardUrls(app)).some((url) => /\/admin\/dashboard/.test(url)), 'dashboard opens for the cashier');
  await page.evaluate(() => window.goerp.call('print:settings', { printer: 'e2e-no-printer', kitchen_printer: '', width: 72, auto_print: true }));
  // The account's daily pages are saved on the device in the background.
  const saved = (pagePath) => deviceRows(userData, 'dashboard-copy.sqlite',
    `SELECT COUNT(*) AS c FROM entries WHERE scope = 'u:4' AND kind = 'page' AND path = '${pagePath}'`)[0].c === 1;
  await until(() => saved('/admin/takeaway'), 'the cashier page is saved for offline use', 40000);
  await until(() => saved('/admin/branch-expenses') && deviceRows(userData, 'dashboard-copy.sqlite',
    "SELECT COUNT(*) AS c FROM entries WHERE kind = 'file' AND url LIKE '%/dashboard/js/branch-expenses.js%'")[0].c === 1,
  'the expenses page and its script are saved for offline use', 40000);
  // Online, the cashier page works from the dashboard as usual.
  await app.evaluate(({ webContents }) => {
    global.__dashboardConsole = [];
    for (const contents of webContents.getAllWebContents()) contents.on('console-message', (event) => {
      const message = event.message || '';
      if (/error|fail|uncaught/i.test(message) || event.level === 'error' || event.level === 3) {
        global.__dashboardConsole.push(`${String(event.sourceId || '').split('/').pop()}:${event.lineNumber || ''} ${String(message).slice(0, 300)}`);
      }
    });
  });
  await inDashboard(app, "location.href = '/admin/takeaway'");
  const products = () => inDashboard(app, "document.querySelectorAll('[data-pos-product]').length").catch(() => 0);
  try {
    await until(async () => (await products()) >= 3, 'the cashier page shows the branch menu online', 30000);
  } catch (error) {
    console.log('DEBUG url', JSON.stringify(await dashboardUrls(app)));
    console.log('DEBUG pos', String(await inDashboard(app, "JSON.stringify({ pos: !!document.querySelector('#takeaway-pos'), message: (document.querySelector('[data-pos-catalog-message]') || {}).textContent, notice: (document.querySelector('[data-pos-message]') || {}).textContent, branch: (document.querySelector('[data-pos-branch]') || {}).value, cards: document.querySelectorAll('.tp-product').length, scripts: Array.from(document.scripts).map((s) => s.src).filter(Boolean).slice(-6) })").catch((e) => 'ERR ' + e.message)));
    console.log('DEBUG console', JSON.stringify(await app.evaluate(() => global.__dashboardConsole || [])));
    console.log('DEBUG catalog', String(await inDashboard(app, "fetch('/admin/takeaway/catalog?branch=f:100&page=1', { headers: { Accept: 'application/json' } }).then(async (r) => r.status + ' ' + (await r.text()).slice(0, 800))").catch((e) => 'ERR ' + e.message)));
    throw error;
  }

  // The internet goes down.
  await stopServer();
  await page.locator('.sync-badge').first().click();
  await page.locator('.status-offline').waitFor({ timeout: 40000 });
  await inDashboard(app, 'location.reload()');
  await until(async () => (await products()) >= 3, 'the cashier page opens from the device without the internet', 30000);
  await shot(page, '13-dashboard-cashier-offline-status');
  await dashboardShot(app, '13-dashboard-cashier-offline');
  // 1 kg بوري مشوي (180) + 1 سلطة طحينة (25) = 205.00
  const step = (code) => inDashboard(app, `(() => { const root = document.querySelector('#takeaway-pos'); ${code} })()`);
  await step(`const card = (name) => Array.from(root.querySelectorAll('[data-pos-product]')).find((button) => button.textContent.includes(name));
    card('بوري مشوي').click(); card('سلطة طحينة').click();`);
  await step(`const phone = root.querySelector('[data-pos-customer-phone]'); phone.value = '01012345678';
    phone.dispatchEvent(new Event('input', { bubbles: true })); phone.dispatchEvent(new Event('change', { bubbles: true }));
    const consent = root.querySelector('[data-pos-customer-consent]'); if (!consent.checked) consent.click();`);
  await until(async () => (await step("return root.querySelector('[data-pos-total=\"total\"]').textContent")) === '205.00', 'the device prices the invoice');
  await step(`const cash = root.querySelector('[data-pos-cash-received]'); cash.value = '300';
    cash.dispatchEvent(new Event('input', { bubbles: true })); root.querySelector('[data-pos-finish]').click();`);
  await until(async () => (await step("const saved = root.querySelector('[data-pos-saved]'); return !saved.hidden && saved.textContent")) || false,
    'the sale is saved on the device', 30000);
  // The dashboard prints its receipt straight to the app's printer (a missing one here).
  try {
    await page.locator('.status-error', { hasText: 'الطابعة المختارة مش موجودة' }).waitFor({ timeout: 20000 });
  } catch (error) {
    console.log('DEBUG print', String(await step("return JSON.stringify({ message: root.querySelector('[data-pos-message] span').textContent, saved: root.querySelector('[data-pos-saved]').textContent, frames: document.querySelectorAll('iframe').length, print: typeof window.DashboardPrint, flag: window.__goerpDirectPrint, hook: window.__goerpPrintHook })")));
    console.log('DEBUG console', JSON.stringify(await app.evaluate(() => global.__dashboardConsole || [])));
    console.log('DEBUG app', appOutput.split('\n').filter((line) => line.includes('[goerp]')).join(' | '));
    throw error;
  }
  const sale = deviceRows(userData, 'goerp.sqlite', "SELECT id, channel, status, json_extract(totals, '$.total_cents') AS total FROM tickets WHERE json_extract(data, '$.source') = 'dashboard'");
  assert.deepEqual(sale.map((row) => [row.channel, row.status, row.total]), [['takeaway', 'paid', 20500]]);
  await shot(page, '14-dashboard-sale-offline-status');
  await dashboardShot(app, '14-dashboard-sale-offline');
  // The expenses page offline: an expense saved from the dashboard's own page goes to the device's journal.
  await inDashboard(app, "location.href = '/admin/branch-expenses'");
  // Ready once the page's own script drew its list (the page counter is filled).
  try {
    await until(async () => await inDashboard(app, "!!(document.querySelector('[data-expense-page]') && document.querySelector('[data-expense-page]').textContent.trim())").catch(() => false),
      'the expenses page opens offline');
  } catch (error) {
    console.log('DEBUG expenses page', String(await inDashboard(app, `JSON.stringify({ url: location.href, ready: document.readyState, form: !!document.querySelector('[data-expense-form]'),
      counter: (document.querySelector('[data-expense-page]') || {}).textContent, notice: (document.querySelector('[data-expense-message] span') || {}).textContent,
      scripts: Array.from(document.scripts).map((s) => s.src).filter((src) => /branch-expenses|dashboard-spa/.test(src)) })`).catch((e) => 'ERR ' + e.message)));
    console.log('DEBUG console', JSON.stringify((await app.evaluate(() => global.__dashboardConsole || [])).filter((line) => !/CKEDITOR|toastr|rateYo|firebase|jstree|ServiceWorker/.test(line)).slice(-15)));
    console.log('DEBUG boot', String(await inDashboard(app, `(() => { try { const boot = JSON.parse(document.getElementById('branch-expenses-bootstrap').textContent);
      return JSON.stringify({ keys: Object.keys(boot), initial: Object.keys(boot.initial || {}), pagination: boot.initial && boot.initial.pagination, items: boot.initial && (boot.initial.items || []).length }); } catch (e) { return 'ERR ' + e.message; } })()`).catch((e) => 'ERR ' + e.message)));
    throw error;
  }
  await inDashboard(app, `(() => { const form = document.querySelector('[data-expense-form]');
    form.elements.category.value = 'electricity'; form.elements.category.dispatchEvent(new Event('change', { bubbles: true }));
    form.elements.amount.value = '88.25'; form.elements.description.value = 'كهرباء من غير نت';
    form.querySelector('[data-expense-save]').click(); })()`);
  try {
    await until(async () => /جهاز-/.test(await inDashboard(app, "document.querySelector('[data-expense-rows]').innerText").catch(() => '')),
      'the offline expense is listed on the expenses page', 20000);
  } catch (error) {
    console.log('DEBUG expense', String(await inDashboard(app, `JSON.stringify({ notice: (document.querySelector('[data-expense-message] span') || {}).textContent,
      rows: document.querySelector('[data-expense-rows]').innerText.slice(0, 300), values: Object.fromEntries(Array.from(document.querySelector('[data-expense-form]').elements).filter((f) => f.name).map((f) => [f.name, f.value + (f.disabled ? ' (disabled)' : '')])),
      options: Array.from(document.querySelector('[data-expense-form]').elements.category.options).map((o) => o.value).slice(0, 12) })`).catch((e) => 'ERR ' + e.message)));
    throw error;
  }
  await dashboardShot(app, '16-dashboard-expense-offline');
  // A page this account never saved explains that it needs the internet, inside the dashboard.
  await inDashboard(app, "window.DashboardSPA && window.DashboardSPA.visit('/admin/wallets/transactions')");
  await until(async () => /الصفحة دي محتاجة إنترنت/.test(await inDashboard(app, 'document.body.innerText').catch(() => '')), 'unsaved page message');
  await shot(page, '15-dashboard-unsaved-page-offline-status');
  await dashboardShot(app, '15-dashboard-unsaved-page-offline');

  // The internet is back: the sale reaches the dashboard under the page's own operation number.
  const before = orderCount();
  await startServer();
  await page.locator('.sync-badge').first().click();
  await until(() => serverRows(`SELECT COUNT(*) AS c FROM takeaway_orders WHERE request_key = '${sale[0].id}'`)[0].c === 1, 'the offline sale reaches the dashboard', 40000);
  assert.equal(orderCount(), before + 1);
  assert.equal(serverRows(`SELECT total_cents FROM takeaway_orders WHERE request_key = '${sale[0].id}'`)[0].total_cents, 20500);
  await until(() => serverRows("SELECT COUNT(*) AS c FROM branch_expenses WHERE amount_cents = 8825 AND category = 'electricity'")[0].c === 1,
    'the offline expense reaches the dashboard', 40000);
  await page.locator('.status-offline').waitFor({ state: 'detached', timeout: 40000 });
  await app.close();
}

async function main() {
  fs.mkdirSync(SHOTS, { recursive: true });
  execFileSync('php', [path.join(__dirname, 'seed.php')], { env: PHP_ENV, stdio: 'inherit' });
  await startServer();
  await cashierDevice();
  if (!server) await startServer();
  await ownerDevice();
  if (!server) await startServer();
  await dashboardOffline();
  await stopServer();
  console.log('GO ERP desktop end-to-end check passed. Screenshots: ' + SHOTS);
}

main().catch(async (error) => {
  console.error(error && error.stack || error);
  console.error('App log: ' + appOutput.split('\n').filter((line) => line.includes('[goerp]')).join(' | '));
  let screen = '';
  try { if (currentPage) screen = (await currentPage.locator('body').innerText({ timeout: 2000 })).slice(0, 1500); } catch { /* window closed */ }
  if (process.env.GITHUB_ACTIONS) {
    // CI logs are not always reachable; an annotation is readable from the run's API.
    const text = `${error && error.message || error}\n--- screen ---\n${screen}\n--- app output ---\n${appOutput.slice(-1200)}`;
    console.log('::error title=GO ERP desktop end-to-end check::' + text.replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A'));
  }
  await stopServer();
  process.exit(1);
});
