<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

/** Private native proof for a fully verified, inactive shared-workspace generation. */
final class DesktopDashboardWorkspaceHandoff
{
    private const KIND='desktop-workspace-preverified-handoff';
    private const DOMAIN="desktop-workspace-preverify:2\n";
    private const REPLAY_DOMAIN="desktop-workspace-replays:2\n";
    private const ERROR='دليل فحص مساحة عمل الجهاز غير متطابق؛ بيانات الجهاز الحالية محفوظة.';
    private const SAFE=9007199254740991;

    /** Full member/history/source/media work finishes before the active write fence is acquired. */
    public function captureWorkspace(array $receipt,array $expectedBinding,string $token): array
    {
        $this->boundary($receipt,$expectedBinding,$token);
        $replay=$this->replay($receipt['delta_replay']??null,$receipt['workspace']);
        $sourceIdentity=$this->sourceIdentity($receipt,$expectedBinding,$replay['source_database']);
        $checked=DesktopDashboardMediaSeal::capture($receipt['media']??[],
            fn()=>app(DesktopDashboardWorkspaceImport::class)->verifyWorkspace($receipt,$expectedBinding));
        $receipt=$checked['result'];
        app(DesktopDashboardWorkspaceCommandAuthorities::class)->assertReplayHistory($receipt['device_id'],$receipt);
        abort_unless($this->same($this->replayDigest($receipt),$replay),409,self::ERROR);
        $state=DB::transaction(fn()=>$this->bindings($receipt,$expectedBinding));
        $payload=['database'=>DB::connection()->getDatabaseName(),'application'=>$this->application(),
            'receipt_sha256'=>$this->hash($receipt),'expected_binding_sha256'=>$this->hash($expectedBinding),
            'workspace'=>$receipt['workspace'],'source_database'=>$replay['source_database'],
            'source_identity'=>$sourceIdentity,'sequence'=>$replay['sequence'],'replay'=>$replay,
            'state_sha256'=>$this->hash($state),'media_seal_sha256'=>$this->hash($checked['seal'])];
        return $receipt+['media_seal'=>$checked['seal'],'archive_handoff'=>['format'=>2,'kind'=>self::KIND,
            'payload'=>$payload,'signature'=>hash_hmac('sha256',self::DOMAIN.$this->json($payload),hex2bin($token))]];
    }

    /** Authentication precedes SQL. Cutover checks only indexed bindings and sealed file identities. */
    public function verifyWorkspaceHandoff(array $receipt,array $expectedBinding,array $seal,array $proof,
        string $token,string $source,?array $identity): array
    {
        $this->boundary($receipt,$expectedBinding,$token);
        abort_unless($this->exact($proof,['format','kind','payload','signature'])&&$proof['format']===2
            &&$proof['kind']===self::KIND&&is_array($proof['payload'])&&$this->digest($proof['signature']),409,self::ERROR);
        $payload=$proof['payload'];
        abort_unless($this->exact($payload,['database','application','receipt_sha256','expected_binding_sha256','workspace',
            'source_database','source_identity','sequence','replay','state_sha256','media_seal_sha256'])
            &&hash_equals(hash_hmac('sha256',self::DOMAIN.$this->json($payload),hex2bin($token)),$proof['signature']),409,self::ERROR);
        $replay=$this->replay($receipt['delta_replay']??null,$receipt['workspace']);
        $this->sourceContext($receipt,$expectedBinding);
        abort_unless(($receipt['verified']??null)===true&&$payload['database']===DB::connection()->getDatabaseName()
            &&$payload['application']===$this->application()&&$payload['receipt_sha256']===$this->hash($receipt)
            &&$payload['expected_binding_sha256']===$this->hash($expectedBinding)
            &&is_array($payload['workspace'])&&$this->same($payload['workspace'],$receipt['workspace'])&&$payload['source_database']===$source
            &&$source===$replay['source_database']&&is_array($identity)
            &&$this->same($identity,$receipt['delta_source_identity'])&&is_array($payload['source_identity'])&&$this->same($payload['source_identity'],$identity)
            &&$payload['sequence']===$replay['sequence']&&is_array($payload['replay'])&&$this->same($payload['replay'],$replay)
            &&$this->digest($payload['state_sha256'])&&$payload['media_seal_sha256']===$this->hash($seal),409,self::ERROR);
        abort_unless($payload['state_sha256']===$this->hash(DB::transaction(fn()=>$this->bindings($receipt,$expectedBinding))),409,self::ERROR);
        DesktopDashboardMediaSeal::verify($receipt['media']??[],$seal);
        return $payload;
    }

