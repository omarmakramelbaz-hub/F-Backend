<?php
/** Functional desktop reconciliation and remote-attempt scope gates on SQLite.
 * Business services, device enrollment checks, journal, references and SQL are real.
 * The minimal runtime supplies a Symfony HTTP adapter and encrypted-envelope binding;
 * this test does not claim full Laravel HTTP middleware or storage-engine coverage.
 */
require __DIR__.'/expense_stock_integration_test.php';

use App\Services\Dashboard\{DesktopDashboardCommands,DesktopDashboardDevices,DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences,DesktopDashboardRemoteAttempts,DesktopDashboardSchema,ExpenseCategories,TakeawayAccess};
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\{Request as SymfonyRequest,Response as SymfonyResponse};

$desktopChecksStart=$checks;

// The small stock harness intentionally omits Laravel's HTTP/encryption packages.
// These adapters provide transport primitives; all application authorization remains real.
if(!class_exists(\Illuminate\Http\Request::class)){
    class ExpenseDesktopHttpRequest extends SymfonyRequest {
        private $routeResolver;
        public function setRouteResolver(callable $resolver){$this->routeResolver=$resolver;return $this;}
        public function route(){return ($this->routeResolver)();}
        public function allFiles(){return $this->files->all();}
        public function path(){return ltrim($this->getPathInfo(),'/');}
        public function input($key,$default=null){return $this->request->has($key)?$this->request->get($key):$this->query->get($key,$default);}
        public function has($key){return $this->request->has($key)||$this->query->has($key);}
        public function header($key,$default=null){return $this->headers->get($key,$default);}
        public function server($key,$default=null){return $this->server->get($key,$default);}
        public function except(...$keys){return array_diff_key($this->request->all()+$this->query->all(),array_flip($keys));}
        public function method(){return $this->getMethod();}
    }
    class_alias(ExpenseDesktopHttpRequest::class,\Illuminate\Http\Request::class);
}
class ExpenseDesktopHttpResponse extends SymfonyResponse {public function header($name,$value){$this->headers->set($name,$value);return $this;}}
if(!function_exists('response')){function response($body='',int $status=200){return new ExpenseDesktopHttpResponse($body,$status);}}
if(!function_exists('auth')){function auth($guard=null){return app('expense-desktop-auth');}}
app()->instance('expense-desktop-auth',new class {public function id(){return 1;}});
app()->instance('encrypter',new class {
    private string $key;
    public function __construct(){$this->key=random_bytes(32);}
    public function encryptString($value){$iv=random_bytes(12);$body=openssl_encrypt($value,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag);return base64_encode($iv.$tag.$body);}
    public function decryptString($value){$body=base64_decode($value,true);$plain=openssl_decrypt(substr($body,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($body,0,12),substr($body,12,16));if($plain===false)throw new RuntimeException('Invalid test envelope');return $plain;}
});
config(['desktop_dashboard.enabled'=>true,'desktop_dashboard.local'=>false,'app.key'=>'expense-desktop-isolated-fixture']);
foreach(['2026_10_08_130000_create_desktop_dashboard_journal.php'=>'CreateDesktopDashboardJournal','2026_10_08_210000_create_desktop_dashboard_remote_attempts.php'=>'CreateDesktopDashboardRemoteAttempts'] as $file=>$class){require_once database_path('migrations/'.$file);(new $class)->up();}
$devices=app(DesktopDashboardDevices::class);
$desktopDeviceId=keyFor(3000);
DB::table('desktop_dashboard_devices')->insert(['id'=>$desktopDeviceId,'actor_id'=>1,'name'=>'admin limited to Mahalla','enrollment_hash'=>str_repeat('a',64),'token_hash'=>str_repeat('b',64),'enabled'=>true,'branches'=>json_encode(['f:100']),'enrolled_at'=>now('UTC')->subMinute(),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$desktopDevice=DB::table('desktop_dashboard_devices')->where('id',$desktopDeviceId)->first();
check(count(app(TakeawayAccess::class)->branches(actor(1)))===2&&json_decode($desktopDevice->branches,true)===['f:100'],'desktop fixture uses a real all-branch administrator enrolled only for Mahalla');

function desktopCatalogState(): string {
    return json_encode([
        DB::table('branch_expense_categories')->orderBy('id')->get(),
        DB::table('branch_expense_category_settings')->orderBy('category_key')->get(),
        DB::table('branch_expense_category_overrides')->orderBy('branch')->orderBy('category_key')->get(),
        DB::table('branch_expense_category_commands')->orderBy('id')->get(),
    ],JSON_UNESCAPED_UNICODE);
}
function desktopEnvelope(int $key,array $values): array {
    return ['command_id'=>keyFor($key),'actor_id'=>1,'route_name'=>'branch-expenses.categorySave','payload'=>['values'=>['idempotency_key'=>keyFor($key)]+$values,'parameters'=>[],'files'=>[]],'local_result'=>['success'=>true],'local_references'=>[],'dependencies'=>[],'occurred_at'=>now('UTC')->toIso8601String()];
}
function desktopRejected(callable $work,int $status,string $label): void {
    $before=desktopCatalogState();$financial=databaseSnapshot();$journal=DB::table('desktop_dashboard_commands')->count();
    denied($work,$status,$label);
    check(desktopCatalogState()===$before&&databaseSnapshot()===$financial&&DB::table('desktop_dashboard_commands')->count()===$journal,$label.' leaves categories, ledgers and device journal unchanged');
}
$reconcile=app(DesktopDashboardReconciliation::class);
$local=desktopEnvelope(3010,['branch'=>'f:100','action'=>'create','name'=>'مستهلكات جهاز المحلة']);
$reconciled=$reconcile->ingest($desktopDevice,$local);
$localKey=$reconciled['result']['category']['key'];
check($reconciled['committed']===true&&$reconciled['result']['category_scope']==='f:100'&&!isset(app(ExpenseCategories::class)->choices(true,'f:101')['active_categories'][$localKey]),'real reconciliation creates a category only in the enrolled branch');
check(DB::table('desktop_dashboard_commands')->where('command_id',keyFor(3010))->value('status')==='acknowledged','reconciled local category and acknowledged desktop journal commit together');
$before=desktopCatalogState();$again=$reconcile->ingest($desktopDevice,$local);
check($again===$reconciled&&desktopCatalogState()===$before,'same reconciled UUID replays without another branch category write');
desktopRejected(fn()=>$reconcile->ingest($desktopDevice,desktopEnvelope(3011,['branch'=>'f:101','action'=>'create','name'=>'محاولة خارج ربط الجهاز'])),403,'reconciliation rejects a branch outside enrollment despite primary administrator authority');
desktopRejected(fn()=>$reconcile->ingest($desktopDevice,desktopEnvelope(3012,['branch'=>'f:101','action'=>'apply_basic'])),403,'reconciliation applies the device gate to basic-category copying');
desktopRejected(fn()=>$reconcile->ingest($desktopDevice,desktopEnvelope(3013,['action'=>'create','name'=>'إنشاء جديد بلا فرع'])),422,'reconciliation rejects a fresh category create without a branch');

// A pre-upgrade global command is a completed durable receipt, not permission to write globally.
$legacyValues=['action'=>'update','key'=>'maintenance','name'=>'الصيانة القديمة','expected_revision'=>6];
$legacyCategory=app(ExpenseCategories::class)->catalog()['maintenance'];
DB::table('branch_expense_category_commands')->insert(['request_key'=>keyFor(3020),'request_hash'=>hash('sha256',json_encode(['update','maintenance','الصيانة القديمة',6])),'actor_id'=>1,'action'=>'update','snapshot'=>json_encode($legacyCategory,JSON_UNESCAPED_UNICODE),'created_at'=>now('UTC')]);
$legacyBefore=desktopCatalogState();$legacyReceipt=$reconcile->ingest($desktopDevice,desktopEnvelope(3020,$legacyValues));
check($legacyReceipt['result']['replayed']===true&&desktopCatalogState()===$legacyBefore,'reconciliation accepts a completed branchless global UUID as a read-only retry');
desktopRejected(fn()=>$reconcile->ingest($desktopDevice,desktopEnvelope(3021,['action'=>'update','key'=>'maintenance','name'=>'اسم جديد بلا فرع','expected_revision'=>7])),422,'reconciliation cannot turn the legacy branchless exception into a fresh global rename');

// Engine metadata is fixture-only: exercise real SQLite transactions after the
// production preflight has seen its expected MySQL metadata representation.
DB::statement("ATTACH DATABASE ':memory:' AS information_schema");
DB::statement('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT,TABLE_NAME TEXT,ENGINE TEXT)');
foreach(array_unique(array_merge(DesktopDashboardSchema::TABLES,['desktop_dashboard_devices','desktop_dashboard_remote_attempts','categories','products','product_features'])) as $table)DB::table('information_schema.TABLES')->insert(['TABLE_SCHEMA'=>DB::connection()->getDatabaseName(),'TABLE_NAME'=>$table,'ENGINE'=>'InnoDB']);
$desktopRoute=new class {
    public function getName(){return 'branch-expenses.categorySave';}
    public function controllerMiddleware(){return [];}
};
app()->instance('router',new class($desktopRoute){
    public function __construct(private object $route){}
    public function getRoutes(){return $this;}
    public function match($request){return $this->route;}
    public function getMiddleware(){return [];}
    public function getMiddlewareGroups(){return [];}
});
$remote=app(DesktopDashboardRemoteAttempts::class);
function desktopRemoteAttempt(int $attempt,array $values): array {
    global $remote,$desktopDevice,$desktopRoute;
    $reservation=$remote->decide($desktopDevice,['id'=>keyFor($attempt),'method'=>'POST','path'=>'/admin/branch-expenses/categories'],false);
    $request=\Illuminate\Http\Request::create('/admin/branch-expenses/categories','POST',$values,[],[],['HTTP_X_FASAKHANSTA_REMOTE_ATTEMPT'=>$reservation['id'],'HTTP_X_FASAKHANSTA_REMOTE_CAPABILITY'=>$reservation['capability'],'HTTP_ACCEPT'=>'application/json']);
    $request->setRouteResolver(fn()=>$desktopRoute);
    return [$reservation,$request];
}
$desktopControllerCalls=0;
$desktopController=function($request)use(&$desktopControllerCalls){$desktopControllerCalls++;$result=app(ExpenseCategories::class)->save($request->except('_token'),actor(1));return new ExpenseDesktopHttpResponse(json_encode($result,JSON_UNESCAPED_UNICODE),200,['Content-Type'=>'application/json']);};
[$remoteAllowed,$remoteRequest]=desktopRemoteAttempt(3030,['idempotency_key'=>keyFor(3031),'branch'=>'f:100','action'=>'create','name'=>'مستهلكات إرسال جهاز المحلة']);
$remoteReply=$remote->handle($remoteRequest,$desktopController);$remoteResult=json_decode($remoteReply->getContent(),true);
check($remoteResult['category_scope']==='f:100'&&$desktopControllerCalls===1&&DB::table('desktop_dashboard_remote_attempts')->where('id',$remoteAllowed['id'])->value('status')==='committed','real remote-attempt handle commits the enrolled branch category and original response');
$before=desktopCatalogState();$remoteReplay=$remote->handle($remoteRequest,$desktopController);
check($remoteReplay->getContent()===$remoteReply->getContent()&&$desktopControllerCalls===1&&desktopCatalogState()===$before,'remote-attempt replay returns saved category response without rerunning the business write');
foreach([['action'=>'create','name'=>'فرع محظور للجهاز'],['action'=>'apply_basic']] as $offset=>$values){
    [$reserved,$request]=desktopRemoteAttempt(3040+$offset,['idempotency_key'=>keyFor(3050+$offset),'branch'=>'f:101']+$values);
    $calls=$desktopControllerCalls;
    desktopRejected(fn()=>$remote->handle($request,$desktopController),403,'remote-attempt '.($values['action']).' rejects a category destination outside device enrollment');
    check($desktopControllerCalls===$calls&&DB::table('desktop_dashboard_remote_attempts')->where('id',$reserved['id'])->value('status')==='ready','rejected out-of-scope remote category request never reaches its controller or commits a response');
}
[$reserved,$request]=desktopRemoteAttempt(3060,['idempotency_key'=>keyFor(3061),'action'=>'create','name'=>'إنشاء إرسال بلا فرع']);
desktopRejected(fn()=>$remote->handle($request,$desktopController),422,'remote-attempt branchless fresh category request is rejected by the real business service');
[$reserved,$request]=desktopRemoteAttempt(3062,['idempotency_key'=>keyFor(3020)]+$legacyValues);
$legacyBefore=desktopCatalogState();$legacyReply=json_decode($remote->handle($request,$desktopController)->getContent(),true);
check($legacyReply['replayed']===true&&desktopCatalogState()===$legacyBefore,'remote-attempt branchless completed legacy UUID returns its saved category without global mutation');
DB::table('desktop_dashboard_devices')->where('id',$desktopDevice->id)->update(['branches'=>json_encode(['f:101'])]);
try{desktopRejected(fn()=>$remote->handle($remoteRequest,$desktopController),403,'stored remote category response still requires current device enrollment for its original branch');}
finally{DB::table('desktop_dashboard_devices')->where('id',$desktopDevice->id)->update(['branches'=>json_encode(['f:100'])]);}

echo ($checks-$desktopChecksStart).' desktop category-scope checks passed; '.$checks.' total real-service checks'.PHP_EOL;
