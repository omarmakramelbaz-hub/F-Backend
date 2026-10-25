'use strict';

// A profile is selected for the NEXT process only. The running database, outbox
// and browser partition never change identity underneath accepted work.
class AccountSwitch {
  constructor({profiles,currentId,available,hold,choose,pause,resume,flush,restart,serialize=work=>work()}) {
    Object.assign(this,{profiles,currentId,available,hold,choose,pause,resume,flush,restart,serialize});
    this.running=null;this.controller=null;this.committed=false;
  }
  cancel(){this.controller?.abort();}
  request(){
    if(this.running)return this.running;
    const controller=new AbortController();this.controller=controller;
    const busy=()=>Error('أكمل حفظ ومزامنة العمليات وأغلق النوافذ المفتوحة قبل تبديل الحساب.');
    const task=Promise.resolve().then(()=>this.serialize(()=>this.hold(async()=>{
      if(controller.signal.aborted)return {cancelled:true};
      if(!(await this.available()))throw busy();
      await this.pause();
      try{
        const choice=await this.choose(await this.profiles.list(),controller.signal);
        if(controller.signal.aborted||!choice)return {cancelled:true};
        if(!choice||typeof choice!=='object'||!['add','select'].includes(choice.action))throw Error('اختيار الحساب غير صالح.');
        if(choice.action==='select'&&choice.id===this.currentId())return {unchanged:true};
        if(!(await this.available()))throw busy();
        await this.flush();
        if(controller.signal.aborted)return {cancelled:true};
        if(!(await this.available()))throw busy();
        const target=choice.action==='add'?await this.profiles.create():{id:choice.id};
        if(controller.signal.aborted)return {cancelled:true};
        const previous=this.currentId();
        await this.profiles.select(target.id);
        try{
          if(controller.signal.aborted){await this.profiles.select(previous);return {cancelled:true};}
          await this.restart();this.committed=true;
          return {restarting:true};
        }catch(error){await this.profiles.select(previous);throw error;}
      }finally{if(!this.committed)await this.resume();}
    })));
    this.running=task.then(result=>{if(result?.deferred)throw busy();return result;})
      .finally(()=>{this.running=null;if(this.controller===controller)this.controller=null;});
    return this.running;
  }
}
module.exports=AccountSwitch;
