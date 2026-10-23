'use strict';
const crypto=require('node:crypto');

const PAGES={messages:'/admin/meta',comments:'/admin/meta/comments'};
const number='[1-9][0-9]{0,18}';
const READS=[/^\/admin\/(?:meta|whatsapp)(?:\/conversations|\/unread)?$/,
  new RegExp('^/admin/(?:meta|whatsapp)/conversations/'+number+'/(?:messages|reply-state)$'),
  new RegExp('^/admin/meta/conversations/'+number+'/messages/'+number+'/media/[0-9]{1,6}$'),
  new RegExp('^/admin/whatsapp/conversations/'+number+'/messages/(?:selection|updates)$'),
  new RegExp('^/admin/whatsapp/conversations/'+number+'/messages/'+number+'/media$'),
  new RegExp('^/admin/whatsapp/conversations/'+number+'/replies/(?:history|'+number+'/(?:media|attachment))$'),
  /^\/admin\/meta\/comments(?:\/(?:accounts|posts|comments|replies|ai\/settings))?$/,
  /^\/admin\/meta\/whatsapp$/];
const WRITES=[new RegExp('^/admin/(?:meta|whatsapp)/conversations/'+number+'/(?:read|replies|voice|attachments?|forward)$'),
  new RegExp('^/admin/meta/conversations/'+number+'/profile$'),
  new RegExp('^/admin/meta/conversations/'+number+'/messages/'+number+'/hide$'),
  new RegExp('^/admin/whatsapp/conversations/'+number+'/messages/(?:delete|'+number+'/(?:delete|forward|reaction))$'),
  new RegExp('^/admin/whatsapp/conversations/'+number+'/replies/'+number+'/(?:forward|reaction)$'),
  /^\/admin\/meta\/comments\/reply$/];
