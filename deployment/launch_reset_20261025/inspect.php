<?php
declare(strict_types=1);

/** Read-only launch planning. Never boots providers, invokes models, or changes business rows. */
final class LaunchReset20261025Inspection
{
    public const ROOT = '/home/fasakha/public_html';
    public const LIMIT = 130000;
    public const RELATED_COLUMNS = ['branch','order_id','paid_order_id','ticket_id','purchase_id','expense_id','closing_id','batch_id','stock_id','employee_id','device_id'];
    public const BRANCH_TABLES = [
        'takeaway_orders','takeaway_tills','takeaway_till_entries','pos_service_tickets','pos_service_commands','pos_service_kitchen_tickets','pos_branch_print_jobs',
        'branch_expenses','branch_expense_commands','expense_inventory_receipts','branch_inventory','branch_inventory_movements','branch_stock','branch_stock_movements','branch_recipe_sales',
        'branch_shift_closings','branch_shift_sources','branch_shift_print_jobs','branch_order_sequences','branch_operation_commands',
        'branch_employees','branch_employee_days','branch_employee_entries','branch_employee_salaries','branch_employee_pay_hours','branch_payrolls',
        'phone_delivery_batches','phone_delivery_dispatches','crm_purchases','desktop_pos_devices','desktop_pos_operations','desktop_pos_orders',
        'desktop_dashboard_created_scopes','desktop_dashboard_print_ownership','branch_stock_minimums','branch_stock_minimum_alerts',
    ];
    public const AUX_TABLES = [
        'orders','carts','payments','commissions','delegate_notifications','reviews','shippings','order_live_activities','order_board_clocks','notifications','wallets','takeaway_order_items',
        'phone_delivery_batch_items','web_storefront_orders','pos_service_tables','whatsapp_order_drafts','meta_order_drafts','crm_customer_feedback_replies','crm_whatsapp_deliveries',
        'desktop_pos_snapshots','desktop_dashboard_devices','desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_archived_commands',
        'desktop_dashboard_local_state','desktop_dashboard_refreshes','desktop_dashboard_remote_attempts','go_order_payments','go_order_payment_receipts','go_store_orders',
        'go_store_payment_receipts','go_service_jobs','go_service_payments','jobs','failed_jobs',
        'users','resturants','products','resturant_products','stock_ingredients','branch_stock_recipes','branch_basic_recipes','branch_work_hours','branch_attendance_rules',
        'branch_expense_categories','branch_expense_category_overrides','branch_expense_category_settings','branch_expense_category_commands',
        'crm_customers','crm_customer_sources','branch_customers','branch_delivery_companies','settings',
    ];

