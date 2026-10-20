'use strict';
const uuid = value => /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value || '');
const hash = value => /^[a-f0-9]{64}$/.test(value || '');
const central=require('./dashboard-connected.cjs');
const crypto=require('node:crypto');
const instant=value=>typeof value==='string'&&!Number.isNaN(Date.parse(value))&&new Date(value).toISOString()===value;
const fingerprint=value=>crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
const getPolicy=require('./dashboard-get-policy.cjs');

/** Native reservations bind delayed original HTTP writes to a terminal server decision. */
class DashboardRemoteAttempts {
  constructor({ state, metadata, credential, request, direct }) {
    this.state = state; this.metadata = metadata; this.credential = credential; this.request = request; this.active = new Set(); this.direct=direct; this.directLocks=new Map();
  }
  supported(details) {
    const path = new URL(details.url).pathname, method = String(details.method).toUpperCase();
    if(['POST','PUT','PATCH'].includes(method)&&/^\/admin\/users\/[1-9][0-9]{0,18}$/.test(path))return true;
    if(method==='GET'&&/^\/admin\/change-status\/[1-9][0-9]{0,18}$/.test(path))return true;
    if(['POST','DELETE'].includes(method)&&/^\/admin\/userAddressDelete\/[1-9][0-9]{0,18}$/.test(path))return true;
    if(method==='POST'&&/^\/admin\/order-board\/(?:legacy|store|service|partner_service)\/[1-9][0-9]{0,18}\/action$/.test(path))return true;
    if(['POST','PUT'].includes(method)&&['/admin/settings/update','/admin/advertising/update'].includes(path))return true;
    if(method==='POST'&&['/admin/wallets/transfer','/admin/wallets/withdraw/store'].includes(path))return true;
    if(['POST','DELETE'].includes(method)&&/^\/admin\/resturant_reviews\/[1-9][0-9]{0,18}$/.test(path))return true;
    if(['POST','DELETE'].includes(method)&&/^\/admin\/userWishlistsDelete\/[1-9][0-9]{0,18}$/.test(path))return true;
    if(method==='GET'&&path==='/admin/resturantControl')return true;
    if(method==='POST'&&path==='/admin/post-sortable')return true;
    if(['POST','PUT','PATCH'].includes(method)&&/^\/admin\/resturants\/[1-9][0-9]{0,18}$/.test(path))return true;
    if(method==='POST'&&/^\/admin\/updateStatus\/[1-9][0-9]{0,18}$/.test(path))return true;
    if(method==='POST'&&path==='/admin/go-stores')return true;
    if(method==='POST'&&/^\/admin\/go-stores\/[1-9][0-9]{0,18}(?:\/products(?:\/[1-9][0-9]{0,18})?)?$/.test(path))return true;
    if(method==='POST'&&/^\/admin\/resturantchange(?:Status|UnderContract)\/[1-9][0-9]{0,18}$/.test(path))return true;
    if(method==='POST'&&/^\/admin\/update_sorting_is_featured\/[1-9][0-9]{0,18}$/.test(path))return true;
    if(method==='GET'&&/^\/admin\/resturant_products\/[1-9][0-9]{0,18}\/update\/(?:status|highest_rated)$/.test(path))return true;
    if(method==='POST'&&path==='/admin/resturant/copy/items')return true;
    // Original multipart forms use POST with a server-validated PUT/DELETE override.
    // Reserve the physical request; Chromium keeps its original body and file bytes.
    if((method==='POST'&&path==='/admin/resturant_products')
      ||(['POST','PUT'].includes(method)&&path==='/admin/resturant_products/update')
      ||(['POST','DELETE'].includes(method)&&/^\/admin\/resturant_products\/[1-9][0-9]{0,18}$/.test(path)))return true;
    if(method==='POST'&&/^\/admin\/order-board\/menu\/(?:f|gs)\/[1-9][0-9]{0,18}\/products\/[1-9][0-9]{0,18}\/availability$/.test(path))return true;
    if((['POST','PUT'].includes(method)&&/^\/admin\/read\/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(path))
      ||(method==='POST'&&path==='/admin/read/all/notification'))return true;
    if(method==='POST'&&(path==='/admin/dashboard-inbox/notifications/read'
      || /^\/admin\/takeaway\/(?:checkout|till\/(?:movements|settings))$/.test(path)
      || /^\/admin\/(?:dining|phone-orders)\/tickets\/(?:save|[1-9][0-9]{0,18}\/(?:action|settle))$/.test(path)
      || /^\/admin\/dining\/(?:tables|settings)$/.test(path)
      || /^\/admin\/phone-orders\/(?:dispatch-company|finish-batch)$/.test(path)
      || /^\/admin\/(?:customers|delivery-companies)\/save$/.test(path)
      || /^\/admin\/customers\/(?:directory-save|consent)$/.test(path)
      || /^\/admin\/employees\/(?:save|attendance|attendance-rules|entry|wallet|daily-notes|void-entry|close|pay)$/.test(path)
      || /^\/admin\/branch-stock\/(?:receive|recipes|minimum|basic-recipes)$/.test(path)
      || /^\/admin\/branch-expenses\/[1-9][0-9]{0,18}\/review$/.test(path)
      || path==='/admin/branch-expenses/categories'
      || path==='/admin/branch-expenses/save'
      || path==='/admin/branch-shifts/close'))return true;
    if((['POST','DELETE'].includes(method)&&/^\/admin\/contacts\/[1-9][0-9]{0,18}$/.test(path))
        ||(method==='DELETE'&&path==='/admin/contactsDeleteAll'))return true;
    if((method==='POST'&&path==='/admin/roles')||(method==='DELETE'&&path==='/admin/rolesDeleteAll'))return true;
    if(['POST','PUT','PATCH','DELETE'].includes(method)&&/^\/admin\/roles\/[1-9][0-9]{0,18}$/.test(path))return true;
    return ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)
      && (/^\/admin\/(?:areas|categorys|products|question_answers|features|contracts)(?:\/[1-9][0-9]{0,18})?$/.test(path)
        || (method === 'DELETE' && /^\/admin\/(?:areas|categorys|products|question_answers|features)DeleteAll$/.test(path)));
  }
  validate(receipt, record) {
    if (receipt?.format !== 1 || receipt.id !== record.id || receipt.device_id !== record.deviceId || receipt.actor_id !== record.actorId
        || receipt.method !== record.method || receipt.path !== record.path || !['ready', 'committed', 'cancelled'].includes(receipt.status))
      throw Error('رد نتيجة السيرفر لا يطابق عملية الجهاز؛ العملية محفوظة.');
    return receipt;
  }
  async begin(id, details) {
    if(String(details.method).toUpperCase()==='HEAD'&&getPolicy.mutation(new URL(details.url).pathname))throw Error('فحص رابط التعديل لا يمكنه تنفيذ عملية؛ استخدم الزر الأصلي.');
    if(this.active.has(id))throw Error('هذه العملية ما زالت قيد التنفيذ؛ لا ترسلها مرة أخرى.');
    this.active.add(id);
    try {
      const direct=await this.direct?.(details);
      if(direct){
        const credential=await this.credential();
        if(!credential||!central.same(direct,central.binding(credential))||!uuid(id))throw Error('ربط العملية المتصلة غير متطابق.');
        return await this.lockDirect(id,async()=>{
          if(await this.metadata.read('remote-central/'+id))throw Error('هذه العملية مسجلة بالفعل؛ راجع نتيجتها قبل إرسال عملية جديدة.');
          // Preserve the original identity forever. Observations and human
          // decisions are separate receipts; neither rewrites the request.
          await this.metadata.write('remote-central/'+id,{format:1,id,binding:direct,method:String(details.method).toUpperCase(),path:new URL(details.url).pathname,created_at:new Date().toISOString()});
          await this.state.begin(id);
          return {'X-Fasakhansta-Connected-Device':credential.deviceId,'X-Fasakhansta-Connected-Token':credential.token};
        });
      }
      let credential;
      if (this.supported(details)) credential = await this.credential();
      if (!credential) { await this.state.begin(id); return {}; }
      const record = { format: 1, id, deviceId: credential.deviceId, actorId: credential.actorId, serverOrigin: credential.serverOrigin,
        method: String(details.method).toUpperCase(), path: new URL(details.url).pathname };
      if (!uuid(id) || new URL(details.url).origin !== credential.serverOrigin) throw Error('طلب السيرفر لا يخص ربط الجهاز.');
      // The binding survives a crash before reservation, including a lost reservation reply.
      await this.metadata.write('remote-attempts/' + id, record);
      await this.state.begin(id);
      const receipt = this.validate(await this.request({ action: 'reserve', id, method: record.method, path: record.path }, credential), record);
      if (receipt.status !== 'ready' || !hash(receipt.capability)) throw Error('الطلب السابق اكتملت نتيجته؛ أعد فتح الصفحة قبل عملية جديدة.');
      return { 'X-Fasakhansta-Remote-Attempt': id, 'X-Fasakhansta-Remote-Capability': receipt.capability };
    } catch (error) { this.active.delete(id); throw error; }
  }
  async settle(id) {
    const record = await this.metadata.read('remote-attempts/' + id);
    if (!record) {
      const direct=await this.metadata.read('remote-central/'+id);
      if(!direct)return false; // Earlier unreserved operations retain their uncertainty.
      return this.lockDirect(id,async()=>{
        const current=await this.metadata.read('remote-central/'+id);await this.validateDirect(current,id);
        const reviewed=await this.metadata.read('remote-central-reviews/'+id);
        if(reviewed)await this.validateReview(reviewed,current,id);
        const observed=await this.metadata.read('remote-central-responses/'+id);
        if(observed)await this.validateObservation(observed,current,id);
        if(!reviewed&&!observed)throw Error('لم يتأكد رد عملية من الداشبورد المتصلة؛ راجع نتيجتها على السيرفر ثم سجل المراجعة من قائمة البرنامج. النسخة المحلية ما زالت متوقفة.');
        await this.state.complete(id);return true;
      });
    }
    const credential = await this.credential();
    if(!credential)throw Error('تعذر استرجاع ربط عملية السيرفر؛ سجلها محفوظ.');
    if (record.format !== 1 || record.id !== id || !uuid(id) || record.deviceId !== credential.deviceId
        || record.actorId !== credential.actorId || record.serverOrigin !== credential.serverOrigin)
      throw Error('عملية السيرفر المحفوظة لا تخص ربط الجهاز الحالي.');
    const receipt = this.validate(await this.request({ action: 'settle', id, method: record.method, path: record.path }, credential), record);
    if (!['committed', 'cancelled'].includes(receipt.status)) throw Error('السيرفر لم يحسم نتيجة العملية بعد.');
    await this.metadata.write('remote-attempts/' + id, { ...record, receipt });
    await this.state.complete(id); // Keep dirty=true until the complete dataset refresh succeeds.
    return true;
  }
  lockDirect(id,work){
    const previous=this.directLocks.get(id)||Promise.resolve();const task=previous.catch(()=>{}).then(work);this.directLocks.set(id,task);
    task.finally(()=>{if(this.directLocks.get(id)===task)this.directLocks.delete(id);}).catch(()=>{});return task;
  }
  async validateReview(review,record,id){
    await this.validateDirect(record,id);
    if(review.format!==1||review.decision!=='human-reviewed'||review.request_id!==id||!uuid(review.audit_id)||!instant(review.reviewed_at)
      ||review.actor_id!==record.binding.actorId||!central.same(review.binding,record.binding)||review.request_sha256!==fingerprint(record)
      ||Object.keys(review).sort().join(',')!=='actor_id,audit_id,binding,decision,format,request_id,request_sha256,reviewed_at')
      throw Error('المراجعة البشرية المحفوظة لا تخص العملية وربطها؛ سجل العملية محفوظ.');
  }
  async validateObservation(observed,record,id){
    await this.validateDirect(record,id);
    if(observed.format!==1||observed.observation!=='response-received'||observed.request_id!==id||!instant(observed.observed_at)
      ||!central.same(observed.binding,record.binding)||observed.request_sha256!==fingerprint(record)
      ||Object.keys(observed).sort().join(',')!=='binding,format,observation,observed_at,request_id,request_sha256')
      throw Error('رد العملية المحفوظ لا يخص الطلب وربطه؛ سجل العملية محفوظ.');
  }
  async reviewable(){
    const records=[];for(const id of (await this.state.read()).pending){
      const record=await this.metadata.read('remote-central/'+id);if(!record)continue;await this.validateDirect(record,id);
      if(!this.active.has(id)&&!await this.metadata.read('remote-central-reviews/'+id)&&!await this.metadata.read('remote-central-responses/'+id))records.push(record);
      if(records.length>=20)break;
    }return records;
  }
  humanReview(id){return this.lockDirect(id,async()=>{
    if(this.active.has(id))throw Error('الطلب ما زال قيد التنفيذ؛ انتظر رده.');
    const record=await this.metadata.read('remote-central/'+id);if(!record)throw Error('هوية العملية المتصلة غير متاحة؛ سجلها محفوظ.');await this.validateDirect(record,id);
    let decision=await this.metadata.read('remote-central-reviews/'+id);
    if(decision)await this.validateReview(decision,record,id);
    const observed=await this.metadata.read('remote-central-responses/'+id);
    if(observed)await this.validateObservation(observed,record,id);
    if(!decision&&!observed){
      decision={format:1,audit_id:crypto.randomUUID(),decision:'human-reviewed',request_id:id,request_sha256:fingerprint(record),binding:record.binding,actor_id:record.binding.actorId,reviewed_at:new Date().toISOString()};
      await this.metadata.write('remote-central-reviews/'+id,decision);
    }
    await this.state.complete(id);return decision||{observed:true};
  });}
  async validateDirect(record,id){
    const credential=await this.credential();
    if(!credential||!record||record.format!==1||record.id!==id||!uuid(id)||!['GET','POST','PUT','PATCH','DELETE','OPTIONS'].includes(record.method)
      ||typeof record.path!=='string'||(!getPolicy.admin(record.path)&&!getPolicy.mutation(record.path))
      ||!instant(record.created_at)||Object.keys(record).sort().join(',')!=='binding,created_at,format,id,method,path'||!central.same(record.binding,central.binding(credential)))
      throw Error('سجل العملية المتصلة لا يطابق مصدر وربط الجهاز؛ العملية محفوظة.');
  }
  async complete(id) {
    this.active.delete(id);
    const direct=await this.metadata.read('remote-central/'+id);
    if(direct)return this.lockDirect(id,async()=>{
      const current=await this.metadata.read('remote-central/'+id);await this.validateDirect(current,id);
      const reviewed=await this.metadata.read('remote-central-reviews/'+id);
      if(reviewed)await this.validateReview(reviewed,current,id);
      const observed=await this.metadata.read('remote-central-responses/'+id);
      if(observed)await this.validateObservation(observed,current,id);
      else await this.metadata.write('remote-central-responses/'+id,{format:1,observation:'response-received',request_id:id,request_sha256:fingerprint(current),binding:current.binding,observed_at:new Date().toISOString()});
      await this.state.complete(id);
    });
    if (!(await this.settle(id))) await this.state.complete(id);
  }
  failed(id) { this.active.delete(id); }
  async recover() {
    for (const id of (await this.state.read()).pending) if (!this.active.has(id)) await this.settle(id);
  }
}
module.exports = DashboardRemoteAttempts;
