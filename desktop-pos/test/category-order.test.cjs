'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto');
const source=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8');
function fixture(storage=new Map(),generation='00000000-0000-4000-8000-000000000001'){
  let prefilter;
  const document={body:{dataset:{dashboardLocal:'1',dashboardActor:'1:admin'}},documentElement:{},querySelectorAll:()=>[],
    querySelector:()=>({dataset:{desktopCategoryGeneration:generation}}),addEventListener:()=>{}};
  vm.runInNewContext(source,{document,window:{jQuery:{ajaxPrefilter:callback=>prefilter=callback}},location:{href:'https://dashboard.test/admin/categorys',origin:'https://dashboard.test'},
    URL,URLSearchParams,crypto,HTMLFormElement:class{},MutationObserver:class{observe(){}},
    sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)}});
  return {request(order,{url='/admin/post-sortable',type='POST'}={}){
    const values=new URLSearchParams();order.forEach((row,index)=>{for(const [key,value]of Object.entries(row))values.append(`order[${index}][${key}]`,value);});
    const headers={};let done,aborted=false;
    prefilter({url,type,data:values.toString()},null,{setRequestHeader:(key,value)=>headers[key]=value,done:callback=>done=callback,abort:()=>aborted=true});
    return {headers,aborted,complete:value=>done?.(value)};
  }};
}
test('the original drag AJAX retains its UUID across lost replies, reordered entries and reload, then releases it only on success',()=>{
  const storage=new Map(),order=[{id:'912345678901234567',position:'2'},{id:'11',position:'1'}];
  const first=fixture(storage).request(order),id=first.headers['X-Fasakhansta-Command'];assert.match(id,/^[a-f0-9-]{36}$/i);
  assert.equal(fixture(storage).request([...order].reverse()).headers['X-Fasakhansta-Command'],id);
  first.complete({status:'failed'});assert.equal(fixture(storage).request(order).headers['X-Fasakhansta-Command'],id);
  first.complete({status:'success'});assert.notEqual(fixture(storage).request(order).headers['X-Fasakhansta-Command'],id);
});
test('new desired positions and refreshed datasets cannot reuse an archived category order UUID',()=>{
  const storage=new Map(),order=[{id:'12',position:'1'},{id:'13',position:'2'}],id=fixture(storage).request(order).headers['X-Fasakhansta-Command'];
  assert.notEqual(fixture(storage).request([{id:'12',position:'2'},{id:'13',position:'1'}]).headers['X-Fasakhansta-Command'],id);
  assert.notEqual(fixture(storage,crypto.randomUUID()).request(order).headers['X-Fasakhansta-Command'],id);
  assert.equal(fixture(storage,'').request(order).aborted,true);
});
test('foreign drag targets and malformed order selections never receive command headers',()=>{
  const f=fixture(),order=[{id:'12',position:'1'}];
  assert.deepEqual(f.request(order,{url:'https://foreign.test/admin/post-sortable'}).headers,{});
  assert.deepEqual(f.request(order,{type:'PUT'}).headers,{});
  assert.equal(f.request([]).aborted,true);assert.equal(f.request([{id:'12',position:'-1'}]).aborted,true);
});
