'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto');
const source=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8');
const Attempts=require('../src/dashboard-remote-attempts.cjs');
const RemoteState=require('../src/dashboard-remote-state.cjs');
async function formFixture(url='https://dashboard.test/admin/go-stores/60',method='POST',dataset={dashboardLocal:'1'},override=null){
  class Form{
    constructor(){this.action=url;this.method=method;this.fields=override?[{name:'_method',value:override}]:[];}
    hasAttribute(){return false;}
    querySelector(selector){return this.fields.find(field=>selector==='[name="'+field.name+'"]')||null;}
    append(field){this.fields.push(field);}
  }
  const form=new Form(),events={},document={body:{dataset},documentElement:{},querySelectorAll:selector=>selector==='form'?[form]:[],createElement:()=>({}),addEventListener:(name,callback)=>events[name]=callback};
  await vm.runInNewContext(source,{document,window:{FasakhanstaDesktop:{status:async()=>({prepared:true})}},location:{href:'https://dashboard.test/admin/go-stores/60',origin:'https://dashboard.test'},URL,crypto,HTMLFormElement:Form,MutationObserver:class{observe(){}}});
  return {form,submit:()=>events.submit({target:form}),command:()=>form.fields.find(field=>field.name==='_desktop_command')?.value};
}
test('original restaurant profile method overrides and status forms preserve one UUID across local and native lost replies',async()=>{
  const attempts=new Attempts({});
  for(const dataset of [{dashboardLocal:'1'},{dashboardLocal:'0',dashboardRemoteAttempts:'1'}]){
    for(const [path,override] of [['/admin/resturants/100','PUT'],['/admin/resturants/100','PATCH'],['/admin/updateStatus/100',null]]){
      const f=await formFixture('https://dashboard.test'+path,'POST',dataset,override),command=f.command();assert.match(command,/^[a-f0-9-]{36}$/i);f.submit();f.submit();assert.equal(f.command(),command);
      assert.equal(f.form.fields.filter(x=>x.name==='_desktop_command').length,1);assert.equal(attempts.supported({url:'https://dashboard.test'+path,method:'POST'}),true);
    }
  }
  for(const path of ['/admin/resturants','/admin/resturants/0','/admin/resturants/01','/admin/resturants/100/edit','/admin/resturants/100/','/admin/updateStatus/0','/admin/updateStatus/100/extra']){
    assert.equal((await formFixture('https://dashboard.test'+path,'POST',undefined,'PUT')).command(),undefined);
    assert.equal(attempts.supported({url:'https://dashboard.test'+path,method:'POST'}),false);
  }
  for(const method of ['GET','HEAD','DELETE'])assert.equal(attempts.supported({url:'https://dashboard.test/admin/resturants/100',method}),false);
  assert.equal((await formFixture('https://foreign.test/admin/resturants/100','POST',undefined,'PUT')).command(),undefined);
  assert.equal((await formFixture('https://dashboard.test/admin/resturants/100','POST')).command(),undefined);
});