const CDN=new Set(['code.ionicframework.com','cdn.jsdelivr.net','cdnjs.cloudflare.com','fonts.googleapis.com','fonts.gstatic.com','www.gstatic.com','cdn.ckeditor.com','unpkg.com','js.pusher.com']);
const STATIC_TYPES=new Set(['stylesheet','script','font','image','media']);
const NEEDS_LOGIN='الرسائل والتعليقات تحتاج اتصالًا بالإنترنت وحساب السيرفر المرتبط. احفظ مسودتك، ثم جدّد تسجيل دخول السيرفر من قائمة البرنامج وأغلق نافذة الرسائل وأعد فتحها.';
const NEEDS_INTERNET='الرسائل والتعليقات تحتاج اتصالًا بالإنترنت. عمل الجهاز المحلي مستمر. لن تُرسل رسالة تلقائيًا عند عودة الاتصال.';
const fixedError=(message,code)=>Object.assign(Error(message),{code});
const enrollment=value=>{
  let url;try{url=new URL(value.serverOrigin);}catch{throw Error(NEEDS_LOGIN);}
  if(url.protocol!=='https:'||url.origin!==value.serverOrigin||url.username||url.password
    ||!/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value.deviceId||'')
    ||!Number.isSafeInteger(value.actorId)||value.actorId<1||!/^[a-f0-9]{64}$/.test(value.token||''))throw Error(NEEDS_LOGIN);
  return JSON.stringify([value.serverOrigin,value.deviceId,value.actorId,value.token]);
};
function route(url,method,serverOrigin,type){
  if(url.protocol!=='https:'||url.username||url.password||url.hash)return null;
  if(!['GET','HEAD','POST'].includes(method))return null;
  if(url.origin!==serverOrigin)return ['GET','HEAD'].includes(method)&&STATIC_TYPES.has(type)
    &&(CDN.has(url.hostname)||type==='image'&&/(?:^|\.)(?:fbcdn\.net|cdninstagram\.com)$/.test(url.hostname))?'asset':null;
  // Encoded aliases and path separators cannot turn a static or social route into an ERP action.
  const pathname=url.pathname.replace(/\/$/,'');
  if(/%(?:2f|5c|2e|25)/i.test(pathname)||pathname.includes('\\'))return null;
  if(method==='GET'&&pathname==='/admin/desktop-dashboard/session')return 'probe';
  if(['GET','HEAD'].includes(method)&&READS.some(rule=>rule.test(pathname)))return 'social';
  if(method==='POST'&&WRITES.some(rule=>rule.test(pathname)))return 'social';
  if(['GET','HEAD'].includes(method)&&STATIC_TYPES.has(type)&&/^\/(?:dashboard|css|js|fonts|images|storage)\//.test(pathname))return 'asset';
  return null;
}
function cleanHeaders(input,foreign=false){
  const headers={...input};
  for(const name of Object.keys(headers))if(/^x-fasakhansta-/i.test(name)||/^authorization$/i.test(name)
    ||foreign&&/^(?:cookie|origin|referer)$/i.test(name))delete headers[name];
  return headers;
}

/** Internet-only social pages have their own cookie session and never select central ERP mode. */
class DashboardLiveSocial{
  constructor({runtime,sourceSession,createSession,createWindow,quitting=()=>false,notify=()=>{},confirmMicrophone=async()=>false}){
    Object.assign(this,{runtime,sourceSession,createSession,createWindow,quitting,notify,confirmMicrophone});this.window=null;this.opening=null;this.context=null;
  }
  busy(){return Boolean(this.window||this.opening);}
  async authorize(details,context=this.context){
    if(!context||context.closed||this.quitting())return {cancel:true};
    let url;try{url=new URL(details.url);}catch{return {cancel:true};}
    const method=String(details.method||'GET').toUpperCase(),kind=route(url,method,context.credential.serverOrigin,details.resourceType);
    const headers=cleanHeaders(details.requestHeaders,url.origin!==context.credential.serverOrigin);
    if(!kind)return {cancel:true};
    if(kind==='probe'){
      if(!context.probing||details.webContentsId>0)return {cancel:true};
      headers.Authorization='Bearer '+context.credential.token;return {requestHeaders:headers};
    }
    if(!this.window||details.webContentsId!==this.window.webContents.id)return {cancel:true};
    if(kind==='asset')return context.verified?{requestHeaders:headers}:{cancel:true};
    try{
      if(method==='POST'||!context.verified)await this.verify(context);
      const current=await this.runtime.connection();
      if(enrollment(current)!==context.enrollment)throw fixedError(NEEDS_LOGIN,'LIVE_LOGIN_REQUIRED');
      if(context.closed||this.context!==context||this.quitting()||!this.window||this.window.isDestroyed()
        ||details.webContentsId!==this.window.webContents.id)return {cancel:true};
      // The server checks the actual cookie actor on this same request, including after cookie expiry.
      headers['X-Fasakhansta-Connected-Device']=current.deviceId;
      headers['X-Fasakhansta-Connected-Token']=current.token;
      return {requestHeaders:headers};
    }catch(error){context.verified=false;if(!context.closed&&this.context===context)this.notice(error.code==='LIVE_NETWORK_UNAVAILABLE'?NEEDS_INTERNET:NEEDS_LOGIN);return {cancel:true};}
  }
  async copyCookies(context){
    const source=this.sourceSession();if(!source?.cookies)throw Error(NEEDS_LOGIN);
    const origin=context.credential.serverOrigin,host=new URL(origin).hostname;
    const cookies=await source.cookies.get({url:origin});
    if(cookies.length>128)throw Error(NEEDS_LOGIN);
    for(const cookie of cookies){
      if(String(cookie.domain||'').replace(/^\./,'')!==host||typeof cookie.name!=='string'||typeof cookie.value!=='string'
        ||cookie.name.length>256||cookie.value.length>16384||!/^\/(?!\/)/.test(cookie.path||'/')||/[?#]/.test(cookie.path||''))continue;
      const target={url:origin+(cookie.path||'/'),name:cookie.name,value:cookie.value,path:cookie.path||'/',
        secure:true,httpOnly:Boolean(cookie.httpOnly)};
      if(!cookie.hostOnly)target.domain=cookie.domain;
      if(['unspecified','no_restriction','lax','strict'].includes(cookie.sameSite))target.sameSite=cookie.sameSite;
      if(!cookie.session&&Number.isFinite(cookie.expirationDate))target.expirationDate=cookie.expirationDate;
      await context.session.cookies.set(target);
    }
  }
  async verify(context){
    if(context.verifying)return context.verifying;
    context.verifying=this.probe(context).finally(()=>{context.verifying=null;});return context.verifying;
  }
  async probe(context){
    context.probing=true;
    try{
      const response=await context.session.fetch(context.credential.serverOrigin+'/admin/desktop-dashboard/session',{
        credentials:'include',headers:{Accept:'application/json',Authorization:'Bearer '+context.credential.token},redirect:'error',signal:AbortSignal.any([context.controller.signal,AbortSignal.timeout(12000)])});
      if(!response.ok)throw fixedError(NEEDS_LOGIN,'LIVE_LOGIN_REQUIRED');
      const proof=await response.json();
      if(context.closed||this.context!==context||this.quitting())throw fixedError(NEEDS_LOGIN,'LIVE_LOGIN_REQUIRED');
      if(proof.actor_id!==context.credential.actorId||proof.device_id!==context.credential.deviceId||proof.connected_request_protocol!==1)throw fixedError(NEEDS_LOGIN,'LIVE_LOGIN_REQUIRED');
      if(enrollment(await this.runtime.connection())!==context.enrollment)throw fixedError(NEEDS_LOGIN,'LIVE_LOGIN_REQUIRED');
      if(context.closed||this.context!==context||this.quitting())throw fixedError(NEEDS_LOGIN,'LIVE_LOGIN_REQUIRED');
      context.verified=true;
    }catch(error){
      context.verified=false;if(error.code==='LIVE_LOGIN_REQUIRED')throw error;
      if(/ERR_(?:INTERNET|CONNECTION|NETWORK|NAME_NOT_RESOLVED|TIMED_OUT|ADDRESS_UNREACHABLE)|Failed to fetch|fetch failed/i.test(error.message||'')
        ||['TypeError','TimeoutError'].includes(error.name))throw fixedError(NEEDS_INTERNET,'LIVE_NETWORK_UNAVAILABLE');
      throw fixedError(NEEDS_LOGIN,'LIVE_LOGIN_REQUIRED');
    }finally{context.probing=false;}
  }
  notice(message=NEEDS_INTERNET){
    const window=this.window;if(!window||window.isDestroyed())return;
    window.webContents.executeJavaScript(`(()=>{let note=document.getElementById('go-live-internet-note');if(!note){note=document.createElement('p');note.id='go-live-internet-note';note.setAttribute('role','status');note.style.cssText='position:fixed;inset:0 0 auto;z-index:2147483647;margin:0;padding:14px;background:#fff3cd;color:#664d03;text-align:center;direction:rtl';document.body.append(note);}note.textContent=${JSON.stringify(message)};})()`).catch(()=>{});
  }
  async offline(context){
    if(context.closed||this.context!==context||!this.window)return;
    if(context.loaded){this.notice();return;}
    const target=context.credential.serverOrigin+PAGES[context.kind];
    const html='<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'"><title>GO ERP — الاتصال بالإنترنت</title><body style="font-family:Arial;padding:48px;background:#f4f5f8;color:#24324b"><h1>الرسائل والتعليقات</h1><p>'+NEEDS_INTERNET+'</p><p>بعد رجوع الإنترنت، اضغط إعادة الاتصال. عمليات البيع والمصروفات والمخزون مستمرة في نافذة الجهاز.</p><a href="'+target+'">إعادة الاتصال</a></body></html>';
    context.offlineURL='data:text/html;charset=utf-8,'+encodeURIComponent(html);
    try{await this.window.loadURL(context.offlineURL);this.window.show();}catch{/* The main local dashboard remains available. */}
  }
  open(kind='messages'){
    if(!Object.hasOwn(PAGES,kind))return Promise.reject(Error('صفحة الرسائل المطلوبة غير متاحة.'));
    if(this.opening)return this.opening;
    this.opening=this.start(kind).finally(()=>{this.opening=null;});return this.opening;
  }
  async start(kind){
    if(this.quitting()||!(await this.runtime.isPrepared()))throw Error('جهّز نسخة الجهاز أولًا ثم افتح الرسائل أو التعليقات المتصلة.');
    if(this.window&&!this.window.isDestroyed()){
      if(enrollment(await this.runtime.connection())!==this.context.enrollment){this.close();throw Error(NEEDS_LOGIN);}
      if(this.context.kind!==kind){
        this.notify('نافذة الرسائل أو التعليقات مفتوحة بالفعل. أغلقها بعد حفظ مسودتك ثم افتح القسم الآخر.');
        this.window.show();this.window.focus();return {live:true,kind:this.context.kind};
      }
      this.window.show();this.window.focus();return {live:true,kind};
    }
    const credential=await this.runtime.connection(),key=enrollment(credential);
    const session=this.createSession('go-erp-live-social-'+crypto.randomUUID());
    const context={credential,enrollment:key,session,kind,closed:false,loaded:false,verified:false,probing:false,controller:new AbortController()};this.context=context;
    session.setPermissionCheckHandler?.((sender,permission,requestingOrigin,details)=>Boolean(context.microphone&&context.verified&&!context.closed&&this.context===context&&!this.quitting()
      &&this.window?.webContents===sender&&permission==='media'&&requestingOrigin===credential.serverOrigin&&details?.mediaType==='audio'));
    session.setPermissionRequestHandler(async(sender,permission,callback,details={})=>{
      let allowed=false;
      try{
        const requesting=new URL(details.requestingUrl||sender.getURL()),page=new URL(sender.getURL());
        if(permission==='media'&&details.mediaTypes?.length===1&&details.mediaTypes[0]==='audio'&&context.verified&&!context.closed
          &&this.window?.webContents===sender&&requesting.origin===credential.serverOrigin&&page.origin===credential.serverOrigin
          &&page.pathname==='/admin/meta'&&!this.quitting()){
          await this.verify(context);
          const accepted=context.microphone||await this.confirmMicrophone(this.window);
          const current=await this.runtime.connection();
          const pageNow=new URL(sender.getURL());
          if(accepted&&!context.closed&&this.context===context&&!this.quitting()&&this.window?.webContents===sender
            &&pageNow.origin===credential.serverOrigin&&pageNow.pathname==='/admin/meta'&&enrollment(current)===context.enrollment){context.microphone=true;allowed=true;}
        }
      }catch{/* Failed identity or cancelled permission never grants capture. */}
      callback(allowed);
    });
    session.webRequest.onBeforeSendHeaders({urls:['<all_urls>']},(details,callback)=>{
      this.authorize(details,context).then(result=>callback(context.closed||this.context!==context||this.quitting()?{cancel:true}:result),()=>callback({cancel:true}));
    });
    session.webRequest.onErrorOccurred({urls:['<all_urls>']},details=>{if(this.window&&details.webContentsId===this.window.webContents.id&&/ERR_(?:INTERNET|CONNECTION|NETWORK|NAME_NOT_RESOLVED|TIMED_OUT|ADDRESS_UNREACHABLE)/.test(details.error||''))this.offline(context);});
    try{
      await this.copyCookies(context);
      if(this.quitting()||context.closed||this.context!==context)throw Error(NEEDS_LOGIN);
      const window=this.createWindow({width:1440,height:950,minWidth:1000,minHeight:650,show:false,title:'GO ERP — الرسائل والتعليقات المتصلة',
        webPreferences:{session,sandbox:true,contextIsolation:true,nodeIntegration:false,webSecurity:true}});this.window=window;
      const contents=window.webContents;
      contents.setWindowOpenHandler(()=>({action:'deny'}));
      const navigate=(event,url)=>{
        let target;try{target=new URL(url);}catch{event.preventDefault();return;}
        if(context.offlineURL&&contents.getURL()===context.offlineURL&&target.origin===credential.serverOrigin&&target.pathname===PAGES[context.kind]){
          event.preventDefault();
          if(!context.retrying)context.retrying=(async()=>{try{await this.verify(context);if(!context.closed&&!this.quitting())await window.loadURL(credential.serverOrigin+PAGES[context.kind]);}
            catch(error){if(error.code==='LIVE_NETWORK_UNAVAILABLE')await this.offline(context);else this.notice(NEEDS_LOGIN);}})().finally(()=>{context.retrying=null;});
          return;
        }
        if(url!==context.offlineURL&&!(target.protocol==='https:'&&!target.username&&!target.password&&target.origin===credential.serverOrigin&&Object.values(PAGES).includes(target.pathname.replace(/\/$/,'')))){
          event.preventDefault();this.notify('هذه النافذة للرسائل والتعليقات المتصلة. نفّذ عمليات الداشبورد من نافذة الجهاز المحلية.');
        }
      };
      contents.on('will-navigate',navigate);contents.on('will-redirect',navigate);
      contents.on('did-finish-load',()=>{
        if(contents.getURL()===context.offlineURL)return;
        context.loaded=true;
        // Keep existing drafts on connectivity loss. Reload is always an explicit user action, never a replay of POST.
        contents.executeJavaScript(`(()=>{const mark=()=>{let note=document.getElementById('go-live-internet-note');if(!note){note=document.createElement('p');note.id='go-live-internet-note';note.setAttribute('role','status');note.style.cssText='background:#fff3cd;color:#664d03;padding:12px;direction:rtl';document.body.prepend(note);}note.textContent=${JSON.stringify(NEEDS_INTERNET)};};window.addEventListener('offline',mark);window.addEventListener('online',()=>{const note=document.getElementById('go-live-internet-note');if(note)note.textContent='عاد الاتصال. حدّث المحادثة أو التعليقات عند الحاجة؛ لم تُرسل أي رسالة تلقائيًا.';});const style=document.createElement('style');style.textContent='[data-wa-order-drawer],#meta-ai-controls,#meta-comment-ai,a[href*="/admin/meta/connection"]{display:none!important}';document.head.append(style);const note=document.createElement('p');note.textContent='الرسائل والتعليقات مباشرة عبر الإنترنت. الطلبات والمصروفات والمخزون من نافذة الجهاز وتُزامن تلقائيًا.';note.style.cssText='padding:8px 16px;background:#e8f4ff;color:#24324b;direction:rtl';document.querySelector('.content-wrapper')?.prepend(note);})()`).catch(()=>{});
      });
      window.on('closed',()=>{if(this.window===window)this.window=null;this.dispose(context);});
      try{await this.verify(context);await window.loadURL(credential.serverOrigin+PAGES[kind]);window.show();window.focus();}
      catch(error){if(error.code==='LIVE_LOGIN_REQUIRED')throw error;await this.offline(context);}
      return {live:true,kind};
    }catch(error){if(this.window&&!this.window.isDestroyed()){const window=this.window;this.window=null;window.destroy();}await this.dispose(context);throw error;}
  }
  async dispose(context){
    context.closed=true;context.controller.abort();
    if(this.context===context)this.context=null;
    try{await context.session.clearStorageData();}catch{/* This isolated, nonpersistent session is never reused. */}
  }
  close(){const window=this.window;this.window=null;if(window&&!window.isDestroyed())window.destroy();if(this.context)this.dispose(this.context);}
}
module.exports={DashboardLiveSocial,route,cleanHeaders,enrollment,PAGES,NEEDS_INTERNET,NEEDS_LOGIN};
