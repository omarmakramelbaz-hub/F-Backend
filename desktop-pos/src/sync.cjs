class Sync {
  constructor(store,request) {this.store=store;this.request=request;this.busy=false;this.lastSnapshot=0;this.online=false;this.error='';}
  async run() {
    if(this.busy)return;this.busy=true;
    try {
      for(const row of this.store.pending()) {
        try{const result=await this.request('POST','sync',{event:row.event});this.store.acknowledge(row.id,result);}
        catch(e){this.store.fail(row.id,e.message);throw e;}
      }
      await this.request('GET','health');this.online=true;this.error='';this.store.set('authorization_blocked',false);
      if(!this.store.snapshot()||Date.now()-this.lastSnapshot>300000) {this.store.saveSnapshot(await this.request('GET','snapshot'));this.lastSnapshot=Date.now();}
    }catch(e){if([401,403,404].includes(e.status))this.store.set('authorization_blocked',true);this.online=Boolean(e.status&&e.status<500&&![401,403,404].includes(e.status));this.error=e.message;}
    finally{this.busy=false;}
  }
}
module.exports=Sync;
