const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const base=path.join(__dirname,'../..'),read=p=>fs.readFileSync(path.join(base,p),'utf8');
const branches=[{value:'f:100',name:'فرع المحلة'},{value:'f:101',name:'فرع المنصورة'}];
const content=read('resources/views/admin/branch_stock/index.blade.php').split("@section('content')")[1].split('@endsection')[0].replace(/<select data-stock-branch[^>]*>[\s\S]*?<\/select>/,'<select data-stock-branch><option value="all">جميع الفروع</option>'+branches.map(b=>'<option value="'+b.value+'">'+b.name+'</option>').join('')+'</select>');
const initial={success:true,all_branches:true,branch:{value:'all',name:'جميع الفروع'},can_manage_minimum:true,minimum_alerts_available:true,items:[{id:1,name:'فسيخ كيلو 4 سمكات',unit:'kg',branch:'f:100',branch_name:'فرع المحلة',stock:{quantity:'5.000',unit:'kg',unit_label:'كجم',minimum_quantity:'5.000',minimum_revision:1,low_stock:true,stock_type:'ingredient'}},{id:2,name:'رنجة بطارخ',unit:'kg',branch:'f:101',branch_name:'فرع المنصورة',stock:{quantity:'43.600',unit:'kg',unit_label:'كجم',minimum_quantity:'10.000',minimum_revision:1,low_stock:false,stock_type:'ingredient'}},{id:3,name:'علبة فسيخ صغيرة',unit:'piece',branch:'f:100',branch_name:'فرع المحلة',stock:{quantity:'0',unit:'piece',unit_label:'قطعة',minimum_quantity:'0',minimum_revision:1,low_stock:true,stock_type:'ingredient'}}],history:[],legacy:[],unmapped_sales:[],pagination:{page:1,last_page:1,total:3}};
const boot={actor_id:1,can_all:true,branches,branch:'all',initial,urls:{data:'/data',receive:'/receive',recover:'/recover',minimum_save:'/minimum'}};
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CRM_TEST_CHROMIUM||undefined,args:['--no-sandbox','--disable-dev-shm-usage']}),errors=[];
 try{
  const page=await browser.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.route('http://localhost/**',route=>route.fulfill({contentType:'text/html',body:'<meta charset="utf-8"><meta name="csrf-token" content="test"><style>body{margin:0;background:#f4f7fb;font-family:Arial,sans-serif}.shell{height:55px;background:#091626}.sidebar{position:fixed;right:0;top:55px;bottom:0;width:200px;background:#101f30;color:#fff;padding:20px;box-sizing:border-box;text-align:right}.content-wrapper{margin-right:200px}*{box-sizing:border-box}button,input,select,textarea{font-family:inherit}'+read('public/dashboard/css/branch-stock.css')+'</style><body class="dashboard-theme"><div class="shell"></div><aside class="sidebar">فسخانستا<br><br>إضافة بضاعة</aside>'+content+'<script id="branch-stock-bootstrap" type="application/json">'+JSON.stringify(boot)+'</script></body>'}));
  for(const width of [1366,1024]){
   await page.setViewportSize({width,height:768});await page.goto('http://localhost/stock');await page.addScriptTag({content:read('public/dashboard/js/branch-stock.js')});
   assert.equal(await page.locator('.bs-low-stock').count(),2);assert.equal(await page.locator('.bs-low-stock .bs-item').first().evaluate(n=>getComputedStyle(n).color),'rgb(180, 35, 46)');assert.equal(await page.locator('.bs-low-stock').first().evaluate(n=>getComputedStyle(n).backgroundColor),'rgb(255, 241, 242)');
   await page.locator('.bs-minimum-toggle').first().click();assert(await page.locator('.bs-minimum-editor input').isVisible());assert(await page.locator('.bs-minimum-save').isEnabled());
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'minimum editor must not overflow the page at '+width);
   const table=await page.locator('.bs-balances .bs-table-wrap').boundingBox(),save=await page.locator('.bs-minimum-save').boundingBox();assert(save.x>=table.x&&save.x+save.width<=table.x+table.width,'minimum save is visible within stock table at '+width);
   if(width===1366&&process.env.STOCK_MINIMUM_SCREENSHOT)await page.screenshot({path:process.env.STOCK_MINIMUM_SCREENSHOT,fullPage:true});
  }
  assert.deepEqual(errors,[]);console.log('STOCK_MINIMUM_LAYOUT_OK widths=1366,1024');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
