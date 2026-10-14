// Real Chromium media playback under a strict autoplay policy, served only on localhost.
// CODEX_PRIMARY_RUNTIME_NODE=... CODEX_PRIMARY_RUNTIME_NODE_MODULES=... \
// PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=... "$CODEX_PRIMARY_RUNTIME_NODE" tests/browser/order-alert-smoke.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES
    ? path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES, 'playwright') : 'playwright');
const root = path.resolve(__dirname, '../..');
const javascript = fs.readFileSync(path.join(root, 'public/dashboard/js/dashboard-order-alerts.js'));
const sound = fs.readFileSync(path.join(root, 'public/sounds/mixkit-correct-answer-reward-952.wav'));
const pageHtml = `<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><body>
<button id="toggle-sound"><i id="sound-icon"></i>الصوت</button>
<script>
// Capture the genuine HTMLAudioElement without changing its playback or autoplay behavior.
const NativeAudio = window.Audio;
window.Audio = function (url) { const audio = new NativeAudio(url); window.fixtureAudio = audio; return audio; };
window.Audio.prototype = NativeAudio.prototype;
</script>
<script src="/dashboard-order-alerts.js" data-sound-actor="browser-smoke" data-sound-url="/order.wav"></script>
<script>
// Runs from the page task queue, before any real user gesture.
setTimeout(() => DashboardOrderAlerts.notify('app:101'), 25);
</script></body></html>`;

(async () => {
    const requests = [], errors = [];
    const server = http.createServer((request, response) => {
        requests.push(request.url);
        const routes = {
            '/': ['text/html; charset=utf-8', Buffer.from(pageHtml)],
            '/dashboard-order-alerts.js': ['application/javascript', javascript],
            '/order.wav': ['audio/wav', sound],
        };
        const selected = routes[request.url];
        if (!selected) { response.writeHead(404); response.end(); return; }
        response.writeHead(200, { 'Content-Type': selected[0], 'Content-Length': selected[1].length, 'Cache-Control': 'no-store' });
        response.end(selected[1]);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    let browser;
    const samples = {};
    try {
        const launch = { headless: true, args: ['--autoplay-policy=user-gesture-required'] };
        if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) launch.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
        browser = await chromium.launch(launch);
        const context = await browser.newContext();
        await context.route('**/*', route => route.request().url().startsWith(origin + '/') ? route.continue() : route.abort());
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        const state = () => page.evaluate(() => ({
            ...DashboardOrderAlerts.state(), paused: fixtureAudio.paused, time: fixtureAudio.currentTime,
            loop: fixtureAudio.loop, duration: fixtureAudio.duration, mediaError: fixtureAudio.error?.code || null,
            buttonState: document.getElementById('toggle-sound').dataset.soundState,
        }));
        await page.goto(origin + '/');
        await page.waitForFunction(() => DashboardOrderAlerts.state().blocked);
        samples.beforeGesture = await state();
        assert.equal(samples.beforeGesture.playing, false, 'Blocked autoplay must not be recorded as playing.');
        assert.equal(samples.beforeGesture.paused, true);
        assert.equal(samples.beforeGesture.buttonState, 'blocked');
        assert.deepEqual(samples.beforeGesture.pending, ['app:101']);
        assert.equal(await page.locator('[data-order-sound-unlock]').isVisible(), true, 'Blocked orders show an unlock button.');

        await page.locator('#toggle-sound').click();
        await page.waitForFunction(() => DashboardOrderAlerts.state().playing && fixtureAudio.currentTime > 0.05);
        samples.afterGesture = await state();
        assert.equal(samples.afterGesture.paused, false);
        assert.equal(samples.afterGesture.loop, true);
        assert.equal(samples.afterGesture.blocked, false);
        assert.equal(samples.afterGesture.mediaError, null);
        await page.waitForTimeout(160);
        assert((await state()).time > samples.afterGesture.time, 'The real media clock advances.');

        await page.evaluate(() => { DashboardOrderAlerts.notify('app:102'); DashboardOrderAlerts.acknowledge('app:101'); });
        samples.oneRemaining = await state();
        assert.deepEqual(samples.oneRemaining.pending, ['app:102']);
        assert.equal(samples.oneRemaining.playing, true, 'Acknowledging one order keeps the other audible.');
        assert.equal(samples.oneRemaining.paused, false);
        assert.equal(samples.oneRemaining.loop, true);
        await page.evaluate(() => DashboardOrderAlerts.acknowledge('app:102'));
        samples.allAcknowledged = await state();
        assert.deepEqual(samples.allAcknowledged.pending, []);
        assert.equal(samples.allAcknowledged.playing, false);
        assert.equal(samples.allAcknowledged.paused, true);
        assert.equal(samples.allAcknowledged.time, 0);

        await page.evaluate(() => DashboardOrderAlerts.notify('app:103'));
        await page.waitForFunction(() => DashboardOrderAlerts.state().playing);
        await page.locator('#toggle-sound').click();
        samples.muted = await state();
        assert.equal(samples.muted.enabled, false);
        assert.equal(samples.muted.paused, true);
        assert.equal(await page.evaluate(() => localStorage.getItem('soundEnabled')), 'false');
        await page.reload();
        await page.waitForFunction(() => DashboardOrderAlerts.state().pending.includes('app:101'));
        samples.mutedReload = await state();
        assert.equal(samples.mutedReload.enabled, false, 'Mute survives reload.');
        assert.equal(samples.mutedReload.playing, false);
        assert.equal(samples.mutedReload.paused, true);
        assert(samples.mutedReload.pending.includes('app:103'), 'An unacknowledged order survives reload.');
        await page.locator('#toggle-sound').click();
        await page.waitForFunction(() => DashboardOrderAlerts.state().playing && fixtureAudio.currentTime > 0.03);
        await page.evaluate(() => DashboardOrderAlerts.state().pending.forEach(key => DashboardOrderAlerts.acknowledge(key)));
        assert.equal((await state()).paused, true);
        assert.deepEqual(errors, [], 'The production sound engine has no browser JavaScript errors.');
        assert(requests.includes('/order.wav'), 'Chromium loaded the actual production WAV.');
        console.log('Order alert Chromium smoke passed: blocked autoplay, gesture unlock, real media clock, two-order acknowledgements, persistent mute and pending orders.');
        console.log(JSON.stringify({ browser: browser.version(), samples }, null, 2));
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
