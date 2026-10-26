'use strict';

// Measure the production SPA in a genuine browser. The transport, page bodies
// and initializers are synthetic; this is not a PHP, hardware or business-data
// benchmark. No production account, database, credential or data is accessed.
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const assert=require('node:assert/strict');
const crypto=require('node:crypto');
const fs=require('node:fs');
const http=require('node:http');
const path=require('node:path');
const {performance}=require('node:perf_hooks');
const root=path.resolve(__dirname,'../..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const spa=read('public/dashboard/js/dashboard-spa.js');
const actualCss=read('public/dashboard/css/branch-expenses.css');
const delay=milliseconds=>new Promise(resolve=>setTimeout(resolve,milliseconds));
const requests=[];
let origin,phase='startup',role={id:1,type:'admin'},singleWorker=false,tail=Promise.resolve();
let nextRead,releaseRead;

function send(response,status,type,body,headers={}){
  response.writeHead(status,{'Content-Type':type,'Content-Length':Buffer.byteLength(body),...headers});
  response.end(body);
}
function shell(url){
  const variant=url.searchParams.get('page')||'a';
  const scripts=url.searchParams.get('scripts')==='3'?['one','two','three']:['page'];
  const footer=scripts.map(name=>'<script src="/dashboard/js/navigation-fixture-'+name+'.js?v=1"></script>').join('');
  return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="synthetic-csrf">'
    +'<script src="/dashboard/js/dashboard-spa.js"></script><!-- dashboard-page-css:start -->'
    +'<link rel="stylesheet" href="/dashboard/css/branch-expenses.css?v=1"><!-- dashboard-page-css:end --></head>'
    +'<body data-dashboard-actor="'+role.id+':'+role.type+'"><header class="main-header">GO ERP navigation fixture</header>'
    +'<nav><a id="to-a" href="/admin/navigation-fixture?page=a">A</a><a id="to-b" href="/admin/navigation-fixture?page=b">B</a>'
    +'<a id="to-scripts" href="/admin/navigation-fixture?page=c&scripts=3">C</a></nav>'
    +'<main data-dashboard-page><h1 data-page="'+variant+'">Synthetic page '+variant+'</h1>'
    +'<button id="start-read" type="button">Synthetic background read</button>'
    +'<form action="/admin/navigation-fixture/save" method="post"><input name="value" value="synthetic"><button type="submit" id="save">Save</button></form>'
    +'</main><div data-dashboard-page-scripts hidden><script src="/dashboard/vendor/navigation-fixture-dependency.js?v=1"></script>'
    +footer+'</div></body></html>';
}
const initializer=name=>"window.fixtureInitializers=(window.fixtureInitializers||[]);window.fixtureInitializers.push("+JSON.stringify(name)+");"
  +"var button=document.getElementById('start-read');if(button)button.addEventListener('click',function(){fetch('/admin/navigation-fixture/data').then(function(r){return r.json();}).then(function(){window.fixtureReadCompleted=(window.fixtureReadCompleted||0)+1;});});";

const server=http.createServer((request,response)=>{
  const record={method:request.method,path:request.url,phase,received_ms:performance.now(),started_ms:null,completed_ms:null};
  requests.push(record);
  const work=async()=>{
    record.started_ms=performance.now();
    const url=new URL(request.url,origin);
    try{
      if(url.pathname==='/dashboard/js/dashboard-spa.js')send(response,200,'application/javascript; charset=utf-8',spa);
      else if(url.pathname==='/dashboard/css/branch-expenses.css'){
        // The real local router sends Content-Type, with no cache validator or
        // freshness header. Preserve that behavior rather than force no-store.
        send(response,200,'text/css; charset=utf-8',actualCss);
      }else if(url.pathname==='/dashboard/vendor/navigation-fixture-dependency.js')send(response,200,'application/javascript','window.fixtureDependencyLoads=(window.fixtureDependencyLoads||0)+1;');
      else if(url.pathname.startsWith('/dashboard/js/navigation-fixture-')){
        const name=path.basename(url.pathname,'.js').replace('navigation-fixture-','');
        if(name!=='page')await delay(80);
        send(response,200,'application/javascript; charset=utf-8',initializer(name));
      }else if(url.pathname==='/admin/navigation-fixture/data'){
        if(nextRead){nextRead();nextRead=null;}
        await new Promise(resolve=>{releaseRead=resolve;});
        send(response,200,'application/json','{"success":true}',{'Cache-Control':'private, no-store'});
      }else if(url.pathname==='/admin/navigation-fixture/save'&&request.method==='POST'){
        assert.equal(request.headers['x-csrf-token'],'synthetic-csrf');
        await delay(100);
        send(response,200,'application/json','{"success":true,"redirect":"/admin/navigation-fixture?page=b"}',{'Cache-Control':'private, no-store'});
      }else if(url.pathname==='/admin/navigation-fixture')send(response,200,'text/html; charset=utf-8',shell(url),{'Cache-Control':'private, no-store'});
      else send(response,404,'text/plain','Not found');
    }catch(error){
      if(!response.headersSent)send(response,500,'text/plain','Synthetic diagnostic failed');
      response.destroy(error);
    }finally{record.completed_ms=performance.now();}
  };
  if(singleWorker){tail=tail.then(work,work).catch(()=>{});}else work().catch(()=>{});
});

async function ready(page,variant){
  await page.waitForFunction(value=>document.querySelector('[data-page]')?.getAttribute('data-page')===value
    &&window.DashboardSPA?.ready()&&!document.body.classList.contains('dashboard-spa-loading'),variant);
}
async function visit(page,selector,variant){
  const before=await page.evaluate(()=>({now:performance.now(),initializers:window.fixtureInitializers?.length||0}));
  await page.locator(selector).click();await ready(page,variant);
  const after=await page.evaluate(()=>({now:performance.now(),initializers:window.fixtureInitializers?.length||0,dependencyLoads:window.fixtureDependencyLoads,
    styleNodes:document.querySelectorAll('link[href*="branch-expenses.css"]').length}));
  return {elapsed_ms:after.now-before.now,initializerExecutions:after.initializers-before.initializers,dependencyLoads:after.dependencyLoads,styleNodes:after.styleNodes};
}

(async()=>{
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  origin='http://127.0.0.1:'+server.address().port;
  const browser=await chromium.launch({headless:true,...(process.env.DESKTOP_TEST_BROWSER_EXECUTABLE?{executablePath:process.env.DESKTOP_TEST_BROWSER_EXECUTABLE}:{})});
  const roleMeasurements=[];
  let queuedReadMeasurement,sequentialScriptMeasurement,saveMeasurement;
  try{
    for(const current of [{id:1,type:'admin',name:'owner'},{id:2,type:'admin',name:'admin'},{id:10,type:'vendor',name:'branch'}]){
      role=current;phase=current.name+'-repeat-navigation';singleWorker=false;
      const context=await browser.newContext(),page=await context.newPage(),errors=[];
      page.on('pageerror',error=>errors.push(error.message));
      const first=requests.length;
      await page.goto(origin+'/admin/navigation-fixture?page=a');await ready(page,'a');
      const visits=[];
      for(const [selector,variant] of [['#to-b','b'],['#to-a','a'],['#to-b','b'],['#to-a','a']])visits.push(await visit(page,selector,variant));
      const seen=requests.slice(first);
      assert(visits.every(value=>value.initializerExecutions===1&&value.dependencyLoads===1&&value.styleNodes===1));
      assert.equal(seen.filter(value=>value.path==='/dashboard/js/dashboard-spa.js').length,1);
      assert.deepEqual(errors,[]);
      roleMeasurements.push({role:current.name,actorMarker:current.id+':'+current.type,visits,
        htmlRequests:seen.filter(value=>value.path.startsWith('/admin/navigation-fixture?')).length,
        sameCssHttpRequests:seen.filter(value=>value.path.startsWith('/dashboard/css/branch-expenses.css')).length,
        pageScriptHttpRequests:seen.filter(value=>value.path.startsWith('/dashboard/js/navigation-fixture-page.js')).length,
        dependencyHttpRequests:seen.filter(value=>value.path.startsWith('/dashboard/vendor/navigation-fixture-dependency.js')).length});
      if(current.name==='branch'){
        phase='sequential-script-transport';
        const start=requests.length;
        const visitResult=await visit(page,'#to-scripts','c');
        const scriptReads=requests.slice(start).filter(value=>/navigation-fixture-(one|two|three)\.js/.test(value.path));
        assert.equal(scriptReads.length,3);
        assert(scriptReads.slice(1).every((value,index)=>value.received_ms>=scriptReads[index].completed_ms));
        sequentialScriptMeasurement={...visitResult,syntheticDelayPerScript_ms:80,requests:scriptReads.map(value=>({path:value.path,received_ms:value.received_ms,completed_ms:value.completed_ms}))};
        await visit(page,'#to-a','a');
        phase='single-worker-background-read';singleWorker=true;
        const accepted=new Promise(resolve=>{nextRead=resolve;});
        await page.locator('#start-read').click();await accepted;
        const startQueue=requests.length;
        const started=performance.now();
        const navigation=visit(page,'#to-b','b');
        // Hold one accepted synthetic read while the real SPA sends its next
        // document request. This explicitly models a single PHP worker; it does
        // not pretend to measure the execution time of a real PHP query.
        await delay(250);
        const beforeRelease=requests.slice(startQueue).find(value=>value.path.includes('page=b'));
        assert(beforeRelease,'The SPA must request its destination while the current read is pending.');
        assert.equal(beforeRelease.started_ms,null,'A single worker cannot start the next request before the current read ends.');
        releaseRead();releaseRead=null;
        const finished=await navigation;
        queuedReadMeasurement={...finished,syntheticHeldRead_ms:250,elapsed_node_ms:performance.now()-started,
          destinationReceivedBeforeReadRelease:true,destinationStartedAfterReadRelease:beforeRelease.started_ms!==null};
        phase='accepted-save-navigation-guard';singleWorker=false;
        const savesBefore=requests.filter(value=>value.method==='POST').length;
        await page.locator('#save').click();
        await page.locator('#to-a').click();
        await ready(page,'b');
        const saves=requests.filter(value=>value.method==='POST').slice(savesBefore);
        assert.equal(saves.length,1);assert(saves[0].completed_ms!==null);
        saveMeasurement={originalPostCount:saves.length,responseCompleted:true,afterSubmitPage:'b'};
        assert.deepEqual(errors,[]);
      }
      await context.close();
    }
    console.log('GO_ERP_GENERAL_NAVIGATION_DIAGNOSTIC '+JSON.stringify({sourceRevision:process.env.GITHUB_SHA||'local',genuineChromium:true,browserVersion:browser.version(),
      productionSpaSha256:crypto.createHash('sha256').update(spa).digest('hex'),productionCssSha256:crypto.createHash('sha256').update(actualCss).digest('hex'),
      syntheticTransport:true,syntheticRoleShells:true,syntheticPageInitializers:true,actualPhp:false,hardware:false,htmlCaching:false,
      roleMeasurements,sequentialScriptMeasurement,queuedReadMeasurement,saveMeasurement}));
  }finally{
    releaseRead?.();await browser.close();server.closeAllConnections?.();await new Promise(resolve=>server.close(resolve));
  }
})().catch(error=>{console.error(error);server.closeAllConnections?.();server.close();process.exitCode=1;});
