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
        'branch_attendance_rules','branch_employee_salaries','branch_employee_days','branch_employee_entries','branch_payrolls',
        'branch_shift_closings','phone_delivery_dispatches','phone_delivery_batches',
    ];
    private const CHILD_TABLES=[
        'takeaway_order_items'=>['order_id','takeaway_orders','id'],
        'branch_shift_sources'=>['closing_id','branch_shift_closings','id'],
        'phone_delivery_batch_items'=>['batch_id','phone_delivery_batches','id'],
    ];
    private const PRIVATE_COLUMN='/password|remember_token|(?:^|_)(?:token|secret|api_key|private_key|credential|partner_auth_email|fcm_id|verification_code|activation_code|mobile_code|email_code|otp_first_no)(?:$|_)/i';
    public function __construct(private DesktopDashboardDevices $devices,private DesktopDashboardData $data) {}

    public function export(object $device): array
    {
        $this->devices->ready();
        abort_unless(DB::transactionLevel()===0,409,'تجهيز البيانات يحتاج معاملة مستقلة.');
        $source=app(DesktopDashboardSource::class)->fingerprint();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return DB::transaction(function()use($device,$source){
            $fresh=DB::table('desktop_dashboard_devices')->where('id',$device->id)->lockForUpdate()->first();abort_unless($fresh&&$fresh->enabled,401);
            $actor=$this->devices->actor($fresh);$branches=[];
            foreach(json_decode($fresh->branches,true,512,JSON_THROW_ON_ERROR) as $branch){
                $this->devices->branch($fresh,$branch,$actor);$branches[]=$branch;
            }
            abort_unless(count($branches),403);
            $queries=$this->data->queries($fresh,$actor,$branches);
            $dataset=$this->data->rows($queries);
            $tables=[];$schema=[];$rows=0;$bytes=0;
            foreach($queries as $table=>$query){
                abort_unless(preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$table),409);
                $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$table]);
                abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,409,'تجهيز الجهاز يتطلب جداول تدعم المعاملات.');
                // SHOW CREATE reads structure only; it never reads triggers, environment files or a server dump.
                $structure=(array)DB::selectOne('SHOW CREATE TABLE `'.$table.'`');$ddl=(string)array_values($structure)[1];
                // Column defaults can contain credentials in legacy installations. Do not export such DDL.
                $columns=[];
                foreach(DB::select('SELECT COLUMN_NAME AS name,COLUMN_DEFAULT AS value,IS_NULLABLE AS nullable,DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$table]) as $column){
                    $columns[$column->name]=$column;
                    $safeDefault=in_array($column->value,["''",'NULL','null',''],true)||(in_array($column->type,['tinyint','smallint','int','bigint'],true)&&in_array($column->value,['0','1'],true));
                    abort_if(preg_match(self::PRIVATE_COLUMN,$column->name)&&$column->value!==null&&!$safeDefault,409,'مخطط بيانات الاعتماد يحتاج تنقية قبل تجهيز الجهاز.');
                }
                $data=[];
                foreach($dataset[$table] as $record){
                    $row=$record;
                    if($table==='settings'&&($row['name']??'')==='app_balance'&&(int)$actor->id!==1)$row['payload']=self::json('0');
                    foreach($row as $column=>$value)if(preg_match(self::PRIVATE_COLUMN,$column)){
                        // Cache only this account's password hash for the original offline login form.
                        if($table==='users'&&$column==='password'&&(int)$row['id']===(int)$actor->id)continue;
                        $row[$column]=$columns[$column]->nullable==='YES'?null:(in_array($columns[$column]->type,['tinyint','smallint','int','bigint'],true)?0:'');
                        if($table==='users'&&$column==='password')$row[$column]='!desktop-disabled-'.Str::random(64);
                    }
                    foreach($row as $column=>$value)if(is_string($value)&&in_array($column,['payload','snapshot','data','metadata','custom_properties','responsive_images','context_snapshot'],true))$row[$column]=$this->redactJson($value);
                    $data[]=$row;$rows++;abort_if($rows>500000,413,'البيانات تحتاج تجهيزًا على دفعات.');
                }
                $encoded=self::json($data);$bytes+=strlen($encoded);abort_if($bytes>256*1024*1024,413,'البيانات تحتاج تجهيزًا على دفعات.');
                $schema[$table]=hash('sha256',$ddl);$tables[$table]=['ddl'=>$ddl,'rows'=>$data,'sha256'=>hash('sha256',$encoded)];
            }
            ksort($schema);
            $snapshot=(string)Str::uuid();$media=app(DesktopDashboardMedia::class)->manifest($dataset,$fresh,$actor,$snapshot);
            abort_unless(app(DesktopDashboardSource::class)->matches($source),409,'مصدر البرنامج تغير أثناء التجهيز؛ أعد المحاولة بعد اكتمال التحديث.');
            return ['format'=>1,'kind'=>'initial-dashboard-data','snapshot_id'=>$snapshot,'device_id'=>$fresh->id,
                'actor_id'=>(int)$actor->id,'branches'=>$branches,'generated_at'=>now('UTC')->toIso8601String(),
                'source'=>$source,
                'schema_hash'=>hash('sha256',self::json($schema)),'tables'=>$tables,'media'=>$media['files'],'media_issues'=>$media['issues'],
                // A native client must not mark the entire dashboard prepared while these modules are uncovered.
                'coverage'=>['write_routes'=>array_merge(DesktopDashboardRoutes::WRITES,array_keys(DesktopDashboardLegacy::ROUTES)),'full_dashboard'=>false,'media'=>$media['complete']]];
        });
    }
    private function redactJson(string $value): string
    {
        try{$decoded=json_decode($value,false,512,JSON_THROW_ON_ERROR);}catch(\JsonException $error){return $value;}
        $changed=false;$clean=function($item)use(&$clean,&$changed){
            if(is_object($item))foreach($item as $key=>$v){if(preg_match(self::PRIVATE_COLUMN,$key)){$item->$key=null;$changed=true;}else $item->$key=$clean($v);}
            elseif(is_array($item))$item=array_map($clean,$item);
            return $item;
        };$decoded=$clean($decoded);
        return $changed?self::json($decoded):$value;
    }
    public static function json($value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
}
