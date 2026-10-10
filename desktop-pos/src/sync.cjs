class Sync {
  constructor(store,request) {this.store=store;this.request=request;this.busy=false;this.stopped=false;this.lastSnapshot=0;this.online=false;this.error='';}
  stop() {this.stopped=true;}
  run(options={}) {
    if(this.running)return this.running;
    if(this.stopped)return Promise.resolve();
    this.busy=true;
    this.running=this.flush(options).finally(()=>{this.busy=false;this.running=null;});
    return this.running;
  }
  async flush({bootstrapOnly=false}={}) {
    try {
      for(const row of bootstrapOnly?[]:this.store.pending()) {
        if(this.stopped)return;
        try{const result=await this.request('POST','sync',{event:row.event});this.store.acknowledge(row.id,result);}
        catch(e){if(this.stopped)return;this.store.fail(row.id,e.message);throw e;}
      }
      if(this.stopped)return;
      await this.request('GET','health');if(this.stopped)return;this.online=true;this.error='';this.store.set('authorization_blocked',false);
      if(!this.store.snapshot()||Date.now()-this.lastSnapshot>300000) {const snapshot=await this.request('GET','snapshot');if(this.stopped)return;this.store.saveSnapshot(snapshot);this.lastSnapshot=Date.now();}
    }catch(e){if(this.stopped)return;if([401,403,404].includes(e.status))this.store.set('authorization_blocked',true);this.online=Boolean(e.status&&e.status<500&&![401,403,404].includes(e.status));this.error=e.message;}
  }
}
module.exports=Sync;
