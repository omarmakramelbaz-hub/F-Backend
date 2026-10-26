'use strict';

// This account's existing DPAPI metadata records explicit sign-out independently
// of browser cookies. A late HTTP response cannot authorize remembered reentry.
const key='explicit-signout';
async function requirePassword(runtime){return await runtime.metadata.read(key)!==null;}
async function signedOut(runtime){await runtime.metadata.write(key,{format:1,kind:'go-erp-explicit-signout',requirePassword:true});}
async function signedIn(runtime,{active=()=>true}={}){
  const cancelled=()=>Object.assign(Error('تم إلغاء تسجيل الدخول.'),{code:'ACCOUNT_LOGIN_CANCELLED'});
  if(!active())throw cancelled();
  let blocked=true;try{blocked=await requirePassword(runtime);}catch{}
  if(!active())throw cancelled();
  if(!blocked)return;
  try{await runtime.metadata.write(key,null);if(!active())throw cancelled();}
  catch(error){
    // A canceled sign-in must not leave a late marker write authorizing a
    // future passive reopen. Restore password-required before reporting it.
    try{await signedOut(runtime);}catch{throw Object.assign(Error('تعذر تأكيد حالة تسجيل الدخول. أغلق البرنامج ثم أعد فتحه.'),{code:'ACCOUNT_LOGIN_RESTART_REQUIRED'});}
    throw error;
  }
}
module.exports={requirePassword,signedOut,signedIn};
