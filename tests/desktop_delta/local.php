<?php
namespace App\Services\Dashboard {
    class DesktopDashboardBootstrap {public static function json($value): string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}}
}
namespace {
    if(!class_exists('PDO')){class PDO {public const FETCH_ASSOC=2;public const FETCH_NUM=3;public const FETCH_COLUMN=7;}}
    function abort_unless($value,$status=422,$message='codec rejected'): void{if(!$value)throw new RuntimeException('CODEC_FIXED_'.$status);}
    require $argv[1].'/app/Services/Dashboard/DesktopDashboardSchema.php';
    require $argv[2];
    require $argv[1].'/app/Services/Dashboard/DesktopDashboardDeltaLocal.php';
    use App\Services\Dashboard\{DesktopDashboardDeltaLocal as Local,DesktopDashboardDeltaCodec as Codec,DesktopDashboardBootstrap as Json};
    const ACTIVE='fasakhansta_dashboard_stage_aaaaaaaaaaaaaaaa';
    const CAPTURE='fasakhansta_dashboard_stage_bbbbbbbbbbbbbbbb';
    const FINAL_DB='fasakhansta_dashboard_stage_cccccccccccccccc';
    const DEVICE='01234567-1234-1234-1234-123456789abc';
    const SNAPSHOT='11234567-1234-1234-1234-123456789abc';
    const NEXT_SNAPSHOT='21234567-1234-1234-1234-123456789abc';
    function check($yes,$message): void{if(!$yes)throw new RuntimeException('TEST_FAILED: '.$message);}
    function denied(callable $work,string $reason): void{try{$work();}catch(Throwable $error){check(str_contains($error->getMessage(),$reason),'expected fixed failure '.$reason);return;}throw new RuntimeException('TEST_FAILED: expected denial '.$reason);}
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
                        'foreign_rows'=>count(array_filter($rows,fn($row)=>$row['device_id']!==$bindings[1]||$row['actor_id']!==$bindings[2]))]]);
                }
                if($m[1]==='COUNT(*) AS `total`')return new Result([['total'=>count($rows)]]);
                return new Result($rows);
            }
            throw new RuntimeException('TEST_UNEXPECTED_QUERY');
        }
        public function commit(): bool{$this->log[]='COMMIT';$this->transaction=false;return true;}
        public function rollBack(): bool{$this->log[]='ROLLBACK';$this->transaction=false;return true;}
    }
    function fixture(): array {
        $old=['format'=>1,'files'=>1327,'framework'=>'8.83.29','sha256'=>str_repeat('1',64)];
        $new=['format'=>1,'files'=>1330,'framework'=>'8.83.29','sha256'=>str_repeat('2',64)];
        $coverage=['print_protocol'=>1,'catalog_scopes'=>0,'full_dashboard'=>true,'media'=>true];
        $db=new FakeDatabase();$db->data=[ACTIVE=>[],CAPTURE=>[],FINAL_DB=>[]];
        $db->data[ACTIVE]=[
            'users'=>[['id'=>1,'name'=>'PRIVATE ACCOUNT VALUE','password'=>'PRIVATE HASH']],
            'settings'=>[['id'=>1,'name'=>'example','payload'=>'PRIVATE SETTING']],
            'desktop_dashboard_local_state'=>[['device_id'=>DEVICE,'actor_id'=>1,'snapshot_id'=>SNAPSHOT,'schema_hash'=>str_repeat('3',64),
                'branches'=>Json::json(['f:1']),'coverage'=>Json::json($coverage),'state'=>'ready']],
            'desktop_dashboard_source_manifest'=>[['device_id'=>DEVICE,'snapshot_id'=>SNAPSHOT,'sha256'=>hash('sha256',Json::json($old)),'source'=>Json::json($old)]],
            'desktop_dashboard_media_manifest'=>[['device_id'=>DEVICE,'snapshot_id'=>SNAPSHOT,'sha256'=>hash('sha256','[]'),'files'=>'[]']],
            'desktop_dashboard_devices'=>[['id'=>DEVICE,'actor_id'=>1,'enrollment_hash'=>str_repeat('4',64),'enrolled_at'=>'2026-10-22 10:34:59','branches'=>'["f:1"]','enabled'=>1]],
            'desktop_dashboard_commands'=>[['sequence'=>7,'device_id'=>DEVICE,'actor_id'=>1,'status'=>'acknowledged','command_id'=>'71234567-1234-1234-1234-123456789abc','route_name'=>'orders.create','request_hash'=>str_repeat('a',64)]],
        ];
        $db->ddl[ACTIVE]=[
            'users'=>"CREATE TABLE `users` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(255) NOT NULL,\n  `password` varchar(255) NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'settings'=>"CREATE TABLE `settings` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(255) NOT NULL,\n  `payload` longtext NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        $runtime=['local'=>true,'host'=>'127.0.0.1','database'=>CAPTURE,'device_id'=>DEVICE,'source'=>$new];
        $input=['action'=>'capture','source_database'=>ACTIVE,'device_id'=>DEVICE,'actor_id'=>1,'snapshot_id'=>SNAPSHOT,'schema_hash'=>str_repeat('3',64),'source'=>$old,'capture_token'=>str_repeat('5',64)];
        return [$db,$runtime,$input,$coverage];
    }
    function noActiveWrites(FakeDatabase $db): void {
        foreach($db->log as $sql)check(!preg_match('/^(?:INSERT INTO|UPDATE|DELETE FROM|CREATE TABLE|DROP DATABASE) `'.ACTIVE.'`/',$sql),'active database never receives DML/DDL');
    }
    function prepare(): array{[$db,$runtime,$input,$coverage]=fixture();$capture=(new Local($db,$runtime))->run($input);return [$db,$runtime,$input,$capture,$coverage];}
    function delta(FakeDatabase $db,array $runtime,array $capture,array $coverage): array {
        $tables=[];
        foreach(array_reverse(array_keys($capture['index']['tables'])) as $name){
            $rows=$db->data[CAPTURE][$name];if($name==='settings')$rows[]=['id'=>2,'name'=>'new','payload'=>'PRIVATE NEW VALUE'];
            $ddl=$db->ddl[CAPTURE][$name];$tables[$name]=['ddl'=>$ddl,'delta'=>Codec::delta($name,$rows,1,$ddl,$capture['index']['tables'][$name])];
        }
        return ['format'=>1,'kind'=>'dashboard-data-delta','device_id'=>DEVICE,'actor_id'=>1,'snapshot_id'=>NEXT_SNAPSHOT,
            'source'=>$runtime['source'],'schema_hash'=>str_repeat('6',64),'branches'=>['f:1'],'coverage'=>$coverage,
            'enrollment'=>$capture['identity']['enrollment'],'created_scopes'=>[],'media'=>[],'tables'=>$tables];
    }
    function importer(FakeDatabase $db,array &$calls): callable {
        return function(array $snapshot)use($db,&$calls){
            $calls[]=$snapshot;
            foreach($snapshot['tables'] as $name=>$part){$db->data[FINAL_DB][$name]=$part['rows'];$db->ddl[FINAL_DB][$name]=$part['ddl'];}
            foreach(['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_devices','desktop_dashboard_local_state','desktop_dashboard_refreshes','desktop_dashboard_archived_commands','desktop_dashboard_media_manifest','desktop_dashboard_source_manifest'] as $table)$db->data[FINAL_DB][$table]=[];
            $db->data[FINAL_DB]['desktop_dashboard_local_state']=[['device_id'=>DEVICE,'actor_id'=>1,'snapshot_id'=>NEXT_SNAPSHOT,'state'=>'ready']];
            return ['format'=>1,'snapshot_id'=>$snapshot['snapshot_id'],'device_id'=>DEVICE,'actor_id'=>1,'source'=>$snapshot['source'],
                'coverage'=>$snapshot['coverage'],'table_rows'=>array_map(fn($part)=>count($part['rows']),$snapshot['tables'])];
        };
    }
    $tests=[];
    $tests['capture privately copies a consistent snapshot without fencing sales']=function(){
        [$db,$runtime,$input]=fixture();$before=$db->data[ACTIVE];
        $db->onStart=function($db){$db->data[ACTIVE]['users'][]=['id'=>2,'name'=>'CONCURRENT SALE USER','password'=>'x'];$db->data[ACTIVE]['desktop_dashboard_commands'][]=['sequence'=>8,'device_id'=>DEVICE,'actor_id'=>1,'status'=>'pending','command_id'=>'81234567-1234-1234-1234-123456789abc','route_name'=>'orders.create','request_hash'=>str_repeat('b',64)];};
        $capture=(new Local($db,$runtime))->run($input);
        check($capture['sequence']===7,'snapshot captures original high water');check($capture['index']['source']===$runtime['source'],'index uses new executing source');
        check($capture['identity']['source']===$input['source'],'identity retains old active source');
        check(count($db->data[CAPTURE]['users'])===1&&count($db->data[ACTIVE]['users'])===2,'concurrent active writes continue independently');
        $wire=Json::json($capture);foreach(['PRIVATE ACCOUNT VALUE','PRIVATE HASH','PRIVATE SETTING','CONCURRENT SALE USER'] as $secret)check(!str_contains($wire,$secret),'index output hides business values');
        noActiveWrites($db);check(!array_key_exists('desktop_dashboard_commands',$db->data[CAPTURE]),'capture never clones accepted journal');
    };
    $tests['forged source identity fails before any private DDL']=function(){
        foreach(['source_database','device_id','actor_id','snapshot_id','schema_hash','source'] as $field){
            [$db,$runtime,$input]=fixture();
            if($field==='source_database')$input[$field]='mysql';elseif($field==='actor_id')$input[$field]=2;
            elseif($field==='source')$input[$field]['sha256']=str_repeat('a',64);else $input[$field]=$field==='device_id'?'31234567-1234-1234-1234-123456789abc':($field==='snapshot_id'?'41234567-1234-1234-1234-123456789abc':str_repeat('7',64));
            denied(fn()=>(new Local($db,$runtime))->run($input),$field==='source_database'||$field==='device_id'?'CAPTURE_BINDING':'SOURCE_IDENTITY');
            check(!array_filter($db->log,fn($sql)=>str_starts_with($sql,'CREATE TABLE')),'forged identity has no DDL');noActiveWrites($db);
        }
    };
    $tests['pending commands abort the capture before row copying']=function(){
        [$db,$runtime,$input]=fixture();$db->data[ACTIVE]['desktop_dashboard_commands'][0]['status']='pending';
        denied(fn()=>(new Local($db,$runtime))->run($input),'CAPTURE_UNSETTLED');
        check(!array_filter($db->log,fn($sql)=>str_starts_with($sql,'INSERT INTO')&&!str_contains($sql,Local::RECEIPT_TABLE)),'pending capture has no business row inserts');noActiveWrites($db);
    };
    $tests['verified deltas reconstruct before importer and bind cleanup proof']=function(){
        [$db,$runtime,$input,$capture,$coverage]=prepare();$runtime['database']=FINAL_DB;$calls=[];
        $response=delta($db,$runtime,$capture,$coverage);
        $receipt=(new Local($db,$runtime,importer($db,$calls)))->run(['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>$response]);
        check(count($calls)===1&&$calls[0]['kind']==='initial-dashboard-data','existing importer receives full validated snapshot');
        check(count($calls[0]['tables']['settings']['rows'])===2,'new server rows applied');
        foreach($calls[0]['tables'] as $part)check(hash_equals($part['sha256'],hash('sha256',Json::json($part['rows']))),'local JSON hash matches assembled rows');
        check(($receipt['delta_stage']['stage_database']??null)===FINAL_DB,'owned stage proof returned');noActiveWrites($db);
    };
    $tests['forged capture or invalid delta never reaches importer or final DDL']=function(){
        foreach(['token','capture','source','delta'] as $case){
            [$db,$runtime,$input,$capture,$coverage]=prepare();$runtime['database']=FINAL_DB;$calls=[];$response=delta($db,$runtime,$capture,$coverage);
            $request=['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>$response];
            if($case==='token')$request['capture_token']=str_repeat('9',64);
            if($case==='capture')$request['capture']['source_database']='fasakhansta_dashboard_stage_dddddddddddddddd';
            if($case==='source')$request['delta']['source']['sha256']=str_repeat('a',64);
            if($case==='delta')$request['delta']['tables']['settings']['delta']['target_hash']=str_repeat('b',64);
            $db->log=[];denied(fn()=>(new Local($db,$runtime,importer($db,$calls)))->run($request),$case==='delta'?'CODEC_FIXED':($case==='source'?'DELTA_BINDING':'CAPTURE_RECEIPT'));
            check($calls===[],'invalid input does not invoke importer');check($db->data[FINAL_DB]===[],'invalid input leaves final DB empty');noActiveWrites($db);
        }
    };
    $tests['capture cleanup requires token marker source and exact private roster']=function(){
        foreach(['valid','token','receipt','active','extra','missing'] as $case){
            [$db,$runtime,$input,$capture]=prepare();$request=['action'=>'discard-capture','capture_database'=>CAPTURE,'source_database'=>ACTIVE,'active_database'=>ACTIVE,'capture_token'=>$input['capture_token'],'capture'=>$capture];
            if($case==='token')$request['capture_token']=str_repeat('9',64);
            if($case==='receipt')$request['capture']['sequence']++;
            if($case==='active')$request['source_database']=CAPTURE;
            if($case==='extra')$db->data[CAPTURE]['desktop_dashboard_commands']=[];
            if($case==='missing')$db->data[CAPTURE][Local::RECEIPT_TABLE]=[];
            if($case==='valid'){check((new Local($db,$runtime))->run($request)['discarded']===true,'valid own capture cleanup');check(!isset($db->data[CAPTURE]),'own clone removed');}
            else{denied(fn()=>(new Local($db,$runtime))->run($request),$case==='active'?'DISCARD_BINDING':($case==='extra'?'DISCARD_TABLES':'DISCARD_RECEIPT'));check(isset($db->data[CAPTURE]),'refused capture kept');}
            check(isset($db->data[ACTIVE]),'active source preserved');noActiveWrites($db);
        }
    };
    $tests['final cleanup cannot remove accepted work or current generation']=function(){
        foreach(['valid','active','token','accepted','extra','source'] as $case){
            [$db,$runtime,$input,$capture,$coverage]=prepare();$runtime['database']=FINAL_DB;$calls=[];
            $receipt=(new Local($db,$runtime,importer($db,$calls)))->run(['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>delta($db,$runtime,$capture,$coverage)]);
            $request=['action'=>'discard-stage','stage_database'=>FINAL_DB,'source_database'=>ACTIVE,'active_database'=>ACTIVE,'capture_token'=>$input['capture_token'],'receipt'=>$receipt];
            if($case==='active')$request['active_database']=FINAL_DB;
            if($case==='token')$request['capture_token']=str_repeat('9',64);
            if($case==='accepted')$db->data[FINAL_DB]['desktop_dashboard_commands'][]=['sequence'=>9];
            if($case==='extra')$db->data[FINAL_DB]['print_ledger_intents']=[];
            if($case==='source')unset($db->data[ACTIVE]['desktop_dashboard_commands']);
            if($case==='valid'){check((new Local($db,$runtime))->run($request)['discarded']===true,'valid own candidate cleanup');check(!isset($db->data[FINAL_DB]),'own empty candidate removed');}
            else{denied(fn()=>(new Local($db,$runtime))->run($request),match($case){'active'=>'DISCARD_STAGE_BINDING','token'=>'DISCARD_STAGE_RECEIPT','accepted'=>'DISCARD_STAGE_ACCEPTED_WORK','extra'=>'DISCARD_TABLES','source'=>'DISCARD_ORIGINAL_SOURCE'});check(isset($db->data[FINAL_DB]),'refused candidate kept');}
            check(isset($db->data[ACTIVE]),'original active DB preserved');noActiveWrites($db);
        }
    };
    $tests['lost capture replies and partial clones are removed only by their native capability']=function(){
        foreach(['pending','empty','missing-marker-nonempty','wrong-token'] as $case){
            [$db,$runtime,$input]=fixture();
            if($case==='pending'){
                $db->data[ACTIVE]['desktop_dashboard_commands'][0]['status']='pending';
                denied(fn()=>(new Local($db,$runtime))->run($input),'CAPTURE_UNSETTLED');
            }elseif($case!=='empty')$capture=(new Local($db,$runtime))->run($input);
            if($case==='missing-marker-nonempty')unset($db->data[CAPTURE][Local::RECEIPT_TABLE]);
            $request=['action'=>'discard-capture','capture_database'=>CAPTURE,'source_database'=>ACTIVE,'active_database'=>ACTIVE,'capture_token'=>$input['capture_token']];
            if($case==='wrong-token')$request['capture_token']=str_repeat('8',64);
            if(in_array($case,['pending','empty'],true)){
                check((new Local($db,$runtime))->run($request)['discarded'],'native-owned partial or markerless empty clone removed');
                check(!isset($db->data[CAPTURE]),'private failed clone absent');
            }else{
                denied(fn()=>(new Local($db,$runtime))->run($request),'DISCARD_RECEIPT');
                check(isset($db->data[CAPTURE]),'unknown nonempty clone retained');
            }
            noActiveWrites($db);
        }
    };
    $tests['external plans clean failed candidates and preserve any unowned or accepted table']=function(){
        foreach(['before-cli','invalid-delta','partial-import','partial-accepted','preexisting','unknown-table'] as $case){
            [$db,$runtime,$input,$capture,$coverage]=prepare();$runtime['database']=FINAL_DB;$calls=[];
            $response=delta($db,$runtime,$capture,$coverage);
            if($case==='preexisting')$db->data[FINAL_DB]['users']=[['foreign'=>'keep']];
            if($case==='invalid-delta')$response['tables']['settings']['delta']['target_hash']=str_repeat('e',64);
            $request=['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>$response];
            if($case!=='before-cli'){
                $importer=function($snapshot)use($db,$case){
                    $db->data[FINAL_DB]['users']=[];
                    if($case==='partial-accepted')$db->data[FINAL_DB]['desktop_dashboard_commands']=[['sequence'=>1]];
                    if($case==='unknown-table')$db->data[FINAL_DB]['foreign_table']=[['keep'=>'yes']];
                    throw new RuntimeException('SYNTHETIC_IMPORT_FAILURE');
                };
                denied(fn()=>(new Local($db,$runtime,$importer))->run($request),match($case){'invalid-delta'=>'CODEC_FIXED','preexisting'=>'TARGET_NOT_EMPTY',default=>'SYNTHETIC_IMPORT_FAILURE'});
            }
            $cleanup=['action'=>'discard-stage','stage_database'=>FINAL_DB,'capture_database'=>CAPTURE,
                'source_database'=>ACTIVE,'active_database'=>ACTIVE,'capture_token'=>$input['capture_token']];
            if(in_array($case,['before-cli','invalid-delta','partial-import'],true)){
                check((new Local($db,$runtime))->run($cleanup)['discarded'],'safe failed final clone removed');
                check(!isset($db->data[FINAL_DB]),'failed final clone absent');
            }else{
                denied(fn()=>(new Local($db,$runtime))->run($cleanup),match($case){'preexisting'=>'DISCARD_FINAL_PLAN','partial-accepted'=>'DISCARD_STAGE_ACCEPTED_WORK',default=>'DISCARD_TABLES'});
                check(isset($db->data[FINAL_DB]),'unowned or accepted final clone retained');
            }
            noActiveWrites($db);
        }
    };
    $tests['authorized schema replacement and new or retired tables affect only the fresh import']=function(){
        foreach(['valid','incomplete-remove','corrupt-retired'] as $case){
            [$db,$runtime,$input,$capture,$coverage]=prepare();$runtime['database']=FINAL_DB;$calls=[];$response=delta($db,$runtime,$capture,$coverage);
            unset($response['tables']['settings']);
            $newDdl="CREATE TABLE `users` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(255) NOT NULL,\n  `password` varchar(255) NOT NULL,\n  `email` varchar(255) NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            $newRows=[['id'=>1,'name'=>'updated actor','password'=>'!new','email'=>'synthetic@example.invalid']];
            $response['tables']['users']=['ddl'=>$newDdl,'delta'=>Codec::delta('users',$newRows,1,$newDdl,$capture['index']['tables']['users'])];
            $categoryDdl="CREATE TABLE `categories` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(255) NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            $response['tables']['categories']=['ddl'=>$categoryDdl,'delta'=>Codec::delta('categories',[['id'=>1,'name'=>'new category']],1,$categoryDdl,Codec::index('categories',[],1,$categoryDdl))];
            if($case==='incomplete-remove')$response['tables']['users']['delta']['remove']=[];
            if($case==='corrupt-retired')$db->data[CAPTURE]['settings'][0]['payload']='corrupt retired baseline';
            $request=['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>$response];
            if($case==='valid'){
                (new Local($db,$runtime,importer($db,$calls)))->run($request);
                check(isset($calls[0]['tables']['categories'])&&!isset($calls[0]['tables']['settings']),'new table included and retired table omitted in candidate');
                check(isset($calls[0]['tables']['users']['rows'][0]['email']),'replacement uses new authorized schema rows');
                check(count($db->data[ACTIVE]['settings'])===1,'retired source table remains intact');
            }else{
                denied(fn()=>(new Local($db,$runtime,importer($db,$calls)))->run($request),$case==='corrupt-retired'?'CAPTURE_DATA_CHANGED':'CODEC_FIXED');
                check($calls===[]&&$db->data[FINAL_DB]===[],'corrupt baseline/replacement cannot create any final tables');
            }
            noActiveWrites($db);
        }
    };
    $tests['trusted coverage projection changes require complete removal of the verified old hashes']=function(){
        foreach(['valid','incomplete-remove'] as $case){
            [$db,$runtime,$input,$coverage]=fixture();
            $columns=['id'=>'bigint unsigned','profession_key'=>'varchar(100)','type'=>'varchar(100)','status'=>'varchar(100)','source_app'=>'varchar(100)','full_name'=>'varchar(255)','mobile'=>'varchar(100)','private_default'=>'varchar(255)'];
            $ddl="CREATE TABLE `pending_vendors` (\n".implode(",\n",array_map(fn($name,$type)=>'  `'.$name.'` '.$type.' NOT NULL',array_keys($columns),$columns))."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            $db->ddl[ACTIVE]['pending_vendors']=$ddl;$db->data[ACTIVE]['pending_vendors']=[['id'=>1,'profession_key'=>'go_store','type'=>'x','status'=>'accepted','source_app'=>'go','full_name'=>'old name','mobile'=>'old mobile','private_default'=>'must be omitted']];
            $capture=(new Local($db,$runtime))->run($input);$runtime['database']=FINAL_DB;$calls=[];
            $response=delta($db,$runtime,$capture,$coverage);
            $response['coverage']['pending_vendor_eligibility']=['kind'=>'go-store-owner-only','source_fields'=>['id','profession_key','type','status','source_app'],'redacted_required_fields'=>['full_name','mobile']];
            $projection=Codec::projection('pending_vendors',$response['coverage']);
            $newRows=[['id'=>1,'profession_key'=>'go_store','type'=>'x','status'=>'accepted','source_app'=>'go','full_name'=>'','mobile'=>'']];
            $part=Codec::delta('pending_vendors',$newRows,1,$ddl,$capture['index']['tables']['pending_vendors'],$projection);
            check(!isset($part['replace']),'projection-only transition retains four-key wire contract');
            if($case==='incomplete-remove')$part['remove']=[];
            $response['tables']['pending_vendors']=['ddl'=>$ddl,'delta'=>$part];
            $request=['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>$response];
            if($case==='valid'){
                (new Local($db,$runtime,importer($db,$calls)))->run($request);
                check(!array_key_exists('private_default',$calls[0]['tables']['pending_vendors']['rows'][0]),'projection drops every ignored local default field');
            }else{
                denied(fn()=>(new Local($db,$runtime,importer($db,$calls)))->run($request),'CODEC_FIXED');check($calls===[],'partial projection proof never reaches importer');
            }
        }
    };
    $tests['replay protection is projected, ordered, checked and staged outside the final fence']=function(){
        foreach(['valid','tampered'] as $case){
            [$db,$runtime,$input,$coverage]=fixture();
            $historical=['device_id'=>DEVICE,'command_id'=>'51234567-1234-1234-1234-123456789abc','actor_id'=>1,
                'route_name'=>'orders.create','request_hash'=>str_repeat('c',64),'source_database'=>'fasakhansta_dashboard_stage_dddddddddddddddd'];
            $db->data[ACTIVE]['desktop_dashboard_archived_commands']=[$historical];
            $capture=(new Local($db,$runtime))->run($input);
            check($capture['replay']['rows']===2,'historic and current acknowledged replay UUIDs captured');
            $expected=[$historical,['device_id'=>DEVICE,'command_id'=>'71234567-1234-1234-1234-123456789abc','actor_id'=>1,
                'route_name'=>'orders.create','request_hash'=>str_repeat('a',64),'source_database'=>ACTIVE]];
            $digest="desktop-dashboard-replays:1\n";foreach($expected as $row)$digest.=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            check($capture['replay']['sha256']===hash('sha256',$digest),'replay digest uses exact normalized key order and prefix');
            foreach($db->data[CAPTURE][Local::REPLAY_TABLE] as $row)check(array_keys($row)===array_keys($historical),'private replay helper carries no encrypted envelope or business payload');
            if($case==='tampered')$db->data[CAPTURE][Local::REPLAY_TABLE][0]['request_hash']=str_repeat('f',64);
            $runtime['database']=FINAL_DB;$calls=[];
            $request=['action'=>'reconstruct','capture_database'=>CAPTURE,'capture_token'=>$input['capture_token'],'capture'=>$capture,'delta'=>delta($db,$runtime,$capture,$coverage)];
            if($case==='valid'){
                $receipt=(new Local($db,$runtime,importer($db,$calls)))->run($request);
                check($receipt['delta_replay']===$capture['replay'],'final receipt binds the exact captured replay digest and high water');
                check($db->data[FINAL_DB]['desktop_dashboard_archived_commands']===$expected,'replay rows copied into candidate before preverify and activation');
            }else{
                denied(fn()=>(new Local($db,$runtime,importer($db,$calls)))->run($request),'CAPTURE_REPLAY');
                check($calls===[]&&$db->data[FINAL_DB]===[],'changed private replay evidence cannot reach importer');
            }
            noActiveWrites($db);
        }
    };
    foreach($tests as $name=>$test){$test();echo 'PASS '.$name.PHP_EOL;}
    echo json_encode(['passed'=>count($tests)],JSON_THROW_ON_ERROR).PHP_EOL;
}
