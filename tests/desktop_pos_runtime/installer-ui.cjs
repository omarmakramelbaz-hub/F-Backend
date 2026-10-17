// npm install --no-save playwright; npx playwright install chromium; node installer-ui.cjs
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.join(__dirname,'../..'),bytes=524288+53,id='a'.repeat(64);
const fixture=(other=false)=>`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"><script src="/dashboard/js/dashboard-spa.js"></script></head><body><header class="main-header"></header><div data-dashboard-page class="content-wrapper"><h1>${other?'صفحة أخرى':'برنامج الكمبيوتر'}</h1><a id="navigate" href="/admin/${other?'desktop-pos':'other'}">انتقال</a>${other?'':`<form data-spa-off data-desktop-installer-upload data-start="/admin/desktop-pos/upload/start" data-chunk="/admin/desktop-pos/upload/chunk" data-finish="/admin/desktop-pos/upload/finish" data-bytes="${bytes}"><input name="_token" type="hidden" value="test-csrf"><input type="file" required><button type="submit">رفع البرنامج</button><progress max="100" value="0" hidden></progress><p data-upload-status></p><a data-upload-download hidden>تحميل برنامج ويندوز</a></form>`}</div><div data-dashboard-page-scripts hidden>${other?'':'<script src="/dashboard/js/desktop-installer-upload.js" data-dashboard-page-init></script>'}</div></body></html>`;
let calls=[],starts=0,chunks=0,finish=0,failCompletion=false;
const server=http.createServer(async(req,res)=>{
    if(req.url.startsWith('/dashboard/js/')){res.setHeader('Content-Type','text/javascript');res.end(fs.readFileSync(path.join(root,'public',req.url)));return;}
    if(req.method==='GET'){res.setHeader('Content-Type','text/html');res.end(fixture(req.url==='/admin/other'));return;}
    try {
        assert.equal(req.headers['x-csrf-token'],'test-csrf');
        assert.equal(req.headers.accept,'application/json');
        const parts=[];for await(const part of req)parts.push(part);const body=Buffer.concat(parts).toString('latin1');
        res.setHeader('Content-Type','application/json');
        if(req.url.endsWith('/start')){starts++;res.end(JSON.stringify({upload_id:id,chunk_bytes:524288}));return;}
        assert.ok(body.includes(id));
        if(req.url.endsWith('/chunk')){
            chunks++;const offset=Number(body.match(/name="offset"\r\n\r\n(\d+)/)[1]);calls.push(offset);
            // First response is lost after the server has accepted the chunk.
            if(chunks===1){res.writeHead(503);res.end('{}');return;}
            res.end(JSON.stringify({offset:Math.min(bytes,offset+524288)}));return;
        }
        if(req.url.endsWith('/finish')){finish++;if(failCompletion){res.writeHead(422);res.end(JSON.stringify({message:'الملف مختلف عن النسخة المعتمدة.'}));return;}
            res.end(JSON.stringify({download_url:'/admin/desktop-pos/download'}));return;}
        throw new Error('Unexpected request '+req.url);
    }catch(error){res.writeHead(500);res.end(JSON.stringify({message:error.message}));}
});
(async()=>{
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));let browser;
    try{
        browser=await chromium.launch({headless:true,executablePath:process.env.POS_BROWSER_EXECUTABLE||undefined,args:['--no-sandbox','--disable-dev-shm-usage']});
        const page=await browser.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));
        const origin='http://127.0.0.1:'+server.address().port;
        await page.goto(origin+'/admin/desktop-pos');
        await page.locator('input[type=file]').setInputFiles({name:'Fasakhansta-POS-Setup.exe',mimeType:'application/octet-stream',buffer:Buffer.alloc(bytes)});
        await page.getByRole('button',{name:'رفع البرنامج'}).click();
        await page.waitForFunction(()=>document.querySelector('[data-upload-status]').textContent.includes('إعادة المحاولة'));
        assert.ok(await page.getByRole('button',{name:'رفع البرنامج'}).isDisabled());
        await page.locator('#navigate').click();assert.equal(new URL(page.url()).pathname,'/admin/desktop-pos');
        await page.waitForFunction(()=>document.querySelector('[data-upload-status]').textContent.includes('بنجاح'));
        assert.deepEqual(calls,[0,0,524288]);assert.equal(starts,1);assert.equal(finish,1);
        assert.equal(await page.locator('progress').evaluate(el=>el.value),100);
        assert.ok(await page.locator('[data-upload-download]').isVisible());
        assert.equal(await page.locator('[data-upload-download]').getAttribute('href'),'/admin/desktop-pos/download');
        assert.ok(await page.getByRole('button',{name:'رفع البرنامج'}).isEnabled());
        console.log('PASS UI: CSRF, progress, chunk retry, navigation guard and available download');
        await page.locator('#navigate').click();await page.waitForURL('**/admin/other',{timeout:10000});
        await page.locator('#navigate').click();await page.waitForURL('**/admin/desktop-pos',{timeout:10000});
        await page.locator('input[type=file]').setInputFiles({name:'wrong.exe',mimeType:'application/octet-stream',buffer:Buffer.alloc(3)});
        await page.getByRole('button',{name:'رفع البرنامج'}).click();assert.equal(starts,1);
        assert.match(await page.locator('[data-upload-status]').innerText(),/اختر ملف/);
        failCompletion=true;
        await page.locator('input[type=file]').setInputFiles({name:'Fasakhansta-POS-Setup.exe',mimeType:'application/octet-stream',buffer:Buffer.alloc(bytes)});
        await page.getByRole('button',{name:'رفع البرنامج'}).click();
        await page.waitForFunction(()=>document.querySelector('[data-upload-status]').textContent.includes('مختلف'));
        assert.ok(await page.locator('[data-upload-download]').isHidden());assert.equal(starts,2);assert.equal(finish,2);
        assert.ok(await page.getByRole('button',{name:'رفع البرنامج'}).isEnabled());assert.equal(errors.length,0,errors.join('; '));
        console.log('PASS UI: dashboard SPA return binds uploader, wrong size stops before upload, integrity failure stays unavailable');
    }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
