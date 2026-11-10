'use strict';
// Production login markup/handlers with fixture-only CSRF, account and POST endpoint.
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const assert=require('node:assert/strict'),crypto=require('node:crypto'),fs=require('node:fs'),http=require('node:http'),path=require('node:path');
const repo=path.resolve(__dirname,'../..'),read=p=>fs.readFileSync(path.join(repo,p),'utf8');
const source=read('resources/views/admin/login.blade.php'),posts=[];
const hash=s=>crypto.createHash('sha256').update(s).digest('hex');
assert.equal(hash(source.match(/<form class="dashboard-login-form"[\s\S]*?<\/form>/)[0]),'c4ece33b00e7e806a721f137fbe135b0d808486390f46834f9aa7f116612c0eb','Login form/action/CSRF/fields must remain byte-identical to the reviewed baseline');
assert.equal(hash(source.match(/<script>\s*\(function \(\) \{[\s\S]*?<\/script>/)[0]),'2c80f8d45949e367ee3ae3e944550c84495554b96f2027f5c275457b743de222','Existing password, storage and remember handling must remain byte-identical');
const labels={'main.sign in to dashboard':'الدخول إلى لوحة التحكم','main.enter password':'أدخل كلمة المرور','main.remember me':'تذكرني','main.login':'تسجيل الدخول'};
function html(url){
 const english=url.searchParams.has('en');
 let s=source.replace(/^<html[^\n]*$/m,'<html lang="'+(english?'en':'ar')+'" dir="'+(english?'ltr':'rtl')+'">');
 s=s.replace(/@php[\s\S]*?@endphp/g,'').replace(/@if\(!config\('desktop_dashboard.local'\)\)[\s\S]*?@endif/g,'');
 s=s.replace(/<link[^\n]*App\\Services\\Dashboard\\DesktopDashboardAssets[^\n]*\n/g,'');
 s=s.replace(/@if\(count\(\$errors\)\)[\s\S]*?@endif/g,'').replace(/@if\(Session::has\('(?:error|success)'\)\)[\s\S]*?@endif/g,'');
 s=s.replace(/\{\{\s*\$isArabic\s*\?\s*'([^']*)'\s*:\s*'([^']*)'\s*\}\}/g,(_,ar,en)=>english?en:ar);
 s=s.replace(/@include\('admin.partials.go_hud_mark'\)/g,read('resources/views/admin/partials/go_hud_mark.blade.php'));
 s=s.replace(/\{\{--[\s\S]*?--\}\}/g,'').replace(/\{\{\s*asset\('([^']+)'\)\s*\}\}/g,(_,p)=>'/'+p).replace(/\{\{\s*url\('dashboard'\)\s*\}\}/g,'/dashboard');
 s=s.replace(/\{\{\s*\$settings->site_name\s*\}\}/g,'GO ERP').replace(/\{\{\s*route\('admin.login'\)\s*\}\}/g,'/admin/login').replace(/\{\{\s*old\('email'\)\s*\}\}/g,'');
 s=s.replace(/\{\{\s*old\('remember'\)\s*\?\s*'checked'\s*:\s*''\s*\}\}/g,'').replace(/@error\('[^']+'\)[\s\S]*?@enderror/g,'');
 s=s.replace(/@lang\('([^']+)'\)/g,(_,key)=>labels[key]||key).replace('@csrf','<input type="hidden" name="_token" value="fixture-csrf-token">');
 if(url.searchParams.has('unsupported'))s=s.replace(/<script src="[^\"]*\/go-hologram\.js[^\"]*"><\/script>/,'');
 if(url.searchParams.has('error'))s=s.replace('<form class="dashboard-login-form"','<div class="dashboard-login-alert dashboard-login-alert-error" role="alert">تعذر تسجيل الدخول. تحقق من بيانات الحساب ثم أعد المحاولة.</div><form class="dashboard-login-form"');
 assert(!/\{\{|@(?:if|else|end|php|lang|include|csrf)/.test(s),'Fixture must resolve known Blade values without replacing the authentication handler');
 return s;
}
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://fixture.invalid');
 if(req.method==='POST'&&url.pathname==='/admin/login'){
  let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{posts.push(body);res.setHeader('Content-Type','text/html; charset=utf-8');res.end('<!doctype html><html><body>Fixture login accepted</body></html>');});return;
 }
 if(url.pathname.startsWith('/dashboard/')){
  const file=path.resolve(repo,'public','.'+url.pathname),base=path.resolve(repo,'public/dashboard')+path.sep;
  if(!file.startsWith(base)||!fs.existsSync(file)){res.writeHead(404).end();return;}
  res.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':file.endsWith('.png')?'image/png':file.endsWith('.woff2')?'font/woff2':'application/octet-stream');res.end(fs.readFileSync(file));return;
 }
 res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html(url));
});
module.exports={html};
if(require.main===module)(async()=>{
 let checks=2;const errors=[],check=(v,m)=>{assert(v,m);checks++;},equal=(a,b,m)=>{assert.deepEqual(a,b,m);checks++;};
 const out=path.resolve(process.env.HOLOGRAM_LOGIN_OUTPUT||path.join(repo,'../analysis/login-hologram'));fs.mkdirSync(out,{recursive:true});
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox','--enable-unsafe-swiftshader']});
 const context=await browser.newContext({reducedMotion:'reduce',deviceScaleFactor:1});
 try{
  await context.addInitScript(()=>{
   window.loginSpeechCalls=0;
   window.Audio=function(){loginSpeechCalls++;throw Error('Login must stay silent');};
   const Recognition=function(){loginSpeechCalls++;throw Error('Login must never start a microphone');};
   Object.defineProperty(window,'SpeechRecognition',{configurable:true,value:Recognition});
   Object.defineProperty(window,'webkitSpeechRecognition',{configurable:true,value:Recognition});
   Object.defineProperty(window,'speechSynthesis',{configurable:true,value:{speak(){loginSpeechCalls++;throw Error('Login must not speak');}}});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  const origin='http://127.0.0.1:'+server.address().port;
  for(const[width,height]of[[1920,1080],[1366,768],[980,900],[820,1180],[700,900],[390,844],[320,568]]){
   await page.setViewportSize({width,height});await page.goto(origin+'/admin/login');
   await page.locator('[data-hologram-artwork]').evaluate(img=>img.decode());
   await page.waitForFunction(()=>document.querySelector('[data-go-login-hologram]').dataset.portraitGpu==='true');
   const layout=await page.evaluate(()=>{
    const box=e=>{const r=e.getBoundingClientRect();return{x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height};};
    const host=document.querySelector('[data-go-login-hologram]'),panel=document.querySelector('.dashboard-login-form-panel'),identity=document.querySelector('.dashboard-login-identity');
    return{root:document.documentElement.scrollWidth,viewport:innerWidth,panel:box(panel),portrait:box(identity),canvas:box(host.querySelector('canvas')),fields:[...panel.querySelectorAll('input:not([type=hidden]),button')].map(box),portraitReady:host.dataset.portraitGpu,border:getComputedStyle(panel).borderTopWidth,bodyBackground:getComputedStyle(document.body).backgroundColor,speech:loginSpeechCalls,diagnostics:host.goLoginHologram.diagnostics()};
   });
   check(layout.root<=width,'Login has no horizontal overflow at '+width);
   check(layout.panel.x>=0&&layout.panel.right<=width,'Form panel stays within viewport at '+width);
   check(layout.fields.every(b=>b.x>=layout.panel.x&&b.right<=layout.panel.right+1),'All authentication controls fit their panel at '+width);
   check(layout.canvas.width>0&&layout.canvas.height>0&&layout.portraitReady==='true','New living portrait mounts on login at '+width);
   equal(layout.border,'1px','Login panel preserves the reference outline');
   equal(layout.speech,0,'Login portrait stays silent and starts no microphone');
   if(width>800)check(layout.portrait.right<layout.panel.x+1,'Desktop layout keeps the large portrait left and form right');
   else check(layout.portrait.bottom<=layout.panel.y+1,'Narrow screens stack the avatar above the usable form');
   check(layout.diagnostics&&layout.diagnostics.artwork,'Production login uses the approved portrait renderer');
   equal(await page.locator('.dashboard-login-brand,.go-erp-platform-logo').count(),0,'Old solar/large logo identity is replaced');
   if(width===1920||width===390)await page.screenshot({path:path.join(out,'login-'+width+'x'+height+'.png'),fullPage:true,animations:'disabled'});
   if(width===1920&&process.env.HOLOGRAM_REVIEW_LOG_IMAGE==='1'){
    const jpeg=await page.screenshot({type:'jpeg',quality:65,scale:'css'}),data=jpeg.toString('base64'),size=4000,total=Math.ceil(data.length/size);
    for(let i=0;i<total;i++)console.log('GO_LOGIN_REVIEW_IMAGE '+(i+1)+'/'+total+' '+data.slice(i*size,(i+1)*size));
   }
  }
  equal(posts.length,0,'Presentation and animation never submit authentication automatically');
  await page.setViewportSize({width:1366,height:768});await page.goto(origin+'/admin/login?en=1&error=1');
  equal(await page.locator('.dashboard-login-form-panel').evaluate(n=>getComputedStyle(n).direction),'ltr','English login keeps its form direction');
  check(await page.locator('[role=alert]').isVisible(),'Server validation feedback stays visible');
  await page.goto(origin+'/admin/login');
  await page.locator('[data-go-login-hologram]').evaluate(n=>n.querySelector('img').decode());
  await page.waitForFunction(()=>document.querySelector('[data-go-login-hologram]').dataset.portraitGpu==='true');
  await page.evaluate(()=>document.dispatchEvent(new PointerEvent('pointermove',{clientX:innerWidth*2,clientY:-20,pointerType:'mouse'})));
  equal(await page.locator('[data-go-login-canvas]').evaluate(c=>[c.__goHologram.gazeX,c.__goHologram.gazeY]),[1,-1],'Login pointer gaze is bounded');
  await page.evaluate(()=>document.dispatchEvent(new Event('pointerleave')));
  equal(await page.locator('[data-go-login-canvas]').evaluate(c=>[c.__goHologram.gazeX,c.__goHologram.gazeY]),[0,0],'Leaving login restores neutral gaze');
  await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true})));
  equal(await page.locator('[data-go-login-canvas]').evaluate(c=>!!c.__goHologram),false,'Login pagehide releases GPU renderer');
  equal(await page.locator('[data-go-login-hologram]').evaluate(n=>n.goLoginHologram.diagnostics()),null,'Paused login exposes no live renderer');
  await page.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true})));
  await page.waitForFunction(()=>!!document.querySelector('[data-go-login-canvas]').__goHologram);
  check(await page.locator('[data-go-login-canvas]').evaluate(c=>!!c.__goHologram),'bfcache restoration remounts the login avatar');
  await page.goto(origin+'/admin/login?unsupported=1');
  check(await page.locator('[data-hologram-artwork]').isVisible(),'Unavailable WebGL keeps the approved image fallback');
  equal(await page.locator('[data-go-login-hologram]').evaluate(n=>n.dataset.portraitGpu||''),'','Fallback cannot hide the only visible portrait');
  await page.locator('.dashboard-login-submit').click();equal(posts.length,0,'Required fields still prevent empty submissions');
  await page.locator('#dashboard-login-email').fill('omar@example.invalid');await page.locator('#dashboard-login-password').fill('fixture-pass-123');
  await page.locator('[data-password-toggle]').click();equal(await page.locator('#dashboard-login-password').getAttribute('type'),'text','Existing password reveal remains functional');
  await page.locator('[data-password-toggle]').click();equal(await page.locator('#dashboard-login-password').getAttribute('type'),'password','Existing password hide remains functional');
  await page.locator('#remember').check();
  const form=await page.locator('[data-dashboard-login]').evaluate(n=>({method:n.method,action:n.action,token:n.querySelector('[name=_token]').value}));
  equal(form,{method:'post',action:origin+'/admin/login',token:'fixture-csrf-token'},'POST destination and CSRF contract are retained');
  await Promise.all([page.waitForURL(origin+'/admin/login'),page.locator('.dashboard-login-submit').click()]);
  await page.waitForFunction(()=>document.body.textContent.includes('Fixture login accepted'));
  equal(posts.length,1,'Explicit validated submit issues exactly one request');
  const submitted=new URLSearchParams(posts[0]);equal(Object.fromEntries(submitted),{_token:'fixture-csrf-token',email:'omar@example.invalid',password:'fixture-pass-123',remember:'1'},'Credentials, CSRF and remember reach the unchanged local fixture endpoint');
  equal(await page.evaluate(()=>localStorage.getItem('fasakhansta.dashboard.username')),'omar@example.invalid','Existing username persistence remains functional');
  equal(await page.evaluate(()=>Object.values(localStorage).includes('fixture-pass-123')),false,'Password is never persisted by the login presentation');
  equal(errors,[],'Login has no browser runtime errors');
  fs.writeFileSync(path.join(out,'result.json'),JSON.stringify({status:'GO_LOGIN_HOLOGRAM_PASS',checks,viewports:7,authContractUnchanged:true,silent:true},null,2));
  console.log(JSON.stringify({status:'GO_LOGIN_HOLOGRAM_PASS',checks,viewports:7,authContractUnchanged:true}));
 }finally{await context.close();await browser.close();await new Promise(r=>server.close(r));}
})().catch(error=>{console.error(error.stack||error);server.close();process.exitCode=1;});
