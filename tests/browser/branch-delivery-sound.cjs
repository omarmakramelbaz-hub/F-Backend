'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.DESKTOP_TEST_BROWSER_MODULE || 'playwright');
const script = fs.readFileSync(path.resolve(__dirname, '../../public/dashboard/js/branch-print-receiver.js'), 'utf8');
const boot = {branch:'f:100',actor_id:10,jobs:'/jobs',claim:'/claim',complete:'/complete',orders:'/admin/phone-orders',labels:{ready:'Ready',offline:'Offline',attention:'Attention',incoming:'وصل طلب جديد للفرع',enable_sound:'تفعيل نغمة طلبات الدليفري'}};
(async () => {
    const browser = await chromium.launch({headless:true,executablePath:process.env.ATTENDANCE_BROWSER_EXECUTABLE || undefined,args:['--no-sandbox','--autoplay-policy=user-gesture-required']});
    const page = await browser.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    let result = {success:true,latest_ticket_id:20,incoming_ticket_ids:[20],jobs:[],attention:0}, prints = 0, completions = 0;
    await page.route('https://branch.test/**', async route => {
        const pathname = new URL(route.request().url()).pathname;
        if(pathname === '/jobs') return route.fulfill({json:result});
        if(pathname === '/claim') {prints++;return route.fulfill({json:{success:true,print_url:'/kitchen/1'}});}
        if(pathname === '/complete') {completions++;return route.fulfill({json:{success:true}});}
        return route.fulfill({contentType:'text/html',body:`<meta charset="utf-8"><meta name="csrf-token" content="test"><button id="operate">متابعة العمل</button><script type="application/json" id="branch-print-bootstrap">${JSON.stringify(boot)}</script>`});
    });
    async function load(mock=true) {
        await page.goto('https://branch.test/');
        await page.evaluate(mock => {
            window.tones = 0; window.contexts = 0; window.polls = 0; window.printInvocations = 0;
            const original = window.setTimeout;
            window.setTimeout = (fn, ms) => original(fn, ms === 2500 || ms === 500 ? 30 : ms);
            document.addEventListener('dashboard:branch-print', () => window.polls++);
            window.DashboardPrint = {print:async () => window.printInvocations++};
            if(mock) window.AudioContext = class {
                constructor(){window.contexts++;this.state='suspended';this.currentTime=0;this.destination={};}
                resume(){this.state='running';return Promise.resolve();}
                close(){this.state='closed';return Promise.resolve();}
                createOscillator(){return {frequency:{},connect(){},disconnect(){},start(){window.tones++;},stop(){}};}
                createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){},disconnect(){}};}
            };
        }, mock);
        await page.addScriptTag({content:script});
        await page.waitForFunction(() => window.polls >= 1);
    }
    async function pollAgain() {const before=await page.evaluate(()=>window.polls);await page.waitForFunction(before=>window.polls>before,before);}
    try {
        await load();
        assert.equal(await page.evaluate(()=>window.tones),0);
        assert.equal(await page.locator('[data-branch-order-alert]').innerText(),boot.labels.incoming);
        await page.locator('[data-branch-sound-enable]').click();
        assert.equal(await page.evaluate(()=>window.tones),3,'first pending call-center order sounds after unlocking');
        await pollAgain();assert.equal(await page.evaluate(()=>window.tones),3,'same order is silent on repeat polls');
        result={...result,latest_ticket_id:30,incoming_ticket_ids:[20]};await pollAgain();
        assert.equal(await page.evaluate(()=>window.tones),3,'branch-local ticket does not trigger the call-center sound');
        result={...result,incoming_ticket_ids:[21,20],jobs:[{id:1,ticket_id:21}]};
        await page.waitForFunction(()=>window.tones===6);
        await page.waitForFunction(()=>window.printInvocations>=1);
        assert.ok(prints>=1 && completions>=1,'automatic printing still claims and completes jobs');
        result={...result,jobs:[]};
        result={...result,incoming_ticket_ids:[19,21,20]};await page.waitForFunction(()=>window.tones===9);
        assert.equal(await page.evaluate(()=>window.contexts),1,'one audio context handles multiple arrivals');
        await load();await page.locator('#operate').click();await pollAgain();
        assert.equal(await page.evaluate(()=>window.tones),0,'navigation does not replay heard orders');
        result={...result,incoming_ticket_ids:[22,21,20]};
        await load();
        assert.equal(await page.evaluate(()=>window.tones),0);
        result={...result,incoming_ticket_ids:[21,20]};await pollAgain();await page.locator('#operate').click();
        assert.equal(await page.evaluate(()=>window.tones),0,'order cancelled before audio unlock remains silent');
        await page.evaluate(()=>dispatchEvent(new Event('pagehide')));
        const polls=await page.evaluate(()=>window.polls);await page.waitForTimeout(100);
        assert.equal(await page.evaluate(()=>window.polls),polls,'leaving the page stops polling');
        // Real browser Web Audio: a trusted click must unlock the context used by later polls.
        result={...result,incoming_ticket_ids:[]};await load(false);
        await page.locator('[data-branch-sound-enable]').click();
        await page.waitForFunction(()=>DashboardBranchPrinter.soundReady);
        result={...result,incoming_ticket_ids:[50]};await pollAgain();
        await page.waitForFunction(()=>JSON.parse(sessionStorage.getItem('branch-delivery-sound:10:f:100')).includes(50));
        assert.deepEqual(errors,[]);
        console.log('PASS pending first arrival, autoplay unlock, deduplication, branch-local silence, out-of-order arrivals, printing, navigation, cancellation, cleanup and real Chromium Web Audio');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
