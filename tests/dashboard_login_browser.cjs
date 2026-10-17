/* NODE_PATH=$CODEX_PRIMARY_RUNTIME_NODE_MODULES node tests/dashboard_login_browser.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const source = fs.readFileSync(path.join(__dirname, '../resources/views/admin/login.blade.php'), 'utf8');
const form = source.match(/<form class="dashboard-login-form"[\s\S]*?<\/form>/)[0];
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
function html(oldUsername = '') {
    const rendered = form.replace(/\{\{\s*route\('admin.login'\)\s*\}\}/g, '/admin/signin')
        .replace(/\{\{\s*old\('email'\)\s*\}\}/g, oldUsername)
        .replace(/\{\{[\s\S]*?\}\}/g, '')
        .replace(/@error\('[^']+'\)\s*aria-invalid="true"\s*@enderror/g, '')
        .replace(/@lang\('[^']+'\)/g, 'Label')
        .replace('@csrf', '<input type="hidden" name="_token" value="test-csrf">');
    return '<!doctype html><html><body>' + rendered + '<script>' + script + '</script></body></html>';
}

(async () => {
    const launch = { headless: true, args: ['--no-sandbox'] };
    if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) launch.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
    const browser = await chromium.launch(launch);
    let submissions = 0;
    try {
        const context = await browser.newContext();
        let posted;
        let oldUsername = '';
        await context.route('https://dashboard.test/**', async route => {
            if (route.request().method() === 'POST') {
                posted = new URLSearchParams(route.request().postData());
                submissions++;
                await route.fulfill({ contentType: 'text/html', body: '<p>Signed in</p>' });
            } else {
                await route.fulfill({ contentType: 'text/html', body: html(oldUsername) });
            }
        });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('https://dashboard.test/admin/login');
        const username = page.locator('#dashboard-login-email');
        const password = page.locator('#dashboard-login-password');
        assert.equal(await username.inputValue(), '');
        assert.equal(await password.inputValue(), '');
        await username.fill('account@example.test');
        await password.fill('temporary-browser-fixture');
        await page.locator('[data-password-toggle]').click();
        assert.equal(await password.getAttribute('type'), 'text');
        await Promise.all([page.waitForURL('**/admin/signin'), page.locator('[type="submit"]').click()]);
        assert.equal(posted.get('email'), 'account@example.test');
        assert.equal(posted.get('password'), 'temporary-browser-fixture', 'clearing on navigation does not erase the submitted credential');
        assert.equal(posted.has('remember'), false);
        assert.deepEqual(await page.evaluate(() => Object.entries(localStorage)), [['fasakhansta.dashboard.username', 'account@example.test']]);
        assert.equal(await page.evaluate(() => sessionStorage.length), 0);
        await page.goBack();
        assert.equal(await username.inputValue(), 'account@example.test');
        assert.equal(await password.inputValue(), '');
        assert.equal(await password.getAttribute('type'), 'password');
        await page.reload();
        assert.equal(await username.inputValue(), 'account@example.test');
        assert.equal(await password.inputValue(), '');
        await password.fill('temporary-browser-fixture');
        await page.locator('#remember').check();
        await Promise.all([page.waitForURL('**/admin/signin'), page.locator('[type="submit"]').click()]);
        assert.equal(posted.get('remember'), '1');
        assert.deepEqual(await page.evaluate(() => Object.entries(localStorage)), [['fasakhansta.dashboard.username', 'account@example.test']]);
        oldUsername = 'failed-login@example.test';
        await page.goto('https://dashboard.test/admin/login');
        assert.equal(await username.inputValue(), oldUsername, 'server validation username takes precedence over saved name');
        await password.fill('restored-form-fixture');
        await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
        assert.equal(await password.inputValue(), '', 'a restored form always starts with an empty password');
        assert.deepEqual(errors, []);
        await context.close();

        const blocked = await browser.newContext();
        await blocked.addInitScript(() => {
            Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('Storage blocked', 'SecurityError'); } });
        });
        await blocked.route('https://dashboard.test/**', async route => {
            if (route.request().method() === 'POST') {
                submissions++;
                await route.fulfill({ contentType: 'text/html', body: '<p>Signed in</p>' });
            } else await route.fulfill({ contentType: 'text/html', body: html() });
        });
        const blockedPage = await blocked.newPage();
        const blockedErrors = [];
        blockedPage.on('pageerror', error => blockedErrors.push(error.message));
        await blockedPage.goto('https://dashboard.test/admin/login');
        await blockedPage.locator('#dashboard-login-email').fill('storage-blocked@example.test');
        await blockedPage.locator('#dashboard-login-password').fill('temporary-browser-fixture');
        await Promise.all([blockedPage.waitForURL('**/admin/signin'), blockedPage.locator('[type="submit"]').click()]);
        assert.deepEqual(blockedErrors, []);
        assert.equal(submissions, 3);
        console.log('Dashboard login browser: username-only storage, empty passwords, remember submission, back/reload and blocked storage passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
