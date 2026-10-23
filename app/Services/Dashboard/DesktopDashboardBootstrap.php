<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;

/** A consistent, explicitly scoped initial dataset. Unknown modules stay unprepared. */
class DesktopDashboardBootstrap
{
    public const GLOBAL_TABLES=['categories','stock_ingredients','permissions','roles','role_has_permissions','branch_expense_category_settings','branch_basic_recipes'];
    public const BRANCH_TABLES=[
        'branch_work_hours','branch_employee_pay_hours','branch_expense_category_overrides','branch_order_sequences',
        'branch_stock_minimums','expense_inventory_receipts','crm_customer_sources','crm_purchases',
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
    private const MEMORY_BUDGET=512*1024*1024;
    public function __construct(private DesktopDashboardDevices $devices,private DesktopDashboardData $data) {}

    public function export(object $device): array
    {
        $this->devices->ready();
        abort_unless(DB::transactionLevel()===0,409,'تجهيز البيانات يحتاج معاملة مستقلة.');
        $source=app(DesktopDashboardSource::class)->fingerprint();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return DB::transaction(function()use($device,$source){
            $fresh=$this->devices->lockAuthenticated($device);
            $this->memoryBudget();
            $actor=$this->devices->actor($fresh);$branches=[];
            foreach(json_decode($fresh->branches,true,512,JSON_THROW_ON_ERROR) as $branch){
                $this->devices->branch($fresh,$branch,$actor);$branches[]=$branch;
            }
            abort_unless(count($branches),403);
            $baseBranches=$branches;$scopeGrants=app(DesktopDashboardCreatedScopes::class);
            $created=$scopeGrants->available()?$scopeGrants->export($fresh,$actor):[];
            $branches=$scopeGrants->available()?$scopeGrants->effective($fresh,$actor):$baseBranches;
            // Catalog creation does not extend branch ledgers, orders, stock, tills or quotes.
            $queries=$this->data->queries($fresh,$actor,$baseBranches);
            $createdOwners=[];foreach($created as $item)if(in_array($item['descriptor']['branch'],$branches,true))$createdOwners[]=$item['descriptor']['entity_id'];
            if($createdOwners){
                foreach(['users'=>'id','go_stores'=>'user_id','go_store_products'=>'user_id'] as $table=>$column)
                    if(isset($queries[$table])&&!empty($queries[$table]->wheres))$queries[$table]->orWhereIn($column,$createdOwners);
            }
            $dataset=$this->data->rows($queries);
            // These manifests need the original scoped rows. Compute them before
            // releasing each raw table as its redacted transport copy is built.
            $snapshot=(string)Str::uuid();$media=app(DesktopDashboardMedia::class)->manifest($dataset,$fresh,$actor,$snapshot);
            $roleMemberships=app(DesktopDashboardRoleFacts::class)->manifest($dataset['roles']??[]);
            $eligibility=$this->data->pendingEligibility($queries,$dataset);
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
                $data=[];$tableHash=hash_init('sha256');hash_update($tableHash,'[');$bytes+=2;
                abort_if($bytes>256*1024*1024,413,'البيانات تحتاج تجهيزًا على دفعات.');
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
                    // Identical to json($data), without allocating a second
                    // whole-table JSON string beside the raw and redacted rows.
                    $encoded=self::json($row);$separator=$data===[]?'':',';
                    $bytes+=strlen($separator)+strlen($encoded);abort_if($bytes>256*1024*1024,413,'البيانات تحتاج تجهيزًا على دفعات.');
                    hash_update($tableHash,$separator);hash_update($tableHash,$encoded);
                    $data[]=$row;$rows++;abort_if($rows>500000,413,'البيانات تحتاج تجهيزًا على دفعات.');
                }
                hash_update($tableHash,']');unset($dataset[$table],$encoded,$record,$row);
                $schema[$table]=hash('sha256',$ddl);$tables[$table]=['ddl'=>$ddl,'rows'=>$data,'sha256'=>hash_final($tableHash)];
            }
            ksort($schema);
            abort_unless(app(DesktopDashboardSource::class)->matches($source),409,'مصدر البرنامج تغير أثناء التجهيز؛ أعد المحاولة بعد اكتمال التحديث.');
            $coverage=['write_routes'=>array_merge(DesktopDashboardRoutes::WRITES,array_keys(DesktopDashboardLegacy::ROUTES)),
                'role_memberships'=>$roleMemberships,'full_dashboard'=>false,'print_protocol'=>1,'catalog_scopes'=>$scopeGrants->available()?1:0,'media'=>$media['complete']];
            if($scopeGrants->available()){$coverage['catalog_grants_sha256']=hash('sha256',self::json($created));$coverage['catalog_structural_branches']=$scopeGrants->structural($fresh,$actor);$coverage['base_branches_sha256']=hash('sha256',self::json($baseBranches));$coverage['catalog_branches_sha256']=hash('sha256',self::json($branches));}
            if($eligibility!==null)$coverage['pending_vendor_eligibility']=$eligibility;
            return ['format'=>1,'kind'=>'initial-dashboard-data','snapshot_id'=>$snapshot,'device_id'=>$fresh->id,
                'actor_id'=>(int)$actor->id,'branches'=>$branches,'enrollment'=>['enrollment_hash'=>$fresh->enrollment_hash,'enrolled_at'=>(string)$fresh->enrolled_at,'base_branches'=>$baseBranches],'created_scopes'=>$created,'generated_at'=>now('UTC')->toIso8601String(),
                'source'=>$source,
                // Captured by this export request, not by a CLI/FPM configuration proxy.
                // These settings are diagnostic only; no hash or encoder setting changes.
                'transport_php'=>['capture_scope'=>'snapshot_export','php_sapi'=>PHP_SAPI,'php_version'=>PHP_VERSION,
                    'serialize_precision'=>(string)ini_get('serialize_precision'),'precision'=>(string)ini_get('precision')],
                'schema_hash'=>hash('sha256',self::json($schema)),'tables'=>$tables,'media'=>$media['files'],'media_issues'=>$media['issues'],
                // A native client must not mark the entire dashboard prepared while these modules are uncovered.
                'coverage'=>$coverage];
        });
    }
    private function memoryBudget(): void
    {
        // The authenticated bootstrap request also serializes the returned
        // snapshot in its controller. Keep this request-local budget until PHP
        // finishes the response; restoring it at export() return is too early.
        $quantity=static function($value): ?float {
            $value=trim((string)$value);if($value==='-1')return INF;
            if(!preg_match('/^([0-9]+)\\s*([KMG]?)$/iD',$value,$match))return null;
            return (float)$match[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($match[2])]));
        };
        $message='ذاكرة تجهيز الجهاز غير كافية؛ يلزم السماح بذاكرة 512 ميجا لطلب التجهيز فقط.';
        $before=$quantity(ini_get('memory_limit'));abort_unless($before!==null,503,$message);
        if($before>=self::MEMORY_BUDGET)return;
        abort_unless(@ini_set('memory_limit','512M')!==false&&($quantity(ini_get('memory_limit'))??0)>=self::MEMORY_BUDGET,503,$message);
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
