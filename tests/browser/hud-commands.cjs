'use strict';
// Real DOM command controls; browser speech services are explicitly stubbed.
const {chromium} = require(process.env.DESKTOP_TEST_BROWSER_MODULE || 'playwright');
const http = require('node:http'), fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const repo = path.resolve(__dirname, '../..');
const js = fs.readFileSync(path.join(repo, 'public/dashboard/js/hud-commands.js'), 'utf8');
const css = fs.readFileSync(path.join(repo, 'public/dashboard/css/hud-commands.css'), 'utf8');
function fixture(unsupported) {
    return `<!doctype html><html lang="ar"><meta charset="utf-8"><style>body{margin:0}#go-hud{height:100vh;position:relative;background:#010b14;color:white}${css}</style><body data-dashboard-actor="1:owner"><section id="go-hud"><a class="hud-card" data-hud-key="customers" href="/admin/customers"><span class="hud-card-label">العملاء</span><span class="hud-card-values"><span><b>50,802</b>عميل</span></span></a><a class="hud-card" data-hud-key="drawer" data-balance-state="ready" href="/admin/dashboard#drawers"><span class="hud-card-label">رصيد الفروع</span><span class="hud-card-values"><span><b>-1.05</b>ج.م</span></span></a><a class="hud-card" data-hud-key="stock" href="https://foreign.invalid/steal"><span class="hud-card-label">مخزون البضاعة</span><span class="hud-card-values"><span><b>28</b>صنف</span></span></a><a class="hud-card" data-hud-key="expenses" href="javascript:window.pwned=true"><span class="hud-card-label">المصروفات</span><span class="hud-card-values"><span><b>278</b>مصروف</span></span></a><button data-hud-enter>الدخول للرئيسية</button><div class="hud-live"><span data-hud-scope>جميع الفروع المصرح بها</span></div></section><script>
window.fixture={spoken:[],cancelled:0,started:0,aborted:0,clicked:[],ducked:0,speaking:[],enter:0,recognizers:[]};
window.SpeechSynthesisUtterance=function(text){this.text=text;};
Object.defineProperty(window,'speechSynthesis',{configurable:true,value:{getVoices:()=>[{lang:'en-US',name:'English'},{lang:'ar-EG',name:'Arabic'}],speak:item=>{fixture.spoken.push(item);if(item.onstart)item.onstart();},cancel:()=>fixture.cancelled++}});
${unsupported ? '' : `window.webkitSpeechRecognition=class{constructor(){fixture.recognizers.push(this);}start(){fixture.started++;this.onstart?.();}abort(){fixture.aborted++;}};`}
document.querySelectorAll('a').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();fixture.clicked.push(a.dataset.hudKey);}));
document.querySelector('[data-hud-enter]').addEventListener('click',()=>fixture.enter++);
</script><script>${js}</script><script>window.commands=GoHudCommands.mount(document.querySelector('#go-hud'),{duckGreeting:()=>fixture.ducked++,setSpeaking:on=>fixture.speaking.push(on)});</script></body></html>`;
}
const server = http.createServer((req, res) => { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(fixture(req.url.includes('unsupported'))); });
let checks = 0;
function check(value, message) { assert(value, message); checks++; }
function equal(actual, expected, message) { assert.deepEqual(actual, expected, message); checks++; }
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    server.unref();
    const origin = 'http://127.0.0.1:' + server.address().port;
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || chromium.executablePath(), headless: true, args: ['--no-sandbox']});
    const errors = [];
    try {
        const page = await browser.newPage({viewport: {width: 1672, height: 941}}); page.on('pageerror', e => errors.push(e.message));
        await page.goto(origin); await page.clock.install();
        const execute = text => page.evaluate(text => commands.execute(text), text);
        const status = () => page.locator('[data-hud-command-status]').textContent();
        equal(await page.evaluate(() => fixture.started), 0, 'No microphone or speech recognition starts on mount');
        equal(await page.locator('.hud-command-panel').isVisible(), false, 'Optional panel begins closed');
        await page.locator('[data-hud-commands-toggle]').click();
        check(await page.locator('[data-hud-command-input]').evaluate(e => document.activeElement === e), 'Opening panel focuses typed command input');
        await page.locator('[data-hud-command-input]').fill('عدد العملاء كام؟'); await page.locator('.hud-command-form button').click();
        check((await status()).includes('50,802 عميل'), 'Typed command reads current metric with separated number and unit');
        check((await status()).includes('جميع الفروع المصرح بها'), 'Read includes actual displayed scope');
        equal(await page.evaluate(() => fixture.spoken.at(-1).lang), 'ar-EG', 'Response selects actual Arabic voice');
        equal(await execute('يا جو لو سمحت افتح العملاء'), true, 'Arabic polite navigation command is supported');
        equal(await page.evaluate(() => fixture.clicked), ['customers'], 'Navigation uses the existing authorized anchor action');
        equal(await execute('رصيد أدراج الفروع كام'), true, 'Owner drawer alias resolves');
        check((await status()).includes('-1.05 ج.م'), 'Signed money comes directly from displayed authoritative amount');
        await page.evaluate(() => document.querySelector('.hud-live').classList.add('stale'));
        await execute('رصيد الفروع كام'); check((await status()).includes('تعذّر تحديثها'), 'Stale summary is described as last available values');
        await page.evaluate(() => document.querySelector('[data-hud-key=drawer]').dataset.balanceState = 'loading');
        equal(await execute('رصيد الفروع كام'), false, 'Unknown/loading currency is not spoken as confirmed money');
        check((await status()).includes('غير جاهزة'), 'Loading response explains no confirmed value');
        const clicked = await page.evaluate(() => fixture.clicked.length);
        for (const unsafe of ['افتح المخزون', 'افتح المصروفات', 'افتح https://evil.invalid', 'امسح العملاء', 'افتح العملاء وامسح الكل', 'رصيد المنصورة كام', 'مبيعات اليوم كام']) {
            equal(await execute(unsafe), false, 'Unsafe, mutating, out-of-scope, or unsupported request is rejected: ' + unsafe);
        }
        equal(await page.evaluate(() => fixture.clicked.length), clicked, 'Rejected commands perform no navigation');
        equal(await page.evaluate(() => window.pwned), undefined, 'Dangerous URL never executes');
        await page.evaluate(() => { const c = document.querySelector('[data-hud-key=customers]'); c.hidden = true; });
        equal(await execute('افتح العملاء'), false, 'A card hidden after mount cannot be addressed');
        await page.evaluate(() => document.querySelector('[data-hud-key=customers]').hidden = false);
        await page.evaluate(() => { const a = document.querySelector('[data-hud-key=customers]').cloneNode(true); document.querySelector('#go-hud').append(a); });
        equal(await execute('افتح العملاء'), false, 'Ambiguous duplicate targets fail closed');
        await page.evaluate(() => document.querySelectorAll('[data-hud-key=customers]')[1].remove());
        await page.locator('[data-hud-command-mic]').click();
        equal(await page.evaluate(() => fixture.started), 1, 'Mic starts only after explicit button click');
        equal(await page.evaluate(() => { const r = fixture.recognizers.at(-1); return [r.lang, r.continuous, r.interimResults]; }), ['ar-EG', false, false], 'Microphone uses a single final Arabic-Egyptian phrase');
        await page.evaluate(() => { const r = fixture.recognizers.at(-1); const result = [{transcript: 'افتح العملاء'}]; result.isFinal = false; r.onresult({results: [result], resultIndex: 0}); });
        equal(await page.evaluate(() => fixture.clicked.length), clicked, 'Interim recognition results never execute');
        await page.evaluate(() => { const r = fixture.recognizers.at(-1); const result = [{transcript: 'افتح العملاء'}]; result.isFinal = true; r.onresult({results: [result], resultIndex: 0}); });
        equal(await page.evaluate(() => fixture.clicked.length), clicked + 1, 'One final voice command executes once');
        equal(await page.evaluate(() => fixture.recognizers.at(-1).onresult), null, 'Final result detaches callbacks and aborts mic');
        await page.locator('[data-hud-command-mic]').click();
        await page.evaluate(() => fixture.recognizers.at(-1).onerror({error: 'not-allowed'}));
        check((await status()).includes('غير مسموح'), 'Permission refusal offers typed command fallback');
        equal(await page.locator('[data-hud-command-mic]').getAttribute('aria-pressed'), 'false', 'Permission failure clears listening state');
        await page.locator('[data-hud-command-mic]').click(); await page.clock.runFor(15001);
        check((await status()).includes('انتهى وقت الاستماع'), 'Mic shuts off after bounded 15-second session');
        equal(await page.locator('[data-hud-command-mic]').getAttribute('aria-pressed'), 'false', 'Timeout never auto-restarts microphone');
        await page.evaluate(() => {
            const bus = new EventTarget(); fixture.voices = [];
            Object.defineProperty(window, 'speechSynthesis', {configurable: true, value: {getVoices: () => fixture.voices, addEventListener: (...args) => bus.addEventListener(...args), removeEventListener: (...args) => bus.removeEventListener(...args), speak: item => fixture.spoken.push(item), cancel: () => fixture.cancelled++}});
            fixture.voiceReady = () => { fixture.voices = [{lang: 'ar-EG'}]; bus.dispatchEvent(new Event('voiceschanged')); };
        });
        let spoken = await page.evaluate(() => fixture.spoken.length);
        await execute('عدد العملاء كام'); equal(await page.evaluate(() => fixture.spoken.length), spoken, 'Initially empty system voices wait without speaking a wrong language');
        await page.evaluate(() => fixture.voiceReady()); equal(await page.evaluate(() => fixture.spoken.length), spoken + 1, 'First Arabic response starts when voiceschanged provides Arabic voice');
        await page.evaluate(() => fixture.voices = []); await execute('عدد العملاء كام');
        await page.locator('[data-hud-command-input]').press('Escape');
        spoken = await page.evaluate(() => fixture.spoken.length); await page.evaluate(() => fixture.voiceReady()); await page.clock.runFor(1300);
        equal(await page.evaluate(() => fixture.spoken.length), spoken, 'Closing panel cancels pending voice so late voiceschanged cannot speak');
        await page.locator('[data-hud-commands-toggle]').click();
        await page.evaluate(() => fixture.voices = []); await execute('عدد العملاء كام'); await page.clock.runFor(1201);
        check((await status()).includes('لا يوجد صوت عربي جاهز'), 'Bounded voice wait gives explicit text-only fallback');
        await page.evaluate(() => fixture.voices = []); await execute('عدد العملاء كام');
        await page.evaluate(() => { fixture.readCard = document.querySelector('[data-hud-key=customers]'); fixture.readCard.remove(); }); await page.evaluate(() => Promise.resolve());
        check(!(await status()).includes('50,802') && (await status()).includes('لم يعد متاحًا'), 'Removing the previously read card immediately clears its private response');
        spoken = await page.evaluate(() => fixture.spoken.length); await page.evaluate(() => fixture.voiceReady()); await page.clock.runFor(1300);
        equal(await page.evaluate(() => fixture.spoken.length), spoken, 'Late voiceschanged cannot speak a removed authorized card');
        await page.evaluate(() => document.querySelector('#go-hud').append(fixture.readCard));
        await execute('عدد العملاء كام');
        let cancelled = await page.evaluate(() => fixture.cancelled);
        await page.evaluate(() => fixture.readCard.classList.add('unavailable')); await page.evaluate(() => Promise.resolve());
        check((await status()).includes('لم يعد متاحًا') && !(await status()).includes('50,802'), 'Losing availability erases the prior metric response');
        check((await page.evaluate(() => fixture.cancelled)) > cancelled, 'Availability loss cancels an already queued metric utterance');
        await page.evaluate(() => fixture.readCard.classList.remove('unavailable'));
        await execute('عدد العملاء كام');
        await page.evaluate(() => fixture.readCard.hidden = true); await page.evaluate(() => Promise.resolve());
        check((await status()).includes('لم يعد متاحًا') && !(await status()).includes('50,802'), 'Hiding the previously read card clears its response');
        await page.evaluate(() => fixture.readCard.hidden = false);
        await page.evaluate(() => Object.defineProperty(window, 'speechSynthesis', {configurable: true, value: {getVoices: () => [{lang: 'en-US'}], speak: () => fixture.wrongVoice = true, cancel() {}}}));
        await execute('عدد العملاء كام'); equal(await page.evaluate(() => fixture.wrongVoice), undefined, 'No silent English fallback for Arabic speech');
        await page.locator('[data-hud-command-input]').press('Escape'); equal(await page.locator('.hud-command-panel').isVisible(), false, 'Escape closes optional panel');
        check(await page.locator('[data-hud-commands-toggle]').evaluate(e => document.activeElement === e), 'Closing panel returns keyboard focus to toggle');
        await page.locator('[data-hud-commands-toggle]').click(); await page.locator('[data-hud-command-mic]').click();
        await page.evaluate(() => document.body.dataset.dashboardActor = '2:owner');
        await page.evaluate(() => Promise.resolve());
        equal(await execute('افتح العملاء'), false, 'Actor change fences old mounted command session');
        equal(await page.locator('.hud-command-panel').count(), 0, 'Actor change removes private command response and microphone controls');
        await page.evaluate(() => commands.destroy()); equal(await page.locator('.hud-command-panel').count(), 0, 'Destroy removes controls');
        equal(await page.evaluate(() => commands.execute('افتح العملاء')), false, 'Disposed API cannot execute late actions');
        await page.goto(origin + '/unsupported');
        check(await page.locator('[data-hud-command-mic]').isDisabled(), 'Unsupported recognition has no active microphone control');
        equal(await execute('افتح العملاء'), true, 'Typed navigation remains available without recognition support');
        equal(await execute('افتح الرئيسية'), true, 'Home command uses existing enter control'); equal(await page.evaluate(() => fixture.enter), 1, 'Home entry is dispatched once');
        equal(await page.evaluate(() => GoHudCommands.mount(document.querySelector('#go-hud')) === commands), true, 'Remount is idempotent');
        await page.locator('[data-hud-commands-toggle]').click();
        await page.setViewportSize({width: 375, height: 812});
        check(await page.locator('.hud-command-panel').evaluate(e => e.getBoundingClientRect().width <= document.documentElement.clientWidth), 'Typed panel fits mobile viewport');
        equal(errors, [], 'No browser runtime errors');
        console.log('GO HUD command browser assertions passed: ' + checks);
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
