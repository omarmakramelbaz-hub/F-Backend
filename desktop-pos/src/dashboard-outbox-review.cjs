'use strict';
const crypto=require('node:crypto');
const uuid=value=>typeof value==='string'&&/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value);
const hash=value=>typeof value==='string'&&/^[a-f0-9]{64}$/.test(value);

/** A human can inspect/retry the first retained command; nothing is discarded or acknowledged. */
class DashboardOutboxReview {
  constructor({runtime,dialog,synchronize=async()=>{},now=()=>new Date().toISOString(),randomUUID=crypto.randomUUID}){
    Object.assign(this,{runtime,dialog,synchronize,now,randomUUID});
  }
  open(window){
    if(this.running)return this.running;
    this.running=this.review(window).finally(()=>{this.running=null;});return this.running;
  }
  async review(window){
    if(!this.runtime.manifest?.sourceHashes?.['app/Services/Dashboard/DesktopDashboardOutboxReview.php'])
      throw Error('نسخة بيانات الجهاز المحفوظة تحتاج تحديثًا قبل فتح مراجعة المزامنة؛ العمليات محفوظة.');
    const source=await this.runtime.connection();
    const value=await this.runtime.control({action:'outbox-review'}),first=value.first;
    if(value.format!==1||value.device_id!==source.deviceId||value.actor_id!==source.actorId||value.snapshot_id!==source.snapshotId
        ||typeof value.held!=='boolean'||!value.counts||!['pending','conflicts','acknowledged'].every(k=>Number.isSafeInteger(value.counts[k])&&value.counts[k]>=0)
        ||(first===null)!==(value.counts.pending+value.counts.conflicts===0)
        ||first&&(!uuid(first.command_id)||!hash(first.review_token)||first.actor_id!==source.actorId
          ||!['pending','conflict'].includes(first.status)||!Number.isSafeInteger(first.sequence)||first.sequence<1
          ||!Number.isSafeInteger(first.attempts)||first.attempts<0||typeof first.occurred_at!=='string'||first.occurred_at.length>60
          ||typeof first.route_name!=='string'||first.route_name.length>150||typeof first.last_error!=='string'||first.last_error.length>4000))
      throw Error('تعذر التحقق من سجل المراجعة؛ جميع العمليات محفوظة.');
    const detail=first?'رقم العملية: '+first.command_id+'\nالقسم: '+first.route_name+'\nوقت الحفظ: '+first.occurred_at
      +'\nعدد محاولات الإرسال: '+first.attempts+'\nسبب التوقف: '+(first.last_error||'لم يصل تأكيد من السيرفر بعد.')
      +'\n\nالعملية نُفّذت محليًا. العمليات التالية وتحديث بيانات الجهاز ينتظران تأكيدها. لا تنشئ بديلًا لها برقم جديد.':
      'كل عمليات الداشبورد المحفوظة مؤكدة. سجل النسخة السابقة مستقل ويمكن فتحه من قائمة البرنامج.';
    const retry=first?.status==='conflict'&&!value.held;
    const decision=await this.dialog.showMessageBox(window,{type:retry?'warning':'info',title:'GO ERP — مراجعة المزامنة',
      message:first?(value.held?'بيانات الجهاز تحت التحديث؛ المراجعة متاحة وإعادة المحاولة متوقفة.':first.status==='conflict'?'أول عملية تحتاج مراجعة قبل المزامنة':'أول عملية تنتظر تأكيد السيرفر'):'لا توجد عمليات داشبورد معلقة',
      detail,buttons:retry?['إعادة إرسال العملية نفسها','إغلاق']:['إغلاق'],defaultId:retry?1:0,cancelId:retry?1:0,noLink:true});
    if(!retry||decision.response!==0)return {counts:value.counts,retried:false};
    const confirm=await this.dialog.showMessageBox(window,{type:'warning',title:'تأكيد إعادة المحاولة',
      message:'هل عالجت سبب التوقف على السيرفر؟',detail:'ستُرسل العملية المحفوظة نفسها برقمها وبياناتها ووقتها الأصلي. السيرفر سيعيد فحص الصلاحيات والتعارضات. لن تُحذف العملية أو تُتجاهل بيانات مختلفة، وقد تتوقف مرة أخرى لنفس السبب.',
      buttons:['إعادة المحاولة','رجوع'],defaultId:1,cancelId:1,noLink:true});
    if(confirm.response!==0)return {counts:value.counts,retried:false};
    // A separate encrypted, flushed receipt precedes changing the sendable state.
    // An interrupted/uncertain intent is retained, never automatically replayed.
    const id=this.randomUUID(),audit={format:1,id,deviceId:source.deviceId,actorId:source.actorId,snapshotId:source.snapshotId,
      database:source.database||'fasakhansta_dashboard',sourceRevision:source.sourceRevision||null,commandId:first.command_id,
      reviewToken:first.review_token,sequence:first.sequence,route:first.route_name,previousError:first.last_error,
      previousAttempts:first.attempts,requestedAt:this.now(),state:'requested'};
    if(!uuid(id))throw Error('تعذر إنشاء سجل المراجعة.');
    const name='outbox-review/'+id;await this.runtime.metadata.write(name,audit);
    const current=await this.runtime.connection();
    if(JSON.stringify(current)!==JSON.stringify(source))throw Error('نسخة بيانات الجهاز تغيّرت؛ افتح المراجعة من جديد.');
    let result;
    try{result=await this.runtime.control({action:'outbox-retry',command_id:first.command_id,review_token:first.review_token});}
    catch(error){await this.runtime.metadata.write(name,{...audit,state:'unconfirmed',finishedAt:this.now(),error:String(error.message).slice(0,1000)});throw error;}
    if(result.format!==1||result.command_id!==first.command_id||result.queued!==true)throw Error('نتيجة إعادة المحاولة غير مؤكدة؛ افتح المراجعة من جديد.');
    await this.runtime.metadata.write(name,{...audit,state:'queued',finishedAt:this.now()});
    await this.synchronize();
    return {counts:result.counts,retried:true};
  }
}
module.exports=DashboardOutboxReview;
