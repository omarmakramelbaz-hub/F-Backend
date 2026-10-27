<?php
// Actual v2 handoff, replay normalizer, sidecar MACs and media seal; isolated synthetic SQL adapters.
namespace Illuminate\Support\Facades {
    final class Schema {public static function hasTable($name){return array_key_exists($name,DB::$tables);}}
    final class DB {
        public static array $tables=[],$queries=[],$identity=[],$sourceIdentity=[],$sourceExpected=[];
        public static int $level=0,$replayReads=0;public static bool $fenced=false;
        public static string $database='fasakhansta_dashboard_stage_2222222222222222',$host='127.0.0.1';
        public static object $sourceRecord,$sourceState;
        public static function connection(){return new class {
            public function getConfig($key){return DB::$host;}
            public function getDatabaseName(){return DB::$database;}
        };}
        public static function transactionLevel(){return self::$level;}
        public static function transaction($work){self::$level++;try{return $work();}finally{self::$level--;}}
        public static function table($name){return new \WorkspaceHandoffQuery($name);}
        public static function selectOne($sql,$bindings=[]){
            if(self::$fenced)throw new \RuntimeException('Source inspection inside the cutover fence.');
            self::$queries[]=$sql;
            if(str_contains($sql,'desktop_dashboard_workspace_local_authority'))return clone self::$sourceRecord;
            if(str_contains($sql,'desktop_dashboard_local_state'))return clone self::$sourceState;
            throw new \RuntimeException('Unexpected source SQL.');
        }
        public static function select($sql,$bindings=[]){
            if(self::$fenced)throw new \RuntimeException('Replay scan inside the cutover fence.');
            \check(str_ends_with($sql,'WHERE device_id=? AND command_id>? ORDER BY command_id LIMIT 1000'),'Replay SQL is bounded and ordered by physical UUID');
            self::$queries[]=$sql;self::$replayReads++;
            $rows=array_filter(self::$tables['desktop_dashboard_archived_commands'],fn($row)=>$row->device_id===$bindings[0]&&strcmp($row->command_id,$bindings[1])>0);
            usort($rows,fn($a,$b)=>strcmp($a->command_id,$b->command_id));return array_slice($rows,0,1000);
        }
    }
}
namespace App\Services\Dashboard {
    function hash_file(...$arguments){
        if(\Illuminate\Support\Facades\DB::$fenced)throw new \RuntimeException('Full attachment hash inside the cutover fence.');
        $GLOBALS['workspace_handoff_hashes']++;return \hash_file(...$arguments);
    }
    final class DesktopDashboardWorkspaceImport {
        public int $calls=0;
        public function verifyWorkspace(array $receipt,array $expected,?array $seal=null): array {
            if(\Illuminate\Support\Facades\DB::$fenced)throw new \RuntimeException('Full member/source verification inside the cutover fence.');
            $this->calls++;DesktopDashboardMedia::verifyFiles($receipt['media']);return $receipt+['verified'=>true];
        }
    }
    final class DesktopDashboardPrintLedger {
        public int $calls=0;
        public function fence($workspace){\check(\Illuminate\Support\Facades\DB::transactionLevel()>0,'Print root fence is transactional');$this->calls++;}
    }
    final class DesktopDashboardWorkspaceLocalAuthority {
        public int $fixedCalls=0,$sourceCalls=0;
        public function handoffBindings(array $receipt,array $expected): array {
            $this->fixedCalls++;
            \abort_unless(\Illuminate\Support\Facades\DB::$identity['workspace']===$GLOBALS['workspace_handoff_target_workspace'],409);
            \abort_unless(DesktopDashboardWorkspaceAuthority::canonical($receipt)===DesktopDashboardWorkspaceAuthority::canonical($GLOBALS['workspace_handoff_target_workspace']),409);
            return \Illuminate\Support\Facades\DB::$identity;
        }
        public function verifyCaptureBindings(array $record,array $state,string $database,array $expected): array {
            if(\Illuminate\Support\Facades\DB::$fenced)throw new \RuntimeException('Source authority verification inside the cutover fence.');
            $this->sourceCalls++;
            \abort_unless(hash_equals(hash_hmac('sha256',$record['record_json'],'synthetic-local-summary-key'),$record['local_mac']),409);
            \abort_unless(DesktopDashboardWorkspaceAuthority::canonical($expected)===DesktopDashboardWorkspaceAuthority::canonical(\Illuminate\Support\Facades\DB::$sourceExpected)
                &&$state['snapshot_id']===\Illuminate\Support\Facades\DB::$sourceIdentity['snapshot_id']
                &&$state['schema_hash']===\Illuminate\Support\Facades\DB::$sourceIdentity['schema_hash'],409);
            return \Illuminate\Support\Facades\DB::$sourceIdentity;
        }
    }
}
namespace {
    use Illuminate\Support\Facades\DB;
    use App\Services\Dashboard\{DesktopDashboardWorkspaceHandoff as Handoff,DesktopDashboardWorkspaceDeltaLocal as Delta,
        DesktopDashboardWorkspaceAuthority as Authority,DesktopDashboardWorkspaceCommandAuthorities as Commands};
    final class WorkspaceHandoffQuery {
        private array $where=[],$ids=[];private bool $ordered=false;
        public function __construct(private string $table){}
        public function where($field,$a,$b=null){$this->where[]=[$field,$b===null?'=':$a,$b===null?$a:$b];return $this;}
        public function whereIn($field,$ids){$this->ids=[$field,$ids];return $this;}
        public function orderBy($field){$this->ordered=true;return $this;}
        public function lockForUpdate(){return $this;}
        private function history(){return in_array($this->table,['desktop_dashboard_archived_commands',Commands::TABLE],true);}
        private function rows(): array {
            if(DB::$fenced&&$this->history())throw new \RuntimeException('History lookup inside the cutover fence.');
            $data=DB::$tables[$this->table]??[];
            foreach($this->where as [$key,$op,$value])if($key==='command_id'&&$op==='='&&isset($data[$value])){$data=[$data[$value]];break;}
            if($this->ids&&$this->ids[0]==='command_id'){$values=[];foreach($this->ids[1] as $id)if(isset($data[$id]))$values[]=$data[$id];$data=$values;}
            $data=array_values(array_filter($data,function($row){foreach($this->where as [$key,$op,$value]){
                $same=($row->$key??null)===$value;if($op==='='&&!$same||$op==='<>'&&$same)return false;
            }return true;}));
            if($this->ordered)usort($data,fn($a,$b)=>strcmp($a->command_id,$b->command_id));return $data;
        }
        public function first($columns=['*']){DB::$queries[]=$this->table.'.first';$rows=$this->rows();return $rows?clone $rows[0]:null;}
        public function exists(){DB::$queries[]=$this->table.'.exists';return (bool)$this->rows();}
        public function get(){DB::$queries[]=$this->table.'.get';return $this->rows();}
        public function chunk($size,$consume){foreach(array_chunk($this->rows(),$size) as $rows)$consume($rows);}
    }
    function abort($status,$message=''){throw new \RuntimeException($message?:'Synthetic fixed refusal',$status);}
    function abort_unless($ok,$status,$message=''){if(!$ok)abort($status,$message);}
    function abort_if($ok,$status,$message=''){abort_unless(!$ok,$status,$message);}
    function app($name){return $GLOBALS['workspace_handoff_services'][$name]??=new $name;}
    function base_path(){return $GLOBALS['workspace_handoff_application'];}
    function storage_path($path=''){return $GLOBALS['workspace_handoff_root'].($path===''?'':'/'.$path);}
    function config($key){return match($key){
        'desktop_dashboard.local'=>$GLOBALS['workspace_handoff_local'],'desktop_dashboard.workspace_enabled'=>$GLOBALS['workspace_handoff_enabled'],
        'desktop_dashboard.device_id'=>$GLOBALS['workspace_handoff_id'],'app.key'=>'base64:'.base64_encode(str_repeat('k',32)),
        'filesystems.disks.local.driver'=>'local','filesystems.disks.local.root'=>storage_path('app'),default=>null,
    };}
    if(!function_exists('mb_strtolower')){function mb_strtolower($value){return strtolower($value);}}
    function check($ok,$label){if(!$ok)throw new \RuntimeException($label);$GLOBALS['workspace_handoff_checks']++;}
    function refused($work,$label){try{$work();}catch(\RuntimeException $error){
        check(in_array($error->getCode(),[403,409,422,503],true),$label.' refuses with a fixed status');
        check(!str_contains($error->getMessage(),'synthetic-grant-secret'),$label.' contains no captured member authority');return;
    }throw new \RuntimeException($label.' was accepted');}
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceIssuer.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceAuthority.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardBootstrap.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardMedia.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardMediaSeal.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceCommandAuthorities.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardDeltaLocal.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceDeltaLocal.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceHandoff.php';
    $GLOBALS['workspace_handoff_checks']=0;$GLOBALS['workspace_handoff_hashes']=0;$GLOBALS['workspace_handoff_services']=[];
    $GLOBALS['workspace_handoff_local']=true;$GLOBALS['workspace_handoff_enabled']=true;
    $GLOBALS['workspace_handoff_id']=$workspace='11111111-1111-4111-8111-111111111111';
    $GLOBALS['workspace_handoff_application']=__DIR__;
    $GLOBALS['workspace_handoff_root']=$root=sys_get_temp_dir().'/go-workspace-handoff-'.bin2hex(random_bytes(8));
    mkdir($root.'/app/public/products',0700,true);$body='synthetic attachment';file_put_contents($root.'/app/public/products/photo.png',$body);
    $source='fasakhansta_dashboard_stage_1111111111111111';$rootHash=str_repeat('a',64);$token=str_repeat('c',64);
    $expected=['workspace_id'=>$workspace,'root_fingerprint'=>$rootHash,'revision'=>2,'roster_sha256'=>str_repeat('b',64),
        'manifest_sha256'=>str_repeat('d',64),'token_hash'=>str_repeat('e',64)];
    $targetWorkspace=['workspace_id'=>$workspace,'root_fingerprint'=>$rootHash,'manifest_revision'=>2,'roster_sha256'=>$expected['roster_sha256'],'manifest_sha256'=>$expected['manifest_sha256']];
    $sourceExpected=$expected;$sourceExpected['revision']=1;$sourceExpected['roster_sha256']=str_repeat('f',64);$sourceExpected['manifest_sha256']=str_repeat('9',64);
    $sourceWorkspace=$targetWorkspace;$sourceWorkspace['manifest_revision']=1;$sourceWorkspace['roster_sha256']=$sourceExpected['roster_sha256'];$sourceWorkspace['manifest_sha256']=$sourceExpected['manifest_sha256'];
    $sourceIdentity=['snapshot_id'=>'33333333-3333-4333-8333-333333333333','schema_hash'=>str_repeat('8',64),'workspace'=>$sourceWorkspace];
    $receipt=['format'=>2,'kind'=>'desktop-workspace-import-receipt','device_id'=>$workspace,'actor_id'=>7,
        'snapshot_id'=>'44444444-4444-4444-8444-444444444444','schema_hash'=>str_repeat('7',64),
        'source'=>['format'=>1,'sha256'=>str_repeat('6',64)],'branches'=>['f:1','f:2'],
        'coverage'=>['workspace_protocol'=>2,'manifest_revision'=>2,'roster_sha256'=>$expected['roster_sha256'],'print_protocol'=>1],
        'tables'=>119,'rows'=>500000,'table_rows'=>['users'=>1],
        'workspace'=>$targetWorkspace,'delta_source_identity'=>$sourceIdentity,'delta_source_binding'=>$sourceExpected,
        'media'=>[['path'=>'products/photo.png','mime'=>'image/png','bytes'=>strlen($body),'sha256'=>hash('sha256',$body)]]];
    $entries=[];$sidecars=[];$hash=hash_init('sha256');hash_update($hash,"desktop-workspace-replays:2\n");
    for($i=1;$i<=5001;$i++){
        $id=sprintf('%08x-5555-4555-8555-555555555555',$i);$actor=$i%2?7:9;$request=hash('sha256','fixture-command-'.$i);
        $proof=['format'=>2,'workspace_id'=>$workspace,'root_fingerprint'=>$rootHash,'actor_id'=>$actor,'manifest_revision'=>1,
            'grant'=>hash('sha256','synthetic-grant-secret-'.$actor),'command_sha256'=>$request];
        $json=Authority::canonical($proof);
        $row=['workspace_id'=>$workspace,'command_id'=>$id,'actor_id'=>(string)$actor,'root_fingerprint'=>$rootHash,'manifest_revision'=>'1',
            'grant_hash'=>hash('sha256',$proof['grant']),'request_hash'=>$request,'proof_json'=>$json,'proof_sha256'=>hash('sha256',$json),
            'accepted_database'=>$source,'created_at'=>'2026-10-27 12:00:00'];
        $binding=[];foreach(['workspace_id','command_id','actor_id','root_fingerprint','manifest_revision','grant_hash','request_hash','proof_sha256','accepted_database'] as $key)$binding[$key]=$row[$key];
        $row['proof_mac']=hash_hmac('sha256','desktop-workspace-command-v2\n'.Authority::canonical($binding),str_repeat('k',32));
        $sidecars[$id]=(object)$row;
        $replay=['device_id'=>$workspace,'command_id'=>$id,'actor_id'=>(string)$actor,'route_name'=>'takeaway.checkout','request_hash'=>$request,'source_database'=>$source];
        $entries[$id]=(object)$replay;hash_update($hash,Authority::canonical(Delta::replayEntry($replay,$row,$workspace,$rootHash))."\n");
    }
    $receipt['delta_replay']=['format'=>2,'workspace_id'=>$workspace,'root_fingerprint'=>$rootHash,'source_database'=>$source,'sequence'=>5100,'rows'=>count($entries),'sha256'=>hash_final($hash)];
    $GLOBALS['workspace_handoff_target_workspace']=$targetWorkspace;
    DB::$identity=['snapshot_id'=>$receipt['snapshot_id'],'schema_hash'=>$receipt['schema_hash'],'workspace'=>$targetWorkspace];
    DB::$sourceExpected=$sourceExpected;DB::$sourceIdentity=$sourceIdentity;
    $recordJson=Authority::canonical(['database'=>$source,'identity'=>$sourceIdentity,'expected'=>$sourceExpected]);
    DB::$sourceRecord=(object)['record_json'=>$recordJson,'local_mac'=>hash_hmac('sha256',$recordJson,'synthetic-local-summary-key')];
    DB::$sourceState=(object)['device_id'=>$workspace,'actor_id'=>7,'snapshot_id'=>$sourceIdentity['snapshot_id'],'schema_hash'=>$sourceIdentity['schema_hash'],'state'=>'ready'];
    DB::$tables=[
        'desktop_dashboard_local_state'=>[(object)['device_id'=>$workspace,'actor_id'=>7,'snapshot_id'=>$receipt['snapshot_id'],'schema_hash'=>$receipt['schema_hash'],
            'branches'=>json_encode($receipt['branches']),'coverage'=>json_encode($receipt['coverage']),'state'=>'ready']],
        'desktop_dashboard_source_manifest'=>[(object)['device_id'=>$workspace,'snapshot_id'=>$receipt['snapshot_id'],'sha256'=>hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($receipt['source']))]],
        'desktop_dashboard_media_manifest'=>[(object)['device_id'=>$workspace,'snapshot_id'=>$receipt['snapshot_id'],'sha256'=>hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($receipt['media']))]],
        'desktop_dashboard_commands'=>[],'desktop_dashboard_entities'=>[],'desktop_dashboard_refreshes'=>[],
        'users'=>[(object)['id'=>7]],'desktop_dashboard_archived_commands'=>$entries,Commands::TABLE=>$sidecars];
    putenv('DESKTOP_DASHBOARD_LOCAL=true');putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');
    try{
        $handoff=new Handoff;$checked=$handoff->captureWorkspace($receipt,$expected,$token);$seal=$checked['media_seal'];$proof=$checked['archive_handoff'];
        unset($checked['media_seal'],$checked['archive_handoff']);
        check($checked['verified']===true&&DB::$replayReads===6,'All 5001 actor-aware replay rows are checked in bounded pages before fencing');
        check(count(array_filter($entries,fn($row)=>(int)$row->actor_id===9))===2500&&count(DB::$tables['users'])===1,'Retired B history remains with no B current user row');
        check($proof['format']===2&&$proof['kind']==='desktop-workspace-preverified-handoff'&&$proof['payload']['replay']['rows']===5001,'Signed proof retains group format and every archived command');
        check($proof['payload']['source_identity']===$sourceIdentity&&$sourceIdentity['workspace']['manifest_revision']<$checked['workspace']['manifest_revision'],'Pinned older source membership can advance to the authenticated target revision');
        check(!str_contains(json_encode($proof),$token)&&!str_contains(json_encode($proof),'synthetic-grant-secret')&&!str_contains(json_encode($proof),$body),'Handoff proof contains no token, member grant or attachment bytes');
        $calls=app(\App\Services\Dashboard\DesktopDashboardWorkspaceImport::class)->calls;$reads=DB::$replayReads;$hashes=$GLOBALS['workspace_handoff_hashes'];
        DB::$fenced=true;DB::$queries=[];
        $payload=$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$proof,$token,$source,$sourceIdentity);
        check($payload['replay']['rows']===5001&&$payload['sequence']===5100,'Private cutover returns the exact full group replay sequence/count');
        check($calls===app(\App\Services\Dashboard\DesktopDashboardWorkspaceImport::class)->calls&&$reads===DB::$replayReads&&$hashes===$GLOBALS['workspace_handoff_hashes'],'Cutover performs no full member/source/history verification or attachment hashing');
        check(!array_filter(DB::$queries,fn($sql)=>str_contains($sql,'archived_commands')||str_contains($sql,Commands::TABLE)),'Cutover uses no replay or sidecar SQL');
        $reordered=$checked;$reorderedProof=$proof;$reorderedExpected=$expected;$reorderedIdentity=$sourceIdentity;
        ksort($reordered['workspace']);ksort($reordered['delta_source_identity']['workspace']);ksort($reordered['delta_source_binding']);
        ksort($reorderedProof['payload']['workspace']);ksort($reorderedExpected);ksort($reorderedIdentity['workspace']);
        check($handoff->verifyWorkspaceHandoff($reordered,$reorderedExpected,$seal,$reorderedProof,$token,$source,$reorderedIdentity)['sequence']===5100,
            'Canonical workspace objects tolerate JSON key ordering without relaxing field/type bindings');
        $before=count(DB::$queries);$bad=$proof;$bad['signature']=str_repeat('0',64);
        refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$bad,$token,$source,$sourceIdentity),'Forged private signature');
        check(count(DB::$queries)===$before,'Signature refusal precedes SQL');
        refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$proof,str_repeat('0',64),$source,$sourceIdentity),'Wrong private token');
        $bad=$proof;$bad['format']=1;refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$bad,$token,$source,$sourceIdentity),'V1 proof format');
        $bad=$proof;$bad['kind']='desktop-preverified-handoff';refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$bad,$token,$source,$sourceIdentity),'V1 proof domain');
        $bad=$checked;$bad['rows']++;refused(fn()=>$handoff->verifyWorkspaceHandoff($bad,$expected,$seal,$proof,$token,$source,$sourceIdentity),'Changed receipt');
        $bad=$expected;$bad['token_hash']=str_repeat('0',64);refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$bad,$seal,$proof,$token,$source,$sourceIdentity),'Changed native expected enrollment');
        $bad=$checked;$bad['delta_source_binding']['roster_sha256']=str_repeat('0',64);refused(fn()=>$handoff->verifyWorkspaceHandoff($bad,$expected,$seal,$proof,$token,$source,$sourceIdentity),'Changed captured source membership');
        $bad=$sourceIdentity;$bad['workspace']['manifest_revision']=2;refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$proof,$token,$source,$bad),'Changed captured source identity');
        DB::$tables['desktop_dashboard_commands']=[(object)['device_id'=>$workspace]];refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$proof,$token,$source,$sourceIdentity),'Candidate accepted a command');DB::$tables['desktop_dashboard_commands']=[];
        $saved=DB::$identity;DB::$identity['workspace']['roster_sha256']=str_repeat('0',64);refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$proof,$token,$source,$sourceIdentity),'Candidate fixed authority changed');DB::$identity=$saved;
        $saved=DB::$tables['desktop_dashboard_local_state'][0]->coverage;DB::$tables['desktop_dashboard_local_state'][0]->coverage='{}';refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$proof,$token,$source,$sourceIdentity),'Candidate coverage changed');DB::$tables['desktop_dashboard_local_state'][0]->coverage=$saved;
        $saved=DB::$tables['desktop_dashboard_media_manifest'][0]->sha256;DB::$tables['desktop_dashboard_media_manifest'][0]->sha256=str_repeat('0',64);refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$proof,$token,$source,$sourceIdentity),'Candidate media manifest changed');DB::$tables['desktop_dashboard_media_manifest'][0]->sha256=$saved;
        $bad=$seal;$bad['files'][0]['identity']['ino']++;refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$bad,$proof,$token,$source,$sourceIdentity),'Changed media identity proof');
        file_put_contents($root.'/app/public/products/photo.png','changed attachment bytes');refused(fn()=>$handoff->verifyWorkspaceHandoff($checked,$expected,$seal,$proof,$token,$source,$sourceIdentity),'Attachment changed after preverification');file_put_contents($root.'/app/public/products/photo.png',$body);
        DB::$fenced=false;
        $bad=$receipt;$bad['delta_source_binding']['revision']=3;refused(fn()=>$handoff->captureWorkspace($bad,$expected,$token),'Source cannot roll target authority backward');
        $bad=$receipt;$bad['delta_source_binding']['root_fingerprint']=str_repeat('0',64);refused(fn()=>$handoff->captureWorkspace($bad,$expected,$token),'Different physical source enrollment');
        $saved=DB::$sourceRecord->local_mac;DB::$sourceRecord->local_mac=str_repeat('0',64);refused(fn()=>$handoff->captureWorkspace($receipt,$expected,$token),'Unauthenticated source summary');DB::$sourceRecord->local_mac=$saved;
        $bad=$receipt;$bad['delta_replay']['sha256']=str_repeat('0',64);refused(fn()=>$handoff->captureWorkspace($bad,$expected,$token),'Wrong group replay digest');
        $first=array_key_first($sidecars);$saved=DB::$tables[Commands::TABLE][$first];DB::$tables[Commands::TABLE][$first]=clone $saved;DB::$tables[Commands::TABLE][$first]->proof_mac=str_repeat('0',64);
        refused(fn()=>$handoff->captureWorkspace($receipt,$expected,$token),'Forged actor history sidecar');DB::$tables[Commands::TABLE][$first]=$saved;
        $foreign=clone reset($entries);$foreign->device_id='99999999-9999-4999-8999-999999999999';DB::$tables['desktop_dashboard_archived_commands']['foreign']=$foreign;
        refused(fn()=>$handoff->captureWorkspace($receipt,$expected,$token),'Foreign workspace replay row');unset(DB::$tables['desktop_dashboard_archived_commands']['foreign']);
        $bad=$receipt;$bad['archive_handoff']=[];refused(fn()=>$handoff->captureWorkspace($bad,$expected,$token),'Public receipt cannot shadow private proof');
        $GLOBALS['workspace_handoff_enabled']=false;refused(fn()=>$handoff->captureWorkspace($receipt,$expected,$token),'Disabled workspace feature');$GLOBALS['workspace_handoff_enabled']=true;
        DB::$host='remote';refused(fn()=>$handoff->captureWorkspace($receipt,$expected,$token),'Remote database boundary');DB::$host='127.0.0.1';
        $saved=DB::$database;DB::$database='fasakhansta_dashboard';refused(fn()=>$handoff->captureWorkspace($receipt,$expected,$token),'Active database is never a candidate');DB::$database=$saved;
        putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=false');refused(fn()=>$handoff->captureWorkspace($receipt,$expected,$token),'Missing private workspace boundary');putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');
        check(hash_file('sha256',__DIR__.'/../app/Services/Dashboard/DesktopDashboardHandoff.php')==='a3521c2e73a790c91a83514b20f1df136bd1abdb1859179af9f7b11a1978169c','Original v1 handoff remains byte unchanged');
        echo 'PASS '.$GLOBALS['workspace_handoff_checks'].' private workspace handoff checks'.PHP_EOL;
    }finally{
        unlink($root.'/app/public/products/photo.png');rmdir($root.'/app/public/products');rmdir($root.'/app/public');rmdir($root.'/app');rmdir($root);
    }
}
