'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {createRequire}=require('node:module');
const file=path.join(__dirname,'../src/local-runtime.cjs'),source=fs.readFileSync(file,'utf8');
const context=vm.createContext({module:{exports:{}},require:createRequire(file),Buffer,process,URL,Error,JSON,AbortSignal,fetch,setTimeout,clearTimeout});
vm.runInContext(source+'\nmodule.exports={LocalRuntime:module.exports,stopChild};',context,{filename:file});
const {LocalRuntime,stopChild}=context.module.exports;
const turn=()=>new Promise(resolve=>setImmediate(resolve));

test('a native child that emitted exit still drains its close/stdio before runtime cleanup finishes',async()=>{
  let finish,done=false,kills=0;
  const child={exitCode:0,signalCode:null,completed:new Promise(resolve=>{finish=resolve;}),failure:Promise.resolve(Error('exited')),kill(){kills++;}};
  const stopping=stopChild(child).then(()=>{done=true;});
  await turn();assert.equal(done,false,'an exit event alone must not finish native cleanup');assert.equal(kills,0);
  finish();await stopping;assert.equal(done,true);
});

test('a running native child waits for close after its termination exit event',async()=>{
  let finish,done=false;
  const child={exitCode:null,signalCode:null,completed:new Promise(resolve=>{finish=resolve;}),failure:Promise.resolve(Error('exited')),
    kill(){this.exitCode=0;return true;}};
  const stopping=stopChild(child).then(()=>{done=true;});
  await turn();assert.equal(done,false);finish();await stopping;assert.equal(done,true);
});

test('closing while startup has not launched services cancels without creating profile files or child processes',async()=>{
  const runtime=new LocalRuntime({bundle:'/unused-bundle',profile:'/unused-profile',safeStorage:{},platform:'win32'});
  runtime.cancelStartup();await assert.rejects(runtime.start(),/البرنامج يُغلق/);
  assert.equal(runtime.children.length,0);assert.equal(runtime.started,undefined);assert.equal(runtime.stopping,true);
});

test('startup cancellation does not block an already running accepted local operation',()=>{
  const runtime=new LocalRuntime({bundle:'/unused-bundle',profile:'/unused-profile',safeStorage:{},platform:'win32'});
  runtime.started=true;runtime.cancelStartup();assert.equal(runtime.stopping,false);
});
