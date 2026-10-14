'use strict';
// Full production comments Blade + both browser modules, with a minimal admin shell.
// ORDER_EDITOR_TEST_PHP=/path/php WA_INBOX_TEST_VENDOR=/path/vendor
// PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/path/chromium "$CODEX_PRIMARY_RUNTIME_NODE" tests/meta_comment_ai_page_test.js
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),os=require('node:os'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES?path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES,'playwright'):'playwright');
const root=path.resolve(__dirname,'..'),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'comment-ai-page-'));
const compile=String.raw`
require $argv[3].'/autoload.php';
class PageUiContainer extends \Illuminate\Container\Container { public function getLocale(){return 'ar';} }
$app=new PageUiContainer(); \Illuminate\Container\Container::setInstance($app);
function app(){return \Illuminate\Container\Container::getInstance();}
function csrf_token(){return 'fixture-csrf';}
function route($name){return '/ai/'.($name==='meta-comment-ai.status'?'status':'update');}
function asset($path){return '/'.$path;}
function public_path($path){return $GLOBALS['fixtureRoot'].'/public/'.$path;}
$GLOBALS['fixtureRoot']=$argv[1];
mkdir($argv[2].'/admin',0777,true);
file_put_contents($argv[2].'/admin/index.blade.php','<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;background:#f5f7fa;font-family:Arial,sans-serif}.fixture-admin-header{height:64px;box-sizing:border-box;background:#172b3d;color:white;display:flex;align-items:center;justify-content:space-between;padding:0 24px;font-weight:bold}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}</style>@stack(\'custom-css\')</head><body><header class="fixture-admin-header"><span>فسخانستا</span><span>لوحة التحكم</span></header>@yield(\'content\')<script>window.DashboardSPA={isCurrentPage:()=>true,onCleanup:fn=>(window.fixtureCleanup||(window.fixtureCleanup=[])).push(fn)};</script>@stack(\'custom-js\')</body></html>');
$files=new \Illuminate\Filesystem\Filesystem();$compiler=new \Illuminate\View\Compilers\BladeCompiler($files,$argv[2]);$resolver=new \Illuminate\View\Engines\EngineResolver();
$resolver->register('blade',fn()=>new \Illuminate\View\Engines\CompilerEngine($compiler));
$factory=new \Illuminate\View\Factory($resolver,new \Illuminate\View\FileViewFinder($files,[$argv[2],$argv[1].'/resources/views']),new \Illuminate\Events\Dispatcher($app));
echo $factory->make('admin.meta.comments',['metaComments'=>['viewer_key'=>str_repeat('a',64),'accounts'=>[['account_key'=>str_repeat('b',64),'channel'=>'facebook','label'=>'صفحة فسخانستا']],'connection_url'=>'/connection','urls'=>['accounts'=>'/admin/meta/comments/accounts','posts'=>'/admin/meta/comments/posts','comments'=>'/admin/meta/comments/comments','replies'=>'/admin/meta/comments/replies','reply'=>'/admin/meta/comments/reply']]])->render();
`;
let html;try{html=execFileSync(process.env.ORDER_EDITOR_TEST_PHP||'php',['-r',compile,root,tmp,process.env.WA_INBOX_TEST_VENDOR||''],{encoding:'utf8'});}finally{fs.rmSync(tmp,{recursive:true,force:true});}
const key=letter=>letter.repeat(64),post={key:key('1'),handle:'post-1',text:'عرض فسخانستا اليوم · رنجة وفسيخ بكل الاختيارات',created_at:'2026-10-14T20:00:00Z',permalink:'https://www.facebook.com/fasakhansta/posts/123'},comment={key:key('3'),handle:'comment-3',text:'ممكن أعرف مواعيد الفرع وطريقة الطلب؟',author:'عميل فسخانستا',created_at:'2026-10-14T20:01:00Z',replies_count:0,can_reply:true};
const control={enabled:false,mode:'always',days:[1,2,3,4,5,6,7],start:'22:00',end:'02:00',timezone:'Africa/Cairo',revision:3,active_now:false,configured:true,worker_seen_at:null,enabled_at:null,last_reason:null};
const resultDir=path.join(root,'test-results/meta-comment-ai');fs.mkdirSync(resultDir,{recursive:true});
async function bounds(page,selector){return page.locator(selector).evaluate(el=>{const r=el.getBoundingClientRect();return {top:r.top,bottom:r.bottom,left:r.left,right:r.right,width:r.width,height:r.height,clientHeight:el.clientHeight,scrollHeight:el.scrollHeight};});}
(async()=>{
 const launch={headless:true};if(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH)launch.executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
 const browser=await chromium.launch(launch);try{
  for(const [name,viewport] of [['desktop',{width:1366,height:768}],['mobile',{width:390,height:844}],['mobile-short',{width:390,height:700}],['landscape',{width:844,height:390}]]){
   const shortHeight=viewport.height<=520;
   const page=await browser.newPage({viewport}),errors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));let releasePosts,postsReleased=false;
   await page.route('**/*',async route=>{
    const req=route.request(),url=new URL(req.url());requests.push(url.pathname);
    if(url.origin!=='http://localhost')return route.abort('blockedbyclient');
    if(url.pathname==='/comments')return route.fulfill({contentType:'text/html',body:html});
    if(/^\/(css|js)\/dashboard-meta-comment/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(path.join(root,'public',url.pathname))});
    if(url.pathname==='/ai/status')return route.fulfill({json:{success:true,can_manage:true,control}});
    if(url.pathname==='/admin/meta/comments/posts'){await new Promise(resolve=>{releasePosts=resolve;});postsReleased=true;return route.fulfill({json:{success:true,posts:[post],next_cursor:null}});}
    if(url.pathname==='/admin/meta/comments/comments')return route.fulfill({json:{success:true,comments:[comment],next_cursor:null}});
    if(url.pathname==='/admin/meta/comments/replies')return route.fulfill({json:{success:true,replies:[],next_cursor:null}});
    return route.fulfill({status:404,json:{success:false}});
   });
   await page.goto('http://localhost/comments',{waitUntil:'domcontentloaded'});
   await page.waitForFunction(()=>!document.querySelector('[data-mcai-toggle]').disabled);
   assert(releasePosts,'The production comments script has independently started its pending posts request');
   assert.equal(postsReleased,false,'Graph-facing posts are still pending when the AI controls become ready');
   const initialBar=await bounds(page,'#meta-comment-ai');assert(initialBar.top>=64&&initialBar.bottom<viewport.height,'AI controls are initially visible below the admin header');
   assert(!requests.includes('/admin/meta/comments/accounts'),'Server bootstrap avoids a serial accounts request');
   releasePosts();await page.locator('[data-mc-post]').click();await page.locator('[data-mc-reply]').click();
   await page.locator('[data-mc-reply-text]').fill('أهلًا بحضرتك، نورت فسخانستا.');
   assert.equal(await page.locator('[data-mc-send]').isDisabled(),false,'The manual composer remains usable with the AI bar');
   let composer=await bounds(page,'.mc-composer'),workspace=await bounds(page,'.mc-workspace');
   assert(workspace.height>=250,'Collapsed AI controls preserve a useful workspace');
   if(shortHeight)await page.locator('.mc-composer').scrollIntoViewIfNeeded();
   composer=await bounds(page,'.mc-composer');
   assert(composer.top>=64&&composer.bottom<=viewport.height,'The complete manual composer is reachable within the admin page');
   await page.locator('[data-mcai-schedule-toggle]').click();await page.locator('[data-mcai-mode]').selectOption('scheduled');
   const editor=await bounds(page,'[data-mcai-form]');composer=await bounds(page,'.mc-composer');workspace=await bounds(page,'.mc-workspace');
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);
   console.log(JSON.stringify({viewport:name,initialBar,expandedEditor:editor,workspace,composer,horizontalOverflow:overflow}));
   assert.equal(overflow,false,'The complete page fits the viewport width');
   assert.equal(await page.evaluate(()=>document.documentElement.scrollHeight>innerHeight+1),false,'The editor scrolls without overflowing the complete admin page');
   for(const field of await page.locator('[data-mcai-form] select,[data-mcai-form] input,[data-mcai-submit]').all()){
    await field.scrollIntoViewIfNeeded();
    const r=await field.boundingBox(),box=await bounds(page,'[data-mcai-form]');
    assert(r&&r.y>=box.top-1&&r.y+r.height<=box.bottom+1,'Every schedule control is reachable inside the bounded scrolling editor');
   }
   await page.screenshot({path:path.join(resultDir,`page-${name}.png`),fullPage:true});
   assert(workspace.height>=200,'Expanded schedule preserves the workspace');
   if(shortHeight)await page.locator('.mc-composer').scrollIntoViewIfNeeded();
   composer=await bounds(page,'.mc-composer');
   assert(composer.top>=64&&composer.bottom<=viewport.height,'Expanded schedule leaves the complete manual composer reachable');
   await page.locator('[data-mcai-toggle]').scrollIntoViewIfNeeded();
   const toggle=await bounds(page,'[data-mcai-toggle]');
   assert(toggle.top>=64&&toggle.bottom<=viewport.height,'The automation stop/start button remains reachable outside the scrolling editor');
   if(shortHeight)assert((await bounds(page,'main.meta-comments')).scrollHeight>(await bounds(page,'main.meta-comments')).clientHeight,'Short landscape uses an internal page scroll area');
   assert(!requests.includes('/ai/update'),'Page visits and opening the schedule never activate automation');
   assert.deepEqual(errors,[],'Both production scripts run without browser errors');
   await page.close();
  }
  console.log('meta-comment-ai full page: actual Blade, both production modules, independent controls while posts pending, desktop/mobile composer and schedule layout passed');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
