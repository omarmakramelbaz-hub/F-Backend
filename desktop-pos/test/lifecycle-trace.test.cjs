'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const LifecycleTrace=require('../src/lifecycle-trace.cjs');

test('lifecycle support trace contains only fixed stages and bounded data-free timing records',t=>{
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'go-lifecycle-'));t.after(()=>fs.rmSync(directory,{recursive:true,force:true}));
  let time=1800000000000;
  const trace=new LifecycleTrace(directory,{now:()=>time,pid:1234});
  const secret='PRIVATE_TOKEN_ACCOUNT_PATH_AND_CUSTOMER';
  assert.equal(trace.mark(secret),false);assert.equal(trace.mark({phase:'startup-ready',secret}),false);
  assert.equal(fs.existsSync(trace.file),false);
  for(let index=0;index<200;index++){time+=5;assert.equal(trace.mark(['runtime-start','runtime-ready','dashboard-navigation'][index%3]),true);}
  const contents=fs.readFileSync(trace.file,'utf8'),record=JSON.parse(contents);
  assert.deepEqual(Object.keys(record).sort(),['events','format','pid','started_at']);
  assert.equal(record.format,1);assert.equal(record.pid,1234);assert.equal(record.events.length,64);
  assert.ok(Buffer.byteLength(contents)<12000);assert.equal(contents.includes(secret),false);assert.equal(contents.includes(directory),false);
  assert.ok(record.events.every(event=>Object.keys(event).sort().join(',')==='at,elapsed_ms,phase'));
  assert.equal(record.events.at(-1).elapsed_ms,1000);
  assert.deepEqual(fs.readdirSync(directory),['lifecycle-trace.json']);
  assert.equal(trace.mark('runtime-ready'),true);assert.equal(JSON.parse(fs.readFileSync(trace.file,'utf8')).events.length,64);
});

test('a new launch replaces the previous trace and an unavailable diagnostic directory cannot block native work',t=>{
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'go-lifecycle-'));t.after(()=>fs.rmSync(directory,{recursive:true,force:true}));
  fs.writeFileSync(path.join(directory,'lifecycle-trace.json'),'OLD_PRIVATE_DETAILS'.repeat(10000));
  const trace=new LifecycleTrace(directory,{pid:4321});assert.equal(trace.mark('startup-ready'),true);
  const record=JSON.parse(fs.readFileSync(trace.file,'utf8'));assert.equal(record.pid,4321);assert.equal(record.events.length,1);
  assert.equal(fs.readFileSync(trace.file,'utf8').includes('OLD_PRIVATE_DETAILS'),false);
  const unavailable=new LifecycleTrace(path.join(directory,'absent'));
  assert.doesNotThrow(()=>assert.equal(unavailable.mark('shutdown-complete'),false));
  assert.equal(fs.existsSync(path.join(directory,'absent')),false);
});

test('navigation classification retains only exact local/server, safe endpoint classes and HTTP status values',t=>{
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'go-lifecycle-'));t.after(()=>fs.rmSync(directory,{recursive:true,force:true}));
  const trace=new LifecycleTrace(directory),secret='PRIVATE_CUSTOMER_TOKEN_QUERY';
  trace.mark('dashboard-response',{mode:'local',path:'/admin/dashboard',status:419,url:secret,cookie:secret,origin:secret,error:secret});
  trace.mark('dashboard-response',{mode:'server',path:'/admin/login',status:200});
  trace.mark('dashboard-navigation-failed',{mode:secret,path:'/admin/dashboard?token='+secret,status:'419',message:secret});
  trace.mark('runtime-ready',{mode:'local',path:'/admin/dashboard',status:200});
  const contents=fs.readFileSync(trace.file,'utf8'),record=JSON.parse(contents);
  assert.deepEqual(record.events[0],{phase:'dashboard-response',at:record.events[0].at,elapsed_ms:record.events[0].elapsed_ms,mode:'local',path:'/admin/dashboard',status:419});
  assert.equal(record.events[1].mode,'server');assert.equal(record.events[1].path,'/admin/login');assert.equal(record.events[1].status,200);
  for(const event of record.events.slice(2))assert.equal(Object.keys(event).sort().join(','),'at,elapsed_ms,phase');
  assert.equal(contents.includes(secret),false);assert.equal(contents.includes('?'),false);
  trace.mark('dashboard-navigation-timeout',{mode:'local',path:'/admin/other',status:600});
  const timeout=JSON.parse(fs.readFileSync(trace.file,'utf8')).events.at(-1);assert.equal(timeout.status,undefined);assert.equal(timeout.path,'/admin/other');
});
