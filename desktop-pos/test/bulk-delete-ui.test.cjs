'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');

function fixture(){
  const source=fs.readFileSync(path.join(__dirname,'../../resources/views/admin/layouts/footer.blade.php'),'utf8');
  const match=source.match(/\$\('\.delete_all'\)\.on\('click', function\(e\) \{([\s\S]*?)\n        \}\);/);
  assert.ok(match,'The test must execute the original shared bulk button handler.');
  // Render only the existing desktop-local Blade conditional; execute the actual handler body.
  const handlerBody=match[1].replace(/@unless\(config\('desktop_dashboard\.local'\)\)[\s\S]*?@endunless/,'');
  const rows=['12','3','7','8','9'].map(id=>({id,checked:false,removed:false})),requests=[],alerts=[];
  let reloads=0;
  function collection(items){return {
    each(callback){items.forEach((item,index)=>callback.call(item,index,item));return this;},
    filter(callback){return collection(items.filter((item,index)=>callback.call(item,index,item)));}
  };}
  function $(value){
    if(value==='.sub_chk:checked')return collection(rows.filter(row=>row.checked&&!row.removed));
    if(value==='.sub_chk')return collection(rows.filter(row=>!row.removed));
    if(value==='meta[name="csrf-token"]')return {attr:()=> 'current-csrf'};
    if(typeof value==='object')return {
      attr:name=>name==='data-id'?value.id:undefined,
      data:name=>name==='url'?'/admin/rolesDeleteAll':undefined,
      parents:selector=>{assert.equal(selector,'tr');return {remove:()=>{value.removed=true;}};}
    };
    throw Error('Unexpected original selector: '+value);
  }
  $.ajax=options=>{requests.push(options);};
  const handler=vm.runInNewContext('(function(e){'+handlerBody+'})',{
    $,Object,confirm:message=>{assert.equal(message,'Are you sure you want to delete this row?');return true;},
    alert:message=>alerts.push(message),window:{DashboardSPA:{reload:()=>reloads++}}
  });
  return {rows,requests,alerts,get reloads(){return reloads;},
    select(ids){rows.forEach(row=>{row.checked=ids.includes(row.id);});},
    click(){handler.call({},{});},removed(){return rows.filter(row=>row.removed).map(row=>row.id);}};
}
test('an original bulk reply removes exactly its sent rows after the checkbox selection changes in flight',()=>{
  const f=fixture();f.select(['12','3']);f.click();
  assert.equal(f.requests.length,1);assert.equal(f.requests[0].data,'ids=12,3');
  assert.equal(f.requests[0].headers['X-CSRF-TOKEN'],'current-csrf');assert.equal(f.requests[0].type,'DELETE');
  f.select(['7','8']);f.requests[0].success({success:'Original deletion success'});
  assert.deepEqual(f.removed(),['12','3']);assert.deepEqual(f.rows.filter(row=>row.checked&&!row.removed).map(row=>row.id),['7','8']);
  assert.deepEqual(f.alerts,['Original deletion success']);assert.equal(f.reloads,1);
});
test('overlapping original bulk replies retain independent selections and a failed reply preserves every row',()=>{
  const f=fixture();f.select(['12','3']);f.click();f.select(['7','8']);f.click();
  f.requests[0].error({responseText:'Lost response'});assert.deepEqual(f.removed(),[]);assert.equal(f.reloads,0);
  f.select(['9']);f.requests[1].success({success:'Second selection success'});
  assert.deepEqual(f.removed(),['7','8']);
  f.requests[0].success({success:'Delayed first selection success'});
  assert.deepEqual(f.removed(),['12','3','7','8']);assert.equal(f.rows.find(row=>row.id==='9').removed,false);
  assert.equal(f.rows.find(row=>row.id==='9').checked,true);assert.equal(f.reloads,2);
});
