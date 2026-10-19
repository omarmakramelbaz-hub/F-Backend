'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path');
const {DashboardPrintEffects,sha256,receiptHash}=require('../src/dashboard-print-effects.cjs');
const {GenerationStore}=require('../src/dashboard-generation.cjs');
const id='a'.repeat(64),command='00000000-0000-4000-8000-000000000001',device='00000000-0000-4000-8000-000000000002';
function fixture(options={}) {
  const records=new Map(),calls=[],invocations=[];
  const html='<html data-dashboard-receipt="phone"><head><style>body{direction:rtl}</style></head><body>فاتورة عربية</body></html>';
  const prepared={effect_id:id,device_id:device,enrollment_id:'b'.repeat(64),origin_command_id:command,kind:'phone_kitchen',
    actor_id:10,branch:'f:100',revision:1,snapshot_sha256:'c'.repeat(64),html,render_sha256:sha256(html),...options.prepared};
  const metadata=options.metadata||{read:async key=>structuredClone(records.get(key)||null),write:async(key,value)=>{calls.push('write:'+value.phase);records.set(key,structuredClone(value));}};
  const control=async value=>{
    calls.push(value);if(options.control){const override=await options.control(value);if(override!==undefined)return override;}
    if(value.action==='print-prepare')return structuredClone(prepared);
    if(value.action==='print-burn')return {effect_id:id,invocation_id:value.invocation_id,permit:'d'.repeat(64),render_sha256:prepared.render_sha256};
    if(value.action==='print-outcome'){const {action,...receipt}=value;return {effect_id:id,invocation_id:value.invocation_id,state:value.state,receipt_sha256:receiptHash(receipt)};}
    if(value.action==='print-review'){const{action,...receipt}=value;receipt.state='review';receipt.permit_hash=sha256('d'.repeat(64));return {effect_id:id,invocation_id:value.invocation_id,state:'review',receipt,receipt_sha256:receiptHash(receipt)};}
    if(value.action==='print-list')return {effects:[{effect_id:id,kind:prepared.kind}],attention:false};
    if(value.action==='print-resolve')return {effect_id:id};
    throw Error('Unexpected action '+value.action);
  };
  const prepareDocument=async document=>{
    calls.push('prepareDocument');assert.equal(document.html,prepared.html);
    if(options.prepareFailure)throw Error('No printer');
    return {invoke:callback=>{calls.push('invoke');invocations.push({callback});if(options.invoke)options.invoke(callback);else callback(true);},close:()=>calls.push('close')};
  };
  const make=extra=>new DashboardPrintEffects({control,metadata,prepareDocument,timeoutMs:30,...(options.available?{available:options.available}:{}),...extra});
  return {make,records,calls,invocations,prepared,metadata};
}
function actions(f){return f.calls.filter(value=>typeof value==='object');}

