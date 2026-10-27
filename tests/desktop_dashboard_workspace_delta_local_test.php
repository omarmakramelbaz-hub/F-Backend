<?php
// Actual group PDO/codec/local-MAC/history engines; SQL I/O and the final importer are synthetic.
namespace Illuminate\Support\Facades {
    final class DB {public static function connection(){return new class {public function getDriverName(){return 'mysql';}public function getConfig($key){return '127.0.0.1';}public function getDatabaseName(){return $GLOBALS['group_runtime']['database'];}};}}
    final class Crypt {
        public static function encryptString($plain){return base64_encode($plain).'.'.hash_hmac('sha256',$plain,'synthetic-catalog-key');}
        public static function decryptString($value){$parts=explode('.',$value);$plain=base64_decode($parts[0]??'',true);if(count($parts)!==2||!is_string($plain)||!hash_equals(hash_hmac('sha256',$plain,'synthetic-catalog-key'),$parts[1]))throw new \RuntimeException('Synthetic cipher refused');return $plain;}
    }
}
namespace App\Services\Dashboard {class DesktopDashboardBootstrap {public static function json($value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}}}
namespace {
if(!class_exists('PDO')){class PDO {public const FETCH_ASSOC=2;public const FETCH_NUM=3;public const FETCH_COLUMN=7;}}
function abort_unless($ok,$status=422,$message=''){if(!$ok)throw new RuntimeException('GROUP_FIXED_'.$status,$status);}
function abort_if($ok,$status=422,$message=''){abort_unless(!$ok,$status,$message);}
function config($key){return match($key){'desktop_dashboard.local'=>true,'desktop_dashboard.workspace_enabled'=>true,'desktop_dashboard.device_id'=>DEVICE,'app.key'=>'base64:'.base64_encode(str_repeat('k',32)),default=>null};}
function app($key=null){if($key===null)return new class {function environment($name){return false;}};if($key==='encrypter')return new class {function getKey(){return str_repeat('k',32);}};return $GLOBALS['group_services'][$key]??=new $key;}
$base=dirname(__DIR__);
foreach(['DesktopDashboardSchema','DesktopDashboardDeltaCodec','DesktopDashboardWorkspaceDeltaCodec','DesktopDashboardWorkspaceIssuer','DesktopDashboardWorkspaceAuthority',
    'DesktopDashboardWorkspaceLocalSchema','DesktopDashboardWorkspaceLocalAuthority',
    'DesktopDashboardWorkspaceCommandAuthorities','DesktopDashboardCreatedScopes','DesktopDashboardWorkspaceCreatedScopes',
    'DesktopDashboardDeltaLocal','DesktopDashboardWorkspaceDeltaLocal'] as $class)require $base.'/app/Services/Dashboard/'.$class.'.php';
use App\Services\Dashboard\{DesktopDashboardWorkspaceDeltaLocal as Local,DesktopDashboardWorkspaceDeltaCodec as Codec,
    DesktopDashboardWorkspaceAuthority as Authority,DesktopDashboardBootstrap as Json};
const ACTIVE='fasakhansta_dashboard_stage_aaaaaaaaaaaaaaaa';
const CAPTURE='fasakhansta_dashboard_stage_bbbbbbbbbbbbbbbb';
const FINAL_DB='fasakhansta_dashboard_stage_cccccccccccccccc';
const DEVICE='11111111-1111-4111-8111-111111111111';
const SNAPSHOT='11234567-1234-1234-1234-123456789abc';
const NEXT_SNAPSHOT='21234567-1234-1234-1234-123456789abc';
function check($ok,$label){if(!$ok)throw new RuntimeException('TEST_FAILED '.$label);}
function denied($work,$reason=''){try{$work();}catch(Throwable $error){if($reason!=='')check(str_contains($error->getMessage(),$reason),'expected '.$reason.' got '.$error->getMessage());return;}throw new RuntimeException('Expected refusal '.$reason);}
    final class Result {
        public int $position=0;
        public function __construct(private array $rows){}
        public function fetchAll($style=null): array{return $style===PDO::FETCH_COLUMN?array_map(fn($row)=>array_values($row)[0],$this->rows):$this->rows;}
        public function fetch($style=null){$row=$this->rows[$this->position++]??false;return $row!==false&&$style===PDO::FETCH_NUM?array_values($row):$row;}
    }
    final class Statement {
        private ?Result $result=null;
        public function __construct(private FakeDatabase $db,private string $sql){}
        public function execute(array $bindings=[]): bool{$this->result=$this->db->perform($this->sql,$bindings);return true;}
        public function fetch($style=null){return $this->result->fetch($style);}
        public function fetchAll($style=null){return $this->result->fetchAll($style);}
    }
    final class FakeDatabase {
        public array $data=[],$ddl=[],$log=[],$snapshot=[];
        public bool $transaction=false;
        public $onStart=null;
        public function inTransaction(): bool{return $this->transaction;}
        public function query(string $sql): Result{return $this->perform($sql,[]);}
        public function prepare(string $sql): Statement{return new Statement($this,$sql);}
        public function exec(string $sql): int {
            $this->log[]=$sql;
            check(!preg_match('/\bFOR\s+UPDATE\b|INSERT\s+INTO.+SELECT/is',$sql),'no source locking SQL');
            if(str_starts_with($sql,'SET '))return 0;
            if($sql==='START TRANSACTION WITH CONSISTENT SNAPSHOT'){$this->snapshot=$this->data;$this->transaction=true;if($this->onStart)($this->onStart)($this);return 0;}
            if(preg_match('/^CREATE TABLE `([^`]+)`\.`([^`]+)` \(/',$sql,$m)){
                check($m[1]!==ACTIVE,'no active DDL');check(!array_key_exists($m[2],$this->data[$m[1]]),'no target replacement');
                $this->data[$m[1]][$m[2]]=[];$this->ddl[$m[1]][$m[2]]=preg_replace('/^CREATE TABLE `[^`]+`\.`([^`]+)`/','CREATE TABLE `$1`',$sql);return 0;
            }
            if(preg_match('/^DROP DATABASE `([^`]+)`$/D',$sql,$m)){check($m[1]!==ACTIVE,'no active drop');unset($this->data[$m[1]],$this->ddl[$m[1]]);return 0;}
            throw new RuntimeException('TEST_UNEXPECTED_EXEC');
        }
        public function perform(string $sql,array $bindings): Result {
            $this->log[]=$sql;check(!preg_match('/\bFOR\s+UPDATE\b|INSERT\s+INTO.+SELECT/is',$sql),'no source locking SQL');
            if(preg_match('/^SHOW TABLES FROM `([^`]+)`$/D',$sql,$m))return new Result(array_map(fn($name)=>['table'=>$name],array_keys($this->data[$m[1]]??[])));
            if(preg_match('/^SHOW CREATE TABLE `([^`]+)`\.`([^`]+)`$/D',$sql,$m))return new Result([['Table'=>$m[2],'Create Table'=>$this->ddl[$m[1]][$m[2]]]]);
            if($sql==='SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?')return new Result(isset($this->data[$bindings[0]])?[['SCHEMA_NAME'=>$bindings[0]]]:[]);
            if(preg_match('/^UPDATE `([^`]+)`\.`([^`]+)` SET /',$sql,$m)){
                check($m[1]!==ACTIVE,'no active update');
                foreach($this->data[$m[1]][$m[2]] as &$row){
                    if(str_contains($sql,'SET `receipt`=')){if($row['capture_id']===$bindings[1]&&$row['token_hash']===$bindings[2])$row['receipt']=$bindings[0];}
                    elseif($row['capture_id']===$bindings[2]&&$row['token_hash']===$bindings[3]&&($row['planned_final_database']===null||$row['planned_final_database']===$bindings[4])){
                        $row['planned_final_database']=$bindings[0];$row['final_plan']=$bindings[1];
                    }
                }unset($row);return new Result([]);
            }
            if(preg_match('/^INSERT INTO `([^`]+)`\.`([^`]+)` \((.+)\) VALUES /',$sql,$m)){
                check($m[1]!==ACTIVE,'no active insert');preg_match_all('/`([^`]+)`/',$m[3],$columns);
                $this->data[$m[1]][$m[2]][]=array_combine($columns[1],$bindings);return new Result([]);
            }
            if(preg_match('/^SELECT COUNT\(\*\) AS `total` FROM `([^`]+)`\.`([^`]+)` WHERE `device_id`<>\?$/D',$sql,$m)){
                $rows=$this->transaction&&$m[1]===ACTIVE?($this->snapshot[$m[1]][$m[2]]??[]):($this->data[$m[1]][$m[2]]??[]);
                return new Result([['total'=>count(array_filter($rows,fn($row)=>$row['device_id']!==$bindings[0]))]]);
            }
            if(preg_match('/^SELECT \* FROM `([^`]+)`\.`([^`]+)` WHERE `workspace_id`=\? AND `command_id` IN \(/',$sql,$m)){
                $rows=$this->transaction&&$m[1]===ACTIVE?($this->snapshot[$m[1]][$m[2]]??[]):($this->data[$m[1]][$m[2]]??[]);
                return new Result(array_values(array_filter($rows,fn($row)=>$row['workspace_id']===$bindings[0]&&in_array($row['command_id'],array_slice($bindings,1),true))));
            }
            if(preg_match('/^SELECT \* FROM `([^`]+)`\.`desktop_dashboard_created_scopes` WHERE `device_id`=\? AND `enrollment_id`=\? ORDER BY `grant_id` LIMIT 1001$/D',$sql,$m)){
                $rows=$this->transaction&&$m[1]===ACTIVE?($this->snapshot[$m[1]]['desktop_dashboard_created_scopes']??[]):($this->data[$m[1]]['desktop_dashboard_created_scopes']??[]);
                $rows=array_values(array_filter($rows,fn($row)=>$row['device_id']===$bindings[0]&&$row['enrollment_id']===$bindings[1]));
                usort($rows,fn($a,$b)=>strcmp($a['grant_id'],$b['grant_id']));return new Result(array_slice($rows,0,1001));
            }
            if(preg_match('/^SELECT (.+) FROM `([^`]+)`\.`([^`]+)` WHERE /',$sql,$m)&&str_contains($sql,'`device_id`<>? OR `actor_id`<>?')){
                $rows=$this->transaction&&$m[2]===ACTIVE?($this->snapshot[$m[2]][$m[3]]??[]):($this->data[$m[2]][$m[3]]??[]);
                return new Result([['total'=>count(array_filter($rows,fn($row)=>$row['device_id']!==$bindings[0]||(string)$row['actor_id']!==(string)$bindings[1]))]]);
            }
            if(preg_match('/^SELECT (.+) FROM `([^`]+)`\.`([^`]+)` WHERE /',$sql,$m)&&str_contains($sql,' LIMIT 1000')){
                $rows=$this->transaction&&$m[2]===ACTIVE?($this->snapshot[$m[2]][$m[3]]??[]):($this->data[$m[2]][$m[3]]??[]);
                if(str_contains($sql,'`command_id`>?')){$rows=array_values(array_filter($rows,fn($row)=>$row['device_id']===$bindings[0]&&strcmp($row['command_id'],$bindings[1])>0));usort($rows,fn($a,$b)=>strcmp($a['command_id'],$b['command_id']));}
                else{$rows=array_values(array_filter($rows,fn($row)=>$row['sequence']>$bindings[0]&&$row['sequence']<=$bindings[1]));usort($rows,fn($a,$b)=>$a['sequence']<=>$b['sequence']);}
                return new Result(array_slice($rows,0,1000));
            }
            if(preg_match('/^SELECT (.+) FROM `([^`]+)`\.`([^`]+)`(?: WHERE `([^`]+)`=\?)?$/D',$sql,$m)){
                $rows=$this->transaction&&$m[2]===ACTIVE?($this->snapshot[$m[2]][$m[3]]??[]):($this->data[$m[2]][$m[3]]??[]);
                if(isset($m[4]))$rows=array_values(array_filter($rows,fn($row)=>(string)$row[$m[4]]===(string)$bindings[0]));
                if(str_starts_with($m[1],'COALESCE(MAX(')){
                    return new Result([['sequence'=>$rows?max(array_column($rows,'sequence')):0,
                        'unsettled'=>count(array_filter($rows,fn($row)=>$row['status']!==$bindings[0])),
                        'foreign_rows'=>count(array_filter($rows,fn($row)=>$row['device_id']!==$bindings[1]||(isset($bindings[2])&&$row['actor_id']!==$bindings[2])))]]);
                }
                if($m[1]==='COUNT(*) AS `total`')return new Result([['total'=>count($rows)]]);
                return new Result($rows);
            }
            throw new RuntimeException('TEST_UNEXPECTED_QUERY');
        }
        public function commit(): bool{$this->log[]='COMMIT';$this->transaction=false;return true;}
        public function rollBack(): bool{$this->log[]='ROLLBACK';$this->transaction=false;return true;}
    }
function canonical($value){return Authority::canonical($value);}
final class CatalogFixtureCodec extends \App\Services\Dashboard\DesktopDashboardCreatedScopes {
    public function encode(array $value):string{return $this->json($value);}
    public function digest(array $value):string{return $this->hash($value);}
}
function localMac(string $domain,array $value):string{return hash_hmac('sha256',"desktop-workspace-local-v2\0".$domain."\0".canonical($value),str_repeat('k',32));}
function expected(array $manifest):array{return ['workspace_id'=>DEVICE,'root_fingerprint'=>$manifest['root_fingerprint'],'revision'=>$manifest['revision'],
    'roster_sha256'=>$manifest['roster_sha256'],'manifest_sha256'=>hash('sha256',canonical($manifest)),'token_hash'=>str_repeat('8',64)];}
function workspace(array $manifest):array{return ['workspace_id'=>DEVICE,'root_fingerprint'=>$manifest['root_fingerprint'],'manifest_revision'=>$manifest['revision'],
    'roster_sha256'=>$manifest['roster_sha256'],'manifest_sha256'=>hash('sha256',canonical($manifest))];}
function sidecar(array $row,array $manifest):array {
    $member=null;foreach($manifest['members'] as $m)if($m['actor_id']===$row['actor_id'])$member=$m;
    check($member!==null,'synthetic historical actor exists at original acceptance');
    $proof=['format'=>2,'workspace_id'=>DEVICE,'root_fingerprint'=>$manifest['root_fingerprint'],'actor_id'=>$row['actor_id'],
        'manifest_revision'=>$manifest['revision'],'grant'=>$member['grant'],'command_sha256'=>$row['request_hash']];
    $json=canonical($proof);$value=['workspace_id'=>DEVICE,'command_id'=>$row['command_id'],'actor_id'=>$row['actor_id'],
        'root_fingerprint'=>$manifest['root_fingerprint'],'manifest_revision'=>$manifest['revision'],'grant_hash'=>hash('sha256',$member['grant']),
        'request_hash'=>$row['request_hash'],'proof_json'=>$json,'proof_sha256'=>hash('sha256',$json),
        'accepted_database'=>ACTIVE,'created_at'=>'2026-10-27 10:00:00'];
    $binding=[];foreach(['workspace_id','command_id','actor_id','root_fingerprint','manifest_revision','grant_hash','request_hash','proof_sha256','accepted_database'] as $field)
        $binding[$field]=in_array($field,['actor_id','manifest_revision'],true)?(string)$value[$field]:$value[$field];
    $value['proof_mac']=hash_hmac('sha256','desktop-workspace-command-v2\n'.canonical($binding),str_repeat('k',32));
    // The real SQL schema fixes column order; every copied row has that same order.
    $ordered=[];foreach(['workspace_id','command_id','actor_id','root_fingerprint','manifest_revision','grant_hash','request_hash','proof_json','proof_sha256','proof_mac','accepted_database','created_at'] as $field)$ordered[$field]=$value[$field];
    return $ordered;
}
function fixture():array {
    $f=json_decode(file_get_contents(__DIR__.'/fixtures/desktop-workspace-delta-codec-v2.json'),true,512,JSON_THROW_ON_ERROR);
    $root=['id'=>DEVICE,'custodian_actor_id'=>101,'name'=>'Synthetic shared PC','enabled'=>true,
        'enrollment_hash'=>str_repeat('4',64),'token_hash'=>str_repeat('8',64),'enrolled_at'=>'2026-10-27 09:00:00'];
    $manifest=$f['manifest'];$manifest['root_fingerprint']=(new Authority)->rootFingerprint((object)$root);
    $old=['format'=>1,'files'=>1327,'framework'=>'8.83.29','sha256'=>str_repeat('1',64)];
    $new=['format'=>1,'files'=>1330,'framework'=>'8.83.29','sha256'=>str_repeat('2',64)];
    $coverage=['workspace_protocol'=>2,'print_protocol'=>1,'catalog_scopes'=>0,'full_dashboard'=>false,'media'=>true,
        'root_fingerprint'=>$manifest['root_fingerprint'],'manifest_revision'=>$manifest['revision'],'roster_sha256'=>$manifest['roster_sha256']];
    $state=['device_id'=>DEVICE,'actor_id'=>101,'snapshot_id'=>SNAPSHOT,'schema_hash'=>str_repeat('3',64),'branches'=>'["f:100"]','coverage'=>Json::json($coverage),'state'=>'ready'];
    $record=['format'=>2,'kind'=>'desktop-dashboard-workspace-local-authority','workspace_id'=>DEVICE,'root_fingerprint'=>$manifest['root_fingerprint'],
        'root'=>$root,'manifest_revision'=>$manifest['revision'],'roster_sha256'=>$manifest['roster_sha256'],'manifest_sha256'=>hash('sha256',canonical($manifest)),
        'manifest_signature'=>$manifest['signature'],'member_count'=>count($manifest['members']),
        'snapshot'=>['workspace_id'=>DEVICE,'custodian_actor_id'=>101,'snapshot_id'=>SNAPSHOT,'schema_hash'=>$state['schema_hash'],'source'=>$old,
            'branches'=>['f:100'],'coverage'=>$coverage],
        'database'=>ACTIVE,'expected_binding_digest'=>hash('sha256',canonical(expected($manifest)))];
    $recordRow=['workspace_id'=>DEVICE,'record_json'=>canonical($record),'manifest_json'=>canonical($manifest),'local_mac'=>localMac('record',$record)];
    $memberRows=[];foreach($manifest['members'] as $member)$memberRows[]=['workspace_id'=>DEVICE,'actor_id'=>$member['actor_id'],'manifest_revision'=>$manifest['revision'],
        'member_json'=>canonical($member),'local_mac'=>localMac('member',['record_sha256'=>hash('sha256',canonical($record)),'member'=>$member])];
    $live=['sequence'=>7,'device_id'=>DEVICE,'actor_id'=>101,'status'=>'acknowledged','command_id'=>'71234567-1234-1234-1234-123456789abc','route_name'=>'orders.create','request_hash'=>str_repeat('a',64)];
    $archived=['device_id'=>DEVICE,'command_id'=>'51234567-1234-1234-1234-123456789abc','actor_id'=>202,'route_name'=>'orders.create','request_hash'=>str_repeat('c',64),'source_database'=>'fasakhansta_dashboard_stage_dddddddddddddddd'];
    $db=new FakeDatabase;$db->data=[ACTIVE=>[
        'users'=>$f['users'],'settings'=>[['id'=>1,'name'=>'example','payload'=>'PRIVATE SETTING']],
        'desktop_dashboard_local_state'=>[$state],
        'desktop_dashboard_source_manifest'=>[['device_id'=>DEVICE,'snapshot_id'=>SNAPSHOT,'sha256'=>hash('sha256',Json::json($old)),'source'=>Json::json($old)]],
        'desktop_dashboard_media_manifest'=>[['device_id'=>DEVICE,'snapshot_id'=>SNAPSHOT,'sha256'=>hash('sha256','[]'),'files'=>'[]']],
        'desktop_dashboard_workspace_local_authority'=>[$recordRow],'desktop_dashboard_workspace_local_members'=>$memberRows,
        'desktop_dashboard_commands'=>[$live],'desktop_dashboard_archived_commands'=>[$archived],
        'desktop_dashboard_workspace_command_authorities'=>[sidecar($archived,$manifest),sidecar($live,$manifest)],
    ],CAPTURE=>[],FINAL_DB=>[]];
    $db->ddl[ACTIVE]=['users'=>$f['users_ddl'],'settings'=>"CREATE TABLE `settings` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(255) NOT NULL,\n  `payload` longtext NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'desktop_dashboard_workspace_command_authorities'=>"CREATE TABLE `desktop_dashboard_workspace_command_authorities` (\n  `workspace_id` char(36) NOT NULL,\n  `command_id` char(36) NOT NULL,\n  `actor_id` bigint unsigned NOT NULL,\n  `root_fingerprint` char(64) NOT NULL,\n  `manifest_revision` bigint unsigned NOT NULL,\n  `grant_hash` char(64) NOT NULL,\n  `request_hash` char(64) NOT NULL,\n  `proof_json` text NOT NULL,\n  `proof_sha256` char(64) NOT NULL,\n  `proof_mac` char(64) NOT NULL,\n  `accepted_database` varchar(100) NOT NULL,\n  `created_at` timestamp NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"];
    $runtime=['local'=>true,'host'=>'127.0.0.1','database'=>CAPTURE,'device_id'=>DEVICE,'source'=>$new];$GLOBALS['group_runtime']=$runtime;
    $input=['action'=>'capture','source_database'=>ACTIVE,'device_id'=>DEVICE,'actor_id'=>101,'snapshot_id'=>SNAPSHOT,'schema_hash'=>$state['schema_hash'],
        'source'=>$old,'capture_token'=>str_repeat('5',64),'expected_binding'=>expected($manifest)];
    return [$db,$runtime,$input,$manifest,$coverage];
}
function noActiveWrites(FakeDatabase $db):void {foreach($db->log as $sql)check(!preg_match('/^(?:INSERT INTO|UPDATE|DELETE FROM|CREATE TABLE|DROP DATABASE) `'.ACTIVE.'`/',$sql),'no active-generation DML/DDL');}
function delta(FakeDatabase $db,array $runtime,array $capture,array $manifest,array $coverage,?array $next=null):array {
    $target=$next??$manifest;$codec=new Codec($target);$old=new Codec($manifest);$previous=$next===null?null:$old;$tables=[];
    foreach($capture['index']['tables'] as $name=>$before){$rows=$db->data[CAPTURE][$name];if($name==='settings')$rows[]=['id'=>2,'name'=>'new','payload'=>'PRIVATE NEW VALUE'];
        $ddl=$db->ddl[CAPTURE][$name];$tables[$name]=['ddl'=>$ddl,'delta'=>$codec->delta($name,$rows,$ddl,$before,null,$previous)];}
    $coverage['manifest_revision']=$target['revision'];$coverage['roster_sha256']=$target['roster_sha256'];
    return ['format'=>2,'kind'=>'workspace-data-delta','device_id'=>DEVICE,'actor_id'=>101,'snapshot_id'=>NEXT_SNAPSHOT,'source'=>$runtime['source'],
        'schema_hash'=>str_repeat('6',64),'branches'=>['f:100'],'coverage'=>$coverage,'workspace_manifest'=>$target,
        'workspace_enrollment'=>['token_hash'=>str_repeat('8',64)],'media'=>[],'tables'=>$tables];
}
function importer(FakeDatabase $db,array &$calls):callable {
    return function(array $snapshot,array $expected)use($db,&$calls){$calls[]=$snapshot;
        foreach($snapshot['tables'] as $name=>$part){$db->data[FINAL_DB][$name]=$part['rows'];$db->ddl[FINAL_DB][$name]=$part['ddl'];}
        foreach(['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_devices','desktop_dashboard_refreshes','desktop_dashboard_archived_commands',
            'desktop_dashboard_media_manifest','desktop_dashboard_source_manifest','desktop_dashboard_workspace_local_authority','desktop_dashboard_workspace_local_members',
            'desktop_dashboard_workspace_local_authority_history','desktop_dashboard_workspace_command_authorities'] as $name)$db->data[FINAL_DB][$name]=[];
        $db->data[FINAL_DB]['desktop_dashboard_local_state']=[['device_id'=>DEVICE,'actor_id'=>101,'snapshot_id'=>NEXT_SNAPSHOT,'state'=>'ready']];
        return ['format'=>2,'kind'=>'desktop-workspace-import-receipt','device_id'=>DEVICE,'actor_id'=>101,'snapshot_id'=>NEXT_SNAPSHOT,
            'source'=>$snapshot['source'],'coverage'=>$snapshot['coverage'],'workspace'=>workspace($snapshot['workspace_manifest']),
            'table_rows'=>array_map(fn($part)=>count($part['rows']),$snapshot['tables'])];
    };
}
putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');$tests=[];
$tests['capture preserves B verifiers and captures all actor history without an active fence']=function(){
    [$db,$runtime,$input,$manifest]=fixture();$original=$db->data[ACTIVE];
    $db->onStart=function($db){$db->data[ACTIVE]['settings'][]=['id'=>9,'name'=>'concurrent','payload'=>'concurrent'];$db->data[ACTIVE]['desktop_dashboard_commands'][]=['sequence'=>8,'device_id'=>DEVICE,'actor_id'=>202,'status'=>'pending','command_id'=>'81234567-1234-1234-1234-123456789abc','route_name'=>'orders.create','request_hash'=>str_repeat('d',64)];};
    $capture=(new Local($db,$runtime))->run($input);check($capture['sequence']===7&&$capture['replay']['rows']===2,'consistent queue high water and both actors');
    check(count($db->data[CAPTURE]['settings'])===1&&count($db->data[ACTIVE]['settings'])===2,'source writes continue while private capture stays consistent');
    check($capture['index']['tables']['users']===(new Codec($manifest))->index('users',$original['users'],$db->ddl[ACTIVE]['users']),'group canonical index preserves B verifier');
    check($db->data[CAPTURE]['desktop_dashboard_workspace_command_authorities']===$original['desktop_dashboard_workspace_command_authorities'],'exact sidecar MACs copied without minting');
    check(array_column($db->data[CAPTURE][Local::REPLAY_TABLE],'actor_id')===[202,101],'retired-capable B history is not custody filtered');
    check($capture['catalog']===['format'=>2,'workspace_id'=>DEVICE,'root_fingerprint'=>$manifest['root_fingerprint'],
        'snapshot_id'=>SNAPSHOT,'schema_hash'=>str_repeat('3',64),'sequence'=>7,'count'=>0,'sha256'=>hash('sha256','[]')],'empty catalog commitment binds source and full physical sequence');
    $wire=Json::json($capture);foreach($original['users'] as $row)check(!str_contains($wire,$row['password']),'capture index does not expose any verifier');noActiveWrites($db);
};
$tests['nonempty catalog wire commitment shares the source snapshot and private receipt ownership']=function(){
    [$db,$runtime,$input,$manifest,$coverage]=fixture();$legacy=new CatalogFixtureCodec;
    $wire=json_decode(file_get_contents(__DIR__.'/fixtures/workspace-catalog-wire-v2.json'),true,64,JSON_THROW_ON_ERROR);
    $public=json_decode($wire['created_scopes_json'],true,64,JSON_THROW_ON_ERROR)[0];
    $public['descriptor']['enrollment_id']=$manifest['root_fingerprint'];$public['descriptor']['workspace_authority']['root_fingerprint']=$manifest['root_fingerprint'];
    $descriptor=&$public['descriptor'];$descriptor['grant_id']=$legacy->digest(['workspace-catalog-created-scope:2',DEVICE,$manifest['root_fingerprint'],
        $descriptor['command_id'],$descriptor['actor_id'],'menu_store_owner',$descriptor['origin_entity_id']]);unset($descriptor);
    $public=json_decode($legacy->encode($public),true,64,JSON_THROW_ON_ERROR);$public=['descriptor'=>$public['descriptor'],'creation'=>$public['creation']];
    $catalog=app(\App\Services\Dashboard\DesktopDashboardWorkspaceCreatedScopes::class);
    $coverage=array_merge($coverage,$catalog->coverage(['f:100'],[$public]));
    $db->data[ACTIVE]['desktop_dashboard_local_state'][0]['coverage']=Json::json($coverage);
    $record=&$db->data[ACTIVE]['desktop_dashboard_workspace_local_authority'][0];$body=json_decode($record['record_json'],true,64,JSON_THROW_ON_ERROR);
    $body['snapshot']['coverage']=$coverage;$record['record_json']=canonical($body);$record['local_mac']=localMac('record',$body);
    foreach($db->data[ACTIVE]['desktop_dashboard_workspace_local_members'] as &$member){$facts=json_decode($member['member_json'],true,64,JSON_THROW_ON_ERROR);
        $member['local_mac']=localMac('member',['record_sha256'=>hash('sha256',$record['record_json']),'member'=>$facts]);}unset($member,$record);
    $value=$public+['provenance'=>['kind'=>'bootstrap','snapshot_id'=>SNAPSHOT,'schema_hash'=>str_repeat('3',64),
        'source_hash'=>$db->data[ACTIVE]['desktop_dashboard_source_manifest'][0]['sha256']]];
    $row=array_intersect_key($public['descriptor'],array_flip(['grant_id','device_id','enrollment_id','command_id','actor_id','route_name','capability','entity','entity_id','origin_entity_id','branch']));
    $row['descriptor_hash']=$legacy->digest($value);$row['evidence_cipher']=\Illuminate\Support\Facades\Crypt::encryptString($legacy->encode($value));
    $db->data[ACTIVE]['desktop_dashboard_created_scopes']=[$row];
    $db->onStart=function($db)use($row){$later=$row;$later['grant_id']=str_repeat('f',64);$db->data[ACTIVE]['desktop_dashboard_created_scopes'][]=$later;};
    $capture=(new Local($db,$runtime))->run($input);
    check($capture['catalog']['count']===1&&count($db->data[ACTIVE]['desktop_dashboard_created_scopes'])===2,'catalog commitment uses the same pre-concurrency RR snapshot');
    check($capture['catalog']['sha256']===$coverage['catalog_grants_sha256']&&str_contains(Json::json([$public]),'"balance":1.0'),'capture/public wire/server coverage preserve fractional tokens and one digest');
    $stored=json_decode($db->data[CAPTURE][Local::RECEIPT_TABLE][0]['receipt'],true,128,JSON_THROW_ON_ERROR);
    check($stored['catalog']===$capture['catalog'],'private catalog commitment is inside the token-owned capture receipt');
    $bad=$capture;$bad['catalog']['sha256']=str_repeat('0',64);$response=delta($db,$runtime,$capture,$manifest,$coverage);
    $runtime['database']=FINAL_DB;$GLOBALS['group_runtime']=$runtime;
    denied(fn()=>(new Local($db,$runtime))->run(['action'=>'reconstruct','capture_database'=>CAPTURE,'capture'=>$bad,'capture_token'=>$input['capture_token'],
        'delta'=>$response,'expected_binding'=>expected($manifest)]),'CAPTURE_RECEIPT');
    noActiveWrites($db);
};
$tests['source authority MAC member proof and native expected binding fail closed before cloning']=function(){
    foreach(['record','member','expected','state'] as $bad){[$db,$runtime,$input]=fixture();
        if($bad==='record')$db->data[ACTIVE]['desktop_dashboard_workspace_local_authority'][0]['local_mac']=str_repeat('f',64);
        elseif($bad==='member')$db->data[ACTIVE]['desktop_dashboard_workspace_local_members'][1]['local_mac']=str_repeat('f',64);
        elseif($bad==='expected')$input['expected_binding']['revision']++;
        else $db->data[ACTIVE]['desktop_dashboard_local_state'][0]['actor_id']=202;
        denied(fn()=>(new Local($db,$runtime))->run($input));check($db->data[CAPTURE]===[],'untrusted source never creates private clone');noActiveWrites($db);
    }
};
$tests['missing or forged historical sidecar is never synthesized']=function(){
    foreach(['missing','mac','actor','hash'] as $bad){[$db,$runtime,$input]=fixture();
        if($bad==='missing')array_shift($db->data[ACTIVE]['desktop_dashboard_workspace_command_authorities']);
        elseif($bad==='mac')$db->data[ACTIVE]['desktop_dashboard_workspace_command_authorities'][0]['proof_mac']=str_repeat('f',64);
        elseif($bad==='actor')$db->data[ACTIVE]['desktop_dashboard_archived_commands'][0]['actor_id']=101;
        else $db->data[ACTIVE]['desktop_dashboard_archived_commands'][0]['request_hash']=str_repeat('f',64);
        denied(fn()=>(new Local($db,$runtime))->run($input));noActiveWrites($db);
    }
};
$tests['group reconstruction keeps stable custody all replay actors exact MACs and inactive cleanup']=function(){
    [$db,$runtime,$input,$manifest,$coverage]=fixture();$original=$db->data[ACTIVE];$capture=(new Local($db,$runtime))->run($input);
    $runtime['database']=FINAL_DB;$GLOBALS['group_runtime']=$runtime;$calls=[];$response=delta($db,$runtime,$capture,$manifest,$coverage);
    $receipt=(new Local($db,$runtime,importer($db,$calls)))->run(['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>$response,'expected_binding'=>expected($manifest)]);
    check(count($calls)===1&&$receipt['actor_id']===101,'one importer invocation keeps physical custody A');
    check($receipt['delta_replay']===$capture['replay']&&$receipt['delta_source_binding']===$input['expected_binding'],'replay and independently sealed source expectation preserved');
    check($db->data[FINAL_DB]['desktop_dashboard_workspace_command_authorities']===$original['desktop_dashboard_workspace_command_authorities'],'stage preserves exact original sidecar MAC rows');
    check(array_column($db->data[FINAL_DB]['desktop_dashboard_archived_commands'],'actor_id')===[202,101],'all actor UUIDs preserved in final archive');
    check($calls[0]['tables']['users']['rows'][1]['password']===$original['users'][1]['password'],'B usable verifier survives reconstruction');
    $clean=(new Local($db,$runtime))->run(['action'=>'discard-stage','stage_database'=>FINAL_DB,'source_database'=>ACTIVE,'active_database'=>ACTIVE,'capture_token'=>$input['capture_token'],'receipt'=>$receipt]);
    check($clean['discarded']===true&&!isset($db->data[FINAL_DB]),'only owned unused candidate removed');
    $runtime['database']=CAPTURE;$GLOBALS['group_runtime']=$runtime;(new Local($db,$runtime))->run(['action'=>'discard-capture','capture_database'=>CAPTURE,'source_database'=>ACTIVE,'active_database'=>ACTIVE,'capture_token'=>$input['capture_token'],'capture'=>$capture]);
    check($db->data[ACTIVE]===$original,'cleanup preserves every active business and queue byte');noActiveWrites($db);
};
$tests['forward roster retirement disables B login while preserving B original replay authority']=function(){
    [$db,$runtime,$input,$manifest,$coverage]=fixture();$capture=(new Local($db,$runtime))->run($input);$next=$manifest;$next['revision']++;array_pop($next['members']);$next['roster_sha256']=hash('sha256',canonical($next['members']));
    $runtime['database']=FINAL_DB;$GLOBALS['group_runtime']=$runtime;$calls=[];$response=delta($db,$runtime,$capture,$manifest,$coverage,$next);
    $receipt=(new Local($db,$runtime,importer($db,$calls)))->run(['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>$response,'expected_binding'=>expected($next)]);
    check(array_column($calls[0]['tables']['users']['rows'],'password','id')[202]===Codec::DISABLED_PASSWORD,'retired B verifier is removed in next generation');
    check($receipt['workspace']['manifest_revision']===2&&$receipt['delta_source_binding']['revision']===1,'target and old source manifest expectations remain distinct');
    check(in_array(202,array_column($db->data[FINAL_DB]['desktop_dashboard_archived_commands'],'actor_id'),true),'retired B replay UUID survives under captured original authority');noActiveWrites($db);
};
$tests['tampered clone replay and cross workspace target cannot reach importer']=function(){
    foreach(['rows','replay','expected','manifest','token'] as $bad){[$db,$runtime,$input,$manifest,$coverage]=fixture();$capture=(new Local($db,$runtime))->run($input);
        $runtime['database']=FINAL_DB;$GLOBALS['group_runtime']=$runtime;$calls=[];$response=delta($db,$runtime,$capture,$manifest,$coverage);$targetExpected=expected($manifest);
        if($bad==='rows')$db->data[CAPTURE]['settings'][0]['payload']='tampered';elseif($bad==='replay')$db->data[CAPTURE][Local::REPLAY_TABLE][0]['actor_id']=101;
        elseif($bad==='expected')$targetExpected['workspace_id']='99999999-9999-4999-8999-999999999999';
        elseif($bad==='manifest')$response['workspace_manifest']['members'][0]['grant']=str_repeat('f',64);
        else $input['capture_token']=str_repeat('0',64);
        denied(fn()=>(new Local($db,$runtime,importer($db,$calls)))->run(['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>$response,'expected_binding'=>$targetExpected]));
        check($calls===[]&&$db->data[FINAL_DB]===[],'tamper never reaches importer or final business DDL');noActiveWrites($db);
    }
};
$tests['pending B and cleanup of active accepted or unowned candidate are refused']=function(){
    [$db,$runtime,$input]=fixture();$db->data[ACTIVE]['desktop_dashboard_commands'][0]['actor_id']=202;$db->data[ACTIVE]['desktop_dashboard_commands'][0]['status']='pending';denied(fn()=>(new Local($db,$runtime))->run($input),'CAPTURE_UNSETTLED');
    [$db,$runtime,$input,$manifest,$coverage]=fixture();$capture=(new Local($db,$runtime))->run($input);$runtime['database']=FINAL_DB;$GLOBALS['group_runtime']=$runtime;$calls=[];
    $receipt=(new Local($db,$runtime,importer($db,$calls)))->run(['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>delta($db,$runtime,$capture,$manifest,$coverage),'expected_binding'=>expected($manifest)]);
    $cleanup=['action'=>'discard-stage','stage_database'=>FINAL_DB,'source_database'=>ACTIVE,'active_database'=>ACTIVE,'capture_token'=>$input['capture_token'],'receipt'=>$receipt];
    $bad=$cleanup;$bad['active_database']=FINAL_DB;denied(fn()=>(new Local($db,$runtime))->run($bad),'DISCARD_BINDING');
    $db->data[FINAL_DB]['desktop_dashboard_commands'][]=['command_id'=>'new accepted work'];denied(fn()=>(new Local($db,$runtime))->run($cleanup),'DISCARD_STAGE_ACCEPTED_WORK');$db->data[FINAL_DB]['desktop_dashboard_commands']=[];
    $db->data[FINAL_DB]['unowned_table']=[];denied(fn()=>(new Local($db,$runtime))->run($cleanup),'DISCARD_TABLES');check(isset($db->data[FINAL_DB]),'failed cleanup preserves candidate for review');noActiveWrites($db);
};
$tests['large shared replay history stays paged and every actor MAC reaches the candidate']=function(){
    [$db,$runtime,$input,$manifest,$coverage]=fixture();$history=[];$authorities=[];
    for($i=1;$i<=2201;$i++){
        $row=['device_id'=>DEVICE,'command_id'=>sprintf('%08x-5555-4555-8555-555555555555',$i),'actor_id'=>$i%2?202:101,
            'route_name'=>'orders.create','request_hash'=>hash('sha256','synthetic-command-'.$i),'source_database'=>'fasakhansta_dashboard_stage_dddddddddddddddd'];
        $history[]=$row;$authorities[]=sidecar($row,$manifest);
    }
    $db->data[ACTIVE]['desktop_dashboard_archived_commands']=$history;
    $authorities[]=sidecar($db->data[ACTIVE]['desktop_dashboard_commands'][0],$manifest);$db->data[ACTIVE]['desktop_dashboard_workspace_command_authorities']=$authorities;
    $capture=(new Local($db,$runtime))->run($input);check($capture['replay']['rows']===2202,'replay pages preserve every historic A/B and live UUID');
    $runtime['database']=FINAL_DB;$GLOBALS['group_runtime']=$runtime;$calls=[];
    $receipt=(new Local($db,$runtime,importer($db,$calls)))->run(['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],
        'capture'=>$capture,'delta'=>delta($db,$runtime,$capture,$manifest,$coverage),'expected_binding'=>expected($manifest)]);
    check($receipt['delta_replay']===$capture['replay']&&count($db->data[FINAL_DB]['desktop_dashboard_archived_commands'])===2202,'large captured replay digest survives reconstruction unchanged');
    $sourceMap=array_column($authorities,'proof_mac','command_id');$targetMap=array_column($db->data[FINAL_DB]['desktop_dashboard_workspace_command_authorities'],'proof_mac','command_id');
    ksort($sourceMap);ksort($targetMap);check($sourceMap===$targetMap,'every exact original history MAC remains bound to its command');
    foreach($db->log as $sql)if(str_contains($sql,'`command_id` IN ('))check(substr_count($sql,'?')<=1001,'sidecar pages are bounded to1000 commands');
    noActiveWrites($db);
};
$tests['lost capture replies and partial candidates require native ownership before cleanup']=function(){
    [$db,$runtime,$input]=fixture();$db->data[ACTIVE]['desktop_dashboard_commands'][0]['status']='pending';
    denied(fn()=>(new Local($db,$runtime))->run($input),'CAPTURE_UNSETTLED');check(isset($db->data[CAPTURE][Local::RECEIPT_TABLE]),'failed capture retained its ownership marker');
    $clean=['action'=>'discard-capture','capture_database'=>CAPTURE,'source_database'=>ACTIVE,'active_database'=>ACTIVE,'capture_token'=>$input['capture_token']];
    $bad=$clean;$bad['capture_token']=str_repeat('0',64);denied(fn()=>(new Local($db,$runtime))->run($bad),'DISCARD_RECEIPT');
    (new Local($db,$runtime))->run($clean);check(!isset($db->data[CAPTURE]),'native capability can clean its failed private capture without a lost reply');noActiveWrites($db);
    [$db,$runtime,$input,$manifest,$coverage]=fixture();$capture=(new Local($db,$runtime))->run($input);$runtime['database']=FINAL_DB;$GLOBALS['group_runtime']=$runtime;
    $failing=function($snapshot,$expected)use($db){$db->data[FINAL_DB]['users']=$snapshot['tables']['users']['rows'];throw new RuntimeException('SYNTHETIC_IMPORT_FAILURE');};
    denied(fn()=>(new Local($db,$runtime,$failing))->run(['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,
        'delta'=>delta($db,$runtime,$capture,$manifest,$coverage),'expected_binding'=>expected($manifest)]),'SYNTHETIC_IMPORT_FAILURE');
    $discard=['action'=>'discard-stage','stage_database'=>FINAL_DB,'capture_database'=>CAPTURE,'source_database'=>ACTIVE,'active_database'=>ACTIVE,'capture_token'=>$input['capture_token']];
    (new Local($db,$runtime))->run($discard);check(!isset($db->data[FINAL_DB]),'persisted external plan cleans only its own unused partial import');noActiveWrites($db);
};
foreach($tests as $name=>$test){$test();echo 'PASS workspace-local '.$name.PHP_EOL;}
echo 'Workspace delta local: '.count($tests).' synthetic cases passed with actual local authority MACs, group codec and sidecar verification.'.PHP_EOL;
}
