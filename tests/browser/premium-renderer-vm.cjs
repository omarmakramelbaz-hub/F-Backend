'use strict';
// GPU lifecycle contract only; shader correctness is checked in Chromium CI.
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict'),path=require('node:path');
const repo=path.resolve(__dirname,'../..');
const shaderSources=[],calls={mesh:0,points:0,ring:0,alloc:0,deleted:0,deletedTextures:0},listeners=new Map(),imageListeners=new Map();
let idx=0;
const gl=new Proxy({
 getShaderParameter:()=>true,getProgramParameter:()=>true,getAttribLocation:()=>idx++%6,getUniformLocation:(_,name)=>name,
 checkFramebufferStatus(){return this.FRAMEBUFFER_COMPLETE;},
 createShader:()=>({}),createProgram:()=>({}),createBuffer:()=>({}),createTexture:()=>({}),createFramebuffer:()=>({}),createRenderbuffer:()=>({}),
 shaderSource:(_,src)=>shaderSources.push(src),getExtension:()=>({}),
 drawElements:()=>calls.mesh++,drawArrays(mode){if(mode===this.POINTS)calls.points++;else if(mode===this.LINE_STRIP)calls.ring++;},texImage2D:()=>calls.alloc++,deleteBuffer:()=>calls.deleted++,deleteTexture:()=>calls.deletedTextures++
},{get(o,k){if(k in o)return o[k];if(typeof k==='string'&&k===k.toUpperCase())return o[k]=Object.keys(o).length+10;return ()=>{};}});
const img={complete:true,naturalWidth:1454,naturalHeight:1082,hidden:false,currentSrc:'/art.png',style:{setProperty(){},removeProperty(){}},getAttribute:()=>'/art.png',getBoundingClientRect:()=>({left:360,top:20,width:1200,height:893}),addEventListener:(k,v)=>imageListeners.set(k,v),removeEventListener:k=>imageListeners.delete(k)};
const host={dataset:{},style:{setProperty(){},removeProperty(){}},querySelector:()=>img};
const canvas={parentElement:host,dataset:{},isConnected:true,getContext:()=>gl,getBoundingClientRect:()=>({left:0,top:0,width:1920,height:1080}),setAttribute(){},addEventListener:(k,v)=>listeners.set(k,v),removeEventListener:k=>listeners.delete(k),dispatchEvent(){}};
const doc={hidden:false,addEventListener(){}};
const win={devicePixelRatio:1,matchMedia:()=>({matches:false,addEventListener(){},removeEventListener(){}}),requestAnimationFrame:()=>1,cancelAnimationFrame(){},addEventListener(){},removeEventListener(){},CustomEvent:function(){},ResizeObserver:class{observe(){}disconnect(){}},IntersectionObserver:class{observe(){}disconnect(){}}};
const context=vm.createContext({window:win,document:doc,Float32Array,Uint16Array,Promise,Math,Number,Array,String});vm.runInContext(fs.readFileSync(path.join(repo,'public/dashboard/js/go-hologram.js'),'utf8'),context);
const api=win.GoHologram.mount(canvas,{});assert(api);const instance=canvas.__goHologram;
assert.equal(api.diagnostics().artwork,true);assert.equal(api.diagnostics().portraitReady,true);assert(calls.alloc>=1);instance.render(9);assert(calls.mesh>0);assert(calls.points>0);assert.equal(calls.ring,0);assert.equal(api.diagnostics().swarmParticles,4096);assert.equal(api.diagnostics().swarmCapacity,65536);
assert.equal(api.diagnostics().portraitAnimated,true);assert(api.diagnostics().portraitParticles>=8192&&api.diagnostics().portraitParticles<=65536);assert.equal(api.diagnostics().assemblyProgress,1);
instance.render(.1);assert(api.diagnostics().assemblyProgress<.1);instance.render(2.6);assert(api.diagnostics().assemblyProgress>0&&api.diagnostics().assemblyProgress<1);
instance.render(8.1);assert(api.diagnostics().blink>.9);instance.render(8.6);assert(api.diagnostics().blink<.1);assert(Math.abs(api.diagnostics().yaw)<.075);assert(Math.abs(api.diagnostics().pitch)<.035);
api.setSpeaking(true);api.setVoiceLevel(1);instance.render(8.6);assert(api.diagnostics().mouthOpen>0);api.setSpeaking(false);instance.render(8.6);assert.equal(api.diagnostics().mouthOpen,0);
const quietYaw=api.diagnostics().yaw;api.setGaze(20,-20);for(let step=0;step<30;step++)instance.render(8.6);assert(api.diagnostics().yaw>quietYaw+.02);assert(Math.abs(api.diagnostics().yaw)<.075);assert(Math.abs(api.diagnostics().pitch)<.035);api.setGaze(0,0);
let prevented=0;listeners.get('webglcontextlost')({preventDefault(){prevented++;}});assert.equal(prevented,1);assert.equal(api.diagnostics().portraitReady,false);assert.equal(host.dataset.portraitGpu,undefined);const lostDraws=calls.mesh+calls.points;instance.render(9);assert.equal(calls.mesh+calls.points,lostDraws);
listeners.get('webglcontextrestored')();assert.equal(api.diagnostics().portraitReady,true);assert.equal(host.dataset.portraitGpu,'true');instance.render(9);assert(calls.mesh+calls.points>lostDraws);
imageListeners.get('error')();assert.equal(img.hidden,true);assert.equal(host.dataset.artworkFailed,'true');assert.equal(api.diagnostics().artwork,false);assert(calls.alloc>=3);instance.render(9);assert(calls.mesh>0);assert(calls.ring>0);
imageListeners.get('load')();assert.equal(img.hidden,false);assert.equal(api.diagnostics().artwork,true);assert.equal(instance.sceneFramebuffer,null);
api.setSpeaking(true);api.setAlert(true);assert.equal(host.dataset.speaking,'true');assert.equal(host.dataset.alert,'true');api.destroy();assert.equal(canvas.__goHologram,undefined);assert.equal(imageListeners.size,0);assert.equal(listeners.size,0);assert(calls.deleted>=9);assert(calls.deletedTextures>=1);
const noGL={...canvas,parentElement:host,dataset:{},getContext:()=>null};const originalHidden=img.hidden;assert.equal(win.GoHologram.mount(noGL,{}),null);assert.equal(img.hidden,originalHidden);
console.log(JSON.stringify({checks:48,shaderPrograms:shaderSources.length/2,calls,nativeFallback:true,artworkSurvivesNoWebGL:true,livingPortraitLifecycle:true,contextRecovery:true}));
