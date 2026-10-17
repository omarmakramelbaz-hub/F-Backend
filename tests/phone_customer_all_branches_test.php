<?php
// Real lookup and authorization against in-memory SQLite; no app bootstrap, .env or live data.
$autoload=getenv('PHONE_DELIVERY_TEST_AUTOLOAD')?:getenv('CRM_TEST_AUTOLOAD');
if(is_string($autoload)&&is_dir($autoload))$autoload.='/autoload.php';
require $autoload?:dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function($class){if(str_starts_with($class,'App\\Services\\')){$file=dirname(__DIR__).'/app/Services/'.str_replace('\\','/',substr($class,13)).'.php';if(is_file($file))require_once $file;}});

use App\Services\Dashboard\{PosServicePhone,TakeawayAccess};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Facade,Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;

function app($abstract=null){$c=Container::getInstance();return $abstract?$c->make($abstract):$c;}
function abort_unless($condition,$status,$message=''){if(!$condition)throw new HttpException($status,$message);}
function abort_if($condition,$status,$message=''){if($condition)throw new HttpException($status,$message);}
function now($zone=null){return \Carbon\Carbon::now($zone);}
class LookupTestUser extends \Illuminate\Database\Eloquent\Model{protected $table='users';public function can($permission){return false;}}
class_alias(LookupTestUser::class,'App\\Models\\User');
$c=new Container;Container::setInstance($c);$c->instance('config',new \Illuminate\Config\Repository);
$capsule=new Capsule($c);$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);$capsule->setAsGlobal();$capsule->bootEloquent();
$c->instance('db',$capsule->getDatabaseManager());$c->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());Facade::setFacadeApplication($c);
$c->instance('validator',new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en'),$c));

Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();$t->string('mobile')->nullable();$t->string('app_scope')->default('fasakhansta');$t->string('status')->default('accepted');});
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');});
Schema::create('go_stores',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('name');});
Schema::create('orders',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('resturant_id');});
Schema::create('user_address',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('address');$t->decimal('lat',10,7)->nullable();$t->decimal('lng',10,7)->nullable();});
// The lookup uses real POS readiness checks, without replacing its authorization service.
foreach(['2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos','2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets','2026_10_04_060000_lock_pos_service_bills.php'=>'LockPosServiceBills','2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations'] as $file=>$class){require_once dirname(__DIR__).'/database/migrations/'.$file;(new $class)->up();}
foreach([[1,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100],[20,'user',null],[30,'vendor',null],[31,'vendor',null]] as [$id,$type,$owner])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'owner_resturant_id'=>$owner,'app_scope'=>in_array($id,[30,31],true)?'go_partner':'fasakhansta','status'=>$id===31?'pending':'accepted']);
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'المنصورة'],['id'=>101,'user_id'=>11,'name'=>'المحلة']]);
DB::table('go_stores')->insert([['user_id'=>30,'name'=>'متجر مقبول'],['user_id'=>31,'name'=>'متجر معلق']]);
DB::table('users')->where('id',20)->update(['name'=>'عميل التطبيق','mobile'=>'01533333333']);
DB::table('user_address')->insert(['user_id'=>20,'address'=>'عنوان التطبيق','lat'=>30.5,'lng'=>31.5]);

