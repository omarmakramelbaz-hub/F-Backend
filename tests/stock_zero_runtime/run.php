<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
require dirname(__DIR__, 2).'/deployment/stock_zero_all_branches/zero.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

$checks = 0;
$temporary = [];
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('TEST_FAILED: '.$message);
    $checks++;
}
function same($expected, $actual, string $message): void
{
    check($expected === $actual, $message.' expected='.json_encode($expected).' actual='.json_encode($actual));
}
function backupDirectory(): string
{
    global $temporary;
    $path = sys_get_temp_dir().'/stock-zero-test-'.bin2hex(random_bytes(8));
    if (!mkdir($path, 0700)) throw new RuntimeException('Temporary directory failed');
    $temporary[] = $path;
    return $path;
}
function rows($db, string $table): array
{
    return array_map(static fn($row) => (array)$row, $db->table($table)->orderBy('id')->get()->all());
}
function state($db): array
{
    $result = [];
    foreach (['resturants','stock_ingredients','resturant_products','branch_inventory','branch_stock',
        'branch_inventory_movements','branch_stock_movements','branch_operation_commands',
        'branch_stock_recipes','branch_recipe_sales','branch_expenses','expense_inventory_receipts'] as $table) {
        $result[$table] = rows($db, $table);
    }
    return $result;
}
function fixture()
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
    $db = $capsule->getConnection();
    $schema = $db->getSchemaBuilder();
    $schema->create('resturants', static function (Blueprint $t) { $t->integer('id')->primary(); $t->string('name'); });
    $schema->create('stock_ingredients', static function (Blueprint $t) { $t->integer('id')->primary(); $t->string('name'); $t->string('unit'); });
    $schema->create('resturant_products', static function (Blueprint $t) { $t->integer('id')->primary(); $t->integer('resturant_id'); $t->string('product_name'); });
    $schema->create('branch_inventory', static function (Blueprint $t) {
        $t->increments('id'); $t->string('branch'); $t->integer('ingredient_id'); $t->bigInteger('quantity_units');
        $t->integer('revision'); $t->string('created_at'); $t->string('updated_at'); $t->unique(['branch','ingredient_id']);
    });
    $schema->create('branch_stock', static function (Blueprint $t) {
        $t->increments('id'); $t->string('branch'); $t->integer('product_id'); $t->string('unit'); $t->bigInteger('quantity_units');
        $t->integer('revision'); $t->string('created_at'); $t->string('updated_at'); $t->unique(['branch','product_id']);
    });
    foreach (['branch_inventory_movements'=>'ingredient_id','branch_stock_movements'=>'product_id'] as $table=>$itemColumn) {
        $schema->create($table, static function (Blueprint $t) use ($itemColumn) {
            $t->increments('id'); $t->integer('stock_id'); $t->string('branch'); $t->integer($itemColumn);
            $t->string($itemColumn === 'ingredient_id' ? 'name' : 'product_name'); $t->string('unit');
            $t->bigInteger('quantity_units'); $t->bigInteger('balance_units'); $t->string('source_type',16); $t->string('source_id',60);
            $t->integer('actor_id')->nullable(); $t->string('supplier')->nullable(); $t->string('notes')->nullable();
            $t->string('created_at'); $t->string('updated_at'); $t->unique(['stock_id','source_type','source_id']);
        });
    }
    $schema->create('branch_operation_commands', static function (Blueprint $t) {
        $t->increments('id'); $t->string('branch'); $t->integer('actor_id'); $t->string('request_key');
        $t->string('request_hash'); $t->string('kind'); $t->text('result'); $t->string('created_at'); $t->string('updated_at');
        $t->unique(['branch','actor_id','request_key']);
    });
    foreach (['branch_stock_recipes','branch_recipe_sales','branch_expenses','expense_inventory_receipts'] as $table) {
        $schema->create($table, static function (Blueprint $t) { $t->integer('id')->primary(); $t->string('branch'); $t->text('protected_data'); });
        $db->table($table)->insert(['id'=>1,'branch'=>'f:1','protected_data'=>'must remain '.$table]);
    }
    foreach ([1,2,10,99] as $id) $db->table('resturants')->insert(['id'=>$id,'name'=>'Branch '.$id]);
    foreach ([['id'=>1,'name'=>'Fish','unit'=>'kg'],['id'=>2,'name'=>'Water','unit'=>'piece']] as $row) $db->table('stock_ingredients')->insert($row);
    foreach ([['id'=>11,'resturant_id'=>1],['id'=>12,'resturant_id'=>2],['id'=>21,'resturant_id'=>10],['id'=>91,'resturant_id'=>9],['id'=>991,'resturant_id'=>99]] as $row) {
        $db->table('resturant_products')->insert($row+['product_name'=>'SKU '.$row['id']]);
    }
    $time = ['revision'=>4,'created_at'=>'2026-10-01 00:00:00','updated_at'=>'2026-11-05 00:00:00'];
    foreach ([
        ['id'=>1,'branch'=>'f:1','ingredient_id'=>1,'quantity_units'=>2500000],
        ['id'=>2,'branch'=>'f:2','ingredient_id'=>1,'quantity_units'=>-1250000],
        ['id'=>3,'branch'=>'f:10','ingredient_id'=>2,'quantity_units'=>0],
        ['id'=>4,'branch'=>'gs:9','ingredient_id'=>1,'quantity_units'=>7000000],
        ['id'=>5,'branch'=>'f:99','ingredient_id'=>1,'quantity_units'=>9000000],
    ] as $row) $db->table('branch_inventory')->insert($row+$time);
    foreach ([
        ['id'=>1,'branch'=>'f:1','product_id'=>11,'unit'=>'piece','quantity_units'=>3000000],
        ['id'=>2,'branch'=>'f:10','product_id'=>21,'unit'=>'kg','quantity_units'=>-500000],
        ['id'=>3,'branch'=>'f:2','product_id'=>12,'unit'=>'kg','quantity_units'=>0],
        ['id'=>4,'branch'=>'gs:9','product_id'=>91,'unit'=>'piece','quantity_units'=>7000000],
        ['id'=>5,'branch'=>'f:99','product_id'=>991,'unit'=>'kg','quantity_units'=>9000000],
    ] as $row) $db->table('branch_stock')->insert($row+$time);
    foreach (['branch_inventory_movements'=>'ingredient_id','branch_stock_movements'=>'product_id'] as $table=>$itemColumn) {
        $db->table($table)->insert(['stock_id'=>1,'branch'=>'f:1',$itemColumn=>($itemColumn==='ingredient_id'?1:11),
            ($itemColumn==='ingredient_id'?'name':'product_name')=>'Original receipt','unit'=>($itemColumn==='ingredient_id'?'kg':'piece'),
            'quantity_units'=>($itemColumn==='ingredient_id'?2500000:3000000),'balance_units'=>($itemColumn==='ingredient_id'?2500000:3000000),
            'source_type'=>'receipt','source_id'=>'old-receipt','actor_id'=>1,'supplier'=>'original supplier','notes'=>'original note',
            'created_at'=>'2026-11-05 00:00:00','updated_at'=>'2026-11-05 00:00:00']);
    }
    $db->table('branch_operation_commands')->insert(['branch'=>'f:99','actor_id'=>1,'request_key'=>'unrelated-command',
        'request_hash'=>str_repeat('a',64),'kind'=>'inventory.receive','result'=>'{}','created_at'=>'2026-11-05 00:00:00','updated_at'=>'2026-11-05 00:00:00']);
    return $db;
}
function expectAtomicFailure(callable $prepare, array $branches, string $label): void
{
    $db = fixture(); $prepare($db); $before = state($db); $caught = null;
    try { goZeroBranchStock($db, $branches, backupDirectory()); }
    catch (Throwable $error) { $caught = $error; }
    check($caught !== null, 'failure was required: '.$label);
    same($before, state($db), 'failure rolls back all tables: '.$label);
}

