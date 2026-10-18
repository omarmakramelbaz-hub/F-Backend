<?php
/** Read-only home stock projection against isolated SQLite; no .env or mail transport. */
$autoload=getenv('HOME_MINIMUM_TEST_AUTOLOAD')?:dirname(__DIR__).'/vendor/autoload.php';
if(!is_file($autoload))throw new RuntimeException('Set HOME_MINIMUM_TEST_AUTOLOAD to an Illuminate 8 compatible Composer autoloader.');
require $autoload;
spl_autoload_register(function($class){
    if(strpos($class,'App\\')!==0)return;
    $path=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require_once $path;
},true,true);

use App\Services\Dashboard\{HomeOverview,TakeawayAccess};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Facade,Schema};

function app($abstract=null,array $parameters=[]){$c=Container::getInstance();return $abstract?$c->make($abstract,$parameters):$c;}
$container=new Container;Container::setInstance($container);
$capsule=new Capsule($container);$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);$capsule->setAsGlobal();
$container->instance('db',$capsule->getDatabaseManager());$container->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());Facade::setFacadeApplication($container);
Schema::create('stock_ingredients',function(Blueprint $t){$t->id();$t->string('name');$t->string('unit');$t->integer('position');});
Schema::create('branch_inventory',function(Blueprint $t){$t->id();$t->string('branch');$t->unsignedBigInteger('ingredient_id');$t->bigInteger('quantity_units');});
foreach([[1,'kg'],[2,'piece'],[3,'kg'],[4,'kg'],[5,'piece'],[6,'kg'],[7,'kg']] as [$id,$unit])DB::table('stock_ingredients')->insert(['id'=>$id,'name'=>'Item '.$id,'unit'=>$unit,'position'=>$id]);
foreach([
    ['f:100',1,1125000],['f:101',1,100000000],
    ['f:100',2,0],['f:100',3,-1000000],['f:100',4,2000000],
    ['f:100',6,4000000],['f:100',7,2000000],['f:999',7,1000000],
] as [$branch,$id,$quantity])DB::table('branch_inventory')->insert(['branch'=>$branch,'ingredient_id'=>$id,'quantity_units'=>$quantity]);
$projection=new ReflectionMethod(HomeOverview::class,'inventory');$projection->setAccessible(true);
function homeStock(array $branches=['f:100','f:101'],bool $matrix=true,bool $ready=true,?HomeOverview $service=null): array{
    global $projection;return $projection->invoke($service?:new HomeOverview(new TakeawayAccess),$branches,$ready,$matrix);
}
function items(array $result): array{return array_column($result['items'],null,'id');}
$checks=0;
function check($ok,string $label): void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo 'PASS '.$label.PHP_EOL;}

$legacy=homeStock();$rows=items($legacy);
check(!$rows[1]['is_low']&&!$rows[1]['balances']['f:100']['is_low'],'missing minimum schema keeps tracked stock available and normal');
check($rows[1]['quantity']==='101.125'&&$rows[1]['balances']['f:100']['quantity']==='1.125','aggregate and branch quantity formatting is unchanged');
check($rows[5]['balances']['f:100']===null&&$rows[5]['tracked_branches']===0,'unrecorded opening stock remains unknown');
check($rows[3]['negative_branches']===1&&count($legacy['attention'])===2,'existing negative and zero attention statuses survive without minimums');
check(homeStock([],true,false)===['items'=>[],'attention'=>[],'tracked'=>0,'unconfigured_recipes'=>0],'unavailable inventory retains the existing empty contract');

