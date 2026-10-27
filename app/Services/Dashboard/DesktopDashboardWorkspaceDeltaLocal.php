<?php
namespace App\Services\Dashboard;

/** Private protocol-two capture/reconstruction. Never selects a signed-in actor's subset of the physical queue. */
final class DesktopDashboardWorkspaceDeltaLocal extends DesktopDashboardDeltaLocal
{
    public const RECEIPT_TABLE='desktop_dashboard_workspace_delta_capture';
    public const STAGE_TABLE='desktop_dashboard_workspace_delta_stage';
    public const REPLAY_TABLE='desktop_dashboard_workspace_delta_replay';
    public const EVIDENCE_TABLE='desktop_dashboard_workspace_delta_authority';
    private const AUTHORITY_TABLE='desktop_dashboard_workspace_command_authorities';
    private const MAX_ROWS=500000;
    private const SIDE_FIELDS=['workspace_id','command_id','actor_id','root_fingerprint','manifest_revision',
        'grant_hash','request_hash','proof_json','proof_sha256','proof_mac','accepted_database','created_at'];
    private array $sourceEvidence=[];

    public function __construct(?object $pdo=null,?array $runtime=null,?callable $importer=null)
    {
        parent::__construct($pdo,$runtime,$importer??fn(array $snapshot,array $expected)=>app(DesktopDashboardWorkspaceImport::class)->importWorkspace($snapshot,$expected));
    }

    public function run(array $input): array
    {
        $this->need(($this->runtime['local']??null)===true&&($this->runtime['host']??null)==='127.0.0.1'
            &&$this->database($this->runtime['database']??null,true)&&$this->uuid($this->runtime['device_id']??null)
            &&$this->source($this->runtime['source']??null)&&!$this->pdo->inTransaction(),'RUNTIME_BINDING');
        return match($input['action']??null){
            'capture'=>$this->capture($input),'reconstruct'=>$this->reconstruct($input),
            'discard-capture'=>$this->discardWorkspaceCapture($input),'discard-stage'=>$this->discardWorkspaceStage($input),
            default=>$this->fail('ACTION'),
        };
    }

