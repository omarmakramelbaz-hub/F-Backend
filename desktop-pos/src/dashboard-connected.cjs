'use strict';
const crypto=require('node:crypto');
const source=require('./dashboard-source.cjs');
const uuid=value=>/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value||'');
const hash=value=>/^[a-f0-9]{64}$/.test(value||'');
function binding(value){
  let origin;try{origin=new URL(value.serverOrigin);}catch{throw Error('ربط الداشبورد المتصلة غير مكتمل.');}
  if(!uuid(value.deviceId)||!Number.isSafeInteger(value.actorId)||value.actorId<1||!hash(value.token)
    ||origin.protocol!=='https:'||origin.username||origin.password||origin.origin!==value.serverOrigin
    ||!/^[a-f0-9]{40}$/.test(value.sourceRevision||'')||!source.valid(value.sourceFingerprint)
    ||!uuid(value.snapshotId)||!uuid(value.refreshId)||!hash(value.schemaHash)||!/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/.test(value.database||''))
    throw Error('مصدر وربط نسخة الجهاز يحتاجان تحديثًا قبل الداشبورد المتصلة.');
  return {deviceId:value.deviceId,actorId:value.actorId,serverOrigin:value.serverOrigin,tokenHash:crypto.createHash('sha256').update(value.token).digest('hex'),
    sourceRevision:value.sourceRevision,source:{format:1,files:value.sourceFingerprint.files,sha256:value.sourceFingerprint.sha256,framework:value.sourceFingerprint.framework},
    snapshotId:value.snapshotId,refreshId:value.refreshId,schemaHash:value.schemaHash,database:value.database};
}
const same=(left,right)=>JSON.stringify(left)===JSON.stringify(right);
/** Explicit full central access keeps the local database fenced until a proven refreshed return. */
class DashboardConnected {
  constructor({runtime,view,mode,generations,remoteState,remoteAttempts,probe,synchronize,onState=()=>{}}){Object.assign(this,{runtime,view,mode,generations,remoteState,remoteAttempts,probe,synchronize,onState});this.metadata=runtime.metadata;this.transitioning=false;}
  async selected({recover=false}={}){
    const record=await this.metadata.read('central-mode');if(!record)return false;
    if(record.format!==1||!['central','returning'].includes(record.phase)||Object.keys(record).sort().join(',')!=='binding,format,phase')throw Error('تعذر التحقق من وضع الداشبورد المتصلة؛ سجلات الجهاز محفوظة.');
    const current=await this.runtime.connection(),actual=binding(current);
    if(!same(record.binding,actual)){
      // Only the exact durable generation receipt can explain a crash after the
      // return snapshot pointer committed but before the central latch cleared.
      const generation=recover&&record.phase==='returning'?await this.metadata.read('generations/'+current.refreshId):null;
      if(!generation||!same(record.binding,binding(generation.previous||{}))||!same(actual,binding(generation.next||{})))
        throw Error('وضع الداشبورد المتصلة يخص مصدرًا أو ربطًا آخر؛ احتفظ بسجلات الجهاز.');
    }
    if(recover&&record.phase==='returning')await this.metadata.write('central-mode',{format:1,phase:'central',binding:actual});
    return true;
  }
  async deferRefresh(){return this.transitioning||await this.selected();}
  async browse(){if(await this.selected())await this.remoteState.browse();}
  async authorizeDirect(details){
    if(!(await this.selected()))return false;
    if(String(details.method).toUpperCase()==='HEAD')throw Error('فحص الرابط لا يمكنه تنفيذ تعديل من الداشبورد المتصلة.');
    const current=await this.runtime.connection(),url=new URL(details.url);
    if(url.origin!==current.serverOrigin||url.username||url.password)throw Error('عملية الداشبورد المتصلة لا تخص سيرفر الجهاز.');
    // Login is required to restore an expired matching server session. Every
    // subsequent business write independently verifies the enrolled actor.
    if(!(String(details.method).toUpperCase()==='POST'&&url.pathname==='/admin/signin'))await this.sameActor(current);
    return binding(current);
  }
  async sameActor(current){
    const session=await this.probe(current);
    if(session.actor_id!==current.actorId||session.device_id!==current.deviceId)throw Error('حساب الداشبورد المتصلة لا يطابق حساب الجهاز؛ سجّل الدخول بالحساب المرتبط.');
    if(session.connected_request_protocol!==1)throw Error('السيرفر لم يؤكد حماية حساب العمليات المتصلة؛ أكمل تحديث السيرفر قبل فتح الداشبورد المتصلة.');
  }
  async idle(){if(this.view().canRefresh&&!(await this.view().canRefresh()))throw Error('انتقل للصفحة الرئيسية وأكمل أي تعديل أو طباعة قبل تغيير وضع الداشبورد.');}
  open(){return this.mode().serialize('full-central',async()=>{
    if(!(await this.runtime.isPrepared())){await this.view().open();return {prepared:false};}
    if(await this.selected()&&!this.view().current().local){await this.sameActor(await this.runtime.connection());this.view().reveal?.();return {central:true};}
    await this.idle();this.transitioning=true;
    try{
      const previous=await this.runtime.connection();
      await this.view().switchTo({origin:previous.serverOrigin,localToken:''},async()=>{
        await this.remoteAttempts?.recover();
        if(this.view().current().local){
          await this.synchronize();
          await this.mode().cancelHold();
          await this.remoteState.snapshot(()=>this.generations().run());
        }
        await this.mode().hold();
        const current=await this.runtime.connection();await this.sameActor(current);
        await this.metadata.write('central-mode',{format:1,phase:'central',binding:binding(current)});
      });
      this.onState();return {central:true};
    }catch(error){
      if(this.view().current().local){await this.mode().cancelHold();await this.metadata.write('central-mode',null);}
      else await this.mode().hold();
      throw error;
    }finally{this.transitioning=false;}
  });}
  local(){return this.mode().serialize('full-central-return',async()=>{
    if(!(await this.runtime.isPrepared()))throw Error('جهّز نسخة الجهاز أولًا للعمل بدون إنترنت.');
    if(this.view().current().local&&!(await this.selected())){this.view().reveal?.();return {local:true};}
    await this.idle();this.transitioning=true;
    try{
      const current=await this.runtime.connection();await this.sameActor(current);
      await this.view().switchTo({origin:this.runtime.origin,localToken:this.runtime.token},async()=>{
        await this.remoteAttempts?.recover();
        await this.metadata.write('central-mode',{format:1,phase:'returning',binding:binding(await this.runtime.connection())});
        await this.mode().cancelHold();
        await this.remoteState.snapshot(()=>this.generations().run());
        // Actor changes while exporting cannot activate the local dataset.
        await this.sameActor(await this.runtime.connection());
        await this.metadata.write('central-mode',null);
      });
      this.onState();return {local:true};
    }catch(error){
      if(!this.view().current().local){await this.mode().hold();await this.metadata.write('central-mode',{format:1,phase:'central',binding:binding(await this.runtime.connection())});}
      throw error;
    }finally{this.transitioning=false;}
  });}
}
module.exports={DashboardConnected,binding,same};
