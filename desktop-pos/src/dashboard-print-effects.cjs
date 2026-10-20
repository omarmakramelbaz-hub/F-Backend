'use strict';
const crypto = require('node:crypto');
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value);
const sha256 = value => crypto.createHash('sha256').update(value).digest('hex');
const reviewMessage = 'نتيجة الطباعة تحتاج مراجعة. لن يعيد البرنامج الطباعة تلقائيًا؛ إعادة الطباعة تحتاج عملية جديدة من الحساب المسموح.';
function descriptor(value, effectId) {
  if (!value || value.effect_id !== effectId || !hash(value.enrollment_id) || !uuid(value.origin_command_id)
      || !uuid(value.device_id) || !['sale_receipt','phone_kitchen','shift_report','dining_kitchen','bill_receipt','delivery_batch'].includes(value.kind)
      || !Number.isSafeInteger(value.actor_id) || value.actor_id < 1 || !/^(?:f|gs):[1-9][0-9]*$/.test(value.branch || '')
      || !Number.isSafeInteger(value.revision) || value.revision < 1 || !hash(value.snapshot_sha256)
      || typeof value.html !== 'string' || Buffer.byteLength(value.html) > 3 * 1024 * 1024
      || !/\bdata-dashboard-receipt\s*=/.test(value.html) || !/<head\b/i.test(value.html)
      || !hash(value.render_sha256) || sha256(value.html) !== value.render_sha256)
    throw Error('تعذر التحقق من مستند الطباعة المحفوظ.');
  return Object.fromEntries(['effect_id','enrollment_id','origin_command_id','device_id','kind','actor_id','branch',
    'revision','snapshot_sha256','render_sha256'].map(key => [key,value[key]]));
}
function canonical(value) {
  if(Array.isArray(value))return value.map(canonical);
  if(value && typeof value==='object')return Object.fromEntries(Object.keys(value).sort().map(key=>[key,canonical(value[key])]));
  return value;
}
const receiptHash=value=>sha256(JSON.stringify(canonical(value)));

