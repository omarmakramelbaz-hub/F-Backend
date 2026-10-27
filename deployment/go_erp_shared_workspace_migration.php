<?php
/** Pinned CLI-only deployment metadata/DDL helper. No financial/user-row writes. */
const GO_ERP_WORKSPACE_MIGRATION_GATE = null;
const GO_ERP_WORKSPACE_ROOT = '/home/fasakha/public_html';
const GO_ERP_WORKSPACE_POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1';
const GO_ERP_WORKSPACE_MIGRATIONS = [
    'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php' => [
        'class' => 'CreateDesktopDashboardWorkspaces',
        'tables' => ['desktop_dashboard_workspaces','desktop_dashboard_workspace_members','desktop_dashboard_workspace_manifests']],
    'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php' => [
        'class' => 'CreateWorkspaceCommandAuthorities',
        'tables' => ['desktop_dashboard_ws_command_auth']],
];

function workspace_need($value, string $code): void { if (!$value) throw new RuntimeException($code); }
function workspace_canonical($value): string {
    $sort = function ($value) use (&$sort) {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $sort($item);
        return $value;
    };
    return json_encode($sort($value), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}
function workspace_hash($value): string { return hash('sha256', workspace_canonical($value)); }
function workspace_normalize_column(string $type, $default, string $extra, bool $maria): array {
    workspace_need(!preg_match('/^(enum|set)\s*\(/i',$type),'UNREVIEWED_LITERAL_COLUMN_TYPE');
    $type=strtolower($type);
    // Integer display width does not alter storage/range. ZEROFILL does, and
    // deliberately does not match this rule. Decimal/char widths stay exact.
    $type=preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint)\([0-9]+\)( unsigned)?$/D','$1$2',$type);
    $quoted=$maria&&is_string($default)&&strlen($default)>=2&&$default[0]==="'"&&substr($default,-1)==="'";
    $timestampExpression=false;
    if ($quoted) {
        $literal=substr($default,1,-1);
        workspace_need(!str_contains($literal,'\\'),'UNREVIEWED_ESCAPED_DEFAULT');
        $default=str_replace("''","'",$literal);
    } elseif ($maria&&$default==='NULL') {
        $default=null; // MariaDB SQL NULL; the literal 'NULL' took the branch above.
    } elseif (is_string($default)&&preg_match('/^(timestamp|datetime)(?:\([0-9]+\))?$/D',$type)
            &&preg_match('/^current_timestamp(?:\(\))?$/iD',$default)) {
        $default='current_timestamp';
        $timestampExpression=true;
    }
    if ((is_int($default)||is_bool($default))&&preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)( unsigned)?$/D',$type)) {
        $default=(string)(int)$default;
    }
    // MySQL's DEFAULT_GENERATED describes expression syntax; the expression
    // remains separately compared in default. Generated-column flags remain.
    $extra=strtolower($extra);
    if ($timestampExpression) $extra=trim(preg_replace('/\bdefault_generated\b/i','',$extra));
    return ['type'=>$type,'default'=>$default,'extra'=>$extra];
}
function workspace_table_schema(string $table): ?array {
    $db = \Illuminate\Support\Facades\DB::connection();
    $maria=str_contains(strtolower((string)$db->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION)),'mariadb');
    $rows = $db->select('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$db->getDatabaseName(),$table]);
    if (!$rows) return null;
    workspace_need(count($rows)===1, 'TABLE_IDENTITY_INVALID');
    $constraints=$db->select("SELECT CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_TYPE NOT IN ('PRIMARY KEY','UNIQUE')",[$db->getDatabaseName(),$table]);
    $triggers=$db->select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND EVENT_OBJECT_TABLE=?',[$db->getDatabaseName(),$table]);
    workspace_need(!$constraints&&!$triggers,'UNREVIEWED_TABLE_SIDE_EFFECTS');
    $columns=[];
    foreach ($db->select('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$db->getDatabaseName(),$table]) as $row) {
        $column=workspace_normalize_column($row->COLUMN_TYPE,$row->COLUMN_DEFAULT,$row->EXTRA,$maria);
        $columns[]=['name'=>$row->COLUMN_NAME,'type'=>$column['type'],'nullable'=>$row->IS_NULLABLE==='YES',
            'default'=>$column['default'],'extra'=>$column['extra'],'collation'=>$row->COLLATION_NAME,
            'generation_expression'=>$row->GENERATION_EXPRESSION??''];
    }
    $indexes=[];
    foreach ($db->select('SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,INDEX_TYPE,SUB_PART,COLLATION,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$db->getDatabaseName(),$table]) as $row) {
        $key=$row->INDEX_NAME;
        if (!isset($indexes[$key])) $indexes[$key]=['name'=>$key,'unique'=>(int)$row->NON_UNIQUE===0,'columns'=>[],
            'type'=>$row->INDEX_TYPE,'prefixes'=>[],'orders'=>[]];
        $indexes[$key]['columns'][]=$row->COLUMN_NAME;
        $indexes[$key]['prefixes'][]=$row->SUB_PART===null?null:(int)$row->SUB_PART;
        $indexes[$key]['orders'][]=$row->COLLATION==='D'?'DESC':'ASC';
    }
    return ['engine'=>$rows[0]->ENGINE,'collation'=>$rows[0]->TABLE_COLLATION,'columns'=>$columns,'indexes'=>array_values($indexes)];
}
function workspace_snapshot(string $databaseHash): array {
    $tables=[];$ledger=[];
    foreach (GO_ERP_WORKSPACE_MIGRATIONS as $path=>$spec) {
        foreach ($spec['tables'] as $table) $tables[$table]=workspace_table_schema($table);
        $name=basename($path,'.php');
        $rows=\Illuminate\Support\Facades\DB::table('migrations')->where('migration',$name)->get(['id','migration','batch']);
        workspace_need(count($rows)<=1,'MIGRATION_LEDGER_AMBIGUOUS');
        $ledger[$path]=count($rows)?['id'=>(int)$rows[0]->id,'migration'=>$rows[0]->migration,'batch'=>(int)$rows[0]->batch]:null;
    }
    foreach (['desktop_dashboard_workspace_local_authority','desktop_dashboard_workspace_local_members','desktop_dashboard_ws_authority_history'] as $table) {
        $tables[$table]=workspace_table_schema($table);
    }
    return ['database_id_sha256'=>$databaseHash,'tables'=>$tables,'ledger'=>$ledger,
        'workspace_enabled'=>false,'local'=>false,'financial_rows_changed'=>false,'dropped_tables'=>[]];
}

try {
    ini_set('display_errors','0');
    workspace_need(PHP_SAPI==='cli'&&function_exists('posix_geteuid')&&posix_geteuid()===1001&&posix_getegid()===1003,'APPLICATION_CLI_REQUIRED');
    workspace_need(is_string(GO_ERP_WORKSPACE_MIGRATION_GATE)&&preg_match('/^[a-f0-9]{64}$/D',GO_ERP_WORKSPACE_MIGRATION_GATE),'FINAL_MIGRATION_GATE_UNAVAILABLE');
    $raw=stream_get_contents(STDIN,131073);
    workspace_need(is_string($raw)&&strlen($raw)<=131072,'INPUT_LIMIT');
    $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    workspace_need(is_array($input)&&array_keys($input)===['database_id_sha256','gate','migration_path','mode','table'],'INPUT_SCOPE_INVALID');
    $gate=$input['gate'];
    workspace_need(is_array($gate)&&workspace_hash($gate)===GO_ERP_WORKSPACE_MIGRATION_GATE
        &&$gate['policy']===GO_ERP_WORKSPACE_POLICY&&array_keys($gate['migration_hashes'])===array_keys(GO_ERP_WORKSPACE_MIGRATIONS),'GATE_BINDING_INVALID');
    foreach ($gate['migration_hashes'] as $path=>$pin) {
        workspace_need(is_string($pin)&&preg_match('/^[a-f0-9]{64}$/D',$pin)
            &&is_file(GO_ERP_WORKSPACE_ROOT.'/'.$path)&&!is_link(GO_ERP_WORKSPACE_ROOT.'/'.$path)
            &&hash_file('sha256',GO_ERP_WORKSPACE_ROOT.'/'.$path)===$pin,'MIGRATION_SOURCE_CHANGED');
    }
    require GO_ERP_WORKSPACE_ROOT.'/vendor/autoload.php';
    $app=require GO_ERP_WORKSPACE_ROOT.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    workspace_need(config('desktop_dashboard.local')===false&&config('desktop_dashboard.workspace_enabled')===false
        &&getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')!=='true','WORKSPACE_MUST_STAY_DISABLED');
    $db=\Illuminate\Support\Facades\DB::connection();
    workspace_need($db->getDriverName()==='mysql'&&!preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',$db->getDatabaseName())
        &&$db->getDatabaseName()!=='takeaway_test','PRODUCTION_DATABASE_REQUIRED');
    workspace_need($db->getConfig('charset')==='utf8mb4'&&$db->getConfig('collation')==='utf8mb4_unicode_ci',
        'REVIEWED_SCHEMA_COLLATION_REQUIRED');
    $dbIdentity=['database'=>$db->getDatabaseName(),'driver'=>$db->getDriverName(),'host'=>(string)$db->getConfig('host'),
        'port'=>(string)($db->getConfig('port')??'3306'),'username'=>(string)$db->getConfig('username')];
    $databaseHash=workspace_hash($dbIdentity);
    workspace_need(in_array($input['mode'],['identity','observe','create-table','record-ledger'],true),'MODE_INVALID');
    workspace_need($input['mode']==='identity'||hash_equals($databaseHash,(string)$input['database_id_sha256']),'DATABASE_IDENTITY_CHANGED');
    if (in_array($input['mode'],['create-table','record-ledger'],true)) {
        // This private CLI connection owns only its SESSION profile. No global
        // server setting changes, and identity/observe remain read only.
        $db->statement('SET SESSION explicit_defaults_for_timestamp = 1');
        workspace_need((int)$db->selectOne('SELECT @@explicit_defaults_for_timestamp AS explicit_ts')->explicit_ts===1,
            'EXPLICIT_TIMESTAMP_SESSION_REQUIRED');
        $path=$input['migration_path'];
        workspace_need(is_string($path)&&isset(GO_ERP_WORKSPACE_MIGRATIONS[$path]),'MIGRATION_PATH_INVALID');
        $spec=GO_ERP_WORKSPACE_MIGRATIONS[$path];
        workspace_need((int)$db->selectOne("SELECT GET_LOCK('go_erp_workspace_additive_deployment',0) AS acquired")->acquired===1,'MIGRATION_DATABASE_BUSY');
        if ($input['mode']==='create-table') {
            $table=$input['table'];
            workspace_need(in_array($table,$spec['tables'],true)&&workspace_table_schema($table)===null,'TABLE_NOT_ABSENT');
            $real=\Illuminate\Support\Facades\Schema::getFacadeRoot();
            $proxy=new class($real,$table,$spec['tables']) {
                public array $seen=[];
                public function __construct(private $real,private string $target,private array $allowed) {}
                public function create($table,$callback): void {
                    workspace_need(in_array($table,$this->allowed,true)&&!in_array($table,$this->seen,true),'UNREVIEWED_CREATE');
                    $this->seen[]=$table;
                    if ($table===$this->target) $this->real->create($table,$callback);
                }
                public function __call($method,$args) { throw new RuntimeException('UNREVIEWED_SCHEMA_OPERATION'); }
            };
            \Illuminate\Support\Facades\Schema::swap($proxy);
            require_once GO_ERP_WORKSPACE_ROOT.'/'.$path;
            $class=$spec['class']; (new $class())->up();
            \Illuminate\Support\Facades\Schema::swap($real);
            workspace_need($proxy->seen===$spec['tables'],'CREATE_ROSTER_CHANGED');
            workspace_need(workspace_hash(workspace_table_schema($table))===$gate['schema_hashes'][$table],'CREATED_SCHEMA_MISMATCH');
        } else {
            workspace_need($input['table']===null,'LEDGER_TABLE_SCOPE_INVALID');
            foreach ($spec['tables'] as $table) workspace_need(workspace_hash(workspace_table_schema($table))===$gate['schema_hashes'][$table],'LEDGER_SCHEMA_NOT_READY');
            $name=basename($path,'.php');
            workspace_need(!\Illuminate\Support\Facades\DB::table('migrations')->where('migration',$name)->exists(),'LEDGER_ALREADY_PRESENT');
            $batch=(int)\Illuminate\Support\Facades\DB::table('migrations')->max('batch')+1;
            \Illuminate\Support\Facades\DB::table('migrations')->insert(['migration'=>$name,'batch'=>$batch]);
        }
        $db->selectOne("SELECT RELEASE_LOCK('go_erp_workspace_additive_deployment') AS released");
    }
    $result=$input['mode']==='identity'?['database_id_sha256'=>$databaseHash,'workspace_enabled'=>false,'local'=>false]:workspace_snapshot($databaseHash);
    echo workspace_canonical($result);
} catch (Throwable $error) {
    fwrite(STDERR,"GO_ERP_SHARED_WORKSPACE_SQL_PRIVATE_FAILURE\n");
    exit(1);
}
