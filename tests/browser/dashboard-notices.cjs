const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const read=p=>fs.readFileSync(path.join(__dirname,'../..',p),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.route('**/*',route=>route.abort());
  await page.setContent('<html dir="rtl"><body class="dashboard-theme"><div role="status" class="is-success" id="saved">تم الحفظ</div><div role="alert" class="is-success" id="success-alert">تم التعديل</div><div role="alert" id="failed">تعذر الحفظ</div><div role="status" id="warning">راجع البيانات</div><button class="btn-success" id="approve">اعتماد وصرف</button><span data-status="approved" id="approved">تم الاعتماد</span></body></html>');
  await page.addStyleTag({content:read('public/dashboard/branding/dashboard-brand.css')});
  await page.addStyleTag({content:read('public/dashboard/plugins/toastr/toastr.min.css')});
  await page.addScriptTag({content:read('public/dashboard/plugins/jquery/jquery.min.js')});
  await page.addScriptTag({content:read('public/dashboard/plugins/toastr/toastr.min.js')});
  await page.evaluate(()=>{jQuery.fn.selectize=function(){return this;};jQuery.fn.rateYo=function(){return this;};});
  let footer=read('resources/views/admin/layouts/footer.blade.php').split('<script data-dashboard-page-init>').find(p=>p.includes('toastr.options.timeOut')).split('</script>')[0];
  footer=footer.replace(/@if \(Session::has\('([^']+)'\)\)([\s\S]*?)@endif/g,(_,key,body)=>body.replace(/{{ Session::get\('[^']+'\) }}/g,key+' fixture'));
  footer=footer.replace(/@if \(count\(\$errors\)\)[\s\S]*?@endif/g,'').replace(/{{ url\('\/change-language'\) }}/g,'/change-language');
  assert(!/@if|@foreach|{{/.test(footer));await page.addScriptTag({content:footer});
  await page.waitForSelector('#toast-container .toast-error');assert.equal(await page.locator('#toast-container .toast-success').count(),0,'Flash success/info must not produce green toasts');
  await page.evaluate(()=>{toastr.success('legacy success');toastr.warning('warning fixture');});
  assert(await page.locator('#toast-container .toast-success').isHidden());assert(await page.locator('#toast-container .toast-error').isVisible());assert(await page.locator('#toast-container .toast-warning').isVisible());
  for(const id of ['saved','success-alert'])assert(await page.locator('#'+id).isHidden());
  for(const id of ['failed','warning','approve','approved'])assert(await page.locator('#'+id).isVisible());
  assert.deepEqual(errors,[]);await page.close();console.log('Success notifications suppressed; errors, warnings, approval buttons and saved statuses remain visible.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
