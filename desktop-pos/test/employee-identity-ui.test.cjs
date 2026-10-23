'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/branch-operations.js'),'utf8');

// Execute the production functions, including their submit and change listeners.
// This fixture replaces browser primitives, not the consent/upload decisions.
function declaration(name){
  let start=source.indexOf('function '+name+'(');assert.ok(start>=0,'Missing production function '+name);
  if(source.slice(start-6,start)==='async ')start-=6;
  let depth=0,quote=null,escaped=false,body=source.indexOf('{',start);
  for(let index=body;index<source.length;index++){
    const c=source[index];
    if(quote){if(escaped)escaped=false;else if(c==='\\')escaped=true;else if(c===quote)quote=null;continue;}
    if(c==='"'||c==="'"){quote=c;continue;}
    if(c==='{')depth++;else if(c==='}'&&!--depth)return source.slice(start,index+1);
  }
  throw Error('Incomplete function '+name);
}
class Element{
  constructor(tag){this.tagName=tag.toUpperCase();this.children=[];this.listeners=new Map();this.dataset={};this.value='';this.disabled=false;this.hidden=false;this.textContent='';this.classList={add(){},toggle(){}};}
  appendChild(child){this.children.push(child);child.parentElement=this;return child;}
  replaceChildren(...children){this.children=[];for(const child of children)this.appendChild(child);}
  replaceWith(child){const parent=this.parentElement;if(parent){const index=parent.children.indexOf(this);parent.children.splice(index,1,child);child.parentElement=parent;}}
  addEventListener(name,fn){this.listeners.set(name,[...(this.listeners.get(name)||[]),fn]);}
  dispatch(name,event={preventDefault(){}}){for(const fn of this.listeners.get(name)||[])fn(event);}
  click(){this.dispatch('click');}
  setAttribute(name,value){this[name]=String(value);}
  querySelector(selector){return this.all().find(el=>selector==='[data-op-identity-status]'&&Object.hasOwn(el.dataset,'opIdentityStatus'))||null;}
  all(){return this.children.flatMap(child=>[child,...child.all()]);}
}
class FormDataFixture{
  constructor(form){this.entries=[];if(form)for(const field of form.all())if(field.name&&!field.disabled&&(!['checkbox','radio'].includes(field.type)||field.checked))this.append(field.name,field.value);}
  append(name,value){this.entries.push([name,value]);}
  forEach(fn){for(const [name,value] of this.entries)fn(value,name);}
}
function fixture(options={}){
  const body=new Element('div'),posted=[],requests=[],messages=[];
  const boot={module:'employees',today:'2026-10-23',branches:[{value:'f:1',name:'Fixture'}],
    regulation:{version:'2026-10-23',sha256:'a'.repeat(64),acceptance:'Fixed acceptance text',sections:[{title:'Fixed section',items:['Fixed policy text']}]}};
  const context=vm.createContext({boot,module:'employees',urls:{save:'/admin/employees/save',identity:'/admin/employees/identity',regulation:'/admin/employees/regulation'},
    data:{can_manage_employees:true},busy:false,pending:null,body,filters:{branch:{value:'f:1'},month:{value:'2026-10'}},
    document:{createElement:tag=>new Element(tag),querySelector:()=>({content:'fixture-csrf'})},FormData:FormDataFixture,
    employeeScheduleFields:()=>()=>{},open:()=>true,close(){},fixedDisabled:(field,value)=>{field.disabled=value;return field;},
    write:(url,payload,done)=>posted.push({url,payload,done}),lock(){},AbortController,setTimeout,clearTimeout,
    FileReader:class{readAsDataURL(){this.result='data:image/png;base64,c3ludGhldGlj';this.onload();}},url:value=>value,read:async response=>response,identityView(){},message:(text,ok)=>messages.push({text,ok}),refresh(){},
    fetch:async(url,request)=>{requests.push({url,request});if(options.networkError)throw Error('Offline fixture');return {identity:{front:true,back:false}};}});
  vm.runInContext(['n','btn','form','field','footer','fields','branchField','edit','regulationField','regulationDetails','identityView','identityImage','identityField','pendingIdentity','uploadIdentityFile','finishIdentity'].map(declaration).join('\n'),context);
  return {context,body,boot,posted,requests,messages,
    edit(item={}){context.edit(Object.assign({id:7,branch:'f:1',revision:4,name:'Fixture employee',job_title:'Fixture role',regulation:{current:false}},item));
      const form=body.children.at(-1),check=form.all().find(field=>field.type==='checkbox');return {form,check,submit(){form.dispatch('submit');}};},
    async upload(file,side='front'){const edit=this.edit(),input=edit.form.all().filter(field=>field.type==='file')[side==='front'?0:1];input.files=[file];input.dispatch('change');if(input.value!==''||!messages.length){edit.submit();if(posted.at(-1)?.done)await posted.at(-1).done({employee:{id:7}});}return input.parentElement;}};
}
const payloadOf=f=>JSON.parse(JSON.stringify(f.posted.at(-1).payload));

