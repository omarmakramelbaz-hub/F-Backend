'use strict';
// Actual production portrait/shaders. Deterministic renderer time; synthetic HUD metrics.
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
async function saveReview(context,reviews){
  const reviewPage=await context.newPage();
  await reviewPage.setContent('<html><body style="margin:0;background:#03121b"><canvas width="960" height="840"></canvas></body></html>');
  const contact=await reviewPage.evaluate(async frames=>{
   const canvas=document.querySelector('canvas'),ctx=canvas.getContext('2d');ctx.fillStyle='#03121b';ctx.fillRect(0,0,960,840);ctx.font='15px sans-serif';
   for(let i=0;i<frames.length;i++){
    const frame=frames[i],image=new Image();image.src=frame.src;await image.decode();const x=i%3*320,y=Math.floor(i/3)*280;
    ctx.fillStyle='#b6e9ef';ctx.fillText(frame.label,x+12,y+22);ctx.drawImage(image,x+8,y+32,304,240);
   }
   const closeups=[frames[3],frames[4],frames[5]].filter(Boolean);
   for(let i=0;i<closeups.length;i++){
    const frame=closeups[i],image=new Image();image.src=frame.src;await image.decode();const x=i*320,y=560;
    ctx.fillStyle='#b6e9ef';ctx.fillText(frame.label+' face detail',x+12,y+22);ctx.drawImage(image,image.width*.395,image.height*.22,image.width*.32,image.height*.36,x+8,y+32,304,240);
   }
   return canvas.toDataURL('image/jpeg',.82).split(',')[1];
  },reviews);
  fs.writeFileSync(path.join(out,'living-review.jpg'),Buffer.from(contact,'base64'));
  if(process.env.HOLOGRAM_REVIEW_LOG_IMAGE==='1'){const size=4000,total=Math.ceil(contact.length/size);for(let i=0;i<total;i++)console.log('GO_HOLOGRAM_REVIEW_IMAGE '+(i+1)+'/'+total+' '+contact.slice(i*size,(i+1)*size));}
  await reviewPage.close();
}
(async()=>{
 fs.mkdirSync(out,{recursive:true});await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox','--enable-unsafe-swiftshader']});
 const context=await browser.newContext({viewport:{width:1366,height:768},deviceScaleFactor:1,reducedMotion:'no-preference'});
 const errors=[];let checks=0;const check=(value,message)=>{assert(value,message);checks++;};
 const reviews=[];
 try{
  await context.route('**/admin/dashboard/overview?**',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,hologram:metrics()})}));
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.addInitScript(()=>{
   window.livingFixture={recordings:0};
   const Recognition=class{start(){livingFixture.recordings++;}abort(){}};
   for(const name of ['SpeechRecognition','webkitSpeechRecognition'])Object.defineProperty(window,name,{configurable:true,value:Recognition});
  });
  const origin='http://127.0.0.1:'+server.address().port;
  await page.goto(origin+'/fixture?real=1&silent=1&silentavatar=1');
  await page.locator('#go-hud [data-hologram-artwork]').evaluate(img=>img.decode());
  await page.waitForFunction(()=>document.querySelector('[data-hud-robot]').__goHologram.api.diagnostics().portraitReady,null,{timeout:5000});
  const intro=await page.evaluate(()=>{
   const r=document.querySelector('[data-hud-robot]').__goHologram;r.api.setVisible(false);
   window.livingFrames={};return r.api.diagnostics();
  });
  check(intro.portraitAnimated,'The approved portrait uses the animated GPU surface');
  check(intro.portraitParticles>=8192&&intro.portraitParticles<=65536,'Dense assembly uses a bounded portrait particle population');
  check(await page.locator('[data-hud-commands-toggle],[data-hud-command-mic],.hud-command-panel').count()===0,'Living opening has no optional microphone controls');
  check(await page.evaluate(()=>livingFixture.recordings===0),'Visual opening does not start microphone recognition');

  async function frame(name,time,voice=0){
   const state=await page.evaluate(({name,time,voice})=>{
    const canvas=document.querySelector('[data-hud-robot]'),r=canvas.__goHologram,gl=r.gl;
    r.reduced=false;r.api.setSpeaking(voice>0);r.api.setVoiceLevel(voice);r.render(time);gl.finish();
    const pixels=new Uint8Array(canvas.width*canvas.height*4);gl.readPixels(0,0,canvas.width,canvas.height,gl.RGBA,gl.UNSIGNED_BYTE,pixels);
    const rect=canvas.getBoundingClientRect(),art=document.querySelector('#go-hud [data-hologram-artwork]').getBoundingClientRect();
    let lit=0,minX=canvas.width,maxX=0,minY=canvas.height,maxY=0;
    for(let y=0;y<canvas.height;y+=2)for(let x=0;x<canvas.width;x+=2){const i=(y*canvas.width+x)*4;if(pixels[i+3]>24&&pixels[i]+pixels[i+1]+pixels[i+2]>35){lit++;minX=Math.min(minX,x);maxX=Math.max(maxX,x);minY=Math.min(minY,y);maxY=Math.max(maxY,y);}}
    window.livingFrames[name]={pixels,width:canvas.width,height:canvas.height,rect:{x:rect.x,y:rect.y,width:rect.width,height:rect.height},art:{x:art.x,y:art.y,width:art.width,height:art.height}};
    return{...r.api.diagnostics(),glError:gl.getError(),lit,spanX:maxX-minX,spanY:maxY-minY,art:{x:art.x,y:art.y,width:art.width,height:art.height}};
   },{name,time,voice});
   check(state.glError===0,'WebGL renders '+name+' without GPU errors');
   const a=state.art,clip={x:Math.max(0,a.x),y:Math.max(0,a.y),width:Math.min(1366,a.x+a.width)-Math.max(0,a.x),height:Math.min(768,a.y+a.height)-Math.max(0,a.y)};
   const image=await page.screenshot({type:'jpeg',quality:80,clip});reviews.push({label:name,src:'data:image/jpeg;base64,'+image.toString('base64')});
   return state;
  }
  const point=await frame('01 Light point',.1),stream=await frame('02 Gathering particles',2.6),settled=await frame('03 Complete hologram',6);
  check(point.assemblyProgress<.1,'Opening begins at the light point');
  check(stream.assemblyProgress>point.assemblyProgress&&stream.assemblyProgress<1,'Particles occupy an intermediate gathering phase');
  check(settled.assemblyProgress===1,'Complete portrait settles after construction');
  check(settled.lit>1000,'Complete hologram produces visible colored portrait pixels');
  check(point.lit<settled.lit*.45,'Light-point opening does not display the completed portrait');
  check(stream.lit>point.lit,'Gathering particles expand the visible construction');

  const open=await frame('04 Eyes open',8.6),blink=await frame('05 Natural blink',8.1),talk=await frame('06 Speech deformation',8.6,1);
  check(open.blink<.1&&blink.blink>.9,'Eyelids close naturally and reopen');
  check(open.mouthOpen===0&&talk.mouthOpen>0,'Mouth is closed at idle and moves with supplied speech level');
  check(Math.abs(open.yaw)<=.075&&Math.abs(open.pitch)<=.035,'Head movement stays subtle');
  const later=await page.evaluate(()=>{const r=document.querySelector('[data-hud-robot]').__goHologram;r.api.setSpeaking(false);r.render(13.4);return r.api.diagnostics();});
  check(Math.abs(later.yaw-open.yaw)>.001||Math.abs(later.pitch-open.pitch)>.001,'Head pose changes gently over time');
  const pixels=await page.evaluate(()=>{
   function difference(first,second,uv){
    const a=livingFrames[first],b=livingFrames[second],art=a.art,rect=a.rect;
    const sx=a.width/rect.width,sy=a.height/rect.height;
    const left=Math.max(0,Math.floor((art.x-rect.x+art.width*uv[0])*sx)),right=Math.min(a.width,Math.ceil((art.x-rect.x+art.width*uv[2])*sx));
    const top=Math.max(0,Math.floor((art.y-rect.y+art.height*uv[1])*sy)),bottom=Math.min(a.height,Math.ceil((art.y-rect.y+art.height*uv[3])*sy));
    let changed=0,total=0,maxDelta=0;
    for(let y=top;y<bottom;y++)for(let x=left;x<right;x++){const i=((a.height-1-y)*a.width+x)*4,delta=Math.abs(a.pixels[i]-b.pixels[i])+Math.abs(a.pixels[i+1]-b.pixels[i+1])+Math.abs(a.pixels[i+2]-b.pixels[i+2]);total++;maxDelta=Math.max(maxDelta,delta);if(delta>18)changed++;}
    return{changed,total,maxDelta};
   }
   return{eyes:difference('04 Eyes open','05 Natural blink',[.45,.285,.66,.365]),mouth:difference('04 Eyes open','06 Speech deformation',[.49,.41,.65,.535])};
  });
  check(pixels.eyes.changed>12,'Blink changes actual eye-region pixels');
  check(pixels.mouth.changed>12,'Speech changes actual lip/jaw pixels at the same renderer time');
  check(pixels.mouth.maxDelta>60,'Speech creates a visible mouth opening rather than only subtle portrait brightness');
  await page.evaluate(()=>{const r=document.querySelector('[data-hud-robot]').__goHologram;r.api.setSpeaking(false);r.render(8.6);});
  check(await page.evaluate(()=>document.querySelector('[data-hud-robot]').__goHologram.api.diagnostics().mouthOpen===0),'Stopping speech closes the mouth');
  const paused=await page.evaluate(()=>document.querySelector('[data-hud-robot]').__goHologram.elapsed);await page.waitForTimeout(250);
  check(await page.evaluate(v=>document.querySelector('[data-hud-robot]').__goHologram.elapsed===v,paused),'Hidden renderer stops its animation clock');
  await page.evaluate(()=>document.querySelector('[data-hud-robot]').__goHologram.api.setVisible(true));
  await page.waitForFunction(v=>document.querySelector('[data-hud-robot]').__goHologram.elapsed>v,paused,{timeout:5000});checks++;

  const reduced=await context.newPage();await reduced.emulateMedia({reducedMotion:'reduce'});await reduced.goto(origin+'/fixture?real=1&silent=1&silentavatar=1');
  await reduced.waitForFunction(()=>document.querySelector('[data-hud-robot]').__goHologram.api.diagnostics().portraitReady,null,{timeout:5000});
  const still=await reduced.evaluate(()=>document.querySelector('[data-hud-robot]').__goHologram.api.diagnostics());
  check(still.assemblyProgress===1&&still.blink===0&&still.yaw===0&&still.pitch===0,'Reduced motion shows the complete portrait without facial or construction motion');
  await reduced.close();
  await page.locator('[data-hud-enter]').click();
  check(await page.locator('[data-hud-robot]').evaluate(c=>!c.__goHologram),'Leaving the opening releases its living renderer');
  assert.deepEqual(errors,[],'No browser exceptions');checks++;

  fs.writeFileSync(path.join(out,'living-result.json'),JSON.stringify({checks,errors,pixels,point,stream,settled,open,blink,talk},null,2));
  console.log(JSON.stringify({status:'GO_LIVING_HOLOGRAM_PASS',checks,pixelChanges:pixels,output:out}));
 }finally{if(reviews.length){try{await saveReview(context,reviews);}catch(error){console.error('Review capture failed:',error.message);}}await context.close();await browser.close();await new Promise(r=>server.close(r));}
})().catch(error=>{console.error(error.stack||error);server.close();process.exitCode=1;});
