<?php
// Real checkout/receipt/controller paths against a private in-memory database; no .env or live services.
require getenv('CRM_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($file)) require_once $file;
    }
});

use App\Services\Dashboard\{BranchStock,PosReceiptPrinting,PosServiceTicket,TakeawayAccess,TakeawayService};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Facade,Schema};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function app($abstract=null) { $c=Container::getInstance();return $abstract?$c->make($abstract):$c; }
function config($key=null,$default=null) {if(is_array($key)){foreach($key as $k=>$v)app('config')->set($k,$v);return;}return app('config')->get($key,$default);}
function now($zone=null) { return \Carbon\Carbon::now($zone); }
function abort_unless($condition,$code,$message='') {if(!$condition)throw new HttpException($code,$message);}
function abort_if($condition,$code,$message='') {if($condition)throw new HttpException($code,$message);}
function route($name,$args=[]) {$id=$args['id']??null;unset($args['id']);return 'https://pos.test/admin/takeaway/receipts/'.$id.($name==='takeaway.details'?'/details':'/print').($args?'?'.http_build_query($args):'');}
function auth($guard=null) {return new class {public function user(){return $GLOBALS['currentActor'];}};}
function response() {return new class {public function view($name,$data){return new PrintResponse($name,$data);}};}
function view($name,$data) {return new PrintResponse($name,$data);}
class PrintResponse {public array $headers=[];public function __construct(public string $name,public array $data){}public function header($key,$value){$this->headers[$key]=$value;return $this;}}
class PrintTestApplication extends Container {public function getLocale(){return 'ar';}}
class PrintTestUser extends \Illuminate\Database\Eloquent\Model {protected $table='users';public function can($permission){return $this->account_type==='admin';}}
class_alias(PrintTestUser::class,'App\\Models\\User');
class PrintTestController {public function middleware($callback){}}
class_alias(PrintTestController::class,'App\\Http\\Controllers\\Controller');
class PrintTestStock extends BranchStock {
    public int $sales=0;public function __construct(){}
    public function validateQuantities(string $branch,array $items):void{}
    public function quoteLines(string $branch,array $lines):array{return $lines;}
    public function posSale(array $branch,int $orderId,array $lines,int $actorId):void{$this->sales++;}
    public function saleBalances(string $branch,array $ids):array{return [];}
}
class PrintTestDiningController extends \App\Http\Controllers\Dashboard\PosServiceController {protected string $channel='dine';public function __construct(){}}
class PrintTestPhoneController extends \App\Http\Controllers\Dashboard\PosServiceController {protected string $channel='phone';public function __construct(){}}
$c=new PrintTestApplication;Container::setInstance($c);$c->instance('config',new \Illuminate\Config\Repository);
$capsule=new Capsule($c);$capsule->addConnection(['driver'=>'sqlite','database'=>getenv('PRINT_POLICY_RACE_DB')?:':memory:','prefix'=>'','foreign_key_constraints'=>true]);$capsule->setAsGlobal();$capsule->bootEloquent();
$c->instance('db',$capsule->getDatabaseManager());$c->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());Facade::setFacadeApplication($c);
$c->instance('validator',new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en'),$c));
config(['app.timezone'=>'Africa/Cairo','app.key'=>'base64:'.base64_encode(str_repeat('receipt-test-key',3))]);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17T12:00:00Z'));
if(getenv('PRINT_POLICY_RACE_DB')){
    // Force two independent connections to read the same unused state before either writes.
    // SQLite uses a write conflict where production InnoDB serializes lockForUpdate().
    DB::statement('PRAGMA busy_timeout = 1000');$synchronized=false;
    DB::listen(function($query)use(&$synchronized){
        if($synchronized||!str_starts_with(strtolower($query->sql),'select')||!str_contains($query->sql,'takeaway_till_entries'))return;
        $synchronized=true;$base=getenv('PRINT_POLICY_RACE_DB');touch($base.'.ready-'.getenv('PRINT_POLICY_RACE_WORKER'));
        $deadline=microtime(true)+3;
        while(!(is_file($base.'.ready-1')&&is_file($base.'.ready-2'))){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timeout');usleep(1000);}
    });
    try{app(PosReceiptPrinting::class)->consume((int)getenv('PRINT_POLICY_RACE_ORDER'),PrintTestUser::find(10),getenv('PRINT_POLICY_RACE_TOKEN'));echo 'ACCEPTED';}
    catch(HttpException $error){if($error->getStatusCode()!==403)throw $error;echo 'DENIED';}
    catch(\Illuminate\Database\QueryException $error){if(!str_contains(strtolower($error->getMessage()),'locked'))throw $error;echo 'CONFLICT';}
    exit;
}
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('guard_name');$t->string('name');});
foreach(['order-list','order-create'] as $name)DB::table('permissions')->insert(['guard_name'=>'admin','name'=>$name]);
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');});
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('product_name');$t->decimal('product_price',14,2);$t->string('status');$t->text('price');});
foreach([[1,'admin',null],[2,'admin',null],[10,'vendor',null],[11,'vendor',null],[12,'admin',100],[13,'admin',100],[20,'resturant_owner',100]] as [$id,$type,$parent])DB::table('users')->insert(['id'=>$id,'name'=>'Fixture '.$id,'account_type'=>$type,'owner_resturant_id'=>$parent]);
foreach([[100,10],[101,11]] as [$id,$owner])DB::table('resturants')->insert(['id'=>$id,'user_id'=>$owner,'name'=>'Branch '.$id]);
DB::table('resturant_products')->insert(['id'=>1,'resturant_id'=>100,'product_name'=>'رنجة','product_price'=>'100.00','status'=>'show','price'=>'{}']);
foreach(['2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos','2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets','2026_10_04_060000_lock_pos_service_bills.php'=>'LockPosServiceBills'] as $file=>$class){require dirname(__DIR__).'/database/migrations/'.$file;(new $class)->up();}
$stock=new PrintTestStock;$c->instance(BranchStock::class,$stock);
$access=app(TakeawayAccess::class);$sales=app(TakeawayService::class);$printing=app(PosReceiptPrinting::class);$tickets=app(PosServiceTicket::class);
$cashier=PrintTestUser::find(10);$admin=PrintTestUser::find(2);$owner=PrintTestUser::find(20);$branchAdmin=PrintTestUser::find(12);$sameBranch=PrintTestUser::find(13);$otherBranch=PrintTestUser::find(11);$currentActor=$cashier;$count=0;
function check($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo 'PASS '.$message.PHP_EOL;}
function denied(callable $fn,int $status,string $message){try{$fn();}catch(HttpException $e){check($e->getStatusCode()===$status,$message);return;}throw new RuntimeException('Expected denial: '.$message);}
function token(?string $url):string{$query=[];parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);return $query['initial_print']??'';}
function newSale($actor=null):array {global $sales,$cashier;$actor=$actor??$cashier;$cart=['branch'=>'f:100','items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>'0.250']]];$q=$sales->quote($cart,$actor);$values=$cart+['idempotency_key'=>(string)Str::uuid(),'quote_hash'=>$q['quote_hash'],'payment_method'=>'cash','cash_received'=>'30.00','customer_phone'=>'01012345678'];return [$sales->checkout($values,$actor),$values,$q];}
function fiscalState():array {return [DB::table('takeaway_orders')->count(),DB::table('takeaway_till_entries')->count(),(int)DB::table('takeaway_tills')->sum('balance_cents'),$GLOBALS['stock']->sales];}
function ticketFixture(string $channel,array $quote,?int $order=null):int {return DB::table('pos_service_tickets')->insertGetId(['branch'=>'f:100','channel'=>$channel,'actor_id'=>10,'status'=>$order?($channel==='dine'?'paid':'finished'):'awaiting_bill','payment_status'=>$order?'paid':'unpaid','revision'=>1,'customer_name'=>'Fixture customer','customer_phone'=>'+201012345678','cart_snapshot'=>json_encode(['branch'=>'f:100','items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>'0.250']]]),'quote_snapshot'=>json_encode($quote),'paid_order_id'=>$order,'business_date'=>'2026-10-17','bill_issued_at'=>'2026-10-17 12:00:00','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);}

check(!$access->canReprintReceipts($cashier)&&!$access->canReprintReceipts($branchAdmin),'cashier and branch-linked admin cannot reprint');
check($access->permissions($branchAdmin)['can_manage']&&!$access->permissions($branchAdmin)['can_reprint'],'branch management permission does not grant receipt reprints');
check($access->canReprintReceipts($owner)&&$access->canReprintReceipts($admin),'restaurant owner and central admin may reprint within their branch access');
[$first,$values,$quote]=newSale();$id=$first['receipt']['id'];$grant=token($first['first_print_url']);
check(!$first['replayed']&&preg_match('/\A[0-9a-f]{64}\z/D',$grant)===1,'fresh checkout supplies a cryptographically signed initial grant');
check($first['receipt']['can_reprint']===false&&$first['receipt']['receipt_url']===null&&$first['receipt_url']===null,'branch success contains no repeat-print URL');
$repeat=$sales->checkout($values,$cashier);
check($repeat['replayed']&&$repeat['first_print_url']===$first['first_print_url'],'exact checkout retry returns the same unused first-print grant');
$recovered=$sales->receipts(['branch'=>'f:100','idempotency_key'=>$values['idempotency_key']],$cashier);
check($recovered['replayed']&&$recovered['first_print_url']===$first['first_print_url'],'uncertain checkout recovery returns the same grant to the original cashier');
$history=$sales->receipts(['branch'=>'f:100'],$cashier);
check($history['first_print_url']===null&&$history['items'][0]['receipt_url']===null,'ordinary daily history never exposes first-print or branch reprint links');
$details=$sales->receipt($id,$cashier);
check($details['details_url']!==null&&$details['receipt_url']===null&&!array_key_exists('first_print_url',$details),'branch details remain readable without minting a print grant');
$raw=DB::table('takeaway_till_entries')->where('order_id',$id)->first();$meta=json_decode($raw->metadata,true);
check(($meta['receipt_print']['expires_at']-now('UTC')->getTimestamp())===600&&!str_contains($raw->metadata,$grant),'grant has a fixed ten-minute lifetime and its secret token is not persisted');
$public=$sales->register('f:100',$cashier);
check(!array_key_exists('receipt_print',$public['entries'][0]['metadata'])&&$public['entries'][0]['metadata']['total_cents']===2500,'ordinary till metadata redacts internal grant state and retains fiscal data');
check($sales->firstReceiptPrintUrl($id,$sameBranch)===null,'another account in the same branch cannot recover the cashier grant');
denied(fn()=>$sales->printReceipt($id,$sameBranch,$grant),403,'a copied first-print token cannot be used by another branch account');
denied(fn()=>$sales->printReceipt($id,$otherBranch,$grant),404,'first-print token cannot cross branch authorization');
denied(fn()=>$sales->printReceipt($id,$cashier),403,'a branch cannot bypass the grant by opening the bare print URL');
denied(fn()=>$sales->printReceipt($id,$cashier,str_repeat('a',64)),403,'a forged first-print token is denied');
[$second,$secondValues]=newSale();
check(token($second['first_print_url'])!==$grant,'independent sales receive different grants');
denied(fn()=>$sales->printReceipt($second['receipt']['id'],$cashier,$grant),403,'grant is bound to its original order');
$before=fiscalState();$printed=$sales->printReceipt($id,$cashier,$grant);
check($printed['id']===$id&&$printed['total']==='25.00','the original cashier can fetch the first receipt once');
check(fiscalState()===$before,'fetching a print changes no sale, cash, stock or movement amounts');
$consumed=json_decode(DB::table('takeaway_till_entries')->where('order_id',$id)->value('metadata'),true);
check($consumed['receipt_print']['consumed_at']!==null&&$consumed['total_cents']===$meta['total_cents'],'consumption persists only the grant state beside the original fiscal metadata');
denied(fn()=>$sales->printReceipt($id,$cashier,$grant),403,'a consumed grant cannot be replayed');
check($sales->checkout($values,$cashier)['first_print_url']===null&&$sales->receipts(['branch'=>'f:100','idempotency_key'=>$values['idempotency_key']],$cashier)['first_print_url']===null,'checkout and recovery never renew a consumed grant');
check(fiscalState()===$before,'checkout recovery after printing remains fiscally idempotent');
foreach([$owner,$admin] as $privileged){check($sales->receipt($id,$privileged)['can_reprint']&&$sales->receipt($id,$privileged)['receipt_url']!==null,'authorized owner/admin receipt includes a repeat-print link');$sales->printReceipt($id,$privileged);$sales->printReceipt($id,$privileged);}
check(fiscalState()===$before,'authorized repeated copies cannot create financial effects');
$stale=clone $branchAdmin;$stale->owner_resturant_id=null;
check(!$access->canReprintReceipts($stale),'reprint policy reloads the persisted role instead of trusting a stale supplied actor');
denied(fn()=>$sales->printReceipt($id,$stale),403,'a forged central-admin object cannot bypass branch repeat-print restriction');
$expiryState=DB::table('takeaway_till_entries')->where('order_id',$second['receipt']['id'])->value('metadata');
\Carbon\Carbon::setTestNow(now('UTC')->addMinutes(10));
denied(fn()=>$sales->printReceipt($second['receipt']['id'],$cashier,token($second['first_print_url'])),403,'initial grant expires exactly ten minutes after the sale');
check($sales->checkout($secondValues,$cashier)['first_print_url']===null&&DB::table('takeaway_till_entries')->where('order_id',$second['receipt']['id'])->value('metadata')===$expiryState,'an expired grant is never renewed by checkout replay');
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17T12:00:00Z'));
[$legacy,$legacyValues]=newSale();$legacyId=$legacy['receipt']['id'];$legacyMeta=PosReceiptPrinting::publicMetadata(DB::table('takeaway_till_entries')->where('order_id',$legacyId)->value('metadata'));DB::table('takeaway_till_entries')->where('order_id',$legacyId)->update(['metadata'=>json_encode($legacyMeta)]);
check($sales->receipt($legacyId,$cashier)['id']===$legacyId&&$sales->checkout($legacyValues,$cashier)['first_print_url']===null,'legacy receipts remain readable/replayable without backfilling a first-print grant');
denied(fn()=>$sales->printReceipt($legacyId,$cashier,token($legacy['first_print_url'])),403,'a legacy branch receipt cannot acquire repeat-print permission');
check($sales->printReceipt($legacyId,$admin)['id']===$legacyId,'central admin can print a legacy receipt');
[$noKey]=newSale();$appKey=config('app.key');config(['app.key'=>'']);
check($sales->firstReceiptPrintUrl($noKey['receipt']['id'],$cashier)===null,'missing signing configuration never creates an insecure grant');
denied(fn()=>$sales->printReceipt($noKey['receipt']['id'],$cashier,token($noKey['first_print_url'])),403,'missing signing key fails closed for first-print tokens');config(['app.key'=>$appKey]);
DB::table('users')->where('id',10)->update(['account_type'=>'user']);
denied(fn()=>$sales->firstReceiptPrintUrl($noKey['receipt']['id'],$cashier),403,'revoked cashier access cannot recover an outstanding grant');DB::table('users')->where('id',10)->update(['account_type'=>'vendor']);

$controller=new \App\Http\Controllers\Dashboard\TakeawayController;$currentActor=$cashier;
denied(fn()=>$controller->print(\Illuminate\Http\Request::create('/admin/takeaway/receipts/'.$id.'/print'),$id,$sales),403,'real takeaway print controller enforces the repeat-print denial');
denied(fn()=>$controller->print(\Illuminate\Http\Request::create('/print?initial_print[]=bad'),$id,$sales),403,'array-valued grant input is rejected without a server type error');
[$http]=newSale();$httpId=$http['receipt']['id'];$httpResponse=$controller->print(\Illuminate\Http\Request::create($http['first_print_url'].'&dashboard_print=1'),$httpId,$sales);
check($httpResponse->data['receipt']['id']===$httpId&&$httpResponse->headers['Cache-Control']==='private, no-store, max-age=0'&&$httpResponse->headers['Referrer-Policy']==='no-referrer','first-print controller accepts the managed bridge query and disables storage/referrer leakage');
$preview=$controller->details($id,$sales);check($preview->data['preview']===true,'the real details controller remains a read-only preview for branch users');
foreach(['dine'=>new PrintTestDiningController,'phone'=>new PrintTestPhoneController] as $channel=>$serviceController){
    [$paid,,$paidQuote]=newSale();$ticketId=ticketFixture($channel,$paidQuote,$paid['receipt']['id']);
    $branchTicket=$tickets->show($channel,$ticketId,$cashier)['ticket'];
    check($branchTicket['receipt_url']===null&&$branchTicket['bill_print_url']===null&&!$branchTicket['can_reprint'],'paid '.$channel.' listing/show cannot expose a branch print bypass');
    denied(fn()=>$serviceController->print(\Illuminate\Http\Request::create('/print'),$ticketId,$tickets),403,'paid '.$channel.' alternate print route enforces the same policy');
    $firstResponse=$serviceController->print(\Illuminate\Http\Request::create('/print?initial_print='.token($paid['first_print_url'])),$ticketId,$tickets);
    check($firstResponse->data['receipt']['id']===$paid['receipt']['id'],'paid '.$channel.' can consume only its original first-print grant');
    denied(fn()=>$serviceController->print(\Illuminate\Http\Request::create('/print?initial_print='.token($paid['first_print_url'])),$ticketId,$tickets),403,'paid '.$channel.' alternate route cannot replay the consumed grant');
    check($serviceController->details($ticketId,$tickets)->data['preview']===true,'paid '.$channel.' branch invoice details remain available');
    $currentActor=$admin;check($tickets->show($channel,$ticketId,$admin)['ticket']['can_reprint']&&$serviceController->print(\Illuminate\Http\Request::create('/print'),$ticketId,$tickets)->data['receipt']['id']===$paid['receipt']['id'],'central admin retains '.$channel.' paid-receipt copies');$currentActor=$cashier;
    $unpaidId=ticketFixture($channel,$paidQuote);$unpaid=$serviceController->print(\Illuminate\Http\Request::create('/print'),$unpaidId,$tickets);
    check($unpaid->name==='admin.pos_service.ticket','unpaid '.$channel.' issued payment bill remains printable');
}
$unpaidId=ticketFixture('dine',$quote);$settleValues=['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>(string)Str::uuid(),'quote_hash'=>$quote['quote_hash'],'payment_method'=>'cash','cash_received'=>'30.00','customer_phone'=>'01012345678'];
$settled=$tickets->settle('dine',$unpaidId,$settleValues,$cashier);$settleReplay=$tickets->settle('dine',$unpaidId,$settleValues,$cashier);$settleRecovery=$tickets->recover('dine',['branch'=>'f:100','idempotency_key'=>$settleValues['idempotency_key']],$cashier);
check($settled['first_print_url']!==null&&$settleReplay['replayed']&&$settleReplay['first_print_url']===$settled['first_print_url']&&$settleRecovery['first_print_url']===$settled['first_print_url'],'service settlement and exact operation recovery preserve one unused grant');
$sales->printReceipt($settled['receipt']['id'],$cashier,token($settled['first_print_url']));
check($tickets->recover('dine',['branch'=>'f:100','idempotency_key'=>$settleValues['idempotency_key']],$cashier)['first_print_url']===null,'service operation recovery cannot renew a consumed receipt grant');
[$racing]=newSale();$raceId=$racing['receipt']['id'];$raceDb=tempnam(sys_get_temp_dir(),'receipt-grant-race-');unlink($raceDb);
$pdo=DB::connection()->getPdo();$pdo->exec('VACUUM INTO '.$pdo->quote($raceDb));$baseline=fiscalState();$workers=[];
try{
    foreach([1,2] as $worker){$pipes=[];$environment=array_merge(getenv(),['PRINT_POLICY_RACE_DB'=>$raceDb,'PRINT_POLICY_RACE_WORKER'=>(string)$worker,'PRINT_POLICY_RACE_ORDER'=>(string)$raceId,'PRINT_POLICY_RACE_TOKEN'=>token($racing['first_print_url'])]);$command=[PHP_BINARY];if(php_ini_loaded_file())$command=array_merge($command,['-c',php_ini_loaded_file()]);$command[]=__FILE__;$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);if(!is_resource($process))throw new RuntimeException('Cannot launch race fixture');fclose($pipes[0]);$workers[]=[$process,$pipes];}
    $outcomes=[];foreach($workers as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);check($code===0&&in_array($out,['ACCEPTED','DENIED','CONFLICT'],true),'concurrent grant worker completed without returning an unauthorized receipt'.($code?' '.$errors:''));$outcomes[]=$out;}
    check(count(array_filter($outcomes,fn($value)=>$value==='ACCEPTED'))===1,'simultaneous use of one unused grant delivers at most one receipt');
    $capsule->addConnection(['driver'=>'sqlite','database'=>$raceDb,'prefix'=>''],'race');$race=DB::connection('race');$raceMeta=json_decode($race->table('takeaway_till_entries')->where('order_id',$raceId)->value('metadata'),true);
    check($raceMeta['receipt_print']['consumed_at']!==null,'concurrent grant consumption remains durably recorded');
    check([$race->table('takeaway_orders')->count(),$race->table('takeaway_till_entries')->count(),(int)$race->table('takeaway_tills')->sum('balance_cents')]===array_slice($baseline,0,3),'concurrent receipt delivery preserves all recorded fiscal counts and cash');
}finally{DB::purge('race');foreach([$raceDb,$raceDb.'.ready-1',$raceDb.'.ready-2',$raceDb.'-journal'] as $file)if(is_file($file))unlink($file);}
echo $count.' receipt first-print/reprint policy checks passed'.PHP_EOL;
