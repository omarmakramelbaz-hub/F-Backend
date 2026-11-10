'use strict';
// Real production DOM, approved artwork and GPU layers; metrics are test fixtures.
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const {html,metrics}=require('./hologram-opening.cjs');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const repo=path.resolve(__dirname,'../..'),out=path.resolve(process.env.HOLOGRAM_PREMIUM_OUTPUT||path.join(repo,'../analysis/hologram-premium'));
const read=p=>fs.readFileSync(path.join(repo,p),'utf8');
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://fixture.invalid');
 if(url.pathname.startsWith('/dashboard/')){
  const file=path.resolve(repo,'public','.'+url.pathname),base=path.resolve(repo,'public/dashboard')+path.sep;
  if(!file.startsWith(base)||!fs.existsSync(file)){res.writeHead(404).end();return;}
  res.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':file.endsWith('.png')?'image/png':'application/octet-stream');res.end(fs.readFileSync(file));return;
 }
 let source=html(url).replace('</style>',read('public/dashboard/css/hud-commands.css')+'</style>');
 source=source.replace('<script data-dashboard-page-init src="/dashboard/js/home-hud.js">','<script src="/dashboard/js/hud-commands.js"></script><script data-dashboard-page-init src="/dashboard/js/home-hud.js">');
 res.setHeader('Content-Type','text/html; charset=utf-8');res.end(source);
});
(async()=>{
 fs.mkdirSync(out,{recursive:true});await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox','--enable-unsafe-swiftshader']});
 const context=await browser.newContext({reducedMotion:'reduce',deviceScaleFactor:1});
 const errors=[];let checks=0;
 const check=(value,message)=>{assert(value,message);checks++;};
 try{
  await context.route('**/admin/dashboard/overview?**',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,hologram:metrics()})}));
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.addInitScript(()=>{
   window.commandRecognitionFixture={started:0,current:null};
   const Recognition=class{constructor(){commandRecognitionFixture.current=this;}start(){commandRecognitionFixture.started++;if(this.onstart)this.onstart();}abort(){}};
   for(const name of ['SpeechRecognition','webkitSpeechRecognition'])Object.defineProperty(window,name,{configurable:true,value:Recognition});
  });
  const origin='http://127.0.0.1:'+server.address().port;
  for(const [width,height] of [[1920,1080],[1366,768],[1024,768],[820,1180],[700,900],[390,844],[320,568]]){
   await page.setViewportSize({width,height});await page.goto(origin+'/fixture?real=1&silent=1');
   await page.locator('[data-hologram-artwork]').first().evaluate(img=>img.decode());
   const state=await page.evaluate(()=>{
    const root=document.getElementById('go-hud'),img=root.querySelector('[data-hologram-artwork]'),r=root.querySelector('canvas[data-hud-robot]').__goHologram;
    const art=img.getBoundingClientRect(),enter=root.querySelector('[data-hud-enter]').getBoundingClientRect();
    const cards=[...root.querySelectorAll('.hud-card')].map(n=>{const b=n.getBoundingClientRect();return{x:b.x,y:b.y,right:b.right,bottom:b.bottom};});
    return{loaded:img.complete&&img.naturalWidth===1454,src:img.getAttribute('src'),art:{x:art.x,y:art.y,right:art.right,bottom:art.bottom,width:art.width,height:art.height},enter:{x:enter.x,y:enter.y,right:enter.right,bottom:enter.bottom},cards,scrollWidth:root.scrollWidth,width:root.clientWidth,diagnostics:r&&r.api.diagnostics(),glError:r&&r.gl.getError(),commands:!!root.goHudCommands};
   });
   check(state.loaded,'Approved transparent artwork is loaded at '+width);
   check(state.src.endsWith('/go-hologram-premium-20261110.png'),'Reviewed character is the production asset');
   check(state.diagnostics&&state.diagnostics.artwork&&state.glError===0,'Real artwork-mode WebGL renders without GPU errors');
   check(state.diagnostics.swarmCapacity<=65536&&state.diagnostics.swarmParticles<=4096,'GPU particle work is bounded');
   check(state.commands,'Commands are mounted by the production home controller');
   check(state.scrollWidth<=state.width+1,'No horizontal overflow at '+width);
   check(state.art.x>=-1&&state.art.right<=width+1&&state.art.width>0,'Artwork fits horizontally');
   check(state.cards.every(c=>c.x>=-1&&c.right<=width+1),'All authorized cards fit horizontally');
   if(width>700)check(state.art.bottom<=state.enter.y+3,'Projection ring clears the enter button');
   await page.screenshot({path:path.join(out,'premium-'+width+'x'+height+'.png'),fullPage:true,animations:'disabled'});
   await page.locator('[data-hud-commands-toggle]').click();
   check(await page.locator('#go-hud input,#go-hud textarea,#go-hud form').count()===0,'Voice-only opening has no text entry');
   await page.locator('[data-hud-command-mic]').click();
   check(await page.evaluate(()=>commandRecognitionFixture.started===1),'Explicit microphone click starts one recognition session');
   await page.evaluate(()=>{const r=commandRecognitionFixture.current,result=[{transcript:'عدد العملاء كام؟'}];result.isFinal=true;r.onresult({results:[result],resultIndex:0});});
   check((await page.locator('[data-hud-command-status]').textContent()).includes('العملاء'),'Recognized voice command reads live card');
   if(width===1920)await page.screenshot({path:path.join(out,'premium-voice-controls.png'),fullPage:true,animations:'disabled'});
   await page.locator('[data-hud-command-mic]').press('Escape');
   check(await page.locator('[data-hud-commands-toggle]').evaluate(n=>document.activeElement===n),'Escape closes command panel and returns keyboard focus');
   check(await page.locator('#go-hud').isVisible(),'Closing commands preserves the opening');
  }
  await page.setViewportSize({width:1366,height:768});await page.goto(origin+'/fixture?real=1&silent=1');
  await page.locator('#go-hud [data-hologram-artwork]').evaluate(img=>img.decode());
  await page.locator('#go-hud [data-hologram-artwork]').dispatchEvent('error');
  check(await page.locator('[data-hud-robot]').evaluate(c=>c.__goHologram.api.diagnostics().artwork===false&&c.__goHologram.gl.getError()===0),'Failed art uses native GPU fallback');
  await page.locator('#go-hud [data-hologram-artwork]').dispatchEvent('load');
  check(await page.locator('[data-hud-robot]').evaluate(c=>c.__goHologram.api.diagnostics().artwork),'Artwork reload restores approved portrait');
  await page.locator('[data-hud-enter]').click();
  check(await page.locator('[data-hud-robot]').evaluate(c=>!c.__goHologram),'Closing opening releases GPU renderer');
  check(await page.locator('#go-hud').evaluate(n=>!n.goHudCommands),'Closing opening destroys command listeners');
  assert.deepEqual(errors,[],'No browser exceptions');checks++;
  fs.writeFileSync(path.join(out,'result.json'),JSON.stringify({checks,errors,viewports:7,realWebGL:true},null,2));
  console.log(JSON.stringify({status:'GO_PREMIUM_BROWSER_PASS',checks,output:out}));
 }finally{await context.close();await browser.close();await new Promise(r=>server.close(r));}
})().catch(error=>{console.error(error.stack||error);server.close();process.exitCode=1;});
