'use strict';
// Review artifact from actual live geometry, never a replacement promotional render.
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const {server}=require('./hologram-opening.cjs');
const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs'),{createHash}=require('node:crypto'),{spawnSync}=require('node:child_process');
const repo=path.resolve(__dirname,'../..'),out=path.resolve(process.env.HOLOGRAM_MOTION_OUTPUT||path.join(repo,'../analysis/hologram-v5-swarm'));
const reviewTag=process.env.HOLOGRAM_REVIEW_TAG||'v5',prefix='hologram-'+reviewTag;
const shapeReview=process.env.HOLOGRAM_SHAPE_REVIEW==='1';
const swarmLoop=process.env.HOLOGRAM_SWARM_LOOP==='1',energyLoop=swarmLoop||process.env.HOLOGRAM_ENERGY_LOOP==='1';
const fullReview=process.env.HOLOGRAM_FULL_REVIEW==='1';
const read=p=>fs.readFileSync(path.join(repo,p),'utf8');
const renderer=read('public/dashboard/js/go-hologram.js');
assert(!/new\s+(?:window\.)?Image\s*\(|\.drawImage\s*\(/.test(renderer),'Review renderer must be generated from actual 3D geometry, not an external bitmap or scanline warp');
let assemblyPreservation;
{
 const approved=JSON.parse(fs.readFileSync(path.join(__dirname,'hologram-approved-assembly.json'),'utf8'));
 const section=(source,start,end)=>{const a=source.indexOf(start),b=source.indexOf(end,a+start.length);assert(a>=0&&b>a,'Assembly comparison markers must exist: '+start);return source.slice(a,b);};
 const shaderBlock=(source,marker)=>{
  const start=source.indexOf(marker);assert(start>=0,'Approved shader block must exist: '+marker);let depth=0,opened=false;
  for(let i=start;i<source.length;i++){if(source[i]==='{'){depth++;opened=true;}if(source[i]==='}'&&opened)depth--;if(opened&&depth===0)return source.slice(start,source.indexOf('\n',i)+1);}
  assert.fail('Approved shader block must close: '+marker);
 };
 const segments=source=>{
  const vertex=section(source,'    var VERTEX = [','    var FRAGMENT = ['),fragment=section(source,'    var FRAGMENT = [','    var POST_VERTEX = ');
  return{
   emitterAndDuration:source.match(/    var ASSEMBLY_DURATION = [^\n]+/)[0],
   arrivalFor:section(source,'    function arrivalFor(p, part) {','    var ARRIVAL_SHADER = ['),
   arrivalShaderAndFountain:section(source,'    var ARRIVAL_SHADER = [','    var VERTEX = ['),
   particleFlightAndScatter:shaderBlock(vertex,"        ' if(uPointPass>.5){',"),
   surfaceArrivalReveal:shaderBlock(fragment,"        ' } else {',\n        '   float arrival=assemblyArrival")
  };
 };
 const current=segments(renderer);
 for(const [key,sha]of Object.entries(approved.sha256))assert.equal(createHash('sha256').update(current[key]).digest('hex'),sha,'Approved assembly must remain byte-identical: '+key);
 assemblyPreservation={reference:approved.reference,byteIdentical:Object.keys(approved.sha256)};
}
(async()=>{
 fs.mkdirSync(out,{recursive:true});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox','--enable-unsafe-swiftshader']});
 const errors=[],requests=[];let assertions=0;
 const envelopeFile=process.env.HOLOGRAM_VOICE_ENVELOPE;
 const envelope=envelopeFile?JSON.parse(fs.readFileSync(envelopeFile,'utf8')):null;
 const audioOffset=Number(process.env.HOLOGRAM_AUDIO_OFFSET||6.2);
 const voiceAt=t=>t<audioOffset?0:envelope&&Array.isArray(envelope.values)?Math.max(0,Math.min(1,Number(envelope.values[Math.min(envelope.values.length-1,Math.floor((t-audioOffset)*(envelope.sample_rate||envelope.samples_per_second||25)))])||0)):0;
 const audioFile=process.env.HOLOGRAM_REFERENCE_AUDIO;
 const sourceHashes={renderer:createHash('sha256').update(renderer).digest('hex'),audio:audioFile?createHash('sha256').update(fs.readFileSync(audioFile)).digest('hex'):null,envelope:envelopeFile?createHash('sha256').update(fs.readFileSync(envelopeFile)).digest('hex'):null};
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
  const geometry=await page.locator('[data-hud-robot]').evaluate(c=>{
   const r=c.__goHologram,g=r.gl,vertices=r.mesh.vertices;let minZ=Infinity,maxZ=-Infinity,bodyMin=Infinity,bodyMax=-Infinity,headMin=Infinity,headMax=-Infinity;
   for(let i=0;i<vertices.length;i+=9){minZ=Math.min(minZ,vertices[i+2]);maxZ=Math.max(maxZ,vertices[i+2]);if(vertices[i+8]===0){bodyMin=Math.min(bodyMin,vertices[i]);bodyMax=Math.max(bodyMax,vertices[i]);}if(vertices[i+8]===1){headMin=Math.min(headMin,vertices[i]);headMax=Math.max(headMax,vertices[i]);}}
   return{webgl:g instanceof WebGLRenderingContext,vertices:r.mesh.vertexCount,triangles:r.mesh.indices.length/3,particles:r.particles.count,minZ,maxZ,bodyWidth:bodyMax-bodyMin,headWidth:headMax-headMin,renderer:g.getParameter(g.RENDERER),error:g.getError()};
  });
  assert(geometry.webgl&&geometry.vertices>15000&&geometry.triangles>20000&&geometry.maxZ-geometry.minZ>.6,'Model contains a genuinely volumetric triangulated human surface');assertions++;
  assert(geometry.particles>=1500,'Living figure includes a geometric particle cloud');assertions++;
  assert(geometry.bodyWidth<2.7&&geometry.bodyWidth/geometry.headWidth<2.75,'Shoulder span is visibly narrower than the previous broad-shouldered model');assertions++;
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
   const headRanges=[];let headStart=-1;
   for(let i=0;i<=r.mesh.indices.length;i+=3){const part=i<r.mesh.indices.length?vertices[r.mesh.indices[i]*9+8]:0,isHead=part>.5&&part<2.5;if(isHead&&headStart<0)headStart=i;if(!isHead&&headStart>=0){headRanges.push({offset:headStart*2,count:i-headStart});headStart=-1;}}
   window.sampleAssemblyPixels=(time,layer)=>{
    const gl=r.gl,arrays=gl.drawArrays,elements=gl.drawElements;
    try{
     if(layer==='mesh'||layer==='head')gl.drawArrays=function(mode){if(mode===gl.POINTS)return;return arrays.apply(gl,arguments);};
     if(layer==='head')gl.drawElements=function(mode,count,type,offset){if(count<=512)return elements.apply(gl,arguments);for(const range of headRanges)elements.call(gl,mode,range.count,type,offset+range.offset);};
     if(layer==='particles')gl.drawElements=function(mode,count){if(count>512)return;return elements.apply(gl,arguments);};
     r.api.setVoiceLevel(0);r.render(time);
     // Contour arrival is measured before bloom, which may legitimately spread neck light into the head ROI.
     const raw=layer==='mesh'||layer==='head',pixels=new Uint8Array(c.width*c.height*4);if(raw)gl.bindFramebuffer(gl.FRAMEBUFFER,r.sceneFramebuffer);gl.readPixels(0,0,c.width,c.height,gl.RGBA,gl.UNSIGNED_BYTE,pixels);gl.bindFramebuffer(gl.FRAMEBUFFER,null);
     let strong=0,outsideEmitter=0,headPixels=0,borderPixels=0;
     for(let i=0;i<pixels.length;i+=4){if(Math.max(pixels[i],pixels[i+1],pixels[i+2])<24||pixels[i+3]<8)continue;strong++;const x=(i/4%c.width)*r.width/c.width,y=(c.height-1-Math.floor(i/4/c.width))*r.height/c.height;
      if(Math.hypot(x-emitter.x,y-emitter.y)>radius)outsideEmitter++;
      if(x>=head.left&&x<=head.right&&y>=head.top&&y<=head.bottom)headPixels++;
      if(x<r.width*.03||x>r.width*.97||y<r.height*.03||y>r.height*.97)borderPixels++;
     }
     return{time,layer,framebuffer:raw?'native geometry before bloom':'final composite',strong,outsideEmitter,headPixels,borderPixels,error:gl.getError()};
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
  const headBefore=await page.evaluate(time=>sampleAssemblyPixels(time,'head'),arrival.headFirst-.20);
  const middleHead=await page.evaluate(time=>sampleAssemblyPixels(time,'head'),arrival.headFirst+(arrival.headLast-arrival.headFirst)*.33);
  const assembled=await page.evaluate(time=>sampleAssemblyPixels(time,'head'),assembly.duration+.3);
  assembly.gpu={early,preSurface,preHead,headBefore,middleHead,assembled};
  fs.writeFileSync(path.join(out,'assembly-diagnostics.json'),JSON.stringify(assembly,null,2));
  assert(early.strong>10&&early.outsideEmitter<=Math.max(5,early.strong*.002)&&early.borderPixels===0,'Initial GPU image contains the emitter only, never a full-screen construction cloud');assertions++;
  assert(preSurface.outsideEmitter<=5,'Mesh contours cannot appear before the first particle arrivals');assertions++;
  assert.equal(headBefore.strong,0,'Isolated actual head triangles remain completely dark before head particles arrive');assertions++;
  assert(preHead.outsideEmitter>20,'Torso contour trail forms before head construction, matching the reference sequence');assertions++;
  assert(assembled.headPixels>100&&middleHead.headPixels<assembled.headPixels*.80,'Head contour construction stays local and progressive until particles settle');assertions++;
  assert([early,preSurface,preHead,headBefore,middleHead,assembled].every(p=>p.error===0),'Independent GPU layer sampling remains error-free');assertions++;

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
  const posture=await page.locator('[data-hud-robot]').evaluate(c=>{
   const r=c.__goHologram,poses=[],pointer=[];let maxHeadYaw=0,maxBodyYaw=0,maxPitch=0;
   for(let time=0;time<=180;time+=.25){r.pose(time);maxHeadYaw=Math.max(maxHeadYaw,Math.abs(r.headYaw));maxBodyYaw=Math.max(maxBodyYaw,Math.abs(r.bodyYaw));maxPitch=Math.max(maxPitch,Math.abs(r.pitch));if(time%30===0)poses.push({time,headYaw:r.headYaw,bodyYaw:r.bodyYaw,pitch:r.pitch});}
   for(const[x,y]of[[-1,-1],[1,1],[-1,1],[1,-1]]){r.api.setGaze(x,y);r.pose(62.5);pointer.push({kind:'gaze',x,y,headYaw:r.headYaw,bodyYaw:r.bodyYaw,pitch:r.pitch});}
   r.api.setGaze(0,0);for(const[x,y]of[[0,0],[innerWidth,innerHeight]]){document.dispatchEvent(new PointerEvent('pointermove',{clientX:x,clientY:y,bubbles:true}));r.render(8);pointer.push({kind:'pointer',gazeX:r.gazeX,gazeY:r.gazeY,headYaw:r.headYaw,bodyYaw:r.bodyYaw,pitch:r.pitch,gpuHead:r.gl.getUniform(r.program,r.uniforms.uHeadYaw),gpuBody:r.gl.getUniform(r.program,r.uniforms.uBodyYaw),gpuPitch:r.gl.getUniform(r.program,r.uniforms.uPitch)});}
   r.api.setGaze(0,0);return{samples:721,maxHeadYaw,maxBodyYaw,maxPitch,poses,pointer};
  });
  assert(posture.maxHeadYaw===0&&posture.maxBodyYaw===0&&posture.maxPitch===0,'Head and shoulders keep a fixed posture from zero through180 seconds, including prior minute-cycle times');assertions++;
  assert(posture.pointer.every(p=>p.headYaw===0&&p.bodyYaw===0&&p.pitch===0),'Pointer and direct gaze input cannot rotate the hologram');assertions++;
  assert(posture.pointer.filter(p=>p.kind==='pointer').every(p=>p.gazeX===0&&p.gazeY===0&&p.gpuHead===0&&p.gpuBody===0&&p.gpuPitch===0),'Production-equivalent pointer movement cannot change gaze or GPU rotation uniforms');assertions++;
  const voiceReady=await page.locator('[data-hud-robot]').evaluate(c=>typeof c.__goHologram.api.setVoiceLevel==='function');
  assert(voiceReady,'Renderer exposes real audio amplitude input for mouth motion');assertions++;
  const mouth=await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram,states=[];for(const level of [0,.25,.7,1]){r.api.setVoiceLevel(level);r.render(90);states.push({level,mouthOpen:r.mouthOpen,gpuVoice:r.gl.getUniform(r.program,r.uniforms.uVoice)});}return states;});
  assert(mouth[0].mouthOpen===0&&mouth[3].mouthOpen>.08&&mouth.every((p,i,a)=>i===0||p.mouthOpen>a[i-1].mouthOpen),'Actual mouth deformation follows audio amplitude continuously');assertions++;
  assert(mouth.every(p=>Math.abs(p.gpuVoice-p.level)<.0001),'Audio envelope reaches the vertex shader, not merely a decorative status');assertions++;

  const project=await page.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram;r.pose(8);r.project(.43,1.2,0,1);const far={x:r.px,y:r.py};r.project(.43,1.2,.35,1);return{far,near:{x:r.px,y:r.py}};});
  assert(project.near.x-project.far.x>5,'Stationary volumetric geometry retains real perspective depth without head rotation');assertions++;
  const energy=await page.locator('[data-hud-robot]').evaluate(c=>{
   const r=c.__goHologram,gl=r.gl;if(typeof r.api.energy!=='function'||!r.energyProgram)return null;
   r.api.setVoiceLevel(0);
   const a=r.api.energy(8),b=r.api.energy(9),early=r.api.energy(5.59),next=new Map(b.samples.map(p=>[p.id,p]));
   const eligible=p=>{const q=next.get(p.id);if(!q)return false;return[p,q].every(s=>s.group<4?s.local_position[1]>.68&&s.local_position[1]<1.58:s.group<6?s.local_position[1]>-.12&&s.local_position[1]<.54:s.group<8?Math.abs(s.local_position[0])>.02&&Math.abs(s.local_position[0])<1.2:true);};
   const selected=['blue','orange'].flatMap(color=>a.samples.filter(p=>p.color===color&&eligible(p)).slice(0,2)),observations=[];
   let earlyDraws=0;
   const original=gl.drawArrays;try{gl.drawArrays=function(mode){if(mode===gl.POINTS&&gl.getParameter(gl.CURRENT_PROGRAM)===r.energyProgram)earlyDraws++;return original.apply(gl,arguments);};r.render(5.59);}finally{gl.drawArrays=original;}
   function render(time,kind,id){
    const arrays=gl.drawArrays;let drawn=false;
    try{
     gl.drawArrays=function(mode){
      if(mode!==gl.POINTS)return arrays.apply(gl,arguments);
      if(kind==='mesh'||gl.getParameter(gl.CURRENT_PROGRAM)!==r.energyProgram)return;
      if(kind==='field')return arrays.apply(gl,arguments);
      if(drawn)return;drawn=true;gl.uniform1f(r.energyUniforms.uTrail,0);return arrays.call(gl,mode,id,1);
     };
     r.render(time);const pixels=new Uint8Array(c.width*c.height*4);gl.readPixels(0,0,c.width,c.height,gl.RGBA,gl.UNSIGNED_BYTE,pixels);return pixels;
    }finally{gl.drawArrays=arrays;}
   }
   let colorPixels={blue:0,orange:0};
   for(const time of [8,9]){
    const reference=render(time,'mesh');
    if(time===8){const field=render(time,'field');for(let i=0;i<field.length;i+=4){const red=Math.max(0,field[i]-reference[i]),green=Math.max(0,field[i+1]-reference[i+1]),blue=Math.max(0,field[i+2]-reference[i+2]);if(blue>20&&blue>red*1.4&&green>6)colorPixels.blue++;if(red>20&&red>blue*1.4&&green>6)colorPixels.orange++;}}
    for(const entry of selected){
     const sample=(time===8?a:b).samples.find(p=>p.id===entry.id),pixels=render(time,'one',entry.id);let weight=0,sx=0,sy=0;
     for(let i=0;i<pixels.length;i+=4){const w=Math.max(0,pixels[i]-reference[i],pixels[i+1]-reference[i+1],pixels[i+2]-reference[i+2]);if(!w)continue;weight+=w;sx+=(i/4%c.width+.5)*r.width/c.width*w;sy+=(c.height-Math.floor(i/4/c.width)-.5)*r.height/c.height*w;}
     observations.push({id:sample.id,color:sample.color,time,weight,actual:weight?{x:sx/weight,y:sy/weight}:null,predicted:sample.projected_position,local_position:sample.local_position});
    }
   }
   const movement=selected.map(p=>{const start=observations.find(s=>s.id===p.id&&s.time===8),end=observations.find(s=>s.id===p.id&&s.time===9);return{id:p.id,color:p.color,pixels:start.actual&&end.actual?Math.hypot(start.actual.x-end.actual.x,start.actual.y-end.actual.y):0};});
   return{count:a.count,enabled:a.enabled,earlyEnabled:early.enabled,earlyDraws,colorPixels,selected:selected.map(p=>({id:p.id,color:p.color,group:p.group})),observations,movement,zSpan:Math.max(...a.samples.map(p=>p.local_position[2]))-Math.min(...a.samples.map(p=>p.local_position[2])),error:gl.getError()};
  });
  fs.writeFileSync(path.join(out,'energy-diagnostics.json'),JSON.stringify(energy,null,2));
  assert(energy&&energy.enabled&&energy.count>=1000&&['blue','orange'].every(color=>energy.selected.some(p=>p.color===color)),'Settled hologram exposes bounded internal blue and orange particle currents');assertions++;
  assert(!energy.earlyEnabled&&energy.earlyDraws===0,'New internal flow never renders during the approved pre-5.6-second construction');assertions++;
  assert(energy.colorPixels.blue>50&&energy.colorPixels.orange>50,'Actual internal point pass adds clearly visible blue and orange pixels through the real translucent mesh');assertions++;
  assert(energy.observations.every(p=>p.weight>5&&p.actual&&Math.hypot(p.actual.x-p.predicted.x,p.actual.y-p.predicted.y)<4),'Internal particle GPU differences remain visible through the mesh and match their 3D projected locations');assertions++;
  assert(['blue','orange'].every(color=>energy.movement.some(p=>p.color===color&&p.pixels>4)),'Both blue and orange interior particles visibly travel between settled frames');assertions++;
  assert(energy.zSpan>.1&&energy.observations.every(p=>Math.abs(p.local_position[0])<1.35&&Math.abs(p.local_position[2])<.33&&p.local_position[1]>-.3&&p.local_position[1]<1.8),'Moving currents occupy the bounded three-dimensional interior instead of a full-screen overlay');assertions++;
  assert.equal(energy.error,0,'Internal-energy GPU validation remains error-free');assertions++;
  const swarm=await page.locator('[data-hud-robot]').evaluate(c=>{
   const r=c.__goHologram,gl=r.gl;if(typeof r.api.swarm!=='function'||!r.swarmProgram)return null;
   r.api.setVoiceLevel(0);r.pose(8);const outline={left:Infinity,right:-Infinity,top:Infinity,bottom:-Infinity},v=r.mesh.vertices;
   for(let i=0;i<v.length;i+=9){if(v[i+8]===0&&v[i+1]<-.4)continue;r.project(v[i],v[i+1],v[i+2],v[i+8]>.5?1:0);outline.left=Math.min(outline.left,r.px);outline.right=Math.max(outline.right,r.px);outline.top=Math.min(outline.top,r.py);outline.bottom=Math.max(outline.bottom,r.py);}
   const a=r.api.swarm(8),b=r.api.swarm(9),early=r.api.swarm(5.59),next=new Map(b.samples.map(p=>[p.id,p]));
   const visible=p=>{const q=next.get(p.id);return q&&[p,q].every(s=>s.projected_position.x>10&&s.projected_position.x<r.width-10&&s.projected_position.y>10&&s.projected_position.y<r.height-10);};
   const selected=['blue','orange'].flatMap(color=>a.samples.filter(p=>p.color===color&&visible(p)).slice(0,2)),observations=[],draws=[];
   let earlyDraws=0;const original=gl.drawArrays;
   try{gl.drawArrays=function(mode){if(mode===gl.POINTS&&gl.getParameter(gl.CURRENT_PROGRAM)===r.swarmProgram)earlyDraws++;return original.apply(gl,arguments);};r.render(5.59);}finally{gl.drawArrays=original;}
   function render(time,kind,id){
    const arrays=gl.drawArrays;
    try{
     gl.drawArrays=function(mode,first,count){if(mode!==gl.POINTS)return arrays.apply(gl,arguments);if(kind==='mesh'||gl.getParameter(gl.CURRENT_PROGRAM)!==r.swarmProgram)return;if(kind==='field'){draws.push({time,first,count});return arrays.apply(gl,arguments);}return arrays.call(gl,mode,id,1);};
     r.render(time);const pixels=new Uint8Array(c.width*c.height*4);gl.readPixels(0,0,c.width,c.height,gl.RGBA,gl.UNSIGNED_BYTE,pixels);return pixels;
    }finally{gl.drawArrays=arrays;}
   }
   const colorPixels={blue:0,orange:0},around={left:0,right:0,above:0,below:0};
   for(const time of [8,9]){
    const reference=render(time,'mesh');
    if(time===8){const field=render(time,'field');for(let i=0;i<field.length;i+=4){const red=Math.max(0,field[i]-reference[i]),green=Math.max(0,field[i+1]-reference[i+1]),blue=Math.max(0,field[i+2]-reference[i+2]);if(blue>8&&blue>red*1.4&&green>2)colorPixels.blue++;if(red>8&&red>blue*1.4&&green>2)colorPixels.orange++;if(Math.max(red,green,blue)>8){const x=(i/4%c.width+.5)*r.width/c.width,y=(c.height-Math.floor(i/4/c.width)-.5)*r.height/c.height;if(x<outline.left-12)around.left++;if(x>outline.right+12)around.right++;if(y<outline.top-12)around.above++;if(y>outline.bottom+12)around.below++;}}}
    for(const entry of selected){const sample=(time===8?a:b).samples.find(p=>p.id===entry.id),pixels=render(time,'one',entry.id);let weight=0,sx=0,sy=0;for(let i=0;i<pixels.length;i+=4){const w=Math.max(0,pixels[i]-reference[i],pixels[i+1]-reference[i+1],pixels[i+2]-reference[i+2]);if(!w)continue;weight+=w;sx+=(i/4%c.width+.5)*r.width/c.width*w;sy+=(c.height-Math.floor(i/4/c.width)-.5)*r.height/c.height*w;}observations.push({id:sample.id,color:sample.color,time,weight,actual:weight?{x:sx/weight,y:sy/weight}:null,predicted:sample.projected_position});}
   }
   const movement=selected.map(p=>{const start=observations.find(s=>s.id===p.id&&s.time===8),end=observations.find(s=>s.id===p.id&&s.time===9);return{id:p.id,color:p.color,pixels:start.actual&&end.actual?Math.hypot(start.actual.x-end.actual.x,start.actual.y-end.actual.y):0};});
   return{capacity:a.capacity,count:a.count,adaptive:a.adaptive,enabled:a.enabled,earlyEnabled:early.enabled,earlyDraws,draws,colorPixels,around,outline,observations,movement,error:gl.getError()};
  });
  fs.writeFileSync(path.join(out,'swarm-diagnostics.json'),JSON.stringify(swarm,null,2));
  assert(swarm&&swarm.enabled&&!swarm.adaptive&&swarm.count===swarm.capacity&&swarm.count>=2000000,'Desktop review uses a real full-density swarm of at least two million vertices');assertions++;
  assert(swarm.draws.length===1&&swarm.draws[0].first===0&&swarm.draws[0].count===swarm.count,'GPU submits the reported swarm vertices in one actual POINTS draw');assertions++;
  assert(!swarm.earlyEnabled&&swarm.earlyDraws===0,'Outer swarm remains absent throughout the approved construction phase');assertions++;
  assert(swarm.colorPixels.blue>500&&swarm.colorPixels.orange>500&&Object.values(swarm.around).every(n=>n>50),'Both swarm colors visibly surround the entire figure on the left, right, above and below');assertions++;
  assert(['blue','orange'].every(color=>swarm.observations.some(p=>p.color===color))&&swarm.observations.every(p=>p.weight>5&&p.actual&&Math.hypot(p.actual.x-p.predicted.x,p.actual.y-p.predicted.y)<4),'Actual outer motes match their projected moving positions in the GPU image');assertions++;
  assert(['blue','orange'].every(color=>swarm.movement.some(p=>p.color===color&&p.pixels>4)),'Blue and orange outer motes visibly travel while the head remains fixed');assertions++;
  assert.equal(swarm.error,0,'Outer swarm renders without GPU errors');assertions++;
  const fps=30,duration=energyLoop?3:24,count=energyLoop?90:(!fullReview||shapeReview||process.env.HOLOGRAM_VERIFY_ONLY?0:duration*fps);
  const sourceTime=i=>energyLoop?8+i/fps:(i/fps<14.2?i/fps:60+i/fps-14.2);
  await page.evaluate(()=>{
   window.captureReviewFrame=(time,level,width,height)=>{
    const c=document.querySelector('[data-hud-robot]'),r=c.__goHologram;r.elapsed=time;r.api.setVoiceLevel(level);r.render(time);
    const dst=window.captureCanvas||(window.captureCanvas=document.createElement('canvas'));if(dst.width!==width)dst.width=width;if(dst.height!==height)dst.height=height;
    const ctx=dst.getContext('2d'),bg=ctx.createRadialGradient(width*.5,height*570/1080,height*40/1080,width*.5,height*570/1080,height*1000/1080);bg.addColorStop(0,'#062331');bg.addColorStop(.45,'#03131f');bg.addColorStop(1,'#010b14');ctx.fillStyle=bg;ctx.fillRect(0,0,width,height);ctx.drawImage(c,0,0,width,height);
    return dst.toDataURL('image/png').split(',')[1];
   };
  });
  for(let i=0;i<count;i++){
   const time=sourceTime(i),level=energyLoop?0:voiceAt(i/fps),png=await page.evaluate(({time,level})=>captureReviewFrame(time,level,1920,1080),{time,level});
   fs.writeFileSync(path.join(out,'frame-'+String(i).padStart(3,'0')+'.png'),Buffer.from(png,'base64'));
   if((i+1)%30===0)console.log('Captured '+(i+1)+'/'+count+' actual geometry frames');
  }
  const encode=['-hide_banner','-loglevel','error','-y','-framerate',String(fps),'-i',path.join(out,'frame-%03d.png')];
  if(audioFile&&!energyLoop)encode.push('-i',path.resolve(audioFile));
  encode.push('-frames:v',String(count),'-c:v','libx264','-preset','medium','-crf','18','-pix_fmt','yuv420p');
  if(audioFile&&!energyLoop)encode.push('-c:a','aac','-b:a','160k','-af','adelay='+Math.round(audioOffset*1000)+':all=1,apad,atrim=0:'+duration,'-t',String(duration));
  const movie=path.join(out,prefix+(swarmLoop?'-swarm-loop.mp4':energyLoop?'-energy-loop.mp4':'-review.mp4'));
  encode.push('-movflags','+faststart',movie);
  if(count){const probe=spawnSync('ffmpeg',encode,{encoding:'utf8'});assert.equal(probe.status,0,probe.stderr);assertions++;}
  const highDPI=await browser.newContext({deviceScaleFactor:2});
  await highDPI.addInitScript(()=>{window.DashboardSPA={isCurrentPage:()=>true,onCleanup:()=>{}};window.fetch=()=>new Promise(()=>{});});
  for(const size of shapeReview?[{width:1920,height:1080}]:[{width:1920,height:1080},{width:2560,height:1440}]){
   await page.setViewportSize(size);await page.waitForTimeout(120);
   const settled=await page.evaluate(({level,size})=>captureReviewFrame(8,level,size.width,size.height),{level:voiceAt(8),size});
   fs.writeFileSync(path.join(out,prefix+'-front-'+size.width+'x'+size.height+'.png'),Buffer.from(settled,'base64'));
   const layoutPage=await highDPI.newPage();layoutPage.on('pageerror',e=>errors.push(e.message));await layoutPage.setViewportSize(size);
   await layoutPage.goto('http://127.0.0.1:'+server.address().port+'/fixture?real=1&silent=1');await layoutPage.waitForTimeout(100);
   await layoutPage.locator('[data-hud-robot]').evaluate(c=>{const r=c.__goHologram;r.api.setVisible(false);r.render(8);document.activeElement.blur();});
   const bounds=await layoutPage.locator('.hud-card').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return{x:r.x,y:r.y,w:r.width,h:r.height,sw:n.scrollWidth,cw:n.clientWidth};}));
   assert.equal(bounds.length,10,'All production menu entries render at '+size.width);assertions++;
   assert(bounds.every(c=>c.x>=0&&c.x+c.w<=size.width&&c.sw<=c.cw+1),'No card/text clipping at '+size.width);assertions++;
   assert(bounds.every((c,i,a)=>a.every((b,j)=>i===j||c.x+c.w<=b.x+1||b.x+b.w<=c.x+1||c.y+c.h<=b.y+1||b.y+b.h<=c.y+1)),'No card overlap at '+size.width);assertions++;
   const density=await layoutPage.locator('[data-hud-robot]').evaluate(c=>({dpr:devicePixelRatio,pixel:c.width,css:c.clientWidth}));
   assert(density.dpr===2&&density.pixel>=density.css,'High-DPI renderer resolves native geometry without downscaling below CSS size');assertions++;
   if(!shapeReview)await layoutPage.screenshot({path:path.join(out,prefix+'-layout-'+size.width+'x'+size.height+'.png'),scale:'css'});await layoutPage.close();
  }
  await highDPI.close();
  const reducedContext=await browser.newContext({viewport:{width:1920,height:1080},reducedMotion:'reduce'}),reducedPage=await reducedContext.newPage();
  reducedPage.on('pageerror',e=>errors.push(e.message));await reducedPage.goto('http://127.0.0.1:'+server.address().port+'/fixture?robotonly=1');
  const reducedMotion=await reducedPage.locator('[data-hud-robot]').evaluate(c=>{
   const r=c.__goHologram,gl=r.gl;r.api.setVisible(false);r.api.setVoiceLevel(.85);r.render(8);
   const before=new Uint8Array(c.width*c.height*4),after=new Uint8Array(before.length);gl.readPixels(0,0,c.width,c.height,gl.RGBA,gl.UNSIGNED_BYTE,before);r.render(9);gl.readPixels(0,0,c.width,c.height,gl.RGBA,gl.UNSIGNED_BYTE,after);
   let changed=0,lit=0;for(let i=0;i<before.length;i+=4){if(before[i]!==after[i]||before[i+1]!==after[i+1]||before[i+2]!==after[i+2]||before[i+3]!==after[i+3])changed++;if(Math.max(before[i],before[i+1],before[i+2])>24&&before[i+3]>8)lit++;}
   return{reduced:r.reduced,changedPixels:changed,litPixels:lit,error:gl.getError()};
  });
  assert(reducedMotion.reduced&&reducedMotion.changedPixels===0&&reducedMotion.litPixels>100&&reducedMotion.error===0,'Reduced-motion renders the complete energy hologram with pixel-identical settled frames');assertions++;
  const sidebarAlpha=await reducedPage.evaluate(()=>{
   const c=document.createElement('canvas');c.style.cssText='width:96px;height:96px;';document.body.appendChild(c);const api=GoHologram.mount(c,{compact:true}),r=c.__goHologram,gl=r.gl;r.render(8);const alpha=[];
   for(const[x,y]of[[0,0],[c.width-1,0],[0,c.height-1],[c.width-1,c.height-1]]){const p=new Uint8Array(4);gl.readPixels(x,y,1,1,gl.RGBA,gl.UNSIGNED_BYTE,p);alpha.push(p[3]);}api.destroy();c.remove();return alpha;
  });
  assert.deepEqual(sidebarAlpha,[0,0,0,0],'New energy pass preserves transparent compact sidebar corners');assertions++;
  await reducedContext.close();
  assert(await page.locator('.hud-card, [data-hud-who], [data-hud-key]').count()===0,'Final review contains actual robot only, no names or synthetic order data');assertions++;
  assert(!requests.some(u=>/go-hologram-bust\.(?:webp|png)|hologram.*\.(?:jpg|jpeg|png|webp)/i.test(u)),'Renderer loads no robot bitmap');assertions++;
  assert.deepEqual(errors,[],'Actual browser has no runtime error');assertions++;
  fs.writeFileSync(path.join(out,'validation.json'),JSON.stringify({assertions,frames:count,fps,audioOffset,sourceHashes,geometry,textures,assembly,assemblyPreservation,energy,swarm,reducedMotion,sidebarAlpha,stills:{frontTime:8,voiceLevel:voiceAt(8)},reviewTimeline:energyLoop?[{frames:[0,89],sourceTime:[8,10.9666667],purpose:swarmLoop?'Dense moving outer blue/orange swarm around the fixed hologram, no audio':'Visible internal orange/blue currents, no speech or audio track'}]:[{frames:[0,185],sourceTime:[0,6.1666667],purpose:'Unskipped construction from one bottom-center emitter'},{frames:[186,425],sourceTime:[6.2,14.1666667],purpose:'Approved greeting once'},{frames:[426,719],sourceTime:[60,69.7666667],purpose:'Settled hologram with fixed head and body posture'}],audio:audioFile?(count&&!energyLoop?'Supplied greeting recording, played once':'Approved recording retained; no audio export'):null,rendering:state,posture,mouth,projectedLandmarks:project,requests,errors},null,2));
  console.log('PASS '+reviewTag+' real-motion validation: '+assertions+' assertions; '+(count?'MP4 '+movie:'verification only, no video recorded'));
  await context.close();
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;server.close();});