require dirname(__DIR__).'/database/migrations/2026_10_18_230000_create_branch_stock_minimum_alerts.php';(new CreateBranchStockMinimumAlerts)->up();
foreach([
    ['f:100','ingredient',1,'kg',1125000],['f:101','ingredient',1,'kg',1000000],
    ['f:100','ingredient',2,'piece',0],['f:100','ingredient',3,'kg',null],
    ['f:100','ingredient',4,'piece',3000000],['f:100','ingredient',5,'piece',0],
    ['f:100','product',6,'kg',5000000],['f:999','ingredient',7,'kg',5000000],
] as [$branch,$type,$id,$unit,$minimum])DB::table('branch_stock_minimums')->insert(['branch'=>$branch,'stock_type'=>$type,'stock_item_id'=>$id,'unit'=>$unit,'minimum_units'=>$minimum,'updated_by'=>1]);
// A queued notification must remain completely untouched by a home-page read.
DB::table('branch_stock_minimum_alerts')->insert(['minimum_id'=>1,'episode'=>1,'branch_name'=>'Test branch','item_name'=>'Item 1','unit'=>'kg','balance_units'=>1125000,'minimum_units'=>1125000]);
$beforeMinimums=DB::table('branch_stock_minimums')->get()->toJson();$beforeAlerts=DB::table('branch_stock_minimum_alerts')->get()->toJson();$beforeStock=DB::table('branch_inventory')->get()->toJson();
DB::connection()->enableQueryLog();DB::connection()->flushQueryLog();
$service=new HomeOverview(new TakeawayAccess);$result=homeStock(['f:100','f:101'],true,true,$service);$queries=DB::connection()->getQueryLog();$rows=items($result);
check($rows[1]['balances']['f:100']['is_low']&& !$rows[1]['balances']['f:101']['is_low'],'exact fractional minimum is low only in its own branch');
check($rows[1]['is_low']&&$rows[1]['quantity']==='101.125','healthy branch aggregate cannot conceal another branch at minimum');
check($rows[2]['is_low']&&$rows[2]['balances']['f:100']['is_low'],'zero is an enabled minimum for a recorded zero piece balance');
check(!$rows[3]['is_low']&&$rows[3]['negative_branches']===1,'null disables minimum while retaining existing negative status');
check(!$rows[4]['is_low'],'a stale setting with a different unit is ignored');
check(!$rows[5]['is_low']&&$rows[5]['balances']['f:100']===null,'configured minimum does not turn unrecorded opening stock into zero');
check(!$rows[6]['is_low'],'direct product setting cannot leak into an ingredient with the same id');
check(!$rows[7]['is_low']&&$rows[7]['quantity']==='2'&&!array_key_exists('f:999',$rows[7]['balances']),'inaccessible branch stock and minimum do not leak into the home projection');
check(count($result['attention'])===2&&$result['attention']===$legacy['attention'],'minimum presentation leaves the existing zero and negative alert list unchanged');
$minimumReads=array_values(array_filter($queries,fn($q)=>preg_match('/^select .* from "branch_stock_minimums"/i',$q['query'])));
check(count($minimumReads)===1,'all displayed ingredients and branches use one minimum-data query');
check(count(array_filter($queries,fn($q)=>preg_match('/^(insert|update|delete|replace)\b/i',$q['query'])))===0,'home projection performs no database writes');
check(DB::table('branch_stock_minimums')->get()->toJson()===$beforeMinimums&&DB::table('branch_stock_minimum_alerts')->get()->toJson()===$beforeAlerts&&DB::table('branch_inventory')->get()->toJson()===$beforeStock,'reading home does not change stock episodes or pending email');
$filtered=items(homeStock(['f:101']));
check(!$filtered[1]['is_low']&&array_keys($filtered[1]['balances'])===['f:101'],'branch selection excludes another branch minimum state');
$branch=items(homeStock(['f:100'],false));
check($branch[1]['is_low']&&!array_key_exists('balances',$branch[1]),'branch home receives low state without exposing a central stock matrix');
DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',1)->update(['quantity_units'=>1125001]);
check(!items(homeStock(['f:100'],true,true,$service))[1]['is_low'],'next refresh clears red state immediately after stock rises above minimum');
DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',1)->update(['quantity_units'=>1124999]);
check(items(homeStock(['f:100'],true,true,$service))[1]['is_low'],'a balance one integer stock unit below minimum is low');
check(homeStock([])['tracked']===0&&count(array_filter(items(homeStock([])),fn($i)=>$i['is_low']))===0,'empty authorized branch scope returns no low state');
echo 'HOME_STOCK_MINIMUM_TESTS_OK checks='.$checks.PHP_EOL;
