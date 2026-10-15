<?php
/** Actual SQLite migration/store/controller; no application boot, Graph or AI calls. */
namespace {
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
    if (!is_string($autoload) || !is_file($autoload)) throw new \RuntimeException('ISOLATED_TEST_VENDOR_REQUIRED');
    require $autoload;
    function app($name = null) { $c = \Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
    function config($key = null, $default = null) { return $key === null ? app('config') : app('config')->get($key, $default); }
    function abort_unless($ok, $status) { if (!$ok) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status); }
    function auth($guard = null) { return new class { public function user() { return $GLOBALS['actor']; } }; }
    function response() { return new class { public function json($value, $status) { return new \Illuminate\Http\JsonResponse($value, $status); } }; }
    set_error_handler(function ($s, $m, $f, $l) { if ($s & E_ALL) throw new \ErrorException($m, 0, $s, $f, $l); return false; });
}
namespace App\Http\Controllers { class Controller {} }
namespace App\Models {
    class User {
        public $id = 1; public $account_type = 'admin'; public $owner_resturant_id; public $active = true; public $superAdmin = false;
        public function __construct($id = 1, $type = 'admin', $branch = null, $owner = false) { $this->id=$id; $this->account_type=$type; $this->owner_resturant_id=$branch; $this->superAdmin=$owner; }
        public function getKey() { return $this->id; }
        public function roles() { return new class($this->superAdmin) {
            private $allowed; private $filters=[];
            public function __construct($allowed) { $this->allowed=$allowed; }
            public function where($key,$value) { $this->filters[$key]=$value; return $this; }
            public function exists() { return $this->allowed && $this->filters===['guard_name'=>'admin','name'=>'Super Admin']; }
        }; }
    }
}
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public $users=[]; public $reads=0;
        public function actor($actor) { $this->reads++; \abort_unless($actor instanceof \App\Models\User && isset($this->users[$actor->id]),403); $fresh=clone $this->users[$actor->id]; \abort_unless($fresh->active,403); return $fresh; }
    }
}
namespace {
    use App\Models\User;
    use App\Services\Dashboard\MetaAiControl;
    use App\Services\Dashboard\MetaInboxAccess;
    use App\Services\Dashboard\WhatsAppInboxAccess;
    use App\Services\Dashboard\TakeawayAccess;
    use App\Services\Dashboard\MetaBusinessAgent;
    use App\Http\Controllers\Dashboard\MetaAiControlController;
    use Illuminate\Container\Container;
    use Illuminate\Config\Repository;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Http\Request;
    use Illuminate\Database\Schema\Blueprint;
    $c=new Container; Container::setInstance($c); $capsule=new Manager($c);
    $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
    $capsule->setEventDispatcher(new Dispatcher($c)); $capsule->setAsGlobal(); $capsule->bootEloquent();
    $c->instance('config',new Repository(['database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]], 'desktop_dashboard'=>['local'=>false], 'whatsapp_orders'=>['enabled'=>true],
        'meta_messaging'=>['accounts'=>[['channel'=>'messenger','account_id'=>'101'],['channel'=>'instagram','account_id'=>'202']]]]));
    $c->instance('db',$capsule->getDatabaseManager()); $c->instance('db.schema',$capsule->getConnection()->getSchemaBuilder()); Facade::setFacadeApplication($c);
    foreach (['Support/MetaAiSchedule','Services/Dashboard/WhatsAppInboxAccess','Services/Dashboard/MetaInboxAccess','Services/Dashboard/MetaBusinessAgent','Services/Dashboard/MetaAiControl','Http/Controllers/Dashboard/MetaAiControlController'] as $file) require __DIR__.'/../app/'.$file.'.php';
    require __DIR__.'/../database/migrations/2026_10_15_000001_create_meta_ai_controls.php';
    class ClockControl extends MetaAiControl { public $clock='2026-10-15 10:00:00'; protected function now(): \DateTimeImmutable { return new \DateTimeImmutable($this->clock,new \DateTimeZone('UTC')); } }
    class FixtureBusinessAgent extends MetaBusinessAgent {
        public $calls=[]; public $duringPrepare;
        public function prepare($actor,array $channels,bool $attested): array { $this->calls[]=[$actor->id,$channels,$attested]; $receipt=parent::prepare($actor,$channels,$attested); if ($this->duringPrepare) ($this->duringPrepare)(); return $receipt; }
    }
    $access=new MetaInboxAccess; $people=new TakeawayAccess; $people->users=[1=>new User,2=>new User(2),3=>new User(3,'admin',null,true),4=>new User(4,'vendor',null,true),5=>new User(5,'admin',7,true)];
    $transportCalls=[];
    $business=new FixtureBusinessAgent(function($method,$url,$payload,$token,$version) use (&$transportCalls) { $transportCalls[]=[$method,$payload]; return ['status'=>200,'body'=>'{"rollout":{"enabled":false}}']; },['access_token'=>str_repeat('p',32)]); $control=new ClockControl;
    $c->instance(TakeawayAccess::class,$people); $c->instance(MetaInboxAccess::class,$access); $c->instance(MetaBusinessAgent::class,$business);
    $GLOBALS['actor']=new User; $checks=0;
    function check($ok,$label) { global $checks; $checks++; if (!$ok) throw new \RuntimeException('META_AI_CONTROL: '.$label); }
    function blocked($call,$reason) { try { $call(); check(false,'must reject '.$reason); } catch (\Throwable $e) { check($e->getMessage()===$reason,'fixed error '.$reason); } }
    check(!$control->installed() && !$control->state()['available'] && !$control->gate('whatsapp')['effective'],'absent schema fails closed without writes');
    (new \CreateMetaAiControls)->up();
    check($control->installed() && DB::table('meta_ai_controls')->count()===1,'single seeded policy row');
    $initial=$control->state(new User);
    check($initial['revision']===0 && !$initial['policy']['enabled'] && $initial['policy']['channels']===['whatsapp','messenger','instagram'],'default disabled all channels');
    check($initial['policy']['mode']==='REPLY_AND_ANALYZE'&&!$control->analysisOnly('whatsapp'),'initial legacy-compatible full mode');
    check($initial['can_manage'] && !$control->state(new User(2))['can_manage'],'owner writes and central Admin read-only');
    foreach ([new User(2,'admin',null,true),new User(4),new User(5)] as $actor) {
        try { $control->update($actor,MetaAiControl::defaults(),0); check(false,'unauthorized write'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { check($e->getStatusCode()===403,'persisted ownership on every write'); }
    }
    check(DB::table('meta_ai_controls')->value('revision')===0,'forged ownership never writes');
    $policy=MetaAiControl::defaults(); $policy['enabled']=true;
    blocked(fn()=>$control->update(new User,$policy,0),'EXTERNAL_AGENT_PAUSE_REQUIRED');
    check(DB::table('meta_ai_controls')->value('revision')===0,'missing external handoff never activates');
    foreach (['whatsapp','meta'] as $prefix) {
        Schema::create($prefix.'_inbox_conversations',function(Blueprint $t){$t->integer('id')->primary();$t->string('waba_id');$t->string('phone_number_id');});
        Schema::create($prefix.'_inbox_messages',function(Blueprint $t){$t->integer('id')->primary();$t->integer('conversation_id');$t->string('direction')->nullable();$t->string('source')->nullable();$t->timestamp('sent_at')->nullable();});
        Schema::create($prefix.'_webhook_events',function(Blueprint $t){$t->integer('id')->primary();});
    }
    DB::table('whatsapp_inbox_conversations')->insert([['id'=>1,'waba_id'=>WhatsAppInboxAccess::WABA_ID,'phone_number_id'=>WhatsAppInboxAccess::PHONE_ID],['id'=>2,'waba_id'=>'other','phone_number_id'=>'other']]);
    DB::table('whatsapp_inbox_messages')->insert([['id'=>10,'conversation_id'=>1],['id'=>900,'conversation_id'=>2]]);
    DB::table('whatsapp_webhook_events')->insert(['id'=>800]);
    DB::table('meta_inbox_conversations')->insert([['id'=>1,'waba_id'=>'messenger','phone_number_id'=>'101'],['id'=>2,'waba_id'=>'instagram','phone_number_id'=>'202'],['id'=>3,'waba_id'=>'messenger','phone_number_id'=>'999']]);
    DB::table('meta_inbox_messages')->insert([['id'=>20,'conversation_id'=>1],['id'=>30,'conversation_id'=>2],['id'=>999,'conversation_id'=>3]]);
    DB::table('meta_webhook_events')->insert(['id'=>1000]);
    $state=$control->update(new User,$policy,0,true);
    check($state['revision']===1 && $state['effective'] && $state['status']==='ACTIVE','enable persists immediately');
    check(!array_key_exists('actor',$state) && !array_key_exists('handoff',$state)
        && $state['handoff_method']==='OWNER_ATTESTED' && !str_contains(json_encode($state),'account_fingerprint'),'browser DTO exposes sanitized handoff method without internal owner or full record');
    $wa=$control->gate('whatsapp'); $ms=$control->gate('messenger'); $ig=$control->gate('instagram');
    check($wa['effective'] && $wa['actor'] instanceof User && $wa['actor_id']===1,'gate returns freshly authorized owner');
    check($wa['floor']===['message_floor'=>10,'event_floor'=>800,'started_at_utc'=>'2026-10-14 10:00:00'],'WhatsApp authorized-account floor excludes foreign history');
    check($ms['floor']['message_floor']===20 && $ig['floor']['message_floor']===30 && $ms['floor']['event_floor']===1000,'Meta channel/account scoped messages and conservative raw event floor');
    check($control->gate('whatsapp','2026-10-15 10:00:00',11,801)['effective'],'native new message at activation second accepted');
    foreach ([['2026-10-15 10:00:00',10,801],['2026-10-14 09:59:59',11,801],['2026-10-15 10:00:01',11,801],['2026-10-15 10:00:01',11,800],[null,11,null],['2026-10-15 10:00:00',null,null],['2026-02-30 10:00:00',11,801],['2026-10-15T13:00:00+03:00',11,801]] as [$time,$id,$event]) check(!$control->gate('whatsapp',$time,$id,$event)['effective'],'native time/id/event activation guard');
    check($control->gate('bogus')['reason']==='INVALID_CHANNEL','unknown channel denied');
    $calls=count($business->calls); blocked(fn()=>$control->update(new User,$policy,0,true),'REVISION_CONFLICT');
    check(count($business->calls)===$calls,'stale revision checked before external effects');
    $people->users[1]->active=false;
    check($control->gate('whatsapp')['reason']==='ACTOR_UNAVAILABLE','revoked stored owner immediately disables automatic work');
    $readOnly=$control->state(new User(2));
    check($readOnly['status']==='ACTIVE' && $readOnly['channels_state'][0]===['channel'=>'whatsapp','effective'=>false,'reason'=>'ACTOR_UNAVAILABLE'],'operational channel status distinguishes enabled schedule from unavailable owner');
    $people->users[1]->active=true;
    $off=$policy; $off['enabled']=false; $control->update(new User,$off,1);
    check($control->gate('whatsapp')['reason']==='DISABLED' && config('whatsapp_orders.enabled')===true,'database master off wins unchanged old config');
    DB::table('whatsapp_inbox_messages')->insert(['id'=>11,'conversation_id'=>1]);
    $control->clock='2026-10-15 10:05:00'; $control->update(new User,$policy,2,true);
    check($control->gate('whatsapp')['floor']['message_floor']===11 && !$control->gate('whatsapp','2026-10-15 10:00:00',11)['effective'],'master re-enable captures a new history floor');
    $scheduled=$policy; $scheduled['schedule']=['enabled'=>true,'days'=>[4],'start'=>'15:00','end'=>'16:00','all_day'=>false];
    $control->update(new User,$scheduled,3);
    check($control->gate('whatsapp')['reason']==='SCHEDULED_OFF','current Cairo off-hours pause');
    $control->clock='2026-10-15 12:30:00';
    $queued=$control->gate('whatsapp','2026-10-15 11:00:00',12,801);
    check($queued['effective'] && $queued['floor']['message_floor']===11 && $queued['floor']['started_at_utc']==='2026-10-14 10:05:00','off-hours arrival queues for current active window without resetting floor');
    $control->clock='2026-10-15 13:00:00'; check(!$control->gate('whatsapp')['effective'],'schedule end immediately pauses existing worker');
    $control->clock='2026-10-15 12:30:00';
    $only=$policy; $only['channels']=['whatsapp']; $control->update(new User,$only,4,true);
    check($control->gate('messenger')['reason']==='CHANNEL_DISABLED','per-channel disable immediate');
    DB::table('meta_inbox_messages')->insert(['id'=>31,'conversation_id'=>2]);
    $control->update(new User,$policy,5,true);
    check($control->gate('instagram')['floor']['message_floor']===31 && $control->gate('whatsapp')['floor']['message_floor']===11,'newly selected channel gets new floor; existing channel keeps floor');
    app('config')->set('meta_messaging.accounts',[['channel'=>'messenger','account_id'=>'103'],['channel'=>'instagram','account_id'=>'202']]);
    check(!$control->gate('messenger')['effective'],'changed connected account cannot inherit old handoff or activation');
    $control->update(new User,$policy,6,true);
    check($control->gate('messenger')['floor']['message_floor']===0 && $control->gate('messenger')['effective'],'explicit owner save establishes new account baseline');
    $stored=DB::table('meta_ai_controls')->first();
    check((int)$stored->updated_by===1 && (int)$stored->actor_id===1 && $stored->updated_at==='2026-10-15 12:30:00','audit actor revision UTC persisted');
    $business->duringPrepare=function(){DB::table('meta_ai_controls')->where('id',1)->increment('revision');};
    $off=$policy; $off['enabled']=false; $control->update(new User,$off,7);
    blocked(fn()=>$control->update(new User,$policy,8,true),'REVISION_CONFLICT');
    check(!json_decode(DB::table('meta_ai_controls')->value('policy'),true)['enabled'],'concurrent owner update cannot be overwritten after external preparation');
    $business->duringPrepare=null;
    $controller=new MetaAiControlController;
    $req=function($input,$json=true){return Request::create('/admin/meta/ai-control','POST',[],[],[],['CONTENT_TYPE'=>$json?'application/json':'text/plain'],is_string($input)?$input:json_encode($input));};
    $GLOBALS['actor']=new User(2);
    check($controller->state(Request::create('/admin/meta/ai-control'),$access,$control)->getStatusCode()===200,'authorized Admin can inspect status');
    check($controller->update($req(['policy'=>$policy,'expected_revision'=>9,'external_agent_paused'=>true]),$access,$control)->getStatusCode()===403,'non-owner controller mutation forbidden');
    $GLOBALS['actor']=new User;
    foreach ([['policy'=>$policy,'expected_revision'=>'9'],['policy'=>$policy,'expected_revision'=>9,'unknown'=>true],['policy'=>$policy,'expected_revision'=>9,'external_agent_paused'=>'true'],'{bad',str_repeat('a',8193)] as $bad) check($controller->update($req($bad),$access,$control)->getStatusCode()===422,'malformed input rejected');
    check($controller->update($req(['policy'=>$policy,'expected_revision'=>9],false),$access,$control)->getStatusCode()===422,'JSON content type required');
    $res=$controller->update($req(['policy'=>$policy,'expected_revision'=>9]),$access,$control);
    check($res->getStatusCode()===422 && json_decode($res->getContent(),true)['error']==='EXTERNAL_AGENT_PAUSE_REQUIRED','fixed external handoff error reaches UI');
    $res=$controller->update($req(['policy'=>$policy,'expected_revision'=>9,'external_agent_paused'=>true]),$access,$control);
    check($res->getStatusCode()===200 && json_decode($res->getContent(),true)['revision']===10,'controller saves actual database revision');
    check(str_contains($res->headers->get('Cache-Control'),'no-store') && $res->headers->get('Referrer-Policy')==='no-referrer','private status response headers');
    $res=$controller->update($req(['policy'=>$policy,'expected_revision'=>9,'external_agent_paused'=>true]),$access,$control);
    check($res->getStatusCode()===409 && json_decode($res->getContent(),true)['error']==='REVISION_CONFLICT','HTTP optimistic conflict');
    // Exercise the actual web CSRF guard, without CLI's unit-test bypass or cookie side effects.
    require __DIR__.'/../app/Http/Middleware/VerifyCsrfToken.php';
    class WebCsrfFixture extends \App\Http\Middleware\VerifyCsrfToken {
        protected $addHttpCookie=false;
        public function __construct() {}
        protected function runningUnitTests() { return false; }
    }
    $csrf=new WebCsrfFixture;
    $session=new \Illuminate\Session\Store('ai-control',new \Illuminate\Session\ArraySessionHandler(120)); $session->start(); $session->regenerateToken();
    $csrfReq=$req(['policy'=>$policy,'expected_revision'=>10]); $csrfReq->setLaravelSession($session); $reached=false;
    try { $csrf->handle($csrfReq,function() use (&$reached){$reached=true;return response()->json([],200);}); check(false,'missing CSRF must fail'); }
    catch (\Illuminate\Session\TokenMismatchException $e) { check(!$reached,'state mutation route cannot bypass missing CSRF'); }
    $csrfReq->headers->set('X-CSRF-TOKEN','wrong');
    try { $csrf->handle($csrfReq,function(){return response()->json([],200);}); check(false,'wrong CSRF must fail'); }
    catch (\Illuminate\Session\TokenMismatchException $e) { check(true,'wrong CSRF rejected'); }
    $csrfReq->headers->set('X-CSRF-TOKEN',$session->token());
    check($csrf->handle($csrfReq,fn()=>response()->json(['ok'=>true],200))->getStatusCode()===200,'same-session CSRF header accepted');
    $router=new \Illuminate\Routing\Router(new Dispatcher($c),$c); $c->instance('router',$router);
    $router->group(['middleware'=>['web']],function(){require __DIR__.'/../routes/meta_inbox.php';});
    $route=$router->getRoutes()->match(Request::create('/admin/meta/ai-control','POST'));
    check($route->getName()==='meta-ai-control.update' && $route->gatherMiddleware()===['web','lang','IsAdmin'],'actual control POST inherits web and Admin middleware through shared routes');
    require __DIR__.'/../app/Http/Kernel.php';
    $kernel=(new \ReflectionClass(\App\Http\Kernel::class))->getDefaultProperties();
    check(in_array(\App\Http\Middleware\VerifyCsrfToken::class,$kernel['middlewareGroups']['web'],true),'actual web group installs CSRF middleware');
    $control->update(new User,$off,10);
    $one=$policy; $one['channels']=['whatsapp']; $control->update(new User,$one,11);
    $receipt=json_decode(DB::table('meta_ai_controls')->value('handoff'),true);
    check($control->gate('whatsapp')['effective'] && $receipt['method']==='VERIFIED_WHATSAPP_API' && $transportCalls===[['GET',null]],'actual takeover receipt composes with store; known-paused WhatsApp needs no attestation or PUT');
    class ControlReadGrammar extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
        public $reads=[];
        public function compileSelect(\Illuminate\Database\Query\Builder $query) { if ($query->from==='meta_ai_controls') $this->reads[]=clone $query; return parent::compileSelect($query); }
    }
    $connection=DB::connection(); $oldGrammar=$connection->getQueryGrammar(); $grammar=new ControlReadGrammar; $connection->setQueryGrammar($grammar);
    $control->gate('whatsapp'); $outside=end($grammar->reads);
    DB::transaction(fn()=>$control->gate('whatsapp')); $inside=end($grammar->reads);
    check($outside->lock===null && $inside->lock===true,'actual store read becomes a current locking read inside an existing work transaction');
    check(str_ends_with((new \Illuminate\Database\Query\Grammars\MySqlGrammar)->compileSelect($inside),' for update'),'actual in-transaction gate query compiles to MySQL current read rather than repeatable-read snapshot');
    $connection->setQueryGrammar($oldGrammar);

    // Re-enabling includes the scoped native preceding day without mutating activation identity.
    $offOne=$one; $offOne['enabled']=false; $control->update(new User,$offOne,12);
    DB::table('whatsapp_inbox_messages')->insert([
        ['id'=>1,'conversation_id'=>1,'direction'=>'inbound','source'=>'messages','sent_at'=>'2026-10-14 10:00:00'],
        ['id'=>2,'conversation_id'=>2,'direction'=>'inbound','source'=>'messages','sent_at'=>'2026-10-15 11:00:00'],
        ['id'=>3,'conversation_id'=>1,'direction'=>'inbound','source'=>'dashboard_api','sent_at'=>'2026-10-15 11:00:00'],
        ['id'=>4,'conversation_id'=>1,'direction'=>'outbound','source'=>'messages','sent_at'=>'2026-10-15 11:00:00'],
        ['id'=>5,'conversation_id'=>1,'direction'=>'inbound','source'=>'messages','sent_at'=>'2026-10-15 10:00:00'],
        ['id'=>6,'conversation_id'=>1,'direction'=>'inbound','source'=>'standby','sent_at'=>'2026-10-16 09:00:00'],
        ['id'=>12,'conversation_id'=>1,'direction'=>'inbound','source'=>'messages','sent_at'=>'2026-10-16 09:59:00'],
    ]);
    $control->clock='2026-10-16 10:00:00'; $control->update(new User,$one,13);
    $activation=json_decode(DB::table('meta_ai_controls')->value('activations'),true)['whatsapp'];
    check($activation['message_floor']===12 && $activation['event_floor']===800 && $activation['started_at_utc']==='2026-10-16 10:00:00','original activation and raw capture watermark preserved');
    check($activation['backlog']===['version'=>1,'message_floor'=>4,'started_at_utc'=>'2026-10-15 10:00:00'],'bounded native inbound floor excludes stale foreign dashboard and outbound rows');
    $backlog=$control->gate('whatsapp');
    check($backlog['floor']===['message_floor'=>4,'event_floor'=>800,'started_at_utc'=>'2026-10-15 10:00:00'] && $backlog['backlog_version']===1 && $backlog['original_activation_started_at_utc']==='2026-10-16 10:00:00','gate exposes only authoritative bounded effective floor');
    check($control->gate('whatsapp','2026-10-15 10:00:00',5,1)['effective'],'pre-enable native message can precede original raw activation event');
    check(!$control->gate('whatsapp','2026-10-15 10:00:00',5,0)['effective'],'historical inclusion never accepts an invalid native event identifier');
    check(!$control->gate('whatsapp','2026-10-15 09:59:59',5,1)['effective'],'bounded lookback never includes older native time');
    check(!$control->gate('whatsapp','2026-10-16 09:59:59',4,1)['effective'],'bounded lookback preserves lower message bound');
    $control->clock='2026-10-16 10:00:05';
    check(!$control->gate('whatsapp','2026-10-16 10:00:01',13,1)['effective'],'new postactivation arrival still respects original raw event watermark');
    check($control->gate('whatsapp','2026-10-16 10:00:01',13,801)['effective'],'new postactivation native event remains allowed');
    $control->clock='2026-10-17 10:00:00';
    check($control->gate('whatsapp')['floor']===$backlog['floor'],'effective floor does not slide or compound across processing time');
    $control->clock='2026-10-16 10:00:05';
    $storedActivations=DB::table('meta_ai_controls')->value('activations');
    $legacyActivations=json_decode($storedActivations,true); unset($legacyActivations['whatsapp']['backlog']);
    $legacyBytes=json_encode($legacyActivations,JSON_THROW_ON_ERROR);
    DB::table('meta_ai_controls')->where('id',1)->update(['activations'=>$legacyBytes]);
    $legacyControl=new ClockControl; $legacyControl->clock=$control->clock;
    $dbBefore=DB::table('meta_ai_controls')->first();
    DB::connection()->flushQueryLog(); DB::connection()->enableQueryLog();
    $legacyGate=$legacyControl->gate('whatsapp');
    $messageQueries=static fn()=>count(array_filter(DB::connection()->getQueryLog(),static fn($q)=>str_contains($q['query'],'inbox_messages')));
    $firstQueries=$messageQueries(); $legacyAgain=$legacyControl->gate('whatsapp');
    check($legacyGate['effective'] && $legacyGate['floor']===$backlog['floor'] && $legacyAgain['floor']===$legacyGate['floor'],'already enabled legacy activation gets same stable bounded semantics');
    check($firstQueries===1 && $messageQueries()===$firstQueries,'legacy scoped lookback query cached across repeated native boundary checks');
    DB::connection()->disableQueryLog();
    check((array)DB::table('meta_ai_controls')->first()===(array)$dbBefore,'legacy transition read performs no policy revision activation or watermark writes');
    DB::table('meta_ai_controls')->where('id',1)->update(['activations'=>$storedActivations]);
    // Separate reply and dispatch schedules; pending approved orders can run with reply hours closed.
    $split=$one; $split['schedule']=['enabled'=>true,'days'=>[5],'start'=>'15:00','end'=>'16:00','all_day'=>false];
    $split['dispatch_schedule']=['enabled'=>true,'days'=>range(0,6),'start'=>'09:00','end'=>'00:30','all_day'=>false];
    $control->update(new User,$split,14);
    check($control->gate('whatsapp')['reason']==='SCHEDULED_OFF' && $control->dispatchGate('whatsapp')['effective'] && $control->processingGate('whatsapp')['effective'],'independent dispatch does not depend on current reply hours');
    $separateState=$control->state(new User);
    check(!$separateState['effective'] && $separateState['dispatch_state']===['effective'=>true,'status'=>'ACTIVE','local_time'=>'2026-10-16T13:00:05+03:00'],'browser state exposes distinct reply and dispatch status on same Cairo clock');
    $control->clock='2026-10-16 21:29:59';
    check($control->dispatchGate('whatsapp')['effective'],'default dispatch 00:29:59 Cairo is open');
    $control->clock='2026-10-16 21:30:00';
    check($control->dispatchGate('whatsapp')['reason']==='DISPATCH_OUTSIDE_HOURS' && !$control->processingGate('whatsapp')['effective'],'default dispatch 00:30 Cairo exclusive with closed reply hours');
    $control->clock='2026-10-17 05:59:59';
    check(!$control->dispatchGate('whatsapp')['effective'],'default dispatch before 09 Cairo is closed');
    $control->clock='2026-10-17 06:00:00';
    check($control->dispatchGate('whatsapp')['effective'],'default dispatch 09 Cairo inclusive');
    check($control->dispatchGate('instagram')['reason']==='CHANNEL_DISABLED','independent dispatch retains selected channel guard');
    $people->users[1]->active=false;
    check($control->dispatchGate('whatsapp')['reason']==='ACTOR_UNAVAILABLE' && !$control->processingGate('whatsapp')['effective'],'revoked owner stops both independent schedule purposes');
    $people->users[1]->active=true;
    $custom=$split; $custom['dispatch_schedule']['start']='14:00'; $custom['dispatch_schedule']['end']='18:00';
    $control->update(new User,$custom,15);
    $legacySave=$custom; unset($legacySave['dispatch_schedule']); $legacySave['schedule']['start']='16:00'; $legacySave['schedule']['end']='17:00';
    $control->update(new User,$legacySave,16);
    check($control->state()['policy']['dispatch_schedule']===$custom['dispatch_schedule'],'legacy reply-only save preserves owner custom dispatch schedule');
    check($control->gate('whatsapp')['floor']===null && $control->processingGate('whatsapp')['floor']===null,'both closed schedules stop before activation scan');
    $control->clock='2026-10-17 12:00:00';
    check($control->dispatchGate('whatsapp')['effective'] && $control->processingGate('whatsapp')['floor']===$backlog['floor'],'schedule edits never recapture or expand original backlog');
    $offCustom=$custom; $offCustom['enabled']=false; $control->update(new User,$offCustom,17);
    check($control->gate('whatsapp')['reason']==='DISABLED' && $control->dispatchGate('whatsapp')['reason']==='DISABLED' && $control->processingGate('whatsapp')['reason']==='DISABLED','master off immediately blocks replies scan and independently queued dispatch');
    check($control->state()['dispatch_state']['status']==='DISABLED','disabled public dispatch status follows master');
    $legacyPolicy=json_decode(DB::table('meta_ai_controls')->value('policy'),true); unset($legacyPolicy['dispatch_schedule']);
    unset($legacyPolicy['mode']);
    $legacyPolicyBytes=json_encode($legacyPolicy,JSON_THROW_ON_ERROR);
    DB::table('meta_ai_controls')->where('id',1)->update(['policy'=>$legacyPolicyBytes]);
    check($control->state()['policy']['dispatch_schedule']===\App\Support\MetaAiSchedule::defaultDispatchSchedule()
        &&$control->state()['policy']['mode']==='REPLY_AND_ANALYZE'&&DB::table('meta_ai_controls')->value('policy')===$legacyPolicyBytes,'legacy stored policy defaults separate dispatch hours and full mode without database writes');

    DB::table('meta_inbox_conversations')->insert(['id'=>4,'waba_id'=>'messenger','phone_number_id'=>'103']);
    DB::table('meta_inbox_messages')->insert([
        ['id'=>2,'conversation_id'=>3,'direction'=>'inbound','source'=>'messages','sent_at'=>'2026-10-17 11:00:00'],
        ['id'=>3,'conversation_id'=>1,'direction'=>'inbound','source'=>'messages','sent_at'=>'2026-10-17 11:00:00'],
        ['id'=>4,'conversation_id'=>4,'direction'=>'inbound','source'=>'messages','sent_at'=>'2026-10-17 11:00:00'],
        ['id'=>5,'conversation_id'=>2,'direction'=>'inbound','source'=>'standby','sent_at'=>'2026-10-17 11:00:00'],
    ]);
    $allChannels=MetaAiControl::defaults(); $allChannels['enabled']=true;
    $control->update(new User,$allChannels,18,true);
    check($control->gate('messenger')['floor']===['message_floor'=>3,'event_floor'=>1000,'started_at_utc'=>'2026-10-16 12:00:00'],'Messenger backlog lookup excludes foreign and previously disconnected accounts');
    check($control->gate('instagram')['floor']===['message_floor'=>4,'event_floor'=>1000,'started_at_utc'=>'2026-10-16 12:00:00'],'Instagram backlog has its own scoped native lower bound');
    check($control->processingGate('messenger','2026-10-17 11:00:00',4,1)['effective'] && $control->processingGate('instagram','2026-10-17 11:00:00',5,1)['effective'],'both Meta channels admit bounded historical native candidates under current fresh policy');

    // Mode changes never reuse an old external-agent pause receipt or rewrite an
    // uncertain customer-facing send. Existing activation identity is retained.
    $beforeMode=DB::table('meta_ai_controls')->first();
    $analysis=$allChannels; $analysis['mode']=\App\Support\MetaAiSchedule::ANALYZE_ONLY;
    $analysis['schedule']=['enabled'=>true,'days'=>[1],'start'=>'15:00','end'=>'16:00','all_day'=>false];
    foreach (['whatsapp','meta'] as $prefix) Schema::create($prefix.'_reply_requests',function(Blueprint $t){
        $t->integer('id')->primary(); $t->string('waba_id'); $t->string('phone_number_id'); $t->string('state');
    });
    class ModeSwitchReadGrammar extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
        public $reads=[];
        public function compileSelect(\Illuminate\Database\Query\Builder $query) {
            if (in_array($query->from,['meta_ai_controls','whatsapp_reply_requests','meta_reply_requests'],true))
                $this->reads[]=['query'=>clone $query,'transaction'=>DB::connection()->transactionLevel()];
            return parent::compileSelect($query);
        }
    }
    $connection=DB::connection(); $oldGrammar=$connection->getQueryGrammar(); $modeGrammar=new ModeSwitchReadGrammar;
    $connection->setQueryGrammar($modeGrammar);
    foreach ([['whatsapp',WhatsAppInboxAccess::WABA_ID,WhatsAppInboxAccess::PHONE_ID],['meta','messenger','103'],['meta','instagram','202']] as [$prefix,$waba,$phone]) {
        foreach (['CLAIMED','UNKNOWN'] as $pending) {
            DB::table($prefix.'_reply_requests')->insert(['id'=>1,'waba_id'=>$waba,'phone_number_id'=>$phone,'state'=>$pending]);
            $pendingBefore=(array)DB::table($prefix.'_reply_requests')->first();
            blocked(fn()=>$control->update(new User,$analysis,19),'REPLIES_PENDING');
            $pendingResponse=$controller->update($req(['policy'=>$analysis,'expected_revision'=>19]),$access,$control);
            check($pendingResponse->getStatusCode()===409&&json_decode($pendingResponse->getContent(),true)['error']==='REPLIES_PENDING',
                'actual controller exposes unsettled mode transition without retry '.$prefix.' '.$waba.' '.$pending);
            check((array)DB::table('meta_ai_controls')->first()===(array)$beforeMode
                &&(array)DB::table($prefix.'_reply_requests')->first()===$pendingBefore,'unsettled mode switch changes neither policy nor receipt '.$prefix.' '.$waba.' '.$pending);
            $locked=array_values(array_filter($modeGrammar->reads,static fn($read)=>$read['query']->lock===true));
            $policyLock=array_values(array_filter($locked,static fn($read)=>$read['query']->from==='meta_ai_controls'));
            $receiptLock=array_values(array_filter($locked,static fn($read)=>$read['query']->from===$prefix.'_reply_requests'));
            check($policyLock!==[]&&$receiptLock!==[]&&end($policyLock)['transaction']>0&&end($receiptLock)['transaction']>0,
                'mode settlement uses current policy and receipt locks inside transaction '.$prefix.' '.$pending);
            $modeGrammar->reads=[];
            DB::table($prefix.'_reply_requests')->delete();
        }
    }
    $connection->setQueryGrammar($oldGrammar);
    DB::table('whatsapp_reply_requests')->insert(['id'=>1,'waba_id'=>'foreign','phone_number_id'=>'other','state'=>'UNKNOWN']);
    DB::table('meta_reply_requests')->insert(['id'=>1,'waba_id'=>'messenger','phone_number_id'=>'999','state'=>'UNKNOWN']);
    $control->clock='2026-10-18 02:00:00'; $prepareBefore=count($business->calls); $networkBefore=count($transportCalls);
    $analysisState=$control->update(new User,$analysis,19);
    check($analysisState['policy']['mode']==='ANALYZE_ONLY'&&$analysisState['effective']&&$analysisState['status']==='ACTIVE'
        &&$analysisState['reply_state']===['effective'=>false,'status'=>'ANALYZE_ONLY','local_time'=>'2026-10-18T05:00:00+03:00'],'analysis-only status distinguishes active analysis from deliberately disabled replies');
    check(count($business->calls)===$prepareBefore&&count($transportCalls)===$networkBefore
        &&DB::table('meta_ai_controls')->value('handoff')===null&&!$analysisState['handoff_required']&&$analysisState['handoff_method']===null,'analysis-only invalidates stale pause receipt without external side effects');
    check(DB::table('meta_ai_controls')->value('activations')===$beforeMode->activations,'switching enabled mode preserves all original activation floors and backlog');
    foreach (['whatsapp','messenger','instagram'] as $channel) {
        $processing=$control->processingGate($channel);
        check($control->analysisOnly($channel)&&$processing['effective']&&$processing['mode']==='ANALYZE_ONLY'
            &&$processing['revision']===20,'fresh analysis authority for '.$channel);
        check(!$control->gate($channel)['effective']&&$control->gate($channel)['reason']==='ANALYZE_ONLY','automatic reply authority closed for '.$channel);
        check($control->dispatchGate($channel)['reason']==='DISPATCH_OUTSIDE_HOURS','dispatch hours remain separate for '.$channel);
    }
    check(!$control->analysisOnly('bogus'),'unknown channel never inherits analysis-only mode');
    check($control->processingGate('whatsapp','2026-10-18 01:00:00',1001,801)['effective']
        &&!$control->processingGate('whatsapp','2026-10-18 02:00:01',1001,801)['effective'],'analysis-only retains exact native message time and event bounds');
    $people->users[1]->active=false;
    check($control->processingGate('whatsapp')['reason']==='ACTOR_UNAVAILABLE','analysis-only cannot bypass revoked owner');
    $people->users[1]->active=true;
    app('config')->set('meta_messaging.accounts',[['channel'=>'messenger','account_id'=>'999'],['channel'=>'instagram','account_id'=>'202']]);
    check($control->processingGate('messenger')['reason']==='ACTIVATION_REQUIRED','analysis-only account change cannot inherit another scoped activation');
    app('config')->set('meta_messaging.accounts',[['channel'=>'messenger','account_id'=>'103'],['channel'=>'instagram','account_id'=>'202']]);
    blocked(fn()=>$control->update(new User,$allChannels,20),'EXTERNAL_AGENT_PAUSE_REQUIRED');
    check(DB::table('meta_ai_controls')->value('revision')===20&&$control->analysisOnly('whatsapp'),'return to full requires fresh external-agent handoff and remains analysis-only on failure');
    $oldClient=$analysis; unset($oldClient['mode']); $control->update(new User,$oldClient,20);
    check($control->state()['policy']['mode']==='ANALYZE_ONLY','old browser save cannot accidentally re-enable dashboard replies');
    $analysisOff=$analysis; $analysisOff['enabled']=false; $control->update(new User,$analysisOff,21);
    check($control->processingGate('whatsapp')['reason']==='DISABLED'&&$control->gate('whatsapp')['reason']==='DISABLED'
        &&$control->dispatchGate('whatsapp')['reason']==='DISABLED','analysis-only still obeys master off for every purpose');
    $control->update(new User,$analysis,22);
    check($control->processingGate('whatsapp')['effective']&&DB::table('meta_ai_controls')->value('handoff')===null,'explicit analysis-only enable needs no paused external agent');
    $beforeReturn=DB::table('meta_ai_controls')->value('activations'); $freshCalls=count($business->calls);
    $control->update(new User,$allChannels,23,true);
    check(count($business->calls)===$freshCalls+1&&$control->gate('whatsapp')['effective']
        &&$control->state()['handoff_method']==='OWNER_ATTESTED'&&!$control->analysisOnly('whatsapp'),'returning to full requires and stores a newly prepared pause handoff');
    check(DB::table('meta_ai_controls')->value('activations')===$beforeReturn,'mode return never rebaselines existing enabled activation');
    DB::table('meta_ai_controls')->where('id',1)->update((array)$beforeMode); $control->clock='2026-10-17 12:00:00';
    foreach (['whatsapp','meta'] as $prefix) Schema::drop($prefix.'_reply_requests');