    protected function capture(array $input): array
    {
        $this->need($this->database($input['source_database']??null)&&$input['source_database']!==$this->runtime['database']
            &&($input['device_id']??null)===$this->runtime['device_id']&&is_int($input['actor_id']??null)&&$input['actor_id']>0
            &&$this->uuid($input['snapshot_id']??null)&&$this->hash($input['schema_hash']??null)
            &&$this->source($input['source']??null)&&$this->hash($input['capture_token']??null),'CAPTURE_BINDING');
        $this->expected($input['expected_binding']??null);
        $source=$input['source_database'];$target=$this->runtime['database'];$this->pinned($source);
        $this->need($this->tables($target)===[],'TARGET_NOT_EMPTY');
        $identity=$this->activeIdentity($source,$input);
        $names=array_values(array_intersect(DesktopDashboardSchema::TABLES,$this->tables($source)));
        $this->need($names!==[]&&in_array('users',$names,true),'CAPTURE_TABLES');$ddl=[];
        foreach($names as $name){$ddl[$name]=$this->ddl($source,$name);$this->safeDdl($name,$ddl[$name],$names);}
        $authorityDdl=$this->ddl($source,self::AUTHORITY_TABLE);$this->safeDdl(self::AUTHORITY_TABLE,$authorityDdl,[self::AUTHORITY_TABLE]);
        $plan=['format'=>2,'kind'=>'desktop-workspace-delta-capture-plan','capture_database'=>$target,'source_database'=>$source,
            'capture_id'=>bin2hex(random_bytes(32)),'identity'=>$identity,'execution_source'=>$this->runtime['source'],'planned_tables'=>$names];
        $this->pdo->exec('CREATE TABLE `'.$target.'`.`'.self::RECEIPT_TABLE.'` (`capture_id` char(64) NOT NULL PRIMARY KEY, `token_hash` char(64) NOT NULL, `receipt` longtext NOT NULL, `planned_final_database` varchar(64) NULL, `final_plan` longtext NULL) ENGINE=InnoDB');
        $this->execute('INSERT INTO `'.$target.'`.`'.self::RECEIPT_TABLE.'` (`capture_id`,`token_hash`,`receipt`,`planned_final_database`,`final_plan`) VALUES (?,?,?,?,?)',
            [$plan['capture_id'],hash('sha256',$input['capture_token']),self::json($plan),null,null]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try{
            $this->pdo->exec('CREATE TABLE `'.$target.'`.`'.self::REPLAY_TABLE.'` (`device_id` char(36) NOT NULL, `command_id` char(36) NOT NULL, `actor_id` bigint unsigned NOT NULL, `route_name` varchar(150) NOT NULL, `request_hash` char(64) NOT NULL, `source_database` varchar(64) NOT NULL, PRIMARY KEY (`device_id`,`command_id`)) ENGINE=InnoDB');
            $this->pdo->exec('CREATE TABLE `'.$target.'`.`'.self::EVIDENCE_TABLE.'` (`capture_id` char(64) NOT NULL PRIMARY KEY, `evidence` longtext NOT NULL) ENGINE=InnoDB');
            $this->pdo->exec($this->qualifiedDdl($target,self::AUTHORITY_TABLE,$authorityDdl));
            foreach($names as $name)$this->pdo->exec($this->qualifiedDdl($target,$name,$ddl[$name]));
        }finally{$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try{
            $identity=$this->activeIdentity($source,$input);$evidence=$this->sourceEvidence;
            $manifest=$this->verifyEvidence($evidence,$source,$input['expected_binding']);$codec=new DesktopDashboardWorkspaceDeltaCodec($manifest);
            $commands=$this->one('SELECT COALESCE(MAX(`sequence`),0) AS `sequence`, COALESCE(SUM(`status`<>?),0) AS `unsettled`, COALESCE(SUM(`device_id`<>?),0) AS `foreign_rows` FROM `'.$source.'`.`desktop_dashboard_commands`',
                ['acknowledged',$input['device_id']]);
            $this->need($commands!==null&&(int)$commands['unsettled']===0&&(int)$commands['foreign_rows']===0,'CAPTURE_UNSETTLED');
            $sequence=$this->integer($commands['sequence']??null,'CAPTURE_SEQUENCE');
            $index=['format'=>2,'kind'=>'desktop-workspace-delta-index','device_id'=>$input['device_id'],'actor_id'=>$input['actor_id'],
                'workspace'=>$codec->context(),'source'=>$this->runtime['source'],'tables'=>[]];$total=0;
            foreach($names as $name){
                $this->need(DesktopDashboardWorkspaceDeltaCodec::schemaHash($this->ddl($source,$name))===DesktopDashboardWorkspaceDeltaCodec::schemaHash($ddl[$name]),'CAPTURE_SCHEMA_CHANGED');
                $rows=$this->rows($source,$name);$total+=count($rows);$this->need($total<=self::MAX_ROWS,'ROW_LIMIT');
                $index['tables'][$name]=$codec->index($name,$rows,$ddl[$name],$this->projection($name,$identity['coverage']));
                $this->insertRows($target,$name,$rows);unset($rows);
            }
            $this->execute('INSERT INTO `'.$target.'`.`'.self::EVIDENCE_TABLE.'` (`capture_id`,`evidence`) VALUES (?,?)',[$plan['capture_id'],self::json($evidence)]);
            $this->captureWorkspaceReplays($source,$target,$input['device_id'],$codec->context()['root_fingerprint'],$sequence);
            $replay=$this->workspaceReplayProof($target,self::REPLAY_TABLE,$input['device_id'],$codec->context()['root_fingerprint'],$source,$sequence);
            $receipt=['format'=>2,'kind'=>'desktop-workspace-delta-capture','capture_database'=>$target,'source_database'=>$source,
                'capture_id'=>$plan['capture_id'],'sequence'=>$sequence,'identity'=>$identity,'index'=>$index,'replay'=>$replay];
            $this->execute('UPDATE `'.$target.'`.`'.self::RECEIPT_TABLE.'` SET `receipt`=? WHERE `capture_id`=? AND `token_hash`=?',
                [self::json($receipt),$receipt['capture_id'],hash('sha256',$input['capture_token'])]);
            $this->pdo->commit();return $receipt;
        }catch(\Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
        finally{$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
    }

    protected function activeIdentity(string $database,array $input): array
    {
        $state=$this->one('SELECT `device_id`,`actor_id`,`snapshot_id`,`schema_hash`,`branches`,`coverage`,`state` FROM `'.$database.'`.`desktop_dashboard_local_state` WHERE `device_id`=?',[$input['device_id']]);
        $source=$this->one('SELECT `device_id`,`snapshot_id`,`sha256`,`source` FROM `'.$database.'`.`desktop_dashboard_source_manifest` WHERE `device_id`=?',[$input['device_id']]);
        $this->need($state!==null&&$source!==null&&$state['device_id']===$input['device_id']&&(int)$state['actor_id']===$input['actor_id']
            &&$state['state']==='ready'&&$state['snapshot_id']===$input['snapshot_id']&&$state['schema_hash']===$input['schema_hash']
            &&$source['device_id']===$input['device_id']&&$source['snapshot_id']===$input['snapshot_id']
            &&is_string($source['source'])&&$this->hash($source['sha256'])&&hash_equals($source['sha256'],hash('sha256',$source['source']))
            &&$this->sameSource($this->decode($source['source']),$input['source']),'SOURCE_IDENTITY');
        $branches=$this->decode($state['branches']);$coverage=$this->decode($state['coverage']);
        $this->need(is_array($branches)&&is_array($coverage)&&($coverage['workspace_protocol']??null)===2,'SOURCE_IDENTITY');
        $record=$this->one('SELECT `record_json`,`manifest_json`,`local_mac` FROM `'.$database.'`.`desktop_dashboard_workspace_local_authority` WHERE `workspace_id`=?',[$input['device_id']]);
        $members=$this->execute('SELECT `actor_id`,`manifest_revision`,`member_json`,`local_mac` FROM `'.$database.'`.`desktop_dashboard_workspace_local_members` WHERE `workspace_id`=?',[$input['device_id']])->fetchAll(\PDO::FETCH_ASSOC);
        $this->need($record!==null&&is_array($members),'SOURCE_AUTHORITY');
        $evidence=['record'=>$record,'members'=>$members,'state'=>$state,'database'=>$database,'expected_binding'=>$input['expected_binding']];
        $manifest=$this->verifyEvidence($evidence,$database,$input['expected_binding']);$this->sourceEvidence=$evidence;
        $installed=$this->decode($record['record_json']);
        $this->need($this->sameSource($installed['snapshot']['source']??null,$input['source']),'SOURCE_IDENTITY');
        $workspace=$this->workspaceReceipt($manifest);$this->need($manifest['workspace_id']===$input['device_id'],'SOURCE_AUTHORITY');
        $union=[];foreach($manifest['members'] as $member)$union=array_merge($union,$member['branches']);$union=array_values(array_unique($union));sort($union,SORT_STRING);
        $this->need($branches===$union&&($coverage['root_fingerprint']??null)===$manifest['root_fingerprint']
            &&($coverage['manifest_revision']??null)===$manifest['revision']&&($coverage['roster_sha256']??null)===$manifest['roster_sha256'],'SOURCE_AUTHORITY');
        $media=$this->one('SELECT `device_id`,`snapshot_id`,`sha256`,`files` FROM `'.$database.'`.`desktop_dashboard_media_manifest` WHERE `device_id`=?',[$input['device_id']]);
        $this->need($media!==null&&$media['device_id']===$input['device_id']&&$media['snapshot_id']===$input['snapshot_id']
            &&is_string($media['files']??null)&&$this->hash($media['sha256']??null)&&hash_equals($media['sha256'],hash('sha256',$media['files'])),'SOURCE_MEDIA');
        $files=$this->decode($media['files']);$this->need(is_array($files),'SOURCE_MEDIA');
        return ['device_id'=>$input['device_id'],'actor_id'=>$input['actor_id'],'snapshot_id'=>$input['snapshot_id'],
            'schema_hash'=>$input['schema_hash'],'branches'=>$branches,'coverage'=>$coverage,'source'=>$input['source'],'media'=>$files,'workspace'=>$workspace];
    }

    protected function reconstruct(array $input): array
    {
        $capture=$input['capture']??null;$delta=$input['delta']??null;$target=$this->runtime['database'];
        $this->expected($input['expected_binding']??null);
        $this->need(is_array($capture)&&($capture['format']??null)===2&&($capture['kind']??null)==='desktop-workspace-delta-capture'
            &&$this->database($input['capture_database']??null,true)&&$input['capture_database']!==$target
            &&($capture['capture_database']??null)===$input['capture_database']&&$this->database($capture['source_database']??null)
            &&$capture['source_database']!==$target&&$capture['source_database']!==$input['capture_database']
            &&$this->hash($capture['capture_id']??null)&&$this->hash($input['capture_token']??null)
            &&is_int($capture['sequence']??null)&&$capture['sequence']>=0,'RECONSTRUCT_BINDING');
        $this->need($this->tables($target)===[],'TARGET_NOT_EMPTY');$identity=$capture['identity']??null;$index=$capture['index']??null;
        $this->need(is_array($identity)&&($identity['device_id']??null)===$this->runtime['device_id']&&is_int($identity['actor_id']??null)&&$identity['actor_id']>0
            &&is_array($index)&&($index['format']??null)===2&&($index['kind']??null)==='desktop-workspace-delta-index'
            &&($index['device_id']??null)===$identity['device_id']&&($index['actor_id']??null)===$identity['actor_id']
            &&$this->sameSource($index['source']??null,$this->runtime['source'])&&is_array($index['tables']??null)&&$index['tables']!==[],'RECONSTRUCT_IDENTITY');
        $stored=$this->one('SELECT `token_hash`,`receipt`,`planned_final_database`,`final_plan` FROM `'.$input['capture_database'].'`.`'.self::RECEIPT_TABLE.'` WHERE `capture_id`=?',[$capture['capture_id']]);
        $this->need($stored!==null&&is_string($stored['token_hash']??null)&&hash_equals($stored['token_hash'],hash('sha256',$input['capture_token']))
            &&$this->decode($stored['receipt']??null)===$capture,'CAPTURE_RECEIPT');
        $evidenceRow=$this->one('SELECT `evidence` FROM `'.$input['capture_database'].'`.`'.self::EVIDENCE_TABLE.'` WHERE `capture_id`=?',[$capture['capture_id']]);
        $this->need($evidenceRow!==null,'CAPTURE_AUTHORITY');$evidence=$this->decode($evidenceRow['evidence']);
        $sourceExpected=$evidence['expected_binding']??null;$this->expected($sourceExpected);
        $oldManifest=$this->verifyEvidence($evidence,$capture['source_database'],$sourceExpected);$oldCodec=new DesktopDashboardWorkspaceDeltaCodec($oldManifest);
        $this->need(($identity['workspace']??null)===$this->workspaceReceipt($oldManifest)&&($index['workspace']??null)===$oldCodec->context(),'CAPTURE_AUTHORITY');
        $sourceState=$evidence['state'];$installed=$this->decode($evidence['record']['record_json']);
        $this->need(($identity['snapshot_id']??null)===$sourceState['snapshot_id']&&($identity['schema_hash']??null)===$sourceState['schema_hash']
            &&($identity['device_id']??null)===$sourceState['device_id']&&($identity['actor_id']??null)===(int)$sourceState['actor_id']
            &&($identity['branches']??null)===$this->decode($sourceState['branches'])&&($identity['coverage']??null)===$this->decode($sourceState['coverage'])
            &&$this->sameSource($identity['source']??null,$installed['snapshot']['source']??null),'CAPTURE_AUTHORITY');
        $replay=$this->workspaceReplayProof($input['capture_database'],self::REPLAY_TABLE,$identity['device_id'],$oldManifest['root_fingerprint'],$capture['source_database'],$capture['sequence']);
        $this->need(($capture['replay']??null)===$replay,'CAPTURE_REPLAY');
        $this->need(is_array($delta)&&($delta['format']??null)===2&&($delta['kind']??null)==='workspace-data-delta'
            &&($delta['device_id']??null)===$identity['device_id']&&($delta['actor_id']??null)===$identity['actor_id']
            &&$this->sameSource($delta['source']??null,$this->runtime['source'])&&is_array($delta['tables']??null)
            &&$delta['tables']!==[]&&count($delta['tables'])<=200&&is_array($delta['workspace_manifest']??null),'DELTA_BINDING');
        $manifest=$delta['workspace_manifest'];$codec=new DesktopDashboardWorkspaceDeltaCodec($manifest);$expected=$input['expected_binding'];
        foreach($codec->context() as $key=>$value)$this->need(($expected[$key]??null)===$value,'DELTA_AUTHORITY');
        $this->need(hash_equals($expected['manifest_sha256'],hash('sha256',DesktopDashboardWorkspaceAuthority::canonical($manifest)))
            &&($delta['workspace_enrollment']['token_hash']??null)===$expected['token_hash']
            &&$expected['workspace_id']===$sourceExpected['workspace_id']&&$expected['root_fingerprint']===$sourceExpected['root_fingerprint']
            &&$expected['token_hash']===$sourceExpected['token_hash']&&$expected['revision']>=$sourceExpected['revision'],'DELTA_AUTHORITY');
        $previous=$codec->context()===$oldCodec->context()?null:$oldCodec;
        $emptyPlan=$this->finalPlan($capture,$identity,$target,[],'empty',$identity['snapshot_id']);$this->saveGroupPlan($input,$capture,$stored,$emptyPlan);
        $verified=[];
        foreach($index['tables'] as $name=>$before){
            $this->need(in_array($name,DesktopDashboardSchema::TABLES,true),'CAPTURE_TABLES');$oldCodec->validateIndex($name,$before);
            $baseline=$this->rows($input['capture_database'],$name);$ddl=$this->ddl($input['capture_database'],$name);
            $actual=$oldCodec->index($name,$baseline,$ddl,$this->projection($name,$identity['coverage']));
            $this->need($actual===$before,'CAPTURE_DATA_CHANGED');$verified[$name]=$actual;unset($baseline);
        }
        $snapshot=$delta;$snapshot['kind']='initial-workspace-data';$snapshot['tables']=[];$total=0;
        foreach($delta['tables'] as $name=>$part){
            $this->need(in_array($name,DesktopDashboardSchema::TABLES,true)&&is_array($part)&&is_string($part['ddl']??null)&&is_array($part['delta']??null),'DELTA_TABLE');
            $this->safeDdl($name,$part['ddl'],array_keys($delta['tables']));$projection=$this->projection($name,$delta['coverage']??[]);
            $baseline=isset($verified[$name])?$this->rows($input['capture_database'],$name):[];
            if(isset($verified[$name])){
                $oldDdl=$this->ddl($input['capture_database'],$name);$oldProjection=$this->projection($name,$identity['coverage']);
                $this->need($oldCodec->index($name,$baseline,$oldDdl,$oldProjection)===$verified[$name],'CAPTURE_DATA_CHANGED');$before=$verified[$name];
                $this->need($oldProjection===$projection||$previous!==null||($part['delta']['replace']??false)===true,'PROJECTION_TRANSITION');
            }else $before=($previous??$codec)->index($name,[],$part['ddl'],$projection);
            if(($part['delta']['replace']??false)===true){
                $rows=$codec->applyReplacement($name,$part['delta']['add']??[],$part['ddl'],$part['delta'],$before,$projection,$previous);
            }else{
                $this->need($previous===null,'DELTA_REPLACEMENT_REQUIRED');
                $rows=$codec->apply($name,$baseline,$part['ddl'],$part['delta'],$projection);
            }
            unset($baseline);$total+=count($rows);$this->need($total<=self::MAX_ROWS,'ROW_LIMIT');
            $snapshot['tables'][$name]=['ddl'=>$part['ddl'],'rows'=>$rows,'sha256'=>hash('sha256',self::json($rows))];
        }
        $this->need($this->uuid($delta['snapshot_id']??null),'FINAL_PLAN');
        $plan=$this->finalPlan($capture,$identity,$target,array_keys($snapshot['tables']),'import',$delta['snapshot_id']);$this->saveGroupPlan($input,$capture,$stored,$plan);
        $receipt=($this->importer)($snapshot,$expected);
        $this->need(is_array($receipt)&&($receipt['format']??null)===2&&($receipt['device_id']??null)===$identity['device_id']
            &&($receipt['actor_id']??null)===$identity['actor_id']&&($receipt['snapshot_id']??null)===$delta['snapshot_id']
            &&($receipt['workspace']??null)===$this->workspaceReceipt($manifest),'IMPORT_RECEIPT');
        foreach(['desktop_dashboard_archived_commands',self::AUTHORITY_TABLE] as $table){$count=$this->one('SELECT COUNT(*) AS `total` FROM `'.$target.'`.`'.$table.'`');$this->need($count!==null&&(int)$count['total']===0,'TARGET_REPLAY_NOT_EMPTY');}
        $this->eachWorkspaceReplay($input['capture_database'],self::REPLAY_TABLE,$identity['device_id'],$oldManifest['root_fingerprint'],function(array $rows,array $authorities)use($target){
            $this->insertRows($target,'desktop_dashboard_archived_commands',$rows);$this->insertRows($target,self::AUTHORITY_TABLE,$authorities);
        });
        $this->need($this->workspaceReplayProof($target,'desktop_dashboard_archived_commands',$identity['device_id'],$oldManifest['root_fingerprint'],$capture['source_database'],$capture['sequence'])===$replay,'TARGET_REPLAY_CHANGED');
        $receipt['delta_replay']=$replay;$receipt['delta_source_identity']=['snapshot_id'=>$identity['snapshot_id'],'schema_hash'=>$identity['schema_hash'],'workspace'=>$identity['workspace']];
        $receipt['delta_source_binding']=$sourceExpected;
        $proof=['format'=>2,'kind'=>'desktop-workspace-delta-stage','stage_id'=>bin2hex(random_bytes(32)),'stage_database'=>$target,
            'source_database'=>$capture['source_database'],'capture_database'=>$input['capture_database'],'capture_id'=>$capture['capture_id'],
            'device_id'=>$identity['device_id'],'actor_id'=>$identity['actor_id'],'snapshot_id'=>$receipt['snapshot_id'],
            'workspace'=>$receipt['workspace'],'receipt_sha256'=>hash('sha256',self::json($receipt))];
        $this->pdo->exec('CREATE TABLE `'.$target.'`.`'.self::STAGE_TABLE.'` (`stage_id` char(64) NOT NULL PRIMARY KEY, `token_hash` char(64) NOT NULL, `proof` longtext NOT NULL) ENGINE=InnoDB');
        $this->execute('INSERT INTO `'.$target.'`.`'.self::STAGE_TABLE.'` (`stage_id`,`token_hash`,`proof`) VALUES (?,?,?)',[$proof['stage_id'],hash('sha256',$input['capture_token']),self::json($proof)]);
        return $receipt+['delta_stage'=>$proof];
    }

    /** Shared actor-aware digest semantics for DeltaLocal and the preverified handoff. */
    public static function replayEntry(array $row,array $authority,string $workspace,string $root,?DesktopDashboardWorkspaceCommandAuthorities $verifier=null): array
    {
        $id=$row['actor_id']??null;
        abort_unless(($row['device_id']??null)===$workspace&&is_string($row['command_id']??null)
            &&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$row['command_id'])
            &&(is_int($id)||(is_string($id)&&preg_match('/^[1-9][0-9]{0,15}$/D',$id)))&&(int)$id>0&&(int)$id<=9007199254740991
            &&is_string($row['route_name']??null)&&strlen($row['route_name'])>0&&strlen($row['route_name'])<=150
            &&preg_match('/^[\x21-\x7e]+$/D',$row['route_name'])&&is_string($row['request_hash']??null)&&preg_match('/^[a-f0-9]{64}$/D',$row['request_hash'])
            &&is_string($row['source_database']??null)&&preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',$row['source_database']),409);
        $proof=($verifier??app(DesktopDashboardWorkspaceCommandAuthorities::class))->assertHistoryRow($authority,$workspace,$root);
        abort_unless(($authority['command_id']??null)===$row['command_id']&&(string)($authority['actor_id']??'')===(string)$id
            &&($authority['request_hash']??null)===$row['request_hash']&&$proof['command_sha256']===$row['request_hash'],409);
        $normalized=[];
        foreach(self::SIDE_FIELDS as $field){abort_unless(array_key_exists($field,$authority),409);$normalized[$field]=in_array($field,['actor_id','manifest_revision'],true)?(int)$authority[$field]:$authority[$field];}
        return ['replay'=>['device_id'=>$workspace,'command_id'=>$row['command_id'],'actor_id'=>(int)$id,'route_name'=>$row['route_name'],
            'request_hash'=>$row['request_hash'],'source_database'=>$row['source_database']],'authority'=>$normalized];
    }

    private function eachWorkspaceReplay(string $database,string $table,string $workspace,string $root,callable $consume): void
    {
        $foreign=$this->one('SELECT COUNT(*) AS `total` FROM `'.$database.'`.`'.$table.'` WHERE `device_id`<>?',[$workspace]);
        $this->need($foreign!==null&&(int)$foreign['total']===0,'REPLAY_ROW');$last='';
        do{
            $rows=$this->execute('SELECT `device_id`,`command_id`,`actor_id`,`route_name`,`request_hash`,`source_database` FROM `'.$database.'`.`'.$table.'` WHERE `device_id`=? AND `command_id`>? ORDER BY `command_id` LIMIT 1000',[$workspace,$last])->fetchAll(\PDO::FETCH_ASSOC);
            if($rows){
                $authorities=$this->authorityPage($database,$workspace,array_column($rows,'command_id'));$normalized=[];$original=[];
                foreach($rows as $row){$entry=self::replayEntry($row,$authorities[$row['command_id']]??[],$workspace,$root);$this->need(strcmp($entry['replay']['command_id'],$last)>0,'REPLAY_ORDER');$last=$entry['replay']['command_id'];$normalized[]=$entry['replay'];$original[]=$authorities[$last];}
                $consume($normalized,$original);
            }
        }while(count($rows)===1000);
    }
    private function authorityPage(string $database,string $workspace,array $ids): array
    {
        if(!$ids)return [];$rows=$this->execute('SELECT * FROM `'.$database.'`.`'.self::AUTHORITY_TABLE.'` WHERE `workspace_id`=? AND `command_id` IN ('.implode(',',array_fill(0,count($ids),'?')).')',[$workspace,...$ids])->fetchAll(\PDO::FETCH_ASSOC);$map=[];$wanted=array_fill_keys($ids,true);
        foreach($rows as $row){$id=$row['command_id']??null;$this->need(is_string($id)&&!isset($map[$id])&&isset($wanted[$id]),'REPLAY_AUTHORITY');$map[$id]=$row;}
        $this->need(count($map)===count($ids),'REPLAY_AUTHORITY');return $map;
    }
    private function captureWorkspaceReplays(string $source,string $target,string $workspace,string $root,int $sequence): void
    {
        $copy=function(array $rows,array $authorities)use($target){$this->insertRows($target,self::REPLAY_TABLE,$rows);$this->insertRows($target,self::AUTHORITY_TABLE,$authorities);};
        if(in_array('desktop_dashboard_archived_commands',$this->tables($source),true))$this->eachWorkspaceReplay($source,'desktop_dashboard_archived_commands',$workspace,$root,$copy);
        $last=0;
        do{
            $rows=$this->execute('SELECT `sequence`,`device_id`,`command_id`,`actor_id`,`route_name`,`request_hash` FROM `'.$source.'`.`desktop_dashboard_commands` WHERE `sequence`>? AND `sequence`<=? ORDER BY `sequence` LIMIT 1000',[$last,$sequence])->fetchAll(\PDO::FETCH_ASSOC);
            if($rows){$authorities=$this->authorityPage($source,$workspace,array_column($rows,'command_id'));$normalized=[];$original=[];
                foreach($rows as $row){$current=$this->integer($row['sequence']??null,'REPLAY_SEQUENCE');$this->need($current>$last&&$current<=$sequence,'REPLAY_SEQUENCE');$last=$current;$row['source_database']=$source;
                    $entry=self::replayEntry($row,$authorities[$row['command_id']]??[],$workspace,$root);$normalized[]=$entry['replay'];$original[]=$authorities[$row['command_id']];}
                $copy($normalized,$original);
            }
        }while(count($rows)===1000);
    }
    private function workspaceReplayProof(string $database,string $table,string $workspace,string $root,string $source,int $sequence): array
    {
        $hash=hash_init('sha256');hash_update($hash,"desktop-workspace-replays:2\n");$count=0;
        $this->eachWorkspaceReplay($database,$table,$workspace,$root,function(array $rows,array $authorities)use($workspace,$root,$hash,&$count){
            foreach($rows as $i=>$row){hash_update($hash,DesktopDashboardWorkspaceAuthority::canonical(self::replayEntry($row,$authorities[$i],$workspace,$root))."\n");$count++;}
        });
        return ['format'=>2,'workspace_id'=>$workspace,'root_fingerprint'=>$root,'source_database'=>$source,'sequence'=>$sequence,'rows'=>$count,'sha256'=>hash_final($hash)];
    }
    private function verifyEvidence(array $evidence,string $database,array $expected): array
    {
        $this->need(($evidence['database']??null)===$database&&($evidence['expected_binding']??null)===$expected
            &&is_array($evidence['record']??null)&&is_array($evidence['members']??null)&&is_array($evidence['state']??null),'CAPTURE_AUTHORITY');
        return app(DesktopDashboardWorkspaceLocalAuthority::class)->verifyCapture($evidence['record'],$evidence['members'],$evidence['state'],$database,$expected);
    }
    private function workspaceReceipt(array $manifest): array
    {
        return ['workspace_id'=>$manifest['workspace_id'],'root_fingerprint'=>$manifest['root_fingerprint'],'manifest_revision'=>$manifest['revision'],
            'roster_sha256'=>$manifest['roster_sha256'],'manifest_sha256'=>hash('sha256',DesktopDashboardWorkspaceAuthority::canonical($manifest))];
    }
    private function expected($value): void
    {
        $this->need(is_array($value)&&count($value)===6&&array_diff(array_keys($value),['workspace_id','root_fingerprint','revision','roster_sha256','manifest_sha256','token_hash'])===[]
            &&($value['workspace_id']??null)===$this->runtime['device_id']&&is_int($value['revision']??null)&&$value['revision']>0&&$value['revision']<=9007199254740991,'EXPECTED_BINDING');
        foreach(['root_fingerprint','roster_sha256','manifest_sha256','token_hash'] as $field)$this->need($this->hash($value[$field]??null),'EXPECTED_BINDING');
    }
    private function pinned(string $source): void {$pinned=getenv('DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE');$this->need($pinned===false||$pinned===''||$pinned===$source,'CAPTURE_SOURCE_DATABASE');}
    private function finalPlan(array $capture,array $identity,string $target,array $tables,string $mode,string $snapshot): array
    {
        return ['format'=>2,'kind'=>'desktop-workspace-delta-final-plan','mode'=>$mode,'stage_database'=>$target,'source_database'=>$capture['source_database'],
            'capture_database'=>$capture['capture_database'],'capture_id'=>$capture['capture_id'],'device_id'=>$identity['device_id'],'actor_id'=>$identity['actor_id'],
            'snapshot_id'=>$snapshot,'source'=>$this->runtime['source'],'tables'=>$tables,'workspace'=>$identity['workspace']];
    }
    private function saveGroupPlan(array $input,array $capture,array $stored,array $plan): void
    {
        // Original helper uses self::RECEIPT_TABLE; this protocol owns a distinct marker.
        $target=$this->runtime['database'];$this->need(($stored['planned_final_database']??null)===null||$stored['planned_final_database']===$target,'FINAL_PLAN');
        $this->need($this->tables($target)===[],'TARGET_NOT_EMPTY');
        $this->execute('UPDATE `'.$input['capture_database'].'`.`'.self::RECEIPT_TABLE.'` SET `planned_final_database`=?,`final_plan`=? WHERE `capture_id`=? AND `token_hash`=? AND (`planned_final_database` IS NULL OR `planned_final_database`=?)',[$target,self::json($plan),$capture['capture_id'],hash('sha256',$input['capture_token']),$target]);
        $row=$this->one('SELECT `planned_final_database`,`final_plan` FROM `'.$input['capture_database'].'`.`'.self::RECEIPT_TABLE.'` WHERE `capture_id`=?',[$capture['capture_id']]);
        $this->need($row!==null&&$row['planned_final_database']===$target&&$this->decode($row['final_plan'])===$plan,'FINAL_PLAN');
    }

    private function captureMarker(string $database,string $token,string $source): array
    {
        $this->need($this->database($database,true)&&$this->database($source)&&$database!==$source&&$this->hash($token),'DISCARD_BINDING');
        $row=$this->one('SELECT `token_hash`,`receipt`,`planned_final_database`,`final_plan` FROM `'.$database.'`.`'.self::RECEIPT_TABLE.'` WHERE `token_hash`=?',[hash('sha256',$token)]);
        $this->need($row!==null&&hash_equals($row['token_hash'],hash('sha256',$token)),'DISCARD_RECEIPT');$capture=$this->decode($row['receipt']);
        $this->need(($capture['format']??null)===2&&in_array($capture['kind']??null,['desktop-workspace-delta-capture-plan','desktop-workspace-delta-capture'],true)
            &&($capture['capture_database']??null)===$database&&($capture['source_database']??null)===$source&&$this->hash($capture['capture_id']??null)
            &&($capture['identity']['device_id']??null)===$this->runtime['device_id']
            &&$this->sameSource($capture['execution_source']??$capture['index']['source']??null,$this->runtime['source']),'DISCARD_RECEIPT');
        $names=$capture['planned_tables']??array_keys($capture['index']['tables']??[]);$this->need(is_array($names)&&$names!==[],'DISCARD_TABLES');
        foreach($names as $name)$this->need(in_array($name,DesktopDashboardSchema::TABLES,true),'DISCARD_TABLES');
        $allowed=array_merge($names,[self::RECEIPT_TABLE,self::REPLAY_TABLE,self::EVIDENCE_TABLE,self::AUTHORITY_TABLE]);$actual=$this->tables($database);
        $this->need(in_array(self::RECEIPT_TABLE,$actual,true)&&array_diff($actual,$allowed)===[],'DISCARD_TABLES');return [$capture,$row];
    }
    private function cleanupWorkspaceBinding(array $input): void
    {
        $target=$this->runtime['database'];$this->need($this->database($input['source_database']??null)&&$input['source_database']!==$target
            &&$this->database($input['active_database']??null)&&$input['active_database']!==$target&&$this->hash($input['capture_token']??null),'DISCARD_BINDING');$this->pinned($input['active_database']);
    }
    private function discardWorkspaceCapture(array $input): array
    {
        $target=$this->runtime['database'];$this->cleanupWorkspaceBinding($input);$this->need(($input['capture_database']??null)===$target,'DISCARD_BINDING');
        $result=['format'=>2,'kind'=>'desktop-workspace-delta-capture-discarded','database'=>$target,'discarded'=>true];
        if($this->tables($target)===[]){$this->pdo->exec('DROP DATABASE `'.$target.'`');return $result+['empty'=>true];}
        [$capture,$row]=$this->captureMarker($target,$input['capture_token'],$input['source_database']);if(isset($input['capture']))$this->need($input['capture']===$capture,'DISCARD_RECEIPT');
        if(($planned=$row['planned_final_database']??null)!==null){$this->need($this->database($planned,true)&&$planned!==$target&&$planned!==$input['source_database'],'FINAL_PLAN');$exists=$this->one('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?',[$planned]);$this->need($exists===null||$planned===$input['active_database'],'DISCARD_FINAL_STILL_PRESENT');}
        $this->pdo->exec('DROP DATABASE `'.$target.'`');return $result+['capture_id'=>$capture['capture_id']];
    }
    private function discardWorkspaceStage(array $input): array
    {
        $target=$this->runtime['database'];$this->cleanupWorkspaceBinding($input);$this->need(($input['stage_database']??null)===$target,'DISCARD_STAGE_BINDING');
        $captureDb=$input['capture_database']??$input['receipt']['delta_stage']['capture_database']??null;
        $this->need($this->database($captureDb,true)&&$captureDb!==$target,'DISCARD_STAGE_BINDING');
        [$capture,$marker]=$this->captureMarker($captureDb,$input['capture_token'],$input['source_database']);
        $plan=($marker['final_plan']??null)===null?null:$this->decode($marker['final_plan']);$actual=$this->tables($target);
        if($plan===null){$this->need($actual===[],'DISCARD_FINAL_PLAN');}
        else{
            $this->need(($plan['format']??null)===2&&($plan['kind']??null)==='desktop-workspace-delta-final-plan'
                &&($marker['planned_final_database']??null)===$target&&($plan['stage_database']??null)===$target
                &&($plan['capture_database']??null)===$captureDb&&($plan['capture_id']??null)===$capture['capture_id']
                &&($plan['source_database']??null)===$input['source_database']&&($plan['device_id']??null)===$this->runtime['device_id']
                &&$this->sameSource($plan['source']??null,$this->runtime['source'])&&is_array($plan['tables']??null),'DISCARD_FINAL_PLAN');
            $allowed=$this->stageTables($plan['tables']);$this->need(array_diff($actual,$allowed)===[],'DISCARD_TABLES');
            foreach(['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_refreshes'] as $table)if(in_array($table,$actual,true)){$count=$this->one('SELECT COUNT(*) AS `total` FROM `'.$target.'`.`'.$table.'`');$this->need($count!==null&&(int)$count['total']===0,'DISCARD_STAGE_ACCEPTED_WORK');}
            if(in_array('desktop_dashboard_local_state',$actual,true)){$states=$this->rows($target,'desktop_dashboard_local_state');$this->need(count($states)<=1,'DISCARD_STAGE_ACCEPTED_WORK');if($states)$this->need($states[0]['device_id']===$plan['device_id']&&(int)$states[0]['actor_id']===$plan['actor_id']&&$states[0]['snapshot_id']===$plan['snapshot_id']&&$states[0]['state']==='ready','DISCARD_STAGE_ACCEPTED_WORK');}
        }
        if(isset($input['receipt'])){
            $receipt=$input['receipt'];$proof=$receipt['delta_stage']??null;$this->need(is_array($proof)&&($proof['format']??null)===2&&($proof['kind']??null)==='desktop-workspace-delta-stage'
                &&($proof['stage_database']??null)===$target&&($proof['capture_id']??null)===$capture['capture_id']&&$this->hash($proof['stage_id']??null),'DISCARD_STAGE_RECEIPT');
            $original=$receipt;unset($original['delta_stage'],$original['php_profiles'],$original['verified']);
            $stored=$this->one('SELECT `token_hash`,`proof` FROM `'.$target.'`.`'.self::STAGE_TABLE.'` WHERE `stage_id`=?',[$proof['stage_id']]);
            $this->need($stored!==null&&hash_equals($stored['token_hash'],hash('sha256',$input['capture_token']))&&$this->decode($stored['proof'])===$proof
                &&hash_equals($proof['receipt_sha256'],hash('sha256',self::json($original))),'DISCARD_STAGE_RECEIPT');
        }
        $sourceTables=$this->tables($input['source_database']);$this->need(in_array('desktop_dashboard_local_state',$sourceTables,true)&&in_array('desktop_dashboard_commands',$sourceTables,true),'DISCARD_ORIGINAL_SOURCE');
        $this->pdo->exec('DROP DATABASE `'.$target.'`');return ['format'=>2,'kind'=>'desktop-workspace-delta-stage-discarded','database'=>$target,'discarded'=>true,'capture_id'=>$capture['capture_id']];
    }
    private function stageTables(array $tables): array
    {
        foreach($tables as $name)$this->need(in_array($name,DesktopDashboardSchema::TABLES,true),'DISCARD_TABLES');
        return array_merge($tables,['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_devices','desktop_dashboard_local_state',
            'desktop_dashboard_refreshes','desktop_dashboard_archived_commands','desktop_dashboard_media_manifest','desktop_dashboard_source_manifest',
            'desktop_dashboard_workspace_local_authority','desktop_dashboard_workspace_local_members','desktop_dashboard_workspace_local_authority_history',self::AUTHORITY_TABLE,self::STAGE_TABLE]);
    }
    protected function fail(string $reason): never {throw new \RuntimeException('DESKTOP_WORKSPACE_DELTA_'.$reason);}
}
