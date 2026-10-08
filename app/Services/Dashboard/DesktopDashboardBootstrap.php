<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;

/** A consistent, explicitly scoped initial dataset. Unknown modules stay unprepared. */
class DesktopDashboardBootstrap
{
    public const GLOBAL_TABLES=['categories','stock_ingredients','permissions','roles','role_has_permissions','branch_expense_category_settings'];
    public const BRANCH_TABLES=[
        'takeaway_tills','takeaway_orders','takeaway_till_entries','takeaway_commands',
        'pos_service_settings','pos_service_tables','pos_service_tickets','pos_service_commands','pos_service_kitchen_tickets',
        'branch_stock','branch_stock_movements','branch_inventory','branch_inventory_movements','branch_stock_recipes','branch_recipe_sales',
        'branch_expenses','branch_expense_commands',
        'branch_customers','branch_delivery_companies','branch_employees','branch_operation_commands',
        'branch_employee_salaries','branch_employee_days','branch_employee_entries','branch_payrolls',
        'branch_shift_closings','phone_delivery_dispatches','phone_delivery_batches',
    ];
    private const CHILD_TABLES=[
        'takeaway_order_items'=>['order_id','takeaway_orders','id'],
        'branch_shift_sources'=>['closing_id','branch_shift_closings','id'],
        'phone_delivery_batch_items'=>['batch_id','phone_delivery_batches','id'],
    ];
    private const PRIVATE_COLUMN='/password|remember_token|(?:^|_)(?:token|secret|api_key|private_key|credential|fcm_id|verification_code|activation_code)(?:$|_)/i';
    public function __construct(private DesktopDashboardDevices $devices) {}

    public function export(object $device): array
    {
        $this->devices->ready();
        abort_unless(DB::transactionLevel()===0,409,'تجهيز البيانات يحتاج معاملة مستقلة.');
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return DB::transaction(function()use($device){
            $fresh=DB::table('desktop_dashboard_devices')->where('id',$device->id)->lockForUpdate()->first();abort_unless($fresh&&$fresh->enabled,401);
            $actor=$this->devices->actor($fresh);$branches=[];
            foreach(json_decode($fresh->branches,true,512,JSON_THROW_ON_ERROR) as $branch){
                $this->devices->branch($fresh,$branch,$actor);$branches[]=$branch;
            }
            abort_unless(count($branches),403);
            $restaurantIds=[];$storeIds=[];
            foreach($branches as $branch){[$kind,$id]=explode(':',$branch);if($kind==='f')$restaurantIds[]=(int)$id;else $storeIds[]=(int)$id;}
            $queries=[];
            foreach(self::GLOBAL_TABLES as $table)if(Schema::hasTable($table))$queries[$table]=DB::table($table);
            foreach(self::BRANCH_TABLES as $table)if(Schema::hasTable($table)){
                // A similarly named table without a branch key must never become a full-table export.
                abort_unless(Schema::hasColumn($table,'branch'),409,'مخطط بيانات الفرع يحتاج مراجعة قبل تجهيز الجهاز.');
                $queries[$table]=DB::table($table)->whereIn('branch',$branches);
            }
            foreach(self::CHILD_TABLES as $table=>[$column,$parent,$key])if(Schema::hasTable($table)&&isset($queries[$parent])){
                $queries[$table]=DB::table($table)->whereIn($column,(clone $queries[$parent])->select($key));
            }
            if(Schema::hasTable('resturants'))$queries['resturants']=DB::table('resturants')->whereIn('id',$restaurantIds);
            if(Schema::hasTable('resturant_products'))$queries['resturant_products']=DB::table('resturant_products')->whereIn('resturant_id',$restaurantIds);
            if(Schema::hasTable('go_stores'))$queries['go_stores']=DB::table('go_stores')->whereIn('user_id',$storeIds);
            if(Schema::hasTable('go_store_products'))$queries['go_store_products']=DB::table('go_store_products')->whereIn('user_id',$storeIds);
            $users=[(int)$actor->id,...$storeIds];
            if(isset($queries['resturants']))$users=array_merge($users,(clone $queries['resturants'])->pluck('user_id')->map(fn($id)=>(int)$id)->all());
            $queries['users']=DB::table('users')->whereIn('id',array_unique($users));
            foreach(['model_has_roles','model_has_permissions'] as $table)if(Schema::hasTable($table)){
                $queries[$table]=DB::table($table)->where('model_type',\App\Models\User::class)->where('model_id',$actor->id);
            }
            if(Schema::hasTable('branch_expense_category_commands'))$queries['branch_expense_category_commands']=DB::table('branch_expense_category_commands')->where('actor_id',$actor->id);
            $tables=[];$schema=[];$rows=0;$bytes=0;
            foreach($queries as $table=>$query){
                abort_unless(preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$table),409);
                $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$table]);
                abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,409,'تجهيز الجهاز يتطلب جداول تدعم المعاملات.');
                // SHOW CREATE reads structure only; it never reads triggers, environment files or a server dump.
                $structure=(array)DB::selectOne('SHOW CREATE TABLE `'.$table.'`');$ddl=(string)array_values($structure)[1];
                // Column defaults can contain credentials in legacy installations. Do not export such DDL.
                $columns=[];
                foreach(DB::select('SELECT COLUMN_NAME AS name,COLUMN_DEFAULT AS value,IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$table]) as $column){
                    $columns[$column->name]=$column;
                    abort_if(preg_match(self::PRIVATE_COLUMN,$column->name)&&$column->value!==null&& !in_array($column->value,["''",'NULL','null',''],true),409,'مخطط بيانات الاعتماد يحتاج تنقية قبل تجهيز الجهاز.');
                }
                $data=[];
                foreach($query->get() as $record){
                    $row=(array)$record;
                    foreach($row as $column=>$value)if(preg_match(self::PRIVATE_COLUMN,$column)){
                        // Cache only this account's password hash for the original offline login form.
                        if($table==='users'&&$column==='password'&&(int)$row['id']===(int)$actor->id)continue;
                        $row[$column]=$columns[$column]->nullable==='YES'?null:'';
                        if($table==='users'&&$column==='password')$row[$column]='!desktop-disabled-'.Str::random(64);
                    }
                    $data[]=$row;$rows++;abort_if($rows>500000,413,'البيانات تحتاج تجهيزًا على دفعات.');
                }
                $encoded=self::json($data);$bytes+=strlen($encoded);abort_if($bytes>256*1024*1024,413,'البيانات تحتاج تجهيزًا على دفعات.');
                $schema[$table]=hash('sha256',$ddl);$tables[$table]=['ddl'=>$ddl,'rows'=>$data,'sha256'=>hash('sha256',$encoded)];
            }
            ksort($schema);
            return ['format'=>1,'kind'=>'initial-dashboard-data','snapshot_id'=>(string)Str::uuid(),'device_id'=>$fresh->id,
                'actor_id'=>(int)$actor->id,'branches'=>$branches,'generated_at'=>now('UTC')->toIso8601String(),
                'schema_hash'=>hash('sha256',self::json($schema)),'tables'=>$tables,
                // A native client must not mark the entire dashboard prepared while these modules are uncovered.
                'coverage'=>['write_routes'=>DesktopDashboardRoutes::WRITES,'full_dashboard'=>false,'media'=>false]];
        });
    }
    public static function json($value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
}
