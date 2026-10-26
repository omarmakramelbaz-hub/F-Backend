const test=require('node:test'),assert=require('node:assert/strict');
const {AccountLogin,originalSignin,actorFromHTML,csrfFromHTML,installCookies,authCookie,recaller}=require('../src/account-login.cjs');
const remote='https://dashboard.example.test',local='http://127.0.0.1:33408',secret='synthetic-only-test-password';
const login=scope=>`<body><form method="POST" action="${scope}/admin/signin" data-dashboard-login><input name="_token" value="${'a'.repeat(40)}"></form></body>`;
const home=actor=>`<body data-dashboard-actor="${actor}:admin"><main>Original authenticated page</main></body>`;
function response(body,status,url,location){const value=new Response(body,{status,headers:location?{location}:{}});Object.defineProperty(value,'url',{value:url});return value;}
function jar(){
  let values=[];const calls=[];
  return {calls,all:()=>structuredClone(values),seed:items=>{values=structuredClone(items);},
    async get(query={}){calls.push(['get',query]);return structuredClone(values.filter(value=>(!query.name||value.name===query.name)&&(!query.url||value.domain===new URL(query.url).hostname)));},
    async remove(url,name){calls.push(['remove',name]);values=values.filter(value=>value.name!==name||value.domain!==new URL(url).hostname);},
    async set(value){calls.push(['set',value.name]);values=values.filter(item=>item.name!==value.name||item.domain!==new URL(value.url).hostname);values.push({...value,domain:new URL(value.url).hostname,session:!value.expirationDate});},
    async flushStore(){calls.push(['flush']);}
  };
}
function session(name,handler){const cookies=jar();return {name,cookies,cleared:0,async clearStorageData(){this.cleared++;cookies.seed([]);},fetch(url,options){return handler(this,url,options);}};}
function cookiesFor(scope,remember=false){const host=new URL(scope).hostname,secure=scope.startsWith('https:');return [{name:'laravel_session',value:'synthetic-session',domain:host,path:'/',httpOnly:true,secure,session:true},
  ...(remember?[{name:recaller,value:'synthetic-recaller',domain:host,path:'/',httpOnly:true,secure,session:false,expirationDate:Date.now()/1000+3600}]:[])];}
