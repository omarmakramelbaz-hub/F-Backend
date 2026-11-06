<?php
use App\Models\User;
use App\Services\Dashboard\{ExpenseCategories,ExpenseStockPurchases,TakeawayAccess};
use Illuminate\Support\Facades\{DB,Schema};

/** Enable one protected bread purchase per primary-business branch, using its existing ingredient. */
final class BaladiBreadCatalog20261106
{
    public const KEY='purchase_baladi_bread';
    public const NAME='خبز بلدي';

    private function plan(bool $lock=false): array
    {
        // The legacy primary application has no OwnerDashboards runtime class.
        // Its tenant binding and local-mode flag still identify excluded runtimes.
        if(app()->bound('owner.dashboard.runtime')||config('desktop_dashboard.local'))throw new RuntimeException('PRIMARY_BUSINESS_OWNER_REQUIRED');
        $actor=User::withoutGlobalScopes()->find(1);
        if(!$actor||(int)$actor->id!==1||$actor->account_type!=='admin'||$actor->status!=='accepted'
            ||!empty($actor->owner_resturant_id)||!app(ExpenseCategories::class)->canCreate($actor))throw new RuntimeException('PRIMARY_BUSINESS_OWNER_REQUIRED');
        foreach(['resturants','stock_ingredients','branch_expense_categories','branch_expense_category_settings','branch_expense_category_overrides','expense_inventory_receipts','branch_inventory','branch_inventory_movements','branch_recipe_sales'] as $table)
            if(!Schema::hasTable($table))throw new RuntimeException('BREAD_SCHEMA_REQUIRED: '.$table);
        $stock=app(ExpenseStockPurchases::class);
        if(!$stock->ready()||(ExpenseStockPurchases::CATEGORIES[self::KEY]??null)!==self::NAME||$stock->unit(self::KEY)!=='piece')throw new RuntimeException('BREAD_PURCHASE_RUNTIME_REQUIRED');
        $rows=DB::table('stock_ingredients')->where('name',self::NAME)->get();
        if($rows->count()!==1||$rows->first()->unit!=='piece')throw new RuntimeException('EXISTING_BALADI_BREAD_INGREDIENT_MUST_BE_UNIQUE_AND_IN_PIECES');
        $ingredient=$rows->first();
        // Do not introduce a second stock identity for an alternate spelling.
        foreach(DB::table('stock_ingredients')->get(['id','name']) as $row)
            if((int)$row->id!==(int)$ingredient->id&&$stock->categoryProduct($row->name)===self::KEY)throw new RuntimeException('AMBIGUOUS_BREAD_STOCK_IDENTITY');
        $access=app(TakeawayAccess::class);$service=app(ExpenseCategories::class);
        $branches=array_values(array_filter($access->branches($actor),fn($b)=>$b['kind']==='f'));
        if(!$branches)throw new RuntimeException('NO_RESTAURANT_BRANCHES');
        $updates=[];
        foreach($branches as $branch){
            if($lock)$access->branch($branch['value'],$actor,true);
            $catalog=$service->catalog($branch['value']);
            if(!isset($catalog[self::KEY])||empty($catalog[self::KEY]['protected']))throw new RuntimeException('PROTECTED_BREAD_CATEGORY_MISSING: '.$branch['value']);
            foreach($catalog as $key=>$item){
                if($key!==self::KEY&&($item['stock_category']??null)!==self::KEY)continue;
                $active=$key===self::KEY;$name=$active?self::NAME:$item['name'];
                if(!$active&&$item['active']&&$stock->categoryProduct($name)!==self::KEY)throw new RuntimeException('CONFLICTING_BREAD_CATEGORY_NAME: '.$branch['value'].' '.$key);
                if((bool)$item['active']===$active&&$item['name']===$name)continue;
                $q=DB::table('branch_expense_category_overrides')->where('branch',$branch['value'])->where('category_key',$key);
                if($lock)$q->lockForUpdate();$before=$q->first();
                $updates[]=['branch'=>$branch['value'],'category_key'=>$key,'name'=>$name,'active'=>$active,
                    'revision'=>(int)$item['revision']+1,'before'=>$before?(array)$before:null];
            }
        }
        return ['branches'=>array_column($branches,'value'),'ingredient_id'=>(int)$ingredient->id,'updates'=>$updates];
    }

