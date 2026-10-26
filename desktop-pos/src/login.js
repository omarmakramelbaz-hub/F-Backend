'use strict';
const form=document.getElementById('login'),email=document.getElementById('email'),password=document.getElementById('password'),remember=document.getElementById('remember'),button=document.getElementById('submit'),error=document.getElementById('error'),status=document.getElementById('status');
let pending=false;
try{email.value=localStorage.getItem('go-erp.login-name')||'';}catch{}
window.GOERPLogin.onState(value=>{
  if(!pending)return;
  const messages={starting:'جارٍ فتح بيانات الحساب على الجهاز…',authenticating:'جارٍ التحقق من بيانات الدخول…',connecting:'جارٍ الاتصال بالسيرفر لتسجيل الحساب…'};
  if(messages[value?.phase]){status.textContent=messages[value.phase];status.hidden=false;}
});
const showPassword=document.getElementById('show-password');
function clearPassword(){password.value='';password.type='password';showPassword.setAttribute('aria-pressed','false');showPassword.setAttribute('aria-label','إظهار كلمة المرور');}
showPassword.addEventListener('click',()=>{const visible=password.type==='password';password.type=visible?'text':'password';showPassword.setAttribute('aria-pressed',String(visible));showPassword.setAttribute('aria-label',visible?'إخفاء كلمة المرور':'إظهار كلمة المرور');});
window.addEventListener('pagehide',clearPassword);
form.addEventListener('submit',async event=>{
  event.preventDefault();if(pending)return;
  pending=true;error.hidden=true;status.hidden=false;status.textContent='جارٍ تسجيل الدخول…';button.disabled=true;button.textContent='جارٍ تسجيل الدخول…';
  const input={email:email.value.trim(),password:password.value,remember:remember.checked};
  clearPassword();
  let success=false;
  try{
    try{localStorage.setItem('go-erp.login-name',input.email);}catch{}
    const result=await window.GOERPLogin.authenticate(input);
    if(!result?.ok){error.textContent=result?.error||'تعذر تسجيل الدخول. أعد المحاولة.';error.hidden=false;}
    else{success=true;status.textContent='تم تسجيل الدخول، جارٍ فتح الحساب…';}
  }catch{error.textContent='تعذر تسجيل الدخول. أعد المحاولة.';error.hidden=false;}
  finally{input.password='';if(!success){pending=false;button.disabled=false;button.textContent='تسجيل الدخول';status.hidden=true;}}
});