test('native printer prepares then flushes planned/burnt before one invocation and persists exact accepted outcome',async()=>{
  const f=fixture(),s=f.make(),result=await s.print(id);
  assert.deepEqual(result,{state:'invoked',already_recorded:false});
  assert.deepEqual(f.calls.map(value=>typeof value==='string'?value:value.action),[
    'print-prepare','prepareDocument','write:planned','print-burn','write:burnt','invoke','write:outcome','print-outcome','write:outcome','close']);
  const burn=actions(f).find(value=>value.action==='print-burn'),outcome=actions(f).find(value=>value.action==='print-outcome');
  assert.equal(burn.actor_id,10);assert.equal(burn.branch,'f:100');assert.equal(burn.render_sha256,sha256(f.prepared.html));
  assert.equal(outcome.permit_hash,sha256('d'.repeat(64)));assert.equal(outcome.state,'invoked');assert.equal(outcome.reason,'spooler_accepted');
  assert.equal(JSON.stringify(f.records.values().next().value).includes('d'.repeat(64)),false,'raw invocation permit is never durable or returned');
  assert.deepEqual(await s.print(id),{state:'invoked',already_recorded:true});assert.equal(f.invocations.length,1);
});
test('two concurrent requests and a reconstructed supervisor cannot invoke one effect twice',async()=>{
  const f=fixture(),s=f.make();await Promise.all([s.print(id),s.print(id)]);await f.make().print(id);
  assert.equal(f.invocations.length,1);assert.equal(actions(f).filter(value=>value.action==='print-burn').length,1);
});
test('a lost burn reply is retained as planned and never retried after restart',async()=>{
  const f=fixture({control:value=>{if(value.action==='print-burn')throw Error('Lost burn reply');}});
  await assert.rejects(f.make().print(id),/Lost burn/);await assert.rejects(f.make().print(id),/مراجعة/);
  assert.equal(f.invocations.length,0);assert.equal(actions(f).filter(value=>value.action==='print-burn').length,1);
  assert.equal(f.records.values().next().value.phase,'outcome');
  assert.equal(f.records.values().next().value.receipt.state,'review');
});
test('a failed durable planned write prevents the burn request and any invocation',async()=>{
  const f=fixture({metadata:{read:async()=>null,write:async()=>{throw Error('Disk unavailable');}}});
  await assert.rejects(f.make().print(id),/Disk unavailable/);assert.equal(f.invocations.length,0);
  assert.equal(actions(f).some(value=>value.action==='print-burn'),false);
});
test('a failed burnt journal flush consumes the server permit and records review without entering Electron',async()=>{
  const f=fixture(),write=f.metadata.write;let failed=false;
  f.metadata.write=async(name,value)=>{if(value.phase==='burnt'&&!failed){failed=true;throw Error('Disk flush failed');}return write(name,value);};
  await assert.rejects(f.make().print(id),/Disk flush failed/);await assert.rejects(f.make().print(id),/مراجعة/);
  assert.equal(f.invocations.length,0);assert.equal(actions(f).find(value=>value.action==='print-outcome').state,'review');
});
test('printer/resource preparation failure does not request a burn or enter the native method',async()=>{
  const f=fixture({prepareFailure:true});await assert.rejects(f.make().print(id),/No printer/);
  assert.equal(actions(f).some(value=>value.action==='print-burn'),false);assert.equal(f.invocations.length,0);
});
test('changed immutable HTML, identity, branch or descriptor hash is rejected before document creation',async()=>{
  for(const patch of [{html:'tampered'}, {effect_id:'e'.repeat(64)},{enrollment_id:'bad'},{branch:'f:100\n'},
    {actor_id:0},{revision:0},{render_sha256:'e'.repeat(64)},{origin_command_id:'not UUID'}]) {
    const f=fixture({prepared:patch});await assert.rejects(f.make().print(id),/التحقق/);
    assert.equal(f.calls.includes('prepareDocument'),false);assert.equal(f.invocations.length,0);
  }
});
test('a rejected callback or synchronous native exception remains review and cannot be retried',async()=>{
  for(const invoke of [callback=>callback(false),()=>{throw Error('Native exception');}]) {
    const f=fixture({invoke});await assert.rejects(f.make().print(id),/مراجعة/);await assert.rejects(f.make().print(id),/مراجعة/);
    assert.equal(f.invocations.length,1);assert.equal(actions(f).find(value=>value.action==='print-outcome').state,'review');
  }
});
test('timeout commits terminal review; a late success callback cannot rewrite that outcome or trigger another invocation',async()=>{
  let callback;const f=fixture({invoke:value=>{callback=value;}});await assert.rejects(f.make({timeoutMs:5}).print(id),/مراجعة/);
  callback(true);callback(false);await new Promise(resolve=>setImmediate(resolve));
  const receipts=actions(f).filter(value=>value.action==='print-outcome');assert.equal(receipts.length,1);
  assert.equal(receipts[0].reason,'native_callback_timeout');assert.equal(f.records.values().next().value.receipt.state,'review');
  await assert.rejects(f.make().print(id),/مراجعة/);assert.equal(f.invocations.length,1);
});
test('lost outcome acknowledgement retries the identical receipt without a second burn or native call',async()=>{
  let lost=true;const f=fixture({control:value=>{if(value.action==='print-outcome'&&lost){lost=false;throw Error('Lost outcome reply');}}});
  await assert.rejects(f.make().print(id),/Lost outcome/);assert.equal(f.records.values().next().value.delivered,false);
  assert.deepEqual(await f.make().print(id),{state:'invoked',already_recorded:true});
  const receipts=actions(f).filter(value=>value.action==='print-outcome');assert.equal(receipts.length,2);assert.deepEqual(receipts[0],receipts[1]);
  assert.equal(f.invocations.length,1);assert.equal(actions(f).filter(value=>value.action==='print-burn').length,1);
});
test('foreign effect/invocation/outcome acknowledgements do not become successful print confirmations',async()=>{
  for(const patch of [{effect_id:'e'.repeat(64)},{invocation_id:command},{state:'review'},{receipt_sha256:'e'.repeat(64)}]) {
    const f=fixture({control:value=>{if(value.action==='print-outcome'){const{action,...receipt}=value;
      return {effect_id:id,invocation_id:value.invocation_id,state:value.state,receipt_sha256:receiptHash(receipt),...patch};}}});
    await assert.rejects(f.make().print(id),/حفظ نتيجة/);assert.equal(f.records.values().next().value.delivered,false);assert.equal(f.invocations.length,1);
  }
});
test('generation or shutdown fencing after preparation prevents burn; after burn retains review',async()=>{
  let available=true;const f=fixture({available:()=>available,control:value=>{if(value.action==='print-prepare')available=false;}});
  await assert.rejects(f.make().print(id),/تنتقل/);assert.equal(actions(f).some(value=>value.action==='print-burn'),false);
  available=true;const b=fixture({available:()=>available,control:value=>{if(value.action==='print-burn')available=false;}});
  await assert.rejects(b.make().print(id),/تنتقل/);assert.equal(b.invocations.length,0);assert.equal(actions(b).find(value=>value.action==='print-outcome').state,'review');
});
test('native polling processes only automatic effects; initial sale and bill grants remain attached to their original buttons',async()=>{
  const automatic=fixture();assert.deepEqual(await automatic.make().pending(),{local:true,invoked:1,attention:false});
  for(const kind of ['sale_receipt','bill_receipt','delivery_batch']){
    const sale=fixture({prepared:{kind}});assert.deepEqual(await sale.make().pending(),{local:true,invoked:0,attention:false});assert.equal(sale.invocations.length,0);
  }
  const dining=fixture({prepared:{kind:'dining_kitchen'}});assert.equal((await dining.make().pending()).invoked,1);
});
test('a real encrypted fsynced journal survives process reconstruction without retaining raw permit or reprinting',async t=>{
  const dir=await fs.mkdtemp(path.join(os.tmpdir(),'native-print-journal-'));t.after(()=>fs.rm(dir,{recursive:true,force:true}));
  const safeStorage={isEncryptionAvailable:()=>true,encryptString:value=>Buffer.from('ENCRYPTED:'+value),decryptString:bytes=>bytes.toString().slice(10)};
  const f=fixture({metadata:new GenerationStore(dir,safeStorage)});await f.make().print(id);
  const bytes=await fs.readFile(path.join(dir,'print-effects',id+'.enc'));assert.equal(bytes.toString().includes('d'.repeat(64)),false);
  await f.make({metadata:new GenerationStore(dir,safeStorage)}).print(id);assert.equal(f.invocations.length,1);
});

