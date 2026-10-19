'use strict';
const {receiptHash}=require('./dashboard-print-effects.cjs');
const hash=value=>typeof value==='string'&&/^[a-f0-9]{64}$/.test(value);
const uuid=value=>typeof value==='string'&&/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value);
function activeProtocol(value) {
  if(value===0)return false;
  if(value===1)return true;
  throw Error('إصدار سجل الطباعة غير معروف؛ العمليات محفوظة لحين تحديث البرنامج.');
}
function receipt(value) {
  if(!value||!hash(value.effect_id)||!uuid(value.invocation_id)||!uuid(value.origin_command_id)
      ||!uuid(value.native_session_id)||!uuid(value.claim_token)||!hash(value.enrollment_id)||!hash(value.permit_hash)
      ||!Number.isSafeInteger(value.actor_id)||value.actor_id<1||!Number.isSafeInteger(value.revision)||value.revision<1
      ||!/^(?:f|gs):[1-9][0-9]*$/.test(value.branch||'')||!['invoked','review'].includes(value.state)
      ||!['spooler_accepted','native_rejected','native_exception','native_callback_timeout','native_interrupted','native_session_lost','native_burn_reply_lost','previous-native-session'].includes(value.reason))
    throw Error('تعذر التحقق من نتائج الطباعة المحفوظة.');
  return value;
}
/** Deliver immutable receipts after business ingest. This class never opens a printer. */
module.exports=async function synchronizePrintOutcomes({local,remote,stopped=()=>false}) {
  const batch=await local({action:'print-outcomes'});
  if(!batch||!Array.isArray(batch.outcomes)||batch.outcomes.length>10000)throw Error('تعذر قراءة نتائج الطباعة المحفوظة.');
  let pending=batch.pending===undefined?batch.outcomes.length:batch.pending;
  if(!Number.isSafeInteger(pending)||pending<batch.outcomes.length)throw Error('عدد نتائج الطباعة غير صالح.');
  for(const value of batch.outcomes.slice(0,100)) {
    if(stopped())return {pending,deferred:true};
    const saved=receipt(value);let ack;
    try{ack=await remote(saved);}
    catch(error){if(error.status===409)return {pending,deferred:true};throw error;}
    if(stopped())return {pending,deferred:true};
    const expected={effect_id:saved.effect_id,invocation_id:saved.invocation_id,state:saved.state,receipt_sha256:receiptHash(saved)};
    if(!ack||Object.entries(expected).some(([key,wanted])=>ack[key]!==wanted))throw Error('لم يؤكد السيرفر نتيجة الطباعة المطابقة.');
    const confirmed=await local({action:'print-acknowledge',receipt:expected});
    if(!confirmed||Object.entries(expected).some(([key,wanted])=>confirmed[key]!==wanted))throw Error('لم يتأكد حفظ تأكيد الطباعة في الجهاز.');
    pending--;
  }
  return {pending,deferred:pending>0};
};
module.exports.activeProtocol=activeProtocol;