function originalHandler({actor=42,rejected=false,redirect='/admin/dashboard',capture=[]}={}){
  return async(browser,url,options)=>{
    capture.push({url,method:options.method||'GET',headers:{...options.headers}});
    const scope=new URL(url).origin;
    if(url===scope+'/admin/login')return response(login(scope),200,url);
    if(url===scope+'/admin/signin'){
      assert.equal(options.method,'POST');assert.equal(options.redirect,'manual');assert.equal(options.credentials,'include');
      assert.equal(options.headers.Referer,scope+'/admin/login');assert.equal(options.headers.Origin,scope);
      const fields=new URLSearchParams(options.body);assert.equal(fields.get('_token'),'a'.repeat(40));assert.equal(fields.get('password'),secret);
      if(rejected)return response('',302,url,scope+'/admin/login');
      browser.cookies.seed(cookiesFor(scope,fields.get('remember')==='1'));return response('',302,url,scope+redirect);
    }
    if(url===scope+redirect)return response(home(actor),200,url);
    throw Error('UNEXPECTED_SYNTHETIC_ROUTE');
  };
}
test('original sign-in obtains fresh CSRF and sends the original POST before reading the authenticated actor',async()=>{
  const capture=[],browser=session('fresh',originalHandler({capture}));
  const actor=await originalSignin({browser,origin:remote,email:'manager@example.test',password:secret,remember:true});
  assert.deepEqual(actor,{actorId:42,role:'admin'});assert.deepEqual(capture.map(value=>value.method),['GET','POST','GET']);
  assert.equal(capture.some(value=>value.headers['X-Fasakhansta-Desktop']),false);
  const localCapture=[],localBrowser=session('fresh',originalHandler({capture:localCapture}));
  await originalSignin({browser:localBrowser,origin:local,localToken:'b'.repeat(64),email:'manager@example.test',password:secret,remember:false});
  assert.equal(localCapture.every(value=>value.headers['X-Fasakhansta-Desktop']==='b'.repeat(64)),true);
});
test('wrong credentials, bad CSRF forms and foreign redirects never produce a trusted account',async()=>{
  const input={origin:remote,email:'manager@example.test',password:secret,remember:false};
  await assert.rejects(originalSignin({...input,browser:session('fresh',originalHandler({rejected:true}))}),{code:'ACCOUNT_CREDENTIALS_REJECTED'});
  await assert.rejects(originalSignin({...input,browser:session('fresh',originalHandler({redirect:'https://foreign.example.test/admin/dashboard'}))}));
  for(const html of [login(remote).replace('/admin/signin','/admin/delete'),login(remote).replace('name="_token"','name="unknown"'),'<body data-dashboard-actor="42:admin"></body>']){
    assert.throws(()=>csrfFromHTML(html,remote));
  }
  for(const html of ['<body></body>','<body data-dashboard-actor="42:admin" data-dashboard-actor="43:admin"></body>','<div data-dashboard-actor="42:admin"></div>'])assert.throws(()=>actorFromHTML(html));
});
test('cookie handoff is scoped to original protected auth cookies and rolls back if flush fails',async()=>{
  const source=session('source',()=>{}),target=session('target',()=>{});
  source.cookies.seed([...cookiesFor(remote,true),{name:'foreign',value:'untrusted',domain:'foreign.example.test',path:'/',httpOnly:true,secure:true,session:true}]);
  const old=cookiesFor(remote);old[0].value='previous';target.cookies.seed(old);
  const undo=await installCookies(source,target,remote);assert.deepEqual(target.cookies.all().map(value=>value.name),['laravel_session',recaller]);
  await undo();assert.equal(target.cookies.all()[0].value,'previous');
  let first=true;const flush=target.cookies.flushStore.bind(target.cookies);target.cookies.flushStore=async()=>{if(first){first=false;throw Error('flush failed');}return flush();};
  await assert.rejects(installCookies(source,target,remote));assert.equal(target.cookies.all()[0].value,'previous');
  for(const changed of [{domain:'foreign.example.test'},{path:'/admin'},{httpOnly:false}])assert.equal(authCookie({...cookiesFor(remote)[0],...changed},remote,false),false);
  const legacy=cookiesFor(remote);legacy[0].secure=false;source.cookies.seed(legacy);
  await installCookies(source,target,remote);assert.equal(target.cookies.all()[0].secure,true,'Native HTTPS handoff tightens legacy server cookie transport.');
});
function fixture({known=true,offline=false,rejected=false,actor=42,legacy=true}={}){
  const old={id:'default',actorId:42,serverOrigin:remote,profile:'/synthetic/default',partition:'persist:fasakhansta-dashboard'};
  const rows=new Map([['default',old]]),aliases=new Map(known?[['owner@example.test','default']]:[]),events=[],saved=[];let selected='default',created=0;
  const profiles={async selected(){return rows.get(selected);},async account(id){return rows.get(id);},async findLogin(name){return rows.get(aliases.get(name))||null;},async forActor(id){return [...rows.values()].find(value=>value.actorId===id)||null;},
    async create(){const row={id:'new-'+(++created),actorId:null,serverOrigin:null,profile:'/synthetic/new-'+created,partition:'persist:new-'+created};rows.set(row.id,row);return row;},
    async bind(id,value){Object.assign(rows.get(id),value);},async assertBinding(id,value){Object.assign(rows.get(id),{actorId:value.actorId,serverOrigin:value.serverOrigin});},
    async rememberLogin(id,binding,name){saved.push({id,binding,name});aliases.set(name,id);},async select(id){selected=id;events.push('select:'+id);return rows.get(id);}};
  const browsers=new Map(),runtimes=new Map(),requests=[];
  const createSession=name=>{
    if(!browsers.has(name))browsers.set(name,session(name,async(browser,url,options)=>{
      requests.push({partition:name,url,method:options.method||'GET'});
      if(offline&&url.startsWith(remote))throw Object.assign(Error('net::ERR_INTERNET_DISCONNECTED'),{code:'ERR_INTERNET_DISCONNECTED'});
      return originalHandler({actor:url.startsWith(remote)?actor:42,rejected})(browser,url,options);
    }));return browsers.get(name);
  };
  const createRuntime=async account=>{
    const metadata=new Map();
    const value={metadata:{async read(name){return metadata.get(name)??null;},async write(name,value){metadata.set(name,value);}},starts:0,stops:0,async isPrepared(){return legacy&&account.id==='default';},async connection(){return {actorId:42,serverOrigin:remote};},
      async start(){this.starts++;events.push('runtime-start:'+account.id);return {origin:local,token:'b'.repeat(64)};},cancelStartup(){this.cancelled=true;},async stop(){this.stops++;}};
    runtimes.set(account.id,value);return value;
  };
  return {broker:new AccountLogin({profiles,createRuntime,createSession,serverOrigin:remote}),profiles,rows,browsers,runtimes,requests,events,saved,selected:()=>selected};
}
test('a known prepared account authenticates locally without Internet or a second profile selection',async()=>{
  const f=fixture({offline:true});const result=await f.broker.authenticate({email:'owner@example.test',password:secret,remember:true});
  assert.equal(result.account.id,'default');assert.equal(result.local,true);assert.equal(result.origin,local);assert.equal(f.runtimes.get('default').starts,1);
  assert.equal(f.requests.some(value=>value.url.startsWith(remote)),false);assert.equal(JSON.stringify(f.saved).includes(secret),false);
  assert.equal(f.browsers.get(result.account.partition).cookies.all().some(value=>value.name===recaller),true);
  assert.equal([...f.browsers].filter(([name])=>!name.startsWith('persist:')).every(([,browser])=>browser.cleared===1),true);
  await f.broker.handoff(result);await f.broker.stop();assert.equal(result.runtime.stops,0,'Handoff transfers the existing service to main.');
});
test('an unknown online branch account uses the server first and never boots the old Owner account',async()=>{
  const f=fixture({known:false,actor:30});const result=await f.broker.authenticate({email:'branch@example.test',password:secret,remember:false});
  assert.equal(f.requests[0].url,remote+'/admin/login');assert.equal(result.local,false);assert.equal(result.account.actorId,30);assert.notEqual(result.account.id,'default');
  assert.equal(f.runtimes.get('default')?.starts||0,0);assert.equal(f.rows.get('default').actorId,42);assert.equal(f.selected(),result.account.id);
  assert.equal(result.localToken,'');assert.equal(JSON.stringify(f.saved).includes(secret),false);
});
test('an offline legacy account learns its alias only after its original local password succeeds',async()=>{
  const f=fixture({known:false,offline:true});const result=await f.broker.authenticate({email:'owner@example.test',password:secret,remember:false});
  assert.equal(result.local,true);assert.equal(result.account.id,'default');assert.equal(f.saved.length,1);assert.equal(f.saved[0].name,'owner@example.test');
});
test('a server rejection never falls back to a cached local password or changes the selected profile',async()=>{
  const f=fixture({known:false,rejected:true});await assert.rejects(f.broker.authenticate({email:'owner@example.test',password:secret,remember:false}),{code:'ACCOUNT_CREDENTIALS_REJECTED'});
  assert.equal(f.runtimes.size,0);assert.equal(f.selected(),'default');assert.deepEqual(f.saved,[]);
});
test('wrong explicit credentials cannot enter through an existing remembered cookie',async()=>{
  const f=fixture({known:true,rejected:true});const remembered=f.browsers;
  // A persistent old session is deliberately available. Explicit auth still
  // uses a fresh partition and must transmit the original password POST.
  const target=session('persist:fasakhansta-dashboard',()=>{});target.cookies.seed(cookiesFor(local,true));remembered.set(target.name,target);
  await assert.rejects(f.broker.authenticate({email:'owner@example.test',password:secret,remember:true}),{code:'ACCOUNT_CREDENTIALS_REJECTED'});
  assert.equal(f.requests.filter(value=>value.method==='POST').length,1);assert.equal(f.requests.every(value=>!value.partition.startsWith('persist:')),true);
  assert.equal(target.cookies.all().some(value=>value.name===recaller),true);assert.deepEqual(f.saved,[]);
});
function deferred(){let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};}
function passiveFixture({autoCancel=true,stopGate=null,failStop=false}={}){
  const f=fixture({known:true,actor:30}),started=deferred(),cancelled=deferred(),stopping=deferred(),boot=deferred(),made=[];
  const create=f.broker.createRuntime;
  f.broker.createRuntime=async account=>{
    const runtime=await create(account);made.push(runtime);
    if(account.id==='default'&&made.length===1){
      runtime.start=async()=>{runtime.starts++;started.resolve();return boot.promise;};
      runtime.cancelStartup=()=>{runtime.cancelled=true;cancelled.resolve();if(autoCancel)boot.reject(Error('passive startup cancelled'));};
      runtime.stop=async()=>{runtime.stops++;stopping.resolve();if(failStop&&runtime.stops===1)throw Error('synthetic stop failure');if(stopGate)await stopGate.promise;};
    }
    return runtime;
  };
  f.broker.createSession('persist:fasakhansta-dashboard').cookies.seed(cookiesFor(local,true));
  return {...f,started,cancelled,stopping,boot,made};
}
test('editing cancels a passive startup and drains both startup and stop before explicit authentication',{timeout:3000},async()=>{
  const stopGate=deferred(),f=passiveFixture({autoCancel:false,stopGate}),remembered=f.broker.resumeRemembered();
  await f.started.promise;f.broker.userEditing();await f.cancelled.promise;await f.stopping.promise;
  let accepted=false;const explicit=f.broker.authenticate({email:'branch@example.test',password:secret,remember:false}).then(value=>{accepted=true;return value;});
  await Promise.resolve();assert.equal(f.requests.length,0);assert.equal(accepted,false);
  stopGate.resolve();await Promise.resolve();assert.equal(f.requests.length,0,'An exited service alone does not release an unfinished startup.');
  f.boot.reject(Error('cancelled verification drained'));
  const result=await explicit;assert.equal(await remembered,null);assert.equal(result.account.actorId,30);
  assert.equal(f.made[0].cancelled,true);assert.equal(f.made[0].stops,1);await f.broker.stop();
});
test('an explicit login for the same account creates a fresh runtime after retiring its cancelled startup',{timeout:3000},async()=>{
  const f=passiveFixture(),remembered=f.broker.resumeRemembered();await f.started.promise;
  const result=await f.broker.authenticate({email:'owner@example.test',password:secret,remember:true});
  assert.equal(await remembered,null);assert.equal(f.made.length,2);assert.notEqual(result.runtime,f.made[0]);
  assert.equal(f.made[0].stops,1);assert.equal(f.made[1].starts,1);assert.equal(result.account.id,'default');
  assert.equal(f.broker.runtimes.get('default'),result.runtime);assert.equal(await f.broker.runtime(result.account),result.runtime);await f.broker.stop();
});
test('an unknown Branch server login does not wait for the remembered Owner startup to finish naturally',{timeout:3000},async()=>{
  const f=passiveFixture(),remembered=f.broker.resumeRemembered();await f.started.promise;
  const result=await f.broker.authenticate({email:'branch@example.test',password:secret,remember:false});
  assert.equal(await remembered,null);assert.equal(result.local,false);assert.equal(result.account.actorId,30);
  assert.equal(f.requests[0].url,remote+'/admin/login');assert.equal(f.made[0].cancelled,true);
  assert.equal(f.broker.runtimes.has('default'),false);assert.equal(f.broker.runtimeTasks.has('default'),false);
  assert.equal(f.rows.get('default').actorId,42);await f.broker.stop();
});
test('a failed passive stop retains ownership and blocks new authentication until shutdown retries the drain',{timeout:3000},async()=>{
  const f=passiveFixture({failStop:true}),remembered=f.broker.resumeRemembered();await f.started.promise;
  await assert.rejects(f.broker.authenticate({email:'branch@example.test',password:secret,remember:false}),/synthetic stop failure/);
  assert.equal(await remembered,null);assert.equal(f.requests.length,0);assert.equal(f.made.length,1);
  assert.equal(f.broker.runtimes.get('default'),f.made[0]);assert.equal(await f.broker.runtimeTasks.get('default'),f.made[0]);
  assert.equal(f.selected(),'default');assert.deepEqual(f.saved,[]);
  await f.broker.stop();assert.equal(f.made[0].stops,2);assert.equal(f.broker.runtimes.size,0);assert.equal(f.broker.runtimeTasks.size,0);
});

