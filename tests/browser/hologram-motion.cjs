'use strict';
// Review artifact from actual live geometry, never a replacement promotional render.
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const {server}=require('./hologram-opening.cjs');
const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs'),{spawnSync}=require('node:child_process');
const repo=path.resolve(__dirname,'../..'),out=path.resolve(process.env.HOLOGRAM_MOTION_OUTPUT||path.join(repo,'../analysis/hologram-v2-motion'));
const read=p=>fs.readFileSync(path.join(repo,p),'utf8');
const renderer=read('public/dashboard/js/go-hologram.js');
assert(!/new\s+(?:window\.)?Image\s*\(|\.drawImage\s*\(/.test(renderer),'Review renderer must be generated from actual 3D geometry, not an external bitmap or scanline warp');
(async()=>{
 fs.mkdirSync(out,{recursive:true});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox','--enable-unsafe-swiftshader']});
 const errors=[],requests=[];let assertions=0;
 const envelopeFile=process.env.HOLOGRAM_VOICE_ENVELOPE;
 const envelope=envelopeFile?JSON.parse(fs.readFileSync(envelopeFile,'utf8')):null;
 const audioOffset=Number(process.env.HOLOGRAM_AUDIO_OFFSET||0);
 const voiceAt=t=>t<audioOffset?0:envelope&&Array.isArray(envelope.values)?Math.max(0,Math.min(1,Number(envelope.values[Math.min(envelope.values.length-1,Math.floor((t-audioOffset)*(envelope.sample_rate||envelope.samples_per_second||25)))])||0)):0;
 const audioFile=process.env.HOLOGRAM_REFERENCE_AUDIO;
 try{
  const context=await browser.newContext({viewport:{width:1920,height:1080},deviceScaleFactor:1});
  await context.addInitScript(()=>{
   // No welcome is requested, and counters are seeded in the test fixture. Keep reads local.
   window.DashboardSPA={isCurrentPage:()=>true,onCleanup:()=>{}};
   window.fetch=()=>new Promise(()=>{});
   window.textureEvidence={allocations:0,uploads:0};
   const proto=WebGLRenderingContext.prototype,original=proto.texImage2D;
   proto.texImage2D=function(){if(arguments.length===9&&arguments[8]===null)textureEvidence.allocations++;else textureEvidence.uploads++;return original.apply(this,arguments);};
   const sub=proto.texSubImage2D;if(sub)proto.texSubImage2D=function(){textureEvidence.uploads++;return sub.apply(this,arguments);};
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>requests.push(r.url()));
  await page.goto('http://127.0.0.1:'+server.address().port+'/fixture?robotonly=1');await page.waitForTimeout(300);
  const mounted=await page.locator('[data-hud-robot]').evaluate(c=>!!c.__goHologram);assert(mounted,'Actual production renderer mounted');assertions++;
  const state=await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram;return{version:GoHologram.version,width:c.width,height:c.height,cssWidth:c.clientWidth,cssHeight:c.clientHeight,mesh:r.mesh?Object.fromEntries(Object.entries(r.mesh).map(([k,v])=>[k,Array.isArray(v)?v.length:typeof v])):null};});
  assert(state.version>=2,'Revised geometry renderer must identify a new version');assertions++;
  assert(state.width>=state.cssWidth&&state.height>=state.cssHeight,'High-DPI canvas has at least CSS resolution');assertions++;
  const geometry=await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram,g=r.gl,vertices=r.mesh.vertices;let minZ=Infinity,maxZ=-Infinity;for(let i=2;i<vertices.length;i+=9){minZ=Math.min(minZ,vertices[i]);maxZ=Math.max(maxZ,vertices[i]);}return{webgl:g instanceof WebGLRenderingContext,vertices:r.mesh.vertexCount,triangles:r.mesh.indices.length/3,particles:r.particles.count,minZ,maxZ,renderer:g.getParameter(g.RENDERER),error:g.getError()};});
  assert(geometry.webgl&&geometry.vertices>15000&&geometry.triangles>20000&&geometry.maxZ-geometry.minZ>.6,'Model contains a genuinely volumetric triangulated human surface');assertions++;
  assert(geometry.particles>=1500,'Living figure includes a geometric particle cloud');assertions++;
  assert.equal(geometry.error,0,'WebGL geometry renders without GPU errors');assertions++;
  const textures=await page.evaluate(()=>textureEvidence);
  assert.equal(textures.uploads,0,'GPU bloom may allocate empty render targets but never uploads an external bitmap');assertions++;


  // Freeze the scheduler for exact pose review. Manual render exercises the same production projection.
  await page.locator('[data-hud-robot]').evaluate(c=>{c.__goHologram.api.setVisible(false);document.activeElement.blur();});
  const poses=[];
  for(const time of [0,30,59,60,62,64,66,68,70,72,120,122]){
   const pose=await page.locator('[data-hud-robot]').evaluate((c,t)=>{const r=c.__goHologram;r.elapsed=t;r.render(t);return{time:t,headYaw:r.headYaw,bodyYaw:r.bodyYaw,pitch:r.pitch};},time);poses.push(pose);
  }
  const turn=await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram,out=[];for(let t=60;t<=72;t+=.1){r.pose(t);out.push({time:t,headYaw:r.headYaw,bodyYaw:r.bodyYaw});}return out;});
  const left=Math.min(...turn.map(p=>p.headYaw)),right=Math.max(...turn.map(p=>p.headYaw));
  assert(Math.abs(left+Math.PI/2)<.03&&Math.abs(right-Math.PI/2)<.03,'60-second cycle turns the head 90 degrees from the neck in both directions');assertions++;
  assert(turn.some(p=>Math.abs(p.bodyYaw)>.025),'Shoulders take part naturally in turn');assertions++;
  assert(Math.abs(poses.find(p=>p.time===72).headYaw)<.05,'Head returns to center after look-around');assertions++;
  const voiceReady=await page.locator('[data-hud-robot]').evaluate(c=>typeof c.__goHologram.api.setVoiceLevel==='function');
  assert(voiceReady,'Renderer exposes real audio amplitude input for mouth motion');assertions++;
  const mouth=await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram,states=[];for(const level of [0,.25,.7,1]){r.api.setVoiceLevel(level);r.render(3);states.push({level,mouthOpen:r.mouthOpen,gpuVoice:r.gl.getUniform(r.program,r.uniforms.uVoice)});}return states;});
  assert(mouth[0].mouthOpen===0&&mouth[3].mouthOpen>.08&&mouth.every((p,i,a)=>i===0||p.mouthOpen>a[i-1].mouthOpen),'Actual mouth deformation follows audio amplitude continuously');assertions++;
  assert(mouth.every(p=>Math.abs(p.gpuVoice-p.level)<.0001),'Audio envelope reaches the vertex shader, not merely a decorative status');assertions++;

  const project=await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram;const samples=[];for(const t of [0,62,66]){r.render(t);r.project(.43,1.2,0,1);const cheek={x:r.px,y:r.py};r.project(0,1.2,.35,1);samples.push({time:t,cheek,nose:{x:r.px,y:r.py}});}return samples;});
  assert(Math.abs(project[1].nose.x-project[2].nose.x)>60,'Real depth projects differently between left/right, rather than translating one flat image');assertions++;
  const fps=30,count=process.env.HOLOGRAM_VERIFY_ONLY?0:18*fps;
  const sourceTime=i=>i<3*fps?i/fps:i<15*fps?59+(i-3*fps)/fps:90+(i-15*fps)/fps;
  for(let i=0;i<count;i++){
   const time=sourceTime(i),level=voiceAt(i/fps);const png=await page.locator('[data-hud-robot]').evaluate((c,{time,level})=>{
    const r=c.__goHologram;r.elapsed=time;r.api.setVoiceLevel(level);r.render(time);
    const dst=window.captureCanvas||(window.captureCanvas=document.createElement('canvas'));if(dst.width!==1920)dst.width=1920;if(dst.height!==1080)dst.height=1080;
    const ctx=dst.getContext('2d'),bg=ctx.createRadialGradient(960,570,40,960,570,1000);bg.addColorStop(0,'#062331');bg.addColorStop(.45,'#03131f');bg.addColorStop(1,'#010b14');ctx.fillStyle=bg;ctx.fillRect(0,0,1920,1080);ctx.drawImage(c,0,0,1920,1080);
    return dst.toDataURL('image/png').split(',')[1];
   },{time,level});
   fs.writeFileSync(path.join(out,'frame-'+String(i).padStart(3,'0')+'.png'),Buffer.from(png,'base64'));
   if((i+1)%30===0)console.log('Captured '+(i+1)+'/'+count+' actual geometry frames');
  }
  const encode=['-hide_banner','-loglevel','error','-y','-framerate',String(fps),'-i',path.join(out,'frame-%03d.png')];
  if(audioFile){if(audioOffset)encode.push('-itsoffset',String(audioOffset));encode.push('-i',path.resolve(audioFile));}
  encode.push('-c:v','libx264','-preset','medium','-crf','18','-pix_fmt','yuv420p');
  if(audioFile)encode.push('-c:a','aac','-b:a','160k','-af','apad','-t','18');
  encode.push('-movflags','+faststart',path.join(out,'hologram-v2-turn-review.mp4'));
  if(count){const probe=spawnSync('ffmpeg',encode,{encoding:'utf8'});assert.equal(probe.status,0,probe.stderr);assertions++;}
  const highDPI=await browser.newContext({deviceScaleFactor:2});
  await highDPI.addInitScript(()=>{window.DashboardSPA={isCurrentPage:()=>true,onCleanup:()=>{}};window.fetch=()=>new Promise(()=>{});});
  for(const size of [{width:1920,height:1080},{width:2560,height:1440}]){
   await page.setViewportSize(size);await page.waitForTimeout(120);
   await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram;r.elapsed=3;r.render(3);});
   await page.screenshot({path:path.join(out,'hologram-v2-'+size.width+'x'+size.height+'.png'),scale:'css'});
   const layoutPage=await highDPI.newPage();layoutPage.on('pageerror',e=>errors.push(e.message));await layoutPage.setViewportSize(size);
   await layoutPage.goto('http://127.0.0.1:'+server.address().port+'/fixture?real=1&silent=1');await layoutPage.waitForTimeout(100);
   await layoutPage.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram;r.api.setVisible(false);r.render(3);document.activeElement.blur();});
   const bounds=await layoutPage.locator('.hud-card').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return{x:r.x,y:r.y,w:r.width,h:r.height,sw:n.scrollWidth,cw:n.clientWidth};}));
   assert.equal(bounds.length,10,'All production menu entries render at '+size.width);assertions++;
   assert(bounds.every(c=>c.x>=0&&c.x+c.w<=size.width&&c.sw<=c.cw+1),'No card/text clipping at '+size.width);assertions++;
   assert(bounds.every((c,i,a)=>a.every((b,j)=>i===j||c.x+c.w<=b.x+1||b.x+b.w<=c.x+1||c.y+c.h<=b.y+1||b.y+b.h<=c.y+1)),'No card overlap at '+size.width);assertions++;
   const density=await layoutPage.locator('[data-hud-robot]').evaluate(c=>({dpr:devicePixelRatio,pixel:c.width,css:c.clientWidth}));
   assert(density.dpr===2&&density.pixel>=density.css,'High-DPI renderer resolves native geometry without downscaling below CSS size');assertions++;
   await layoutPage.screenshot({path:path.join(out,'hologram-v2-layout-'+size.width+'x'+size.height+'.png'),scale:'css'});await layoutPage.close();
  }
  await highDPI.close();
  assert(await page.locator('.hud-card, [data-hud-who], [data-hud-key]').count()===0,'Final review contains actual robot only, no names or synthetic order data');assertions++;
  assert(!requests.some(u=>/go-hologram-bust\.(?:webp|png)|hologram.*\.(?:jpg|jpeg|png|webp)/i.test(u)),'Renderer loads no robot bitmap');assertions++;
  assert.deepEqual(errors,[],'Actual browser has no runtime error');assertions++;
  fs.writeFileSync(path.join(out,'validation.json'),JSON.stringify({assertions,frames:count,fps,audioOffset,geometry,textures,reviewTimeline:[{frames:[0,89],sourceTime:[0,2.9666667],purpose:'Assembly from particles'},{frames:[90,449],sourceTime:[59,70.9666667],purpose:'Recurring 60-second left/right neck turns'},{frames:[450,539],sourceTime:[90,92.9666667],purpose:'Settled idle'}],audio:audioFile?'Supplied greeting recording, played once':null,rendering:state,poses,turnPeaks:{left,right},mouth,projectedLandmarks:project,requests,errors},null,2));
  console.log('PASS v2 real-motion validation: '+assertions+' assertions; MP4 '+path.join(out,'hologram-v2-turn-review.mp4'));
  await context.close();
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;server.close();});
