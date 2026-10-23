<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

/** CLI-only private staging. Active business rows and journals are read without write fences. */
class DesktopDashboardDeltaLocal
{
    public const RECEIPT_TABLE='desktop_dashboard_delta_capture';
    public const STAGE_TABLE='desktop_dashboard_delta_stage';
    public const REPLAY_TABLE='desktop_dashboard_delta_replay';
    private const MAX_ROWS=500000;
    private object $pdo;
    private array $runtime;
    private \Closure $importer;

    /** Injectable I/O is used by the isolated database protocol regression. */
    public function __construct(?object $pdo=null,?array $runtime=null,?callable $importer=null)
    {
        $this->pdo=$pdo??DB::connection()->getPdo();
        $this->runtime=$runtime??['local'=>config('desktop_dashboard.local')===true,
            'host'=>DB::connection()->getConfig('host'),'database'=>DB::connection()->getDatabaseName(),
            'device_id'=>(string)config('desktop_dashboard.device_id'),
            'source'=>app(DesktopDashboardSource::class)->fingerprint()];
        $this->importer=$importer?\Closure::fromCallable($importer):fn(array $snapshot)=>app(DesktopDashboardImport::class)->import($snapshot);
    }

    public function run(array $input): array
    {
        $this->need(($this->runtime['local']??null)===true&&($this->runtime['host']??null)==='127.0.0.1'
            &&$this->database($this->runtime['database']??null,true)&&$this->uuid($this->runtime['device_id']??null)
            &&$this->source($this->runtime['source']??null)&&!$this->pdo->inTransaction(),'RUNTIME_BINDING');
        return match($input['action']??null){
            'capture'=>$this->capture($input),
            'reconstruct'=>$this->reconstruct($input),
            'discard-capture'=>$this->discard($input),
            'discard-stage'=>$this->discardStage($input),
            default=>$this->fail('ACTION'),
        };
    }