$count=0;
function lookupCheck($ok,$label){global $count;if(!$ok)throw new RuntimeException('PHONE_CUSTOMER_ALL_BRANCHES_FAILED: '.$label);$count++;echo 'PASS '.$label.PHP_EOL;}
function lookupDenied(callable $call,int $status,string $label){try{$call();}catch(HttpException $e){lookupCheck($e->getStatusCode()===$status,$label);return;}catch(\Illuminate\Validation\ValidationException $e){lookupCheck($status===422,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function lookupSaved(string $branch,string $phone,string $name='عميل محفوظ',string $address='عنوان محفوظ',int $revision=1): int{return DB::table('branch_customers')->insertGetId(['branch'=>$branch,'phone'=>$phone,'phone_key'=>\App\Services\Dashboard\BranchCustomers::phoneKey($phone),'name'=>$name,'address'=>$address,'area'=>'المنطقة','revision'=>$revision,'actor_id'=>1,'created_at'=>'2026-10-17 12:00:00','updated_at'=>'2026-10-17 12:00:00']);}
function lookupHistory(string $branch,string $phone,string $address='عنوان محفوظ',float $latitude=31.1): int{return DB::table('pos_service_tickets')->insertGetId(['branch'=>$branch,'channel'=>'phone','actor_id'=>1,'status'=>'new','payment_status'=>'unpaid','revision'=>1,'customer_name'=>'اسم تاريخي','customer_phone'=>$phone,'phone_key'=>\App\Services\Dashboard\BranchCustomers::phoneKey($phone),'address'=>$address,'area'=>'المنطقة','delivery_snapshot'=>json_encode(['latitude'=>$latitude,'longitude'=>31.2]),'cart_snapshot'=>'{}','quote_snapshot'=>'{}','business_date'=>'2026-10-17','created_at'=>'2026-10-17 12:00:00','updated_at'=>'2026-10-17 12:00:00']);}
$central=LookupTestUser::find(1);$staff=LookupTestUser::find(10);$phone=app(PosServicePhone::class);
$mahalla=lookupSaved('f:101','01012345678','عميل المحلة','عنوان محفوظ',7);
foreach(['010123','٠١٠١٢٣','+2010123','002010123','10123'] as $number){
    $result=$phone->customers(['branch'=>'all','phone'=>$number,'prefix'=>true],$central);$item=$result['items'][0];
    lookupCheck(count($result['items'])===1&&$result['branch']['value']==='all'&&$result['items']===$result['customers']&&$result['items']===$result['matches'],'all-branch normalized prefix '.$number);
    lookupCheck($item['branch']==='f:101'&&$item['branch_name']==='المحلة'&&$item['name']==='عميل المحلة'&&$item['phone']==='01012345678'&&$item['customer_id']===$mahalla&&$item['customer_revision']===7,'Mahalla choice contains its real branch and local identity '.$number);
}
foreach([4,10,11,12,20,30] as $id)lookupDenied(fn()=>$phone->customers(['branch'=>'all','phone'=>'010123'],LookupTestUser::find($id)),403,'all lookup rejected for noncentral actor '.$id);
lookupDenied(fn()=>$phone->customers(['branch'=>'f:101','phone'=>'010123'],$staff),404,'staff cannot explicitly select another branch');
lookupDenied(fn()=>$phone->customers(['branch'=>'all','phone'=>'01'],$central),422,'too-short all-branch search rejected');
lookupDenied(fn()=>$phone->customers(['branch'=>'invalid','phone'=>'010123'],$central),422,'invalid lookup scope rejected');
lookupCheck($phone->customers(['branch'=>'f:100','phone'=>'010123','prefix'=>true],$staff)['items']===[],'branch staff cannot find foreign saved customer');

$local=lookupSaved('f:100','01012345678','عميل المنصورة','عنوان محفوظ',3);
lookupHistory('f:100','01012345678','عنوان محفوظ',30.1);lookupHistory('f:101','01012345678','عنوان محفوظ',31.1);
$items=$phone->customers(['branch'=>'all','phone'=>'01012345678'],$central)['items'];$byBranch=array_column($items,null,'branch');
lookupCheck(count($items)===2&&$byBranch['f:100']['customer_id']===$local&&$byBranch['f:100']['customer_revision']===3&&$byBranch['f:101']['customer_id']===$mahalla&&$byBranch['f:101']['customer_revision']===7,'identical phone and address preserve distinct branch records and revisions');
lookupCheck($byBranch['f:100']['latitude']===30.1&&$byBranch['f:101']['latitude']===31.1,'historical coordinates enrich only their matching branch address');
$items=$phone->customers(['branch'=>'f:100','phone'=>'01012345678'],$central)['items'];
lookupCheck(count($items)===1&&$items[0]['branch']==='f:100'&&$items[0]['customer_id']===$local&&$items[0]['branch_name']==='المنصورة','explicit lookup keeps current deduplication and selected-branch identity');
DB::table('branch_customers')->where('id',$mahalla)->update(['address'=>'عنوان آخر']);
$items=$phone->customers(['branch'=>'f:100','phone'=>'01012345678'],$central)['items'];
lookupCheck(count($items)===2&&count(array_filter($items,fn($item)=>$item['branch']==='f:100'&&$item['customer_id']===$local&&$item['customer_revision']===3))===2,'foreign alternate address remains effective in explicitly selected branch');

$history=lookupHistory('f:101','01244444444','عنوان من طلب قديم');
$item=$phone->customers(['branch'=>'all','phone'=>'01244444444'],$central)['items'][0];
lookupCheck($item['branch']==='f:101'&&$item['branch_name']==='المحلة'&&$item['last_ticket_id']===$history&&$item['customer_id']===null&&$item['customer_revision']===null,'history-only suggestion retains source branch without borrowing another customer id');
$go=lookupSaved('gs:30','01155555555');lookupSaved('gs:31','01155555555');lookupSaved('f:999','01155555555');lookupHistory('gs:31','01155555555');lookupHistory('f:999','01155555555');
$items=$phone->customers(['branch'=>'all','phone'=>'01155555555'],$central)['items'];
lookupCheck(count($items)===1&&$items[0]['branch']==='gs:30'&&$items[0]['branch_name']==='متجر مقبول'&&$items[0]['customer_id']===$go,'all scope includes accepted GO store and excludes unknown or unapproved branches');
lookupCheck($phone->customers(['branch'=>'f:100','phone'=>'01155555555'],$central)['items']===[],'explicit Fasakhansta scope does not expand to GO stores');
$item=$phone->customers(['branch'=>'all','phone'=>'01533333333'],$central)['items'][0];
lookupCheck($item['name']==='عميل التطبيق'&&$item['branch']===null&&$item['branch_name']===null&&$item['customer_id']===null&&$item['latitude']===30.5,'unassigned app profile is found without guessing its branch or local identity');
lookupCheck($phone->customers(['branch'=>'f:100','phone'=>'01533333333'],$staff)['items']===[],'app lookup still requires branch history for branch staff');
DB::table('orders')->insert(['user_id'=>20,'resturant_id'=>100]);
$item=$phone->customers(['branch'=>'f:100','phone'=>'01533333333'],$staff)['items'][0];
lookupCheck($item['branch']==='f:100'&&$item['name']==='عميل التطبيق'&&$item['customer_id']===null,'explicit app lookup still restores authorized branch customer');
foreach(range(1,12) as $n)lookupSaved('f:101','017'.str_pad((string)$n,8,'0',STR_PAD_LEFT));
lookupCheck(count($phone->customers(['branch'=>'all','phone'=>'017','prefix'=>true],$central)['items'])===8,'all lookup retains eight-choice bound');

$queries=[];DB::connection()->setEventDispatcher(new \Illuminate\Events\Dispatcher(app()));DB::listen(function($query)use(&$queries){$queries[]=$query->sql;});
$phone->customers(['branch'=>'all','phone'=>'01012345678'],$central);
lookupCheck(count(array_filter($queries,fn($sql)=>preg_match('/\A\s*(insert|update|delete|replace)\b/i',$sql)))===0,'customer suggestions and identity enrichment are read-only');
echo 'PHONE_CUSTOMER_ALL_BRANCHES_TESTS='.$count." PASS\n";
