'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {DashboardConnected,binding}=require('../src/dashboard-connected.cjs');
const DashboardMode=require('../src/dashboard-mode.cjs'),RemoteState=require('../src/dashboard-remote-state.cjs'),RemoteAttempts=require('../src/dashboard-remote-attempts.cjs');
function fixture(){
  const records=new Map(),calls=[];let current={format:2,deviceId:crypto.randomUUID(),actorId:1,token:'a'.repeat(64),serverOrigin:'https://central.test',
    sourceRevision:'b'.repeat(40),sourceFingerprint:{format:1,sha256:'c'.repeat(64),framework:'8.83.29',files:100},snapshotId:crypto.randomUUID(),refreshId:crypto.randomUUID(),schemaHash:'d'.repeat(64),database:'fasakhansta_dashboard_stage_'+'e'.repeat(16)};
  let local=true,held=null,idle=true,gate=false,actor=1,protocol=1,prepared=true,generationFailure=false;
  const metadata={read:async key=>structuredClone(records.get(key)||null),write:async(key,value)=>{calls.push(['write',key,gate]);records.set(key,structuredClone(value));}};
  const runtime={metadata,origin:'http://127.0.0.1:43000',token:'browser-native',isPrepared:async()=>prepared,connection:async()=>structuredClone(current),control:async value=>{
    calls.push([value.action,gate]);if(value.action==='refresh-begin'){held=value.refresh_id;return {held:true,device_id:current.deviceId,actor_id:current.actorId,schema_hash:current.schemaHash};}
    if(value.action==='refresh-status')return {exists:held===value.refresh_id,held:held===value.refresh_id};
    if(value.action==='refresh-cancel'){held=null;return {held:false};}
  }};
  const view={current:()=>({local,origin:local?runtime.origin:current.serverOrigin}),canRefresh:async()=>idle&&!gate,reveal:()=>calls.push(['reveal']),open:async()=>calls.push(['open']),
    switchTo:async(target,work)=>{assert.equal(gate,false);calls.push(['gate+']);gate=true;try{await work();local=Boolean(target.localToken);}finally{gate=false;calls.push(['gate-']);}}};
  const generations={run:async()=>{calls.push(['generation',gate]);if(generationFailure)throw Error('snapshot unavailable');const previous=structuredClone(current);current={...current,snapshotId:crypto.randomUUID(),refreshId:crypto.randomUUID(),sourceRevision:crypto.randomBytes(20).toString('hex'),sourceFingerprint:{...current.sourceFingerprint,sha256:crypto.randomBytes(32).toString('hex')}};await metadata.write('generations/'+current.refreshId,{previous,next:current});held=null;}};
  const probe=async()=>{calls.push(['probe',gate]);return {actor_id:actor,device_id:current.deviceId,...(protocol===undefined?{}:{connected_request_protocol:protocol})};};
  const state=new RemoteState(metadata);let access;
  const options={metadata,state,credential:()=>runtime.connection(),direct:details=>access.authorizeDirect(details),request:async()=>{throw Error('Full central access must not use a bounded server capability.');}};
  const attempts=new RemoteAttempts(options),mode=new DashboardMode({runtime,view,generations,probe,remoteState:state,remoteAttempts:attempts});
  access=new DashboardConnected({runtime,view:()=>view,mode:()=>mode,generations:()=>generations,remoteState:state,remoteAttempts:attempts,probe,
    synchronize:async()=>{calls.push(['synchronize',gate]);assert.equal(await access.deferRefresh(),true,'Scheduled refresh cannot deadlock or change a selected mode during transition.');}});
  return {access,attempts,state,mode,runtime,view,generations,options,records,calls,current:()=>current,replace:value=>{current={...current,...value};},held:()=>held,setIdle:value=>idle=value,setActor:value=>actor=value,setProtocol:value=>protocol=value,setPrepared:value=>prepared=value,failGeneration:()=>generationFailure=true};
}
const details=(f,method='POST',url='/admin/users')=>({method,url:f.current().serverOrigin+url});
const actorProof=f=>({'X-Fasakhansta-Connected-Device':f.current().deviceId,'X-Fasakhansta-Connected-Token':f.current().token});

