'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict');
const AccountSwitch=require('../src/account-switch.cjs');
function fixture(overrides={}){
  const events=[];let selected='default',available=true,choice={action:'select',id:'other'};
  const options={profiles:{list:async()=>[{id:'default'}],create:async()=>{events.push('create');return{id:'new'};},
    select:async id=>{events.push('select:'+id);selected=id;}},currentId:()=> 'default',available:async()=>available,
    hold:async work=>{events.push('hold');try{return await work();}finally{events.push('release');}},
    choose:async()=>{events.push('choose');return choice;},pause:()=>events.push('pause'),resume:()=>events.push('resume'),
    flush:async()=>events.push('flush'),restart:async()=>events.push('restart'),...overrides};
  return {subject:new AccountSwitch(options),events,selected:()=>selected,ready:value=>available=value,choose:value=>choice=value,options};
}
test('switch persists the selected profile only inside the held idle page, then restarts',async()=>{
  const f=fixture();assert.deepEqual(await f.subject.request(),{restarting:true});
  assert.deepEqual(f.events,['hold','pause','choose','flush','select:other','restart','release']);assert.equal(f.selected(),'other');
});
test('unsettled work refuses account selection without opening the picker or changing profiles',async()=>{
  const f=fixture();f.ready(false);await assert.rejects(f.subject.request(),/مزامنة/);
  assert.deepEqual(f.events,['hold','release']);assert.equal(f.selected(),'default');
});
test('a new pending operation during selection blocks the final profile write',async()=>{
  const f=fixture();f.options.choose=async()=>{f.ready(false);return{action:'select',id:'other'};};
  f.subject.choose=f.options.choose;await assert.rejects(f.subject.request(),/مزامنة/);
  assert.equal(f.selected(),'default');assert.deepEqual(f.events,['hold','pause','resume','release']);
});
test('cancel and selecting the current profile preserve identity and resume synchronization',async()=>{
  for(const choice of [null,{action:'select',id:'default'}]){
    const f=fixture();f.choose(choice);await f.subject.request();
    assert.equal(f.selected(),'default');assert.ok(f.events.includes('resume'));assert.ok(!f.events.includes('flush'));
  }
});
test('cookies finish flushing before a new independent profile is created and selected',async()=>{
  const f=fixture();f.choose({action:'add'});await f.subject.request();
  assert.deepEqual(f.events,['hold','pause','choose','flush','create','select:new','restart','release']);
});
test('a failed cookie flush cannot change the selected profile or restart',async()=>{
  const f=fixture({flush:async()=>{throw Error('flush failed');}});await assert.rejects(f.subject.request(),/flush failed/);
  assert.equal(f.selected(),'default');assert.ok(f.events.includes('resume'));assert.ok(!f.events.includes('restart'));
});
test('a failed restart restores the previous durable selection and resumes the current process',async()=>{
  const f=fixture({restart:async()=>{throw Error('restart failed');}});await assert.rejects(f.subject.request(),/restart failed/);
  assert.equal(f.selected(),'default');assert.deepEqual(f.events.slice(-4),['select:other','select:default','resume','release']);
});
test('shutdown cancels an open picker and settles the accepted switch without changing identity',async()=>{
  let opened;const ready=new Promise(resolve=>opened=resolve);
  const f=fixture({choose:async(_profiles,signal)=>{opened();return new Promise(resolve=>signal.addEventListener('abort',()=>resolve(null),{once:true}));}});
  const task=f.subject.request();await ready;f.subject.cancel();assert.deepEqual(await task,{cancelled:true});
  assert.equal(f.selected(),'default');assert.ok(f.events.includes('resume'));assert.equal(f.subject.running,null);
});
test('shutdown arriving during a durable profile selection restores the previous selection',async()=>{
  const f=fixture();const select=f.subject.profiles.select;
  f.subject.profiles.select=async id=>{await select(id);if(id==='other')f.subject.cancel();};
  assert.deepEqual(await f.subject.request(),{cancelled:true});assert.equal(f.selected(),'default');assert.ok(!f.events.includes('restart'));
});
test('a deferred page hold cannot silently claim an account switch',async()=>{
  const f=fixture({hold:async()=>({deferred:true})});await assert.rejects(f.subject.request(),/مزامنة/);assert.equal(f.selected(),'default');
});
