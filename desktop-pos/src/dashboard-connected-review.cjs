'use strict';
/** Native user gesture records human review without asserting a server/provider outcome. */
class DashboardConnectedReview{
  constructor({attempts,connected,runtime,dialog}){Object.assign(this,{attempts,connected,runtime,dialog});}
  open(window){
    if(this.running)return this.running;
    this.running=this.review(window).finally(()=>{this.running=null;});return this.running;
  }
  async review(window){
    if(!(await this.runtime.isPrepared())||!await this.connected.selected())throw Error('افتح الداشبورد المتصلة كاملة لمراجعة نتائجها.');
    await this.connected.sameActor(await this.runtime.connection());
    const records=await this.attempts.reviewable();
    if(!records.length){await this.dialog.showMessageBox(window,{type:'info',title:'فسخانستا — مراجعة العمليات المتصلة',message:'لا توجد عمليات متصلة ذات رد غير مؤكد متاحة للمراجعة البشرية.'});return;}
    const choice=await this.dialog.showMessageBox(window,{type:'warning',title:'فسخانستا — مراجعة العمليات المتصلة',
      message:'اختر العملية بعد مراجعة نتيجتها على السيرفر.',detail:'كل اختيار يحتفظ بسجل الطلب الأصلي. العودة لنسخة الجهاز تتطلب تحديثًا كاملًا بعد المراجعة.',
      buttons:[...records.map(record=>record.method+' '+record.path+' — '+record.created_at),'إغلاق'],cancelId:records.length,defaultId:records.length,noLink:true});
    if(choice.response<0||choice.response>=records.length)return;
    const record=records[choice.response];
    const confirmation=await this.dialog.showMessageBox(window,{type:'warning',title:'فسخانستا — تسجيل مراجعة بشرية',message:record.method+' '+record.path,
      detail:'وقت الطلب: '+record.created_at+'\nسجّل هذه المراجعة بعد التحقق من نتيجة العملية في الداشبورد المتصلة. ستظل نسخة الجهاز بحاجة لتحديث كامل.',
      buttons:['تمت مراجعة النتيجة على السيرفر','إلغاء'],defaultId:1,cancelId:1,noLink:true});
    if(confirmation.response!==0)return;
    await this.connected.sameActor(await this.runtime.connection());
    await this.attempts.humanReview(record.id);
    await this.dialog.showMessageBox(window,{type:'info',title:'فسخانستا',message:'حُفظت المراجعة البشرية وسجل الطلب الأصلي. استخدم العودة لنسخة الجهاز بعد التحديث.'});
  }
}
module.exports=DashboardConnectedReview;
