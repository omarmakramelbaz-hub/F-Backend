const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const code=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/dashboard-print.js'));
const server=http.createServer((req,res)=>{if(req.url==='/print.js'){res.setHeader('content-type','text/javascript');res.end(code);}else{res.setHeader('content-type','text/html');res.end('<!doctype html><html lang="ar"><head><meta charset="utf-8"></head><body><h1>Dashboard</h1><script src="/print.js"></script></body></html>');}});
(async()=>{await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));let browser;
  try{browser=await chromium.launch({headless:true,executablePath:process.env.POS_BROWSER_EXECUTABLE||undefined,args:['--no-sandbox','--disable-dev-shm-usage']});
    const page=await browser.newPage(),calls=[],errors=[];let reject=false;page.on('pageerror',error=>errors.push(error.message));
    await page.exposeBinding('nativePrint',async(_source,url)=>{calls.push(url);await new Promise(resolve=>setTimeout(resolve,50));if(reject)throw Error('طابعة غير متاحة');return true;});
    await page.addInitScript(()=>{window.FasakhanstaDesktop={printReceipt:url=>window.nativePrint(url)};});
    await page.goto('http://127.0.0.1:'+server.address().port+'/admin/dashboard');
    await page.evaluate(()=>Promise.all([DashboardPrint.print('/admin/takeaway/1/print'),DashboardPrint.print('/admin/takeaway/1/print'),DashboardPrint.print('/admin/branch-expenses/2/print')]));
    assert.equal(calls.length,2);assert.ok(calls.every(url=>url.includes('dashboard_print=1')));
    assert.equal(await page.locator('iframe').count(),0);
    const denied=await page.evaluate(()=>DashboardPrint.print('https://example.com/admin/receipt').then(()=>false,()=>true));assert.ok(denied);assert.equal(calls.length,2);
    reject=true;const error=await page.evaluate(()=>DashboardPrint.print('/admin/takeaway/3/print').then(()=>'',error=>error.message));assert.match(error,/طابعة غير متاحة/);
    reject=false;await page.evaluate(()=>DashboardPrint.print('/admin/takeaway/3/print'));assert.equal(calls.length,4);assert.equal(errors.length,0);
    console.log('PASS dashboard print UI: native printer, duplicate suppression, queued receipts, denied external URL and retry after printer failure');
  }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
