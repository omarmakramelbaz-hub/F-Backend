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
const PHP_ENV = { ...process.env, GOERP_E2E_DIR: WORK };
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let server = null;
let currentPage = null; // shown in the failure report
let appOutput = ''; // the app's own console output, also for the failure report

async function startServer() {
  server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(__dirname, 'router.php')], { stdio: 'ignore', env: PHP_ENV });
  await until(async () => {
    try { await fetch(SERVER + '/api/desktop-sync/v1/sync', { method: 'POST' }); return true; } catch { return false; }
  }, 'local dashboard starts', 15000);
}

async function stopServer() {
  if (server) server.kill('SIGKILL');
  server = null;
  await wait(300);
}

function serverRows(sql) {
  const db = new DatabaseSync(path.join(WORK, 'server.sqlite'), { readOnly: true });
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

async function launch(userData, executablePath = require(path.join(APP, 'node_modules', 'electron')), args = [APP]) {
  const app = await electron.launch({ executablePath, args: [...args, '--no-sandbox', '--disable-gpu'], cwd: APP,
    env: { ...process.env, GOERP_SERVER: SERVER, GOERP_USER_DATA: userData } });
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
  await page.waitForTimeout(200);
  await page.screenshot({ path: path.join(SHOTS, name + '.png') });
}

async function addItem(page, name, quantity) {
  if (quantity) await page.getByPlaceholder('مثال: 1.250 أو 3').fill(quantity);
  await page.locator('.product', { hasText: name }).click();
}

async function grandTotal(page, text) {
  await until(async () => (await page.locator('.totals .grand').textContent().catch(() => '')).includes(text), 'invoice total ' + text);
}

async function signIn(page, login, password) {
  await page.getByLabel('رقم الموبايل أو البريد').fill(login);
  await page.getByLabel('كلمة المرور').fill(password);
  await page.getByRole('button', { name: 'دخول' }).click();
  await page.locator('.pos').waitFor();
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
  await page.getByLabel('رقم الموبايل أو البريد').fill('01000000004');
  await page.getByLabel('كلمة المرور').fill('cashier-pass');
  await page.getByLabel('اسم الجهاز').fill('كاشير المعادي 1');
  await shot(page, '01-setup');
  await page.getByRole('button', { name: 'تجهيز الجهاز' }).click();
  await page.getByRole('heading', { name: 'تسجيل الدخول' }).waitFor({ timeout: 20000 });
  await shot(page, '02-login');

  // Online sale: 1.250 x 180 + 1 x 40 + 2 x 25 = 315.00.
  await signIn(page, '01000000004', 'cashier-pass');
  await page.evaluate(() => window.goerp.call('print:settings', { printer: '', kitchen_printer: '', width: 72, auto_print: false }));
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

  // The internet is back: the waiting sale is sent without being duplicated.
  await startServer();
  await page.locator('.sync-badge').first().click();
  await until(() => orderCount() === 2, 'offline sale reaches the dashboard', 40000);
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
  await shot(page, '07-owner-offline-delivery');
  await pay(page);

  // An open invoice is saved on the device and is back after the app restarts.
  await addItem(page, 'بلطي مقلي', '1');
  await page.locator('.open-tickets button', { hasText: 'تيك أواي' }).waitFor();
  await app.close();
  app = await launch(userData);
  page = await firstWindow(app);
  page.on('pageerror', (error) => problems.push(error.message));
  await page.getByRole('heading', { name: 'تسجيل الدخول' }).waitFor({ timeout: 20000 });
  await signIn(page, 'u1@example.test', 'root-pass');
  await page.locator('.line', { hasText: 'بلطي مقلي' }).waitFor();

  await startServer();
  await page.locator('.sync-badge').first().click();
  await until(() => orderCount() === 3, 'owner sale reaches the dashboard', 40000);
  assert.deepEqual({ ...serverRows('SELECT total_cents, actor_id FROM takeaway_orders ORDER BY id DESC LIMIT 1')[0] }, { total_cents: 25000, actor_id: 1 });
  assert.deepEqual(serverRows("SELECT status, COUNT(*) AS c FROM desktop_sync_operations GROUP BY status").map((row) => ({ ...row })), [{ status: 'applied', c: 3 }]);
  await app.close();
  assert.deepEqual(problems, []);
}

async function ownerDevice() {
  // The owner sets up another device for two branches and switches between their menus.
  const app = await launch(path.join(WORK, 'owner-device'), process.env.GOERP_E2E_EXECUTABLE || undefined, process.env.GOERP_E2E_EXECUTABLE ? [] : [APP]);
  const page = await firstWindow(app);
  await page.getByLabel('رقم الموبايل أو البريد').fill('u1@example.test');
  await page.getByLabel('كلمة المرور').fill('root-pass');
  await page.getByRole('button', { name: 'تجهيز الجهاز' }).click();
  await page.getByText('اختر فروع هذا الجهاز').waitFor({ timeout: 20000 });
  await page.getByLabel('فرع المعادي').check();
  await page.getByLabel('فرع مدينة نصر').check();
  await page.getByRole('button', { name: 'تأكيد الفروع' }).click();
  await page.getByRole('heading', { name: 'تسجيل الدخول' }).waitFor({ timeout: 20000 });
  await signIn(page, '01000000005', 'other-pass');
  assert.equal(await page.locator('.session select').count(), 0, 'A branch cashier only sees its own branch');
  await page.getByRole('button', { name: 'خروج' }).click();
  await signIn(page, '01000000001', 'root-pass');
  await page.locator('.session select').selectOption({ label: 'فرع مدينة نصر' });
  await page.locator('.product', { hasText: 'بوري مشوي' }).waitFor();
  assert.deepEqual(await page.locator('.product .name').allTextContents(), ['بوري مشوي']);
  await shot(page, '08-owner-two-branches');
  await app.close();
}

async function main() {
  fs.mkdirSync(SHOTS, { recursive: true });
  execFileSync('php', [path.join(__dirname, 'seed.php')], { env: PHP_ENV, stdio: 'inherit' });
  await startServer();
  await cashierDevice();
  if (!server) await startServer();
  await ownerDevice();
  await stopServer();
  console.log('GO ERP desktop end-to-end check passed. Screenshots: ' + SHOTS);
}

main().catch(async (error) => {
  console.error(error && error.stack || error);
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
