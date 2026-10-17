'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto');
const source=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/dashboard-inbox.js'),'utf8');
test('the original desktop notification menu keeps its operation UUID across a lost reply and page reload',async()=>{
  const storage=new Map(),requests=[];
  function page(){
    const events={},error={hidden:true},items=[{dataset:{notificationId:'first-note'},remove(){this.removed=true;}}];
    const shell={dataset:{notificationsUrl:'/notes',notificationsReadUrl:'/notes/read',inboxReadFailed:'failed'},
      querySelector:selector=>selector==='[data-inbox-read-error]'?error:null,
      querySelectorAll:selector=>selector==='[data-notification-id]'?items:[]};
    const document={body:{dataset:{dashboardLocal:'1',dashboardActor:'10:vendor'}},hidden:false,documentElement:{lang:'ar'},
      querySelector:selector=>selector==='[data-dashboard-inbox]'?shell:selector==='meta[name="csrf-token"]'?{content:'csrf'}:null,
      querySelectorAll:()=>[],addEventListener:(name,fn)=>{events[name]=fn;},dispatchEvent:()=>{}};
    const window={addEventListener:()=>{}};
    vm.runInNewContext(source,{window,document,crypto,sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},
      fetch:async(url,options)=>{
        if(options.method==='POST'){
          requests.push(JSON.parse(options.body));if(requests.length===1)throw Error('lost reply');
        }
        return {ok:true,headers:{get:()=> 'application/json'},json:async()=>({success:true,count:0,marked:1,notifications:[]})};
      },CustomEvent:class{},setInterval:()=>1,clearInterval:()=>{},setTimeout:()=>1,clearTimeout:()=>{}});
    return {error,items,click:()=>events.click({target:{closest:()=>({getAttribute:()=> 'false'})}})};
  }
  const first=page();first.click();await new Promise(setImmediate);
  assert.equal(first.error.hidden,false);assert.equal(storage.size,1);
  const second=page();second.click();await new Promise(setImmediate);
  assert.match(requests[0].idempotency_key,/^[a-f0-9-]{36}$/i);
  assert.deepEqual(requests[1],requests[0]);assert.equal(storage.size,0);assert.equal(second.items[0].removed,true);
});
