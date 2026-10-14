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
        Schema::create($prefix.'_inbox_messages',function(Blueprint $t){$t->integer('id')->primary();$t->integer('conversation_id');});
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
    check($wa['floor']===['message_floor'=>10,'event_floor'=>800,'started_at_utc'=>'2026-10-15 10:00:00'],'WhatsApp authorized-account floor excludes foreign history');
    check($ms['floor']['message_floor']===20 && $ig['floor']['message_floor']===30 && $ms['floor']['event_floor']===1000,'Meta channel/account scoped messages and conservative raw event floor');
    check($control->gate('whatsapp','2026-10-15 10:00:00',11,801)['effective'],'native new message at activation second accepted');
    foreach ([['2026-10-15 10:00:00',10,801],['2026-10-15 09:59:59',11,801],['2026-10-15 10:00:01',11,801],['2026-10-15 10:00:00',11,800],[null,11,null],['2026-10-15 10:00:00',null,null],['2026-02-30 10:00:00',11,801],['2026-10-15T13:00:00+03:00',11,801]] as [$time,$id,$event]) check(!$control->gate('whatsapp',$time,$id,$event)['effective'],'native time/id/event activation guard');
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
    check($queued['effective'] && $queued['floor']['message_floor']===11 && $queued['floor']['started_at_utc']==='2026-10-15 10:05:00','off-hours arrival queues for current active window without resetting floor');
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
