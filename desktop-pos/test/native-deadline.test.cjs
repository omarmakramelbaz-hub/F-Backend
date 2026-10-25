'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const {withDeadline,printOnce}=require('../src/native-deadline.cjs');

test('a missing native print callback expires once and cannot become success after the deadline',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});
  let calls=0,callback;
  const result=printOnce({print(_options,complete){calls++;callback=complete;}},{silent:true},{timeoutMs:30});
  const failure=assert.rejects(result,error=>error.code==='NATIVE_OPERATION_TIMEOUT'&&/لن تُعاد تلقائيًا/.test(error.message));
  await Promise.resolve();
  assert.equal(calls,1);
  t.mock.timers.tick(30);await failure;
  callback(true);callback(false,'late rejection');
  await Promise.resolve();assert.equal(calls,1);
});

test('native print success, rejection and synchronous exceptions settle before timeout',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});
  await printOnce({print(_options,complete){complete(true);}},{},{timeoutMs:30});
  await assert.rejects(printOnce({print(_options,complete){complete(false,'spooler unavailable');}},{},{timeoutMs:30}),/spooler unavailable/);
  await assert.rejects(printOnce({print(){throw Error('native exception');}},{},{timeoutMs:30}),/native exception/);
  t.mock.timers.tick(30);
});

test('a native read which never settles releases its deadline without swallowing later rejection',async t=>{
  t.mock.timers.enable({apis:['setTimeout']});
  let reject;
  const result=withDeadline(()=>new Promise((_resolve,fail)=>reject=fail),20,'native read stalled');
  const failure=assert.rejects(result,/native read stalled/);
  await Promise.resolve();t.mock.timers.tick(20);await failure;
  reject(Error('late native failure'));await Promise.resolve();
});