    private function boundary(array $receipt,array $expected,string $token): void
    {
        abort_unless(PHP_SAPI==='cli'&&getenv('DESKTOP_DASHBOARD_LOCAL')==='true'
            &&getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')==='true'&&config('desktop_dashboard.local')===true
            &&config('desktop_dashboard.workspace_enabled')===true&&DB::connection()->getConfig('host')==='127.0.0.1'
            &&DB::transactionLevel()===0&&$this->database(DB::connection()->getDatabaseName(),true)
            &&$this->digest($token)&&($receipt['format']??null)===2
            &&($receipt['kind']??null)==='desktop-workspace-import-receipt'
            &&$this->uuid($receipt['device_id']??null)&&$receipt['device_id']===(string)config('desktop_dashboard.device_id')
            &&$this->positive($receipt['actor_id']??null)&&is_array($receipt['workspace']??null)
            &&!array_key_exists('media_seal',$receipt)&&!array_key_exists('archive_handoff',$receipt),403,self::ERROR);
        $this->expected($expected);$this->workspace($receipt['workspace']);
        abort_unless($receipt['device_id']===$expected['workspace_id']&&$this->same($receipt['workspace'],$this->workspaceFromExpected($expected)),409,self::ERROR);
    }

    /** These fixed rows remain private and immutable between preverification and activation. */
    private function bindings(array $receipt,array $expected): array
    {
        app(DesktopDashboardPrintLedger::class)->fence($receipt['device_id']);
        $authority=app(DesktopDashboardWorkspaceLocalAuthority::class)->handoffBindings($receipt['workspace'],$expected);
        abort_unless($this->exact($authority,['snapshot_id','schema_hash','workspace'])
            &&$authority['snapshot_id']===($receipt['snapshot_id']??null)&&$authority['schema_hash']===($receipt['schema_hash']??null)
            &&is_array($authority['workspace'])&&$this->same($authority['workspace'],$receipt['workspace']),409,self::ERROR);
        $state=DB::table('desktop_dashboard_local_state')->where('device_id',$receipt['device_id'])->lockForUpdate()->first();
        abort_unless($state&&$state->state==='ready'&&$state->snapshot_id===$receipt['snapshot_id']
            &&(int)$state->actor_id===$receipt['actor_id']&&$state->schema_hash===$receipt['schema_hash']
            &&$this->decode($state->branches)===($receipt['branches']??null)
            &&$this->decode($state->coverage)===($receipt['coverage']??null),409,self::ERROR);
        foreach(['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_refreshes'] as $table)
            abort_unless(!DB::table($table)->exists(),409,self::ERROR);
        $source=DB::table('desktop_dashboard_source_manifest')->where('device_id',$receipt['device_id'])->first(['snapshot_id','sha256']);
        $media=DB::table('desktop_dashboard_media_manifest')->where('device_id',$receipt['device_id'])->first(['snapshot_id','sha256']);
        abort_unless($source&&$source->snapshot_id===$receipt['snapshot_id']
            &&$source->sha256===hash('sha256',DesktopDashboardBootstrap::json($receipt['source']??null))
            &&$media&&$media->snapshot_id===$receipt['snapshot_id']&&$this->digest($media->sha256),409,self::ERROR);
        return ['identity'=>$authority,'actor_id'=>(int)$state->actor_id,'branches'=>$this->decode($state->branches),
            'coverage'=>$this->decode($state->coverage),'source_sha256'=>$source->sha256,'media_sha256'=>$media->sha256];
    }

    /** The source expectation was pinned by native and authenticated during source capture. */
    private function sourceContext(array $receipt,array $expected): void
    {
        abort_unless(is_array($receipt['delta_source_binding']??null)&&is_array($receipt['delta_source_identity']??null),409,self::ERROR);
        $source=$receipt['delta_source_binding'];$this->expected($source);$identity=$receipt['delta_source_identity'];
        abort_unless($this->exact($identity,['snapshot_id','schema_hash','workspace'])&&$this->uuid($identity['snapshot_id'])
            &&$this->digest($identity['schema_hash'])&&is_array($identity['workspace'])
            &&$this->same($identity['workspace'],$this->workspaceFromExpected($source))
            &&$source['workspace_id']===$expected['workspace_id']&&$source['root_fingerprint']===$expected['root_fingerprint']
            &&$source['token_hash']===$expected['token_hash']&&$source['revision']<=$expected['revision'],409,self::ERROR);
        if($source['revision']===$expected['revision'])abort_unless($source['roster_sha256']===$expected['roster_sha256']
            &&$source['manifest_sha256']===$expected['manifest_sha256'],409,self::ERROR);
    }
    private function sourceIdentity(array $receipt,array $expected,string $database): array
    {
        $this->sourceContext($receipt,$expected);
        $record=DB::selectOne('SELECT record_json,local_mac FROM `'.$database.'`.`desktop_dashboard_workspace_local_authority` WHERE workspace_id=?',[$receipt['device_id']]);
        $state=DB::selectOne('SELECT device_id,actor_id,snapshot_id,schema_hash,state FROM `'.$database.'`.`desktop_dashboard_local_state` WHERE device_id=?',[$receipt['device_id']]);
        abort_unless($record&&$state&&$state->state==='ready',409,self::ERROR);
        $identity=app(DesktopDashboardWorkspaceLocalAuthority::class)->verifyCaptureBindings((array)$record,(array)$state,
            $database,$receipt['delta_source_binding']);
        abort_unless($this->same($identity,$receipt['delta_source_identity']),409,self::ERROR);
        return $identity;
    }

