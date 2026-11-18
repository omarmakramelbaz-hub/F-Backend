<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Validator};

/** Protocol-two preflight around the original isolated SQL/FK/media import engine. */
final class DesktopDashboardWorkspaceImport extends DesktopDashboardImport
{
    private object $issuanceCapability;
    private ?array $verifiedContext=null;
    public function __construct() { $this->issuanceCapability=new \stdClass; }

    public function importWorkspace(array $snapshot,array $expectedBinding,?array $rowHashes=null): array
    {
        $this->boundary();abort_unless($this->verifiedContext===null,409);
        $this->verifiedContext=$this->preflight($snapshot,$expectedBinding);
        try{
            $legacy=$snapshot;$legacy['format']=1;$legacy['kind']='initial-dashboard-data';
            // Original importer still validates every DDL, raw row checksum,
            // schema digest, FK, source and the empty staging database boundary.
            $receipt=parent::import($legacy,$rowHashes);
            $proof=DesktopDashboardWorkspaceImportProvenance::issue($this,$this->issuanceCapability,$this->verifiedContext);
            $binding=app(DesktopDashboardWorkspaceLocalAuthority::class)->install($proof);
            if(($snapshot['coverage']['catalog_scopes']??null)===1)
                DB::transaction(fn()=>app(DesktopDashboardWorkspaceCreatedScopes::class)->restoreWorkspace($snapshot,$proof));
            // The physical print scope is bound only after actual rows and the
            // private local root authority exist. Custody does not grant prints.
            app(DesktopDashboardPrintLedger::class)->bind($snapshot['device_id']);
            return array_merge($receipt,['format'=>2,'kind'=>'desktop-workspace-import-receipt','workspace'=>$binding]);
        }finally{$this->verifiedContext=null;}
    }

    public function verifyWorkspace(array $receipt,array $expectedBinding,?array $mediaSeal=null): array
    {
        $this->boundary();abort_unless($this->verifiedContext===null,409);
        self::need(($receipt['format']??null)===2&&($receipt['kind']??null)==='desktop-workspace-import-receipt'
            &&is_array($receipt['workspace']??null));
        $this->expected($expectedBinding);
        $manifest=app(DesktopDashboardWorkspaceLocalAuthority::class)->verifyImport($receipt['workspace'],$expectedBinding);
        $codec=new DesktopDashboardWorkspaceDeltaCodec($manifest);
        self::need(($receipt['device_id']??null)===$manifest['workspace_id']);
        $this->coverage($receipt['coverage']??null,$manifest);
        $this->branches($receipt['branches']??null,$manifest);
        $this->verifiedContext=['manifest'=>$manifest];
        try{
            $legacy=$receipt;$legacy['format']=1;
            $checked=parent::verify($legacy,$mediaSeal);
            // Check actual local password/permission facts again after the
            // ordinary source/table/media verification has completed.
            app(DesktopDashboardWorkspaceLocalAuthority::class)->verifyImport($receipt['workspace'],$expectedBinding);
            return array_merge($checked,['format'=>2,'kind'=>'desktop-workspace-import-receipt','verified'=>true]);
        }finally{$this->verifiedContext=null;}
    }

    public function assertIssuanceCapability(object $capability): void
    {
        if($capability!==$this->issuanceCapability||$this->verifiedContext===null)
            throw new \LogicException('Workspace import authority cannot be supplied by a request.');
    }

