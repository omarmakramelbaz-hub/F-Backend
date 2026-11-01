'use strict';
// Real production opening markup/styles/controller, synthetic read-only data.
const {chromium} = require(process.env.DESKTOP_TEST_BROWSER_MODULE || 'playwright');
const http=require('node:http'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const repo=path.resolve(__dirname,'../..');
const read=name=>fs.readFileSync(path.join(repo,name),'utf8');
const labels={employees:'الموظفين والرواتب',customers:'العملاء',expenses:'المصروفات',drawer:'تقفيل الوردية',stock:'مخزون البضاعة','ch-app':'طلبات التطبيق','ch-takeaway':'تيك أواي','ch-phone':'دليفري','ch-dine':'صالة','pending-expenses':'مصروفات منتظرة'};
function metrics(owner=false){const data={scope:'all',scope_label:'جميع الفروع المصرّح بها',branch_count:4,updated_at:'2026-11-01T20:00:00+02:00',cards:Object.entries(labels).map(([key,label],i)=>({key,label,url:'/admin/'+key,count:i+1,count_label:key.startsWith('ch-')?'غير منتهية':'إجمالي',secondary_count:key==='stock'?4:key==='ch-app'?11374:null,secondary_label:key==='stock'?'وصلت للحد الأدنى':key==='ch-app'?'منتهية · جميع الفروع المصرّح بها':null,warning:key==='stock',available:true}))};if(owner)Object.assign(data.cards.find(c=>c.key==='drawer'),{label:'رصيد الفروع',url:'/admin/dashboard#ho-drawers-title',count:null,count_label:'ج.م',available:false,loading:true,value_type:'currency',currency:'EGP',amount_cents:null,action:'owner-balances',balance_scope:'all',balance_branches:['f:100','f:101'],balance_url:'/admin/dashboard/overview?branch=f%3A999&section=drawers'});return data;}
function finance(cents=-105,stamp='2026-11-01T20:01:00Z',keys=['f:100','f:101']){return{success:true,filters:{branch:''},updated_at:stamp,owner_drawer:{loaded:true,ready:true,total_cents:cents,updated_at:stamp,branches:Object.fromEntries(keys.map((key,i)=>[key,{expected_cents:i===0?cents:0}]))}};}
function namedReady(){return{state:'ready',status_url:'/admin/dashboard/greeting/status/'+ 'a'.repeat(64),url:'/admin/dashboard/greeting/audio/'+ 'a'.repeat(64),poll_after_ms:1500,expires_at:Math.floor(Date.now()/1000)+1800,envelope:{sample_rate:50,values:[0,.1,.85,.4,0]}};}
function html(url){
 if(url.searchParams.has('robotonly')) return `<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#010b14}canvas{display:block;width:100vw;height:100vh}</style></head><body><canvas data-hud-robot aria-hidden="true"></canvas><script src="/dashboard/js/go-hologram.js"></script><script>window.motionRobot=GoHologram.mount(document.querySelector('canvas'),{background:true,fullViewport:true,interactive:false,adaptiveParticles:false});</script></body></html>`;
 let partial=read('resources/views/admin/home_hud.blade.php').replace(/\{\{--[\s\S]*?--\}\}/g,'').replace(/\{\{\s*!empty\([^}]+\}\}/g,'1').replace(/\{\{\s*auth\('admin'\)->user\(\)->name\s*\}\}/g,'عمر');
 partial=partial.replace("@include('admin.partials.go_hud_mark')",read('resources/views/admin/partials/go_hud_mark.blade.php'));
 // Resolve Blade asset helpers in shared production markup.
 partial=partial.replace(/\{\{\s*asset\('([^']+)'\)\s*\}\}/g,(_,p)=>'/'+p);
 partial=partial.replace(/\{\{--[\s\S]*?--\}\}/g,'');
 assert(!/@(?:include|if|php)|\{\{/.test(partial),'Fixture must use production markup with only Blade values substituted');
 const metric=metrics(url.searchParams.has('owner'));
 if(url.searchParams.has('unsafe')){metric.cards[0].url='javascript:window.bad=true';metric.cards[1].url='https://foreign.invalid/steal';metric.cards[2].label='<img src=x onerror="window.bad=true">';}
 const greeting=url.searchParams.has('silent')?null:{nonce:url.searchParams.get('nonce')||'login-one',name:'Omar',audio:{url:'/dashboard/audio/go-welcome-omar.mp3',envelope:{sample_rate:50,values:[0,.1,.85,.4,0]}}};
 if(greeting&&url.searchParams.has('named')){greeting.name='Mariam Hassan';greeting.audio=namedReady();if(url.searchParams.get('named')==='pending'){delete greeting.audio.url;delete greeting.audio.envelope;greeting.audio.state='pending';}}
 const boot={initial:{hologram:metric},url:'/admin/dashboard/overview?branch=f%3A999',intro:true,greeting,hud_links:{}};
 let sidebar=read('resources/views/admin/partials/go_hologram_sidebar.blade.php').replace(/\{\{\s*asset\('([^']+)'\)\s*\}\}/g,(_,p)=>'/'+p).replace(/\{\{[^}]+\}\}/g,'/admin/dashboard');
 const real=url.searchParams.has('real'), spa=url.searchParams.has('spa');
 const cachedRefusal=url.searchParams.has('cachedrefused')?'<div id="home-overview"></div><script>document.getElementById("home-overview").goHomeDrawersState={auth_refused:true};</script>':'';
 return `<!doctype html><html lang="ar"><head><meta charset="utf-8"><style>html,body{margin:0;font-family:Arial,sans-serif}aside.main-sidebar{width:240px}#ho-title{margin:30px}${read('public/dashboard/branding/dashboard-brand.css')}\n${read('public/dashboard/css/dashboard-fit.css')}\n${read('public/dashboard/css/home-hud.css')}\n${read('public/dashboard/css/go-hologram.css')}</style></head><body class="dashboard-theme" data-dashboard-actor="1:admin"><aside id="dashboard-sidebar" class="main-sidebar sidebar-dark-primary"><div class="brand-link dashboard-brand">${sidebar}<span class="dashboard-brand-copy">GO</span></div></aside><h1 id="ho-title">Home</h1><main data-dashboard-page>${partial}</main><script type="application/json" id="home-overview-bootstrap">${JSON.stringify(boot).replace(/</g,'\\u003c')}</script>${cachedRefusal}${spa?'<script src="/dashboard/js/dashboard-spa.js"></script>':''}${real?'<script src="/dashboard/js/go-hologram.js"></script><script src="/dashboard/js/go-hologram-shell.js"></script>':''}<script data-dashboard-page-init src="/dashboard/js/home-hud.js"></script></body></html>`;
}
const server=http.createServer((req,res)=>{const url=new URL(req.url,'http://fixture.invalid'); if(url.pathname.startsWith('/dashboard/')){const file=path.join(repo,'public',url.pathname); if(!file.startsWith(path.join(repo,'public/dashboard/'))||!fs.existsSync(file)){res.writeHead(404).end();return;}res.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':file.endsWith('.webp')?'image/webp':file.endsWith('.png')?'image/png':'application/octet-stream');res.end(fs.readFileSync(file));return;}res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html(url));});
let checks=0; function check(condition,message){assert(condition,message);checks++;}function equal(actual,expected,message){assert.deepEqual(actual,expected,message);checks++;}
module.exports={server,html,metrics,finance,namedReady};
async function focusedCases(context,origin,errors){
 const open=async query=>{const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.clock.pauseAt(await p.evaluate(()=>Date.now()+100));await p.goto(origin+'/fixture?'+query);await p.clock.runFor(1);return p;};
 const calls=async(p,part)=>p.evaluate(part=>fixture.calls.map((c,i)=>({i,url:c.url,aborted:c.aborted})).filter(c=>c.url.includes(part)),part);
 const reply=async(p,i,data,status=200)=>{await p.evaluate(({i,data,status})=>fixture.reply(i,data,status),{i,data,status});await p.evaluate(()=>Promise.resolve());};
 const emit=async(p,data)=>{await p.evaluate(data=>document.dispatchEvent(new CustomEvent('go:home-data',{detail:data})),data);};
 const money=p=>p.locator('[data-hud-key="drawer"] .hud-primary b').textContent();
 let p=await open('owner=1&silent=1');
 equal(await money(p),'—','Owner balance starts unknown instead of treating branch count as money');
 check((await p.locator('[data-hud-key="drawer"]').textContent()).includes('جار تحميل الرصيد'),'Owner has explicit currency loading state');
 let cash=await calls(p,'section=drawers');equal(cash.length,1,'Owner starts one independent canonical balance read');
 equal(cash[0].url,origin+'/admin/dashboard/overview?section=drawers','Owner finance read strips selected and foreign branch query filters');
 await reply(p,cash[0].i,finance());equal(await money(p),'-1.05','Negative integer cents render as an exact signed currency amount');
 equal(await p.locator('[data-hud-key="drawer"] .hud-primary b').getAttribute('dir'),'ltr','Signed currency keeps its minus sign before the amount in RTL layouts');
 equal(await p.locator('[data-hud-key="drawer"]').getAttribute('data-balance-state'),'ready','Confirmed money clears loading');
 const inconsistent=finance(999);inconsistent.owner_drawer.branches['f:100'].expected_cents=1;
 for(const invalid of [{...finance(999),filters:{branch:'f:100'}},finance(999,'2026-11-01T20:02:00Z',['f:100']),finance(999,'2026-11-01T20:02:00Z',['f:100','f:999']),finance(999,'2026-10-31T20:00:00Z'),finance(Number.MAX_SAFE_INTEGER+1),inconsistent]){
  await emit(p,invalid);equal(await money(p),'-1.05','Partial, foreign, older or unsafe financial payload never replaces Owner total');
 }
 await p.clock.runFor(44000);const retained=finance();retained.updated_at='2026-11-02T22:00:00Z';retained.hologram=metrics(true);await emit(p,retained);await p.clock.runFor(1100);
 cash=await calls(p,'section=drawers');equal(cash.length,2,'A newer overview timestamp cannot make retained finance fresh or postpone its45-second read');
 await reply(p,cash[1].i,{},503);equal(await money(p),'-1.05','Failed finance read retains the last confirmed signed total');
 equal(await p.locator('[data-hud-key="drawer"]').getAttribute('data-balance-state'),'stale','Failed finance read visibly marks retained amount stale');
 await p.clock.runFor(45000);cash=await calls(p,'section=drawers');await reply(p,cash.at(-1).i,finance());
 equal(await p.locator('[data-hud-key="drawer"]').getAttribute('data-balance-state'),'ready','Successful canonical retry clears stale even when balance and timestamp are identical');
 await p.clock.runFor(45000);cash=await calls(p,'section=drawers');await emit(p,finance(500,'2026-11-01T20:05:00Z'));await reply(p,cash.at(-1).i,finance(999,'2026-11-01T20:04:00Z'));
 equal(await money(p),'5.00','A slower finance GET cannot overwrite a newer authoritative event');equal(await p.locator('[data-hud-key="drawer"]').getAttribute('data-balance-state'),'ready','A superseded finance response does not falsely mark newer money stale');
 await p.clock.runFor(45000);cash=await calls(p,'section=drawers');await emit(p,finance(600,'2026-11-01T20:05:00Z'));await reply(p,cash.at(-1).i,finance(501,'2026-11-01T20:05:00Z'));equal(await money(p),'6.00','Same-second finance response cannot overwrite a newer event accepted during the request');
 await p.evaluate(()=>{fixture.ownerOpens=0;document.addEventListener('go:owner-drawers-open',()=>fixture.ownerOpens++);});await p.locator('[data-hud-key="drawer"]').dispatchEvent('click');
 equal(await p.locator('#go-hud').isVisible(),false,'Owner balance click leaves the opening');equal(await p.evaluate(()=>fixture.ownerOpens),1,'Owner click opens the existing balance-detail panel');await p.close();
 p=await open('owner=1&silent=1&ignoreabort=1');cash=await calls(p,'section=drawers');let newer=metrics(true);newer.cards.find(c=>c.key==='drawer').balance_branches=['f:100'];await emit(p,{hologram:newer});await p.clock.runFor(1);
 check((await calls(p,'section=drawers'))[0].aborted,'Changing authorized branch scope aborts the old finance request');await reply(p,cash[0].i,finance(999));equal(await money(p),'—','Late old-scope money cannot hydrate the replacement scope');cash=await calls(p,'section=drawers');await reply(p,cash.at(-1).i,finance(-1,'2026-11-01T20:06:00Z',['f:100']));equal(await money(p),'-0.01','One negative cent survives exact formatting');
 await p.evaluate(()=>document.body.dataset.dashboardActor='2:admin');await p.evaluate(()=>Promise.resolve());equal(await p.locator('[data-hud-key="drawer"]').count(),0,'Account switch removes the previous Owner monetary card');await p.close();
 p=await open('silent=1');equal(await calls(p,'section=drawers'),[],'Other Admin never issues an Owner finance read');equal(await p.locator('[data-hud-key="drawer"] .hud-card-label').textContent(),'تقفيل الوردية','Other Admin retains the original label');equal(await money(p),'4','Other Admin retains its original count');equal(await p.locator('[data-hud-key="drawer"]').getAttribute('href'),origin+'/admin/drawer','Other Admin retains its original destination');await p.close();

 p=await open('named=pending&v3delay=1&nonce=named-before-construction');let voice=await calls(p,'greeting/status');equal(voice.length,1,'Fresh named login polls one authenticated readiness ticket');equal(await p.evaluate(()=>fixture.plays),0,'Pending named audio is never substituted with a generic recording');
 await reply(p,voice[0].i,namedReady());await p.clock.runFor(6000);equal(await p.evaluate(()=>fixture.plays),0,'Ready named audio still waits for construction');await p.clock.runFor(300);equal(await p.evaluate(()=>fixture.plays),1,'Ready named recording starts once after construction');check((await p.evaluate(()=>fixture.audio[0].src)).includes('/greeting/audio/'),'Playback uses the authenticated named asset');await p.evaluate(()=>fixture.audio[0].currentTime=.04);await p.clock.runFor(40);equal(await p.evaluate(()=>fixture.mounted[0].voiceLevel),.85,'Named asset envelope drives mouth from actual playback time');await p.reload();await p.clock.runFor(1);equal(await calls(p,'greeting/status'),[],'Reload after named playback cannot re-arm readiness polling');equal(await p.evaluate(()=>fixture.plays),0,'Reload never repeats named audio');await p.close();
 p=await open('named=pending&v3delay=1&nonce=named-after-construction&delayed=1');voice=await calls(p,'greeting/status');await reply(p,voice[0].i,{...namedReady(),state:'pending',url:undefined,envelope:undefined});await p.clock.runFor(7000);equal(await p.evaluate(()=>fixture.plays),0,'Finished construction never plays a still-pending name');voice=await calls(p,'greeting/status');await reply(p,voice.at(-1).i,namedReady());await p.locator('[data-hud-robot]').dispatchEvent('pointerdown');await p.locator('[data-hud-robot]').dispatchEvent('pointerdown');equal(await p.evaluate(()=>fixture.plays),1,'Late readiness and repeated gestures queue only one player');await p.evaluate(()=>{const a=fixture.audio[0];a.onplaying?.();a.onplaying?.();});equal(await p.evaluate(()=>fixture.audio[0].paused),false,'Buffering or duplicate playing events cannot stop the successful named player');await p.close();
 p=await open('named=pending&v3delay=1&nonce=named-blocked-play&blocked=1');voice=await calls(p,'greeting/status');await reply(p,voice[0].i,namedReady());await p.clock.runFor(6300);equal(await p.locator('[data-hud-voice]').textContent(),'تشغيل الترحيب','Blocked named autoplay exposes a retry control');await p.locator('[data-hud-voice]').dispatchEvent('click');equal(await p.evaluate(()=>fixture.plays),2,'Named autoplay retry makes exactly one additional attempt');equal(await p.evaluate(()=>fixture.audio.at(-1).paused),false,'Named retry remains playing');equal(await p.evaluate(()=>fixture.speech.length),0,'Named autoplay never falls back to a system voice');await p.close();
 for(const stop of ['mute','leave','hidden','actor','root']){
  p=await open('named=pending&v3delay=1&ignoreabort=1&nonce=named-cancel-'+stop);voice=await calls(p,'greeting/status');
  if(stop==='mute'){check(await p.locator('[data-hud-voice]').isVisible(),'Pending welcome exposes a usable mute/cancel control');await p.locator('[data-hud-voice]').dispatchEvent('click');}
  else if(stop==='leave')await p.locator('[data-hud-enter]').dispatchEvent('click');
  else if(stop==='hidden')await p.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));});
  else if(stop==='actor')await p.evaluate(()=>document.body.dataset.dashboardActor='2:admin');
  else await p.evaluate(()=>document.querySelector('#go-hud').hidden=true);
  await p.evaluate(()=>Promise.resolve());check((await calls(p,'greeting/status'))[0].aborted,'Pending '+stop+' cancels its readiness request');await reply(p,voice[0].i,namedReady());await p.clock.runFor(7000);equal(await p.evaluate(()=>fixture.plays),0,'Late readiness after '+stop+' cannot speak or revive the opening');
  if(stop==='hidden'){await p.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:false});document.dispatchEvent(new Event('visibilitychange'));});await p.clock.runFor(1000);equal((await calls(p,'greeting/status')).length,1,'Returning to a hidden page never resumes a canceled greeting');}
  if(stop==='actor'){await emit(p,{hologram:metrics()});await p.clock.runFor(30000);equal(await p.locator('.hud-card').count(),0,'Late overview data cannot re-add the previous account counters');equal((await calls(p,'section=hologram')).length,0,'Account change terminates old metric polling');}await p.close();
 }
 for(const status of [401,403,404]){p=await open('named=pending&v3delay=1&nonce=named-denied-'+status);voice=await calls(p,'greeting/status');await reply(p,voice[0].i,{},status);await p.clock.runFor(8000);equal((await calls(p,'greeting/status')).length,1,'Auth-invalid named ticket stops polling: '+status);equal(await p.evaluate(()=>fixture.plays),0,'Auth-invalid named ticket never plays: '+status);if(status!==404){await p.clock.runFor(31000);equal(await p.locator('.hud-card').count(),0,'Voice authorization loss fences previous account counters: '+status);equal(await p.evaluate(()=>fixture.calls.length),1,'Voice authorization loss terminates all background work: '+status);}else equal(await p.locator('.hud-card').count(),10,'Expired/replaced voice ticket does not revoke otherwise valid account counters');await p.close();}
 p=await open('owner=1&named=pending&v3delay=1&ignoreabort=1&nonce=named-balance-auth-loss');cash=await calls(p,'section=drawers');voice=await calls(p,'greeting/status');await reply(p,cash[0].i,{},403);check((await calls(p,'greeting/status'))[0].aborted,'Financial authorization loss aborts pending voice readiness');await reply(p,voice[0].i,namedReady());await emit(p,{hologram:metrics(true)});await p.clock.runFor(31000);equal(await p.locator('.hud-card').count(),0,'Financial authorization loss removes and fences all old-account counters');equal(await p.evaluate(()=>fixture.plays),0,'Financial authorization loss cannot later arm a cached greeting');equal(await p.evaluate(()=>fixture.calls.length),2,'Authorization loss stops all finance/metric/voice background requests');await p.close();
 p=await open('owner=1&named=pending&v3delay=1&cachedrefused=1&nonce=named-cached-auth-loss');await p.clock.runFor(7000);equal(await p.locator('.hud-card').count(),0,'A cached overview auth refusal fences a later-mounted HUD');equal(await p.evaluate(()=>fixture.plays),0,'Cached auth refusal cannot arm a fresh-looking greeting descriptor');equal(await p.evaluate(()=>fixture.calls.length),0,'Cached auth refusal starts no metric, financial or voice requests');await p.close();
 await context.clock.resume();
}
if(require.main===module)(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const origin='http://127.0.0.1:'+server.address().port;
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox']});
 const errors=[];
 try{
  const context=await browser.newContext({viewport:{width:1672,height:941}});
  await context.addInitScript(()=>{
   window.fixture={calls:[],speech:[],audio:[],plays:0,pauses:0,cancelled:0,mounted:[],cleanups:[],autoStart:!location.search.includes('delayed')};
   window.Audio=class {constructor(url){this.src=url;this.paused=true;this.ended=false;this.currentTime=0;fixture.audio.push(this);}play(){fixture.plays++;if(location.search.includes('blocked')&&fixture.plays===1){this.paused=true;return Promise.reject(Object.assign(new Error('Blocked'),{name:'NotAllowedError'}));}this.paused=false;if(fixture.autoStart&&this.onplaying)this.onplaying();return Promise.resolve();}pause(){fixture.pauses++;this.paused=true;}};
   if(!location.search.includes('spa'))window.DashboardSPA={isCurrentPage:()=>true,onCleanup:callback=>fixture.cleanups.push(callback)};
   const api={getVoices:()=>[{name:'Microsoft David',lang:'en-US'}],speak:utterance=>{fixture.speech.push(utterance);if(fixture.autoStart&&utterance.onstart)utterance.onstart();},cancel:()=>{fixture.cancelled++;}};
   Object.defineProperty(window,'speechSynthesis',{value:api,configurable:true});
   window.SpeechSynthesisUtterance=function(text){this.text=text;};
   window.fetch=(url,options={})=>new Promise((resolve,reject)=>{const request={url:String(url),options,resolve,reject,aborted:false};fixture.calls.push(request);if(options.signal)options.signal.addEventListener('abort',()=>{request.aborted=true;if(!location.search.includes('ignoreabort'))reject(new DOMException('Aborted','AbortError'));});});
   window.fixture.reply=(i,data,status=200,redirected=false)=>fixture.calls[i].resolve({status,ok:status>=200&&status<300,redirected,json:()=>Promise.resolve(data)});
   if(!location.search.includes('real'))window.GoHologram={version:location.search.includes('v3delay')?3:undefined,mount:(canvas,options)=>{const robot={options,dead:false,speaking:false,alert:false,destroy(){this.dead=true},setSpeaking(v){this.speaking=v},setVoiceLevel(v){this.voiceLevel=v},setAlert(v){this.alert=v}};fixture.mounted.push(robot);return robot;}};
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.clock.install();await page.goto(origin+'/fixture');
  equal(await page.locator('.hud-card').count(),10,'All ten authorized entries remain');
  equal(await page.locator('[data-hud-right] .hud-card').count(),5,'Five cards on right');
  equal(await page.locator('[data-hud-left] .hud-card').count(),5,'Five cards on left');
  equal(await page.evaluate(()=>fixture.mounted[0].options.interactive),false,'Production opening explicitly disables pointer-driven robot rotation');
  equal(await page.evaluate(()=>fixture.plays),1,'Login plays reviewed local greeting once');
  check((await page.evaluate(()=>fixture.audio[0].src)).endsWith('/go-welcome-omar.mp3'),'Named login uses reviewed local personalized recording');
  equal(await page.evaluate(()=>fixture.speech.length),0,'No unrelated native/system voice may substitute the reviewed recording');
  await page.evaluate(()=>fixture.audio[0].currentTime=.04);await page.clock.runFor(40);
  equal(await page.evaluate(()=>fixture.mounted[0].voiceLevel),.85,'Playback currentTime drives the real recorded mouth amplitude');
  await page.locator('#ho-title').evaluate(n=>{n.tabIndex=0;n.focus();});await page.keyboard.press('Tab');
  check(await page.evaluate(()=>document.querySelector('#go-hud').contains(document.activeElement)),'Tab from outside is trapped inside live dialog');
  await page.locator('[data-hud-voice]').focus();await page.locator('[data-hud-voice]').click();
  equal(await page.locator('[data-hud-voice]').isVisible(),false,'Mute button disappears after muting');
  equal(await page.evaluate(()=>fixture.audio[0].paused),true,'Muting pauses the actual recording');
  equal(await page.evaluate(()=>fixture.mounted[0].voiceLevel),0,'Muting resets mouth amplitude to zero');
  equal(await page.evaluate(()=>document.activeElement===document.querySelector('[data-hud-enter]')),true,'Hiding focused mute restores focus to home-entry control');
  await page.locator('[data-hud-key="ch-app"]').focus();
  const before=await page.locator('[data-hud-key="ch-app"]').elementHandle();
  const next=metrics();next.cards.find(c=>c.key==='ch-app').count=89;
  await page.evaluate(data=>document.dispatchEvent(new CustomEvent('go:home-data',{detail:{hologram:data}})),next);
  equal(await page.locator('[data-hud-key="ch-app"] .hud-primary b').textContent(),'89','Live event updates counter in place');
  equal(await page.evaluate(node=>node===document.activeElement,before),true,'Live refresh preserves keyboard focus and element');
  await page.evaluate(()=>document.dispatchEvent(new CustomEvent('go:home-data',{detail:{completed:999}})));
  equal(await page.locator('[data-hud-key="ch-app"] .hud-primary b').textContent(),'89','Unrelated overview totals never substitute app counter');
  equal(await page.evaluate(()=>fixture.plays),1,'Updates never replay welcome');
  next.cards.find(c=>c.key==='pending-expenses').url='/admin/pending-expenses?from=2025-01-01';
  await page.evaluate(data=>document.dispatchEvent(new CustomEvent('go:home-data',{detail:{hologram:data}})),next);
  check((await page.locator('[data-hud-key="pending-expenses"]').getAttribute('href')).endsWith('?from=2025-01-01'),'Live update also refreshes date-scoped navigation target');
  await page.clock.runFor(15010);equal(await page.evaluate(()=>fixture.calls.length),1,'Live poll scheduled once');
  const request=await page.evaluate(()=>{const c=fixture.calls[0];return{url:c.url,cache:c.options.cache,credentials:c.options.credentials,method:c.options.method||'GET'};});
  equal(request,{url:origin+'/admin/dashboard/overview?section=hologram',cache:'no-store',credentials:'same-origin',method:'GET'},'Polling uses dedicated read-only endpoint and clears stale branch filters');
  const reply=metrics();reply.cards.find(c=>c.key==='ch-app').count=0;reply.cards.find(c=>c.key==='stock').available=false;reply.cards.find(c=>c.key==='stock').count=null;
  await page.evaluate(data=>fixture.reply(0,{success:true,hologram:data}),reply);await page.waitForTimeout(0);
  equal(await page.locator('[data-hud-key="ch-app"] .hud-primary b').textContent(),'0','Confirmed real zero displayed');
  equal(await page.locator('[data-hud-key="stock"] .hud-primary b').textContent(),'—','Unavailable module remains unknown, never zero');
  check((await page.locator('[data-hud-key="stock"]').textContent()).includes('غير متاح'),'Unavailable module clearly labelled');
  await page.clock.runFor(15010);equal(await page.evaluate(()=>fixture.calls.length),2,'Settled poll schedules next without overlap');
  await page.evaluate(()=>fixture.reply(1,{},503));await page.waitForTimeout(0);
  check(await page.locator('.hud-live').evaluate(n=>n.classList.contains('stale')),'Failed refresh labels last confirmed counters stale');
  equal(await page.locator('[data-hud-key="ch-app"] .hud-primary b').textContent(),'0','Failed refresh retains last confirmed value');
  await page.clock.runFor(15010);equal(await page.evaluate(()=>fixture.calls.length),3,'Retry is bounded');
  await page.locator('[data-hud-enter]').click();equal(await page.locator('#go-hud').isVisible(),false,'Home button dismisses opening');
  equal(await page.evaluate(()=>fixture.calls[2].aborted),true,'Pending counter request aborts on leave');
  equal(await page.evaluate(()=>fixture.mounted[0].dead),true,'Large renderer destroyed on leave');
  equal(await page.evaluate(()=>document.documentElement.classList.contains('go-hud-open')),false,'Opening scroll lock cleaned');
  await page.clock.runFor(60000);equal(await page.evaluate(()=>fixture.calls.length),3,'No refresh remains after disposal');
  equal(await page.evaluate(()=>fixture.plays),1,'No idle greeting after leave');
  await page.reload();equal(await page.evaluate(()=>fixture.plays),0,'Back/reload with same nonce cannot replay welcome');
  await page.goto(origin+'/fixture?nonce=login-two');equal(await page.evaluate(()=>fixture.plays),1,'New login nonce permits greeting once');
  await page.goto(origin+'/fixture?nonce=login-delayed&delayed=1');
  await page.locator('[data-hud-robot]').dispatchEvent('pointerdown');
  await page.locator('[data-hud-robot]').dispatchEvent('pointerdown');
  equal(await page.evaluate(()=>fixture.plays),1,'Repeated interactions before audio starts do not queue duplicate welcome');
  await page.goto(origin+'/fixture?nonce=login-blocked&blocked=1');
  equal(await page.locator('[data-hud-voice]').textContent(),'تشغيل الترحيب','Blocked autoplay exposes the reviewed-audio play button');
  equal(await page.evaluate(()=>fixture.plays),1,'Blocked autoplay makes one initial attempt');
  await page.locator('[data-hud-voice]').click();
  equal(await page.evaluate(()=>fixture.plays),2,'Explicit retry starts one new attempt');
  equal(await page.evaluate(()=>fixture.audio.at(-1).paused),false,'Pointerdown followed by retry click must not immediately mute successful audio');
  equal(await page.evaluate(()=>fixture.mounted[0].speaking),true,'Explicit retry keeps synchronized robot speaking active');
  equal(await page.locator('[data-hud-voice]').textContent(),'كتم الترحيب','Successful retry becomes a mute control');
  equal(await page.evaluate(()=>fixture.speech.length),0,'Autoplay rejection never invokes a native fallback voice');
  await page.goto(origin+'/fixture?silent=1&unsafe=1');
  equal(await page.locator('[data-hud-key="employees"]').evaluate(n=>n.tagName),'DIV','javascript URL never becomes a navigation link');
  equal(await page.locator('[data-hud-key="customers"]').evaluate(n=>n.tagName),'DIV','Cross-origin URL never becomes a navigation link');
  equal(await page.locator('[data-hud-key="expenses"] img').count(),0,'Counter labels are rendered as text, not HTML');
  equal(await page.evaluate(()=>window.bad),undefined,'Untrusted label/URL cannot execute code');
  equal(await page.evaluate(()=>fixture.plays),0,'Normal home entry does not greet');
  await page.clock.runFor(15010);await page.evaluate(()=>fixture.reply(0,{},403));await page.waitForTimeout(0);
  equal(await page.locator('.hud-card').count(),0,'Lost authorization clears sensitive counters');
  // Freeze before navigation so the construction gate is measured exactly from page initialization.
  await page.clock.pauseAt(await page.evaluate(()=>Date.now()+1000));
  await page.goto(origin+'/fixture?nonce=login-v3-ready&v3delay=1');
  equal(await page.evaluate(()=>fixture.plays),0,'V3 waits for emitter construction before greeting');
  await page.locator('[data-hud-robot]').dispatchEvent('pointerdown');
  await page.locator('[data-hud-robot]').dispatchEvent('keydown',{key:'ArrowRight'});
  equal(await page.evaluate(()=>fixture.plays),0,'Early pointer and keyboard activity cannot bypass construction gate');
  await page.clock.runFor(6100);
  equal(await page.evaluate(()=>fixture.plays),0,'Greeting remains silent through the first 6.1 seconds');
  await page.clock.runFor(200);
  equal(await page.evaluate(()=>fixture.plays),1,'Greeting starts once after 6.2-second construction');
  await page.evaluate(()=>fixture.audio[0].currentTime=.04);await page.clock.runFor(40);
  equal(await page.evaluate(()=>fixture.mounted[0].voiceLevel),.85,'Delayed V3 greeting still drives synchronized mouth amplitude');
  await page.clock.runFor(3000);await page.locator('[data-hud-robot]').dispatchEvent('pointerdown');
  equal(await page.evaluate(()=>fixture.plays),1,'Finishing construction and later interaction never replay greeting');
  await page.goto(origin+'/fixture?nonce=login-v3-cancel&v3delay=1');
  await page.clock.runFor(1000);await page.locator('[data-hud-enter]').dispatchEvent('click');await page.clock.runFor(7000);
  equal(await page.evaluate(()=>fixture.plays),0,'Leaving during construction cancels the pending greeting timer');
  equal(await page.evaluate(()=>fixture.mounted[0].dead),true,'Leaving during construction destroys the main renderer');
  await page.emulateMedia({reducedMotion:'reduce'});await page.goto(origin+'/fixture?nonce=login-v3-reduced&v3delay=1');
  equal(await page.evaluate(()=>fixture.plays),1,'Reduced-motion V3 skips construction wait and greets immediately');
  await page.clock.resume();
  await page.close();
  await focusedCases(context,origin,errors);
  // Production renderer and responsive DOM. No metric or audio network operations.
  for(const size of [{width:1672,height:941},{width:1366,height:768},{width:1024,height:768},{width:800,height:600},{width:390,height:844},{width:320,height:568}]){
   const visual=await context.newPage();await visual.setViewportSize(size);visual.on('pageerror',e=>errors.push(e.message));
   await visual.goto(origin+'/fixture?real=1&silent=1');await visual.waitForTimeout(180);
   const layout=await visual.evaluate(()=>({w:innerWidth,h:innerHeight,root:{w:document.querySelector('#go-hud').clientWidth,scroll:document.querySelector('#go-hud').scrollWidth},cards:[...document.querySelectorAll('.hud-card')].map(n=>{const r=n.getBoundingClientRect();return{x:r.x,y:r.y,w:r.width,h:r.height,sw:n.scrollWidth,cw:n.clientWidth};}),button:(()=>{const r=document.querySelector('[data-hud-enter]').getBoundingClientRect();return{x:r.x,y:r.y,w:r.width,h:r.height}})()}));
   check(layout.root.scroll<=layout.root.w+1,'No horizontal overflow at '+size.width+'x'+size.height);
   check(layout.cards.every(c=>c.x>=-1&&c.x+c.w<=layout.w+1&&c.sw<=c.cw+1),'All menu text/cards fit at '+size.width+'x'+size.height);
   check(layout.cards.every((c,i,a)=>a.every((b,j)=>i===j||c.x+c.w<=b.x+1||b.x+b.w<=c.x+1||c.y+c.h<=b.y+1||b.y+b.h<=c.y+1)),'Cards never overlap at '+size.width+'x'+size.height);
   const canvas=await visual.locator('[data-hud-robot]').evaluate(c=>({w:c.width,h:c.height,ready:!!c.__goHologram}));check(canvas.ready&&canvas.w>0&&canvas.h>0,'Real canvas mounted at '+size.width+'x'+size.height);
   if(size.width===1672){
    const posture=await visual.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram,states=[];for(const t of[0,30,60,62.5,66,120,180]){r.pose(t);states.push({head:r.headYaw,body:r.bodyYaw,pitch:r.pitch});}for(const g of[-1,1]){r.api.setGaze(g,g);r.pose(62.5);states.push({head:r.headYaw,body:r.bodyYaw,pitch:r.pitch});}r.api.setGaze(0,0);return states;});
    check(posture.every(p=>p.head===0&&p.body===0&&p.pitch===0),'Hologram keeps a fixed posture through minute boundaries and gaze input');
    const out=process.env.HOLOGRAM_SCREENSHOTS||path.join(repo,'../analysis');fs.mkdirSync(out,{recursive:true});await visual.screenshot({path:path.join(out,'hologram-desktop.png'),fullPage:true});
   }
   if(size.width===390){const out=process.env.HOLOGRAM_SCREENSHOTS||path.join(repo,'../analysis');await visual.screenshot({path:path.join(out,'hologram-mobile.png'),fullPage:true});}
   await visual.close();
  }
  const reduced=await context.newPage();await reduced.emulateMedia({reducedMotion:'reduce'});await reduced.goto(origin+'/fixture?real=1&silent=1');await reduced.waitForTimeout(100);
  const still=await reduced.locator('[data-hud-robot]').evaluate(c=>({reduced:c.__goHologram.reduced,elapsed:c.__goHologram.elapsed}));check(still.reduced&&still.elapsed===0,'Reduced-motion draws a static complete hologram');
  await reduced.locator('[data-hud-enter]').click();equal(await reduced.locator('[data-hud-robot]').evaluate(c=>!!c.__goHologram),false,'Renderer destroys on opening dismissal');
  check(await reduced.locator('[data-go-sidebar-robot]').evaluate(c=>!!c.__goHologram),'Sidebar renderer survives dismissing large opening');
  for(const mode of ['normal','compact','collapsed']){
   await reduced.evaluate(mode=>{document.body.classList.toggle('sidebar-collapse',mode==='collapsed');document.querySelector('#dashboard-sidebar').classList.toggle('dashboard-sidebar-compact',mode==='compact');},mode);await reduced.waitForTimeout(120);
   const chrome=await reduced.locator('.go-sidebar-robot').evaluate(n=>{const a=getComputedStyle(n),p=getComputedStyle(n.parentElement);return{avatarBackground:a.backgroundColor,avatarImage:a.backgroundImage,avatarBorder:a.borderTopWidth,avatarShadow:a.boxShadow,parentBackground:p.backgroundColor,parentImage:p.backgroundImage,parentBorder:p.borderTopWidth,parentShadow:p.boxShadow};});
   check(chrome.avatarBackground==='rgba(0, 0, 0, 0)'&&chrome.avatarImage==='none'&&chrome.avatarBorder==='0px'&&chrome.avatarShadow==='none','Sidebar robot has no rectangular own background in '+mode);
   check(chrome.parentBackground==='rgba(0, 0, 0, 0)'&&chrome.parentImage==='none'&&chrome.parentBorder==='0px'&&chrome.parentShadow==='none','Actual branding parent has no rectangular panel in '+mode);
   const alpha=await reduced.locator('[data-go-sidebar-robot]').evaluate(c=>{const r=c.__goHologram;r.render(3);const gl=r.gl,out=[];for(const[x,y]of[[0,0],[c.width-1,0],[0,c.height-1],[c.width-1,c.height-1]]){const pixel=new Uint8Array(4);gl.readPixels(x,y,1,1,gl.RGBA,gl.UNSIGNED_BYTE,pixel);out.push(pixel[3]);}return out;});
   // The tiny collapsed canvas can receive <1.2% alpha from the requested outer swarm's bloom.
   if(mode==='collapsed')check(alpha.every(a=>a<=3),'Collapsed canvas has no visible rectangular backdrop beyond faint particle glow');
   else equal(alpha,[0,0,0,0],'Real WebGL corner pixels stay transparent in '+mode);
  }

  await reduced.close();
  const scoped=await context.newPage();scoped.on('pageerror',e=>errors.push(e.message));await scoped.goto(origin+'/fixture?real=1&silent=1&spa=1');
  await scoped.waitForFunction(()=>document.querySelector('[data-go-sidebar-robot]').__goHologram.elapsed>0,null,{timeout:5000});
  equal(await scoped.evaluate(()=>DashboardSPA.ready()),true,'Real SPA owns the current page');
  const earlier=await scoped.locator('[data-go-sidebar-robot]').evaluate(c=>c.__goHologram.elapsed);
  await scoped.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram;window.rendererCleanupEvidence={gl:r.gl,energyProgram:r.energyProgram,energyBuffer:r.energyBuffer,blurProgram:r.blurProgram,swarmProgram:r.swarmProgram,swarmBuffer:r.swarmBuffer};});
  await scoped.evaluate(()=>window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true})));
  await scoped.waitForFunction(time=>document.querySelector('[data-go-sidebar-robot]').__goHologram.elapsed>time,earlier,{timeout:5000});
  check(await scoped.locator('[data-go-sidebar-robot]').evaluate((c,time)=>c.__goHologram.elapsed>time,earlier),'Shared sidebar animation survives cancellation of actual SPA page callbacks');
  equal(await scoped.locator('[data-hud-robot]').evaluate(c=>!!c.__goHologram),false,'SPA cleanup destroys only the opening instance');
  check(await scoped.evaluate(()=>{const e=rendererCleanupEvidence,s=document.querySelector('[data-go-sidebar-robot]').__goHologram;return !e.gl.isProgram(e.energyProgram)&&!e.gl.isProgram(e.blurProgram)&&!e.gl.isBuffer(e.energyBuffer)&&!e.gl.isProgram(e.swarmProgram)&&!e.gl.isBuffer(e.swarmBuffer)&&!!s&&s.gl.isProgram(s.energyProgram)&&s.gl.isProgram(s.swarmProgram); }),'SPA leave releases main energy/bloom/swarm GPU resources while the sidebar programs survive');
  equal(await scoped.locator('#go-hud').isVisible(),false,'bfcache cleanup cannot leave an inert opening overlay visible');
  await scoped.close();equal(errors,[],'No browser runtime errors');await context.close();
  console.log('PASS hologram browser: '+checks+' assertions');
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;server.close();});
