'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),{EventEmitter}=require('node:events');
const {originalSignin}=require('../src/account-login.cjs');
const {nativeAuthHTML,redirectAllowed}=require('../src/native-auth-http.cjs');
const origin='https://dashboard.example.test';
function factory(flow){
  const calls=[];
  const requestFactory=options=>{
    const request=new EventEmitter();request.options=options;request.follows=0;request.aborts=0;
    request.abort=()=>{request.aborts++;request.emit('abort');request.emit('close');};
    request.followRedirect=()=>{request.follows++;queueMicrotask(()=>response());};
    function response(){const message=new EventEmitter();message.statusCode=200;message.headers={};request.emit('response',message);
      message.emit('data',Buffer.from('<body data-dashboard-actor="42:admin">account</body>'));message.emit('end');request.emit('close');}
    request.end=body=>{calls.push({options,body,request});queueMicrotask(()=>flow?flow(request,response):response());};
    return request;
  };
  return {requestFactory,calls};
}
test('native ClientRequest holds each redirect until exact manual approval and returns the validated final URL',async()=>{
  const f=factory(request=>request.emit('redirect',302,'GET',origin+'/admin/dashboard',{})),browser={};
  const result=await nativeAuthHTML({...f,browser,origin,url:origin+'/admin/signin',method:'POST',body:'original-form-body'});
  assert.equal(f.calls[0].options.redirect,'manual');assert.equal(f.calls[0].options.session,browser);
  assert.equal(f.calls[0].options.useSessionCookies,true);assert.equal(f.calls[0].options.credentials,'include');
  assert.equal(f.calls[0].request.follows,1);assert.equal(result.url,origin+'/admin/dashboard');assert.equal(result.status,200);
  assert.match(await result.text(),/42:admin/);
});
test('closing the native Writable upload does not discard a still arriving HTTP response',async()=>{
  const f=factory((request,response)=>{request.emit('close');response();});
  const result=await nativeAuthHTML({...f,browser:{},origin,url:origin+'/admin/login'});
  assert.equal(result.status,200);assert.match(await result.text(),/42:admin/);
  const truncated=factory(request=>{const message=new EventEmitter();message.statusCode=200;request.emit('response',message);message.emit('data',Buffer.from('incomplete'));message.emit('close');});
  await assert.rejects(nativeAuthHTML({...truncated,browser:{},origin,url:origin+'/admin/login'}),/تعذر قراءة/);
});
test('foreign, downgrade, query, encoded, mutation, print and replayed POST redirects are aborted before followRedirect',async()=>{
  for(const [status,method,target] of [[302,'GET','https://foreign.example.test/collect'],[302,'GET','http://dashboard.example.test/admin/dashboard'],
    [302,'GET',origin+'/admin/dashboard?foreign=1'],[302,'GET',origin+'/admin/%64ashboard'],[302,'GET',origin+'/admin/adminLogout'],
    [302,'GET',origin+'/admin/takeaway/receipts/1/print'],[307,'POST',origin+'/admin/signin'],[308,'POST',origin+'/admin/signin']]){
    const f=factory(request=>request.emit('redirect',status,method,target,{}));
    await assert.rejects(nativeAuthHTML({...f,browser:{},origin,url:origin+'/admin/signin',method:'POST',body:'original-form-body'}),error=>error.code==='ACCOUNT_AUTH_REDIRECT_REJECTED');
    assert.equal(f.calls[0].request.follows,0,target);assert.equal(f.calls[0].request.aborts,1,target);
  }
});
test('remembered role reads use manual redirects and cannot start a credential POST',async()=>{
  const f=factory(request=>request.emit('redirect',302,'GET',origin+'/admin/applies-orders',{}));
  const result=await nativeAuthHTML({...f,browser:{},origin,url:origin+'/admin/dashboard',readOnly:true});
  assert.equal(result.url,origin+'/admin/applies-orders');assert.equal(f.calls[0].options.method,'GET');
  const refused=factory();await assert.rejects(nativeAuthHTML({...refused,browser:{},origin,url:origin+'/admin/signin',method:'POST',body:'must-not-send',readOnly:true}));
  assert.equal(refused.calls.length,0);
  assert.equal(redirectAllowed({origin,target:origin+'/admin/dashboard',statusCode:307,method:'GET',previousMethod:'POST'}),false);
});
test('fresh login reads refuse redirects before a password POST and native responses stay bounded',async()=>{
  const redirected=factory(request=>request.emit('redirect',302,'GET',origin+'/admin/dashboard',{}));
  await assert.rejects(nativeAuthHTML({...redirected,browser:{},origin,url:origin+'/admin/login',allowRedirects:false}));assert.equal(redirected.calls[0].request.follows,0);
  const oversized=factory(request=>{const message=new EventEmitter();message.statusCode=200;request.emit('response',message);message.emit('data',Buffer.alloc(4*1024**2+1));});
  await assert.rejects(nativeAuthHTML({...oversized,browser:{},origin,url:origin+'/admin/login'}));assert.equal(oversized.calls[0].request.aborts,1);
});
test('an opaque standard manual response rejects the returned original login form',async()=>{
  let posts=0;const form='<body><form method="post" action="'+origin+'/admin/signin" data-dashboard-login><input name="_token" value="'+'a'.repeat(40)+'"></form></body>';
  const browser={async fetch(_url,options){assert.equal(options.redirect,'manual');if(options.method==='POST')posts++;return new Response(form,{status:200});}};
  await assert.rejects(originalSignin({browser,origin,email:'owner@example.test',password:'incorrect-transient',remember:false}),error=>error.code==='ACCOUNT_CREDENTIALS_REJECTED');assert.equal(posts,1);
});
