'use strict';
const crypto=require('node:crypto');
const {withDeadline}=require('./native-deadline.cjs');

const recaller='remember_admin_'+crypto.createHash('sha1').update('Illuminate\\Auth\\SessionGuard').digest('hex');
const roles=new Set(['admin','vendor','resturant_owner']);
const homes=new Set(['/admin/dashboard','/admin/applies-orders','/admin/resturants']);
const failure=()=>Error('تعذر تسجيل الدخول. تأكد من بيانات الحساب والاتصال، ثم أعد المحاولة.');
const denied=()=>Object.assign(failure(),{code:'ACCOUNT_CREDENTIALS_REJECTED'});
const aborted=()=>Object.assign(Error('تم إلغاء تسجيل الدخول.'),{code:'ACCOUNT_LOGIN_CANCELLED'});
const restartRequired=()=>Object.assign(Error('تعذر تأكيد حفظ جلسة الحساب. أغلق البرنامج ثم أعد فتحه.'),{code:'ACCOUNT_LOGIN_RESTART_REQUIRED'});
function scope(value,local=false){
  const url=new URL(value);
  if(url.username||url.password||url.origin!==value||(!local&&url.protocol!=='https:')
    ||(local&&(url.protocol!=='http:'||url.hostname!=='127.0.0.1'||!url.port)))throw failure();
  return url;
}
function networkFailure(error){
  return /ERR_(?:INTERNET_DISCONNECTED|NAME_NOT_RESOLVED|CONNECTION_(?:REFUSED|RESET|CLOSED|TIMED_OUT)|NETWORK_CHANGED|ADDRESS_UNREACHABLE|TIMED_OUT)|\b(?:ENOTFOUND|ECONNREFUSED|ENETUNREACH|EHOSTUNREACH|ETIMEDOUT)\b/.test(String(error?.code||'')+' '+String(error?.message||'')+' '+String(error?.cause?.code||''));
}
function actorFromHTML(html){
  if(typeof html!=='string'||Buffer.byteLength(html)>4*1024**2)throw failure();
  const body=html.match(/<body\b((?:[^>"']|"[^"]*"|'[^']*')*)>/i);
  if(!body)throw failure();
  const matches=[...body[1].matchAll(/\bdata-dashboard-actor\s*=\s*(["'])([1-9][0-9]{0,14}):(admin|vendor|resturant_owner)\1/gi)];
  if(matches.length!==1)throw failure();
  const actorId=Number(matches[0][2]),role=matches[0][3];
  if(!Number.isSafeInteger(actorId)||actorId<1||!roles.has(role))throw failure();
  return {actorId,role};
}
function csrfFromHTML(html,origin){
  if(typeof html!=='string'||Buffer.byteLength(html)>4*1024**2)throw failure();
  const form=html.match(/<form\b((?:[^>"']|"[^"]*"|'[^']*')*)\bdata-dashboard-login\b[^>]*>([\s\S]*?)<\/form>/i);
  if(!form)throw failure();
  const attributes=form[0].slice(0,form[0].indexOf('>')+1);
  const method=attributes.match(/\bmethod\s*=\s*(["'])post\1/i),action=attributes.match(/\baction\s*=\s*(["'])(.*?)\1/i);
  if(!method||!action||new URL(action[2],origin).href!==origin+'/admin/signin')throw failure();
  const fields=[...form[2].matchAll(/<input\b((?:[^>"']|"[^"]*"|'[^']*')*)>/gi)]
    .filter(item=>/\bname\s*=\s*(["'])_token\1/i.test(item[1]));
  if(fields.length!==1)throw failure();
  const token=fields[0][1].match(/\bvalue\s*=\s*(["'])([A-Za-z0-9_-]{16,256})\1/i);
  if(!token)throw failure();return token[2];
}
async function boundedHTML(response){
  if(!response?.body?.getReader){const text=await response.text();if(Buffer.byteLength(text)>4*1024**2)throw failure();return text;}
  const reader=response.body.getReader(),parts=[];let size=0;
  try{while(true){const value=await reader.read();if(value.done)break;size+=value.value.byteLength;if(size>4*1024**2)throw failure();parts.push(Buffer.from(value.value));}return Buffer.concat(parts).toString('utf8');}
  catch(error){await reader.cancel().catch(()=>{});throw error;}finally{reader.releaseLock();}
}
async function discard(response){await response?.body?.cancel?.().catch(()=>{});}
function authCookie(cookie,origin,local){
  const url=scope(origin,local),host=url.hostname;
  if(!cookie||typeof cookie.name!=='string'||typeof cookie.value!=='string'||cookie.value.length>8192
    ||cookie.path!=='/'||typeof cookie.domain!=='string'||cookie.domain.replace(/^\./,'').toLowerCase()!==host.toLowerCase())return false;
  const session=/^[A-Za-z0-9_-]{1,120}_session$/.test(cookie.name),remember=cookie.name===recaller,xsrf=cookie.name==='XSRF-TOKEN';
  // The native authentication client only contacts the pinned HTTPS origin.
  // Some existing Laravel installations omit Secure; the destination copy is
  // tightened to Secure instead of rejecting a correctly authenticated user.
  if(!(session||remember||xsrf)||(!xsrf&&cookie.httpOnly!==true)||typeof cookie.secure!=='boolean')return false;
  if(remember&&(cookie.session!==false||!Number.isFinite(cookie.expirationDate)||cookie.expirationDate<=Date.now()/1000))return false;
  return cookie.session===true||Number.isFinite(cookie.expirationDate)&&cookie.expirationDate>Date.now()/1000;
}
function cookieDetails(cookie,origin,upgrade=true){
  return {url:origin+'/',name:cookie.name,value:cookie.value,path:'/',httpOnly:cookie.httpOnly===true,
    secure:upgrade&&origin.startsWith('https:')?true:cookie.secure===true,
    ...(!upgrade&&cookie.hostOnly===false?{domain:cookie.domain}:{}),
    ...(cookie.sameSite?{sameSite:cookie.sameSite}:{}),...(cookie.session===false?{expirationDate:cookie.expirationDate}:{})};
}
async function installCookies(source,target,origin,local=false,{active=()=>true}={}){
  scope(origin,local);
  const bounded=async(work,external=true)=>{
    let live=true;const check=()=>{if(!live)throw restartRequired();if(external&&!active())throw aborted();};
    try{return await withDeadline(()=>work(check),5000,'تعذر حفظ جلسة الحساب في الوقت المحدد.');}
    catch(error){if(error.code==='NATIVE_OPERATION_TIMEOUT')throw restartRequired();throw error;}
    finally{live=false;}
  };
  let incoming,previous,names;
  await bounded(async check=>{
    check();incoming=(await source.cookies.get({url:origin+'/admin/dashboard'})).filter(value=>authCookie(value,origin,local));check();
    if(incoming.length>16||!incoming.some(value=>/^[A-Za-z0-9_-]{1,120}_session$/.test(value.name)))throw failure();
    previous=(await target.cookies.get({url:origin+'/admin/dashboard'})).filter(value=>authCookie(value,origin,local));check();
    if(previous.length>16)throw failure();names=new Set([...incoming,...previous].map(value=>value.name));
  });
  const replace=(values,external=true,upgrade=true)=>bounded(async check=>{
    for(const name of names){check();await target.cookies.remove(origin+'/',name);check();}
    for(const value of values){check();await target.cookies.set(cookieDetails(value,origin,upgrade));check();}
    check();await target.cookies.flushStore();check();
  },external);
  try{await replace(incoming);}catch(error){
    if(error.code==='ACCOUNT_LOGIN_RESTART_REQUIRED')throw error;
    try{await replace(previous,false,false);}catch{throw restartRequired();}throw error;
  }
  let undone=false;
  return async()=>{if(undone)return;undone=true;await replace(previous,false,false);};
}

/** Original Laravel authentication in a fresh, nonpersistent cookie session. */
async function originalSignin({browser,origin,localToken='',email,password,remember,signal}){
  const local=Boolean(localToken);scope(origin,local);
  const headers={Accept:'text/html',...(local?{'X-Fasakhansta-Desktop':localToken}:{})};
  const fetch=(url,options={})=>browser.fetch(url,{...options,credentials:'include',redirect:'manual',signal,headers:{...headers,...options.headers}});
  const login=await fetch(origin+'/admin/login');
  if(login.status!==200){await discard(login);throw failure();}
  const csrf=csrfFromHTML(await boundedHTML(login),origin);
  const body=new URLSearchParams({_token:csrf,email,password,remember:remember?'1':'0'});
  let response;try{response=await fetch(origin+'/admin/signin',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-CSRF-TOKEN':csrf,Referer:origin+'/admin/login',Origin:origin},body:body.toString()});}
  finally{body.set('password','');}
  let next=response;
  for(let redirects=0;[301,302,303].includes(next.status)&&redirects<4;redirects++){
    const location=next.headers.get('location');await discard(next);
    const url=new URL(location||'',origin);
    if(url.origin===origin&&url.pathname==='/admin/login'&&!url.search&&!url.hash)throw denied();
    if(url.origin!==origin||url.username||url.password||!homes.has(url.pathname)||url.search||url.hash)throw failure();
    next=await fetch(url.href);
  }
  if(next.status!==200){await discard(next);throw [401,422].includes(next.status)?denied():failure();}
  const finalURL=next.url?new URL(next.url):null;
  if(finalURL&&(finalURL.origin!==origin||!homes.has(finalURL.pathname))){await discard(next);throw finalURL.origin===origin&&['/admin/login','/admin/signin'].includes(finalURL.pathname)?denied():failure();}
  return actorFromHTML(await boundedHTML(next));
}

class AccountLogin{
  constructor({profiles,createRuntime,createSession,serverOrigin,stopped=()=>false,onState=()=>{}}){
    scope(serverOrigin);Object.assign(this,{profiles,createRuntime,createSession,serverOrigin,stopped,onState});
    this.runtimes=new Map();this.runtimeTasks=new Map();this.sessions=new Set();this.controllers=new Set();this.running=null;this.cancelled=false;this.sequence=0;
  }
  assert(){if(this.cancelled||this.stopped())throw aborted();}
  report(phase){this.onState({phase});}
  async runtime(account){
    this.assert();
    if(!this.runtimeTasks.has(account.id)){
      const task=Promise.resolve().then(()=>{this.assert();return this.createRuntime(account);}).then(async runtime=>{
        this.runtimes.set(account.id,runtime);
        if(this.cancelled||this.stopped()){runtime.cancelStartup?.();await runtime.stop();this.runtimes.delete(account.id);throw aborted();}
        return runtime;
      });
      this.runtimeTasks.set(account.id,task);
    }
    return this.runtimeTasks.get(account.id);
  }
  async selectedAccount(){
    const selected=await this.profiles.selected();let account=await this.profiles.account(selected.id);
    if(account.actorId===null){
      const runtime=await this.runtime(account);
      if(await runtime.isPrepared()){
        const trusted=await runtime.connection();await this.profiles.assertBinding(account.id,trusted);
        account=await this.profiles.account(account.id);
      }
    }
    return account;
  }
  async fresh(work){
    this.assert();const browser=this.createSession('go-erp-account-auth-'+crypto.randomUUID());this.sessions.add(browser);
    const controller=new AbortController();this.controllers.add(controller);
    const signal=AbortSignal.any([controller.signal,AbortSignal.timeout(120000)]);
    try{return await work(browser,signal);}finally{
      controller.abort();this.controllers.delete(controller);
      try{await withDeadline(()=>browser.clearStorageData?.(),5000,'تعذر إغلاق جلسة الدخول المؤقتة.');}catch{}
      this.sessions.delete(browser);
    }
  }
  async authenticate(input){
    if(this.running)throw Error('جارٍ تسجيل الدخول بالفعل.');
    if(!input||typeof input.email!=='string'||!input.email.trim()||input.email.length>320||/[\x00-\x1f\x7f]/.test(input.email)
      ||typeof input.password!=='string'||!input.password||input.password.length>4096||typeof input.remember!=='boolean')throw failure();
    this.sequence++;
    const credentials={email:input.email.trim(),password:input.password,remember:input.remember};
    this.running=this.login(credentials).catch(error=>{if(error.code==='ACCOUNT_LOGIN_RESTART_REQUIRED')this.cancel();throw error;})
      .finally(()=>{credentials.password='';this.running=null;});
    return this.running;
  }
  async localLogin(account,credentials){
    this.assert();const runtime=await this.runtime(account);
    if(!(await runtime.isPrepared()))return null;
    const connection=await runtime.connection();
    if(connection.actorId!==account.actorId||connection.serverOrigin!==account.serverOrigin)throw failure();
    this.assert();this.report('starting');const local=await runtime.start();this.assert();this.report('authenticating');
    return this.fresh(async(browser,signal)=>{
      const actor=await originalSignin({browser,origin:local.origin,localToken:local.token,...credentials,signal});this.assert();
      if(actor.actorId!==connection.actorId)throw failure();
      const commit=async serverBrowser=>{
        const target=this.createSession(account.partition),undo=[];
        try{
          undo.push(await installCookies(browser,target,local.origin,true,{active:()=>!this.cancelled&&!this.stopped()}));
          if(serverBrowser)undo.push(await installCookies(serverBrowser,target,connection.serverOrigin,false,{active:()=>!this.cancelled&&!this.stopped()}));
          await this.profiles.rememberLogin(account.id,{actorId:actor.actorId,serverOrigin:connection.serverOrigin},credentials.email);
          this.assert();await this.profiles.select(account.id);
        }catch(error){for(const rollback of undo.reverse())await rollback();throw error;}
        return {account,runtime,local:!serverBrowser,origin:serverBrowser?connection.serverOrigin:local.origin,localToken:serverBrowser?'':local.token};
      };
      if(await this.needsRemote(runtime))return this.fresh(async(serverBrowser,serverSignal)=>{
        const serverActor=await originalSignin({browser:serverBrowser,origin:connection.serverOrigin,...credentials,signal:serverSignal});
        this.assert();if(serverActor.actorId!==actor.actorId)throw failure();return commit(serverBrowser);
      });
      return commit(null);
    });
  }
  async needsRemote(runtime){
    const remote=await runtime.metadata.read('remote-writes'),central=await runtime.metadata.read('central-mode');
    if(remote&&(remote.format!==1||typeof remote.dirty!=='boolean'||!Array.isArray(remote.pending)))throw failure();
    return Boolean(remote&&(remote.dirty||remote.pending.length)||central);
  }
  async login(credentials){
    this.assert();this.report('authenticating');
    const matched=await this.profiles.findLogin(credentials.email,this.serverOrigin);
    if(matched){const result=await this.localLogin(matched,credentials);if(result)return result;}
    // The selected legacy profile may predate the encrypted alias registry.
    // Its own original login is the only offline fallback; foreign profiles
    // are never enumerated or tried with the submitted password.
    try{return await this.serverLogin(credentials);}
    catch(error){
      this.assert();if(matched||!networkFailure(error))throw error;
      const legacy=await this.selectedAccount();
      if(legacy.actorId!==null&&legacy.serverOrigin===this.serverOrigin){const result=await this.localLogin(legacy,credentials);if(result)return result;}
      throw error;
    }
  }
  async serverLogin(credentials){
    this.report('connecting');
    return this.fresh(async(browser,signal)=>{
      const actor=await originalSignin({browser,origin:this.serverOrigin,...credentials,signal});this.assert();
      await this.selectedAccount();
      let account=await this.profiles.forActor(actor.actorId,this.serverOrigin);
      if(account){const result=await this.localLogin(account,credentials);if(result)return result;}
      else{account=await this.profiles.create();await this.profiles.bind(account.id,{actorId:actor.actorId,serverOrigin:this.serverOrigin});account=await this.profiles.account(account.id);}
      const target=this.createSession(account.partition),undo=await installCookies(browser,target,this.serverOrigin,false,{active:()=>!this.cancelled&&!this.stopped()});
      try{
        await this.profiles.rememberLogin(account.id,{actorId:actor.actorId,serverOrigin:this.serverOrigin},credentials.email);
        this.assert();await this.profiles.select(account.id);
      }catch(error){await undo();throw error;}
      return {account,runtime:await this.runtime(account),local:false,origin:this.serverOrigin,localToken:''};
    });
  }
  async resumeRemembered(){
    if(this.running)return null;this.assert();const sequence=this.sequence;
    let account=await this.selectedAccount();
    if(account.serverOrigin!==null&&account.serverOrigin!==this.serverOrigin)return null;
    const browser=this.createSession(account.partition);
    const runtime=await this.runtime(account),prepared=await runtime.isPrepared();
    const remoteNeeded=!prepared||await this.needsRemote(runtime),cookies=await withDeadline(()=>browser.cookies.get({name:recaller}),5000,'تعذر قراءة جلسة الحساب.');
    if(!cookies.some(cookie=>authCookie(cookie,remoteNeeded?this.serverOrigin:'http://127.0.0.1:1',!remoteNeeded)&&cookie.name===recaller))return null;
    let local,connection;
    if(prepared){
      connection=await runtime.connection();if(connection.actorId!==account.actorId||connection.serverOrigin!==account.serverOrigin)throw failure();
      this.assert();this.report('starting');local=await runtime.start();this.assert();
    }
    const controller=new AbortController();this.controllers.add(controller);
    try{
      const destination=remoteNeeded?this.serverOrigin:local.origin;
      const options={headers:{Accept:'text/html',...(!remoteNeeded?{'X-Fasakhansta-Desktop':local.token}:{})},credentials:'include',redirect:'manual',signal:AbortSignal.any([controller.signal,AbortSignal.timeout(45000)])};
      let response=await browser.fetch(destination+'/admin/dashboard',options);
      for(let redirects=0;[301,302,303].includes(response.status)&&redirects<4;redirects++){
        const next=new URL(response.headers.get('location')||'',destination);await discard(response);
        if(next.origin!==destination||!homes.has(next.pathname)||next.search||next.hash)return null;
        response=await browser.fetch(next.href,options);
      }
      if(response.status!==200){await discard(response);return null;}
      const actor=actorFromHTML(await boundedHTML(response));
      if(account.actorId!==null&&actor.actorId!==account.actorId)throw failure();
      if(sequence!==this.sequence||this.running)return null;
      if(account.actorId===null){await this.profiles.bind(account.id,{actorId:actor.actorId,serverOrigin:this.serverOrigin});account=await this.profiles.account(account.id);}
      return {account,runtime,local:!remoteNeeded,origin:destination,localToken:remoteNeeded?'':local.token};
    }finally{controller.abort();this.controllers.delete(controller);}
  }
  async handoff(result){
    this.assert();
    await Promise.allSettled(this.runtimeTasks.values());this.assert();
    for(const [id,runtime] of this.runtimes)if(runtime!==result.runtime){await runtime.stop();this.runtimes.delete(id);}
    this.runtimes.delete(result.account.id);this.runtimeTasks.delete(result.account.id);return result;
  }
  cancel(){this.cancelled=true;for(const controller of this.controllers)controller.abort();for(const runtime of this.runtimes.values())try{runtime.cancelStartup?.();}catch{}}
  async stop(){
    this.cancel();await Promise.allSettled(this.runtimeTasks.values());
    const runtimes=[...this.runtimes.entries()],results=await Promise.allSettled(runtimes.map(([,runtime])=>Promise.resolve().then(()=>runtime.stop())));
    if(this.running)await this.running.catch(()=>{});
    const failures=[];for(let i=0;i<results.length;i++){if(results[i].status==='fulfilled'){this.runtimes.delete(runtimes[i][0]);this.runtimeTasks.delete(runtimes[i][0]);}else failures.push(results[i].reason);}
    if(failures.length)throw new AggregateError(failures,'تعذر إغلاق خدمة الحساب. احتفظ ببياناته وأعد المحاولة.');
    this.runtimeTasks.clear();
  }
}
module.exports={AccountLogin,originalSignin,actorFromHTML,csrfFromHTML,installCookies,authCookie,recaller,networkFailure};
