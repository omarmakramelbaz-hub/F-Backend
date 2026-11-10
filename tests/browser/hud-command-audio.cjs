'use strict';
// Real same-origin HTTP requests and DOM; speech and Audio APIs are controlled stubs.
const {chromium} = require(process.env.DESKTOP_TEST_BROWSER_MODULE || 'playwright');
const http = require('node:http'), fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const source = fs.readFileSync(path.resolve(__dirname, '../../public/dashboard/js/hud-commands.js'), 'utf8');
const requests = [], held = new Map(), waiters = new Map();
const wav = Buffer.alloc(80); wav.write('RIFF'); wav.write('WAVE', 8);
function respond(res, mode) {
    if (mode === 'network-failure') { res.destroy(); return; }
    if (mode === 'redirect') { res.writeHead(302, {Location:'/login'}); res.end(); return; }
    res.writeHead(mode === 'http-failure' ? 503 : 200, {'Content-Type': mode === 'wrong-type' ? 'text/html' : 'audio/wav', 'Cache-Control': 'private, no-store'});
    res.end(mode === 'tiny-audio' ? Buffer.alloc(10) : mode === 'large-audio' ? Buffer.alloc(2097153) : wav);
}
function fixture(url) {
    const mode = url.searchParams.get('mode') || 'fallback';
    return `<!doctype html><html lang="ar"><meta charset="utf-8"><meta name="csrf-token" content="fixture-csrf"><style>#go-hud{height:800px}.hud-command-panel[hidden],button[hidden],p[hidden]{display:none}button{padding:12px}</style><body data-dashboard-actor="1:owner"><section id="go-hud"><a class="hud-card" data-hud-key="customers" href="/admin/customers"><span class="hud-card-label">العملاء</span><span class="hud-card-values"><span><b>50,802</b>عميل</span></span></a><div class="hud-live"><span data-hud-scope>جميع الفروع المصرح بها</span></div></section><script>
window.fixture={mode:${JSON.stringify(mode)},requests:[],audios:[],created:[],revoked:[],spoken:[],nativeUtterances:[],speaking:[],voices:[],rejectNext:${mode.includes('autoplay')},recognizers:[]};
const voiceEvents=new EventTarget();
window.SpeechSynthesisUtterance=function(text){this.text=text;};
Object.defineProperty(window,'speechSynthesis',{configurable:true,value:${mode === 'missing-synthesis' ? 'undefined' : "{getVoices:()=>fixture.voices,addEventListener:(...a)=>voiceEvents.addEventListener(...a),removeEventListener:(...a)=>voiceEvents.removeEventListener(...a),speak:item=>{fixture.spoken.push(item.text);fixture.nativeUtterances.push(item);if(fixture.mode!=='native-silent')item.onstart?.();},cancel(){}}"}});
fixture.voices=${['native-ready','native-error-autoplay','native-silent'].includes(mode) ? "[{lang:'ar-EG'}]" : "[{lang:'en-US'}]"};
fixture.nativeReady=()=>{fixture.voices=[{lang:'ar-EG'}];voiceEvents.dispatchEvent(new Event('voiceschanged'));};
const Recognition=class{constructor(){fixture.recognizers.push(this);}start(){this.onstart?.();}abort(){}};
for(const key of ['SpeechRecognition','webkitSpeechRecognition'])Object.defineProperty(window,key,{configurable:true,value:Recognition});
const realFetch=window.fetch.bind(window);
window.fetch=(url,options)=>{const record={url:String(url),method:options.method,credentials:options.credentials,cache:options.cache,redirect:options.redirect,headers:options.headers,body:options.body,aborted:false};fixture.requests.push(record);options.signal.addEventListener('abort',()=>record.aborted=true);const actual={...options},ignoreAbort=new URL(location.href).searchParams.has('ignoreabort');if(ignoreAbort)delete actual.signal;return realFetch(url,actual).then(async response=>{if(ignoreAbort)await response.clone().arrayBuffer();record.fulfilled=true;return response;});};
window.Audio=class{constructor(){this.src='';this.paused=0;this.removed=[];this.plays=0;fixture.audios.push(this);}play(){this.plays++;if(fixture.rejectNext){fixture.rejectNext=false;return Promise.reject(Object.assign(new Error('gesture required'),{name:'NotAllowedError'}));}this.onplaying?.();return Promise.resolve();}pause(){this.paused++;}removeAttribute(name){this.removed.push(name);if(name==='src')this.src='';}};
URL.createObjectURL=blob=>{const url='blob:private-fixture-'+(fixture.created.length+1);fixture.created.push({url,type:blob.type,size:blob.size});return url;};
URL.revokeObjectURL=url=>fixture.revoked.push(url);
</script><script>${source}</script><script>window.commands=GoHudCommands.mount(document.getElementById('go-hud'),{setSpeaking:on=>fixture.speaking.push(on)});</script></body></html>`;
}
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://fixture.invalid');
    if (url.pathname === '/admin/dashboard/hologram-arabic/speak') {
        let body = ''; req.on('data', part => body += part); req.on('end', () => {
            const referrer = new URL(req.headers.referer || '/', 'http://fixture.invalid'), mode = referrer.searchParams.get('mode') || 'fallback';
            requests.push({mode, method:req.method, url:req.url, headers:req.headers, body});
            if (mode.startsWith('hold-')) { held.set(mode, res); if (waiters.has(mode)) { waiters.get(mode)(); waiters.delete(mode); } }
            else respond(res, mode);
        }); return;
    }
    res.writeHead(200, {'Content-Type':'text/html; charset=utf-8', 'Set-Cookie':'fixture-session=private; HttpOnly; SameSite=Lax'}); res.end(fixture(url));
});
function waitHeld(mode) {
    if (held.has(mode)) return Promise.resolve();
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => { waiters.delete(mode); reject(new Error('No pending voice request: '+mode)); }, 5000);
        waiters.set(mode, () => { clearTimeout(timer); resolve(); });
    });
}
let checks = 0;
function equal(a,b,message) { assert.deepEqual(a,b,message); checks++; }
function check(value,message) { assert(value,message); checks++; }
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); server.unref();
    const origin = 'http://127.0.0.1:'+server.address().port;
    const browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),headless:true,args:['--no-sandbox']});
    const errors = [];
    const page = await browser.newPage(); page.on('pageerror', error => errors.push(error.message));
    const status = () => page.locator('[data-hud-command-status]').textContent();
    let clockInstalled = false;
    async function open(mode, ignoreAbort=false) {
        console.log('GO private Arabic audio scenario: '+mode);
        await page.goto(origin+'/fixture?mode='+encodeURIComponent(mode)+(ignoreAbort?'&ignoreabort=1':''));
        if (!clockInstalled) { await page.clock.install(); clockInstalled = true; }
        await page.locator('[data-hud-commands-toggle]').click();
        equal(await page.evaluate(()=>fixture.requests.length),0,'Opening the panel requests no synthesized reply');
    }
    async function request() {
        equal(await page.evaluate(()=>commands.execute('عدد العملاء كام')),true,'Read command resolves the currently authorized customer card');
        await page.clock.runFor(1201);
    }
    async function waitPage(predicate) {
        const deadline=Date.now()+5000;
        while(Date.now()<deadline){if(await page.evaluate(predicate))return;await new Promise(resolve=>setTimeout(resolve,10));}
        throw new Error('Browser fixture did not settle: '+predicate);
    }
    async function audioReady() { await waitPage(()=>fixture.audios.length===1); await page.evaluate(()=>Promise.resolve()); }
    async function cancel(kind) {
        if(kind==='stop')await page.locator('.hud-command-stop').click();
        else if(kind==='close')await page.locator('.hud-command-close').click();
        else await page.evaluate(kind=>{
            if(kind==='hidden'){Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));}
            if(kind==='root-hidden')document.getElementById('go-hud').hidden=true;
            if(kind==='actor')document.body.dataset.dashboardActor='2:owner';
            if(kind==='destroy')commands.destroy();
            if(kind==='scope')document.querySelector('[data-hud-key=customers]').remove();
            if(kind==='unavailable')document.querySelector('[data-hud-key=customers]').classList.add('unavailable');
            if(kind==='scope-text')document.querySelector('[data-hud-scope]').textContent='فرع واحد';
            if(kind==='value-change')document.querySelector('[data-hud-key=customers] b').textContent='9';
        },kind);
        await page.evaluate(()=>Promise.resolve());
    }
    try {
        for(const mode of ['fallback','missing-synthesis']) {
            await open(mode); await request(); await audioReady();
            const record=await page.evaluate(()=>fixture.requests[0]);
            equal(record.url,origin+'/admin/dashboard/hologram-arabic/speak','Missing Arabic speech uses only the same-origin private endpoint');
            equal([record.method,record.credentials,record.cache],['POST','same-origin','no-store'],'Audio request uses authenticated noncached POST');
            equal(record.redirect,'error','Audio requests never follow a login or cross-origin redirect');
            equal(record.headers['X-CSRF-TOKEN'],'fixture-csrf','Audio request carries the application CSRF token');
            equal(record.headers.Accept,'audio/wav','Audio request asks for a WAV response');
            const payload=JSON.parse(record.body);
            equal(Object.keys(payload),['text'],'No actor, credentials or unrelated fields enter the body');
            check(payload.text.includes('50,802 عميل')&&payload.text.includes('جميع الفروع المصرح بها'),'Only the authorized displayed reply is synthesized');
            const actual=requests.filter(r=>r.mode===mode).at(-1);
            equal(actual.method,'POST','The real fixture receives a POST');
            check((actual.headers.cookie||'').includes('fixture-session=private'),'The real same-origin fetch sends the session cookie');
            equal(actual.body,record.body,'The real HTTP request has the exact displayed-reply payload');
            equal(await page.evaluate(()=>fixture.created.map(x=>[x.type,x.size])),[['audio/wav',80]],'Reply audio is constructed from the private response blob');
            check(await page.evaluate(()=>fixture.audios[0].src.startsWith('blob:private-fixture-')),'Playback uses a private blob URL');
            equal(await page.evaluate(()=>fixture.speaking.at(-1)),true,'Audio onplaying activates the portrait speaking state');
            equal(await page.evaluate(()=>fixture.spoken.length),0,'An English system voice never reads the Arabic response');
            await page.evaluate(()=>fixture.audios[0].onended());
            equal(await page.evaluate(()=>fixture.speaking.at(-1)),false,'Audio completion returns the portrait to silent idle');
            equal(await page.evaluate(()=>[fixture.audios[0].paused,fixture.audios[0].src,fixture.revoked.length]),[1,'',1],'Completion pauses audio, clears its source and revokes its blob');
        }
        await open('native-ready'); await request();
        equal(await page.evaluate(()=>[fixture.spoken.length,fixture.requests.length,fixture.audios.length]),[1,0,0],'An available Arabic system voice needs no server request');
        await open('native-silent');
        await page.evaluate(()=>commands.execute('عدد العملاء كام'));
        await page.clock.runFor(3501); await audioReady();
        equal(await page.evaluate(()=>[fixture.spoken.length,fixture.requests.length,fixture.audios[0].plays]),[1,1,1],'A system voice that never starts falls back exactly once');
        equal(await page.evaluate(()=>fixture.nativeUtterances[0].onstart),null,'The timed out native voice cannot start speaking over the fallback');
        await open('native-error-autoplay');
        await page.evaluate(()=>commands.execute('عدد العملاء كام'));
        equal(await page.evaluate(()=>[fixture.spoken.length,fixture.requests.length]),[1,0],'An installed Arabic voice is tried first');
        await page.evaluate(()=>fixture.nativeUtterances[0].onerror({error:'synthesis-failed'}));
        await audioReady(); await waitPage(()=>!document.querySelector('[data-hud-command-replay]').hidden);
        equal(await page.evaluate(()=>[fixture.spoken.length,fixture.requests.length,fixture.audios[0].plays]),[1,1,1],'A native-voice error falls back once and exposes autoplay replay');
        await page.locator('[data-hud-command-replay]').click();
        equal(await page.evaluate(()=>[fixture.audios[0].plays,fixture.requests.length,fixture.speaking.at(-1)]),[2,1,true],'Replay remains connected after an early native voice fails');
        await page.evaluate(()=>fixture.audios[0].onended());
        equal(await page.evaluate(()=>fixture.revoked.length),1,'A replayed native-error fallback releases its private blob on completion');
        await open('native-delayed');
        await page.evaluate(()=>commands.execute('عدد العملاء كام')); await page.evaluate(()=>fixture.nativeReady()); await page.clock.runFor(1300);
        equal(await page.evaluate(()=>[fixture.spoken.length,fixture.requests.length]),[1,0],'A voice arriving during the short readiness wait prevents fallback');
        await open('hold-late-native',true); await request(); await waitHeld('hold-late-native');
        await page.evaluate(()=>fixture.nativeReady());
        equal(await page.evaluate(()=>[fixture.spoken.length,fixture.requests.length]),[0,1],'A late native voice cannot double-play an already requested reply');
        respond(held.get('hold-late-native'),'fallback'); held.delete('hold-late-native'); await audioReady();
        equal(await page.evaluate(()=>fixture.audios[0].plays),1,'Late-native fallback audio plays only once');
        const cancellations=['stop','close','hidden','root-hidden','actor','destroy','scope','unavailable','scope-text','value-change'];
        for(const kind of cancellations) {
            const mode='hold-'+kind;
            await open(mode,true); await request(); await waitHeld(mode); await cancel(kind);
            equal(await page.evaluate(()=>fixture.requests[0].aborted),true,kind+' aborts the pending private request');
            // Chromium need not finish an unconsumed fetch body after the application
            // fences it. The fixture drains a clone and records delivery before the
            // real application's late-response guard gets the original response.
            respond(held.get(mode),'fallback'); held.delete(mode);
            await waitPage(()=>fixture.requests[0].fulfilled); await page.evaluate(()=>Promise.resolve());
            equal(await page.evaluate(()=>fixture.audios.length),0,kind+' ignores an audio response that arrives after cancellation');
            if(['scope','unavailable','scope-text','value-change'].includes(kind))check(!(await status()).includes('50,802'),'Scope invalidation removes the previous private readout');
        }
        for(const kind of cancellations) {
            await open('play-'+kind); await request(); await audioReady(); await cancel(kind);
            equal(await page.evaluate(()=>fixture.audios[0].src),'',kind+' clears an existing private audio source');
            check(await page.evaluate(()=>fixture.audios[0].paused>0),kind+' pauses an existing reply');
            equal(await page.evaluate(()=>fixture.revoked.length),1,kind+' revokes its one private blob URL');
            equal(await page.evaluate(()=>fixture.speaking.at(-1)),false,kind+' clears portrait speech animation');
        }
        for(const mode of ['wrong-type','http-failure','network-failure','redirect','tiny-audio','large-audio']) {
            await open(mode); await request(); await waitPage(()=>document.querySelector('[data-hud-command-status]').textContent.includes('تعذّر تشغيل الرد الصوتي الآن'));
            equal(await page.evaluate(()=>[fixture.audios.length,fixture.created.length]),[0,0],mode+' never exposes an invalid response as playable audio');
            check(!(await status()).includes('إعدادات الجهاز'),mode+' offers retry without device-settings advice');
        }
        await open('autoplay'); await request(); await audioReady();
        await waitPage(()=>!document.querySelector('[data-hud-command-replay]').hidden);
        equal(await page.evaluate(()=>fixture.speaking.at(-1)),false,'Autoplay refusal leaves the portrait silent');
        await page.locator('[data-hud-command-replay]').click();
        equal(await page.evaluate(()=>[fixture.audios[0].plays,fixture.requests.length]),[2,1],'A second user gesture reuses the same private reply without a second request');
        equal(await page.evaluate(()=>fixture.speaking.at(-1)),true,'Replay onplaying activates portrait speech');
        check(await page.locator('[data-hud-command-replay]').isHidden(),'Replay hides after its user gesture');
        await open('hold-timeout',true); await request(); await waitHeld('hold-timeout'); await page.clock.runFor(12001);
        equal(await page.evaluate(()=>fixture.requests[0].aborted),true,'The bounded server wait aborts its pending request');
        check((await status()).includes('تعذّر تشغيل الرد الصوتي الآن'),'A server timeout offers a retry');
        respond(held.get('hold-timeout'),'fallback'); held.delete('hold-timeout');
        await open('play-timeout'); await request(); await audioReady(); await page.clock.runFor(45001);
        equal(await page.evaluate(()=>[fixture.audios[0].src,fixture.revoked.length,fixture.speaking.at(-1)]),['',1,false],'The bounded playback timer releases audio and portrait speech');
        equal(errors,[],'No browser errors during private Arabic audio playback');
        console.log('GO HUD private Arabic audio assertions passed: '+checks);
    } finally {
        for(const res of held.values())res.destroy(); held.clear();
        await browser.close(); await new Promise(resolve=>server.close(resolve));
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
