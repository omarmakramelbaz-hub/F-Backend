'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const synchronize=require('../src/dashboard-print-sync.cjs'),{receiptHash}=require('../src/dashboard-print-effects.cjs');
const DashboardSync=require('../src/dashboard-sync.cjs');
const uuid='00000000-0000-4000-8000-000000000001';
const saved={effect_id:'a'.repeat(64),enrollment_id:'b'.repeat(64),origin_command_id:uuid,actor_id:10,branch:'f:100',revision:1,
  claim_token:uuid,native_session_id:uuid,invocation_id:uuid,permit_hash:'c'.repeat(64),state:'invoked',reason:'spooler_accepted'};
const ack=value=>({effect_id:value.effect_id,invocation_id:value.invocation_id,state:value.state,receipt_sha256:receiptHash(value)});

test('immutable print outcomes are acknowledged only after matching remote and durable local confirmations',async()=>{
  const actions=[],remote=[];
  const local=async value=>{actions.push(value);if(value.action==='print-outcomes')return {outcomes:[saved]};return value.receipt;};
  assert.deepEqual(await synchronize({local,remote:async value=>{remote.push(value);return ack(value);}}),{pending:0,deferred:false});
  assert.deepEqual(remote,[saved]);assert.deepEqual(actions.map(value=>value.action),['print-outcomes','print-acknowledge']);
  assert.deepEqual(actions[1].receipt,ack(saved));
});
test('business dependency409 and transport uncertainty retain the receipt without manufacturing acknowledgement',async()=>{
  const actions=[],local=async value=>{actions.push(value);return {outcomes:[saved]};};
  assert.deepEqual(await synchronize({local,remote:async()=>{throw Object.assign(Error('Business not yet present'),{status:409});}}),{pending:1,deferred:true});
  assert.equal(actions.some(value=>value.action==='print-acknowledge'),false);
  await assert.rejects(synchronize({local,remote:async()=>{throw Error('Lost reply');}}),/Lost reply/);
  assert.equal(actions.some(value=>value.action==='print-acknowledge'),false);
});
test('wrong effect, invocation, terminalstate or canonicalhash cannot clear a pending outcome',async()=>{
  for(const patch of [{effect_id:'d'.repeat(64)},{invocation_id:'00000000-0000-4000-8000-000000000002'},{state:'review'},{receipt_sha256:'d'.repeat(64)}]){
    const actions=[];await assert.rejects(synchronize({local:async value=>{actions.push(value);return {outcomes:[saved]};},remote:async value=>({...ack(value),...patch})}),/المطابقة/);
    assert.equal(actions.some(value=>value.action==='print-acknowledge'),false);
  }
});
test('lost local acknowledgement safely redelivers the exact same immutable receipt',async()=>{
  const remote=[];let lost=true;
  const local=async value=>{if(value.action==='print-outcomes')return {outcomes:[saved]};if(lost){lost=false;throw Error('Local reply lost');}return value.receipt;};
  const send=async value=>{remote.push(value);return ack(value);};
  await assert.rejects(synchronize({local,remote:send}),/Local reply lost/);
  await synchronize({local,remote:send});assert.deepEqual(remote,[saved,saved]);
});
test('shutdown after remote reply leaves the local outcome pending for a later exact delivery',async()=>{
  let stopped=false;const actions=[];
  const result=await synchronize({local:async value=>{actions.push(value);return {outcomes:[saved]};},
    remote:async value=>{stopped=true;return ack(value);},stopped:()=>stopped});
  assert.deepEqual(result,{pending:1,deferred:true});assert.equal(actions.some(value=>value.action==='print-acknowledge'),false);
});
test('print receipt delivery follows original business acknowledgement and precedes any dataset replacement',async()=>{
  const order=[];let pending=true;
  const sync=new DashboardSync({local:async value=>{
    order.push(value.action);if(value.action==='pending')return {commands:pending?[{command_id:uuid}]:[],counts:{pending:pending?1:0,conflicts:0}};
    if(value.action==='acknowledge'){pending=false;return {counts:{pending:0,conflicts:0}};}
  },remote:async()=>{order.push('business-ingest');return {};},effects:async()=>{order.push('print-outcome-delivery');return {pending:0,deferred:false};},
    refresh:async()=>{order.push('refresh');return {};}});
  await sync.run();assert.ok(order.indexOf('acknowledge')<order.indexOf('print-outcome-delivery'));
  assert.ok(order.indexOf('print-outcome-delivery')<order.indexOf('refresh'));
});
test('an unresolved print dependency defers replacement while preserving the business sync state',async()=>{
  let refreshed=false;const sync=new DashboardSync({local:async()=>({commands:[],counts:{pending:0,conflicts:0}}),remote:async()=>{},
    effects:async()=>({pending:1,deferred:true}),refresh:async()=>{refreshed=true;}});
  await sync.run();assert.equal(refreshed,false);assert.equal(sync.state.print_pending,1);assert.equal(sync.state.refresh_deferred,true);
});
test('previous-session review receipts deliver without claiming physical success',async()=>{
  const review={...saved,state:'review',reason:'previous-native-session'},sent=[];
  await synchronize({local:async value=>value.action==='print-outcomes'?{outcomes:[review]}:value.receipt,remote:async value=>{sent.push(value);return ack(value);}});
  assert.equal(sent[0].state,'review');assert.equal(sent[0].reason,'previous-native-session');
});

test('a bounded outcome batch retains its total and defers replacement until later receipts are delivered',async()=>{
  const result=await synchronize({local:async value=>value.action==='print-outcomes'?{outcomes:[saved],pending:2}:value.receipt,remote:async value=>ack(value)});
  assert.deepEqual(result,{pending:1,deferred:true});
});

test('retained pre-upgrade runtimes cannot advertise the new print control merely because the executable changed',()=>{
  const Runtime=require('../src/local-runtime.cjs');
  const supports=manifest=>Runtime.prototype.supportsPrintEffects.call({manifest});
  assert.equal(supports({sourceHashes:{}}),false);
  assert.equal(supports({sourceHashes:{'app/Services/Dashboard/DesktopDashboardPrintEffects.php':'bad'}}),false);
  assert.equal(supports({sourceHashes:{'app/Services/Dashboard/DesktopDashboardPrintEffects.php':'a'.repeat(64)}}),true);
});

test('legacy snapshots can refresh into the print protocol while active and unknown protocols retain their failures',async()=>{
  for(const protocol of ['absent',0,1,2,null,'1']){
    let inspected=0,refreshed=0,reported;
    const outbox={commands:[],counts:{pending:0,conflicts:0}};
    if(protocol!=='absent')outbox.print_protocol=protocol;
    const sync=new DashboardSync({local:async()=>outbox,remote:async()=>{},effects:async value=>{
      reported=value.printProtocol;
      if(!synchronize.activeProtocol(value.printProtocol))return {pending:0,deferred:false};
      inspected++;throw Error('Active ledger is unavailable');
    },refresh:async()=>{refreshed++;return {};}});
    await sync.run();
    assert.equal(reported,protocol==='absent'?0:protocol);
    if(protocol==='absent'||protocol===0){assert.equal(refreshed,1);assert.equal(inspected,0);assert.equal(sync.state.error,'');}
    else {assert.equal(refreshed,0);assert.equal(inspected,protocol===1?1:0);assert.ok(sync.state.error);}
  }
});
