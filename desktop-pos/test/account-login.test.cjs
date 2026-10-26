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
    const value={metadata:{async read(){return null;}},starts:0,stops:0,async isPrepared(){return legacy&&account.id==='default';},async connection(){return {actorId:42,serverOrigin:remote};},
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
