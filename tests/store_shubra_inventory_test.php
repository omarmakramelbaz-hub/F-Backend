<?php
/** Isolated real stock services, SQLite ledgers, recipe portions and rollback. */
require getenv('STORE_SHUBRA_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) return;
    $path=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php';
    if (is_file($path)) require_once $path;
},true,true);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Facade,Schema};
use App\Services\Dashboard\{BranchInventory,BranchStock};
use Symfony\Component\HttpKernel\Exception\HttpException;

function app($class=null){$c=Container::getInstance();return $class?$c->make($class):$c;}
function now($zone=null){return \Carbon\Carbon::now($zone);}
function abort_unless($condition,$status,$message=''){if(!$condition)throw new HttpException($status,$message);}
function abort_if($condition,$status,$message=''){if($condition)throw new HttpException($status,$message);}
// Only the order value object is substituted; both stock services and migrations are real.
class StockTestOrder {public $id;public $resturant_id;public $type='current';}
class_alias(StockTestOrder::class,'App\\Models\\Order');
function database_path($path){return dirname(__DIR__).'/database/'.$path;}
$checks=0;
function checkStock($condition,$message){global $checks;$checks++;if(!$condition)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$c=new Container;Container::setInstance($c);$db=new Capsule($c);
$db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);$db->setAsGlobal();$db->bootEloquent();
$c->instance('db',$db->getDatabaseManager());$c->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());Facade::setFacadeApplication($c);
Schema::create('resturants',function(Blueprint $t){$t->id();$t->string('name');$t->integer('parent_id')->nullable();});
DB::table('resturants')->insert([['id'=>301,'name'=>'فرع شبرا','parent_id'=>null],['id'=>309,'name'=>'المقطم ستور','parent_id'=>301],['id'=>363,'name'=>'فرع المنصورة','parent_id'=>null]]);
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->integer('resturant_id');$t->integer('product_id');$t->string('product_name');});
DB::table('resturant_products')->insert([
 ['id'=>11,'resturant_id'=>301,'product_id'=>101,'product_name'=>'فسيخ كيلو 4 سمكات'],
 ['id'=>12,'resturant_id'=>301,'product_id'=>102,'product_name'=>'وجبة فسيخ'],
 ['id'=>13,'resturant_id'=>301,'product_id'=>103,'product_name'=>'منتج مباشر'],
 ['id'=>21,'resturant_id'=>309,'product_id'=>201,'product_name'=>'فسيخ كيلو 4 سمكات'],
 ['id'=>22,'resturant_id'=>309,'product_id'=>202,'product_name'=>'وجبة فسيخ'],
 ['id'=>23,'resturant_id'=>309,'product_id'=>203,'product_name'=>'منتج مباشر'],
 ['id'=>31,'resturant_id'=>363,'product_id'=>301,'product_name'=>'فسيخ كيلو 4 سمكات'],
]);
Schema::create('product_features',function(Blueprint $t){$t->id();$t->integer('product_id');$t->string('name');});
DB::table('product_features')->insert([['id'=>1011,'product_id'=>101,'name'=>'quarter'],['id'=>2011,'product_id'=>201,'name'=>'quarter']]);
Schema::create('carts',function(Blueprint $t){$t->id();$t->integer('order_id');$t->integer('resturant_product_id');$t->integer('product_feature')->nullable();$t->string('qty');});
foreach(['2026_10_04_190000_create_branch_stock.php'=>'CreateBranchStock','2026_10_04_210000_create_branch_inventory_recipes.php'=>'CreateBranchInventoryRecipes'] as $file=>$class){require database_path('migrations/'.$file);(new $class)->up();}
$ingredient=(int)DB::table('stock_ingredients')->where('name','فسيخ كيلو 4 سمكات')->value('id');
foreach(['f:301','f:309','f:363'] as $branch)DB::table('branch_inventory')->insert(['branch'=>$branch,'ingredient_id'=>$ingredient,'quantity_units'=>10000000,'revision'=>1]);
DB::table('branch_stock_recipes')->insert(['branch'=>'f:301','product_id'=>12,'updated_by'=>1,'unit'=>'piece','variants'=>json_encode(['0'=>[['ingredient_id'=>$ingredient,'name'=>'فسيخ كيلو 4 سمكات','unit'=>'kg','quantity_units'=>250000]]]),'revision'=>1]);
DB::table('branch_stock')->insert(['branch'=>'f:301','product_id'=>13,'unit'=>'kg','quantity_units'=>10000000,'revision'=>1]);
function orderStock($id,$branch,$rows){$o=new StockTestOrder;$o->id=$id;$o->resturant_id=$branch;foreach($rows as $row)DB::table('carts')->insert(['order_id'=>$id,'resturant_product_id'=>$row[0],'product_feature'=>$row[1],'qty'=>$row[2]]);return $o;}
function balanceStock($branch){global $ingredient;return (int)DB::table('branch_inventory')->where('branch',$branch)->where('ingredient_id',$ingredient)->value('quantity_units');}
$o=orderStock(1,309,[[21,2011,'3'],[22,null,'2'],[23,null,'1.25']]);
DB::transaction(fn()=>app(BranchStock::class)->appSale($o));
checkStock(balanceStock('f:301')===8750000,'store quarter portions and prepared meals consume Shubra ingredients exactly');
checkStock(balanceStock('f:309')===10000000&&balanceStock('f:363')===10000000,'store and other physical branch ingredient balances stay unchanged');
checkStock((int)DB::table('branch_stock')->where('product_id',13)->value('quantity_units')===8750000,'direct SKU maps to the Shubra SKU and preserves fractional kilograms');
$audit=json_decode(DB::table('branch_recipe_sales')->where('source_id','1')->value('snapshot'),true);
checkStock($audit['lines'][0]['origin_branch']==='f:309'&&$audit['lines'][0]['origin_product_id']===21&&$audit['lines'][0]['origin_feature_id']===2011,'stock audit keeps the original store SKU and feature');
checkStock($o->resturant_id===309,'fulfillment never rewrites the customer order origin');
DB::transaction(fn()=>app(BranchStock::class)->appSale($o));
checkStock(balanceStock('f:301')===8750000&&DB::table('branch_recipe_sales')->count()===1,'completion retry cannot consume Shubra twice');
$physical=orderStock(2,363,[[31,null,'1']]);DB::transaction(fn()=>app(BranchStock::class)->appSale($physical));
checkStock(balanceStock('f:363')===9000000,'ordinary physical branch still consumes its own goods');
$old=orderStock(3,309,[[21,2011,'1']]);DB::table('branch_recipe_sales')->insert(['branch'=>'f:309','source_type'=>'app','source_id'=>'3','snapshot'=>'{}']);DB::transaction(fn()=>app(BranchStock::class)->appSale($old));
checkStock(balanceStock('f:301')===8750000,'historical deduction at the old store is never replayed into Shubra');
DB::table('resturant_products')->insert(['id'=>24,'resturant_id'=>309,'product_id'=>204,'product_name'=>'صنف غير مربوط']);$bad=orderStock(4,309,[[21,2011,'1'],[24,null,'1']]);
try{DB::transaction(fn()=>app(BranchStock::class)->appSale($bad));throw new RuntimeException('Expected missing SKU rejection');}catch(HttpException $error){checkStock($error->getStatusCode()===422&&balanceStock('f:301')===8750000&&!DB::table('branch_recipe_sales')->where('source_id','4')->exists(),'missing Shubra SKU fails atomically before any inventory movement');}
DB::table('resturant_products')->insert([['id'=>14,'resturant_id'=>301,'product_id'=>104,'product_name'=>'وجبة بلا وصفة'],['id'=>25,'resturant_id'=>309,'product_id'=>204,'product_name'=>'وجبة بلا وصفة']]);
$noRecipe=orderStock(6,309,[[25,null,'1']]);
try{DB::transaction(fn()=>app(BranchStock::class)->appSale($noRecipe));throw new RuntimeException('Expected missing recipe rejection');}catch(HttpException $error){checkStock($error->getStatusCode()===422&&!DB::table('branch_recipe_sales')->where('source_id','6')->exists(),'store completion cannot silently omit an unconfigured Shubra recipe');}
$missing=orderStock(7,309,[[999,null,'1']]);
try{DB::transaction(fn()=>app(BranchStock::class)->appSale($missing));throw new RuntimeException('Expected missing source SKU rejection');}catch(HttpException $error){checkStock($error->getStatusCode()===422&&!DB::table('branch_recipe_sales')->where('source_id','7')->exists(),'deleted store SKU cannot create a partial successful deduction');}
// Older installations without recipes still consume the canonical Shubra direct SKU.
Schema::drop('branch_recipe_sales');
DB::table('branch_stock')->insert(['branch'=>'f:301','product_id'=>11,'unit'=>'kg','quantity_units'=>5000000,'revision'=>1]);
$fallback=orderStock(5,309,[[21,2011,'2']]);DB::transaction(fn()=>app(BranchStock::class)->appSale($fallback));
checkStock((int)DB::table('branch_stock')->where('product_id',11)->value('quantity_units')===4500000,'legacy direct-stock fallback maps the store quarter SKU to Shubra');
DB::transaction(fn()=>app(BranchStock::class)->appSale($fallback));
checkStock((int)DB::table('branch_stock')->where('product_id',11)->value('quantity_units')===4500000,'legacy fallback retry cannot duplicate its deduction');
try{DB::transaction(fn()=>app(BranchStock::class)->appSale($noRecipe));throw new RuntimeException('Expected missing direct balance rejection');}catch(HttpException $error){checkStock($error->getStatusCode()===422,'legacy store completion cannot silently omit an untracked Shubra SKU');}
try{DB::transaction(fn()=>app(BranchStock::class)->appSale($missing));throw new RuntimeException('Expected missing fallback source SKU rejection');}catch(HttpException $error){checkStock($error->getStatusCode()===422,'legacy fallback also rejects a deleted store SKU');}
echo 'STORE_SHUBRA_INVENTORY_OK checks='.$checks.PHP_EOL;
