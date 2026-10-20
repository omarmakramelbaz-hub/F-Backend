<?php
/** Real Illuminate/SQLite migrations and ExpenseCategories service; no deployed app or network. */
namespace {
    require getenv('TAHINI_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/vendor/autoload.php';
}
namespace App\Models {
    /** Minimal fixture model; persisted owner authorization still uses real TakeawayAccess. */
    class User extends \Illuminate\Database\Eloquent\Model {protected $guarded=[];public $timestamps=false;}
}
namespace TahiniCatalogTest {
    final class Application extends \Illuminate\Container\Container {
        public function abort($code,$message='',$headers=[]):void {throw new \RuntimeException('HTTP '.$code.': '.$message);}
    }
    final class Connection extends \Illuminate\Database\SQLiteConnection {
        public static string $commitFailure='';
        public function commit() {
            $outer=$this->transactionLevel()===1;
            if($outer&&self::$commitFailure==='before')throw new \RuntimeException('Injected commit uncertainty');
            parent::commit();
            if($outer&&self::$commitFailure==='after')throw new \RuntimeException('Injected commit uncertainty');
        }
    }
    final class State {public static array $writes=[];public static ?string $root=null;}
}
namespace {
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\{DB,Schema,Facade};
    use TahiniCatalogTest\{Application,Connection,State};
    use App\Services\Dashboard\ExpenseCategories;
    require dirname(__DIR__,2).'/app/Services/Dashboard/TakeawayAccess.php';
    require dirname(__DIR__,2).'/app/Services/Dashboard/BranchExpenses.php';
    require dirname(__DIR__,2).'/app/Services/Dashboard/ExpenseCategories.php';
    require dirname(__DIR__,2).'/deployment/add_tahini_catalog_20261021.php';
    require dirname(__DIR__,2).'/database/migrations/2026_10_06_120000_create_branch_expenses_categories.php';
    require dirname(__DIR__,2).'/database/migrations/2026_10_06_200000_manage_expense_categories.php';
    require dirname(__DIR__,2).'/database/migrations/2026_10_04_210000_create_branch_inventory_recipes.php';
    $checks=0;
    function check(bool $condition,string $message):void {global $checks;$checks++;if(!$condition)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message.PHP_EOL;}
    function reject(callable $action,string $contains):void {try{$action();}catch(Throwable $e){check(stripos($e->getMessage(),$contains)!==false,'rejects '.$contains);return;}throw new RuntimeException('Expected rejection '.$contains);}
    function cleanup(string $root):void {if(!is_dir($root))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)($f->isDir()&&!$f->isLink())?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
    function files(string $root):array {$out=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile())$out[substr($f->getPathname(),strlen($root))]=hash_file('sha256',$f->getPathname());ksort($out);return $out;}
    function rows(string $table,string $order='id'):array {return DB::table($table)->orderBy($order)->get()->map(fn($r)=>(array)$r)->all();}
    function database():array {$out=[];foreach(['users','resturants','stock_ingredients','branch_expense_categories','branch_expense_category_commands','branch_inventory','branch_inventory_movements','branch_stock_recipes','branch_recipe_sales','branch_expenses','takeaway_tills','go_store_products'] as $table)$out[$table]=rows($table);$out['branch_expense_category_settings']=rows('branch_expense_category_settings','category_key');return $out;}
    function resetWrites():void {State::$writes=[];}
    function fixture(string $unit='kg'):FasakhanstaTahiniCatalog20261021 {
        if(State::$root)cleanup(State::$root);State::$root=sys_get_temp_dir().'/tahini-catalog-test-'.bin2hex(random_bytes(5));mkdir(State::$root.'/storage/app',0700,true);mkdir(State::$root.'/backup',0700);
        $container=new Application;Application::setInstance($container);$db=new Capsule($container);
        $db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
        $db->getDatabaseManager()->extend('sqlite',fn($config,$name)=>new Connection(new PDO('sqlite::memory:'),$config['database'],$config['prefix'],$config));
        $db->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));$db->setAsGlobal();$db->bootEloquent();
        $container->instance('db',$db->getDatabaseManager());$container->instance('db.schema',$db->getConnection()->getSchemaBuilder());
        $loader=new \Illuminate\Translation\ArrayLoader;$translator=new \Illuminate\Translation\Translator($loader,'ar');
        foreach(['ar','en'] as $locale){$path=dirname(__DIR__,2).'/resources/lang/'.$locale.'/expenses.php';if(is_file($path))$loader->addMessages($locale,'expenses',require $path);}
        $container->instance('translator',$translator);$container->instance('validator',new \Illuminate\Validation\Factory($translator,$container));
        Facade::clearResolvedInstances();Facade::setFacadeApplication($container);Connection::$commitFailure='';
        Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
        DB::table('users')->insert(['id'=>1,'name'=>'owner','account_type'=>'admin','owner_resturant_id'=>null]);
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->string('name');$t->unsignedBigInteger('user_id');});
        foreach([94,301,307,308,309,310,311,312,313,336,337,338,339,340,341,342,343,344,345,346,347,348,349,350,354,355,356,357,358,359,360,363] as $id)DB::table('resturants')->insert(['id'=>$id,'name'=>'branch '.$id,'user_id'=>1]);
        (new CreateBranchExpensesCategories)->up();(new ManageExpenseCategories)->up();(new CreateBranchInventoryRecipes)->up();
        foreach(['branch_expenses','takeaway_tills','go_store_products'] as $table){Schema::create($table,function(Blueprint $t){$t->id();$t->string('sentinel');});DB::table($table)->insert(['sentinel'=>'unchanged '.$table]);}
        DB::table('branch_inventory')->insert(['branch'=>'f:94','ingredient_id'=>1,'quantity_units'=>123000000,'revision'=>7,'created_at'=>'2026-10-20 00:00:00','updated_at'=>'2026-10-20 00:00:00']);
        DB::table('branch_inventory_movements')->insert(['stock_id'=>1,'branch'=>'f:94','ingredient_id'=>1,'name'=>'original ingredient','unit'=>'kg','quantity_units'=>123000000,'balance_units'=>123000000,'source_type'=>'receipt','source_id'=>'old','created_at'=>'2026-10-20 00:00:00','updated_at'=>'2026-10-20 00:00:00']);
        DB::table('branch_stock_recipes')->insert(['branch'=>'f:94','product_id'=>900,'unit'=>'piece','revision'=>2,'variants'=>'{"0":[{"ingredient_id":1}]}','updated_by'=>1,'created_at'=>'2026-10-20 00:00:00','updated_at'=>'2026-10-20 00:00:00']);
        DB::table('branch_recipe_sales')->insert(['branch'=>'f:94','source_type'=>'pos','source_id'=>'old','snapshot'=>'unchanged sale','created_at'=>'2026-10-20 00:00:00','updated_at'=>'2026-10-20 00:00:00']);
        DB::listen(function($event){if(preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i',$event->sql))State::$writes[]=$event->sql;});resetWrites();
        return new FasakhanstaTahiniCatalog20261021(State::$root,$unit);
    }
    function addIngredient(string $name,string $unit='kg'):int {return DB::table('stock_ingredients')->insertGetId(['name'=>$name,'unit'=>$unit,'position'=>30,'created_at'=>'2026-10-19 00:00:00','updated_at'=>'2026-10-19 00:00:00']);}
    function addCategory(string $name):int {return DB::table('branch_expense_categories')->insertGetId(['name'=>$name,'name_hash'=>hash('sha256',$name),'created_by'=>1,'request_key'=>'existing-'.bin2hex(random_bytes(4)),'created_at'=>'2026-10-19 00:00:00','updated_at'=>'2026-10-19 00:00:00']);}
    try {
        $runner=fixture();$before=database();$beforeFiles=files(State::$root);$preview=$runner->run(false,null);check($preview['status']==='TAHINI_CATALOG_CHECK_READY'&&$preview['branch_count']===32&&$preview['shared_catalog'],'default check describes shared choices for all 32 branches');check(State::$writes===[]&&database()===$before&&files(State::$root)===$beforeFiles,'check writes neither database nor private filesystem');
        $result=$runner->run(true,State::$root.'/backup');$ingredient=DB::table('stock_ingredients')->where('name','طحينة')->first();$categories=app(ExpenseCategories::class)->catalog();$matches=array_filter($categories,fn($c)=>$c['name']==='طحينة');check($result['verified']&&$ingredient&&$ingredient->unit==='kg'&&count($matches)===1&&reset($matches)['active'],'apply creates exactly one active global category and one explicit-unit ingredient');check(DB::table('branch_expense_category_commands')->where('request_key',FasakhanstaTahiniCatalog20261021::CATEGORY_REQUEST)->count()===1,'actual category service records its fixed idempotency command');
        $branches=app(App\Services\Dashboard\TakeawayAccess::class)->branches(App\Models\User::find(1));$allShare=count($branches)===32;foreach($branches as $branch)$allShare=$allShare&&in_array('طحينة',app(ExpenseCategories::class)->options(false),true)&&DB::table('stock_ingredients')->where('id',$ingredient->id)->where('unit','kg')->exists();check($allShare,'all 32 existing branch views share the same category and ingredient identities');
        $after=database();$unaffected=true;foreach(['branch_inventory','branch_inventory_movements','branch_stock_recipes','branch_recipe_sales','branch_expenses','takeaway_tills','go_store_products'] as $table)$unaffected=$unaffected&&$after[$table]===$before[$table];check($unaffected,'no balances, ingredient movements, recipes, sales, expenses, till entries or GO rows are created or changed');
        $first=State::$root.'/storage/app/tahini-catalog-20261021/before.json';$firstSha=hash_file('sha256',$first);resetWrites();$repeat=$runner->run(true,State::$root.'/backup');check(State::$writes===[]&&database()===$after&&!$repeat['ingredient_created']&&!$repeat['expense_category_changed'],'repeat performs no SQL writes, duplicate rows or timestamp updates');check(hash_file('sha256',$first)===$firstSha,'first before.json remains byte-for-byte immutable');
        $runner=fixture('piece');$id=addIngredient('  طَحينه  ','piece');$cat=addCategory('طحينه');DB::table('branch_inventory')->insert(['branch'=>'f:301','ingredient_id'=>$id,'quantity_units'=>17000000,'revision'=>4,'created_at'=>'2026-10-19 00:00:00','updated_at'=>'2026-10-19 00:00:00']);$before=database();resetWrites();$reused=$runner->run(true,State::$root.'/backup');check(State::$writes===[]&&database()===$before&&$reused['ingredient']['id']===$id&&$reused['expense_category']['key']==='custom_'.$cat,'normalized Arabic aliases are reused without converting units, balances or display names');
        $runner=fixture();addIngredient('طحينة','piece');$before=database();resetWrites();reject(fn()=>$runner->run(true,State::$root.'/backup'),'different unit');check(State::$writes===[]&&database()===$before,'conflicting ingredient unit is rejected before SQL writes');
        $runner=fixture();addIngredient('طحينة');addIngredient('طحينه');$before=database();resetWrites();reject(fn()=>$runner->run(true,State::$root.'/backup'),'Multiple matching tahini ingredients');check(State::$writes===[]&&database()===$before,'ambiguous ingredients are not merged or rewritten');
        $runner=fixture();addCategory('طحينة');addCategory('طحينه');$before=database();resetWrites();reject(fn()=>$runner->run(true,State::$root.'/backup'),'Multiple matching tahini expense categories');check(State::$writes===[]&&database()===$before,'ambiguous expense categories are not merged');
        $runner=fixture();DB::table('resturants')->where('id',94)->delete();$before=database();resetWrites();reject(fn()=>$runner->run(true,State::$root.'/backup'),'32-branch scope changed');check(State::$writes===[]&&database()===$before,'changed branch scope is rejected before SQL writes');
        $runner=fixture();Schema::drop('branch_expense_category_commands');resetWrites();reject(fn()=>$runner->run(true,State::$root.'/backup'),'runtime schema is missing');check(State::$writes===[],'missing required schema is rejected before SQL writes');
        $runner=fixture();DB::table('users')->where('id',1)->update(['owner_resturant_id'=>94]);$before=database();resetWrites();reject(fn()=>$runner->run(true,State::$root.'/backup'),'primary owner');check(State::$writes===[]&&database()===$before,'persisted owner must have global category authority');
        $runner=fixture();DB::statement("CREATE TRIGGER reject_category_command BEFORE INSERT ON branch_expense_category_commands BEGIN SELECT RAISE(ABORT, 'Injected category failure'); END");$before=database();resetWrites();reject(fn()=>$runner->run(true,State::$root.'/backup'),'Injected category failure');check(database()===$before&&DB::transactionLevel()===0,'real category-service failure rolls back both catalogs atomically');
        $runner=fixture();DB::statement("CREATE TRIGGER reject_ingredient BEFORE INSERT ON stock_ingredients BEGIN SELECT RAISE(ABORT, 'Injected ingredient failure'); END");$before=database();resetWrites();reject(fn()=>$runner->run(true,State::$root.'/backup'),'Injected ingredient failure');check(database()===$before&&DB::transactionLevel()===0,'ingredient failure rolls back the category and service command in real SQLite');
        $runner=fixture();Connection::$commitFailure='after';reject(fn()=>$runner->run(true,State::$root.'/backup'),'commit uncertainty');$committed=database();Connection::$commitFailure='';resetWrites();$retry=$runner->run(true,State::$root.'/backup');check(State::$writes===[]&&database()===$committed&&$retry['verified'],'lost commit acknowledgement recovers committed global rows without duplicates');
        $runner=fixture();$before=database();Connection::$commitFailure='before';reject(fn()=>$runner->run(true,State::$root.'/backup'),'commit uncertainty');check(database()===$before,'uncommitted outer transaction rolls back both categories and ingredients');$first=State::$root.'/storage/app/tahini-catalog-20261021/before.json';$firstSha=hash_file('sha256',$first);Connection::$commitFailure='';$runner->run(true,State::$root.'/backup');check(hash_file('sha256',$first)===$firstSha,'retry after rollback retains the first immutable backup');
        $runner=fixture();reject(fn()=>new FasakhanstaTahiniCatalog20261021(State::$root,''),'explicit ingredient unit');check(State::$writes===[],'unit is never guessed when absent');
        echo 'TAHINI CATALOG SQLITE CHECKS PASSED: '.$checks.PHP_EOL;
    } finally {if(State::$root)cleanup(State::$root);}
}