    private function capture(array $input): array
    {
        $this->need($this->database($input['source_database']??null)
            &&$input['source_database']!==$this->runtime['database']
            &&($input['device_id']??null)===$this->runtime['device_id']
            &&is_int($input['actor_id']??null)&&$input['actor_id']>0
            &&$this->uuid($input['snapshot_id']??null)&&$this->hash($input['schema_hash']??null)
            &&$this->source($input['source']??null)&&$this->hash($input['capture_token']??null),'CAPTURE_BINDING');
        $source=$input['source_database'];$target=$this->runtime['database'];
        $pinned=getenv('DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE');
        $this->need($pinned===false||$pinned===''||$pinned===$source,'CAPTURE_SOURCE_DATABASE');
        $this->need($this->tables($target)===[],'TARGET_NOT_EMPTY');
        // This preliminary check is repeated inside the repeatable read snapshot.
        // All target DDL is checked before the first private staging write.
        $identity=$this->activeIdentity($source,$input);
        $names=array_values(array_intersect(DesktopDashboardSchema::TABLES,$this->tables($source)));
        $this->need(count($names)>0&&in_array('users',$names,true),'CAPTURE_TABLES');
        $ddl=[];
        foreach($names as $name){$ddl[$name]=$this->ddl($source,$name);$this->safeDdl($name,$ddl[$name],$names);}
        $plan=['format'=>1,'kind'=>'dashboard-delta-capture-plan','capture_database'=>$target,
            'source_database'=>$source,'capture_id'=>bin2hex(random_bytes(32)),'identity'=>$identity,
            'execution_source'=>$this->runtime['source'],'planned_tables'=>$names];
        // Commit ownership before any business clone DDL. A lost/failed capture
        // reply remains safely removable by its native-only random capability.
        $this->pdo->exec('CREATE TABLE `'.$target.'`.`'.self::RECEIPT_TABLE.'` (`capture_id` char(64) NOT NULL PRIMARY KEY, `token_hash` char(64) NOT NULL, `receipt` longtext NOT NULL, `planned_final_database` varchar(64) NULL, `final_plan` longtext NULL) ENGINE=InnoDB');
        $this->execute('INSERT INTO `'.$target.'`.`'.self::RECEIPT_TABLE.'` (`capture_id`,`token_hash`,`receipt`,`planned_final_database`,`final_plan`) VALUES (?,?,?,?,?)',
            [$plan['capture_id'],hash('sha256',$input['capture_token']),self::json($plan),null,null]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try{
            $this->pdo->exec('CREATE TABLE `'.$target.'`.`'.self::REPLAY_TABLE.'` (`device_id` char(36) NOT NULL, `command_id` char(36) NOT NULL, `actor_id` bigint unsigned NOT NULL, `route_name` varchar(150) NOT NULL, `request_hash` char(64) NOT NULL, `source_database` varchar(64) NOT NULL, PRIMARY KEY (`device_id`,`command_id`)) ENGINE=InnoDB');
            foreach($names as $name)$this->pdo->exec($this->qualifiedDdl($target,$name,$ddl[$name]));
        }finally{$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try{
            $identity=$this->activeIdentity($source,$input);
            $commands=$this->one('SELECT COALESCE(MAX(`sequence`),0) AS `sequence`, COALESCE(SUM(`status`<>?),0) AS `unsettled`, COALESCE(SUM(`device_id`<>? OR `actor_id`<>?),0) AS `foreign_rows` FROM `'.$source.'`.`desktop_dashboard_commands`',
                ['acknowledged',$input['device_id'],$input['actor_id']]);
            $this->need($commands!==null&&(int)$commands['unsettled']===0&&(int)$commands['foreign_rows']===0,'CAPTURE_UNSETTLED');
            $sequence=$this->integer($commands['sequence']??null,'CAPTURE_SEQUENCE');
            $index=['format'=>1,'kind'=>'dashboard-delta-index','device_id'=>$input['device_id'],
                'actor_id'=>$input['actor_id'],'source'=>$this->runtime['source'],'tables'=>[]];
            $total=0;
            foreach($names as $name){
                $this->need(DesktopDashboardDeltaCodec::schemaHash($this->ddl($source,$name))===DesktopDashboardDeltaCodec::schemaHash($ddl[$name]),'CAPTURE_SCHEMA_CHANGED');
                // Buffered SELECT + INSERT VALUES never acquires an INSERT SELECT
                // source lock. InnoDB keeps the original business snapshot stable.
                $rows=$this->rows($source,$name);$total+=count($rows);$this->need($total<=self::MAX_ROWS,'ROW_LIMIT');
                $index['tables'][$name]=DesktopDashboardDeltaCodec::index($name,$rows,$input['actor_id'],$ddl[$name],$this->projection($name,$identity['coverage']));
                $this->insertRows($target,$name,$rows);unset($rows);
            }
            $this->captureReplays($source,$target,$input['device_id'],$input['actor_id'],$sequence);
            $replay=$this->replayProof($target,self::REPLAY_TABLE,$input['device_id'],$input['actor_id'],$source,$sequence);
            $receipt=['format'=>1,'kind'=>'dashboard-delta-capture','capture_database'=>$target,
                'source_database'=>$source,'capture_id'=>$plan['capture_id'],'sequence'=>$sequence,
                'identity'=>$identity,'index'=>$index,'replay'=>$replay];
            $this->execute('UPDATE `'.$target.'`.`'.self::RECEIPT_TABLE.'` SET `receipt`=? WHERE `capture_id`=? AND `token_hash`=?',
                [self::json($receipt),$receipt['capture_id'],hash('sha256',$input['capture_token'])]);
            $this->pdo->commit();return $receipt;
        }catch(\Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
        finally{$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
    }

    private function reconstruct(array $input): array
    {
        $capture=$input['capture']??null;$delta=$input['delta']??null;$target=$this->runtime['database'];
        $this->need(is_array($capture)&&($capture['format']??null)===1&&($capture['kind']??null)==='dashboard-delta-capture'
            &&$this->database($input['capture_database']??null,true)&&$input['capture_database']!==$target
            &&($capture['capture_database']??null)===$input['capture_database']
            &&$this->database($capture['source_database']??null)&&$capture['source_database']!==$input['capture_database']
            &&$capture['source_database']!==$target&&$this->hash($capture['capture_id']??null)
            &&$this->hash($input['capture_token']??null)&&is_int($capture['sequence']??null)&&$capture['sequence']>=0,'RECONSTRUCT_BINDING');
        $identity=$capture['identity']??null;$index=$capture['index']??null;
        $this->need(is_array($identity)&&($identity['device_id']??null)===$this->runtime['device_id']
            &&is_int($identity['actor_id']??null)&&$identity['actor_id']>0&&$this->source($identity['source']??null)
            &&is_array($index)&&$this->sameSource($index['source']??null,$this->runtime['source'])
            &&($index['device_id']??null)===$identity['device_id']&&($index['actor_id']??null)===$identity['actor_id']
            &&is_array($index['tables']??null)&&count($index['tables'])>0,'RECONSTRUCT_IDENTITY');
        $this->need($this->tables($target)===[],'TARGET_NOT_EMPTY');
        $stored=$this->one('SELECT `token_hash`,`receipt`,`planned_final_database`,`final_plan` FROM `'.$input['capture_database'].'`.`'.self::RECEIPT_TABLE.'` WHERE `capture_id`=?',[$capture['capture_id']]);
        $this->need($stored!==null&&is_string($stored['token_hash']??null)
            &&hash_equals($stored['token_hash'],hash('sha256',$input['capture_token']))
            &&$this->decode($stored['receipt']??null)===$capture,'CAPTURE_RECEIPT');
        $replay=$this->replayProof($input['capture_database'],self::REPLAY_TABLE,$identity['device_id'],$identity['actor_id'],$capture['source_database'],$capture['sequence']);
        $this->need(($capture['replay']??null)===$replay,'CAPTURE_REPLAY');
        $emptyPlan=['format'=>1,'kind'=>'dashboard-delta-final-plan','mode'=>'empty','stage_database'=>$target,
            'source_database'=>$capture['source_database'],'capture_database'=>$input['capture_database'],
            'capture_id'=>$capture['capture_id'],'device_id'=>$identity['device_id'],'actor_id'=>$identity['actor_id'],
            'snapshot_id'=>$identity['snapshot_id'],'source'=>$this->runtime['source'],'tables'=>[],'catalog_scopes'=>false];
        $this->saveFinalPlan($input,$capture,$stored,$emptyPlan);
        $this->need(is_array($delta)&&($delta['format']??null)===1&&($delta['kind']??null)==='dashboard-data-delta'
            &&($delta['device_id']??null)===$identity['device_id']&&($delta['actor_id']??null)===$identity['actor_id']
            &&$this->sameSource($delta['source']??null,$this->runtime['source'])&&is_array($delta['tables']??null)
            &&count($delta['tables'])>0&&count($delta['tables'])<=200,'DELTA_BINDING');
        // Validate every old captured table, including ones the new authorized
        // export no longer selects. No replacement can hide a corrupt baseline.
        $verified=[];
        foreach($index['tables'] as $name=>$before){
            $this->need(in_array($name,DesktopDashboardSchema::TABLES,true),'CAPTURE_TABLES');
            $baseline=$this->rows($input['capture_database'],$name);$captureDdl=$this->ddl($input['capture_database'],$name);
            $actual=DesktopDashboardDeltaCodec::index($name,$baseline,$identity['actor_id'],$captureDdl,$this->projection($name,$identity['coverage']??[]));
            $this->need($actual===$before,'CAPTURE_DATA_CHANGED');$verified[$name]=$actual;unset($baseline);
        }
        $snapshot=$delta;$snapshot['kind']='initial-dashboard-data';$snapshot['tables']=[];$total=0;
        foreach($delta['tables'] as $name=>$part){
            $this->need(in_array($name,DesktopDashboardSchema::TABLES,true)&&is_array($part)
                &&is_string($part['ddl']??null)&&is_array($part['delta']??null),'DELTA_TABLE');
            $this->safeDdl($name,$part['ddl'],array_keys($delta['tables']));
            $projection=$this->projection($name,$delta['coverage']??[]);
            $baseline=isset($verified[$name])?$this->rows($input['capture_database'],$name):[];
            $oldProjection=$this->projection($name,$identity['coverage']??[]);$projectionChanged=isset($verified[$name])&&$oldProjection!==$projection;
            if(isset($verified[$name]))$this->need(DesktopDashboardDeltaCodec::index($name,$baseline,$identity['actor_id'],$this->ddl($input['capture_database'],$name),$oldProjection)===$verified[$name],'CAPTURE_DATA_CHANGED');
            if(($part['delta']['replace']??false)===true||$projectionChanged){
                $this->need(isset($verified[$name]),'REPLACEMENT_BASELINE');
                $rows=DesktopDashboardDeltaCodec::applyReplacement($name,$part['delta']['add']??[],$identity['actor_id'],$part['ddl'],$part['delta'],$verified[$name],$projection,$projectionChanged);
            }else{
                $this->need(!isset($verified[$name])||DesktopDashboardDeltaCodec::schemaHash($part['ddl'])===$verified[$name]['schema_sha256'],'DELTA_SCHEMA');
                $rows=DesktopDashboardDeltaCodec::apply($name,$baseline,$identity['actor_id'],$part['ddl'],$part['delta'],$projection);
            }
            unset($baseline);$total+=count($rows);$this->need($total<=self::MAX_ROWS,'ROW_LIMIT');
            $snapshot['tables'][$name]=['ddl'=>$part['ddl'],'rows'=>$rows,'sha256'=>hash('sha256',self::json($rows))];
        }
        $finalPlan=['format'=>1,'kind'=>'dashboard-delta-final-plan','mode'=>'import','stage_database'=>$target,
            'source_database'=>$capture['source_database'],'capture_database'=>$input['capture_database'],
            'capture_id'=>$capture['capture_id'],'device_id'=>$identity['device_id'],'actor_id'=>$identity['actor_id'],
            'snapshot_id'=>$delta['snapshot_id']??null,'source'=>$this->runtime['source'],
            'tables'=>array_keys($snapshot['tables']),'catalog_scopes'=>($delta['coverage']['catalog_scopes']??null)===1];
        $this->need($this->uuid($finalPlan['snapshot_id']),'FINAL_PLAN');
        $this->saveFinalPlan($input,$capture,$stored,$finalPlan);
        // Every capture/delta hash has passed before this importer may create
        // anything in the empty final database. The active database is untouched.
        $receipt=($this->importer)($snapshot);
        $this->need(is_array($receipt)&&($receipt['device_id']??null)===$identity['device_id']
            &&($receipt['actor_id']??null)===$identity['actor_id']&&($receipt['snapshot_id']??null)===($delta['snapshot_id']??null),'IMPORT_RECEIPT');
        $emptyArchive=$this->one('SELECT COUNT(*) AS `total` FROM `'.$target.'`.`desktop_dashboard_archived_commands`');
        $this->need($emptyArchive!==null&&(int)$emptyArchive['total']===0,'TARGET_REPLAY_NOT_EMPTY');
        $this->eachReplay($input['capture_database'],self::REPLAY_TABLE,$identity['device_id'],$identity['actor_id'],function(array $rows)use($target){$this->insertRows($target,'desktop_dashboard_archived_commands',$rows);});
        $copied=$this->replayProof($target,'desktop_dashboard_archived_commands',$identity['device_id'],$identity['actor_id'],$capture['source_database'],$capture['sequence']);
        $this->need($copied===$replay,'TARGET_REPLAY_CHANGED');$receipt['delta_replay']=$replay;
        $proof=['format'=>1,'kind'=>'dashboard-delta-stage','stage_id'=>bin2hex(random_bytes(32)),
            'stage_database'=>$target,'source_database'=>$capture['source_database'],
            'capture_database'=>$input['capture_database'],'capture_id'=>$capture['capture_id'],
            'device_id'=>$identity['device_id'],'actor_id'=>$identity['actor_id'],'snapshot_id'=>$receipt['snapshot_id'],
            'receipt_sha256'=>hash('sha256',self::json($receipt))];
        $this->pdo->exec('CREATE TABLE `'.$target.'`.`'.self::STAGE_TABLE.'` (`stage_id` char(64) NOT NULL PRIMARY KEY, `token_hash` char(64) NOT NULL, `proof` longtext NOT NULL) ENGINE=InnoDB');
        $this->execute('INSERT INTO `'.$target.'`.`'.self::STAGE_TABLE.'` (`stage_id`,`token_hash`,`proof`) VALUES (?,?,?)',
            [$proof['stage_id'],hash('sha256',$input['capture_token']),self::json($proof)]);
        return $receipt+['delta_stage'=>$proof];
    }

    private function saveFinalPlan(array $input,array $capture,array $stored,array $plan): void
    {
        $target=$this->runtime['database'];
        $this->need(($stored['planned_final_database']??null)===null||$stored['planned_final_database']===$target,'FINAL_PLAN');
        $this->need($this->tables($target)===[],'TARGET_NOT_EMPTY');
        $this->execute('UPDATE `'.$input['capture_database'].'`.`'.self::RECEIPT_TABLE.'` SET `planned_final_database`=?,`final_plan`=? WHERE `capture_id`=? AND `token_hash`=? AND (`planned_final_database` IS NULL OR `planned_final_database`=?)',
            [$target,self::json($plan),$capture['capture_id'],hash('sha256',$input['capture_token']),$target]);
        $planned=$this->one('SELECT `planned_final_database`,`final_plan` FROM `'.$input['capture_database'].'`.`'.self::RECEIPT_TABLE.'` WHERE `capture_id`=?',[$capture['capture_id']]);
        $this->need($planned!==null&&$planned['planned_final_database']===$target&&$this->decode($planned['final_plan'])===$plan,'FINAL_PLAN');
    }

    private function marker(string $database,string $token,string $source): array
    {
        $this->need($this->database($database,true)&&$this->database($source)&&$source!==$database&&$this->hash($token),'DISCARD_BINDING');
        $row=$this->one('SELECT `token_hash`,`receipt`,`planned_final_database`,`final_plan` FROM `'.$database.'`.`'.self::RECEIPT_TABLE.'` WHERE `token_hash`=?',[hash('sha256',$token)]);
        $this->need($row!==null&&is_string($row['token_hash']??null)&&hash_equals($row['token_hash'],hash('sha256',$token)),'DISCARD_RECEIPT');
        $capture=$this->decode($row['receipt']??null);
        $this->need(is_array($capture)&&($capture['format']??null)===1
            &&in_array($capture['kind']??null,['dashboard-delta-capture-plan','dashboard-delta-capture'],true)
            &&($capture['capture_database']??null)===$database&&($capture['source_database']??null)===$source
            &&$this->hash($capture['capture_id']??null)&&($capture['identity']['device_id']??null)===$this->runtime['device_id']
            &&$this->sameSource($capture['execution_source']??$capture['index']['source']??null,$this->runtime['source']),'DISCARD_RECEIPT');
        $expected=$capture['kind']==='dashboard-delta-capture-plan'?($capture['planned_tables']??null):array_keys($capture['index']['tables']??[]);
        $this->need(is_array($expected)&&count($expected)>0,'DISCARD_TABLES');
        foreach($expected as $table)$this->need(in_array($table,DesktopDashboardSchema::TABLES,true),'DISCARD_TABLES');
        $expected[]=self::RECEIPT_TABLE;$expected[]=self::REPLAY_TABLE;$actual=$this->tables($database);
        $this->need(in_array(self::RECEIPT_TABLE,$actual,true)&&array_diff($actual,$expected)===[],'DISCARD_TABLES');
        return [$capture,$row];
    }
    private function cleanupBinding(array $input,string $target): void
    {
        $this->need($this->database($input['source_database']??null)&&$input['source_database']!==$target
            &&$this->database($input['active_database']??null)&&$input['active_database']!==$target
            &&$this->hash($input['capture_token']??null),'DISCARD_BINDING');
        $pinned=getenv('DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE');
        $this->need($pinned===false||$pinned===''||$pinned===$input['active_database'],'DISCARD_SOURCE_DATABASE');
    }
    private function discard(array $input): array
    {
        $database=$this->runtime['database'];$this->need(($input['capture_database']??null)===$database,'DISCARD_BINDING');
        $this->cleanupBinding($input,$database);
        if($this->tables($database)===[]){
            // Native calls this only for the fresh cryptographic name recorded
            // durably before creation. A markerless nonempty DB is never owned.
            $this->pdo->exec('DROP DATABASE `'.$database.'`');
            return ['format'=>1,'kind'=>'dashboard-delta-capture-discarded','database'=>$database,'discarded'=>true,'empty'=>true];
        }
        [$capture,$row]=$this->marker($database,$input['capture_token'],$input['source_database']);
        if(isset($input['capture']))$this->need($capture===$input['capture'],'DISCARD_RECEIPT');
        $planned=$row['planned_final_database']??null;
        if($planned!==null){
            $this->need($this->database($planned,true)&&$planned!==$database&&$planned!==$input['source_database'],'FINAL_PLAN');
            // Keep the external ownership plan if an inactive partial candidate
            // still exists and its cleanup has not been proved safe.
            $schemas=$this->pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$schemas->execute([$planned]);
            $exists=$schemas->fetch(\PDO::FETCH_ASSOC)!==false;
            $this->need(!$exists||$planned===$input['active_database'],'DISCARD_FINAL_STILL_PRESENT');
        }
        $this->pdo->exec('DROP DATABASE `'.$database.'`');
        return ['format'=>1,'kind'=>'dashboard-delta-capture-discarded','capture_id'=>$capture['capture_id'],'database'=>$database,'discarded'=>true];
    }

    private function discardStage(array $input): array
    {
        $receipt=$input['receipt']??null;$proof=is_array($receipt)?($receipt['delta_stage']??null):null;
        $database=$this->runtime['database'];
        if($receipt===null)return $this->discardPlannedStage($input);
        $this->need(is_array($receipt)&&is_array($proof)&&($proof['format']??null)===1&&($proof['kind']??null)==='dashboard-delta-stage'
            &&($proof['stage_database']??null)===$database&&($input['stage_database']??null)===$database
            &&$this->database($input['source_database']??null)&&$input['source_database']!==$database
            &&($proof['source_database']??null)===$input['source_database']
            &&$this->database($input['active_database']??null)&&$input['active_database']!==$database
            &&$this->database($proof['capture_database']??null,true)&&$proof['capture_database']!==$database
            &&$this->hash($proof['capture_id']??null)&&$this->hash($proof['stage_id']??null)
            &&$this->hash($proof['receipt_sha256']??null)&&$this->hash($input['capture_token']??null)
            &&($proof['device_id']??null)===$this->runtime['device_id']
            &&($receipt['device_id']??null)===$proof['device_id']&&($receipt['actor_id']??null)===($proof['actor_id']??null)
            &&($receipt['snapshot_id']??null)===($proof['snapshot_id']??null)
            &&$this->sameSource($receipt['source']??null,$this->runtime['source']),'DISCARD_STAGE_BINDING');
        $pinned=getenv('DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE');
        $this->need($pinned===false||$pinned===''||$pinned===$input['active_database'],'DISCARD_SOURCE_DATABASE');
        $original=$receipt;unset($original['delta_stage'],$original['php_profiles'],$original['verified']);
        $this->need(hash_equals($proof['receipt_sha256'],hash('sha256',self::json($original))),'DISCARD_STAGE_RECEIPT');
        $stored=$this->one('SELECT `token_hash`,`proof` FROM `'.$database.'`.`'.self::STAGE_TABLE.'` WHERE `stage_id`=?',[$proof['stage_id']]);
        $this->need($stored!==null&&is_string($stored['token_hash']??null)
            &&hash_equals($stored['token_hash'],hash('sha256',$input['capture_token']))
            &&$this->decode($stored['proof']??null)===$proof,'DISCARD_STAGE_RECEIPT');
        $sourceTables=$this->tables($input['source_database']);
        $this->need(in_array('desktop_dashboard_local_state',$sourceTables,true)
            &&in_array('desktop_dashboard_commands',$sourceTables,true),'DISCARD_ORIGINAL_SOURCE');
        $expected=array_keys($receipt['table_rows']??[]);
        $this->need(count($expected)>0,'DISCARD_TABLES');
        foreach($expected as $table)$this->need(in_array($table,DesktopDashboardSchema::TABLES,true),'DISCARD_TABLES');
        $expected=array_merge($expected,['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_devices',
            'desktop_dashboard_local_state','desktop_dashboard_refreshes','desktop_dashboard_archived_commands',
            'desktop_dashboard_media_manifest','desktop_dashboard_source_manifest',self::STAGE_TABLE]);
        if(($receipt['coverage']['catalog_scopes']??null)===1)$expected[]='desktop_dashboard_created_scopes';
        $actual=$this->tables($database);sort($expected,SORT_STRING);sort($actual,SORT_STRING);
        $this->need($expected===$actual,'DISCARD_TABLES');
        foreach(['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_refreshes'] as $table){
            $count=$this->one('SELECT COUNT(*) AS `total` FROM `'.$database.'`.`'.$table.'`');
            $this->need($count!==null&&(int)$count['total']===0,'DISCARD_STAGE_ACCEPTED_WORK');
        }
        $state=$this->one('SELECT `device_id`,`actor_id`,`snapshot_id`,`state` FROM `'.$database.'`.`desktop_dashboard_local_state` WHERE `device_id`=?',[$proof['device_id']]);
        $this->need($state!==null&&$state['device_id']===$proof['device_id']&&(int)$state['actor_id']===$proof['actor_id']
            &&$state['snapshot_id']===$proof['snapshot_id']&&$state['state']==='ready','DISCARD_STAGE_ACCEPTED_WORK');
        $this->pdo->exec('DROP DATABASE `'.$database.'`');
        return ['format'=>1,'kind'=>'dashboard-delta-stage-discarded','stage_id'=>$proof['stage_id'],'database'=>$database,'discarded'=>true];
    }

    private function discardPlannedStage(array $input): array
    {
        $database=$this->runtime['database'];$this->need(($input['stage_database']??null)===$database
            &&$this->database($input['capture_database']??null,true)&&$input['capture_database']!==$database,'DISCARD_STAGE_BINDING');
        $this->cleanupBinding($input,$database);
        [$capture,$row]=$this->marker($input['capture_database'],$input['capture_token'],$input['source_database']);
        if(($row['final_plan']??null)===null){
            $sourceTables=$this->tables($input['source_database']);
            $this->need(in_array('desktop_dashboard_local_state',$sourceTables,true)&&in_array('desktop_dashboard_commands',$sourceTables,true),'DISCARD_ORIGINAL_SOURCE');
            $this->need($this->tables($database)===[],'DISCARD_FINAL_PLAN');
            // Recovery of a lost CLI before its first plan write uses the
            // exact native-owned name and capture capability, and emptiness.
            $this->pdo->exec('DROP DATABASE `'.$database.'`');
            return ['format'=>1,'kind'=>'dashboard-delta-stage-discarded','capture_id'=>$capture['capture_id'],'database'=>$database,'discarded'=>true,'empty'=>true];
        }
        $plan=$this->decode($row['final_plan']);
        $this->need(is_array($plan)&&($plan['format']??null)===1&&($plan['kind']??null)==='dashboard-delta-final-plan'
            &&($row['planned_final_database']??null)===$database&&($plan['stage_database']??null)===$database
            &&($plan['capture_database']??null)===$input['capture_database']&&($plan['capture_id']??null)===$capture['capture_id']
            &&($plan['source_database']??null)===$input['source_database']&&$this->sameSource($plan['source']??null,$this->runtime['source'])
            &&($plan['device_id']??null)===$this->runtime['device_id']&&is_int($plan['actor_id']??null)&&$plan['actor_id']>0
            &&$this->uuid($plan['snapshot_id']??null)&&is_array($plan['tables']??null),'DISCARD_FINAL_PLAN');
        $sourceTables=$this->tables($input['source_database']);
        $this->need(in_array('desktop_dashboard_local_state',$sourceTables,true)&&in_array('desktop_dashboard_commands',$sourceTables,true),'DISCARD_ORIGINAL_SOURCE');
        if(($plan['mode']??null)==='empty'){
            $this->need($this->tables($database)===[],'DISCARD_TABLES');
            $this->pdo->exec('DROP DATABASE `'.$database.'`');
            return ['format'=>1,'kind'=>'dashboard-delta-stage-discarded','capture_id'=>$capture['capture_id'],'database'=>$database,'discarded'=>true];
        }
        $this->need(($plan['mode']??null)==='import','DISCARD_FINAL_PLAN');
        $expected=$plan['tables'];foreach($expected as $table)$this->need(in_array($table,DesktopDashboardSchema::TABLES,true),'DISCARD_TABLES');
        $expected=array_merge($expected,['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_devices',
            'desktop_dashboard_local_state','desktop_dashboard_refreshes','desktop_dashboard_archived_commands',
            'desktop_dashboard_media_manifest','desktop_dashboard_source_manifest',self::STAGE_TABLE]);
        if(($plan['catalog_scopes']??null)===true)$expected[]='desktop_dashboard_created_scopes';
        $actual=$this->tables($database);$this->need(array_diff($actual,$expected)===[],'DISCARD_TABLES');
        foreach(['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_refreshes'] as $table)if(in_array($table,$actual,true)){
            $count=$this->one('SELECT COUNT(*) AS `total` FROM `'.$database.'`.`'.$table.'`');
            $this->need($count!==null&&(int)$count['total']===0,'DISCARD_STAGE_ACCEPTED_WORK');
        }
        if(in_array('desktop_dashboard_local_state',$actual,true)){
            $states=$this->pdo->query('SELECT * FROM `'.$database.'`.`desktop_dashboard_local_state`')->fetchAll(\PDO::FETCH_ASSOC);
            $this->need(count($states)<=1,'DISCARD_STAGE_ACCEPTED_WORK');
            if($states)$this->need($states[0]['device_id']===$plan['device_id']&&(int)$states[0]['actor_id']===$plan['actor_id']
                &&$states[0]['snapshot_id']===$plan['snapshot_id']&&$states[0]['state']==='ready','DISCARD_STAGE_ACCEPTED_WORK');
        }
        $this->pdo->exec('DROP DATABASE `'.$database.'`');
        return ['format'=>1,'kind'=>'dashboard-delta-stage-discarded','capture_id'=>$capture['capture_id'],'database'=>$database,'discarded'=>true];
    }

    private function activeIdentity(string $database,array $input): array
    {
        $state=$this->one('SELECT `device_id`,`actor_id`,`snapshot_id`,`schema_hash`,`branches`,`coverage`,`state` FROM `'.$database.'`.`desktop_dashboard_local_state` WHERE `device_id`=?',[$input['device_id']]);
        $source=$this->one('SELECT `device_id`,`snapshot_id`,`sha256`,`source` FROM `'.$database.'`.`desktop_dashboard_source_manifest` WHERE `device_id`=?',[$input['device_id']]);
        $this->need($state!==null&&$source!==null&&$state['device_id']===$input['device_id']
            &&(int)$state['actor_id']===$input['actor_id']&&$state['state']==='ready'
            &&$state['snapshot_id']===$input['snapshot_id']&&$state['schema_hash']===$input['schema_hash']
            &&$source['device_id']===$input['device_id']&&$source['snapshot_id']===$input['snapshot_id']
            &&is_string($source['sha256'])&&is_string($source['source'])&&hash_equals($source['sha256'],hash('sha256',$source['source']))
            &&$this->sameSource($this->decode($source['source']),$input['source']),'SOURCE_IDENTITY');
        $branches=$this->decode($state['branches']);$coverage=$this->decode($state['coverage']);
        $this->need(is_array($branches)&&count($branches)>0&&is_array($coverage),'SOURCE_IDENTITY');
        $media=$this->one('SELECT `device_id`,`snapshot_id`,`sha256`,`files` FROM `'.$database.'`.`desktop_dashboard_media_manifest` WHERE `device_id`=?',[$input['device_id']]);
        $this->need($media!==null&&$media['device_id']===$input['device_id']&&$media['snapshot_id']===$input['snapshot_id']
            &&is_string($media['files']??null)&&is_string($media['sha256']??null)
            &&hash_equals($media['sha256'],hash('sha256',$media['files'])),'SOURCE_MEDIA');
        $files=$this->decode($media['files']);$this->need(is_array($files),'SOURCE_MEDIA');
        $device=$this->one('SELECT `id`,`actor_id`,`enrollment_hash`,`enrolled_at`,`branches`,`enabled` FROM `'.$database.'`.`desktop_dashboard_devices` WHERE `id`=?',[$input['device_id']]);
        $enrollment=null;
        if(($coverage['print_protocol']??null)===1){
            $this->need($device!==null&&$device['id']===$input['device_id']&&(int)$device['actor_id']===$input['actor_id']
                &&(int)$device['enabled']===1&&$this->hash($device['enrollment_hash']??null)&&is_string($device['enrolled_at']??null),'SOURCE_ENROLLMENT');
            $base=$this->decode($device['branches']);$this->need(is_array($base)&&count($base)>0,'SOURCE_ENROLLMENT');
            $enrollment=['enrollment_hash'=>$device['enrollment_hash'],'enrolled_at'=>$device['enrolled_at'],'base_branches'=>$base];
        }
        return ['device_id'=>$input['device_id'],'actor_id'=>$input['actor_id'],'snapshot_id'=>$input['snapshot_id'],
            'schema_hash'=>$input['schema_hash'],'branches'=>$branches,'coverage'=>$coverage,'source'=>$input['source'],
            'media'=>$files,'enrollment'=>$enrollment];
    }

    private function projection(string $table,array $coverage): ?array
    {
        return DesktopDashboardDeltaCodec::projection($table,$coverage);
    }
    private function sameKeys(array $left,array $right): bool
    {
        $left=array_keys($left);$right=array_keys($right);sort($left,SORT_STRING);sort($right,SORT_STRING);return $left===$right;
    }
    private function tables(string $database): array
    {
        $rows=$this->pdo->query('SHOW TABLES FROM `'.$database.'`')->fetchAll(\PDO::FETCH_COLUMN);
        $this->need(is_array($rows),'DATABASE_TABLES');return $rows;
    }
    private function rows(string $database,string $table): array
    {
        return $this->pdo->query('SELECT * FROM `'.$database.'`.`'.$table.'`')->fetchAll(\PDO::FETCH_ASSOC);
    }
    private function ddl(string $database,string $table): string
    {
        $row=$this->pdo->query('SHOW CREATE TABLE `'.$database.'`.`'.$table.'`')->fetch(\PDO::FETCH_NUM);
        $this->need(is_array($row)&&is_string($row[1]??null),'TABLE_DDL');return $row[1];
    }
    private function insertRows(string $database,string $table,array $rows): void
    {
        if(!$rows)return;
        $columns=array_keys($rows[0]);$this->need(count($columns)>0,'ROW_COLUMNS');
        foreach($columns as $name)$this->need(is_string($name)&&preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D',$name),'ROW_COLUMNS');
        $statement='INSERT INTO `'.$database.'`.`'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
        $prepared=$this->pdo->prepare($statement);
        foreach($rows as $row){$this->need(is_array($row)&&array_keys($row)===$columns,'ROW_COLUMNS');$prepared->execute(array_values($row));}
    }
    private function replayRow(array $row,string $device,int $actor): array
    {
        $this->need(($row['device_id']??null)===$device&&$this->uuid($row['command_id']??null)
            &&(string)($row['actor_id']??'')===(string)$actor&&is_string($row['route_name']??null)
            &&strlen($row['route_name'])>0&&strlen($row['route_name'])<=150
            &&preg_match('/^[\x21-\x7e]+$/D',$row['route_name'])&&$this->hash($row['request_hash']??null)
            &&$this->database($row['source_database']??null),'REPLAY_ROW');
        return ['device_id'=>$device,'command_id'=>$row['command_id'],'actor_id'=>$actor,
            'route_name'=>$row['route_name'],'request_hash'=>$row['request_hash'],'source_database'=>$row['source_database']];
    }
    private function eachReplay(string $database,string $table,string $device,int $actor,callable $consume): void
    {
        $foreign=$this->one('SELECT COUNT(*) AS `total` FROM `'.$database.'`.`'.$table.'` WHERE `device_id`<>? OR `actor_id`<>?',[$device,$actor]);
        $this->need($foreign!==null&&(int)$foreign['total']===0,'REPLAY_ROW');
        $last='';
        do{
            $statement=$this->execute('SELECT `device_id`,`command_id`,`actor_id`,`route_name`,`request_hash`,`source_database` FROM `'.$database.'`.`'.$table.'` WHERE `device_id`=? AND `command_id`>? ORDER BY `command_id` LIMIT 1000',[$device,$last]);
            $rows=$statement->fetchAll(\PDO::FETCH_ASSOC);$normalized=[];
            foreach($rows as $row){$value=$this->replayRow($row,$device,$actor);$this->need($value['command_id']!==$last,'REPLAY_ORDER');$last=$value['command_id'];$normalized[]=$value;}
            if($normalized)$consume($normalized);
        }while(count($rows)===1000);
    }
    private function captureReplays(string $source,string $target,string $device,int $actor,int $sequence): void
    {
        if(in_array('desktop_dashboard_archived_commands',$this->tables($source),true))
            $this->eachReplay($source,'desktop_dashboard_archived_commands',$device,$actor,function(array $rows)use($target){$this->insertRows($target,self::REPLAY_TABLE,$rows);});
        $last=0;
        do{
            $statement=$this->execute('SELECT `sequence`,`device_id`,`command_id`,`actor_id`,`route_name`,`request_hash` FROM `'.$source.'`.`desktop_dashboard_commands` WHERE `sequence`>? AND `sequence`<=? ORDER BY `sequence` LIMIT 1000',[$last,$sequence]);
            $rows=$statement->fetchAll(\PDO::FETCH_ASSOC);$normalized=[];
            foreach($rows as $row){$current=$this->integer($row['sequence']??null,'REPLAY_SEQUENCE');$this->need($current>$last&&$current<=$sequence,'REPLAY_SEQUENCE');$last=$current;
                $row['source_database']=$source;$normalized[]=$this->replayRow($row,$device,$actor);}
            if($normalized)$this->insertRows($target,self::REPLAY_TABLE,$normalized);
        }while(count($rows)===1000);
    }
    private function replayProof(string $database,string $table,string $device,int $actor,string $source,int $sequence): array
    {
        $hash=hash_init('sha256');hash_update($hash,"desktop-dashboard-replays:1\n");$count=0;
        $this->eachReplay($database,$table,$device,$actor,function(array $rows)use($hash,&$count){
            foreach($rows as $row){hash_update($hash,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");$count++;}
        });
        return ['format'=>1,'source_database'=>$source,'sequence'=>$sequence,'rows'=>$count,'sha256'=>hash_final($hash)];
    }

    private function safeDdl(string $table,string $ddl,array $roster): void
    {
        $this->need(strlen($ddl)<1024*1024&&str_starts_with($ddl,'CREATE TABLE `'.$table.'` ('),'TABLE_DDL');
        $syntax=preg_replace('/\x27(?:[^\x27\\\\]|\\\\.|\x27\x27)*\x27|"(?:[^"\\\\]|\\\\.|"")*"|`(?:[^`]|``)*`/s','_',$ddl);
        $syntax=preg_replace('/\bON\s+(?:DELETE|UPDATE)\s+(?:RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION)\b/i','',$syntax??'');
        $syntax=preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\([0-9]*\))?/i','',$syntax);
        $this->need($syntax!==null&&preg_match('/\bENGINE=InnoDB\b/i',$syntax)
            &&!preg_match('/;|--|#|\/\*|REFERENCES\s+_\s*\.|\b(?:SELECT|INSERT|UPDATE|DELETE|DROP|ALTER|RENAME|TRUNCATE|GRANT|REVOKE|INTO|OUTFILE|INFILE|TABLESPACE|DIRECTORY|FEDERATED|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i',substr($syntax,strlen('CREATE TABLE'))),'TABLE_DDL');
        preg_match_all('/REFERENCES\s+`([^`]+)`\s*\(/i',$ddl,$references);
        foreach($references[1] as $name)$this->need(in_array($name,$roster,true),'TABLE_REFERENCE');
    }
    private function qualifiedDdl(string $database,string $table,string $ddl): string
    {
        return 'CREATE TABLE `'.$database.'`.`'.$table.'` ('.substr($ddl,strlen('CREATE TABLE `'.$table.'` ('));
    }
    private function one(string $sql,array $bindings=[]): ?array{$statement=$this->execute($sql,$bindings);$value=$statement->fetch(\PDO::FETCH_ASSOC);return is_array($value)?$value:null;}
    private function execute(string $sql,array $bindings=[]): object{$statement=$this->pdo->prepare($sql);$statement->execute($bindings);return $statement;}
    private function decode($value){$this->need(is_string($value),'PRIVATE_JSON');return json_decode($value,true,512,JSON_THROW_ON_ERROR);}
    private function integer($value,string $reason): int{$this->need((is_int($value)&&$value>=0)||(is_string($value)&&preg_match('/^(?:0|[1-9][0-9]{0,15})$/D',$value)), $reason);$this->need((int)$value<=9007199254740991,$reason);return (int)$value;}
    private function database($value,bool $stage=false): bool{return is_string($value)&&preg_match($stage?'/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/D':'/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',$value)===1;}
    private function uuid($value): bool{return is_string($value)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$value)===1;}
    private function hash($value): bool{return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;}
    private function source($value): bool{return is_array($value)&&count($value)===4&&($value['format']??null)===1&&is_int($value['files']??null)&&$value['files']>0&&$value['files']<=20000&&$this->hash($value['sha256']??null)&&is_string($value['framework']??null)&&preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$value['framework']);}
    private function sameSource($left,$right): bool
    {
        return $this->source($left)&&$this->source($right)&&$left['files']===$right['files']
            &&$left['framework']===$right['framework']&&hash_equals($left['sha256'],$right['sha256']);
    }
    private static function json($value): string{return DesktopDashboardBootstrap::json($value);}
    private function need($value,string $reason): void{if(!$value)$this->fail($reason);}
    private function fail(string $reason): never{throw new \RuntimeException('DESKTOP_DELTA_'.$reason);}
}
