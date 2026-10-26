'use strict';
const {BrowserWindow,ipcMain}=require('electron');
const crypto=require('node:crypto');
const path=require('node:path');
const {pathToFileURL}=require('node:url');

/** One bounded native sign-in surface. Only original Laravel auth decides access. */
module.exports=function createLoginWindow({broker,quitting=()=>false,quit=()=>{}}){
  const nonce=crypto.randomBytes(32).toString('hex'),channel='go-erp-login:'+nonce;
  const file=path.join(__dirname,'login.html'),url=pathToFileURL(file).href;
  const allowed=new Set(['login.html','login.css','login.js','assets/go-erp.svg'].map(name=>pathToFileURL(path.join(__dirname,name)).href));
  const window=new BrowserWindow({width:1440,height:950,minWidth:1000,minHeight:650,show:false,fullscreen:true,
    title:'GO ERP — تسجيل الدخول',icon:path.join(__dirname,'assets','app.ico'),backgroundColor:'#071321',
    webPreferences:{preload:path.join(__dirname,'login-preload.cjs'),partition:'persist:go-erp-login-ui',
      additionalArguments:['--go-erp-login-token='+nonce],sandbox:true,contextIsolation:true,nodeIntegration:false,webSecurity:true}});
  window.removeMenu();window.webContents.on('page-title-updated',event=>event.preventDefault());
  window.webContents.setWindowOpenHandler(()=>({action:'deny'}));
  window.webContents.on('will-navigate',(event,destination)=>{if(destination!==url)event.preventDefault();});
  window.webContents.session.setPermissionRequestHandler((_sender,_permission,callback)=>callback(false));
  window.webContents.session.setPermissionCheckHandler(()=>false);
  window.webContents.session.webRequest.onBeforeRequest({urls:['<all_urls>']},(details,callback)=>callback({cancel:!allowed.has(details.url)}));
  let resolve,reject,finished=false,disposed=false,explicit=false,busy=false,ready=false;
  const result=new Promise((yes,no)=>{resolve=yes;reject=no;});
  // Main owns this promise immediately, including a close during the first GET.
  result.catch(()=>{});
  const previousState=broker.onState;
  broker.onState=event=>{
    try{previousState?.(event);}catch{}
    if(!disposed&&!window.isDestroyed()&&['starting','authenticating','connecting'].includes(event?.phase))window.webContents.send('go-erp-login-state',{phase:event.phase});
  };
  const detach=()=>{ipcMain.removeHandler(channel);broker.onState=previousState;};
  function finish(value){if(finished||disposed||quitting())return false;finished=true;detach();resolve(value);return true;}
  function cancel(){
    if(disposed)return;disposed=true;
    if(!finished){finished=true;reject(Object.assign(Error('تم إلغاء تسجيل الدخول.'),{code:'ACCOUNT_LOGIN_CANCELLED'}));}
    try{detach();}finally{try{broker.cancel();}finally{if(!window.isDestroyed())window.destroy();}}
  }
  function dispose(){if(disposed)return;disposed=true;detach();if(!window.isDestroyed())window.destroy();}
  function reveal(){if(!ready||disposed||window.isDestroyed()||quitting()||window.webContents.getURL()!==url)return;window.show();if(window.isMinimized())window.restore();window.focus();}
  ipcMain.handle(channel,async(event,input)=>{
    if(event.sender!==window.webContents||event.senderFrame!==window.webContents.mainFrame||event.senderFrame.url!==url||quitting()||disposed||finished||busy)return {ok:false,error:'تعذر تسجيل الدخول الآن.'};
    explicit=true;busy=true;
    try{const value=await broker.authenticate(input);return finish(value)?{ok:true}:{ok:false,error:'تم إلغاء تسجيل الدخول.'};}
    catch(error){return {ok:false,error:error?.code==='ACCOUNT_LOGIN_RESTART_REQUIRED'?'تعذر تأكيد حفظ جلسة الحساب. أغلق البرنامج ثم أعد فتحه.':error?.code==='ACCOUNT_CREDENTIALS_REJECTED'?'بيانات الدخول غير صحيحة. تأكد من اسم الحساب وكلمة المرور.':'تعذر تسجيل الدخول. الحساب الذي لم يُجهّز على هذا الجهاز يحتاج اتصالًا بالإنترنت أول مرة.'};}
    finally{busy=false;if(input&&typeof input==='object')input.password='';}
  });
  window.on('close',event=>{if(!disposed){event.preventDefault();try{cancel();}finally{if(!quitting())quit();}}});
  window.on('closed',()=>{if(!disposed){try{cancel();}finally{if(!quitting())quit();}}});
  const interactive=()=>{
    if(window.isDestroyed()||window.webContents.getURL()!==url)return;
    ready=true;window.webContents.removeListener('dom-ready',interactive);reveal();
    Promise.resolve().then(()=>broker.resumeRemembered()).then(value=>{if(value&&!explicit)finish(value);}).catch(()=>{});
  };
  window.webContents.on('dom-ready',interactive);
  window.loadURL(url).catch(error=>{if(!finished){finished=true;detach();reject(error);}cancel();if(!quitting())quit();});
  return {result,dispose,reveal,cancel};
};