    // Malformed optional backlog bounds never silently lower an activation.
    foreach ([['version'=>2],['message_floor'=>-1],['message_floor'=>13],['started_at_utc'=>'2026-10-14 10:00:00'],['extra'=>true]] as $changes) {
        $bad=json_decode($storedActivations,true); $bad['whatsapp']['backlog']=array_replace($bad['whatsapp']['backlog'],$changes);
        DB::table('meta_ai_controls')->where('id',1)->update(['activations'=>json_encode($bad,JSON_THROW_ON_ERROR)]);
        check(!$control->state()['available'] && !$control->processingGate('whatsapp')['effective'],'strict versioned bounded activation validation');
    }
    DB::table('meta_ai_controls')->where('id',1)->update(['activations'=>$storedActivations]);

    app('config')->set('desktop_dashboard.local',true);
    check(!$control->state(new User)['available'] && !$control->gate('whatsapp')['effective'],'local desktop cannot run primary AI');
    app('config')->set('desktop_dashboard.local',false);
    $saved=DB::table('meta_ai_controls')->first();
    foreach (['policy'=>'{"enabled":true}', 'activations'=>'{"whatsapp":{"message_floor":0}}', 'handoff'=>'not-json'] as $key=>$bad) {
        DB::table('meta_ai_controls')->where('id',1)->update([$key=>$bad]);
        check($control->installed() && !$control->state()['available'] && !$control->gate('whatsapp')['effective'],'corrupt installed policy fails closed without legacy fallback');
        DB::table('meta_ai_controls')->where('id',1)->update([$key=>$saved->$key]);
    }
    DB::table('meta_ai_controls')->where('id',1)->delete();
    check($control->installed() && !$control->gate('whatsapp')['effective'],'missing fixed policy row never silently activates');
    (new \CreateMetaAiControls)->down(); check(!$control->installed(),'migration reverses cleanly');
    echo 'META_AI_CONTROL_TESTS='.$checks." PASS\n";
}
