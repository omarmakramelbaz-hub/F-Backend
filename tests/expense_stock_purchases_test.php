<?php
/** Real SQLite migrations, integer stock ledger, transaction rollback and replay checks. */
$autoload = getenv('EXPENSE_STOCK_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
require $autoload;

use App\Services\Dashboard\{BranchInventory, BranchOperations, ExpenseStockPurchases, TakeawayAccess};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\{Factory, ValidationException};
use Symfony\Component\HttpKernel\Exception\HttpException;

if (!function_exists('app')) { function app($abstract = null) { $c=Container::getInstance(); return $abstract === null ? $c : $c->make($abstract); } }
if (!function_exists('now')) { function now($timezone = null) { return \Carbon\Carbon::now($timezone); } }
if (!function_exists('abort_unless')) { function abort_unless($condition, $status, $message = '') { if (!$condition) throw new HttpException($status, $message); } }
if (!function_exists('abort_if')) { function abort_if($condition, $status, $message = '') { if ($condition) throw new HttpException($status, $message); } }

require dirname(__DIR__).'/app/Services/Dashboard/TakeawayAccess.php';
require dirname(__DIR__).'/app/Services/Dashboard/BranchOperations.php';
require dirname(__DIR__).'/app/Services/Dashboard/BranchInventory.php';
require dirname(__DIR__).'/app/Services/Dashboard/ExpenseStockPurchases.php';
require dirname(__DIR__).'/database/migrations/2026_10_04_210000_create_branch_inventory_recipes.php';
require dirname(__DIR__).'/database/migrations/2026_10_18_090000_link_expense_inventory_purchases.php';

$checks = 0;
function purchaseCheck(bool $condition, string $label): void
{
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}
function purchaseInvalid(callable $action, string $label): void
{
    try { $action(); } catch (ValidationException $error) { purchaseCheck(isset($error->errors()['quantity_kg']) || isset($error->errors()['category']), $label); return; }
    throw new RuntimeException('Expected validation failure: '.$label);
}
function purchaseDenied(callable $action, int $status, string $label): void
{
    try { $action(); } catch (HttpException $error) { purchaseCheck($error->getStatusCode() === $status, $label); return; }
    throw new RuntimeException('Expected HTTP '.$status.': '.$label);
}
function purchaseFixture(): ExpenseStockPurchases
{
    $container = new Container; Container::setInstance($container);
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
    $capsule->setAsGlobal(); $capsule->bootEloquent();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
    $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    $container->instance(BranchInventory::class, new BranchInventory(new BranchOperations(new TakeawayAccess)));
    Schema::create('resturants', fn(Blueprint $table) => $table->id());
    DB::table('resturants')->insert([['id'=>100],['id'=>101]]);
    Schema::create('branch_expenses', function (Blueprint $table) { $table->id(); $table->string('branch'); $table->string('status')->default('pending'); $table->string('supplier')->nullable(); });
    (new CreateBranchInventoryRecipes)->up();
    DB::table('branch_expenses')->insert(['id'=>1,'branch'=>'f:100','status'=>'approved','supplier'=>'سجل قديم']);
    (new LinkExpenseInventoryPurchases)->up();
    return new ExpenseStockPurchases;
}
function purchaseExpense(string $branch='f:100', string $status='pending'): int
{
    return DB::table('branch_expenses')->insertGetId(['branch'=>$branch,'status'=>$status,'supplier'=>'مورد تجريبي']);
}
function purchaseValues(string $category='purchase_pepper', string $quantity='2.125', string $branch='f:100'): array
{
    return ['branch'=>$branch,'category'=>$category,'stock_category'=>$category,'quantity_kg'=>$quantity];
}
function purchaseReceive(ExpenseStockPurchases $service, int $id, array $values): void
{
    DB::transaction(function () use ($service, $id, $values) {
        DB::table('resturants')->where('id', (int)substr($values['branch'], 2))->lockForUpdate()->first();
        $service->receive($id, $values, (object)['id'=>7]);
    });
}
function purchaseReverse(ExpenseStockPurchases $service, int $id): void
{
    DB::transaction(function () use ($service, $id) {
        $expense = DB::table('branch_expenses')->where('id', $id)->first();
        DB::table('resturants')->where('id', (int)substr($expense->branch, 2))->lockForUpdate()->first();
        $service->reverse($expense, (object)['id'=>1]);
    });
}
function purchaseBalance(string $name='فلفل', string $branch='f:100'): int
{
    $id=DB::table('stock_ingredients')->where('name',$name)->value('id');
    return (int)DB::table('branch_inventory')->where('branch',$branch)->where('ingredient_id',$id)->value('quantity_units');
}

$service=purchaseFixture();
purchaseCheck($service->ready(), 'new schema is ready without rewriting any historical expense');
purchaseCheck(DB::table('expense_inventory_receipts')->count()===0 && DB::table('branch_inventory_movements')->count()===0, 'migration creates no historical receipts or balance movements');
purchaseCheck(DB::table('stock_ingredients')->whereIn('name',['زيت','خل'])->where('unit','kg')->count()===2, 'oil and vinegar are both available as kilograms');
(new LinkExpenseInventoryPurchases)->up();
purchaseCheck(DB::table('stock_ingredients')->whereIn('name',array_values(ExpenseStockPurchases::CATEGORIES))->count()===6, 'migration retry reuses the six exact ingredient identities');

foreach (['فلفل'=>'purchase_pepper','مشتريات طماطم - المحلة'=>'purchase_tomato','ليمون (فرع نبروه)'=>'purchase_lemon','بصل شبرا الخيمة'=>'purchase_onion','الزيت'=>'purchase_oil','خل المنصوره'=>'purchase_vinegar'] as $label=>$key) {
    purchaseCheck($service->categoryProduct($label)===$key, 'canonical purchase identity: '.$label);
}
foreach (['خضار','فلفل وطماطم','تنظيف بصل','زيت ماكينة','سندوتش بصل','ليموننبروه'] as $label) purchaseCheck($service->categoryProduct($label)===null, 'mixed or unrelated description cannot infer goods: '.$label);
purchaseCheck($service->normalizeQuantity(['quantity_kg'=>'٠٠٢٫١٢٠'],'purchase_pepper')['quantity_kg']==='2.12', 'Arabic decimal quantity is canonicalized without floating point');
purchaseCheck($service->normalizeQuantity(['quantity_kg'=>'0.001'],'purchase_pepper')['quantity_kg']==='0.001', 'one gram is an exact supported input');
purchaseCheck($service->normalizeQuantity(['quantity_kg'=>'1000000.000'],'purchase_pepper')['quantity_kg']==='1000000', 'maximum quantity is exact');
foreach ([null,'','0','0.000','-1','1.0001','1000000.001','1e3','1,5',[],true,1.2] as $value) purchaseInvalid(fn()=>$service->normalizeQuantity(['quantity_kg'=>$value],'purchase_pepper'), 'invalid purchase quantity is blocked: '.json_encode($value));
purchaseInvalid(fn()=>$service->normalizeQuantity(['quantity_kg'=>'1'],null), 'non-purchase expense cannot smuggle a stock quantity');
purchaseInvalid(fn()=>$service->normalizeQuantity(['quantity_kg'=>'1'],'custom_999'), 'untrusted category cannot choose a stock ingredient');
purchaseCheck($service->normalizeQuantity(['stock_category'=>'purchase_oil'],null)['stock_category']===null, 'posted stock category is replaced by trusted server classification');

$id=purchaseExpense(); $values=purchaseValues();
purchaseDenied(fn()=>$service->receive($id,$values,(object)['id'=>7]),503,'standalone receipt outside expense transaction is refused');
purchaseReceive($service,$id,$values);
purchaseCheck(purchaseBalance()===2125000 && DB::table('expense_inventory_receipts')->count()===1, 'pending expense immediately adds the exact kilogram quantity once');
$receipt=DB::table('expense_inventory_receipts')->first();
$movement=DB::table('branch_inventory_movements')->where('id',$receipt->receipt_movement_id)->first();
purchaseCheck($movement->source_type==='expense' && $movement->source_id===(string)$id && $movement->actor_id===7 && $movement->supplier==='مورد تجريبي', 'stock audit points to the actual expense, recorder and supplier');
purchaseReceive($service,$id,$values);
purchaseCheck(purchaseBalance()===2125000 && DB::table('branch_inventory_movements')->count()===1, 'expense replay cannot add the goods twice');
purchaseDenied(fn()=>purchaseReceive($service,$id,purchaseValues('purchase_pepper','3')),409,'same expense cannot be reissued with another kilogram quantity');
purchaseDenied(fn()=>purchaseReceive($service,$id,purchaseValues('purchase_oil','2.125')),409,'same expense cannot be changed to another ingredient');
$wrong=purchaseExpense('f:101');
purchaseDenied(fn()=>purchaseReceive($service,$wrong,purchaseValues()),409,'another branch expense cannot receive into the selected branch stock');
purchaseCheck(purchaseBalance('فلفل','f:101')===0, 'branch balances remain isolated');
$store=purchaseExpense('gs:100');
purchaseDenied(fn()=>purchaseReceive($service,$store,purchaseValues('purchase_pepper','1','gs:100')),422,'restaurant raw goods cannot be written as a Go store balance');

$present=$service->present(DB::table('branch_expenses')->where('id',$id)->first());
purchaseCheck($present['stock_quantity_kg']==='2.125' && $present['stock_item_name']==='فلفل' && !$present['stock_reversed'], 'expense details present immutable stock quantity and item');
DB::table('branch_expenses')->where('id',$id)->update(['status'=>'approved']);
purchaseReceive($service,$id,$values);
purchaseCheck(purchaseBalance()===2125000, 'approval/replay leaves the initial receipt unchanged');
// The same ledger must reverse the original purchase even after stock consumption.
DB::table('branch_inventory')->where('ingredient_id',$receipt->ingredient_id)->update(['quantity_units'=>500000]);
purchaseReverse($service,$id); purchaseReverse($service,$id);
purchaseCheck(purchaseBalance()===-1625000 && DB::table('branch_inventory_movements')->where('source_type','expense_reverse')->count()===1, 'void reverses the original exact purchase once even after partial consumption');
$present=$service->present(DB::table('branch_expenses')->where('id',$id)->first());
purchaseCheck($present['stock_reversed'] && $present['stock_quantity_kg']==='2.125', 'reversal keeps the original audited quantity visible');
purchaseReceive($service,$id,$values);
purchaseCheck(purchaseBalance()===-1625000, 'an old create replay cannot revive a reversed purchase');
purchaseReverse($service,1);
purchaseCheck(DB::table('branch_inventory_movements')->count()===2, 'legacy expense without a receipt never fabricates a negative stock movement');

$pending=purchaseExpense(); purchaseReceive($service,$pending,purchaseValues('purchase_onion','4.5'));
purchaseReverse($service,$pending);
purchaseCheck(purchaseBalance('بصل')===0, 'rejecting a pending expense reverses goods before any approval');
foreach (['purchase_tomato'=>'طماطم','purchase_lemon'=>'ليمون','purchase_oil'=>'زيت','purchase_vinegar'=>'خل'] as $category=>$name) {
    $expenseId=purchaseExpense(); purchaseReceive($service,$expenseId,purchaseValues($category,'1.001'));
    purchaseCheck(purchaseBalance($name)===1001000, 'purchase category credits its own raw ingredient: '.$name);
}

$service=purchaseFixture(); $rolledBackId=null;
try {
    DB::transaction(function () use ($service,&$rolledBackId) {
        $rolledBackId=purchaseExpense(); $service->receive($rolledBackId,purchaseValues(),(object)['id'=>7]);
        purchaseCheck($service->present(DB::table('branch_expenses')->where('id',$rolledBackId)->first())['stock_quantity_kg']==='2.125','nested transaction can review its own receipt');
        throw new RuntimeException('simulated outer journal rollback');
    });
} catch (RuntimeException $error) { if ($error->getMessage()!=='simulated outer journal rollback') throw $error; }
purchaseCheck(DB::table('branch_expenses')->count()===1 && DB::table('branch_inventory')->count()===0 && DB::table('expense_inventory_receipts')->count()===0 && DB::table('branch_inventory_movements')->count()===0, 'outer desktop journal rollback removes expense, balance, movement and receipt together');
purchaseCheck($service->present((object)['id'=>$rolledBackId])['stock_quantity_kg']===null, 'uncommitted receipt presentation never leaves phantom cached metadata');

$id=purchaseExpense();
DB::statement("CREATE TRIGGER fail_purchase_metadata BEFORE INSERT ON expense_inventory_receipts BEGIN SELECT RAISE(ABORT, 'receipt failure'); END");
try { purchaseReceive($service,$id,purchaseValues()); throw new RuntimeException('Expected metadata failure'); }
catch (\Illuminate\Database\QueryException $error) { purchaseCheck(purchaseBalance()===0 && DB::table('branch_inventory_movements')->count()===0, 'metadata failure rolls back both balance and stock audit'); }
DB::statement('DROP TRIGGER fail_purchase_metadata'); purchaseReceive($service,$id,purchaseValues());
DB::statement("CREATE TRIGGER fail_purchase_reverse BEFORE UPDATE ON expense_inventory_receipts BEGIN SELECT RAISE(ABORT, 'reverse receipt failure'); END");
try { purchaseReverse($service,$id); throw new RuntimeException('Expected reversal receipt failure'); }
catch (\Illuminate\Database\QueryException $error) { purchaseCheck(purchaseBalance()===2125000 && DB::table('branch_inventory_movements')->where('source_type','expense_reverse')->count()===0, 'failed reversal metadata update rolls back the negative stock movement'); }
DB::statement('DROP TRIGGER fail_purchase_reverse'); purchaseReverse($service,$id);
purchaseCheck(purchaseBalance()===0,'rolled-back reversal can be retried safely');

$service=purchaseFixture(); DB::table('stock_ingredients')->where('name','زيت')->update(['unit'=>'piece']);
$id=purchaseExpense(); purchaseDenied(fn()=>purchaseReceive($service,$id,purchaseValues('purchase_oil','1')),503,'wrong material unit blocks the expense receipt without mutating stock');
purchaseCheck(DB::table('branch_inventory_movements')->count()===0, 'wrong-unit rejection leaves the ledger untouched');
try { (new LinkExpenseInventoryPurchases)->up(); throw new RuntimeException('Expected unit preflight rejection'); }
catch (RuntimeException $error) { purchaseCheck(str_contains($error->getMessage(),'non-kilogram'),'migration refuses an incompatible existing material identity'); }

echo 'Expense stock purchases: '.$checks.' checks passed.'.PHP_EOL;