    public function run(string $mode,?string $backup=null): array
    {
        if(!in_array($mode,['check','apply','verify'],true))throw new RuntimeException('INVALID_CATALOG_MODE');
        $plan=$this->plan();
        if($mode==='apply'){
            if(!$backup||!is_dir($backup)||is_link($backup)||realpath($backup)!==$backup||(fileperms($backup)&0077))throw new RuntimeException('PRIVATE_CATALOG_BACKUP_REQUIRED');
            DB::transaction(function()use($backup){
                DB::table('users')->where('id',1)->lockForUpdate()->first();
                $plan=$this->plan(true);
                $record=$backup.'/bread-catalog-before.json';
                if(file_exists($record)||is_link($record))throw new RuntimeException('CATALOG_BACKUP_ALREADY_EXISTS');
                $bytes=json_encode($plan+['recorded_at'=>gmdate('c')],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
                $handle=fopen($record,'xb');if(!$handle)throw new RuntimeException('CATALOG_BACKUP_FAILED');
                try{if(!chmod($record,0600)||fwrite($handle,$bytes)!==strlen($bytes)||!fflush($handle))throw new RuntimeException('CATALOG_BACKUP_FAILED');}finally{fclose($handle);}
                foreach($plan['updates'] as $update){
                    $values=['name'=>$update['name'],'active'=>$update['active'],'revision'=>$update['revision'],'updated_at'=>now('UTC')];
                    $q=DB::table('branch_expense_category_overrides')->where('branch',$update['branch'])->where('category_key',$update['category_key']);
                    if($update['before']!==null)$q->update($values);
                    else DB::table('branch_expense_category_overrides')->insert(['branch'=>$update['branch'],'category_key'=>$update['category_key'],'created_at'=>now('UTC')]+$values);
                }
                if($this->plan(true)['updates'])throw new RuntimeException('BREAD_CATALOG_DID_NOT_VERIFY');
            },1);
            $plan=$this->plan();
        }
        if($mode==='verify'&&$plan['updates'])throw new RuntimeException('BREAD_CATALOG_PENDING_UPDATES');
        if($mode!=='check')foreach($plan['branches'] as $branch){
            $choices=app(ExpenseCategories::class)->choices(false,$branch);
            $matches=array_filter($choices['active_categories'],fn($name,$key)=>($choices['stock_categories'][$key]??null)===self::KEY,ARRAY_FILTER_USE_BOTH);
            if(array_keys($matches)!==[self::KEY]||($choices['stock_units'][self::KEY]??null)!=='piece')throw new RuntimeException('BREAD_BRANCH_VERIFY_FAILED: '.$branch);
        }
        return ['status'=>$mode==='check'?'BALADI_BREAD_CHECK_OK':'BALADI_BREAD_ALL_BRANCHES_VERIFIED',
            'branch_count'=>count($plan['branches']),'branches'=>$plan['branches'],'ingredient_id'=>$plan['ingredient_id'],
            'ingredient_name'=>self::NAME,'stock_unit'=>'piece','pending_category_updates'=>count($plan['updates']),
            'stock_balances_changed'=>false,'financial_entries_created'=>0,'historical_expenses_changed'=>0];
    }
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    try{
        umask(0077);$options=getopt('',['root:','stage:','mode:','backup:']);
        $root=$options['root']??'';
        if(realpath($root)!==$root||!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('INVALID_LARAVEL_ROOT');
        require $root.'/vendor/autoload.php';
        if(isset($options['stage']))foreach(['BranchInventory','ExpenseStockPurchases','ExpenseCategories','BranchExpenses'] as $class){
            if(class_exists('App\\Services\\Dashboard\\'.$class,false))throw new RuntimeException('CANDIDATE_ALREADY_LOADED');
            require $options['stage'].'/app/Services/Dashboard/'.$class.'.php';
        }
        $application=require $root.'/bootstrap/app.php';$application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode((new BaladiBreadCatalog20261106)->run($options['mode']??'check',$options['backup']??null),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    }catch(Throwable $error){fwrite(STDERR,'BALADI_BREAD_CATALOG_STOPPED: '.$error->getMessage()."\n");exit(1);}
}