test('explicit central entry gates the window before synchronization, refreshes and holds its exact source-bound enrollment',async()=>{
  const f=fixture();await f.access.open();assert.equal(f.view.current().local,false);assert.ok(f.held());assert.equal(await f.access.selected(),true);
  assert.ok(f.calls.filter(row=>['synchronize','generation','refresh-begin','probe'].includes(row[0])).every(row=>row[1]===true));
  assert.deepEqual(f.records.get('central-mode').binding,binding(f.current()));
  const before=f.calls.filter(row=>row[0]==='generation').length;assert.equal(await f.access.deferRefresh(),true);await f.access.open();assert.equal(f.calls.filter(row=>row[0]==='generation').length,before);assert.equal(f.calls.at(-1)[0],'reveal');
});
test('editing, printing or unsettled pages refuse mode changes without destroying their original form',async()=>{
  const f=fixture();f.setIdle(false);await assert.rejects(f.access.open(),/للصفحة الرئيسية/);assert.equal(f.calls.length,0);assert.equal(f.view.current().local,true);assert.equal(f.held(),null);
});
test('server actor drift cannot select central mode or leave an inactive local write fence',async()=>{
  const f=fixture();f.setActor(2);await assert.rejects(f.access.open(),/لا يطابق/);assert.equal(f.view.current().local,true);assert.equal(f.held(),null);assert.equal(f.records.get('central-mode'),null);
});
test('a matching actor on an old or unknown server protocol cannot enter central mode or send work through a persisted central latch',async()=>{
  for(const protocol of [undefined,0,2,'1',null,true]){
    const fresh=fixture();fresh.setProtocol(protocol);await assert.rejects(fresh.access.open(),/لم يؤكد حماية/);
    assert.equal(fresh.view.current().local,true);assert.equal(fresh.held(),null);assert.equal(fresh.records.get('central-mode'),null);
    const selected=fixture();await selected.access.open();selected.setProtocol(protocol);
    await assert.rejects(selected.access.open(),/لم يؤكد حماية/);
    for(const [method,url] of [['POST','/admin/users/20'],['POST','/admin/updatePaymentActivation'],['GET','/admin/resturantControl']]){
      const id=crypto.randomUUID();await assert.rejects(selected.attempts.begin(id,details(selected,method,url)),/لم يؤكد حماية/);
      assert.equal(selected.records.has('remote-central/'+id),false);
    }
    await assert.rejects(selected.access.local(),/لم يؤكد حماية/);assert.equal(selected.view.current().local,false);assert.ok(selected.held());assert.deepEqual((await selected.state.read()).pending,[]);
  }
});
test('full central credential, provider and payment routes send no bounded headers and persist identity before transmission',async()=>{
  for(const endpoint of ['/admin/users','/admin/users/20','/admin/updatePaymentActivation','/admin/charging/wallet','/admin/send_chat_notification']){
    const f=fixture();await f.access.open();const id=crypto.randomUUID();assert.deepEqual(await f.attempts.begin(id,details(f,'POST',endpoint)),actorProof(f));
    const record=f.records.get('remote-central/'+id);assert.equal(record.path,endpoint);assert.deepEqual(record.binding,binding(f.current()));assert.deepEqual((await f.state.read()).pending,[id]);
    assert.equal(f.records.has('remote-attempts/'+id),false);assert.deepEqual(Object.keys(record).sort(),['binding','created_at','format','id','method','path']);
  }
});
test('an unknown central reply survives restart and blocks both local activation and snapshot replacement',async()=>{
  const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));f.attempts.failed(id);
  const restarted=new RemoteAttempts(f.options);await assert.rejects(restarted.recover(),/لم يتأكد رد/);assert.equal(await f.access.selected({recover:true}),true);
  const generations=f.calls.filter(row=>row[0]==='generation').length;await assert.rejects(f.access.local(),/لم يتأكد رد/);
  assert.equal(f.view.current().local,false);assert.ok(f.held());assert.deepEqual((await f.state.read()).pending,[id]);assert.equal(f.calls.filter(row=>row[0]==='generation').length,generations);
});
test('a received central response remains dirty until an explicit complete snapshot and same-actor return',async()=>{
  const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));await f.attempts.complete(id);
  assert.equal(f.records.get('remote-central-responses/'+id).observation,'response-received');assert.equal((await f.state.read()).dirty,true);assert.deepEqual((await f.state.read()).pending,[]);
  await f.access.local();assert.equal(f.view.current().local,true);assert.equal(f.held(),null);assert.equal(f.records.get('central-mode'),null);await f.state.assertClean();assert.equal(await f.access.deferRefresh(),false);
});
test('a crash after persisting an observed response recovers only that native observation and retains dirty data',async()=>{
  const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));
  const complete=f.state.complete.bind(f.state);let fail=true;f.state.complete=async value=>{if(fail){fail=false;throw Error('lost native completion');}return complete(value);};
  await assert.rejects(f.attempts.complete(id),/lost native completion/);assert.equal(f.records.get('remote-central-responses/'+id).observation,'response-received');assert.deepEqual((await f.state.read()).pending,[id]);
  await new RemoteAttempts(f.options).recover();assert.deepEqual((await f.state.read()).pending,[]);assert.equal((await f.state.read()).dirty,true);
});
test('expired login can restore the enrolled server session while another actor cannot submit business work or return local',async()=>{
  const f=fixture();await f.access.open();f.setActor(2);const login=crypto.randomUUID();assert.deepEqual(await f.attempts.begin(login,details(f,'POST','/admin/signin')),actorProof(f));await f.attempts.complete(login);
  await assert.rejects(f.attempts.begin(crypto.randomUUID(),details(f)),/لا يطابق/);await assert.rejects(f.access.local(),/لا يطابق/);assert.equal(f.view.current().local,false);assert.ok(f.held());
});
test('changed enrollment, actor or source cannot reuse a chosen central latch or an observed-response receipt',async()=>{
  for(const change of [{token:'f'.repeat(64)},{actorId:2},{sourceRevision:'f'.repeat(40)}]){
    const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));f.attempts.failed(id);f.replace(change);
    await assert.rejects(f.access.selected(),/مصدرًا أو ربطًا آخر/);await assert.rejects(new RemoteAttempts(f.options).recover(),/لا يطابق/);assert.deepEqual((await f.state.read()).pending,[id]);
  }
});
test('restart after an interrupted return accepts only the exact durable generation predecessor and next-source receipt',async()=>{
  const f=fixture();await f.access.open();const prior=f.records.get('central-mode');await f.runtime.metadata.write('central-mode',{...prior,phase:'returning'});await f.mode.cancelHold();await f.generations.run();
  assert.equal(await f.access.selected({recover:true}),true);assert.equal(f.records.get('central-mode').phase,'central');assert.deepEqual(f.records.get('central-mode').binding,binding(f.current()));await f.mode.hold();assert.ok(f.held());
  const bad=fixture();await bad.access.open();const old=bad.records.get('central-mode');await bad.runtime.metadata.write('central-mode',{...old,phase:'returning'});await bad.generations.run();bad.records.get('generations/'+bad.current().refreshId).previous.token='f'.repeat(64);
  await assert.rejects(bad.access.selected({recover:true}),/مصدرًا أو ربطًا آخر/);
});
test('a failed explicit return preserves central mode, dirty state and the local fence',async()=>{
  const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));await f.attempts.complete(id);f.failGeneration();
  await assert.rejects(f.access.local(),/snapshot unavailable/);assert.equal(f.view.current().local,false);assert.ok(f.held());assert.equal(await f.access.selected(),true);assert.equal((await f.state.read()).dirty,true);
});
test('known original GET status, driving activation and financial cancellation retain durable direct uncertainty; HEAD cannot invoke them',async()=>{
  for(const endpoint of ['/admin/change-status/20','/admin/users/20/go_drive_activation','/admin/cancel/order/20/delegate','/test-test',
    '/admin/change-status/020','/admin/change-status/20/','/admin/change-status/%32%30','/%61dmin/users/020/go_drive_activation/',
    '/admin/resturant_products/020/update/status/','/admin//change-status/20','/%61dmin/change-status/20%','/%61dmin/change-status/20%FF']){
    const f=fixture();await f.access.open();const id=crypto.randomUUID();assert.deepEqual(await f.attempts.begin(id,details(f,'GET',endpoint)),actorProof(f));assert.deepEqual((await f.state.read()).pending,[id]);
    await assert.rejects(f.attempts.begin(crypto.randomUUID(),details(f,'HEAD',endpoint)),/فحص/);
  }
});
test('routing-equivalent admin paths retain exact transmitted provenance without bypassing full-central classification',async()=>{
  const policy=require('../src/dashboard-get-policy.cjs');
  for(const endpoint of ['/admin/users/20/','/%61dmin/users/20','/admin%2Fusers%2F20','/%61dmin/users/20%FF','/%61dmin/users/20%']){
    assert.equal(policy.admin(endpoint),true);const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f,'POST',endpoint));
    assert.equal(f.records.get('remote-central/'+id).path,endpoint);assert.deepEqual((await f.state.read()).pending,[id]);
  }
  for(const path of ['/admin/change-status/020','/admin/change-status/20/','/admin/change-status/%32%30','/test-test/'])assert.equal(policy.mutation(path),true);
});
test('all original order-board invoice issuance sources preserve uncertain GET outcomes and reject HEAD route aliases',async()=>{
  const policy=require('../src/dashboard-get-policy.cjs');
  for(const source of ['legacy','store','service','partner_service']){
    for(const endpoint of ['/admin/order-board/'+source+'/20/print','/admin/order-board/'+source+'/020/print/',
      '/%61dmin/order-board/'+source+'/%32%30/print','/admin/order-board/'+source.replace('_','%5f')+'/20/print']){
      const f=fixture();await f.access.open();const id=crypto.randomUUID();assert.equal(policy.mutation(endpoint),true);
      assert.deepEqual(await f.attempts.begin(id,details(f,'GET',endpoint)),actorProof(f));assert.equal(f.records.get('remote-central/'+id).path,endpoint);
      f.attempts.failed(id);await assert.rejects(new RemoteAttempts(f.options).recover(),/لم يتأكد رد/);assert.deepEqual((await f.state.read()).pending,[id]);
      await assert.rejects(f.attempts.begin(crypto.randomUUID(),details(f,'HEAD',endpoint)),/فحص/);
      await assert.rejects(f.access.local(),/لم يتأكد رد/);assert.equal(f.view.current().local,false);assert.ok(f.held());
    }
  }
});
test('paired connected actor proof is transport-only, explicit-mode-only and refuses a foreign origin',async()=>{
  const f=fixture(),unselected=crypto.randomUUID();assert.deepEqual(await f.attempts.begin(unselected,details(f)),{});assert.equal(f.records.has('remote-central/'+unselected),false);
  await f.attempts.complete(unselected);await f.access.open();
  await assert.rejects(f.attempts.begin(crypto.randomUUID(),{method:'POST',url:'https://foreign.test/admin/users'}),/سيرفر الجهاز/);
  const id=crypto.randomUUID(),proof=await f.attempts.begin(id,details(f));assert.deepEqual(proof,actorProof(f));
  assert.equal(Object.keys(proof).some(name=>/Remote-(?:Attempt|Capability)/i.test(name)),false);
  f.attempts.failed(id);await f.attempts.humanReview(id);await f.attempts.complete(id);
  const audit=[...f.records].filter(([key])=>key.startsWith('remote-central'));
  assert.equal(JSON.stringify(audit).includes(f.current().token),false);assert.equal(JSON.stringify(audit).includes('X-Fasakhansta-Connected-Token'),false);
  assert.ok(audit.every(([,record])=>record.binding.tokenHash===crypto.createHash('sha256').update(f.current().token).digest('hex')));
});
test('full-central original forms receive no bounded UUID or profile-only frontend mutation',async()=>{
  let scanned=false;const form={action:'https://central.test/admin/users/20',elements:[{name:'password',value:'OriginalSecret123'},{name:'roles_name[0]',value:'admin'}]};
  await vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8'),{
    document:{body:{dataset:{dashboardLocal:'0',dashboardRemoteAttempts:'1'}},querySelectorAll:()=>{scanned=true;return [form];}},window:{FasakhanstaDesktop:{status:async()=>({prepared:true,full_central:true})}}});
  assert.equal(scanned,false);assert.equal(form.elements.length,2);assert.equal(form.elements[0].value,'OriginalSecret123');
});
test('human review retains immutable original request and a distinct durable decision without claiming the server outcome',async()=>{
  const f=fixture();await f.access.open();const id=crypto.randomUUID();
  await f.attempts.begin(id,{...details(f,'POST','/admin/users/20?password=NeverStoreThis'),uploadData:[{bytes:Buffer.from('password=NeverStoreThis')}]});
  const original=structuredClone(f.records.get('remote-central/'+id));
  await assert.rejects(f.attempts.humanReview(id),/قيد التنفيذ/);f.attempts.failed(id);
  assert.deepEqual(await f.attempts.reviewable(),[original]);
  const decision=await f.attempts.humanReview(id);
  assert.equal(decision.decision,'human-reviewed');assert.equal(decision.request_id,id);assert.equal(decision.actor_id,1);
  assert.equal(decision.request_sha256,crypto.createHash('sha256').update(JSON.stringify(original)).digest('hex'));
  assert.equal('status' in decision,false);assert.equal('receipt' in decision,false);
  assert.deepEqual(f.records.get('remote-central/'+id),original);assert.deepEqual((await f.state.read()).pending,[]);assert.equal((await f.state.read()).dirty,true);
  assert.equal(JSON.stringify([...f.records]).includes('NeverStoreThis'),false);
  await f.attempts.complete(id);assert.deepEqual(f.records.get('remote-central/'+id),original);
  assert.deepEqual(await f.attempts.humanReview(id),decision);assert.deepEqual(f.records.get('remote-central-reviews/'+id),decision);
  await f.access.local();await f.state.assertClean();assert.equal(f.view.current().local,true);
});
test('human review crash after decision flush recovers idempotently without fabricating or retransmitting a request',async()=>{
  const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));f.attempts.failed(id);
  const original=structuredClone(f.records.get('remote-central/'+id)),complete=f.state.complete.bind(f.state);let crash=true;
  f.state.complete=async value=>{if(crash){crash=false;throw Error('crash after decision flush');}return complete(value);};
  await assert.rejects(f.attempts.humanReview(id),/crash after decision flush/);
  const decision=structuredClone(f.records.get('remote-central-reviews/'+id));assert.equal(decision.decision,'human-reviewed');assert.deepEqual((await f.state.read()).pending,[id]);
  const restarted=new RemoteAttempts(f.options);await restarted.recover();assert.deepEqual((await f.state.read()).pending,[]);assert.equal((await f.state.read()).dirty,true);
  assert.deepEqual(await restarted.humanReview(id),decision);assert.deepEqual(f.records.get('remote-central/'+id),original);
  assert.equal(f.records.has('remote-central-responses/'+id),false);assert.equal(f.records.has('remote-attempts/'+id),false);
  await f.access.local();await f.state.assertClean();
});
test('failed review persistence retains pending uncertainty and a valid later review never overwrites the request',async()=>{
  const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));f.attempts.failed(id);
  const original=structuredClone(f.records.get('remote-central/'+id)),write=f.runtime.metadata.write;let fail=true;
  f.runtime.metadata.write=async(key,value)=>{if(key==='remote-central-reviews/'+id&&fail){fail=false;throw Error('disk unavailable');}return write(key,value);};
  await assert.rejects(f.attempts.humanReview(id),/disk unavailable/);assert.deepEqual((await f.state.read()).pending,[id]);assert.equal(f.records.has('remote-central-reviews/'+id),false);
  await assert.rejects(new RemoteAttempts(f.options).recover(),/لم يتأكد رد/);await f.attempts.humanReview(id);assert.deepEqual(f.records.get('remote-central/'+id),original);
});
test('reused direct request IDs and changed source, enrollment, generation or forged human decision cannot clear uncertainty',async()=>{
  const changes=[{token:'f'.repeat(64)},{actorId:2},{sourceRevision:'f'.repeat(40)},{sourceFingerprint:{format:1,sha256:'f'.repeat(64),framework:'8.83.29',files:100}},
    {snapshotId:crypto.randomUUID()},{refreshId:crypto.randomUUID()},{schemaHash:'f'.repeat(64)},{database:'fasakhansta_dashboard_stage_'+'f'.repeat(16)},
    {deviceId:crypto.randomUUID()},{serverOrigin:'https://another.test'}];
  for(const change of changes){
    const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));const original=structuredClone(f.records.get('remote-central/'+id));
    await assert.rejects(f.attempts.begin(id,details(f,'DELETE','/admin/users/21')),/قيد التنفيذ/);assert.ok(f.attempts.active.has(id));
    f.attempts.failed(id);await assert.rejects(f.attempts.begin(id,details(f,'DELETE','/admin/users/21')),/مسجلة/);assert.deepEqual(f.records.get('remote-central/'+id),original);
    f.replace(change);await assert.rejects(f.attempts.humanReview(id),/لا يطابق/);assert.deepEqual((await f.state.read()).pending,[id]);
  }
  const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));f.attempts.failed(id);const decision=await f.attempts.humanReview(id);await f.state.begin(id);
  f.records.set('remote-central-reviews/'+id,{...decision,decision:'committed'});await assert.rejects(new RemoteAttempts(f.options).recover(),/المراجعة البشرية/);assert.deepEqual((await f.state.read()).pending,[id]);
});
test('ordinary central browsing flushes dirty state before transmission and cannot bypass the snapshot gate',async()=>{
  const f=fixture();await f.access.open();await f.state.assertClean();await f.access.browse();assert.equal((await f.state.read()).dirty,true);await assert.rejects(f.state.assertClean(),/تُحدّث/);
  let release;const snapshot=f.state.snapshot(()=>new Promise(resolve=>release=resolve));await new Promise(resolve=>setImmediate(resolve));
  await assert.rejects(f.access.browse(),/تحديث/);release();await snapshot;await f.state.assertClean();
  await f.access.local();const before=f.calls.length;await f.access.browse();assert.equal(f.calls.length,before);
});
test('human review is a native-menu gesture with current actor checks and no renderer IPC exposure',async()=>{
  const DashboardConnectedReview=require('../src/dashboard-connected-review.cjs');
  const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));f.attempts.failed(id);let replies=[0,0],notices=[];
  const review=new DashboardConnectedReview({attempts:f.attempts,connected:f.access,runtime:f.runtime,dialog:{showMessageBox:async(_window,options)=>{notices.push(options);return {response:replies.shift()};}}});
  await review.open({});assert.equal(f.records.get('remote-central-reviews/'+id).decision,'human-reviewed');assert.equal(notices.length,3);assert.equal((await f.state.read()).dirty,true);
  const source=fs.readFileSync(path.join(__dirname,'../src/dashboard.cjs'),'utf8');assert.match(source,/مراجعة نتائج العمليات المتصلة/);assert.doesNotMatch(source,/['"]review-central['"]\s*:/);
  await f.state.begin(id);f.records.delete('remote-central-reviews/'+id);f.setActor(2);await assert.rejects(review.open({}),/لا يطابق/);assert.deepEqual((await f.state.read()).pending,[id]);
});
test('native review cancellation preserves uncertainty and actor change during confirmation cannot record a decision',async()=>{
  const Review=require('../src/dashboard-connected-review.cjs');
  for(const cancelAt of [0,1]){
    const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));f.attempts.failed(id);let step=0;
    const review=new Review({attempts:f.attempts,connected:f.access,runtime:f.runtime,dialog:{showMessageBox:async()=>({response:step++===cancelAt?1:0})}});
    await review.open({});assert.deepEqual((await f.state.read()).pending,[id]);assert.equal(f.records.has('remote-central-reviews/'+id),false);
  }
  const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));f.attempts.failed(id);let step=0;
  const review=new Review({attempts:f.attempts,connected:f.access,runtime:f.runtime,dialog:{showMessageBox:async()=>{if(step++===1)f.setActor(2);return {response:0};}}});
  await assert.rejects(review.open({}),/لا يطابق/);assert.deepEqual((await f.state.read()).pending,[id]);assert.equal(f.records.has('remote-central-reviews/'+id),false);
});