test('unchanged consent does not rewrite its first acceptance while ordinary employee edits submit',()=>{
  for(const current of [false,true]){
    const f=fixture(),edit=f.edit({regulation:{current}});assert.equal(edit.check.checked,current);edit.submit();
    const payload=payloadOf(f);assert.equal(payload.employee_id,7);assert.equal(payload.expected_revision,4);assert.equal(payload.branch,'f:1');
    for(const key of ['regulation_accepted','regulation_version','regulation_hash'])assert.equal(Object.hasOwn(payload,key),false,key);
  }
});

test('new consent submits the exact version and hash whose fixed text is displayed',()=>{
  const f=fixture(),edit=f.edit();edit.check.checked=true;edit.submit();const payload=payloadOf(f);
  assert.equal(payload.regulation_accepted,true);assert.equal(payload.regulation_version,'2026-10-23');assert.equal(payload.regulation_hash,'a'.repeat(64));
  assert.ok(edit.form.all().some(el=>el.textContent==='Fixed policy text'));
});

test('an already rendered consent never adopts a replacement boot policy before submission',()=>{
  const f=fixture(),edit=f.edit();edit.check.checked=true;
  f.boot.regulation={version:'2027-01-01',sha256:'b'.repeat(64),acceptance:'New text',sections:[]};edit.submit();const payload=payloadOf(f);
  assert.equal(payload.regulation_version,'2026-10-23');assert.equal(payload.regulation_hash,'a'.repeat(64));
});

test('clearing consent sends false without claiming acceptance of any policy version',()=>{
  const f=fixture(),edit=f.edit({regulation:{current:true}});edit.check.checked=false;edit.submit();const payload=payloadOf(f);
  assert.equal(payload.regulation_accepted,false);assert.equal(Object.hasOwn(payload,'regulation_version'),false);assert.equal(Object.hasOwn(payload,'regulation_hash'),false);
});

test('a newly accepted older regulation submits the currently displayed policy identity',()=>{
  const f=fixture(),edit=f.edit({regulation:{accepted:true,current:false,version:'2020-01-01'}});edit.check.checked=true;edit.submit();
  const payload=payloadOf(f);assert.equal(payload.regulation_version,'2026-10-23');assert.equal(payload.regulation_hash,'a'.repeat(64));
});

test('busy or unresolved employee saves do not submit a second operation',()=>{
  for(const property of ['busy','pending']){const f=fixture(),edit=f.edit();edit.check.checked=true;f.context[property]=property==='busy'?true:{};edit.submit();assert.equal(f.posted.length,0);}
});

test('ID upload rejects unsupported media and oversized images before any request',async()=>{
  for(const file of [{type:'image/svg+xml',size:15},{type:'application/pdf',size:15},{type:'image/jpeg',size:5*1024*1024+1}]){
    const f=fixture(),cell=await f.upload(file);assert.equal(f.requests.length,0);assert.equal(f.context.busy,false);assert.ok(f.messages.at(-1).text);
  }
});

test('an allowed ID upload sends one scoped multipart request with a CSRF token',async()=>{
  const f=fixture(),file={type:'image/png',size:5*1024*1024};await f.upload(file,'back');assert.equal(f.requests.length,1);
  const {url,request}=f.requests[0];assert.equal(url,'/admin/employees/identity');assert.equal(request.method,'POST');assert.equal(request.credentials,'same-origin');assert.equal(request.headers['X-CSRF-TOKEN'],'fixture-csrf');
  assert.deepEqual(request.body.entries,[['branch','f:1'],['employee_id','7'],['side','back'],['photo',file]]);assert.equal(f.context.busy,false);
});

test('an uncertain photo upload displays the failure and never retries automatically',async()=>{
  const f=fixture({networkError:true}),cell=await f.upload({type:'image/jpeg',size:10});await Promise.resolve();
  assert.equal(f.requests.length,1);assert.equal(f.context.busy,false);assert.match(f.messages.at(-1).text,/Offline fixture/);assert.match(f.messages.at(-1).text,/اتحفظت بيانات الموظف/);
});

test('branch readers and the offline employee page offer no upload control',()=>{
  for(const online of [false,true]){
    const f=fixture();if(online)f.context.data.can_manage_employees=false;else delete f.context.urls.identity;
    f.context.identityView({id:7,branch:'f:1',name:'Fixture employee',identity:{front:false,back:false}});
    assert.equal(f.body.all().filter(el=>el.type==='file').length,0);assert.equal(f.requests.length,0);
    if(!online){f.edit();assert.ok(f.body.all().some(el=>el.textContent.includes('من المتصفح')));assert.equal(f.body.all().filter(el=>el.type==='file').length,0);}
  }
});

test('an unavailable device photo replaces the image with a fixed explanatory message',()=>{
  const f=fixture();delete f.context.urls.identity;f.context.urls['identity-image']='/admin/employees/identity-image';
  f.context.identityView({id:7,branch:'f:1',name:'Fixture employee',identity:{front:true,back:false}});
  const image=f.body.all().find(el=>el.tagName==='IMG');assert.ok(image);image.dispatch('error');
  assert.equal(f.body.all().some(el=>el.tagName==='IMG'),false);
  assert.ok(f.body.all().some(el=>el.textContent==='الصورة مش متاحة على الجهاز ده لسه؛ هتظهر بعد تحديث البيانات.'));
  assert.equal(f.requests.length,0);
});
