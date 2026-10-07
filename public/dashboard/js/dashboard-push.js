(function(){
'use strict';
if(window.DashboardSPA&&!window.DashboardSPA.isCurrentPage())return;
var root=document.getElementById('dashboard-push');if(!root)return;
var labels=JSON.parse(document.getElementById('dashboard-push-labels').textContent),form=root.querySelector('[data-push-form]'),history=root.querySelector('[data-push-history]'),progress=root.querySelector('[data-push-progress]'),resume=root.querySelector('[data-push-resume]'),fresh=root.querySelector('[data-push-new]');
var id=null,timer,disposed=false,busy=false,frozen=null,epoch=0,csrf=form.elements._token.value;
function show(message){progress.hidden=false;progress.textContent=message;}
function fieldsDisabled(value){Array.from(form.elements).forEach(function(el){el.disabled=value;});}
function uuid(){return crypto.randomUUID?crypto.randomUUID(): '10000000-1000-4000-8000-100000000000'.replace(/[018]/g,function(c){return (c^crypto.getRandomValues(new Uint8Array(1))[0]&15>>c/4).toString(16);});}
function url(kind){return root.dataset[kind+'Url'].replace(/\/0(?=\/|$)/,'/'+id);}
async function request(target,body){var controller=new AbortController(),timeout=setTimeout(function(){controller.abort();},30000);try{var response=await fetch(target,{method:body?'POST':'GET',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body:body,signal:controller.signal}),data=await response.json();if(!response.ok){var error=Error(data.message||labels.connection_error);error.status=response.status;throw error;}return data;}finally{clearTimeout(timeout);}}
function syncHistory(){if(window.jQuery&&history.classList.contains('select2-hidden-accessible'))window.jQuery(history).trigger('change.select2');}
function render(c){clearTimeout(timer);id=c.id;history.value=String(id);syncHistory();show((labels['status_'+c.status]||c.status)+'\n'+c.message+(c.diagnostic?'\n'+c.diagnostic:'')+(c.issues.length?'\n'+c.issues.join('\n'):''));progress.style.whiteSpace='pre-line';resume.hidden=c.status!=='paused';fresh.hidden=false;fieldsDisabled(true);if(['queued','sending'].includes(c.status))timer=setTimeout(pump,1200);}
async function pump(){if(disposed||!id)return;if(busy){timer=setTimeout(pump,1200);return;}busy=true;var g=epoch;try{var c=await request(url('step'),new FormData());if(!disposed&&g===epoch)render(c);}catch(e){if(!disposed&&g===epoch){show(labels.connection_error);timer=setTimeout(pump,6000);}}finally{busy=false;}}
async function load(){clearTimeout(timer);if(!id)return;var g=++epoch;try{var c=await request(url('status'));if(!disposed&&g===epoch)render(c);}catch(e){if(!disposed&&g===epoch)show(e.message);}}
form.addEventListener('submit',async function(event){event.preventDefault();if(busy||id)return;frozen=frozen||new FormData(form);var payload=frozen,g=++epoch;busy=true;fieldsDisabled(true);show(labels.working);try{var data=await request(form.action,payload);if(disposed||g!==epoch)return;var option=document.createElement('option');option.value=data.campaign.id;option.textContent=String(payload.get('title'));history.prepend(option);render(data.campaign);}catch(e){if(!disposed&&g===epoch){if([403,409,419,422,503].includes(e.status)){frozen=null;fieldsDisabled(false);show(e.message);}else{show(labels.uncertain_request+' '+e.message);form.querySelector('[type="submit"]').disabled=false;}}}finally{busy=false;}});
history.addEventListener('change',function(){id=Number(history.value)||null;frozen=null;if(id){fieldsDisabled(true);load();}});
resume.addEventListener('click',async function(){if(busy||!id)return;busy=true;resume.disabled=true;var g=epoch;try{var c=await request(url('resume'),new FormData());if(!disposed&&g===epoch)render(c);}catch(e){if(!disposed)show(e.message);}finally{busy=false;resume.disabled=false;}});
fresh.addEventListener('click',function(){clearTimeout(timer);epoch++;id=null;frozen=null;history.value='';syncHistory();fieldsDisabled(false);form.elements.request_key.value=uuid();fresh.hidden=resume.hidden=progress.hidden=true;});
if(window.jQuery)window.jQuery(history).on('change.dashboardPush',function(e){if(!e.originalEvent)history.dispatchEvent(new Event('change',{bubbles:true}));});
var initial=Number(new URL(location.href).searchParams.get('campaign'));if(initial&&Array.from(history.options).some(function(o){return Number(o.value)===initial;})){id=initial;load();}
if(window.DashboardSPA)window.DashboardSPA.onCleanup(function(){disposed=true;clearTimeout(timer);epoch++;if(window.jQuery)window.jQuery(history).off('.dashboardPush');});
}());