try {
    $db = fixture(); $before = state($db); $directory = backupDirectory();
    $result = goZeroBranchStock($db, ['f:10','f:2','f:1'], $directory);
    same('GO_STOCK_ZERO_APPLIED', $result['status'], 'success marker');
    foreach (['branch_inventory','branch_stock'] as $table) {
        foreach ($before[$table] as $old) {
            $fresh=(array)$db->table($table)->where('id',$old['id'])->first();
            $target=in_array($old['branch'],['f:1','f:2','f:10'],true);
            if (!$target || $old['quantity_units'] === 0) { same($old,$fresh,'untouched balance '.$table.'/'.$old['id']); continue; }
            same(0,$fresh['quantity_units'],'selected positive and negative stock zero '.$table.'/'.$old['id']);
            same($old['revision']+1,$fresh['revision'],'changed balance revision '.$table.'/'.$old['id']);
            foreach ($old as $key=>$value) if (!in_array($key,['quantity_units','revision','updated_at'],true)) same($value,$fresh[$key],'balance metadata retained '.$table.'/'.$key);
            $moves=$db->table($table==='branch_inventory'?'branch_inventory_movements':'branch_stock_movements')
                ->where('stock_id',$old['id'])->where('source_type','adjustment')->where('source_id','owner-zero-20261106-v1')->get();
            same(1,$moves->count(),'one reset movement '.$table.'/'.$old['id']); $movement=$moves->first();
            same(-$old['quantity_units'],$movement->quantity_units,'exact compensating delta '.$table.'/'.$old['id']);
            same(0,$movement->balance_units,'reset movement zero final balance '.$table.'/'.$old['id']);
            same(1,$movement->actor_id,'owner reset movement '.$table.'/'.$old['id']);
            check(trim((string)$movement->notes)!=='','reset movement audit note '.$table.'/'.$old['id']);
        }
    }
    foreach (['branch_inventory_movements','branch_stock_movements'] as $table) {
        foreach ($before[$table] as $old) same($old,(array)$db->table($table)->where('id',$old['id'])->first(),'original movement immutable '.$table);
        same(2,$db->table($table)->where('source_type','adjustment')->count(),'only nonzero selected balances adjusted '.$table);
    }
    foreach (['resturants','stock_ingredients','resturant_products','branch_stock_recipes','branch_recipe_sales','branch_expenses','expense_inventory_receipts'] as $table) {
        same($before[$table],rows($db,$table),'unrelated table preserved '.$table);
    }
    same($before['branch_operation_commands'][0],(array)$db->table('branch_operation_commands')->where('id',1)->first(),'unrelated operation marker retained');
    same(3,$db->table('branch_operation_commands')->where('kind','stock.zero')->count(),'one durable operation per selected branch');
    check(is_file($result['backup']),'backup exists'); same(0600,fileperms($result['backup']) & 0777,'backup is private');
    $backup=json_decode(file_get_contents($result['backup']),true,512,JSON_THROW_ON_ERROR);
    same(array_slice($before['branch_inventory'],0,3),$backup['inventory'],'backup contains original inventory rows including zero');
    same(array_slice($before['branch_stock'],0,3),$backup['stock'],'backup contains original direct stock rows including zero');
    same(['f:1','f:2','f:10'],$backup['branches'],'branch lock scope sorted by numeric id');
    same('owner-zero-20261106-v1',$backup['source'],'backup identifies fixed one-time operation');
    same(4,count($backup['planned_adjustments']),'backup records all planned compensating movements');
    $after=state($db); $again=goZeroBranchStock($db,['f:1','f:2','f:10'],$directory);
    same('GO_STOCK_ZERO_ALREADY_APPLIED',$again['status'],'durable replay marker'); same($after,state($db),'repeat leaves every table unchanged');
    // The same command must not erase replenishment entered after the reset.
    $db->table('branch_inventory')->where('id',1)->update(['quantity_units'=>4200000,'revision'=>6]);
    $db->table('branch_stock')->where('id',1)->update(['quantity_units'=>9000000,'revision'=>6]);
    $replenished=state($db); $again=goZeroBranchStock($db,['f:1','f:2','f:10'],$directory);
    same('GO_STOCK_ZERO_ALREADY_APPLIED',$again['status'],'replay after replenishment marker');
    same($replenished,state($db),'later new inventory survives repeated old command');
    expectAtomicFailure(static fn($db)=>null,['f:1','gs:9'],'invalid namespace');
    expectAtomicFailure(static fn($db)=>$db->table('stock_ingredients')->where('id',1)->delete(),['f:1','f:2','f:10'],'missing ingredient metadata');
    expectAtomicFailure(static fn($db)=>$db->unprepared("CREATE TRIGGER deny_direct_reset BEFORE UPDATE ON branch_stock WHEN OLD.id = 1 BEGIN SELECT RAISE(ABORT, 'injected failure'); END"),['f:1','f:2','f:10'],'failure after ingredient writes');
    echo 'PASS '.$checks." runtime assertions\n";
} finally {
    foreach ($temporary as $directory) {
        foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
        rmdir($directory);
    }
}
