// Actual expense-page behavior: branch labels, editable stock names and retained kg rules.
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync(path.join(__dirname,'expense-stock.cjs'),'utf8').split('(async()=>{')[0].replace("const {chromium}=require('playwright');",'');
const context={require,__dirname,exports:{}};vm.runInNewContext(source+'\nexports.fixture={template,data,labels};',context);
const {template,data,labels}=context.exports.fixture;
const branches=[{value:'f:100',name:'فرع المحلة'},{value:'f:101',name:'فرع المنصورة'}];let names={'f:100':'زيت','f:101':'زيت'},revisions={'f:100':0,'f:101':0},checks=0;
const posted=[],changes=[];
function dataset(scope='all'){const d=data();d.filters.branch=scope;d.category_scope=scope;d.branch_categories=Object.fromEntries(branches.map(branch=>{const c=data(),name=names[branch.value],revision=revisions[branch.value];c.categories.purchase_oil=name;c.active_categories.purchase_oil=name;c.category_items=[{key:'purchase_oil',name,protected:true,revision}];return[branch.value,c];}));const selected=d.branch_categories[scope==='all'?'f:100':scope];Object.assign(d,{categories:selected.categories,active_categories:selected.active_categories,category_items:selected.category_items,stock_categories:selected.stock_categories});d.items=branches.map((b,i)=>({id:i+1,revision:1,number:'EXP-00'+i,branch:b.value,branch_name:b.name,occurred_on:'2026-10-07',category:'purchase_oil',category_base_name:names[b.value],category_name:names[b.value]+' — '+b.name,description:'فاتورة مشتريات',amount:'10.00',payment_method:'cash',actor_name:'مالك',status:'pending'}));return d;}
const boot={actor_id:1,today:'2026-10-07',initial:dataset(),branches,permissions:{can_create:true,can_approve:true,can_manage_categories:true},urls:{data:'/data',save:'/save',recover:'/recover',export:'/export',report:'/report',categorySave:'/category-save'}};
const dom=new JSDOM(`<meta name="csrf-token" content="test">${template(true,true)}<script type="application/json" id="branch-expenses-bootstrap">${JSON.stringify(boot)}</script><script type="application/json" id="branch-expenses-labels">${JSON.stringify(labels)}</script>`,{url:'https://expense-test.invalid/',runScripts:'outside-only'});
const w=dom.window,doc=w.document,form=doc.querySelector('[data-expense-form]'),mainBranch=doc.querySelector('[data-expense-branch]');
const options='<option value="f:100">فرع المحلة</option><option value="f:101">فرع المنصورة</option>';
mainBranch.innerHTML='<option value="all">جميع الفروع</option>'+options;mainBranch.value='all';form.elements.branch.innerHTML=options;
w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};w.confirm=()=>true;
w.fetch=async(address,options={})=>{
 const route=new URL(address).pathname;
 if(route==='/category-save'){const value=JSON.parse(options.body);changes.push(value);names[value.branch]=value.name;revisions[value.branch]++;return{ok:true,status:200,json:async()=>({...dataset(value.branch),category:{key:'purchase_oil',name:value.name,revision:revisions[value.branch]}})};}
 if(route==='/save'){const value=Object.fromEntries(options.body.entries());posted.push(value);return{ok:true,status:200,json:async()=>({success:true,expense:{branch:value.branch,status:'pending',stock_quantity_kg:value.quantity_kg}})};}
 return{ok:true,status:200,json:async()=>dataset(new URL(address).searchParams.get('branch')||mainBranch.value)};
};
w.eval(fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/branch-expenses.js'),'utf8'));
function check(value,message){assert(value,message);checks++;}const tick=()=>new Promise(resolve=>setImmediate(resolve));
const change=(field,value)=>{field.value=value;field.dispatchEvent(new w.Event('change',{bubbles:true}));};
(async()=>{try{
 const rows=[...doc.querySelectorAll('[data-expense-rows] tr')];
 check(rows[0].children[3].textContent==='زيت — فرع المحلة','Mahalla expense shows its branch once beside category');
 check(rows[1].children[3].textContent==='زيت — فرع المنصورة','Mansoura expense is distinct in all branches');
 check(form.elements.category.querySelector('[value="purchase_oil"]').textContent==='زيت — فرع المحلة','form label follows receiving branch');
 check(doc.querySelector('[data-expense-filters] [name="category"] [value="purchase_oil"]').textContent==='زيت — جميع الفروع','all-branch filter keeps shared scope');
 change(form.elements.category,'purchase_oil');form.elements.quantity_kg.value='2.500';change(form.elements.branch,'f:101');
 check(form.elements.category.querySelector('[value="purchase_oil"]').textContent==='زيت — فرع المنصورة','form branch change relabels options');
 check(form.elements.quantity_kg.value==='2.500'&&form.elements.quantity_kg.required,'branch label refresh preserves draft kg');
 doc.querySelector('[data-expense-categories-open]').click();check(!doc.querySelector('[data-expense-categories-dialog]').open,'all-branch view cannot rename shared categories');change(mainBranch,'f:101');await tick();await tick();change(form.elements.category,'purchase_oil');form.elements.quantity_kg.value='2.500';doc.querySelector('[data-expense-categories-open]').click();let row=doc.querySelector('[data-category-key="purchase_oil"]');
 check(row.querySelector('strong').textContent==='زيت — فرع المنصورة','management label shows selected branch scope');
 const edit=row.querySelector('button');check(!edit.disabled,'stock category edit is enabled');check(row.querySelector('.ex-category-delete').disabled,'stock identity remains protected from deletion');edit.click();
 check(doc.querySelector('[data-expense-category-name]').value==='زيت','edit field contains the shared name without branch suffix');
 doc.querySelector('[data-expense-category-name]').value='زيت عباد';doc.querySelector('[data-expense-category-form]').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await tick();await tick();
 check(changes.length===1&&changes[0].key==='purchase_oil'&&changes[0].expected_revision===0&&changes[0].branch==='f:101','rename posts selected branch, stable key and revision');
 check(form.elements.category.querySelector('[value="purchase_oil"]').textContent==='زيت عباد — فرع المنصورة','renamed label retains form branch');
 check(form.elements.quantity_kg.required&&form.elements.quantity_kg.value==='2.500','rename preserves inventory requirement and draft quantity');
 check([...doc.querySelectorAll('[data-expense-rows] tr')][0].children[3].textContent==='زيت — فرع المحلة','renaming another branch preserves the Mahalla expense name');
 form.elements.description.value='مشتريات فعلية';form.elements.amount.value='10';form.elements.quantity_kg.value='';form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));check(posted.length===0,'renaming cannot bypass required kg');
 form.elements.quantity_kg.value='٢٫٥٠٠';form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await tick();await tick();
 check(posted.length===1&&posted[0].category==='purchase_oil'&&posted[0].branch==='f:101'&&posted[0].quantity_kg==='2.500','renamed category still submits original inventory identity and correct branch');
 console.log(checks+' branch/edit DOM checks passed');
}finally{w.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