test('native renewal restores an expired local server session without selecting central or modifying its generation',async()=>{
  const f=fixture(),probe=f.access.probe;f.access.probe=async()=>{throw Error('expired cookie401');};
  await assert.rejects(f.access.open(),/expired cookie401/);assert.equal(f.view.current().local,true);assert.equal(f.held(),null);
  const before=structuredClone([...f.records]),generation=f.calls.filter(row=>row[0]==='generation').length;
  f.view.reauthenticate=async(origin,verify)=>{assert.equal(origin,f.current().serverOrigin);assert.equal(await f.access.deferRefresh(),true);f.access.probe=probe;await verify();};
  assert.deepEqual(await f.access.reauthenticate(),{authenticated:true});assert.equal(f.view.current().local,true);assert.deepEqual([...f.records],before);
  assert.equal(f.calls.filter(row=>row[0]==='generation').length,generation);assert.equal(f.held(),null);assert.equal(await f.access.selected(),false);
  await f.access.open();assert.equal(await f.access.selected(),true);assert.equal(f.view.current().local,false);
});
test('wrong actor, unknown protocol, cancellation and binding drift during native renewal preserve an existing central fence and uncertain request',async()=>{
  for(const scenario of ['actor','protocol','cancel','binding','probe-drift']){
    const f=fixture();await f.access.open();const id=crypto.randomUUID();await f.attempts.begin(id,details(f));f.attempts.failed(id);
    const before=structuredClone([...f.records]),held=f.held();
    f.view.reauthenticate=async(_origin,verify)=>{
      if(scenario==='actor')f.setActor(2);
      if(scenario==='protocol')f.setProtocol(2);
      if(scenario==='cancel')throw Error('cancel renewal');
      if(scenario==='binding')f.replace({token:'f'.repeat(64)});
      if(scenario==='probe-drift')f.access.probe=async()=>{f.replace({sourceRevision:'f'.repeat(40)});return {actor_id:1,device_id:f.current().deviceId,connected_request_protocol:1};};
      await verify();
    };
    await assert.rejects(f.access.reauthenticate(),/لا يطابق|لم يؤكد حماية|cancel renewal|تغير ربط/);
    assert.deepEqual([...f.records],before);assert.equal(f.held(),held);assert.equal(f.view.current().local,false);assert.deepEqual((await f.state.read()).pending,[id]);assert.equal(f.access.transitioning,false);
  }
});
test('native renewal refuses an unprepared or editing device before opening a cookie-changing window',async()=>{
  for(const scenario of ['unprepared','editing']){
    const f=fixture();let called=false;f.view.reauthenticate=async()=>{called=true;};
    if(scenario==='unprepared')f.setPrepared(false);else f.setIdle(false);
    await assert.rejects(f.access.reauthenticate(),/جهّز الجهاز|للصفحة الرئيسية/);assert.equal(called,false);assert.equal(f.records.size,0);
  }
});