test('explicit sign-out requires a password before any remembered cookie read, runtime boot or HTTP request',async()=>{
  const signout=require('../src/account-signout.cjs'),f=fixture();
  const account=await f.profiles.account('default'),runtime=await f.broker.runtime(account);
  await signout.signedOut(runtime);
  const target=f.broker.createSession(account.partition);target.cookies.seed(cookiesFor(local,true));
  const createSession=f.broker.createSession;let sessionCreations=0;
  f.broker.createSession=(...args)=>{sessionCreations++;return createSession(...args);};
  assert.equal(await f.broker.resumeRemembered(),null);
  assert.equal(runtime.starts,0);assert.equal(f.requests.length,0);assert.equal(sessionCreations,0);
  assert.equal(target.cookies.calls.some(([operation])=>operation==='get'),false);
  assert.equal(await signout.requirePassword(runtime),true);
  await f.broker.stop();
});

test('a rejected password retains explicit sign-out while a fresh successful password retires it',async()=>{
  const signout=require('../src/account-signout.cjs');
  const rejected=fixture({rejected:true}),badRuntime=await rejected.broker.runtime(await rejected.profiles.account('default'));
  await signout.signedOut(badRuntime);
  await assert.rejects(rejected.broker.authenticate({email:'owner@example.test',password:secret,remember:true}),{code:'ACCOUNT_CREDENTIALS_REJECTED'});
  assert.equal(await signout.requirePassword(badRuntime),true);assert.equal(rejected.selected(),'default');await rejected.broker.stop();
  const accepted=fixture(),runtime=await accepted.broker.runtime(await accepted.profiles.account('default'));
  await signout.signedOut(runtime);
  const result=await accepted.broker.authenticate({email:'owner@example.test',password:secret,remember:true});
  assert.equal(result.account.id,'default');assert.equal(await signout.requirePassword(runtime),false);
  assert.equal(accepted.requests.filter(value=>value.method==='POST').length,1);await accepted.broker.stop();
});

test('an unexpected signed-out marker remains fail-closed without interpreting cookie values',async()=>{
  const signout=require('../src/account-signout.cjs');
  for(const value of [false,{},[],{format:99},'unexpected'])assert.equal(await signout.requirePassword({metadata:{read:async()=>value}}),true);
  assert.equal(await signout.requirePassword({metadata:{read:async()=>null}}),false);
});
