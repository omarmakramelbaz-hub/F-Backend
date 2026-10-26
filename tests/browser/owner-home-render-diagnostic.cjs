'use strict';
// Genuine browser layout/ResizeObserver diagnostic. Synthetic account data only;
// no requests, business logic replacements, timing assertions or cached HTML.
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..'),read=name=>fs.readFileSync(path.join(root,name),'utf8');
const labels=Object.fromEntries([...read('resources/lang/ar/home_overview.php').matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(m=>[m[1],m[2]]));
const channels=['takeaway','dine','phone','app'];
const names=['فرع المحله','فرع شبرا','فرع نبروه','مصر الجديده','فرع المنصورة','فيلمه ستور','المقطم ستور','زايد ستور','التجمع ستور','مدينتي ستور'];
const branches=Array.from({length:32},(_,i)=>({value:'f:'+(100+i),name:names[i]||'فرع تجريبي '+(i+1)}));
const zeroChannels=()=>Object.fromEntries(channels.map(key=>[key,{gross_cents:0,count:0}]));
const initial={success:true,can_view_financials:true,selected_branches:32,filters:{branch:'',period:'today',from:'2026-10-26',to:'2026-10-26'},operating_day:{date:'2026-10-26'},updated_at:'2026-10-26T19:36:00Z',branches,
 owner_platform:{go_partners:0,go_stores:1,go_users:2,fasakhansta_users:11145,fasakhansta_stores:32,pending_total:0,pending_stores:0,pending_partners:0},
 owner_drawer:{ready:true,total_cents:1114462,branches:Object.fromEntries(branches.map((b,i)=>[b.value,{expected_cents:i===0?901462:i===4?213000:0}]))},
 modules:{pos:true,app:true,expenses:true,inventory:true},sales:{gross_cents:0,delivery_cents:0,expenses_cents:0,net_cents:0,channels:zeroChannels()},expenses_cents:0,completed:0,
 channels:channels.map(key=>({key,amount_cents:0,count:0})),
 branch_cards:branches.map(b=>({...b,completed:0,expenses_cents:0,order_counts:Object.fromEntries(channels.map(k=>[k,0])),sales:{gross_cents:0,channels:zeroChannels()}})),
 inventory:{tracked:35,items:Array.from({length:35},(_,i)=>({id:i+1,name:['فسيخ كيلو ٤ سمكات','رنجة بطارخ','زيت سمينة','خل طبيعي','سردين بلدي'][i%5],quantity:String(i%2?-i:132.75),unit:i%4?'kg':'piece',tracked_branches:32,negative_branches:i%2?1:0,balances:Object.fromEntries(branches.map(b=>[b.value,{quantity:String(i%2?-i:132.75),quantity_units:1000000,is_low:false}]))}))},
 ranking:branches.slice(0,10).map((b,i)=>({...b,amount_cents:0,count:0,sales_rank:i+1})),trend:Array.from({length:24},(_,i)=>({label:String((i+6)%24).padStart(2,'0')+':00',amount_cents:0,count:0}))};
const boot={locale:'ar',labels,url:'/admin/dashboard/overview',initial};
function ownerHtml(){
 let partial=read('resources/views/admin/home_owner.blade.php').replace(/^@php[^\n]*\n/,'');
 partial=partial.replace(/@foreach\(\['takeaway','dine','phone','app','total'\] as \$channel\)([\s\S]*?)@endforeach/g,(_,body)=>channels.concat('total').map(key=>body.replace(/{{\s*__\('home_overview\.'\.\$channel\)\s*}}/g,labels[key])).join(''));
 partial=partial.replace("@if(isset($boot['initial']['owner_drawer']))",'').replace(/@endif/g,'');
 let main=read('resources/views/admin/home.blade.php').split("@section('content')")[1].split('@endsection')[0];
 main=main.replace("@include('admin.home_hud')",'');
 const open=main.indexOf("@if(isset($boot['initial']['owner_platform']))"),otherwise=main.indexOf('@else',open),close=main.indexOf('@endif',otherwise);
 assert(open>=0&&otherwise>open&&close>otherwise);
 main=main.slice(0,open)+partial+main.slice(close+6);
 main=main.replace(/@foreach\(\$boot\['initial'\]\['branches'\] as \$branch\)([\s\S]*?)@endforeach/g,(_,body)=>branches.map(b=>body.replace(/{{\s*\$branch\['value'\]\s*}}/g,b.value).replace(/{{\s*\$branch\['name'\]\s*}}/g,b.name)).join(''));
 main=main.replace(/@foreach\(\['today','week','month','custom'\] as \$period\)([\s\S]*?)@endforeach/g,(_,body)=>['today','week','month','custom'].map(p=>body.replace(/{{\s*\$period\s*}}/g,p).replace(/{{\s*__\('home_overview\.'\.\$period\)\s*}}/g,labels[p])).join(''));
 main=main.replace("@if(isset($boot['initial']['owner_platform']))",'').replace("@if($boot['initial']['can_view_financials'] || !isset($boot['initial']['owner_platform']))",'').replace(/@endif/g,'');
 main=main.replace(/{{\s*isset\(\$boot\['initial'\]\['owner_platform'\]\)\?' ho-compact':''\s*}}/g,' ho-compact')
  .replace(/{{\s*app\(\)->getLocale\(\)==='ar'\?'rtl':'ltr'\s*}}/g,'rtl')
  .replace(/{{\s*\$financial\s*\?\s*''\s*:\s*' ho-admin-overview'\s*}}/g,'')
  .replace(/{{\s*__\([^}]*?'home_overview\.([^']+)'[^}]*\)\s*}}/g,(_,key)=>labels[key]||key)
  .replace(/{{\s*route\('[^']+'\)\s*}}/g,'/admin/branch-stock');
 assert(!/@(?:if|else|foreach|endif|php|include)|{{/.test(main),'Only real Blade control/translation substitutions are permitted.');
 return main;
}
const shell='*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;font-size:13px;background:#f5f7fa}.main-header{height:58px;margin:0!important}.main-footer{height:51px;margin:0!important;padding:15px}.content-wrapper{margin-right:250px!important;margin-left:0!important;min-height:0}.sidebar{position:fixed;right:0;top:0;bottom:0;width:250px;background:#091727}@media(max-width:1099px){.sidebar{display:none}.content-wrapper{margin-right:0!important}}';
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.DESKTOP_TEST_BROWSER_EXECUTABLE?{executablePath:process.env.DESKTOP_TEST_BROWSER_EXECUTABLE}:{})});
 const errors=[];const measurements=[];
 try{
  const page=await browser.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',route=>route.request().isNavigationRequest()
   ?route.fulfill({contentType:'text/html',body:'<!doctype html><html><body></body></html>'}):route.abort());
  await page.goto('http://owner-layout.fixture.test/admin/dashboard');
  await page.setViewportSize({width:1920,height:1080});
  await page.setContent('<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>'+read('public/dashboard/dist/css/bootstrap.rtl.min.css')+'\n'+read('public/dashboard/dist/css/adminlte.min.css')+'\n'+shell+'\n'+read('public/dashboard/css/home-overview.css')+'</style></head><body><header class="main-header">GO ERP</header><aside class="sidebar"></aside>'+ownerHtml()+'<footer class="main-footer">Copyright GO ERP</footer><script id="home-overview-bootstrap" type="application/json">'+JSON.stringify(boot)+'</script></body></html>');
  await page.evaluate(()=>{
   window.measure={replacements:0,ownerFrames:0,resizeCallbacks:0,resizeSamples:[]};
   const replace=Element.prototype.replaceChildren;
   Element.prototype.replaceChildren=function(...args){if(this.matches('[data-ho-chart]'))measure.replacements++;return replace.apply(this,args);};
   const raf=window.requestAnimationFrame;
   window.requestAnimationFrame=callback=>raf.call(window,time=>{if(callback.name==='sizeOwner')measure.ownerFrames++;callback(time);});
   const RealObserver=window.ResizeObserver;
   window.ResizeObserver=class extends RealObserver{constructor(callback){super((entries,observer)=>{measure.resizeCallbacks++;if(measure.resizeSamples.length<12)measure.resizeSamples.push(entries.map(e=>({name:e.target.getAttribute('data-ho-chart')!==null?'chart':'list',width:e.contentRect.width,height:e.contentRect.height})));callback(entries,observer);});}};
   window.fetch=async()=>{throw Error('No network reads in fixed-layout diagnostic');};
  });
  await page.addScriptTag({content:read('public/dashboard/js/home-overview.js')});
  for(const viewport of [{width:1920,height:1080},{width:1440,height:900},{width:1366,height:768},{width:1100,height:700},{width:1024,height:768},{width:390,height:844}]){
   await page.setViewportSize(viewport);await page.waitForTimeout(400);
   const before=await page.evaluate(()=>({...measure}));
   await page.waitForTimeout(1000);
   const after=await page.evaluate(()=>{const chart=document.querySelector('[data-ho-chart]'),box=chart.getBoundingClientRect(),svg=chart.querySelector('svg');return{...measure,chart:{width:box.width,height:box.height,svgViewBox:svg.getAttribute('viewBox')},rows:document.querySelector('[data-ho-branch-sales]').children.length,trendRows:document.querySelector('[data-ho-trend-data]').children.length};});
   assert.equal(after.rows,32);assert.equal(after.trendRows,24);
   measurements.push({viewport,idleChartReplacements:after.replacements-before.replacements,idleOwnerFrames:after.ownerFrames-before.ownerFrames,idleResizeCallbacks:after.resizeCallbacks-before.resizeCallbacks,totalChartReplacements:after.replacements,chart:after.chart,resizeSamples:after.resizeSamples});
  }
  assert.deepEqual(errors,[]);
  console.log('GO_ERP_OWNER_HOME_LAYOUT_DIAGNOSTIC '+JSON.stringify({sourceRevision:process.env.GITHUB_SHA||'local',genuineChromium:true,browserVersion:browser.version(),browserExecutable:process.env.DESKTOP_TEST_BROWSER_EXECUTABLE||'pinned-playwright-headless-shell',syntheticAccountData:true,actualBladeStructure:true,actualCss:true,actualHomeScript:true,financialOwner:true,branches:32,trendPoints:24,measurements}));
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
