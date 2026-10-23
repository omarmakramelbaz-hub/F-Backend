<?php
namespace App\Services\Dashboard {
    // Isolate framework wiring only; the production Local/Codec/Schema SQL is used.
    class DesktopDashboardBootstrap {public static function json($value): string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}}
}
namespace {
function abort_unless($value,$status=422,$message=''): void{if(!$value)throw new RuntimeException('DELTA_CODEC_FIXED_'.$status);}
// Actual private InnoDB proof. All rows/databases are synthetic and disposable.
use Illuminate\Support\Facades\DB;
use App\Services\Dashboard\{DesktopDashboardDeltaLocal as Local,DesktopDashboardDeltaCodec as Codec,
    DesktopDashboardImport,DesktopDashboardSource,DesktopDashboardBootstrap as Json};
$application=realpath($argv[1]??'');$port=(int)($argv[2]??0);
if(!$application||$port<1024||$port>65535)throw new RuntimeException('Usage: delta-local.php application private-MariaDB-port');
$profile=sys_get_temp_dir().'/desktop-delta-local-'.bin2hex(random_bytes(6));
foreach(['private','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache'] as $dir)mkdir($profile.'/'.$dir,0700,true);
$names=[];foreach(['source','capture','final'] as $name)$names[$name]='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
$root=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach($names as $database)$root->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$completed=false;$checks=0;
register_shutdown_function(function()use($root,$names,$profile,&$completed){
    foreach($names as $database)$root->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($profile);
    if(!$completed){fwrite(STDERR,'DESKTOP_DELTA_LOCAL_DID_NOT_COMPLETE'.PHP_EOL);exit(1);}
});
$device='01234567-1234-1234-1234-123456789abc';$snapshot='11234567-1234-1234-1234-123456789abc';
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ENABLED'=>'true','DESKTOP_DASHBOARD_DEVICE_ID'=>$device,
    'DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$names['source'],'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'CACHE_DRIVER'=>'array','SESSION_DRIVER'=>'array','APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php',
    'APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php','APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php',
    'APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
try{
    require $application.'/app/Services/Dashboard/DesktopDashboardSchema.php';
    require $argv[3]??$application.'/app/Services/Dashboard/DesktopDashboardDeltaCodec.php';
    require $application.'/app/Services/Dashboard/DesktopDashboardDeltaLocal.php';
    $check=function(bool $yes,string $message)use(&$checks){if(!$yes)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;};
    $source=['format'=>1,'files'=>1330,'framework'=>'8.83.29','sha256'=>str_repeat('c',64)];$old=$source;$old['sha256']=str_repeat('a',64);
    $coverage=['full_dashboard'=>true,'print_protocol'=>0,'catalog_scopes'=>0,'media'=>true];
    $db=$names['source'];
    foreach([
        'users'=>'`id` bigint unsigned NOT NULL PRIMARY KEY,`name` varchar(255) NOT NULL,`password` varchar(255) NOT NULL',
        'settings'=>'`id` bigint unsigned NOT NULL PRIMARY KEY,`name` varchar(255) NOT NULL,`payload` longtext NOT NULL',
        'desktop_dashboard_local_state'=>'`device_id` char(36) NOT NULL PRIMARY KEY,`actor_id` bigint unsigned NOT NULL,`snapshot_id` char(36) NOT NULL,`schema_hash` char(64) NOT NULL,`branches` longtext NOT NULL,`coverage` longtext NOT NULL,`state` varchar(16) NOT NULL',
        'desktop_dashboard_source_manifest'=>'`device_id` char(36) NOT NULL PRIMARY KEY,`snapshot_id` char(36) NOT NULL,`sha256` char(64) NOT NULL,`source` longtext NOT NULL',
        'desktop_dashboard_media_manifest'=>'`device_id` char(36) NOT NULL PRIMARY KEY,`snapshot_id` char(36) NOT NULL,`sha256` char(64) NOT NULL,`files` longtext NOT NULL',
        'desktop_dashboard_devices'=>'`id` char(36) NOT NULL PRIMARY KEY,`actor_id` bigint unsigned NOT NULL,`enrollment_hash` char(64) NOT NULL,`enrolled_at` datetime NOT NULL,`branches` longtext NOT NULL,`enabled` tinyint NOT NULL',
        'desktop_dashboard_commands'=>'`sequence` bigint unsigned NOT NULL PRIMARY KEY,`device_id` char(36) NOT NULL,`actor_id` bigint unsigned NOT NULL,`status` varchar(20) NOT NULL,`command_id` char(36) NOT NULL,`route_name` varchar(150) NOT NULL,`request_hash` char(64) NOT NULL',
        'desktop_dashboard_archived_commands'=>'`device_id` char(36) NOT NULL,`command_id` char(36) NOT NULL,`actor_id` bigint unsigned NOT NULL,`route_name` varchar(150) NOT NULL,`request_hash` char(64) NOT NULL,`source_database` varchar(64) NOT NULL,PRIMARY KEY (`device_id`,`command_id`)',
    ] as $table=>$columns)$root->exec('CREATE TABLE `'.$db.'`.`'.$table.'` ('.$columns.') ENGINE=InnoDB');
    $insert=function(string $table,array $row)use($root,$db){$q=$root->prepare('INSERT INTO `'.$db.'`.`'.$table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));};
    $insert('users',['id'=>1,'name'=>'Synthetic actor','password'=>'!synthetic']);$insert('settings',['id'=>1,'name'=>'synthetic','payload'=>'original']);
    $insert('desktop_dashboard_local_state',['device_id'=>$device,'actor_id'=>1,'snapshot_id'=>$snapshot,'schema_hash'=>str_repeat('b',64),'branches'=>'["f:1"]','coverage'=>Json::json($coverage),'state'=>'ready']);
    $insert('desktop_dashboard_source_manifest',['device_id'=>$device,'snapshot_id'=>$snapshot,'sha256'=>hash('sha256',Json::json($old)),'source'=>Json::json($old)]);
    $insert('desktop_dashboard_media_manifest',['device_id'=>$device,'snapshot_id'=>$snapshot,'sha256'=>hash('sha256','[]'),'files'=>'[]']);
    $insert('desktop_dashboard_commands',['sequence'=>7,'device_id'=>$device,'actor_id'=>1,'status'=>'acknowledged','command_id'=>'71234567-1234-1234-1234-123456789abc','route_name'=>'orders.create','request_hash'=>str_repeat('d',64)]);
    $insert('desktop_dashboard_archived_commands',['device_id'=>$device,'command_id'=>'51234567-1234-1234-1234-123456789abc','actor_id'=>1,'route_name'=>'orders.create','request_hash'=>str_repeat('e',64),'source_database'=>'fasakhansta_dashboard_stage_dddddddddddddddd']);
    $private=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$names['capture'].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $writer=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$names['source'].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $writer->exec('SET SESSION innodb_lock_wait_timeout=1');
    // A second real connection commits a business row + journal entry while the
    // capture transaction is still reading its earlier consistent snapshot.
    $proxy=new class($private,$writer,$device) {
        public bool $concurrent=false;
        public function __construct(private PDO $pdo,private PDO $writer,private string $device){}
        public function inTransaction(){return $this->pdo->inTransaction();}
        public function exec($sql){return $this->pdo->exec($sql);}
        public function prepare($sql){return $this->pdo->prepare($sql);}
        public function query($sql){
            if(!$this->concurrent&&preg_match('/^SELECT \* FROM `[^`]+`\.`users`$/D',$sql)){
                $this->writer->beginTransaction();
                $this->writer->exec("INSERT INTO users (id,name,password) VALUES (2,'Concurrent local sale actor','!synthetic')");
                $q=$this->writer->prepare('INSERT INTO desktop_dashboard_commands (sequence,device_id,actor_id,status,command_id,route_name,request_hash) VALUES (8,?,1,?,?,?,?)');$q->execute([$this->device,'pending','81234567-1234-1234-1234-123456789abc','orders.create',str_repeat('f',64)]);
                $this->writer->commit();$this->concurrent=true;
            }
            return $this->pdo->query($sql);
        }
        public function commit(){return $this->pdo->commit();}
        public function rollBack(){return $this->pdo->rollBack();}
    };
    $runtime=['local'=>true,'host'=>'127.0.0.1','database'=>$names['capture'],'device_id'=>$device,'source'=>$source];
    $request=['action'=>'capture','source_database'=>$names['source'],'device_id'=>$device,'actor_id'=>1,'snapshot_id'=>$snapshot,
        'schema_hash'=>str_repeat('b',64),'source'=>$old,'capture_token'=>bin2hex(random_bytes(32))];
    $capture=(new Local($proxy,$runtime))->run($request);
    $check($proxy->concurrent,'a second InnoDB connection commits active business and journal rows during capture');
    $check($capture['sequence']===7&&$capture['index']['tables']['users']['count']===1,'the private capture retains its earlier row and command snapshot');
    $check((int)$root->query('SELECT COUNT(*) FROM `'.$names['source'].'`.users')->fetchColumn()===2,'the active database keeps its concurrent business row');
    $check((int)$root->query('SELECT COUNT(*) FROM `'.$names['source'].'`.desktop_dashboard_commands WHERE status=\'pending\'')->fetchColumn()===1,'the concurrent local journal is preserved and sendable');
    $check($capture['identity']['source']===$old&&$capture['index']['source']===$source,'old active source binding remains distinct from the authorized executing upgrade source');
    $tables=[];$schema=[];
    foreach($capture['index']['tables'] as $table=>$before){
        $ddl=$root->query('SHOW CREATE TABLE `'.$names['capture'].'`.`'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $rows=$root->query('SELECT * FROM `'.$names['capture'].'`.`'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        if($table==='settings')$rows[]=['id'=>2,'name'=>'server new','payload'=>'server value'];
        $tables[$table]=['ddl'=>$ddl,'delta'=>Codec::delta($table,$rows,1,$ddl,$before)];$schema[$table]=hash('sha256',$ddl);
    }
    ksort($schema);$next='21234567-1234-1234-1234-123456789abc';
    $delta=['format'=>1,'kind'=>'dashboard-data-delta','snapshot_id'=>$next,'device_id'=>$device,'actor_id'=>1,
        'branches'=>['f:1'],'coverage'=>$coverage,'source'=>$source,'schema_hash'=>hash('sha256',Json::json($schema)),
        'tables'=>$tables,'media'=>[],'created_scopes'=>[]];
    $final=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$names['final'].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $runtime['database']=$names['final'];
    // The importer boundary is synthetic in this focused engine proof. Existing
    // import suites verify original Laravel checks; all clone/reconstruct SQL,
    // row hashes and cleanup below execute the actual production service.
    $importer=function(array $snapshot)use($final,$names){
        $total=0;
        foreach($snapshot['tables'] as $table=>$part){
            $final->exec($part['ddl']);$rows=$part['rows'];$total+=count($rows);
            if($rows){$columns=array_keys($rows[0]);$q=$final->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');foreach($rows as $row)$q->execute(array_values($row));}
        }
        $final->exec('CREATE TABLE `desktop_dashboard_archived_commands` (`device_id` char(36) NOT NULL,`command_id` char(36) NOT NULL,`actor_id` bigint unsigned NOT NULL,`route_name` varchar(150) NOT NULL,`request_hash` char(64) NOT NULL,`source_database` varchar(64) NOT NULL,PRIMARY KEY (`device_id`,`command_id`)) ENGINE=InnoDB');
        foreach(['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_devices','desktop_dashboard_refreshes','desktop_dashboard_media_manifest','desktop_dashboard_source_manifest'] as $table)$final->exec('CREATE TABLE `'.$table.'` (`id` bigint NOT NULL) ENGINE=InnoDB');
        $final->exec('CREATE TABLE `desktop_dashboard_local_state` (`device_id` char(36) NOT NULL,`actor_id` bigint NOT NULL,`snapshot_id` char(36) NOT NULL,`state` varchar(16) NOT NULL) ENGINE=InnoDB');
        $q=$final->prepare('INSERT INTO desktop_dashboard_local_state (device_id,actor_id,snapshot_id,state) VALUES (?,?,?,?)');$q->execute([$snapshot['device_id'],$snapshot['actor_id'],$snapshot['snapshot_id'],'ready']);
        return ['format'=>1,'device_id'=>$snapshot['device_id'],'actor_id'=>$snapshot['actor_id'],'snapshot_id'=>$snapshot['snapshot_id'],
            'source'=>$snapshot['source'],'coverage'=>$snapshot['coverage'],'tables'=>count($snapshot['tables']),'rows'=>$total,
            'table_rows'=>array_map(fn($part)=>count($part['rows']),$snapshot['tables'])];
    };
    $receipt=(new Local($final,$runtime,$importer))->run(['action'=>'reconstruct','capture_database'=>$names['capture'],
        'capture_token'=>$request['capture_token'],'capture'=>$capture,'delta'=>$delta]);
    $check(($receipt['delta_replay']['rows']??null)===2&&(int)$root->query('SELECT COUNT(*) FROM `'.$names['final'].'`.desktop_dashboard_archived_commands')->fetchColumn()===2,'both historic and current acknowledged UUID replay rows are verified and copied before activation');
    $check($receipt['tables']===2&&$receipt['rows']===3,'the production reconstruct service passes checked baseline plus changed rows to the importer boundary');
    $check((int)$root->query('SELECT COUNT(*) FROM `'.$names['final'].'`.desktop_dashboard_commands')->fetchColumn()===0,'the new staging database contains no accepted local operations');
    $check((int)$root->query('SELECT COUNT(*) FROM `'.$names['source'].'`.desktop_dashboard_commands')->fetchColumn()===2,'reconstruction never deletes or changes the original journal');
    $discard=(new Local($final,$runtime))->run(['action'=>'discard-stage','stage_database'=>$names['final'],'source_database'=>$names['source'],
        'active_database'=>$names['source'],'capture_token'=>$request['capture_token'],'receipt'=>$receipt]);
    $check($discard['discarded']===true,'only the token-owned inactive unused final candidate is removed');
    $runtime['database']=$names['capture'];
    $discard=(new Local($private,$runtime))->run(['action'=>'discard-capture','capture_database'=>$names['capture'],
        'source_database'=>$names['source'],'active_database'=>$names['source'],'capture_token'=>$request['capture_token'],'capture'=>$capture]);
    $check($discard['discarded']===true,'only the token-owned private capture clone is removed');
    $check((int)$root->query('SELECT COUNT(*) FROM `'.$names['source'].'`.desktop_dashboard_commands WHERE status=\'pending\'')->fetchColumn()===1,'cleanup preserves active accepted and pending customer operations');
    $completed=true;echo 'DESKTOP_DELTA_LOCAL_COMPLETE '.$checks.PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);}

}
