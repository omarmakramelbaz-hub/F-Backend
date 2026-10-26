'use strict';
const fs=require('node:fs/promises');
const path=require('node:path');

const hash=value=>typeof value==='string'&&value.length===64&&/^[a-f0-9]{64}$/.test(value);
const uuid=value=>typeof value==='string'&&value.length===36&&/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value);
const object=value=>value!==null&&typeof value==='object'&&!Array.isArray(value);
const count=value=>Number.isSafeInteger(value)&&value>=0;
const positive=value=>Number.isSafeInteger(value)&&value>0;
const branch=value=>typeof value==='string'&&/^(?:f|gs):[1-9][0-9]*$/.test(value)&&!/[\r\n]/.test(value);
const kinds=['sale_receipt','phone_kitchen','shift_report','dining_kitchen','bill_receipt','delivery_batch'];
const reviewReasons=['native_rejected','native_exception','native_callback_timeout','native_interrupted',
  'native_session_lost','native_burn_reply_lost','previous-native-session'];
const unknown=()=>Error('Startup source idle state could not be verified.');
function validReceipt(value){
  return object(value)&&hash(value.effect_id)&&uuid(value.invocation_id)&&uuid(value.origin_command_id)
    &&uuid(value.native_session_id)&&uuid(value.claim_token)&&hash(value.enrollment_id)&&hash(value.permit_hash)
    &&positive(value.actor_id)&&positive(value.revision)&&branch(value.branch)
    &&(value.state==='invoked'&&value.reason==='spooler_accepted'
      ||value.state==='review'&&reviewReasons.includes(value.reason));
}
function recordPending(value,id){
  const identity=value?.identity;
  if(!object(value)||value.format!==1||!object(identity)||identity.effect_id!==id||!hash(id)
    ||!hash(identity.enrollment_id)||!uuid(identity.origin_command_id)||!uuid(identity.device_id)
    ||!kinds.includes(identity.kind)||!positive(identity.actor_id)||!branch(identity.branch)
    ||!positive(identity.revision)||!hash(identity.snapshot_sha256)||!hash(identity.render_sha256)
    ||!uuid(value.invocation_id)||!uuid(value.native_session_id)||!uuid(value.claim_token)
    ||!['planned','burnt','outcome'].includes(value.phase)||typeof value.delivered!=='boolean')throw unknown();
  if(value.phase!=='outcome'){
    if(value.delivered||value.receipt!==undefined||value.phase==='planned'&&value.permit_hash!==undefined
      ||value.phase==='burnt'&&!hash(value.permit_hash))throw unknown();
    return 1;
  }
  const receipt=value.receipt;
  if(!validReceipt(receipt)||['effect_id','actor_id','branch','enrollment_id','origin_command_id','revision']
    .some(key=>receipt[key]!==identity[key])||['invocation_id','native_session_id','claim_token']
    .some(key=>receipt[key]!==value[key])||value.permit_hash!==undefined&&value.permit_hash!==receipt.permit_hash)throw unknown();
  return value.delivered?0:1;
}
const unchanged=(a,b)=>a.dev===b.dev&&a.ino===b.ino&&a.size===b.size&&a.mtimeMs===b.mtimeMs&&a.ctimeMs===b.ctimeMs;
async function nativePrintPending(metadata){
  // GenerationStore exposes its profile root and read(name), not a directory
  // listing API. Enumerate only this private folder; decrypt through read().
  if(typeof metadata?.read!=='function'||typeof metadata.profile!=='string'||!path.isAbsolute(metadata.profile))throw unknown();
  const folder=path.join(metadata.profile,'print-effects');
  let before;
  try{before=await fs.lstat(folder);}catch(error){if(error.code==='ENOENT')return 0;throw error;}
  if(!before.isDirectory()||before.isSymbolicLink())throw unknown();
  const names=await fs.readdir(folder);
  if(names.length>10000)throw unknown();
  let pending=0;
  for(const name of names){
    if(name.length!==68||!/^[a-f0-9]{64}\.enc$/.test(name))throw unknown();
    const file=path.join(folder,name),info=await fs.lstat(file);
    if(!info.isFile()||info.isSymbolicLink()||info.size>1024*1024)throw unknown();
    const id=name.slice(0,-4),record=await metadata.read('print-effects/'+id);
    if(!unchanged(info,await fs.lstat(file)))throw unknown();
    pending+=recordPending(record,id);
  }
  if(!unchanged(before,await fs.lstat(folder)))throw unknown();
  return pending;
}

/** Read-only startup gate. It never repairs, acknowledges or invokes printing. */
async function inspectStartupIdle({store,runtime,metadata}={}){
  if(typeof store?.counts!=='function'||typeof store.openOrders!=='function'
    ||typeof runtime?.control!=='function'||typeof runtime.supportsPrintEffects!=='function')throw unknown();
  const legacy=await store.counts(),orders=await store.openOrders();
  // SQLite SUM is null for an empty legacy outbox; its exact empty count is 0.
  const legacyErrors=legacy?.errors===null&&legacy.pending===0?0:legacy?.errors;
  if(!object(legacy)||!count(legacy.pending)||!count(legacyErrors)||legacyErrors>legacy.pending
    ||!Array.isArray(orders)||Object.hasOwn(legacy,'conflicts')&&(!count(legacy.conflicts)||legacy.conflicts>legacy.pending))throw unknown();
  const local=await runtime.control({action:'pending'});
  if(!object(local)||!object(local.counts)||!count(local.counts.pending)||!count(local.counts.conflicts)
    ||!Array.isArray(local.commands)||local.commands.length>local.counts.pending)throw unknown();
  const supported=await runtime.supportsPrintEffects();
  if(typeof supported!=='boolean'||Object.hasOwn(local,'print_protocol')&&![0,1].includes(local.print_protocol)
    ||!supported&&local.print_protocol===1)throw unknown();
  let printEffects=await nativePrintPending(metadata);
  if(supported){
    const outcomes=await runtime.control({action:'print-outcomes'});
    if(!object(outcomes)||!Array.isArray(outcomes.outcomes)||outcomes.outcomes.length>10000
      ||!count(outcomes.pending)||outcomes.pending<outcomes.outcomes.length
      ||outcomes.outcomes.some(value=>!validReceipt(value)))throw unknown();
    // Without a session ID this does no previous-session repair; the default
    // automatic-only selection cannot enter the sale-grant expiry write path.
    const listing=await runtime.control({action:'print-list'});
    if(!object(listing)||!Array.isArray(listing.effects)||listing.effects.length>500||!count(listing.attention)
      ||listing.effects.some(value=>!object(value)||!hash(value.effect_id)
        ||!['phone_kitchen','dining_kitchen','shift_report'].includes(value.kind)||!branch(value.branch)
        ||!positive(value.actor_id)||!uuid(value.origin_command_id)||!positive(value.revision)))throw unknown();
    // Overlap is harmless: only exact zero authorizes a source transition.
    printEffects+=outcomes.pending+listing.effects.length+listing.attention;
    if(!count(printEffects))throw unknown();
  }
  return {pending:local.counts.pending,conflicts:local.counts.conflicts,printEffects,
    legacyPending:legacy.pending,legacyConflicts:Math.max(legacyErrors,legacy.conflicts||0),openOrders:orders.length};
}
module.exports={inspectStartupIdle};
