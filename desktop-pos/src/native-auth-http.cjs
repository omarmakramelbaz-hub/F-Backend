'use strict';
const homes=new Set(['/admin/dashboard','/admin/applies-orders','/admin/resturants']);
const invalid=()=>Object.assign(Error('تعذر تأكيد مسار تسجيل الدخول.'),{code:'ACCOUNT_AUTH_REDIRECT_REJECTED'});
const cancelled=()=>Object.assign(Error('تم إلغاء تسجيل الدخول.'),{code:'ACCOUNT_LOGIN_CANCELLED'});
function authURL(value,origin){
  const url=new URL(value);
  if(url.origin!==origin||url.username||url.password||url.search||url.hash
    ||!['https:','http:'].includes(url.protocol))throw invalid();
  return url;
}
function redirectAllowed({origin,target,method,statusCode,previousMethod,allowRedirects=true}){
  if(!allowRedirects||![301,302,303,307,308].includes(statusCode)||!['GET','HEAD'].includes(String(method).toUpperCase())
    ||previousMethod==='POST'&&[307,308].includes(statusCode))return false;
  try{const url=authURL(target,origin);return url.pathname==='/admin/login'||homes.has(url.pathname);}catch{return false;}
}

/** Bounded original-auth HTTP through Electron's documented manual redirect event. */
function nativeAuthHTML({requestFactory,browser,origin,url,method='GET',headers={},body,signal,readOnly=false,allowRedirects=true}){
  return new Promise((resolve,reject)=>{
    let currentURL,currentMethod=String(method).toUpperCase();
    try{
      const parsed=authURL(url,origin);currentURL=parsed.href;
      const read=['GET','HEAD'].includes(currentMethod)&&(parsed.pathname==='/admin/login'||homes.has(parsed.pathname));
      const signin=!readOnly&&currentMethod==='POST'&&parsed.pathname==='/admin/signin';
      if(!read&&!signin||body!==undefined&&(typeof body!=='string'||Buffer.byteLength(body)>64*1024||!signin))throw invalid();
      if(signal?.aborted)throw cancelled();
    }catch(error){reject(error);return;}
    let request,settled=false,redirects=0,responseEnded=false;
    const timer=setTimeout(()=>stop(Object.assign(Error('تجاوز تسجيل الدخول الوقت المسموح.'),{code:'ACCOUNT_AUTH_TIMED_OUT'})),120000);
    const cleanup=()=>{clearTimeout(timer);signal?.removeEventListener('abort',abort);};
    const finish=(error,value)=>{if(settled)return;settled=true;cleanup();error?reject(error):resolve(value);};
    function stop(error){if(settled)return;finish(error);try{request?.abort();}catch{}}
    function abort(){stop(cancelled());}
    signal?.addEventListener('abort',abort,{once:true});
    try{
      request=requestFactory({url:currentURL,method:currentMethod,headers,session:browser,useSessionCookies:true,
        credentials:'include',redirect:'manual',cache:'no-store',origin});
      request.on('login',(_information,callback)=>callback());
      request.on('redirect',(statusCode,nextMethod,target)=>{
        if(settled||signal?.aborted||++redirects>4||!redirectAllowed({origin,target,method:nextMethod,statusCode,previousMethod:currentMethod,allowRedirects})){stop(invalid());return;}
        currentURL=new URL(target).href;currentMethod=String(nextMethod).toUpperCase();
        // With redirect:'manual', Chromium cannot transmit this redirect until
        // this exact synchronous approval calls followRedirect().
        try{request.followRedirect();}catch(error){stop(error);}
      });
      request.on('response',response=>{
        const parts=[];let size=0;
        response.on('data',chunk=>{
          if(settled)return;const bytes=Buffer.from(chunk);size+=bytes.length;
          if(size>4*1024**2){stop(invalid());return;}parts.push(bytes);
        });
        response.on('error',error=>stop(error));
        response.on('aborted',()=>stop(cancelled()));
        response.on('end',()=>{
          responseEnded=true;if(settled)return;
          const text=Buffer.concat(parts,size).toString('utf8'),raw=response.headers||{};
          finish(null,{status:response.statusCode,url:currentURL,
            headers:{get(name){const entry=Object.entries(raw).find(([key])=>key.toLowerCase()===String(name).toLowerCase());return entry?(Array.isArray(entry[1])?entry[1].join(', '):String(entry[1])):null;}},
            text:async()=>text});
        });
      });
      request.on('error',error=>stop(error));
      request.on('abort',()=>stop(cancelled()));
      request.on('close',()=>{if(!responseEnded&&!settled)stop(failure());});
      if(signal?.aborted){abort();return;}
      request.end(body);
    }catch(error){stop(error);}
  });
}
function failure(){return Error('تعذر قراءة رد تسجيل الدخول.');}
module.exports={nativeAuthHTML,redirectAllowed};
