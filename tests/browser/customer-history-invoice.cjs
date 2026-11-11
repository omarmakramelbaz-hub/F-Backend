'use strict';
// The customer's order history: «تفاصيل الفاتورة» beside an order opens its invoice in the invoice viewer (as the invoice review does).
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const source=path.resolve(__dirname,'../..');
(async()=>{
 const browser=await chromium.launch();const page=await browser.newPage();const opened=[];
 const feed={success:true,name:'عمرو',phone:'01064464499',summary:{count:2,paid_total:'1.00',last:'2026-11-09 17:47'},orders:[
  {at:'2026-11-09 17:47',channel:'تيك أواي',branch:'فرع المحلة',number:'R-995',total:'1.00',status:'مدفوع',details_url:'/admin/takeaway/receipts/995/details'},
  {at:'2026-11-08 19:29',channel:'دليفري',branch:'فرع المنصورة',number:'R-814',total:'460.00',status:'مدفوع',details_url:null}]};
 await page.route('http://history.test/**',route=>{const url=new URL(route.request().url());
  if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="ar"><body><main id="phone-orders"></main></body></html>'});
  if(url.pathname==='/history')return route.fulfill({json:feed});
  opened.push(url.pathname);
  return route.fulfill({contentType:'text/html',body:'<!doctype html><html data-dashboard-invoice-details="1"><body><h1>فاتورة R-995</h1><script>parent.hacked=1</script></body></html>'});});
 try{
  await page.goto('http://history.test/');
  await page.addScriptTag({path:path.join(source,'public/dashboard/js/dashboard-invoice-details.js')});
  await page.evaluate(code=>{const s=document.createElement('script');s.dataset.historyUrl='/history';s.textContent=code;document.body.appendChild(s);},fs.readFileSync(path.join(source,'public/dashboard/js/dashboard-customer-history.js'),'utf8'));
  await page.evaluate(()=>window.DashboardCustomerHistory.open('01064464499'));
  await page.waitForSelector('.go-history tbody tr');
  assert.equal(await page.locator('.go-history-details').count(),1,'only an order with an invoice has the button');
  assert.equal(await page.locator('.go-history-details').textContent(),'تفاصيل الفاتورة');
  await page.locator('.go-history tbody tr').first().locator('td').first().click();
  assert.equal(await page.locator('dialog.dashboard-invoice-details').count(),0,'the row itself opens nothing');
  await page.locator('.go-history-details').click();
  const frame=page.locator('dialog.dashboard-invoice-details iframe');await frame.waitFor();
  assert.deepEqual(opened,['/admin/takeaway/receipts/995/details']);
  assert.ok((await frame.getAttribute('srcdoc')).includes('فاتورة R-995'));
  assert.equal(await page.evaluate(()=>window.hacked),undefined,'the invoice runs no script');
  // Closing the invoice goes back to the history, still open.
  await page.locator('dialog.dashboard-invoice-details button').click();
  assert.equal(await page.locator('dialog.dashboard-invoice-details').count(),0);
  assert.ok(await page.locator('dialog.go-history').evaluate(d=>d.open));
  // Keyboard: Enter on the button opens it too.
  await page.locator('.go-history-details').focus();await page.keyboard.press('Enter');
  await page.locator('dialog.dashboard-invoice-details iframe').waitFor();
  console.log('Browser checks passed: the «تفاصيل الفاتورة» button beside an order opens its invoice in the invoice viewer (click and Enter), without scripts, and back to the history.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