    public static function id(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name)) throw new RuntimeException('IDENTIFIER_INVALID');
        return '`'.$name.'`';
    }
    public static function canonical($value): string
    {
        $sort = function ($item) use (&$sort) {
            if (!is_array($item)) return $item;
            if (array_keys($item) !== range(0, count($item) - 1)) ksort($item, SORT_STRING);
            foreach ($item as &$child) $child = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    public static function schema(PDO $pdo): array
    {
        $schema = [];
        foreach ($pdo->query("SELECT TABLE_NAME AS t,ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $r) {
            self::id($r['t']);
            $schema[$r['t']] = ['engine'=>$r['e'],'columns'=>[],'references'=>[],'unique_indexes'=>[]];
        }
        if (count($schema) > 500) throw new RuntimeException('SCHEMA_BOUND_EXCEEDED');
        foreach ($pdo->query('SELECT TABLE_NAME AS t,COLUMN_NAME AS c,COLUMN_TYPE AS typ,IS_NULLABLE AS nul,COLUMN_DEFAULT AS def,EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION') as $r) {
            if (!isset($schema[$r['t']])) continue;
            self::id($r['c']);
            $schema[$r['t']]['columns'][$r['c']] = ['type'=>$r['typ'],'nullable'=>$r['nul']==='YES','default'=>$r['def'],'extra'=>$r['extra']];
        }
        foreach ($pdo->query('SELECT k.TABLE_NAME AS t,k.COLUMN_NAME AS c,k.REFERENCED_TABLE_NAME AS parent,k.REFERENCED_COLUMN_NAME AS col,r.DELETE_RULE AS rule FROM information_schema.KEY_COLUMN_USAGE k LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.COLUMN_NAME') as $r) {
            if (!isset($schema[$r['t']])) continue;
            foreach (['c','parent','col'] as $key) self::id($r[$key]);
            $schema[$r['t']]['references'][] = ['column'=>$r['c'],'table'=>$r['parent'],'target_column'=>$r['col'],'delete_rule'=>$r['rule']];
        }
        foreach ($pdo->query('SELECT TABLE_NAME AS t,INDEX_NAME AS i,COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0 ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX') as $r) {
            if (!isset($schema[$r['t']])) continue;
            self::id($r['i']); self::id($r['c']); $schema[$r['t']]['unique_indexes'][$r['i']][] = $r['c'];
        }
        return $schema;
    }
    public static function legacySchema(array $schema): array
    {
        $out = [];
        foreach ($schema as $table=>$meta) {
            $cols=[];
            foreach ($meta['columns'] as $name=>$column) $cols[$name]=$column['type'].($column['nullable']?' nullable':'').($column['extra']?' '.$column['extra']:'');
            $out[$table]=['engine'=>$meta['engine'],'columns'=>$cols,'references'=>$meta['references'],'unique_indexes'=>$meta['unique_indexes']];
        }
        return $out;
    }
    public static function related(string $table, array $meta): bool
    {
        if (in_array($table,array_merge(self::BRANCH_TABLES,self::AUX_TABLES),true)) return true;
        if (array_intersect(self::RELATED_COLUMNS,array_keys($meta['columns']))) return true;
        foreach ($meta['references'] as $reference) if (in_array($reference['table'],array_merge(self::BRANCH_TABLES,self::AUX_TABLES),true)) return true;
        return false;
    }
    public static function publicMeta(array $meta): array
    {
        $copy=$meta;
        foreach ($copy['columns'] as &$column) {
            $default=$column['default']; unset($column['default']);
            $column['default_is_null']=$default===null;
            $column['default_sha256']=$default===null?null:hash('sha256',(string)$default);
        }
        return $copy;
    }
    public static function encodeReport(array $report): string
    {
        $encode=fn(array $value):string=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $out=$encode($report);
        if (strlen($out)<=self::LIMIT) return $out;
        foreach ($report['schema']['changed_related_metadata'] as &$meta) {
            $columns=[];$defaults=[];
            foreach ($meta['columns'] as $name=>$column) {
                $columns[$name]=$column['type'].($column['nullable']?' nullable':'').($column['extra']?' '.$column['extra']:'');
                $defaults[$name]=[$column['default_is_null'],$column['default_sha256']];
            }
            $meta['columns']=$columns;$meta['column_defaults_sha256']=hash('sha256',self::canonical($defaults));
        }
        unset($meta);$report['schema']['metadata_compacted']=true;$out=$encode($report);
        if (strlen($out)>self::LIMIT) throw new RuntimeException('OUTPUT_BOUND_EXCEEDED');
        return $out;
    }
    public static function schemaReport(array $schema, array $expected): array
    {
        $actual=self::legacySchema($schema); $changed=[]; $missing=[]; $extra=[]; $related=[];
        foreach ($expected as $table=>$meta) {
            $meta['unique_indexes']=$meta['unique_indexes']??[];
            if (!isset($actual[$table])) $missing[]=$table;
            elseif (self::canonical($actual[$table])!==self::canonical($meta)) $changed[]=$table;
        }
        foreach ($actual as $table=>$_) if (!isset($expected[$table])) $extra[]=$table;
        foreach (array_unique(array_merge($changed,$extra)) as $table) if (self::related($table,$schema[$table])) $related[$table]=self::publicMeta($schema[$table]);
        return ['sha256'=>hash('sha256',self::canonical($schema)),'legacy_sha256'=>hash('sha256',self::canonical($actual)),
            'baseline_sha256'=>hash('sha256',self::canonical($expected)),'table_count'=>count($schema),
            'changed_tables'=>$changed,'missing_tables'=>$missing,'extra_tables'=>$extra,'changed_related_metadata'=>$related];
    }
    public static function has(array $schema, string $table, array $columns=[]): bool
    {
        return isset($schema[$table])&&!array_diff($columns,array_keys($schema[$table]['columns']));
    }
    public static function rows(PDO $pdo, string $sql, array $params=[]): array
    {
        $statement=$pdo->prepare($sql); $statement->execute($params); $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>1000) throw new RuntimeException('AGGREGATE_BOUND_EXCEEDED');
        return $rows;
    }
    public static function count(PDO $pdo, string $sql, array $params=[]): int
    {
        $rows=self::rows($pdo,$sql,$params); $value=$rows[0]['n']??null;
        if (!is_string($value)&&!is_int($value) || !preg_match('/^[0-9]{1,15}$/D',(string)$value)) throw new RuntimeException('COUNT_INVALID');
        return (int)$value;
    }
    public static function grouped(PDO $pdo,array $schema,string $table,string $column): array
    {
        if (!self::has($schema,$table,[$column])) return ['available'=>false];
        $rows=self::rows($pdo,'SELECT '.self::id($column).' AS value,COUNT(*) AS n FROM '.self::id($table).' GROUP BY '.self::id($column).' LIMIT 1001');
        $out=[];
        foreach ($rows as $row) {
            $label=self::label($row['value'],$column);
            $out[$label]=($out[$label]??0)+(int)$row['n'];
        }
        ksort($out,SORT_STRING); return $out;
    }
    public static function label($value,string $column): string
    {
        if ($value===null) return '_null_';
        $value=(string)$value;
        $states=['0','1','-1','true','false','pending','acknowledged','conflict','committed','cancelled','canceled','started','queued','failed','failure',
            'processing','ready','review','reversal_review','created','paid','success','succeeded','completed','blocked','open','closed','draft','refreshing',
            'stale','fenced','applied','preparing','prepared','unknown','accepted','rejected','finished','delivered','delivering','in_progress','on_the_way','in_delivery'];
        if ($column==='enabled') return in_array($value,['0','1'],true)?$value:'_other_';
        if (in_array($column,['status','state'],true)) return in_array($value,$states,true)?$value:'_other_';
        if ($column==='type') return in_array($value,['current','schedule','shipping','store','service','go','go_store'],true)?$value:'_other_';
        if ($column==='kind'&&in_array($value,['create','approve','void','save','receive','close','pay'],true)) return $value;
        return preg_match('/^[a-z][a-z0-9_-]{0,40}(?:\.[a-z][a-z0-9_-]{0,50}){1,4}$/D',$value)?$value:'_other_';
    }
    public static function fingerprint(PDO $pdo,array $schema,string $table,array $columns): array
    {
        if (!self::has($schema,$table,$columns)||!in_array('id',$columns,true)) return ['available'=>false,'missing_columns'=>array_values(array_diff($columns,array_keys($schema[$table]['columns']??[])))];
        $statement=$pdo->query('SELECT '.implode(',',array_map([self::class,'id'],$columns)).' FROM '.self::id($table).' ORDER BY id');
        $hash=hash_init('sha256'); $n=0;
        while ($row=$statement->fetch(PDO::FETCH_ASSOC)) {
            if (++$n>1000000) throw new RuntimeException('FINGERPRINT_BOUND_EXCEEDED');
            hash_update($hash,self::canonical($row)."\n");
        }
        $statement->closeCursor(); return ['available'=>true,'rows'=>$n,'sha256'=>hash_final($hash),'columns'=>$columns];
    }
    public static function financial(PDO $pdo,array $schema): array
    {
        $tables=[]; $branches=[];
        foreach (array_unique(array_merge(self::BRANCH_TABLES,self::AUX_TABLES)) as $table) {
            if (!isset($schema[$table])) {$tables[$table]=['present'=>false]; continue;}
            $count=self::count($pdo,'SELECT COUNT(*) AS n FROM '.self::id($table));
            $entry=['present'=>true,'rows'=>$count];
            if (self::has($schema,$table,['branch'])) {
                $row=self::rows($pdo,"SELECT COALESCE(SUM(branch LIKE 'f:%'),0) AS f_rows,COALESCE(SUM(branch LIKE 'g:%'),0) AS g_rows,COALESCE(SUM(branch IS NULL),0) AS null_rows,COALESCE(SUM(branch LIKE 'f:%' AND branch NOT REGEXP '^f:[1-9][0-9]{0,18}$'),0) AS invalid_f_rows FROM ".self::id($table))[0];
                foreach ($row as $key=>$value) $entry[$key]=(int)$value;
                $entry['other_rows']=$count-$entry['f_rows']-$entry['g_rows']-$entry['null_rows'];
                foreach (self::rows($pdo,"SELECT DISTINCT branch FROM ".self::id($table)." WHERE branch LIKE 'f:%' ORDER BY branch LIMIT 1001") as $row) {
                    if (preg_match('/^f:[1-9][0-9]{0,18}$/D',(string)$row['branch'])) $branches[$row['branch']]=true;
                }
            }
            $tables[$table]=$entry;
        }
        ksort($branches,SORT_NATURAL);
        $balances=[];
        foreach (['takeaway_tills'=>'balance_cents','branch_inventory'=>'quantity_units','branch_stock'=>'quantity_units'] as $table=>$column) {
            if (self::has($schema,$table,['branch',$column])) $balances[$table]=self::rows($pdo,'SELECT COUNT(*) AS rows_count,COALESCE(SUM('.self::id($column).'<>0),0) AS nonzero_rows,COALESCE(SUM('.self::id($column).'),0) AS sum_units FROM '.self::id($table)." WHERE branch LIKE 'f:%'")[0];
        }
        $orderGroups=[];$selected='';
        if (self::has($schema,'orders',['id','resturant_id','type','status'])&&self::has($schema,'resturants',['id'])) {
            $orderGroups=self::rows($pdo,"SELECT o.resturant_id,o.type,o.status,COUNT(*) AS n FROM orders o GROUP BY o.resturant_id,o.type,o.status ORDER BY o.resturant_id,o.type,o.status LIMIT 1001");
            foreach ($orderGroups as &$row) {
                $row['resturant_id']=preg_match('/^[0-9]{1,19}$/D',(string)$row['resturant_id'])?(string)$row['resturant_id']:null;
                $row['type']=self::label($row['type'],'type');$row['status']=self::label($row['status'],'status');
                $row['n']=(int)$row['n'];
            }
            unset($row);
            $selected=" FROM orders o INNER JOIN resturants r ON r.id=o.resturant_id WHERE o.type IN ('current','schedule')";
        }
        $legacy=['orders_by_restaurant_type'=>$orderGroups];
        if ($selected!=='') {
            $legacy['current_schedule_restaurant_orders']=self::count($pdo,'SELECT COUNT(*) AS n'.$selected);
            foreach (['carts','payments','commissions','delegate_notifications','reviews','shippings','order_live_activities','wallets'] as $table) {
                if (self::has($schema,$table,['order_id'])) $legacy['linked_counts'][$table]=self::count($pdo,'SELECT COUNT(*) AS n FROM '.self::id($table).' child WHERE child.order_id IN (SELECT o.id'.$selected.')');
            }
            if (self::has($schema,'payments',['order_id','status'])) {
                $rows=self::rows($pdo,'SELECT p.status,COUNT(*) AS n FROM payments p WHERE p.order_id IN (SELECT o.id'.$selected.') GROUP BY p.status');
                $legacy['payments_status_counts']=[];
                foreach ($rows as $row) {$key=self::label($row['status'],'status');$legacy['payments_status_counts'][$key]=($legacy['payments_status_counts'][$key]??0)+(int)$row['n'];}
            }
            if (self::has($schema,'notifications',['type','data'])) {
                $names=['App\\Notifications\\NotifyResturantOrderCreatedNotification','App\\Notifications\\NotifyUserOrderStatusUpdatedNotification','App\\Notifications\\NotifyOrderPriceTransferToWalletNotification'];
                $marks=implode(',',array_fill(0,count($names),'?'));
                $legacy['known_order_notification_rows']=self::count($pdo,'SELECT COUNT(*) AS n FROM notifications WHERE type IN ('.$marks.')',$names);
            }
        }
        $preservation=['wallets'=>self::fingerprint($pdo,$schema,'wallets',['id','from_user','to_user','amount','type','payment','status','transfer_reference','created_at','updated_at']),
            'user_balances'=>self::fingerprint($pdo,$schema,'users',['id','balance','min_wallet'])];
        return ['branch_values'=>array_keys($branches),'table_counts'=>$tables,'f_balance_aggregates'=>$balances,'legacy_orders'=>$legacy,'preservation_fingerprints'=>$preservation];
    }
    public static function desktop(PDO $pdo,array $schema): array
    {
        $out=[];
        foreach (['desktop_dashboard_devices'=>'enabled','desktop_dashboard_commands'=>'status','desktop_dashboard_remote_attempts'=>'status',
            'desktop_dashboard_local_state'=>'state','desktop_dashboard_refreshes'=>'state','desktop_pos_devices'=>'enabled'] as $table=>$column) $out[$table]=self::grouped($pdo,$schema,$table,$column);
        $out['desktop_command_routes']=self::grouped($pdo,$schema,'desktop_dashboard_commands','route_name');
        $out['branch_operation_kinds']=self::grouped($pdo,$schema,'branch_operation_commands','kind');
        $out['expense_command_kinds']=self::grouped($pdo,$schema,'branch_expense_commands','kind');
        $out['go_order_payment_status']=self::grouped($pdo,$schema,'go_order_payments','status');
        $out['go_service_payment_status']=self::grouped($pdo,$schema,'go_service_payments','status');
        return $out;
    }
}

function launchReset20261025InspectionMain(array $args): int
{
    $pdo=null;$stage='arguments';$result=null;
    try {
        if (count($args)!==2||$args[1]!==LaunchReset20261025Inspection::ROOT||PHP_SAPI!=='cli'
            ||gethostname()===false||explode('.',(string)gethostname())[0]!=='srv1836524') throw new RuntimeException('HOST_ARGUMENTS_REQUIRED');
        $root=$args[1];
        if (realpath($root)!==$root||is_link($root)||!function_exists('posix_geteuid')||posix_geteuid()!==fileowner($root)) throw new RuntimeException('APPLICATION_OWNER_REQUIRED');
        $stage='configuration';
        require $root.'/vendor/autoload.php'; $app=require $root.'/bootstrap/app.php';
        (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($app);
        (new Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($app);
        $config=$app['config']->get('database');$capsule=new Illuminate\Database\Capsule\Manager();
        $capsule->addConnection($config['connections'][$config['default']]);$connection=$capsule->getConnection();
        if ($connection->getDriverName()!=='mysql'||$connection->getTablePrefix()!=='') throw new RuntimeException('UNSUPPORTED_DATABASE');
        $connection->disableQueryLog();$pdo=$connection->getPdo();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $stage='read_only_transaction';
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
        // First InnoDB read anchors the repeatable-read business-data snapshot.
        LaunchReset20261025Inspection::count($pdo,'SELECT COUNT(*) AS n FROM users');
        $stage='schema';$schema=LaunchReset20261025Inspection::schema($pdo);
        $baseline=json_decode(file_get_contents(__DIR__.'/inspected_schema_oct19.json'),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($baseline)) throw new RuntimeException('BASELINE_INVALID');
        $schemaReport=LaunchReset20261025Inspection::schemaReport($schema,$baseline);
        $schemaReport['trigger_count']=LaunchReset20261025Inspection::count($pdo,'SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()');
        $schemaReport['visible_cross_schema_foreign_key_count']=LaunchReset20261025Inspection::count($pdo,'SELECT COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA=DATABASE() OR REFERENCED_TABLE_SCHEMA=DATABASE()) AND REFERENCED_TABLE_NAME IS NOT NULL AND REFERENCED_TABLE_SCHEMA<>TABLE_SCHEMA');
        $schemaReport['foreign_key_visibility']='database_account';
        $stage='financial';$financial=LaunchReset20261025Inspection::financial($pdo,$schema);
        $stage='desktop';$desktop=LaunchReset20261025Inspection::desktop($pdo,$schema);
        $stage='payroll';require_once __DIR__.'/payroll_inspect.php';$payroll=LaunchPayrollInspection::inspect($pdo,$schema);
        $stage='schema_recheck';
        if ($schemaReport['sha256']!==hash('sha256',LaunchReset20261025Inspection::canonical(LaunchReset20261025Inspection::schema($pdo)))) throw new RuntimeException('SCHEMA_CHANGED_DURING_INSPECTION');
        $result=['format'=>1,'kind'=>'launch-reset-20261025-inspection','ok'=>true,'mode'=>'read_only',
            'requested'=>['payroll_from'=>'2026-10-01','payroll_through'=>'2026-10-25','reset_financial'=>true,'reset_stock'=>true],
            'schema'=>$schemaReport,'financial'=>$financial,'desktop'=>$desktop,'payroll'=>$payroll,
            'database_changed'=>false,'application_changed'=>false,'messages_sent'=>false,'credentials_disclosed'=>false];
        $stage='rollback';$pdo->rollBack();
        $out=LaunchReset20261025Inspection::encodeReport($result);
        echo 'LAUNCH_RESET_20261025_INSPECTION '.$out.PHP_EOL;return 0;
    } catch (Throwable $error) {
        if ($pdo instanceof PDO&&$pdo->inTransaction()) {try {$pdo->rollBack();} catch (Throwable $ignored) {}}
        $known=['IDENTIFIER_INVALID','UNSUPPORTED_DATABASE','SCHEMA_BOUND_EXCEEDED','AGGREGATE_BOUND_EXCEEDED','COUNT_INVALID','FINGERPRINT_BOUND_EXCEEDED',
            'BASELINE_INVALID','SCHEMA_CHANGED_DURING_INSPECTION','OUTPUT_BOUND_EXCEEDED','HOST_ARGUMENTS_REQUIRED','APPLICATION_OWNER_REQUIRED'];
        $reason=in_array($error->getMessage(),$known,true)?$error->getMessage():'PRIVATE_INSPECTION_FAILURE';
        echo 'LAUNCH_RESET_20261025_INSPECTION '.json_encode(['format'=>1,'kind'=>'launch-reset-20261025-inspection','ok'=>false,
            'mode'=>'read_only','stage'=>$stage,'code'=>'LAUNCH_RESET_INSPECTION_FAILED','reason'=>$reason,'database_changed'=>false,'application_changed'=>false,
            'messages_sent'=>false,'credentials_disclosed'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;return 1;
    }
}
if (PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) exit(launchReset20261025InspectionMain($argv));
