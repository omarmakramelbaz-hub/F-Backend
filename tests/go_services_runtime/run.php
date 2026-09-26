<?php
/** Isolated tests of the real Marketplace service and real migration. No gateway calls. */
require __DIR__.'/vendor/autoload.php';
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\GoServices\Marketplace;
use App\Services\GoServices\Money;
use App\Services\GoServices\Payments;
use App\Services\GoServices\PaymobHmac;
$root=dirname(__DIR__,2);
foreach(['Money','PaymobHmac','Marketplace','Payments'] as $class)require $root.'/app/Services/GoServices/'.$class.'.php';
require $root.'/database/migrations/2026_09_26_090000_create_go_service_marketplace.php';
function now(){return Carbon::now('UTC');}
function abort($code,$message=''){throw new DomainException($message,(int)$code);}
function abort_unless($condition,$code,$message=''){if(!$condition)abort($code,$message);}
function abort_if($condition,$code,$message=''){if($condition)abort($code,$message);}
function config($key,$default=null){$a=$GLOBALS['settings'];foreach(explode('.',$key) as $part){if(!is_array($a)||!array_key_exists($part,$a))return $default;$a=$a[$part];}return $a;}
$settings=['go_services'=>['enabled'=>true,'batch_size'=>2,'batch_seconds'=>120,'search_minutes'=>60,'offer_minutes'=>30,'payment_minutes'=>10,'max_recipients'=>100,'max_open_jobs'=>5,'paymob'=>['enabled'=>false,'secret_key'=>'test-only','public_key'=>'test-only','hmac_secret'=>'fixture-hmac','is_live'=>false,'methods'=>['card'=>9]]],'settings'=>['cache'=>['enabled'=>false],'default_repository'=>'database']];
$app=new Container();$capsule=new Capsule($app);$mysql=getenv('TEST_DB')==='mysql';
$capsule->addConnection($mysql?['driver'=>'mysql','host'=>'127.0.0.1','port'=>3306,'database'=>'go_test','username'=>'root','password'=>'test-only','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'']:['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
$capsule->setAsGlobal();$capsule->bootEloquent();$app->instance('db',$capsule->getDatabaseManager());$app->bind('db.schema',fn()=> $capsule->getConnection()->getSchemaBuilder());Facade::setFacadeApplication($app);
$assertions=0;
function eq($a,$b,$label){global $assertions;++$assertions;if($a!==$b)throw new RuntimeException($label.': '.var_export($a,true).' != '.var_export($b,true));}
function deny(callable $f,int $code,string $label){try{$f();throw new RuntimeException($label.' was allowed');}catch(DomainException $e){eq($e->getCode(),$code,$label);}}
function bal(int $id):int{return Money::minor(DB::table('users')->where('id',$id)->value('balance'));}
function appBal():int{return Money::minor(json_decode(DB::table('settings')->where('name','app_balance')->value('payload'),true));}
function resetDb():void {
 Carbon::setTestNow(Carbon::parse('2026-09-26 10:00:00','UTC'));
 Schema::disableForeignKeyConstraints();foreach(['outbox','payment_receipts','payments','ledger','assignments','offers','recipients','jobs'] as $n)Schema::dropIfExists('go_service_'.$n);foreach(['users','pending_vendors','settings','wallets'] as $n)Schema::dropIfExists($n);Schema::enableForeignKeyConstraints();
 Schema::create('users',function(Blueprint $t){$t->bigIncrements('id');$t->string('name');$t->string('status');$t->string('account_type');$t->string('app_scope');$t->string('connected');$t->unsignedBigInteger('pending_vendor_id')->nullable();$t->decimal('delegate_fees',6,2)->nullable();$t->decimal('balance',14,2);$t->string('mobile')->default('1010000000');$t->string('email')->default('fixture@example.test');});
 Schema::create('pending_vendors',function(Blueprint $t){$t->bigIncrements('id');$t->string('application_kind');$t->string('status');$t->string('profession_key');$t->decimal('lat',10,7)->nullable();$t->decimal('lng',11,7)->nullable();$t->integer('work_radius_km');});
 Schema::create('settings',function(Blueprint $t){$t->bigIncrements('id');$t->string('group');$t->string('name');$t->text('payload');});
 Schema::create('wallets',function(Blueprint $t){$t->bigIncrements('id');$t->unsignedBigInteger('from_user')->nullable();$t->unsignedBigInteger('to_user')->nullable();$t->string('status');$t->string('payment');$t->string('type');$t->decimal('amount',14,2);$t->timestamps();});
 (new CreateGoServiceMarketplace())->up();
 DB::table('settings')->insert(['group'=>'general','name'=>'app_balance','payload'=>'"1000.00"']);
 foreach([1,2] as $id)DB::table('users')->insert(['id'=>$id,'name'=>'Customer '.$id,'status'=>'accepted','account_type'=>'user','app_scope'=>'go','connected'=>'active','balance'=>'1000.00']);
 for($id=10;$id<=15;$id++){
  DB::table('pending_vendors')->insert(['id'=>$id,'application_kind'=>'partner','status'=>'accepted','profession_key'=>'plumber','lat'=>30,'lng'=>31+($id-10)*0.001,'work_radius_km'=>5]);
  DB::table('users')->insert(['id'=>$id,'name'=>'Partner '.$id,'status'=>'accepted','account_type'=>'delegate','app_scope'=>'go_partner','connected'=>'active','balance'=>'100.00','delegate_fees'=>'10.00','pending_vendor_id'=>$id]);
 }
}
function job(Marketplace $m,string $key='test-job-key-0001'):int{return $m->create(1,['request_key'=>$key,'profession_key'=>'plumber','description'=>'Fix the kitchen sink and leaking pipe','area'=>'Fixture area','address'=>'Private fixture address','phone'=>'01010000000','lat'=>30,'lng'=>31,'photos'=>[]],hash('sha256',$key));}
function offer(Marketplace $m,int $job,int $partner=10,string $price='100.00'):int{return $m->quote($job,$partner,['price'=>$price,'scope'=>'Fix pipe, workmanship only','materials_included'=>false,'arrival_minutes'=>30,'duration_minutes'=>60]);}
$m=new Marketplace();resetDb();$j=job($m);
eq(DB::table('go_service_recipients')->where('job_id',$j)->count(),2,'initial batch');eq(job($m),$j,'idempotent create');
$o=offer($m,$j);eq(bal(10),10000,'quote charges nothing');eq(offer($m,$j),$o,'idempotent quote');
eq($m->read($j,10)['location'],null,'unselected address hidden');eq($m->read($j,10)['phone'],null,'unselected phone hidden');deny(fn()=> $m->read($j,2),403,'other customer denied');deny(fn()=>offer($m,$j,15),403,'uninvited quote denied');
$m->reject($j,$o,1);eq(DB::table('go_service_recipients')->where('job_id',$j)->count(),4,'rejection dispatches next batch');$m->reject($j,$o,1);eq(DB::table('go_service_recipients')->where('job_id',$j)->count(),4,'rejection retry no extra wave');eq(bal(10),10000,'rejection charges nothing');deny(fn()=> $m->accept($j,$o,1,'cash'),409,'rejected offer denied');
$o2=offer($m,$j,11);$m->accept($j,$o2,1,'cash');$m->accept($j,$o2,1,'cash');eq(bal(11),9000,'commission once');eq(appBal(),101000,'main app wallet credited');eq(DB::table('go_service_ledger')->where('kind','commission')->count(),1,'one commission record');eq($m->read($j,11)['location']['address'],'Private fixture address','selected address visible');
$m->transition($j,11,'in_progress');$m->transition($j,11,'awaiting_confirmation');$m->transition($j,1,'completed');$m->transition($j,1,'completed');eq(bal(11),9000,'cash no second commission');eq(DB::table('go_service_assignments')->count(),0,'reservation released');
resetDb();$j=job($m);$o=offer($m,$j);$m->accept($j,$o,1,'wallet');eq(bal(1),90000,'customer funds held');$m->transition($j,10,'in_progress');$m->transition($j,10,'awaiting_confirmation');$m->transition($j,1,'completed');$m->transition($j,1,'completed');eq(bal(10),19000,'gross payout avoids double commission');eq(appBal(),101000,'only commission is revenue');
resetDb();$j=job($m);$o=offer($m,$j);$m->accept($j,$o,1,'wallet');$m->transition($j,1,'cancelled','Changed plan');$m->transition($j,1,'cancelled','Retry');eq(bal(1),100000,'hold refunded once');eq(bal(10),10000,'commission refunded once');eq(appBal(),100000,'main wallet reversal');
resetDb();$j=job($m);$o=offer($m,$j);DB::table('users')->where('id',1)->update(['balance'=>0]);deny(fn()=> $m->accept($j,$o,1,'wallet'),422,'insufficient customer funds');eq(bal(10),10000,'partner debit rolled back');eq(appBal(),100000,'app credit rolled back');eq(DB::table('go_service_assignments')->count(),0,'reservation rolled back');eq(DB::table('go_service_ledger')->count(),0,'ledger rolled back');
DB::table('users')->where('id',10)->update(['connected'=>'inactive']);deny(fn()=> $m->accept($j,$o,1,'cash'),409,'offline partner denied');DB::table('users')->where('id',10)->update(['connected'=>'active','delegate_fees'=>20]);deny(fn()=> $m->accept($j,$o,1,'cash'),409,'changed commission not silently charged');
resetDb();$j=job($m);$o=offer($m,$j);$m->accept($j,$o,1,'cash');$m->transition($j,10,'in_progress');$m->transition($j,1,'disputed','Work not as agreed');deny(fn()=> $m->transition($j,1,'completed'),409,'dispute blocks payout');
resetDb();$settings['go_services']['paymob']['enabled']=true;$j=job($m);$o=offer($m,$j);$m->accept($j,$o,1,'card');
DB::table('go_service_payments')->insert(['job_id'=>$j,'reference'=>'00000000-0000-0000-0000-000000000001','integration_id'=>9,'amount_cents'=>10000,'gateway_order_id'=>'200','status'=>'pending','expires_at'=>now()->addMinutes(10),'created_at'=>now(),'updated_at'=>now()]);
$p=new Payments();$obj=['id'=>300,'order'=>['id'=>200],'amount_cents'=>10000,'currency'=>'EGP','integration_id'=>9,'is_live'=>false,'success'=>true,'pending'=>false,'is_auth'=>false,'is_capture'=>false,'is_standalone_payment'=>true,'error_occured'=>false];$sig=PaymobHmac::digest($obj,'fixture-hmac');
$bad=$obj;$bad['amount_cents']=1;deny(fn()=> $p->callback($bad,$sig),403,'forged payment denied');
$p->callback($obj,$sig);$p->callback($obj,$sig);eq(DB::table('go_service_payment_receipts')->count(),1,'duplicate callback once');eq((int)DB::table('go_service_jobs')->where('id',$j)->value('held_cents'),10000,'gateway funds held');eq(appBal(),101000,'gateway receipt not revenue');
$obj['id']=301;$p->callback($obj,PaymobHmac::digest($obj,'fixture-hmac'));eq(DB::table('go_service_payment_receipts')->where('status','refund_due')->count(),1,'duplicate capture requires refund');
$m->transition($j,1,'cancelled','Cancelled before work');eq($m->read($j,1)['payment_status'],'refund_pending','gateway refund is not faked');eq(appBal(),100000,'gateway cancellation commission reversal');
resetDb();$j=job($m);$o=offer($m,$j);$m->accept($j,$o,1,'card');Carbon::setTestNow(now()->addMinutes(11));$m->transition($j,0,'cancelled','Payment timeout',true);eq(bal(10),10000,'expired reservation reverses commission');
resetDb();$j=job($m);Carbon::setTestNow(now()->addMinutes(61));$m->distribute($j);eq($m->read($j,1)['status'],'expired','finite search lifetime');
if($mysql&&function_exists('pcntl_fork')){
 resetDb();$j=job($m);$a=offer($m,$j,10);$b=offer($m,$j,11);DB::disconnect();$children=[];
 foreach([$a,$b] as $o){$pid=pcntl_fork();if($pid===0){try{DB::reconnect();$m->accept($j,$o,1,'cash');exit(0);}catch(DomainException $e){exit($e->getCode()===409?0:2);}catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(3);}}$children[]=$pid;}
 foreach($children as $pid){pcntl_waitpid($pid,$status);eq(pcntl_wexitstatus($status),0,'concurrent accept result');}DB::reconnect();
 eq(DB::table('go_service_ledger')->where('kind','commission')->count(),1,'concurrent one winner');eq(bal(10)+bal(11),19000,'concurrent one debit');eq(appBal(),101000,'concurrent one app credit');
}
echo 'PASS '.$assertions.' marketplace assertions on '.($mysql?'MySQL':'SQLite')."\n";