/** Native-only one-use side effects. A database burn cannot be reconstructed from a journal. */
class DashboardPrintEffects {
  constructor({control,metadata,prepareDocument,available=()=>true,timeoutMs=45000,sessionId=crypto.randomUUID()}) {
    if (!uuid(sessionId) || !Number.isInteger(timeoutMs) || timeoutMs < 1 || timeoutMs > 120000) throw Error('Invalid native printing configuration.');
    Object.assign(this,{control,metadata,prepareDocument,available,timeoutMs,sessionId});
    this.tail=Promise.resolve();this.active=0;
  }
  serialize(work) { const result=this.tail.then(work);this.tail=result.catch(()=>{});return result; }
  name(id) { if(!hash(id))throw Error('معرّف الطباعة غير صالح.');return 'print-effects/'+id; }
  assertAvailable() { if(!this.available())throw Error('الداشبورد تنتقل أو تُغلق؛ المستند محفوظ دون إعادة طباعة.'); }
  async flushRecord(record) {
    const result=await this.control({action:'print-outcome',...record.receipt});
    // An arbitrary successful HTTP reply does not acknowledge a different effect.
    if(!result || result.effect_id!==record.identity.effect_id || result.invocation_id!==record.invocation_id
        || result.state!==record.receipt.state || result.receipt_sha256!==receiptHash(record.receipt))
      throw Error('لم يتأكد حفظ نتيجة الطباعة؛ المستند محفوظ للمراجعة.');
    await this.metadata.write(this.name(record.identity.effect_id),{...record,delivered:true});
  }
  async remembered(id) {
    const record=await this.metadata.read(this.name(id));
    if(!record)return null;
    if(record.format!==1 || record.identity?.effect_id!==id || !uuid(record.invocation_id)
        || !uuid(record.native_session_id) || !['planned','burnt','outcome'].includes(record.phase))
      throw Error('تعذر التحقق من سجل الطباعة؛ احتفظ بملفات الجهاز للمراجعة.');
    if(['planned','burnt'].includes(record.phase)) {
      // The private ledger can resolve a lost burn reply from the original
      // persisted tuple. It returns evidence only, never a replacement permit.
      const resolved=await this.control({action:'print-review',effect_id:id,actor_id:record.identity.actor_id,
        branch:record.identity.branch,enrollment_id:record.identity.enrollment_id,
        origin_command_id:record.identity.origin_command_id,revision:record.identity.revision,
        claim_token:record.claim_token,native_session_id:record.native_session_id,invocation_id:record.invocation_id,
        reason:'native_burn_reply_lost'});
      const saved=resolved?.receipt;
      if(!saved||resolved.effect_id!==id||resolved.invocation_id!==record.invocation_id||resolved.state!=='review'
          ||saved.effect_id!==id||saved.invocation_id!==record.invocation_id||saved.state!=='review'
          ||saved.actor_id!==record.identity.actor_id||saved.branch!==record.identity.branch
          ||saved.enrollment_id!==record.identity.enrollment_id||saved.origin_command_id!==record.identity.origin_command_id
          ||saved.revision!==record.identity.revision||saved.claim_token!==record.claim_token
          ||saved.native_session_id!==record.native_session_id||!hash(saved.permit_hash)
          ||resolved.receipt_sha256!==receiptHash(saved))throw Error(reviewMessage);
      await this.metadata.write(this.name(id),{...record,phase:'outcome',receipt:saved,delivered:true});
      throw Error(reviewMessage);
    }
    if(record.phase==='outcome' && record.receipt && !record.delivered)await this.flushRecord(record);
    if(record.phase==='outcome' && record.receipt?.state==='invoked')return {state:'invoked',already_recorded:true};
    throw Error(reviewMessage);
  }
  print(id) { return this.serialize(async()=>{
    this.assertAvailable();this.active++;
    let document,record,permit;
    try {
      const remembered=await this.remembered(id);if(remembered)return remembered;
      const claimToken=crypto.randomUUID(),invocationId=crypto.randomUUID();
      const request={effect_id:id,claim_token:claimToken,native_session_id:this.sessionId};
      const prepared=await this.control({action:'print-prepare',...request});
      const identity=descriptor(prepared,id);
      // Font/image/printer validation happens before consuming the grant.
      document=await this.prepareDocument(prepared);
      if(!document || typeof document.invoke!=='function' || typeof document.close!=='function')throw Error('تعذر تجهيز طابعة الفاتورة.');
      this.assertAvailable();
      record={format:1,identity,invocation_id:invocationId,native_session_id:this.sessionId,claim_token:claimToken,phase:'planned',delivered:false};
      // This is flushed before a burn request can leave the process. Any old
      // planned/burnt record fences this identity after restart or a lost reply.
      await this.metadata.write(this.name(id),record);
      const base={...request,actor_id:identity.actor_id,branch:identity.branch,invocation_id:invocationId,
        enrollment_id:identity.enrollment_id,origin_command_id:identity.origin_command_id,revision:identity.revision,
        snapshot_sha256:identity.snapshot_sha256,render_sha256:identity.render_sha256};
      const burn=await this.control({action:'print-burn',...base});
      if(!burn || burn.effect_id!==id || burn.invocation_id!==invocationId || !hash(burn.permit)
          || burn.render_sha256!==identity.render_sha256)throw Error(reviewMessage);
      permit=burn.permit;
      record={...record,phase:'burnt',permit_hash:sha256(permit)};
      await this.metadata.write(this.name(id),record);
      this.assertAvailable();
      // No awaited operation occurs between consuming the live permit and the
      // single call into Electron. An exception/timeout is terminal review.
      permit=null;
      const outcome=await this.invokeOnce(document);
      const receipt={effect_id:id,actor_id:identity.actor_id,branch:identity.branch,claim_token:claimToken,
        native_session_id:this.sessionId,invocation_id:invocationId,permit_hash:record.permit_hash,
        enrollment_id:identity.enrollment_id,origin_command_id:identity.origin_command_id,revision:identity.revision,
        state:outcome.state,reason:outcome.reason};
      record={...record,phase:'outcome',receipt};
      await this.metadata.write(this.name(id),record);
      await this.flushRecord(record);
      if(outcome.state!=='invoked')throw Error(reviewMessage);
      return {state:'invoked',already_recorded:false};
    } catch(error) {
      // A pre-invocation failure after a successful burn also consumes the
      // permit. Persist review when possible; never return it to the renderer.
      if(record?.phase==='burnt') {
        const receipt={effect_id:id,actor_id:record.identity.actor_id,branch:record.identity.branch,claim_token:record.claim_token,
          native_session_id:record.native_session_id,invocation_id:record.invocation_id,permit_hash:record.permit_hash,
          enrollment_id:record.identity.enrollment_id,origin_command_id:record.identity.origin_command_id,revision:record.identity.revision,
          state:'review',reason:'native_interrupted'};
        const interrupted={...record,phase:'outcome',receipt};
        try{await this.metadata.write(this.name(id),interrupted);await this.flushRecord(interrupted);}catch{}
      }
      throw error;
    } finally {permit=null;try{await document?.close();}finally{this.active--;}}
  }); }
  invokeOnce(document) {
    return new Promise(resolve=>{
      let finished=false;
      const finish=(state,reason)=>{if(finished)return;finished=true;clearTimeout(timer);resolve({state,reason});};
      const timer=setTimeout(()=>finish('review','native_callback_timeout'),this.timeoutMs);
      try{document.invoke((accepted)=>finish(accepted===true?'invoked':'review',accepted===true?'spooler_accepted':'native_rejected'));}
      catch{finish('review','native_exception');}
    });
  }
  async inspect() {
    this.assertAvailable();
    const result=await this.control({action:'print-list',native_session_id:this.sessionId});
    if(!result || !Array.isArray(result.effects) || result.effects.length>500)throw Error('تعذر قراءة قائمة الطباعة المحفوظة.');
    return result;
  }
  async pending() {
    const result=await this.inspect();
    let attention=Boolean(result.attention),invoked=0;
    for(const item of result.effects) {
      if(!hash(item?.effect_id) || !['sale_receipt','phone_kitchen','shift_report','dining_kitchen','bill_receipt','delivery_batch'].includes(item.kind))throw Error('قائمة الطباعة غير صالحة.');
      // Initial receipts remain controlled by the original checkout UI.
      if(['sale_receipt','bill_receipt','delivery_batch'].includes(item.kind))continue;
      try{const printed=await this.print(item.effect_id);if(!printed.already_recorded)invoked++;}
      catch{attention=true;}
    }
    return {local:true,invoked,attention};
  }
  async receipt(path,query,requestId) {
    this.assertAvailable();
    const explicitReprint=requestId!==undefined;
    requestId=requestId===undefined?crypto.randomUUID():requestId;
    if(!uuid(requestId))throw Error('معرّف طلب الطباعة غير صالح.');
    const result=await this.control({action:'print-resolve',path,query,request_id:requestId,explicit_reprint:explicitReprint});
    if(!hash(result?.effect_id))throw Error('هذه الفاتورة تحتاج طلب طباعة جديدًا من الحساب المسموح.');
    return this.print(result.effect_id);
  }
}
module.exports={DashboardPrintEffects,descriptor,sha256,receiptHash};
