'use strict';
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
(async()=>{
    const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.ATTENDANCE_BROWSER_EXECUTABLE||undefined});
    const page=await browser.newPage({timezoneId:'America/Los_Angeles'});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    const before=new Date('2026-10-09T02:59:59Z');await page.clock.install({time:before});
    await page.setContent(`<div data-dashboard-clock data-clock-server="${before.getTime()}"><bdi data-dashboard-operating-date></bdi><bdi data-dashboard-local-time></bdi></div>`);
    await page.evaluate(()=>{window.dayEvents=[];window.addEventListener('dashboard:operating-day',e=>window.dayEvents.push(e.detail));});
    const script=fs.readFileSync(path.resolve(__dirname,'../../public/dashboard/js/dashboard-operating-day.js'),'utf8');
    await page.addScriptTag({content:script});
    assert.equal(await page.locator('[data-dashboard-operating-date]').textContent(),'2026-10-08');
    await page.clock.fastForward(1000);
    assert.equal(await page.locator('[data-dashboard-operating-date]').textContent(),'2026-10-09');
    assert.equal(await page.locator('[data-dashboard-local-time]').textContent(),'6:00 am');
    assert.deepEqual(await page.evaluate(()=>window.dayEvents.at(-1)),{date:'2026-10-09',previous:'2026-10-08'});
    await page.addScriptTag({content:script});
    await page.clock.fastForward(1000);assert.equal(await page.evaluate(()=>window.dayEvents.length),2);
    const boundaries=await page.evaluate(()=>[
        DashboardOperatingDay.date(new Date('2026-11-01T03:59:59Z')),
        DashboardOperatingDay.date(new Date('2026-11-01T04:00:00Z')),
        DashboardOperatingDay.date(new Date('2027-01-01T03:59:59Z')),
        DashboardOperatingDay.date(new Date('2026-04-24T02:59:59Z')),
        DashboardOperatingDay.date(new Date('2026-04-24T03:00:00Z')),
    ]);
    assert.deepEqual(boundaries,['2026-10-31','2026-11-01','2026-12-31','2026-04-23','2026-04-24']);
    const offline=await browser.newPage({timezoneId:'America/Los_Angeles'});
    offline.on('pageerror',e=>errors.push(e.message));
    await offline.clock.install({time:new Date('2026-11-10T12:00:00Z')});
    await offline.setContent(`<body data-dashboard-local="1"><div data-dashboard-clock data-clock-server="${before.getTime()}"><bdi data-dashboard-operating-date></bdi><bdi data-dashboard-local-time></bdi></div></body>`);
    await offline.addScriptTag({content:script});
    assert.equal(await offline.evaluate(()=>DashboardOperatingDay.current()),'2026-11-10');
    assert.equal(await offline.locator('[data-dashboard-local-time]').textContent(),'2:00 pm');
    assert.deepEqual(errors,[]);await browser.close();
    process.stdout.write('Browser operating-day checks passed: Cairo time across six, month/year changes, daylight saving, rollover event, one persistent clock and archived offline pages.\n');
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exit(1);});
