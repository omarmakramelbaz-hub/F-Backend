'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto');
const source=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8');
function history(storage,ids,{actor='1:admin',origin='https://dashboard.test',single=false}={}){
  class Form{
    constructor(){this.action=origin+(single?'/admin/read/'+ids[0]:'/admin/read/all/notification');this.method='post';this.fields=[];this.dataset={notificationIds:JSON.stringify(ids)};}
    hasAttribute(name){return name==='data-desktop-notification-read';}
    querySelector(selector){if(selector==='[name="_method"]')return single?{value:'PUT'}:null;return this.fields.find(field=>selector==='[name="'+field.name+'"]')||null;}
    append(field){this.fields.push(field);}
  }
  const form=new Form(),events={},document={body:{dataset:{dashboardLocal:'1',dashboardActor:actor}},documentElement:{},
    querySelectorAll:()=>[form],createElement:()=>({}),addEventListener:(event,callback)=>events[event]=callback};
  vm.runInNewContext(source,{document,window:{},location:{href:'https://dashboard.test/admin/notifications',origin:'https://dashboard.test'},
    URL,crypto,HTMLFormElement:Form,MutationObserver:class{observe(){}},
    sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value)}});
  return {form,submit:()=>events.submit({target:form}),value:name=>form.fields.find(field=>field.name===name)?.value};
}
test('original notification history keeps its operation and immutable sorted snapshot after a lost response/reload',()=>{
  const storage=new Map(),ids=[crypto.randomUUID(),crypto.randomUUID()],first=history(storage,ids);
  const command=first.value('_desktop_command');assert.match(command,/^[a-f0-9-]{36}$/i);
  first.submit();assert.equal(first.form.fields.length,2);
  const reload=history(storage,[...ids].reverse());assert.equal(reload.value('_desktop_command'),command);
  assert.equal(reload.value('desktop_notification_ids'),first.value('desktop_notification_ids'));
  assert.notEqual(history(storage,[...ids,crypto.randomUUID()]).value('_desktop_command'),command);
  assert.notEqual(history(storage,ids,{actor:'10:vendor'}).value('_desktop_command'),command);
});
test('single notification forms persist their UUID and unreviewed/foreign history targets receive no credentials',()=>{
  const storage=new Map(),ids=[crypto.randomUUID()];
  assert.equal(history(storage,ids,{single:true}).value('_desktop_command'),history(storage,ids,{single:true}).value('_desktop_command'));
  assert.equal(history(storage,ids,{origin:'https://foreign.test'}).form.fields.length,0);
  assert.equal(history(storage,['malformed']).form.fields.length,0);
});
