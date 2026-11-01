'use strict';
// Review artifact from actual live geometry, never a replacement promotional render.
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const {server}=require('./hologram-opening.cjs');
const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs'),{spawnSync}=require('node:child_process');
const repo=path.resolve(__dirname,'../..'),out=path.resolve(process.env.HOLOGRAM_MOTION_OUTPUT||path.join(repo,'../analysis/hologram-v3-motion'));
const read=p=>fs.readFileSync(path.join(repo,p),'utf8');
const renderer=read('public/dashboard/js/go-hologram.js');
assert(!/new\s+(?:window\.)?Image\s*\(|\.drawImage\s*\(/.test(renderer),'Review renderer must be generated from actual 3D geometry, not an external bitmap or scanline warp');
(async()=>{
 fs.mkdirSync(out,{recursive:true});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox','--enable-unsafe-swiftshader']});
 const errors=[],requests=[];let assertions=0;
 const envelopeFile=process.env.HOLOGRAM_VOICE_ENVELOPE;
 const envelope=envelopeFile?JSON.parse(fs.readFileSync(envelopeFile,'utf8')):null;
 const audioOffset=Number(process.env.HOLOGRAM_AUDIO_OFFSET||6.2);
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
  assert(state.version>=3,'Emitter-driven assembly renderer must identify version 3');assertions++;
  assert(state.width>=state.cssWidth&&state.height>=state.cssHeight,'High-DPI canvas has at least CSS resolution');assertions++;
  const geometry=await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram,g=r.gl,vertices=r.mesh.vertices;let minZ=Infinity,maxZ=-Infinity;for(let i=2;i<vertices.length;i+=9){minZ=Math.min(minZ,vertices[i]);maxZ=Math.max(maxZ,vertices[i]);}return{webgl:g instanceof WebGLRenderingContext,vertices:r.mesh.vertexCount,triangles:r.mesh.indices.length/3,particles:r.particles.count,minZ,maxZ,renderer:g.getParameter(g.RENDERER),error:g.getError()};});
  assert(geometry.webgl&&geometry.vertices>15000&&geometry.triangles>20000&&geometry.maxZ-geometry.minZ>.6,'Model contains a genuinely volumetric triangulated human surface');assertions++;
  assert(geometry.particles>=1500,'Living figure includes a geometric particle cloud');assertions++;
  assert.equal(geometry.error,0,'WebGL geometry renders without GPU errors');assertions++;
  const textures=await page.evaluate(()=>textureEvidence);
  assert.equal(textures.uploads,0,'GPU bloom may allocate empty render targets but never uploads an external bitmap');assertions++;



  const assembly=await page.locator('[data-hud-robot]').evaluate(c=>{
   const r=c.__goHologram;
   if(typeof r.api.assembly!=='function')return null;
   const state=r.api.assembly(0),distance=(a,b)=>Math.hypot(...Array.from(a,(value,i)=>value-b[i]));
   const trajectories=state.particles.map(sample=>{
    const find=t=>r.api.assembly(t).particles.find(p=>p.id===sample.id);
    const born=find(sample.birth+.000001),mid=find((sample.birth+sample.arrival)/2),landed=find(sample.arrival+.0001),before=sample.birth>.01?find(sample.birth-.01):null;
    return{id:sample.id,birth:sample.birth,arrival:sample.arrival,originError:distance(born.position,state.emitter),landingError:distance(landed.position,landed.target),midDistance:distance(mid.position,state.emitter),unbornActive:before?before.active:false};
   });
   r.pose(0);const head={left:Infinity,top:Infinity,right:-Infinity,bottom:-Infinity};
   const vertices=r.mesh.vertices;
   for(let i=0;i<vertices.length;i+=9){if(vertices[i+8]<.5||vertices[i+8]>1.5)continue;r.project(vertices[i],vertices[i+1],vertices[i+2],1);head.left=Math.min(head.left,r.px);head.right=Math.max(head.right,r.px);head.top=Math.min(head.top,r.py);head.bottom=Math.max(head.bottom,r.py);}
   const hx=(head.right-head.left)*.07,hy=(head.bottom-head.top)*.10;head.left+=hx;head.right-=hx;head.top+=hy;head.bottom-=hy;
   const emitter=state.emitter_projected,radius=r.height*.12;
   window.sampleAssemblyPixels=(time,layer)=>{
    const gl=r.gl,arrays=gl.drawArrays,elements=gl.drawElements;
    try{
     if(layer==='mesh')gl.drawArrays=function(mode){if(mode===gl.POINTS)return;return arrays.apply(gl,arguments);};
     if(layer==='particles')gl.drawElements=function(mode,count){if(count>512)return;return elements.apply(gl,arguments);};
     r.api.setVoiceLevel(0);r.render(time);
     const pixels=new Uint8Array(c.width*c.height*4);gl.readPixels(0,0,c.width,c.height,gl.RGBA,gl.UNSIGNED_BYTE,pixels);
     let strong=0,outsideEmitter=0,headPixels=0,borderPixels=0;
     for(let i=0;i<pixels.length;i+=4){if(Math.max(pixels[i],pixels[i+1],pixels[i+2])<24||pixels[i+3]<8)continue;strong++;const x=(i/4%c.width)*r.width/c.width,y=(c.height-1-Math.floor(i/4/c.width))*r.height/c.height;
      if(Math.hypot(x-emitter.x,y-emitter.y)>radius)outsideEmitter++;
      if(x>=head.left&&x<=head.right&&y>=head.top&&y<=head.bottom)headPixels++;
      if(x<r.width*.03||x>r.width*.97||y<r.height*.03||y>r.height*.97)borderPixels++;
     }
     return{time,layer,strong,outsideEmitter,headPixels,borderPixels,error:gl.getError()};
    }finally{gl.drawArrays=arrays;gl.drawElements=elements;}
   };
   return{duration:state.duration,emitter:state.emitter,emitterPixel:emitter,headRegion:head,surfaceArrival:state.surfaceArrival,trajectories,width:r.width,height:r.height};
  });
  fs.writeFileSync(path.join(out,'assembly-diagnostics.json'),JSON.stringify(assembly,null,2));
  assert(assembly&&assembly.trajectories.length>=8,'Assembly exposes bounded deterministic particle trajectories for independent validation');assertions++;
  assert(assembly.duration>=5&&assembly.duration<=6.5,'Reference construction lasts about five to six seconds');assertions++;
  assert(Math.abs(assembly.emitterPixel.x-assembly.width/2)<assembly.width*.03&&assembly.emitterPixel.y>assembly.height*.62,'One emitter sits in the lower-center region');assertions++;
  assert(assembly.trajectories.every(p=>p.originError<.035&&!p.unbornActive),'Every construction particle starts at the same emitter and remains absent before birth');assertions++;
  assert(assembly.trajectories.every(p=>p.arrival>p.birth+.1&&p.landingError<.05),'Particles travel before arrival and land on their target geometry');assertions++;
  const arrival=assembly.surfaceArrival;
  assert(arrival.headFirst>arrival.torsoFirst+.5&&arrival.headLast>arrival.headFirst+.6,'Head and torso have staggered local arrivals rather than a simultaneous whole-mesh fade');assertions++;
  const early=await page.evaluate(()=>sampleAssemblyPixels(.25,'all'));
  const preSurface=await page.evaluate(time=>sampleAssemblyPixels(time,'mesh'),Math.max(.1,Math.min(arrival.headFirst,arrival.torsoFirst)-.15));
  const preHead=await page.evaluate(time=>sampleAssemblyPixels(time,'mesh'),arrival.headFirst-.20);
  const middleHead=await page.evaluate(time=>sampleAssemblyPixels(time,'mesh'),arrival.headFirst+(arrival.headLast-arrival.headFirst)*.33);
  const assembled=await page.evaluate(time=>sampleAssemblyPixels(time,'mesh'),assembly.duration+.3);
  assembly.gpu={early,preSurface,preHead,middleHead,assembled};
  fs.writeFileSync(path.join(out,'assembly-diagnostics.json'),JSON.stringify(assembly,null,2));
  assert(early.strong>10&&early.outsideEmitter<=Math.max(5,early.strong*.002)&&early.borderPixels===0,'Initial GPU image contains the emitter only, never a full-screen construction cloud');assertions++;
  assert(preSurface.outsideEmitter<=5,'Mesh contours cannot appear before the first particle arrivals');assertions++;
  assert(preHead.headPixels<=Math.max(5,assembled.headPixels*.0005),'Head mesh is actually dark before head particles arrive');assertions++;
  assert(preHead.outsideEmitter>20,'Torso contour trail forms before head construction, matching the reference sequence');assertions++;
  assert(assembled.headPixels>100&&middleHead.headPixels<assembled.headPixels*.80,'Head contour construction stays local and progressive until particles settle');assertions++;
  assert([early,preSurface,preHead,middleHead,assembled].every(p=>p.error===0),'Independent GPU layer sampling remains error-free');assertions++;

  // Isolate actual GPU vertices so a correct diagnostic cannot hide a different rendered path.
  const particleParity=await page.locator('[data-hud-robot]').evaluate(c=>{
   const r=c.__goHologram,gl=r.gl,all=r.api.assembly(0).particles,visible=all.filter(p=>p.local_target[1]>.05);
   const ids=[visible[0].id,visible[Math.floor(visible.length/2)].id,visible[visible.length-1].id],result=[];
   for(const id of ids){
    const initial=all.find(p=>p.id===id);
    for(const time of [initial.birth-.03,initial.birth+.10,(initial.birth+initial.arrival)/2,initial.arrival+.10]){
     const sample=r.api.assembly(time).particles.find(p=>p.id===id),arrays=gl.drawArrays,elements=gl.drawElements;
     try{
      gl.drawArrays=function(mode){if(mode===gl.POINTS)return arrays.call(gl,mode,id,1);return arrays.apply(gl,arguments);};
      gl.drawElements=function(mode,count){if(count>512)return;return elements.apply(gl,arguments);};
      r.api.setVoiceLevel(0);r.render(time);
      const pixels=new Uint8Array(c.width*c.height*4);gl.readPixels(0,0,c.width,c.height,gl.RGBA,gl.UNSIGNED_BYTE,pixels);
      let weight=0,sx=0,sy=0;
      for(let i=0;i<pixels.length;i+=4){const w=Math.max(pixels[i],pixels[i+1],pixels[i+2]);if(!w||!pixels[i+3])continue;weight+=w;sx+=(i/4%c.width+.5)*r.width/c.width*w;sy+=(c.height-Math.floor(i/4/c.width)-.5)*r.height/c.height*w;}
      const expected=sample.projected_position,predicted=Array.isArray(expected)?{x:expected[0],y:expected[1]}:expected;
      result.push({id,time,beforeBirth:time<initial.birth,weight,actual:weight?{x:sx/weight,y:sy/weight}:null,predicted,error:gl.getError()});
     }finally{gl.drawArrays=arrays;gl.drawElements=elements;}
    }
   }
   return result;
  });
  assembly.gpu.particleParity=particleParity;
  fs.writeFileSync(path.join(out,'assembly-diagnostics.json'),JSON.stringify(assembly,null,2));
  assert(particleParity.filter(p=>p.beforeBirth).every(p=>p.weight===0),'Actual GPU construction particles are absent before birth');assertions++;
  assert(particleParity.filter(p=>!p.beforeBirth).every(p=>p.weight>0&&p.predicted&&Math.hypot(p.actual.x-p.predicted.x,p.actual.y-p.predicted.y)<4),'Isolated GPU particles follow their shared-emitter diagnostic paths during travel and arrival');assertions++;
  assert(particleParity.every(p=>p.error===0),'Isolated-particle GPU projection checks remain error-free');assertions++;

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
  const mouth=await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram,states=[];for(const level of [0,.25,.7,1]){r.api.setVoiceLevel(level);r.render(90);states.push({level,mouthOpen:r.mouthOpen,gpuVoice:r.gl.getUniform(r.program,r.uniforms.uVoice)});}return states;});
  assert(mouth[0].mouthOpen===0&&mouth[3].mouthOpen>.08&&mouth.every((p,i,a)=>i===0||p.mouthOpen>a[i-1].mouthOpen),'Actual mouth deformation follows audio amplitude continuously');assertions++;
  assert(mouth.every(p=>Math.abs(p.gpuVoice-p.level)<.0001),'Audio envelope reaches the vertex shader, not merely a decorative status');assertions++;

  const project=await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram;const samples=[];for(const t of [0,62,66]){r.render(t);r.project(.43,1.2,0,1);const cheek={x:r.px,y:r.py};r.project(0,1.2,.35,1);samples.push({time:t,cheek,nose:{x:r.px,y:r.py}});}return samples;});
  assert(Math.abs(project[1].nose.x-project[2].nose.x)>60,'Real depth projects differently between left/right, rather than translating one flat image');assertions++;
  const fps=30,duration=24,count=process.env.HOLOGRAM_VERIFY_ONLY?0:duration*fps;
  const sourceTime=i=>i/fps<14.2?i/fps:60+i/fps-14.2;
  await page.evaluate(()=>{
   window.captureReviewFrame=(time,level,width,height)=>{
    const c=document.querySelector('[data-hud-robot]'),r=c.__goHologram;r.elapsed=time;r.api.setVoiceLevel(level);r.render(time);
    const dst=window.captureCanvas||(window.captureCanvas=document.createElement('canvas'));if(dst.width!==width)dst.width=width;if(dst.height!==height)dst.height=height;
    const ctx=dst.getContext('2d'),bg=ctx.createRadialGradient(width*.5,height*570/1080,height*40/1080,width*.5,height*570/1080,height*1000/1080);bg.addColorStop(0,'#062331');bg.addColorStop(.45,'#03131f');bg.addColorStop(1,'#010b14');ctx.fillStyle=bg;ctx.fillRect(0,0,width,height);ctx.drawImage(c,0,0,width,height);
    return dst.toDataURL('image/png').split(',')[1];
   };
  });
  for(let i=0;i<count;i++){
   const time=sourceTime(i),level=voiceAt(i/fps),png=await page.evaluate(({time,level})=>captureReviewFrame(time,level,1920,1080),{time,level});
   fs.writeFileSync(path.join(out,'frame-'+String(i).padStart(3,'0')+'.png'),Buffer.from(png,'base64'));
   if((i+1)%30===0)console.log('Captured '+(i+1)+'/'+count+' actual geometry frames');
  }
  const encode=['-hide_banner','-loglevel','error','-y','-framerate',String(fps),'-i',path.join(out,'frame-%03d.png')];
  if(audioFile){if(audioOffset)encode.push('-itsoffset',String(audioOffset));encode.push('-i',path.resolve(audioFile));}
  encode.push('-frames:v',String(count),'-c:v','libx264','-preset','medium','-crf','18','-pix_fmt','yuv420p');
  if(audioFile)encode.push('-c:a','aac','-b:a','160k','-af','apad','-t',String(duration));
  encode.push('-movflags','+faststart',path.join(out,'hologram-v3-turn-review.mp4'));
  if(count){const probe=spawnSync('ffmpeg',encode,{encoding:'utf8'});assert.equal(probe.status,0,probe.stderr);assertions++;}
  const highDPI=await browser.newContext({deviceScaleFactor:2});
  await highDPI.addInitScript(()=>{window.DashboardSPA={isCurrentPage:()=>true,onCleanup:()=>{}};window.fetch=()=>new Promise(()=>{});});
  for(const size of [{width:1920,height:1080},{width:2560,height:1440}]){
   await page.setViewportSize(size);await page.waitForTimeout(120);
   const settled=await page.evaluate(({level,size})=>captureReviewFrame(8,level,size.width,size.height),{level:voiceAt(8),size});
   fs.writeFileSync(path.join(out,'hologram-v3-settled-'+size.width+'x'+size.height+'.png'),Buffer.from(settled,'base64'));
   const layoutPage=await highDPI.newPage();layoutPage.on('pageerror',e=>errors.push(e.message));await layoutPage.setViewportSize(size);
   await layoutPage.goto('http://127.0.0.1:'+server.address().port+'/fixture?real=1&silent=1');await layoutPage.waitForTimeout(100);
   await layoutPage.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram;r.api.setVisible(false);r.render(8);document.activeElement.blur();});
   const bounds=await layoutPage.locator('.hud-card').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return{x:r.x,y:r.y,w:r.width,h:r.height,sw:n.scrollWidth,cw:n.clientWidth};}));
   assert.equal(bounds.length,10,'All production menu entries render at '+size.width);assertions++;
   assert(bounds.every(c=>c.x>=0&&c.x+c.w<=size.width&&c.sw<=c.cw+1),'No card/text clipping at '+size.width);assertions++;
   assert(bounds.every((c,i,a)=>a.every((b,j)=>i===j||c.x+c.w<=b.x+1||b.x+b.w<=c.x+1||c.y+c.h<=b.y+1||b.y+b.h<=c.y+1)),'No card overlap at '+size.width);assertions++;
   const density=await layoutPage.locator('[data-hud-robot]').evaluate(c=>({dpr:devicePixelRatio,pixel:c.width,css:c.clientWidth}));
   assert(density.dpr===2&&density.pixel>=density.css,'High-DPI renderer resolves native geometry without downscaling below CSS size');assertions++;
   await layoutPage.screenshot({path:path.join(out,'hologram-v3-layout-'+size.width+'x'+size.height+'.png'),scale:'css'});await layoutPage.close();
  }
  await highDPI.close();
  assert(await page.locator('.hud-card, [data-hud-who], [data-hud-key]').count()===0,'Final review contains actual robot only, no names or synthetic order data');assertions++;
  assert(!requests.some(u=>/go-hologram-bust\.(?:webp|png)|hologram.*\.(?:jpg|jpeg|png|webp)/i.test(u)),'Renderer loads no robot bitmap');assertions++;
  assert.deepEqual(errors,[],'Actual browser has no runtime error');assertions++;
  fs.writeFileSync(path.join(out,'validation.json'),JSON.stringify({assertions,frames:count,fps,audioOffset,geometry,textures,assembly,reviewTimeline:[{frames:[0,185],sourceTime:[0,6.1666667],purpose:'Unskipped construction from one bottom-center emitter'},{frames:[186,425],sourceTime:[6.2,14.1666667],purpose:'Approved greeting once'},{frames:[426,719],sourceTime:[60,69.7666667],purpose:'Recurring 60-second left/right neck turns'}],audio:audioFile?'Supplied greeting recording, played once':null,rendering:state,poses,turnPeaks:{left,right},mouth,projectedLandmarks:project,requests,errors},null,2));
  console.log('PASS v3 real-motion validation: '+assertions+' assertions; '+(count?'MP4 '+path.join(out,'hologram-v3-turn-review.mp4'):'verification only, no video recorded'));
  await context.close();
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;server.close();});
