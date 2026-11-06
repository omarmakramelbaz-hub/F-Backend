<?php
/** Isolated Laravel 8 + SQLite integration of the real expense/inventory services. */
namespace App\Models {
    require getenv('BREAD_TEST_AUTOLOAD');
    class User extends \Illuminate\Database\Eloquent\Model { protected $table='users'; public $timestamps=false; }
}
namespace App\Services\Dashboard {
    // Scope/notification dependencies only; financial and inventory writers are real.
    class TakeawayAccess {
        public function ready(): bool { return true; }
        public function actor($actor) { return $actor; }
        public function permissions($actor): array { return ['can_checkout'=>true]; }
        public function branches($actor): array { return array_map(fn($id)=>$this->branch('f:'.$id,$actor),[100,101]); }
        public function branch(string $branch,$actor,bool $lock=false): array {
            \abort_unless($actor->id===1||($actor->id===10&&$branch==='f:100'),404);
            $q=\Illuminate\Support\Facades\DB::table('resturants')->where('id',(int)substr($branch,2));
            if($lock)$q->lockForUpdate(); $row=$q->first(); \abort_unless($row,404);
            return ['value'=>$branch,'kind'=>'f','id'=>(int)$row->id,'name'=>$row->name];
        }
    }
    class BranchStockMinimums { public function observe(...$values): void {} }
}
namespace {
    function abort($status,$message='') { throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message); }
    function abort_unless($condition,$status,$message='') { if(!$condition)abort($status,$message); }
    function abort_if($condition,$status,$message='') { if($condition)abort($status,$message); }
    function check($condition,string $message): void { if(!$condition)throw new \RuntimeException($message); }
    function invalid(callable $work): void { try{$work();}catch(\Illuminate\Validation\ValidationException $error){return;}throw new \RuntimeException('Expected invalid quantity'); }
    $root=dirname(__DIR__);
    spl_autoload_register(function($class)use($root){if(str_starts_with($class,'App\\')){$path=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($path))require $path;}});
    $app=new \Illuminate\Container\Container; \Illuminate\Container\Container::setInstance($app);
    $app->instance('config',new \Illuminate\Config\Repository(['app'=>['timezone'=>'Africa/Cairo']]));
    $loader=new \Illuminate\Translation\ArrayLoader;
    $loader->addMessages('ar','expenses',require $root.'/resources/lang/ar/expenses.php');
    $translator=new \Illuminate\Translation\Translator($loader,'ar'); $app->instance('translator',$translator);
    $app->instance('validator',new \Illuminate\Validation\Factory($translator,$app));
    $db=new \Illuminate\Database\Capsule\Manager($app);
    $db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']); $db->setAsGlobal(); $db->bootEloquent();
    $app->instance('db',$db->getDatabaseManager()); $app->bind('db.schema',fn()=>$db->schema());
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-11-06 17:00:00','Africa/Cairo'));
    $schema=$db->schema();
    $schema->create('users',function($t){$t->id();$t->string('name');$t->string('account_type');$t->string('status')->default('accepted');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
    $schema->create('resturants',function($t){$t->id();$t->string('name');});
    foreach([
        ['2026_10_03_140000_create_takeaway_pos.php','CreateTakeawayPos'],
        ['2026_10_04_030000_create_branch_expenses.php','CreateBranchExpenses'],
        ['2026_10_06_120000_create_branch_expenses_categories.php','CreateBranchExpensesCategories'],
        ['2026_10_06_200000_manage_expense_categories.php','ManageExpenseCategories'],
        ['2026_10_18_200000_scope_expense_categories_by_branch.php','ScopeExpenseCategoriesByBranch'],
        ['2026_10_04_210000_create_branch_inventory_recipes.php','CreateBranchInventoryRecipes'],
        ['2026_10_18_090000_link_expense_inventory_purchases.php','LinkExpenseInventoryPurchases'],
    ] as [$file,$class]){require $root.'/database/migrations/'.$file;(new $class)->up();}
    $db->table('users')->insert([['id'=>1,'name'=>'Owner','account_type'=>'admin'],['id'=>10,'name'=>'Branch','account_type'=>'vendor']]);
    $db->table('resturants')->insert([['id'=>100,'name'=>'المحلة'],['id'=>101,'name'=>'المنصورة']]);
    $app->singleton(\App\Services\Dashboard\ExpenseStockPurchases::class);
    $stock=app(\App\Services\Dashboard\ExpenseStockPurchases::class);
    $categories=app(\App\Services\Dashboard\ExpenseCategories::class);
    $service=app(\App\Services\Dashboard\BranchExpenses::class);
    $owner=\App\Models\User::find(1); $branch=\App\Models\User::find(10);
    $ingredient=$db->table('stock_ingredients')->where('name','خبز بلدي')->value('id');
    foreach(['خبز بلدي','الخبز البلدي','عيش بلدي','شراء خبز بلدي','خبز بلدي - فرع المحلة'] as $label)check($stock->categoryProduct($label)==='purchase_baladi_bread','Bread alias failed: '.$label);
    foreach(['فلفل','طماطم','ليمون','بصل','زيت','خل','طحينة'] as $label)check($stock->unit($stock->categoryProduct($label))==='kg','Kilogram material changed');
    check($stock->categoryProduct('توست')===null,'Unrelated bread was linked');
    foreach([null,'','0','-1','1.5','٠٫٥','1000001','1e2','1,000'] as $bad)invalid(fn()=>$stock->normalizeQuantity(['quantity_kg'=>$bad],'purchase_baladi_bread'));
    check($stock->normalizeQuantity(['quantity_kg'=>'١٠٠'],'purchase_baladi_bread')['quantity_kg']==='100','Arabic quantity failed');
    check($stock->normalizeQuantity(['quantity_kg'=>'2.125'],'purchase_tomato')['quantity_kg']==='2.125','Kilogram decimals changed');
    foreach(['f:100','f:101'] as $scope){$choices=$categories->choices(false,$scope);check($choices['active_categories']['purchase_baladi_bread']==='خبز بلدي','Bread missing at '.$scope);check($choices['stock_units']['purchase_baladi_bread']==='piece','Bread unit missing');}
    $uuid=fn($n)=>sprintf('00000000-0000-4000-8000-%012d',$n);
    $values=['branch'=>'f:100','idempotency_key'=>$uuid(1),'occurred_on'=>'2026-11-06','category'=>'purchase_baladi_bread','quantity_kg'=>'١٠٠','description'=>'خبز بلدي','amount'=>'75.00','payment_method'=>'cash'];
    $first=$service->save($values,$branch);$item=$first['expense'];
    $balance=fn($scope='f:100')=>(int)$db->table('branch_inventory')->where('branch',$scope)->where('ingredient_id',$ingredient)->value('quantity_units');
    check($balance()===100000000,'Bread receipt did not increase stock by 100');
    check($balance('f:101')===0,'Another branch stock changed');
    check($item['stock_quantity_kg']==='100'&&$item['stock_unit']==='piece','Displayed stock quantity/unit is wrong');
    check($db->table('takeaway_till_entries')->count()===0,'Pending expense moved cash');
    check($service->save($values,$branch)['replayed']&&$balance()===100000000,'Lost response duplicated bread');
    $review=['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>$uuid(2),'action'=>'approve','reason'=>''];
    $approved=$service->review($item['id'],$review,$owner)['expense'];
    check($balance()===100000000,'Approval duplicated bread');
    check((int)$db->table('takeaway_tills')->value('balance_cents')===-7500,'Cash debit wrong');
    check($service->review($item['id'],$review,$owner)['replayed'],'Approval retry failed');
    $void=['branch'=>'f:100','expected_revision'=>$approved['revision'],'idempotency_key'=>$uuid(3),'action'=>'void','reason'=>'تم الإلغاء'];
    $service->review($item['id'],$void,$owner);$service->review($item['id'],$void,$owner);
    check($balance()===0,'Void did not reverse bread exactly once');
    check((int)$db->table('takeaway_tills')->value('balance_cents')===0,'Void did not refund cash exactly once');
    $pending=$service->save(array_replace($values,['idempotency_key'=>$uuid(4),'quantity_kg'=>'12']),$branch)['expense'];
    $reject=['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>$uuid(5),'action'=>'reject','reason'=>'مرفوض'];
    $service->review($pending['id'],$reject,$owner);$service->review($pending['id'],$reject,$owner);
    check($balance()===0,'Rejection did not reverse bread exactly once');
    $before=$db->table('branch_expenses')->count();
    invalid(fn()=>$service->save(array_replace($values,['idempotency_key'=>$uuid(6),'quantity_kg'=>'2.5']),$branch));
    check($db->table('branch_expenses')->count()===$before,'Invalid count created an expense');
    // A failure after receive must roll the whole expense and its inventory receipt back.
    try{\Illuminate\Support\Facades\DB::transaction(function()use($service,$values,$uuid,$branch){$service->save(array_replace($values,['idempotency_key'=>$uuid(7),'quantity_kg'=>'20']),$branch);throw new \RuntimeException('Simulated enclosing journal failure');});}catch(\RuntimeException $error){check($error->getMessage()==='Simulated enclosing journal failure','Unexpected rollback failure');}
    check($balance()===0&&$db->table('branch_expenses')->count()===$before,'Enclosing transaction left stock or expense behind');
    $service->save(array_replace($values,['idempotency_key'=>$uuid(7),'quantity_kg'=>'20']),$branch);
    check($balance()===20000000,'Retry after rollback failed');
    $tomato=$service->save(array_replace($values,['idempotency_key'=>$uuid(8),'category'=>'purchase_tomato','quantity_kg'=>'2.125','description'=>'طماطم']),$branch)['expense'];
    check($tomato['stock_quantity_kg']==='2.125'&&$tomato['stock_unit']==='kg','Kilogram purchase regressed');
    // A pre-upgrade unlinked bread expense stays unlinked even on a lost-response retry.
    $alias=$db->table('branch_expense_categories')->insertGetId(['name'=>'عيش بلدي','name_hash'=>hash('sha256','عيش بلدي'),'request_key'=>$uuid(90),'created_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
    $historical=array_replace($values,['idempotency_key'=>$uuid(91),'category'=>'custom_'.$alias]);unset($historical['quantity_kg']);
    $normalized=$historical+['payment_reference'=>'','supplier'=>'','cost_center'=>'','notes'=>''];
    $historicId=$db->table('branch_expenses')->insertGetId(['branch'=>'f:100','actor_id'=>10,'occurred_on'=>'2026-11-06','category'=>'custom_'.$alias,'description'=>'خبز بلدي','amount_cents'=>7500,'payment_method'=>'cash','status'=>'pending','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
    $db->table('branch_expense_commands')->insert(['branch'=>'f:100','actor_id'=>10,'request_key'=>$uuid(91),'request_hash'=>\App\Services\Dashboard\PosServiceTicket::fingerprint(['save',$normalized,[]]),'expense_id'=>$historicId,'kind'=>'create','revision'=>1,'snapshot'=>json_encode(['stock_category'=>null]),'created_at'=>now(),'updated_at'=>now()]);
    check($service->save($historical,$branch)['replayed']&&$balance()===20000000,'Historical bread was changed or replay was blocked');
    check(!$db->table('expense_inventory_receipts')->where('expense_id',$historicId)->exists(),'Historical bread received stock retroactively');
    // The catalog setup collapses old local aliases and re-enables a hidden protected choice.
    // Reproduce the legacy production environment: this class must never be loaded.
    check(!class_exists('App\\Services\\OwnerDashboards\\Runtime',false),'Owner runtime unexpectedly preloaded');
    $missingOwnerRuntime=static function($class){if($class==='App\\Services\\OwnerDashboards\\Runtime')throw new \RuntimeException('Legacy application has no OwnerDashboards Runtime');};
    spl_autoload_register($missingOwnerRuntime,true,true);
    require $root.'/deployment/baladi-bread-purchases-20261106/catalog.php';
    $local=$db->table('branch_expense_categories')->insertGetId(['name'=>'الخبز البلدي','origin_branch'=>'f:101','name_hash'=>hash('sha256','f:101|bread'),'request_key'=>$uuid(92),'created_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
    $db->table('branch_expense_category_overrides')->insert(['branch'=>'f:100','category_key'=>'purchase_baladi_bread','active'=>false,'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
    $setup=new \BaladiBreadCatalog20261106;
    $catalogDenied=static function()use($setup){try{$setup->run('check');}catch(\RuntimeException $error){check($error->getMessage()==='PRIMARY_BUSINESS_OWNER_REQUIRED','Unexpected catalog guard error: '.$error->getMessage());return;}throw new \RuntimeException('Expected primary application guard');};
    $app->instance('owner.dashboard.runtime',['id'=>'another-owner']);$catalogDenied();$app->offsetUnset('owner.dashboard.runtime');
    config(['desktop_dashboard.local'=>true]);$catalogDenied();config(['desktop_dashboard.local'=>false]);
    $db->table('users')->where('id',1)->update(['status'=>'blocked']);$catalogDenied();$db->table('users')->where('id',1)->update(['status'=>'accepted']);
    $db->table('users')->where('id',1)->update(['owner_resturant_id'=>100]);$catalogDenied();$db->table('users')->where('id',1)->update(['owner_resturant_id'=>null]);
    check($setup->run('check')['pending_category_updates']===2,'Catalog plan missed local alias or hidden choice');
    $backup=sys_get_temp_dir().'/bread-test-'.bin2hex(random_bytes(5));mkdir($backup,0700);
    try{$setup->run('apply',$backup);check($setup->run('verify')['branch_count']===2,'All-branch catalog did not verify');check($setup->run('check')['pending_category_updates']===0,'Catalog setup is not idempotent');}finally{unlink($backup.'/bread-catalog-before.json');rmdir($backup);}
    check($balance()===20000000,'Catalog setup altered stock');
    spl_autoload_unregister($missingOwnerRuntime);
    check(!class_exists('App\\Services\\OwnerDashboards\\Runtime',false),'Catalog setup introduced the absent owner runtime');
    echo "BALADI_BREAD_EXPENSE_STOCK_INTEGRATION_OK\n";
}