    private function replay($value,array $workspace): array
    {
        abort_unless(is_array($value)&&$this->exact($value,['format','workspace_id','root_fingerprint','source_database','sequence','rows','sha256'])
            &&$value['format']===2&&$value['workspace_id']===$workspace['workspace_id']
            &&$value['root_fingerprint']===$workspace['root_fingerprint']&&$this->database($value['source_database'])
            &&$value['source_database']!==DB::connection()->getDatabaseName()
            &&$this->unsigned($value['sequence'])&&$this->unsigned($value['rows'])&&$this->digest($value['sha256']),409,self::ERROR);
        return $value;
    }
    /** Shared normalizer authenticates each actor's captured sidecar, including retired members. */
    private function replayDigest(array $receipt): array
    {
        $proof=$this->replay($receipt['delta_replay'],$receipt['workspace']);$workspace=$receipt['device_id'];
        abort_unless(!DB::table('desktop_dashboard_archived_commands')->where('device_id','<>',$workspace)->exists(),409,self::ERROR);
        $hash=hash_init('sha256');hash_update($hash,self::REPLAY_DOMAIN);$rows=0;$after='';
        do{
            $page=DB::select('SELECT device_id,command_id,actor_id,route_name,request_hash,source_database FROM desktop_dashboard_archived_commands'
                .' WHERE device_id=? AND command_id>? ORDER BY command_id LIMIT 1000',[$workspace,$after]);
            $ids=array_map(fn($row)=>$row->command_id,$page);$sidecars=[];
            if($ids)foreach(DB::table(DesktopDashboardWorkspaceCommandAuthorities::TABLE)->where('workspace_id',$workspace)->whereIn('command_id',$ids)->get() as $row){
                abort_unless(!isset($sidecars[$row->command_id]),409,self::ERROR);$sidecars[$row->command_id]=(array)$row;
            }
            foreach($page as $row){
                abort_unless($row->device_id===$workspace&&$this->uuid($row->command_id)&&strcmp($after,$row->command_id)<0
                    &&isset($sidecars[$row->command_id]),409,self::ERROR);
                $entry=DesktopDashboardWorkspaceDeltaLocal::replayEntry((array)$row,$sidecars[$row->command_id],$workspace,$proof['root_fingerprint']);
                hash_update($hash,$this->json($entry)."\n");$rows++;$after=$row->command_id;
            }
        }while(count($page)===1000);
        return ['format'=>2,'workspace_id'=>$workspace,'root_fingerprint'=>$proof['root_fingerprint'],
            'source_database'=>$proof['source_database'],'sequence'=>$proof['sequence'],'rows'=>$rows,'sha256'=>hash_final($hash)];
    }

    private function expected(array $value): void
    {
        abort_unless($this->exact($value,['workspace_id','root_fingerprint','revision','roster_sha256','manifest_sha256','token_hash'])
            &&$this->uuid($value['workspace_id'])&&$this->positive($value['revision']),409,self::ERROR);
        foreach(['root_fingerprint','roster_sha256','manifest_sha256','token_hash'] as $key)
            abort_unless($this->digest($value[$key]),409,self::ERROR);
    }
    private function workspace(array $value): void
    {
        abort_unless($this->exact($value,['workspace_id','root_fingerprint','manifest_revision','roster_sha256','manifest_sha256'])
            &&$this->uuid($value['workspace_id'])&&$this->positive($value['manifest_revision']),409,self::ERROR);
        foreach(['root_fingerprint','roster_sha256','manifest_sha256'] as $key)abort_unless($this->digest($value[$key]),409,self::ERROR);
    }
    private function workspaceFromExpected(array $value): array
    {
        return ['workspace_id'=>$value['workspace_id'],'root_fingerprint'=>$value['root_fingerprint'],
            'manifest_revision'=>$value['revision'],'roster_sha256'=>$value['roster_sha256'],'manifest_sha256'=>$value['manifest_sha256']];
    }
    private function application(): string
    {
        $path=realpath(base_path());abort_unless(is_string($path)&&is_dir($path),409,self::ERROR);
        $path=str_replace('\\','/',$path);return DIRECTORY_SEPARATOR==='\\'?strtolower($path):$path;
    }
    private function decode(string $json): array
    {
        try{$value=json_decode($json,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException $error){abort(409,self::ERROR);}
        abort_unless(is_array($value),409,self::ERROR);return $value;
    }
    private function database($value,bool $stage=false): bool
    {
        return is_string($value)&&preg_match($stage?'/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/D':'/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',$value)===1;
    }
    private function exact(array $value,array $keys): bool {return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];}
    private function digest($value): bool {return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;}
    private function uuid($value): bool {return is_string($value)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value)===1;}
    private function unsigned($value): bool {return is_int($value)&&$value>=0&&$value<=self::SAFE;}
    private function positive($value): bool {return is_int($value)&&$value>0&&$value<=self::SAFE;}
    private function hash(array $value): string {return hash('sha256',$this->json($value));}
    private function same(array $left,array $right): bool {return $this->json($left)===$this->json($right);}
    private function json(array $value): string
    {
        try{return DesktopDashboardWorkspaceAuthority::canonical($value);}
        catch(\Throwable $error){abort(409,self::ERROR);}
    }
}
