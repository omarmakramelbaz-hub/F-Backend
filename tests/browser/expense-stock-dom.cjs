// Actual expense-page logic in a DOM, with only HTTP replies replaced by fixtures.
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const fixtureSource=fs.readFileSync(path.join(__dirname,'expense-stock.cjs'),'utf8').split('(async()=>{')[0].replace("const {chromium}=require('playwright');",'');
const fixtureContext={require,__dirname,exports:{}};
vm.runInNewContext(fixtureSource+'\nexports.fixture={template,data,labels,shell};',fixtureContext);
const {template,data,labels}=fixtureContext.exports.fixture;
const boot={actor_id:1,today:'2026-10-07',initial:data(),branches:[{value:'f:100',name:'فرع المحلة'}],permissions:{can_create:true,can_approve:true,can_manage_categories:true},urls:{data:'/data',save:'/save',recover:'/recover',export:'/export',report:'/report',categorySave:'/category-save'}};
const dom=new JSDOM(`<meta name="csrf-token" content="test">${template(true,true)}<script type="application/json" id="branch-expenses-bootstrap">${JSON.stringify(boot)}</script><script type="application/json" id="branch-expenses-labels">${JSON.stringify(labels)}</script>`,{url:'https://expense-test.invalid/',runScripts:'outside-only'});
const w=dom.window,doc=w.document,posted=[];let checks=0;
function check(value,message){assert(value,message);checks++;}
w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};
w.confirm=()=>true;
w.fetch=async(url,options={})=>{
 if(new URL(url).pathname==='/save'){
  const payload=Object.fromEntries(options.body.entries());posted.push(payload);
  return {ok:true,status:200,json:async()=>({success:true,expense:{branch:'f:100',status:'pending',stock_quantity_kg:payload.quantity_kg}})};
 }
 return {ok:true,status:200,json:async()=>data()};
};
w.eval(fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/branch-expenses.js'),'utf8'));
const form=doc.querySelector('[data-expense-form]'),category=form.elements.category,quantity=form.elements.quantity_kg;
const setCategory=key=>{category.value=key;category.dispatchEvent(new w.Event('change',{bubbles:true}));};
const submit=()=>form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
const tick=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 try{
  check(quantity.disabled&&!quantity.required&&quantity.closest('[data-expense-quantity]').hidden,'unlinked selection hides and disables kg');
  setCategory('purchase_oil');
  check(!quantity.disabled&&quantity.required&&!quantity.closest('[data-expense-quantity]').hidden,'linked selection requires kg');
  form.elements.description.value='زيت للمحلة';form.elements.amount.value='125.50';
  submit();check(posted.length===0,'blank kg prevents save');
  for(const invalid of ['0','-1','2.1234','1000001']){quantity.value=invalid;submit();check(posted.length===0,'invalid kg prevents save: '+invalid);}
  quantity.value='٢٫٥٠٠';submit();await tick();await tick();
  check(posted.length===1&&posted[0].quantity_kg==='2.500','Arabic kg normalizes in actual request');
  check(posted[0].branch==='f:100'&&posted[0].category==='purchase_oil','request keeps selected branch and material');
  check(quantity.value===''&&!quantity.required&&quantity.disabled,'success clears and hides stale kg');
  check([...doc.querySelectorAll('[data-expense-protected]')].every(button=>button.disabled),'stock category deletion remains disabled after lock cycle');
  setCategory('purchases');form.elements.description.value='مشتريات عامة';form.elements.amount.value='10';quantity.value='99';submit();await tick();await tick();
  check(posted.length===2&&!('quantity_kg' in posted[1]),'general expense omits stale kilograms');
  console.log(checks+' DOM checks passed against actual expense page script');
 } finally {w.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
