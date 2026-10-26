'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const {authenticationAdmission,originalSignin}=require('../src/account-login.cjs');
const origin='https://dashboard.example.test';
function fixture(options){
  let listener,removed=false;
  const browser={webRequest:{onBeforeRequest(filter,callback){if(filter===null){removed=true;listener=null;return;}assert.deepEqual(filter,{urls:['<all_urls>']});listener=callback;}}};
  const gate=authenticationAdmission(browser,origin,options);
  return {gate,removed:()=>removed,request(path,method='GET'){let result;listener({url:new URL(path,origin).href,method},value=>{result=value;});return result.cancel===false;}};
}
test('native follow admission allows only the fresh login, one original POST and authenticated role reads',()=>{
  const f=fixture();assert.equal(f.gate.redirect,'follow');
  assert.equal(f.request('/admin/login'),true);assert.equal(f.request('/admin/dashboard'),false);
  f.gate.beginPost();assert.equal(f.request('/admin/signin','POST'),true);
  assert.equal(f.request('/admin/applies-orders'),true);assert.equal(f.request('/admin/login'),true);
  assert.equal(f.request('/admin/signin','POST'),false,'A 307/308 redirect cannot replay the original credential POST.');
  f.gate.close();assert.equal(f.removed(),true);
});
test('remembered native admission permits role redirects but never sends a password or mutation POST',()=>{
  const f=fixture({readOnly:true});assert.equal(f.request('/admin/dashboard'),true);assert.equal(f.request('/admin/applies-orders'),true);
  f.gate.beginPost();assert.equal(f.request('/admin/signin','POST'),false);assert.equal(f.request('/admin/adminLogout'),false);f.gate.close();
});
test('foreign, query, encoded, mutation and consuming print redirects are rejected before transmission',()=>{
  for(const value of ['https://foreign.example.test/admin/dashboard','http://dashboard.example.test/admin/dashboard',
    '/admin/dashboard?foreign=1','/admin/%64ashboard','/admin/adminLogout','/admin/takeaway/receipts/1/print',
    '/admin/dashboard#other']){
    const f=fixture();f.request('/admin/login');f.gate.beginPost();f.request('/admin/signin','POST');
    assert.equal(f.request(value),false,value);f.gate.close();
  }
  const f=fixture();for(let i=0;i<8;i++)assert.equal(f.request('/admin/login'),true);assert.equal(f.request('/admin/login'),false);f.gate.close();
});
test('an Electron followed response with no final URL rejects the returned original login form',async()=>{
  let listener,posts=0;
  const form='<body><form method="post" action="'+origin+'/admin/signin" data-dashboard-login><input name="_token" value="'+'a'.repeat(40)+'"></form></body>';
  const browser={webRequest:{onBeforeRequest(filter,callback){listener=filter===null?null:callback;}},async fetch(url,options){
    assert.equal(options.redirect,'follow');let decision;listener({url,method:options.method||'GET'},value=>{decision=value;});assert.equal(decision.cancel,false);
    if(options.method==='POST')posts++;
    return new Response(form,{status:200});
  }};
  await assert.rejects(originalSignin({browser,origin,email:'owner@example.test',password:'incorrect-transient',remember:false}),error=>error.code==='ACCOUNT_CREDENTIALS_REJECTED');
  assert.equal(posts,1);assert.equal(listener,null);
});
