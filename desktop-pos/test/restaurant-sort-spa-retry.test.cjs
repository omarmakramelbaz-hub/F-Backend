'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const renderer=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8');
const spa=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/dashboard-spa.js'),'utf8');
const origin='https://dashboard.example',uuid='d2020202-2020-4020-8020-202020202020';

async function originalCaptureOrdering({discardStale=true}={}){
  const listeners=[],requests=[],notices=[],visits=[],buttons=[],pending=[];
  class Form{
    constructor(){
      this.action=origin+'/admin/update_sorting_is_featured/100';this.method='post';this.target='';this.dataset={};this.isConnected=true;
      this.fields=[{name:'_token',value:'source-csrf'},{name:'page',value:''},{name:'sortby_is_featured',value:'7'}];
      this.button={tagName:'BUTTON',name:'',disabled:false,innerHTML:'<i class="fa-solid fa-floppy-disk"></i>',hasAttribute:()=>false,form:this};buttons.push(this.button);
    }
    querySelector(selector){const match=/^\[name="([^"]+)"\]$/.exec(selector);return match?this.fields.find(field=>field.name===match[1])||null:null;}
    querySelectorAll(){return [this.button];}
    append(field){this.fields.push(field);}
    hasAttribute(){return false;}
    getAttribute(name){return name==='action'?this.action:name==='method'?this.method:null;}
  }
  const form=new Form(),document={
    body:{dataset:{dashboardLocal:'1'}},documentElement:{},
    addEventListener(type,callback,options){listeners.push({type,callback,capture:options===true});},
    querySelectorAll(selector){return selector==='form'?[form]:[];},
    querySelector(selector){return selector==='[data-dashboard-page]'?{}:selector==='meta[name="csrf-token"]'?{content:'source-csrf'}:null;},
    createElement(tag){assert.equal(tag,'input');return {};}
  };
  const native={
    add:document.addEventListener,submit(){throw Error('Natural sorting must not navigate away from the preserved original form.');},
    fetch(url,options){
      const sent=options.body.fields.map(field=>({...field}));requests.push({url,method:options.method,fields:sent});
      return new Promise((resolve,reject)=>pending.push({resolve,reject}));
    }
  };
  const context=vm.createContext({document,window:{DashboardSPA:{ready:()=>true}},HTMLFormElement:Form,
    URL,URLSearchParams,location:{href:origin+'/admin/resturants',origin},
    FormData:class{constructor(actual){this.fields=actual.fields.map(field=>({...field}));}append(name,value){this.fields.push({name,value});}},
    File:class{},MutationObserver:class{observe(){}},crypto:{randomUUID:()=>uuid},sessionStorage:{getItem:()=>null,setItem(){}},
    native,started:true,submitting:false,navigation:null,sequence:0,pendingHistory:null,
    eligible:value=>new URL(value,origin).origin===origin,authRedirect:()=>false,
    busy(){},message:value=>value,notify:(value)=>notices.push(value),visit:async value=>{visits.push(value);},console:{error(){}},Promise,Array,Object
  });
  // Execute the original production renderer before the original SPA capture registration,
  // matching defer-script registration followed by DashboardSPA's DOMContentLoaded start.
  const prepared=discardStale?renderer:renderer.replace('delete form.dashboardSubmitButtons;','');
  await vm.runInContext(prepared,context);
  const start=spa.indexOf('    function submitButtons(form) {'),end=spa.indexOf('    function start() {',start);
  assert.ok(start>=0&&end>start,'Exercise actual original SPA submission/button functions and prototype path.');
  vm.runInContext(spa.slice(start,end),context);
  const capture=spa.split('\n').find(line=>line.includes("native.add.call(document, 'submit', function (event) { if (event.target instanceof HTMLFormElement)"));
  assert.ok(capture,'Exercise the actual later original SPA button-snapshot capture listener.');vm.runInContext(capture,context);
  const click=async value=>{
    if(form.button.disabled)return false;
    form.querySelector('[name="sortby_is_featured"]').value=value;
    const event={target:form,submitter:form.button,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}};
    for(const listener of listeners.filter(item=>item.type==='submit'&&item.capture))listener.callback(event);
    assert.equal(event.defaultPrevented,true,'The captured original sort must prevent native navigation.');
    // The original footer mutates the button after capture; restore uses the real SPA snapshot.
    form.button.innerHTML='<i class="fa fa-spinner fa-spin"></i>loading';
    return true;
  };
  const settle=async result=>{
    const request=pending.shift();assert.ok(request);
    if(result==='lost')request.reject(Error('The committed original response was lost.'));
    else request.resolve({ok:result!==409,url:origin+'/admin/resturants',headers:{get:()=>result===409?'application/json':'text/html'},
      json:async()=>({message:'Changed UUID payload',error:'conflict'})});
    await new Promise(resolve=>setImmediate(resolve));
  };
  return {form,requests,notices,visits,click,settle};
}

test('real SPA capture ordering restores the original sorting button after lost7 and changed8/409 before same-UUID retry7',async()=>{
  const env=await originalCaptureOrdering();
  for(const [value,result] of [['7','lost'],['8',409],['7',302]]){
    assert.equal(await env.click(value),true);assert.equal(env.form.button.disabled,true);
    await env.settle(result);assert.equal(env.form.button.disabled,false);
    assert.equal(env.form.button.innerHTML,'<i class="fa-solid fa-floppy-disk"></i>');assert.equal(env.form.isConnected,true);
  }
  assert.equal(env.requests.length,3);assert.deepEqual(env.requests.map(request=>request.fields.find(field=>field.name==='sortby_is_featured').value),['7','8','7']);
  for(const request of env.requests){
    assert.equal(request.url,origin+'/admin/update_sorting_is_featured/100');assert.equal(request.method,'POST');
    assert.deepEqual(request.fields.filter(field=>field.name==='_desktop_command').map(field=>field.value),[uuid]);
    assert.deepEqual(request.fields.filter(field=>['_token','page'].includes(field.name)),[{name:'_token',value:'source-csrf'},{name:'page',value:''}]);
  }
  assert.equal(env.visits.length,1,'Only an accepted original response reaches SPA navigation.');
});

test('the prior cached snapshot reproduces the real second-retry disabled-button failure',async()=>{
  const env=await originalCaptureOrdering({discardStale:false});
  assert.equal(await env.click('7'),true);await env.settle('lost');assert.equal(env.form.button.disabled,false);
  assert.equal(await env.click('8'),true);await env.settle(409);assert.equal(env.form.button.disabled,true);
  assert.equal(await env.click('7'),false);assert.equal(env.requests.length,2);assert.equal(env.visits.length,0);
});
