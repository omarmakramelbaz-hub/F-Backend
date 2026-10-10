'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const phone=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/phone-orders.js'),'utf8');
const search=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/phone-address-search.js'),'utf8');
const picker=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/dashboard-location-picker.js'),'utf8');
function region(source,start,end){const a=source.indexOf(start),b=source.indexOf(end,a);assert.ok(a>=0&&b>a,'Exercise exact original UI source regions.');return source.slice(a,b);}
class Element{
 constructor(){this.value='';this.textContent='';this.id='addresses';this.children=[];this.listeners={};this.attributes={};this.hidden=true;this.style={};}
 setAttribute(k,v){this.attributes[k]=String(v);}removeAttribute(k){delete this.attributes[k];}replaceChildren(){this.children=[];}
 appendChild(child){this.children.push(child);}addEventListener(k,f){this.listeners[k]=f;}removeEventListener(k){delete this.listeners[k];}scrollIntoView(){}
}
const response=(payload,status=200)=>({ok:status<400,status,json:async()=>payload});
const tick=async()=>{await new Promise(setImmediate);await new Promise(setImmediate);};
function instance(local='1'){
 const box=new Element(),status=new Element(),distance=new Element(),fields={};for(const k of ['address','area','delivery_fee','discount','discount_reason'])fields[k]=new Element();fields.discount.value='0.00';
 const timers=new Map(),requests=[],providers=[],notices=[];let clock=0;
 const context={URL,AbortController,Promise,Number,Map,JSON,Error,console,fields,latInput:new Element(),lngInput:new Element(),companySelect:new Element(),
  root:{querySelector:s=>({'[data-phone-address-options]':box,'[data-phone-address-status]':status,'[data-phone-distance]':distance,'[data-phone-map]':new Element()})[s]},
  document:{body:{dataset:{dashboardLocal:local}},createElement:()=>new Element(),querySelector:()=>({content:'fixture-csrf'})},window:{},location:{href:'http://local.test/admin/phone-orders',origin:'http://local.test'},
  boot:{maps_key:'key',open_phone_maps:true,tile_url:'https://tiles.test/{z}/{x}/{y}'},urls:{address_suggestions:'/admin/phone-orders/address-suggestions',delivery_quote:'/admin/phone-orders/delivery-quote'},
  branch:'f:100',disposed:false,writing:false,uncertain:false,locating:false,controllers:{},deliverySettings:{ready:true,latitude:30,longitude:31},deliveryLocation:{delivery_quote_hash:'old'},locationPicker:null,locationGeneration:0,locationPreviewTimer:null,lastGeocoded:'',savedAddressPending:'',addressSearch:null,manualDeliveryFee:false,quote:null,cart:[],policy:{},customerRef:null,
  setTimeout:(f,ms)=>{const id=++clock;timers.set(id,{f,ms});return id;},clearTimeout:id=>timers.delete(id),text:k=>k,changed(){},lock(){},notify:v=>notices.push(v),scaled:v=>Number(v),decimal:v=>String(v),
  fetch:async(url,options)=>{const record={url,options,body:JSON.parse(options.body)};requests.push(record);if(url.endsWith('/delivery-quote'))return response({success:true,delivery:{latitude:Number(record.body.latitude),longitude:Number(record.body.longitude),delivery_fee:'10.00',distance_km:'1.000',km_price:'10.00',method:'pin_distance',delivery_quote_hash:'new-confirmed-hash'}});return new Promise(resolve=>{record.resolve=resolve;});}
 };
 vm.createContext(context);vm.runInContext(picker,context);context.DashboardLocationPicker=context.window.DashboardLocationPicker;
 context.DashboardLocationPicker.create=()=>{providers.push('create');return {resize(){},invalidate(){},set(){},clear(){},suggest:async q=>{providers.push(['suggest',q]);return [{id:'google',label:'Google place'}];},resolve:async item=>{providers.push(['resolve',item.id]);return {label:'Google address',area:'Google area',latitude:30.1,longitude:31.2};}};};
 vm.runInContext(search,context);context.PhoneAddressSearch=context.window.PhoneAddressSearch;
 vm.runInContext(region(phone,'    var desktopLocal =','\n    var labels =')+region(phone,'    function invalidateLocation()','    function setCustomerLocation(')+region(phone,'    function endpoint(','    function notify(')+region(phone,'    function abort(kind)','    function canWrite(')+region(phone,'    function ensureLocation()','    async function pollReceiver(')+region(phone,'    function payload()','    function changed('),context);
 return {context,box,status,distance,requests,providers,notices,run(ms){for(const [id,t]of [...timers])if(t.ms===ms){timers.delete(id);t.f();}},async result(payload,index=requests.length-1,statusCode=200){requests[index].resolve(response(payload,statusCode));await tick();},dispose(){context.disposed=true;context.addressSearch.destroy();},changeBranch(branch){context.addressSearch.reset();context.branch=branch;context.deliveryLocation=null;}};
}
const saved={id:'saved-1',label:'شارع محفوظ، المنطقة',latitude:30.123,longitude:31.456};
test('original local wiring posts saved search with CSRF/signal, bypasses providers, and requires explicit confirmation',async()=>{
 const f=instance();f.context.fields.address.value='شارع';f.context.ensureLocation();await tick();assert.equal(f.requests.length,1);const request=f.requests[0];
 assert.deepEqual(request.body,{branch:'f:100',query:'شارع'});assert.equal(request.options.credentials,'same-origin');assert.equal(request.options.headers['X-CSRF-TOKEN'],'fixture-csrf');assert.ok(request.options.signal instanceof AbortSignal);
 await f.result({success:true,provider:'saved',items:[saved,{...saved,id:'bad',latitude:Infinity},{...saved,id:'zero',latitude:0,longitude:0},{...saved,id:'range',longitude:181}]});assert.equal(f.box.children.length,1);assert.match(f.status.textContent,/عنوانًا محفوظًا/);
 await f.box.children[0].listeners.click();await tick();assert.equal(f.context.fields.area.value,'');assert.equal(f.context.fields.address.value,saved.label);assert.equal(f.context.latInput.value,saved.latitude);assert.equal(f.context.deliveryLocation,null);assert.equal(f.context.payload().location_confirmed,false);
 f.run(350);await tick();assert.equal(f.context.deliveryLocation,null,'Cost preview does not confirm the chosen saved location.');assert.match(f.distance.textContent,/إحداثيات العميل/);
 await f.context.confirmLocation(false);assert.equal(f.context.payload().location_confirmed,true);assert.equal(f.context.payload().delivery_quote_hash,'new-confirmed-hash');assert.deepEqual(f.providers,[]);f.dispose();
});
test('original local search fences late branch replies and does not reuse cached branch results',async()=>{
 const f=instance();f.context.fields.address.value='شارع';f.context.addressSearch.search();await tick();f.changeBranch('gs:60');assert.equal(f.requests[0].options.signal.aborted,true);
 f.context.addressSearch.search();await tick();f.run(0);await tick();await f.result({success:true,provider:'saved',items:[saved]},0);assert.equal(f.box.children.length,0);
 const current=f.requests.length-1;assert.equal(f.requests[current].body.branch,'gs:60');await f.result({success:true,provider:'saved',items:[{...saved,id:'current',label:'عنوان الفرع الحالي'}]},current);assert.equal(f.box.children[0].textContent,'عنوان الفرع الحالي');
 f.context.addressSearch.search();await tick();assert.equal(f.requests.length,current+2,'cache:false repeats the actual branch-scoped read.');f.dispose();
});
test('original component discards stale query replies, cancellation and disposed results',async()=>{
 const f=instance();f.context.fields.address.value='الأول';f.context.addressSearch.search();await tick();f.context.fields.address.value='الثاني';f.context.addressChanged();f.run(250);await tick();
 await f.result({success:true,provider:'saved',items:[saved]},0);assert.equal(f.box.children.length,0);f.run(0);await tick();assert.equal(f.requests[1].body.query,'الثاني');
 f.run(7000);await tick();assert.equal(f.requests[1].options.signal.aborted,true);assert.equal(f.box.children.length,0);f.context.addressSearch.search();await tick();f.dispose();await f.result({success:true,provider:'saved',items:[saved]});assert.equal(f.box.children.length,0);assert.deepEqual(f.providers,[]);
});
test('empty, revoked authority and unexpected providers retain manual-coordinate guidance without selection',async()=>{
 for(const [payload,code]of [[{success:true,provider:'saved',items:[]},200],[{success:false,message:'revoked'},403],[{success:true,provider:'photon',items:[saved]},200]]){
  const f=instance();f.context.fields.address.value='شارع';f.context.addressSearch.search();await tick();await f.result(payload,0,code);assert.equal(f.box.children.length,0);assert.equal(f.context.latInput.value,'');assert.deepEqual(f.providers,[]);if(code===403)assert.match(f.status.textContent,/forbidden/);else assert.match(f.status.textContent,/إحداثيات/);f.dispose();
 }
});
test('ordinary original online UI retains Google suggestion, resolution and default wording',async()=>{
 const f=instance('0');f.context.fields.address.value='online';f.context.ensureLocation();await tick();assert.equal(f.requests.length,0);assert.equal(f.box.children.length,2);assert.equal(f.box.children[1].textContent,'Google Maps');
 await f.box.children[0].listeners.click();await tick();assert.equal(f.context.fields.area.value,'Google area');assert.match(f.status.textContent,/العنوان المختار/);assert.deepEqual(f.providers,['create',['suggest','online'],['resolve','google']]);f.dispose();
});
