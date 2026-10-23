const fs=require('node:fs'),assert=require('node:assert/strict'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const old=path.resolve(__dirname,'../..'),dir=old;
const strip=s=>s.replace(/\{\{--[\s\S]*?--\}\}/g,'');
const hud=strip(fs.readFileSync(old+'/resources/views/admin/home_hud.blade.php','utf8')).replace(/data-intro="[^"]*"/,'data-intro="0"').replace("@include('admin.home_hud_solar')",strip(fs.readFileSync(dir+'/resources/views/admin/home_hud_solar.blade.php','utf8')));
let css=fs.readFileSync(dir+'/public/dashboard/css/home-hud.css','utf8');
const font=path.join(dir,'public/dashboard/vendor/desktop-external/fonts.gstatic.com/s/almarai/v19/tsstApxBaigK_hnnQ1iFo0C3.woff2');
if(fs.existsSync(font))css+='@font-face{font-family:Almarai;src:url(data:font/woff2;base64,'+fs.readFileSync(font).toString('base64')+')}body{font-family:Almarai,sans-serif}';
const js=fs.readFileSync(dir+'/public/dashboard/js/home-hud.js','utf8');
const initial={branch_home:false,filters:{period:'today'},operating_day:{date:'2026-10-24'},modules:{inventory:true,expenses:true,app:true},active:{new:7},channels:[{key:"takeaway",count:3},{key:"app",count:1},{key:"dine",count:0},{key:"phone",count:0}],selected_branches:1,open_branches:1,can_view_financials:true,sales:{gross_cents:158000},owner_drawer:{ready:true,total_cents:940500},today_expenses:{total_cents:10000},customers:{total:43200},owner_platform:{go_stores:2,go_partners:3,pending_total:1}};
const links={'active':'/admin/branch-orders','branches':'/admin/branch-orders','go-stores':'/admin/go-stores','go-partners':'/admin/users?account_type=delegate','pending':'/admin/pending_vendors','employees':'/admin/employees'};
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE}:{})});try{
 for(const reducedMotion of ['no-preference','reduce']){
 const page=await browser.newPage({viewport:{width:1440,height:900},reducedMotion}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.setContent('<style>'+css+'</style><main id="original"><input value="unchanged"></main>'+hud+'<script id="home-overview-bootstrap" type="application/json">'+JSON.stringify({initial,hud_links:links})+'</script>');await page.addScriptTag({content:js});await page.locator('#go-hud.ready').waitFor();
 assert.equal(await page.locator('.hud-solar-far [data-solar-planet]').count(),8);assert.equal(await page.locator('.hud-solar-near [data-solar-planet]').count(),0);assert.deepEqual(await page.evaluate(()=>['.hud-solar-far','.hud-sun','.hud-solar-near'].map(s=>getComputedStyle(document.querySelector(s)).zIndex)),['0','1','2']);assert.equal(await page.locator('.hud-logo').getAttribute('fill'),'url(#go-hud-mark-light)');
 for(const [width,height] of [[1440,900],[500,998],[320,600],[375,667],[759,600],[899,700],[900,700],[1024,768],[1279,720],[1280,720],[1366,600],[1366,500],[500,400],[1920,1080],[500,998],[1440,900]]){
 await page.setViewportSize({width,height});await page.waitForTimeout(reducedMotion==='reduce'?30:100);
 const result=await page.evaluate(()=>{
 const root=document.querySelector('#go-hud'),sun=root.querySelector('.hud-sun'),title=root.querySelector('.hud-title'),nodes=[...root.querySelectorAll('.hud-node')],checks=root.querySelector('.hud-checks');
 const rect=n=>{const r=n.getBoundingClientRect();return {l:r.left,r:r.right,t:r.top,b:r.bottom}};
 const bad=[];const overlap=(a,b)=>Math.min(a.r,b.r)-Math.max(a.l,b.l)>1&&Math.min(a.b,b.b)-Math.max(a.t,b.t)>1;
 const rects=nodes.map(n=>{const dot=rect(n.querySelector('.hud-dot')),tag=rect(n.querySelector('.hud-tag'));return {l:Math.min(dot.l,tag.l),r:Math.max(dot.r,tag.r),t:Math.min(dot.t,tag.t),b:Math.max(dot.b,tag.b)}});
 for(let i=0;i<nodes.length;i++){const r=rects[i];if(r.l<0||r.r>root.clientWidth+1)bad.push('node outside '+nodes[i].dataset.key);if(overlap(r,rect(checks)))bad.push('checks overlap '+nodes[i].dataset.key);for(let j=i+1;j<nodes.length;j++)if(overlap(r,rects[j]))bad.push('node collision '+i+'/'+j);}
 if(overlap(rect(sun),rect(title)))bad.push('sun/title overlap');
 if(root.classList.contains('compact')){const nr=rect(root.querySelector('.hud-nodes'));if(rect(sun).b>nr.t+1)bad.push('sun/tiles overlap');for(const n of nodes){if(n.scrollWidth>n.clientWidth+1)bad.push('tile text overflow '+n.dataset.key);}}
 if(root.scrollWidth>root.clientWidth+1)bad.push('horizontal overflow');
 return {bad,mode:root.className,nodes:nodes.length,rects};});
 assert.deepEqual(result.bad,[],JSON.stringify({width,height,reducedMotion,...result}));
 if(process.env.HUD_SCREENSHOT&&width===500&&height===998&&reducedMotion==='no-preference')await page.screenshot({path:process.env.HUD_SCREENSHOT});
 }
 await page.locator('#go-hud').getByRole('button',{name:'GO — الدخول على الرئيسية',exact:true}).click();await page.waitForFunction(()=>document.querySelector('#go-hud').hidden);assert.equal(await page.locator('#original input').inputValue(),'unchanged');assert.deepEqual(errors,[]);await page.close();
 }
 console.log('32 live viewport changes passed: no text/section collisions, no horizontal overflow, halo clear of date/tiles, reduced motion, GO preserves home.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