    private function preflight(array $snapshot,array $expected): array
    {
        self::need(($snapshot['format']??null)===2&&($snapshot['kind']??null)==='initial-workspace-data'
            &&is_array($snapshot['workspace_manifest']??null)&&is_array($snapshot['workspace_enrollment']??null));
        $this->expected($expected);$manifest=$snapshot['workspace_manifest'];
        $codec=new DesktopDashboardWorkspaceDeltaCodec($manifest);
        foreach(['workspace_id','root_fingerprint','roster_sha256'] as $key)self::need($manifest[$key]===$expected[$key]);
        self::need($manifest['revision']===$expected['revision']
            &&hash_equals($expected['manifest_sha256'],hash('sha256',DesktopDashboardWorkspaceAuthority::canonical($manifest))));
        $enrollment=$snapshot['workspace_enrollment'];
        self::need(self::exact($enrollment,['format','workspace_id','custodian_actor_id','enrollment_hash','token_hash','enrolled_at'])
            &&$enrollment['format']===2&&$enrollment['workspace_id']===$manifest['workspace_id']
            &&is_int($enrollment['custodian_actor_id'])&&$enrollment['custodian_actor_id']>0
            &&$enrollment['custodian_actor_id']<=9007199254740991
            &&self::hash($enrollment['enrollment_hash'])&&self::hash($enrollment['token_hash'])
            &&hash_equals($expected['token_hash'],$enrollment['token_hash'])
            &&is_string($enrollment['enrolled_at'])&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$enrollment['enrolled_at']));
        $root=(object)['id'=>$enrollment['workspace_id'],'custodian_actor_id'=>$enrollment['custodian_actor_id'],
            'enrollment_hash'=>$enrollment['enrollment_hash'],'token_hash'=>$enrollment['token_hash'],'enrolled_at'=>$enrollment['enrolled_at']];
        self::need(hash_equals($expected['root_fingerprint'],app(DesktopDashboardWorkspaceAuthority::class)->rootFingerprint($root))
            &&($snapshot['device_id']??null)===$enrollment['workspace_id']
            &&($snapshot['actor_id']??null)===$enrollment['custodian_actor_id']
            &&($snapshot['enrollment']['enrollment_hash']??null)===$enrollment['enrollment_hash']
            &&($snapshot['enrollment']['enrolled_at']??null)===$enrollment['enrolled_at']);
        $this->coverage($snapshot['coverage']??null,$manifest);$this->branches($snapshot['branches']??null,$manifest);
        self::need(($snapshot['enrollment']['base_branches']??null)===$snapshot['branches']);
        if($snapshot['coverage']['catalog_scopes']===1)app(DesktopDashboardWorkspaceCreatedScopes::class)->validateSnapshotCatalog($snapshot,$manifest);
        else self::need(($snapshot['created_scopes']??null)===[]);
        $users=$snapshot['tables']['users']??null;
        self::need(is_array($users)&&is_array($users['rows']??null)&&is_string($users['ddl']??null));
        $members=[];foreach($manifest['members'] as $member)$members[$member['actor_id']]=$member;
        $seen=[];
        foreach($users['rows'] as $row){
            self::need(is_array($row)&&isset($row['id'])&&array_key_exists('password',$row));$id=(int)$row['id'];
            if(isset($members[$id])){
                self::need(!isset($seen[$id])&&($row['account_type']??null)===$members[$id]['role']
                    &&!in_array($row['status']??null,['disabled','declined'],true));$seen[$id]=true;
            }else self::need(is_string($row['password'])&&(bool)preg_match('/^!desktop-disabled(?:-[A-Za-z0-9]{64})?$/D',$row['password']));
        }
        self::need(count($seen)===count($members));
        // Public index checks also validate the DDL/SQL types and EVERY member
        // verifier against its authenticated SHA256 binding. No hash API exposes it.
        $codec->index('users',$users['rows'],$users['ddl']);
        if(array_key_exists('modules',$snapshot['coverage']))DesktopDashboardWorkspaceModuleCoverage::validateSnapshot($snapshot);
        return ['workspace_enrollment'=>$enrollment,'manifest'=>$manifest,
            'snapshot'=>['workspace_id'=>$snapshot['device_id'],'custodian_actor_id'=>$snapshot['actor_id'],
                'snapshot_id'=>$snapshot['snapshot_id']??null,'schema_hash'=>$snapshot['schema_hash']??null,'source'=>$snapshot['source']??null,
                'branches'=>$snapshot['branches'],'coverage'=>$snapshot['coverage']],
            'database'=>DB::connection()->getDatabaseName(),
            'expected_binding_digest'=>hash('sha256',DesktopDashboardWorkspaceAuthority::canonical($expected))];
    }

    private function expected(array $value): void
    {
        self::need(self::exact($value,['workspace_id','root_fingerprint','revision','roster_sha256','manifest_sha256','token_hash'])
            &&is_string($value['workspace_id'])&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value['workspace_id'])
            &&is_int($value['revision'])&&$value['revision']>0&&$value['revision']<=9007199254740991);
        foreach(['root_fingerprint','roster_sha256','manifest_sha256','token_hash'] as $key)self::need(self::hash($value[$key]));
    }
    private function coverage($coverage,array $manifest): void
    {
        self::need(is_array($coverage)&&($coverage['workspace_protocol']??null)===2
            &&($coverage['manifest_revision']??null)===$manifest['revision']
            &&($coverage['roster_sha256']??null)===$manifest['roster_sha256']
            &&($coverage['root_fingerprint']??null)===$manifest['root_fingerprint']
            &&($coverage['print_protocol']??null)===1&&in_array($coverage['catalog_scopes']??null,[0,1],true)
            &&($coverage['full_dashboard']??null)===false);
    }
    private function branches($branches,array $manifest): void
    {
        $union=[];foreach($manifest['members'] as $member)$union=array_merge($union,$member['branches']);
        $union=array_values(array_unique($union));sort($union,SORT_STRING);self::need($branches===$union);
    }
    private function boundary(): void
    {
        abort_unless(PHP_SAPI==='cli'&&getenv('DESKTOP_DASHBOARD_LOCAL')==='true'
            &&getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')==='true'
            &&config('desktop_dashboard.local')===true&&config('desktop_dashboard.workspace_enabled')===true
            &&DB::connection()->getConfig('host')==='127.0.0.1'
            &&preg_match('/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/D',DB::connection()->getDatabaseName()),403);
    }

    /** Empty financial branch union is valid only after typed workspace preflight. */
    protected function assertPrincipalRows(array $users,array $snapshot): void
    {
        self::need($this->verifiedContext!==null);$counts=[];
        foreach($users as $row){$id=(int)($row['id']??0);$counts[$id]=($counts[$id]??0)+1;}
        foreach($this->verifiedContext['manifest']['members'] as $member)self::need(($counts[$member['actor_id']]??0)===1);
        // An absent/retired custodian is audit metadata, never a fabricated
        // authentication principal. Actual members were hash-verified before DDL.
    }
    protected function validateCatalogEnvelope(array $snapshot,array $base): void
    {
        self::need($this->verifiedContext!==null&&$base===$snapshot['branches']);
        if(($snapshot['coverage']['catalog_scopes']??null)===1){
            // The original engine receives an internal v1 header only to reuse
            // its SQL/FK importer. Restore the already-preflighted workspace
            // envelope for the strict group catalog validator.
            $workspace=$snapshot;$workspace['format']=2;$workspace['kind']='initial-workspace-data';
            app(DesktopDashboardWorkspaceCreatedScopes::class)->validateSnapshotCatalog($workspace,$this->verifiedContext['manifest']);
        }
        else parent::validateCatalogEnvelope($snapshot,$base);
    }
    protected function restoreCatalogSnapshot(array $snapshot): void
    {
        // Deferred until typed local authority exists; legacy restore expects a
        // single current custodian and cannot import retired creators' history.
        self::need($this->verifiedContext!==null);
    }
    protected function verifyCatalogReceipt(array $receipt): void
    {
        self::need($this->verifiedContext!==null);
        app(DesktopDashboardWorkspaceCreatedScopes::class)->verifyWorkspaceCatalog($receipt,$this->verifiedContext['manifest']['root_fingerprint']);
    }
    protected function provisionPrintLedger(array $snapshot): void
    {
        self::need($this->verifiedContext!==null);
        app(DesktopDashboardPrintLedgerSchema::class)->install();
    }
    protected function validateImportMetadata(array $snapshot): void
    {
        self::need($this->verifiedContext!==null);
        Validator::make(self::validationMetadata($snapshot,['format','kind','snapshot_id','device_id','actor_id','branches','schema_hash','tables','coverage'],['tables','coverage']),
            ['format'=>'required|in:1','kind'=>'required|in:initial-dashboard-data','snapshot_id'=>'required|uuid','device_id'=>'required|uuid',
                'actor_id'=>'required|integer|min:1','branches'=>'present|array','branches.*'=>['required','distinct','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],
                'schema_hash'=>'required|regex:/^[a-f0-9]{64}$/D','tables'=>'required|array','coverage'=>'required|array'])->validate();
    }
    protected function validateBaseBranches(array $base): void
    {
        self::need($this->verifiedContext!==null);
        Validator::make(['base'=>$base],['base'=>'present|array','base.*'=>['required','distinct','regex:/^(f|gs):[1-9][0-9]{0,18}$/D']])->validate();
    }
    protected function validateVerifyMetadata(array $receipt): void
    {
        self::need($this->verifiedContext!==null);
        Validator::make($receipt,['format'=>'required|in:1','snapshot_id'=>'required|uuid','device_id'=>'required|uuid','actor_id'=>'required|integer|min:1',
            'schema_hash'=>'required|regex:/^[a-f0-9]{64}$/D','branches'=>'present|array','coverage'=>'required|array','table_rows'=>'required|array|min:1',
            'tables'=>'required|integer|min:1','rows'=>'required|integer|min:0'])->validate();
    }
    protected function assertReplayHistory(array $receipt): void
    {
        self::need($this->verifiedContext!==null);
        // verifyWorkspace already checks every actual member and verifier via
        // LocalAuthority. Historical custody need not have a current users row.
        abort_unless(DB::table('desktop_dashboard_commands')->count()===0
            &&!DB::table('desktop_dashboard_archived_commands')->where('device_id','<>',$receipt['device_id'])->exists(),409);
        // Empty initial staging imports need no historical proof. Refreshes
        // retain every actor and validate immutable captured command authority.
        if(DB::table('desktop_dashboard_archived_commands')->exists())
            app(DesktopDashboardWorkspaceCommandAuthorities::class)->assertReplayHistory($receipt['device_id'],$receipt);
    }
    private static function exact(array $value,array $keys): bool {return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];}
    private static function hash($value): bool {return is_string($value)&&(bool)preg_match('/^[a-f0-9]{64}$/D',$value);}
    private static function need(bool $condition): void {abort_unless($condition,422,'بيانات مساحة عمل الجهاز غير متطابقة؛ النسخة الحالية محفوظة.');}
}