test('new dining kitchen and bill snapshots require the same immutable descriptor and one-use burn',async()=>{
  for(const kind of ['dining_kitchen','bill_receipt','delivery_batch']){
    const f=fixture({prepared:{kind}});await f.make().print(id);await f.make().print(id);
    assert.equal(f.invocations.length,1);assert.equal(f.records.values().next().value.identity.kind,kind);
  }
});

test('explicit management reprint retries retain their original audited request UUID',async()=>{
  const f=fixture(),s=f.make(),request='00000000-0000-4000-8000-000000000033';
  await s.receipt('/admin/branch-shifts/12/print',{},request);
  await s.receipt('/admin/branch-shifts/12/print',{},request);
  const resolves=actions(f).filter(value=>value.action==='print-resolve');
  assert.equal(resolves.length,2);assert.equal(resolves[0].request_id,request);assert.equal(resolves[1].request_id,request);assert.equal(resolves[0].explicit_reprint,true);assert.equal(resolves[1].explicit_reprint,true);
  assert.equal(f.invocations.length,1);await assert.rejects(s.receipt('/admin/branch-shifts/12/print',{},'bad'),/معرّف/);
});

test('automatic printing racing the original non-sale URL never requests a new management copy, including uncertain originals',async()=>{
  for(const accepted of [true,false]){
    const f=fixture({prepared:{kind:'dining_kitchen'},invoke:callback=>callback(accepted)}),s=f.make();
    const auto=await s.pending();assert.equal(auto.attention,!accepted);
    if(accepted)assert.equal((await s.receipt('/admin/dining/kitchen/55/print',{})).already_recorded,true);
    else await assert.rejects(s.receipt('/admin/dining/kitchen/55/print',{}),/مراجعة/);
    const resolved=actions(f).find(value=>value.action==='print-resolve');assert.equal(resolved.explicit_reprint,false);
    assert.equal(f.invocations.length,1);assert.equal(actions(f).filter(value=>value.action==='print-burn').length,1);
  }
});
