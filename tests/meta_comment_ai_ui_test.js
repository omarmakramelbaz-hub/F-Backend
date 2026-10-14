// Actual standalone Blade controls + production JavaScript in Chromium; no Graph/AI calls.
// ORDER_EDITOR_TEST_PHP=/path/php WA_INBOX_TEST_VENDOR=/path/vendor PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/path/chromium
// "$CODEX_PRIMARY_RUNTIME_NODE" tests/meta_comment_ai_ui_test.js
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),os=require('node:os'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES?path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES,'playwright'):'playwright');
const root=path.resolve(__dirname,'..'),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'comment-ai-ui-'));
const compile=String.raw`
require $argv[3].'/autoload.php';
class UiContainer extends \Illuminate\Container\Container { public function getLocale(){return 'ar';} }
$app=new UiContainer(); \Illuminate\Container\Container::setInstance($app);
function app(){return \Illuminate\Container\Container::getInstance();}
function csrf_token(){return 'fixture-csrf';}
function route($name){return $name==='meta-comment-ai.status'?'/ai/status':'/ai/update';}
$source=file_get_contents($argv[1].'/resources/views/admin/meta/comments.blade.php');
$start=strpos($source,'@php');$end=strpos($source,'@endphp',$start)+strlen('@endphp');$header=substr($source,$start,$end-$start);
$start=strpos($source,'<section id="meta-comment-ai"');$end=strpos($source,'</section>',$start)+strlen('</section>');
file_put_contents($argv[2].'/bar.blade.php',$header.substr($source,$start,$end-$start).'<script id="meta-comment-ai-bootstrap" type="application/json">@json($metaCommentAiBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>');
$files=new \Illuminate\Filesystem\Filesystem();$compiler=new \Illuminate\View\Compilers\BladeCompiler($files,$argv[2]);$resolver=new \Illuminate\View\Engines\EngineResolver();
$resolver->register('blade',fn()=>new \Illuminate\View\Engines\CompilerEngine($compiler));
$factory=new \Illuminate\View\Factory($resolver,new \Illuminate\View\FileViewFinder($files,[$argv[2]]),new \Illuminate\Events\Dispatcher($app));
echo $factory->make('bar')->render();
`;
let bar;try{bar=execFileSync(process.env.ORDER_EDITOR_TEST_PHP||'php',['-r',compile,root,tmp,process.env.WA_INBOX_TEST_VENDOR||''],{encoding:'utf8'});}finally{fs.rmSync(tmp,{recursive:true,force:true});}
const script=fs.readFileSync(path.join(root,'public/js/dashboard-meta-comment-ai.js'),'utf8'),css=fs.readFileSync(path.join(root,'public/css/dashboard-meta-comment-ai.css'),'utf8');
const initial=()=>({success:true,can_manage:true,control:{enabled:false,mode:'always',days:[1,2,3,4,5,6,7],start:'22:00',end:'02:00',timezone:'Africa/Cairo',revision:3,active_now:false,configured:true,worker_seen_at:null,enabled_at:null}});
const fixture=`<!doctype html><html dir="rtl"><meta charset="utf-8"><style>${css}[hidden]{display:none!important}body{margin:10px;font-family:Arial}</style><body>${bar}<script>window.commentsStillLoading=new Promise(()=>{});window.fixtureXss=0;window.fixtureCleanup=[];window.DashboardSPA={isCurrentPage:()=>true,onCleanup:fn=>fixtureCleanup.push(fn)};</script></body></html>`;
(async()=>{
 const launch={headless:true};if(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH)launch.executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
 const browser=await chromium.launch(launch);let release,releaseStatus;try{
  const page=await browser.newPage({viewport:{width:1366,height:768}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  let payload=initial(),mode='success',posts=[],gets=0,holdStatus=false;
  await page.route('http://localhost/**',async route=>{
   const req=route.request(),url=new URL(req.url());
   if(url.pathname==='/ai/status'){gets++;const captured=JSON.parse(JSON.stringify(payload));if(holdStatus)await new Promise(resolve=>{releaseStatus=resolve});return route.fulfill({json:captured});}
   if(url.pathname==='/ai/update'){
    assert.equal(req.method(),'POST');assert.equal(req.headers()['x-csrf-token'],'fixture-csrf');assert.equal(req.headers()['content-type'],'application/json');
    const input=req.postDataJSON();posts.push(input);
    if(mode==='hold')await new Promise(resolve=>{release=resolve});
    if(mode==='conflict'){payload.control.revision=8;return route.fulfill({status:409,json:{success:false,reason:'SETTINGS_CHANGED'}});}
    if(mode==='invalid')return route.fulfill({status:422,json:{success:false,reason:'INVALID_SETTINGS',message:'<img src=x onerror=fixtureXss=1>'}});
    if(mode==='lost')return route.abort('failed');
    Object.assign(payload.control,input,{revision:payload.control.revision+1,active_now:input.enabled&&input.mode==='always'});delete payload.control.expected_revision;
    return route.fulfill({json:payload});
   }
   return route.fulfill({contentType:'text/html',body:fixture});
  });
  const host=page.locator('#meta-comment-ai'),toggle=host.locator('[data-mcai-toggle]'),schedule=host.locator('[data-mcai-schedule-toggle]'),form=host.locator('[data-mcai-form]'),message=host.locator('[data-mcai-message]');
  async function open(overrides={}){payload=initial();Object.assign(payload,overrides);mode='success';posts=[];gets=0;release=null;await page.goto('http://localhost/comments');await page.addScriptTag({content:script});await page.waitForFunction(()=>document.querySelector('[data-mcai-state]').textContent!=='جارٍ تحميل حالة AI…');}
  await open();assert.equal(gets,1);assert.equal(posts.length,0,'Mount never enables automation');assert.equal(await toggle.innerText(),'تشغيل AI');assert.equal(await toggle.isDisabled(),false,'Settings mount independently while comment loading never resolves');
  await toggle.click();await page.waitForFunction(()=>document.querySelector('[data-mcai-toggle]').getAttribute('aria-pressed')==='true');
  assert.equal(posts.length,1);assert.equal(posts[0].enabled,true);assert.equal(posts[0].expected_revision,3);assert.equal('actor_id'in posts[0],false);assert.equal(await toggle.innerText(),'إيقاف AI');assert.equal(await host.locator('[data-mcai-worker]').innerText(),'بانتظار اتصال عامل الرد الآلي.');
  await toggle.click();await page.waitForFunction(()=>document.querySelector('[data-mcai-toggle]').getAttribute('aria-pressed')==='false');assert.equal(posts[1].enabled,false,'Stop submits current policy disabled');
  await schedule.click();await host.locator('[data-mcai-mode]').selectOption('scheduled');
  for(const day of await form.locator('[data-mcai-day]').all())await day.uncheck();await form.locator('[data-mcai-day][value="5"]').check();await form.locator('[data-mcai-start]').fill('22:00');await form.locator('[data-mcai-end]').fill('02:00');
  fs.mkdirSync(path.join(root,'test-results/meta-comment-ai'),{recursive:true});
  await page.screenshot({path:path.join(root,'test-results/meta-comment-ai/schedule-desktop.png'),fullPage:true});
  await form.locator('[data-mcai-submit]').click();await form.waitFor({state:'hidden'});
  assert.deepEqual(posts.at(-1).days,[5]);assert.equal(posts.at(-1).mode,'scheduled');assert.equal(posts.at(-1).enabled,false,'Saving schedule does not turn on automation');assert.equal(posts.at(-1).start,'22:00');assert.equal(posts.at(-1).end,'02:00');
  await open();mode='conflict';await toggle.click();await message.waitFor({state:'visible'});assert.equal(gets,2,'Conflict reloads authoritative policy');assert.equal(await toggle.getAttribute('aria-pressed'),'false');assert.equal(posts.length,1,'Conflict never retries a write');assert((await message.innerText()).includes('تغيرت الإعدادات'));
  mode='hold';await toggle.click();await page.waitForFunction(()=>document.querySelector('#meta-comment-ai').getAttribute('aria-busy')==='true');assert.equal(await schedule.isDisabled(),true);assert.equal(await toggle.isDisabled(),true);assert(release);mode='success';release();await page.waitForFunction(()=>document.querySelector('#meta-comment-ai').getAttribute('aria-busy')==='false');
  await open();mode='invalid';await toggle.click();await message.waitFor({state:'visible'});assert.equal(await toggle.getAttribute('aria-pressed'),'false');assert.equal(await page.evaluate(()=>fixtureXss),0);assert.equal(await host.locator('img').count(),0);assert.equal((await message.innerText()).includes('<img'),false,'Provider/error messages are not reflected');
  await open();mode='lost';await toggle.click();await message.waitFor({state:'visible'});assert.equal(gets,2,'A lost update result reloads actual policy');assert.equal(posts.length,1,'Lost save never automatically retries');assert((await message.innerText()).includes('تعذّر تأكيد الحفظ'));
  await page.clock.install();await open();holdStatus=true;const poll=page.waitForRequest('**/ai/status');await page.clock.runFor(31000);await poll;
  for(let i=0;i<20&&!releaseStatus;i++)await new Promise(resolve=>setTimeout(resolve,10));assert(releaseStatus,'Periodic status read is pending');
  await toggle.click();await page.waitForFunction(()=>document.querySelector('[data-mcai-toggle]').getAttribute('aria-pressed')==='true');
  holdStatus=false;releaseStatus();releaseStatus=null;await page.clock.runFor(100);
  assert.equal(await toggle.getAttribute('aria-pressed'),'true','An older polling response cannot overwrite a confirmed update');
  await open({can_manage:false});assert.equal(await toggle.isDisabled(),true);assert.equal(await schedule.isDisabled(),true);assert.equal(posts.length,0,'Read-only central admin cannot enable');
  const health=initial();Object.assign(health.control,{enabled:true,active_now:true,worker_seen_at:'2026-10-15T12:00:00Z',last_reason:'RECONNECT_REQUIRED'});await open(health);
  assert.equal(await host.locator('[data-mcai-state]').innerText(),'مفعّل · يحتاج مراجعة','A worker heartbeat does not hide operational reply failures');
  assert.equal(await host.locator('[data-mcai-worker]').innerText(),'ربط ميتا يحتاج تجديدًا قبل الرد الآلي.');assert.equal(await host.locator('[data-mcai-state]').getAttribute('data-active'),'false');
  health.control.last_reason='INACTIVE';await open(health);assert.equal(await host.locator('[data-mcai-worker]').isHidden(),true,'Expected inactive cycle is not a warning');
  health.control.last_reason='<img src=x onerror=fixtureXss=1>';await open(health);assert.equal(await page.evaluate(()=>fixtureXss),0);assert.equal(await host.locator('img').count(),0);assert.equal(await host.locator('[data-mcai-worker]').isHidden(),true,'Unknown status text is never rendered');
  const unconfigured=initial();unconfigured.control.configured=false;await open(unconfigured);assert.equal(await toggle.isDisabled(),true);assert.equal(await schedule.isDisabled(),false);assert.equal(await host.locator('[data-mcai-state]').innerText(),'AI غير جاهز؛ راجع إعداداته.');
  const stoppedDespiteUnconfigured=initial();stoppedDespiteUnconfigured.control.configured=false;stoppedDespiteUnconfigured.control.enabled=true;await open(stoppedDespiteUnconfigured);assert.equal(await toggle.isDisabled(),false,'Stopping remains available if AI credentials fail');
  await page.setViewportSize({width:390,height:844});await schedule.click();await host.locator('[data-mcai-mode]').selectOption('scheduled');assert.equal(await host.evaluate(el=>el.scrollWidth>el.clientWidth+1),false,'Control bar fits mobile width');
  await page.screenshot({path:path.join(root,'test-results/meta-comment-ai/schedule-mobile.png'),fullPage:true});
  await page.evaluate(()=>fixtureCleanup.forEach(fn=>fn()));assert.deepEqual(errors,[]);
  console.log('meta-comment-ai UI: actual Blade, independent bootstrap/default-off, start/stop, Cairo overnight schedule, safe revision recovery, pending guard, owner/readiness states, no injection and mobile layout passed');
 }finally{if(release)release();if(releaseStatus)releaseStatus();await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
