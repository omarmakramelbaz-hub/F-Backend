'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const DashboardSync=require('../src/dashboard-sync.cjs'),SyncSchedule=require('../src/sync-schedule.cjs');
const settle=()=>new Promise(setImmediate);
function clock(){
  let now=0,id=0;const timers=new Map();
  return {now:()=>now,setTimer(fn,delay){const key=++id;timers.set(key,{at:now+delay,fn});return key;},clearTimer:key=>timers.delete(key),
    async advance(target){for(;;){const next=[...timers].sort(([,a],[,b])=>a.at-b.at)[0];if(!next||next[1].at>target)break;
      now=next[1].at;timers.delete(next[0]);next[1].fn();await settle();}now=target;await settle();}};
}

test('the actual shared 30-second schedule uploads later sales while an incoming refresh spans multiple slots',async()=>{
  const time=clock(),queue=[{command_id:'first'}],sent=[],starts=[];let release;
  const sync=new DashboardSync({...time,backgroundRefresh:true,
    local:async input=>{if(input.action==='acknowledge')queue.shift();return {commands:queue.slice(0,1),counts:{pending:queue.length,conflicts:0}};},
    remote:async command=>{sent.push({id:command.command_id,at:time.now()});return {committed:true};},
    refresh:async()=>{starts.push(time.now());if(starts.length===1)await new Promise(resolve=>release=resolve);return {unchanged:true};}});
  const schedule=new SyncSchedule({...time,work:()=>sync.run()});schedule.start();
  await time.advance(30000);const background=sync.refreshing;assert.ok(background);assert.equal(schedule.running,null);
  assert.deepEqual(starts,[30000]);assert.equal(sync.nextRefresh,60000);
  queue.push({command_id:'second'});await time.advance(60000);assert.equal(queue.length,0);assert.equal(sync.refreshing,background);
  queue.push({command_id:'third'});await time.advance(90000);
  assert.deepEqual(sent,[{id:'first',at:30000},{id:'second',at:60000},{id:'third',at:90000}]);assert.deepEqual(starts,[30000]);
  await time.advance(95000);release();await background;assert.equal(sync.refreshing,null);assert.equal(sync.nextRefresh,60000);
  assert.deepEqual(starts,[30000]);await time.advance(119999);assert.deepEqual(starts,[30000]);
  await time.advance(120000);assert.deepEqual(starts,[30000,120000]);schedule.stop();sync.stop();
});

test('quiet incoming checks use each 30-second slot instead of adding a delay after completion',async()=>{
  const time=clock(),starts=[];const sync=new DashboardSync({...time,backgroundRefresh:true,
    local:async()=>({commands:[],counts:{pending:0,conflicts:0}}),remote:async()=>{},refresh:async()=>{starts.push(time.now());return {unchanged:true};}});
  const schedule=new SyncSchedule({...time,work:()=>sync.run()});schedule.start();await time.advance(90000);
  assert.deepEqual(starts,[30000,60000,90000]);assert.equal(sync.refreshInterval,30000);schedule.stop();sync.stop();
});

test('the final cutover defers throughout remote send and local acknowledgement, then atomically excludes a new upload',async()=>{
  const queue=[{command_id:'original-uuid'}],calls=[];let releaseRemote,releaseAck,releaseHandoff;
  const sync=new DashboardSync({backgroundRefresh:true,
    local:async input=>{calls.push(input.action);if(input.action==='acknowledge'){await new Promise(resolve=>releaseAck=resolve);queue.shift();}
      return {commands:queue.slice(0,1),counts:{pending:queue.length,conflicts:0}};},
    remote:async command=>{calls.push('send:'+command.command_id);await new Promise(resolve=>releaseRemote=resolve);return {committed:true};}});
  const upload=sync.run();await settle();assert.equal(typeof releaseRemote,'function');
  assert.deepEqual(await sync.withRefreshHandoff(()=>{throw Error('must not fence an unknown send');}),{deferred:true});
  releaseRemote();await settle();assert.equal(typeof releaseAck,'function');
  assert.deepEqual(await sync.withRefreshHandoff(()=>{throw Error('must not fence an unpersisted acknowledgement');}),{deferred:true});
  releaseAck();await upload;queue.push({command_id:'next-uuid'});const before=calls.length;
  const handoff=sync.withRefreshHandoff(async()=>{calls.push('cutover');await new Promise(resolve=>releaseHandoff=resolve);throw Error('view refused');});
  assert.equal(sync.handoff,true);await sync.run();assert.equal(calls.length,before+1);assert.equal(queue.length,1);
  await settle();releaseHandoff();await assert.rejects(handoff,/view refused/);assert.equal(sync.handoff,false);
  const next=sync.run();await settle();assert.equal(calls.at(-1),'send:next-uuid');releaseRemote();await settle();releaseAck();await next;
});

test('a stopped background coordinator drains its accepted task and never admits a new refresh or final cutover',async()=>{
  let release,starts=0;const sync=new DashboardSync({backgroundRefresh:true,
    local:async()=>({commands:[],counts:{pending:0,conflicts:0}}),remote:async()=>{},
    refresh:async()=>{starts++;await new Promise(resolve=>release=resolve);}});
  await sync.run();await settle();const accepted=sync.refreshing;assert.ok(accepted);sync.stop();release();await accepted;
  await sync.run();assert.equal(starts,1);assert.equal(sync.refreshing,null);
  assert.deepEqual(await sync.withRefreshHandoff(()=>{throw Error('closed');}),{deferred:true});
});

test('a deferred or failed detached refresh keeps existing connectivity and retries only on a later due slot',async()=>{
  let now=0,count=0;const fixed='تعذر تحديث بيانات السيرفر في الخلفية.';
  const sync=new DashboardSync({backgroundRefresh:true,now:()=>now,local:async()=>({commands:[],counts:{pending:0,conflicts:0}}),remote:async()=>{},
    refresh:async()=>{if(++count===1)return {deferred:true};throw Error(fixed);}});
  await sync.run();await sync.refreshing;assert.equal(sync.state.online,false);assert.equal(sync.state.refresh_deferred,true);
  assert.equal(sync.state.refreshedAt,undefined);assert.equal(sync.nextRefresh,30000);
  now=29999;await sync.run();assert.equal(count,1);now=30000;await sync.run();await sync.refreshing;
  assert.equal(count,2);assert.equal(sync.state.error,fixed);assert.equal(sync.state.refreshedAt,undefined);assert.equal(sync.nextRefresh,60000);
});
