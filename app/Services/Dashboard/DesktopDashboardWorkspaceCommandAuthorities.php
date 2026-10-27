<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Schema};

/** A command keeps its actual actor and original grant across account switches and generation refreshes. */
final class DesktopDashboardWorkspaceCommandAuthorities
{
    public const TABLE='desktop_dashboard_workspace_command_authorities';
    private const ERROR='دليل صلاحيات العملية المحفوظة غير مكتمل؛ بيانات الجهاز محفوظة وتحتاج مراجعة.';
    private const FIELDS=['format','workspace_id','root_fingerprint','actor_id','manifest_revision','grant','command_sha256'];

    /** Called immediately after the journal insert, in the same transaction. */
    public function capture(string $workspace,string $command,int $actor,string $requestHash): void
    {
        $scope=$this->scope($workspace);
        if ($scope===null) return;
        abort_unless(!DesktopDashboardWorkspaceExecutionContext::isReadOnly()&&DB::transactionLevel()>0
            && $scope->actor_id===$actor && $this->digest($requestHash),503,self::ERROR);
        $journal=DB::table('desktop_dashboard_commands')->where('device_id',$workspace)->where('command_id',$command)->lockForUpdate()->first();
        abort_unless($journal&&(int)$journal->actor_id===$actor&&hash_equals($journal->request_hash,$requestHash),503,self::ERROR);
        if(config('desktop_dashboard.local')===true)
            abort_unless(DesktopDashboardWorkspaceExecutionContext::incomingProof()===null,503,self::ERROR);
        $member=$scope->acceptedMember()??$scope->member();
        $proof=DesktopDashboardWorkspaceExecutionContext::incomingProof() ?? [
            'format'=>2,'workspace_id'=>$workspace,'root_fingerprint'=>$scope->rootFingerprint(),
            'actor_id'=>$actor,'manifest_revision'=>$scope->acceptedManifestRevision()??$scope->manifestRevision(),
            'grant'=>$member['grant'],'command_sha256'=>$requestHash,
        ];
        $this->proof($proof,$workspace,$scope->rootFingerprint());
        abort_unless($proof['actor_id']===$actor && hash_equals($proof['grant'],$member['grant'])
            && $proof['manifest_revision']===($scope->acceptedManifestRevision()??$scope->manifestRevision()),403,self::ERROR);
        $json=DesktopDashboardWorkspaceAuthority::canonical($proof);
        $row=['workspace_id'=>$workspace,'command_id'=>$command,'actor_id'=>$actor,
            'root_fingerprint'=>$scope->rootFingerprint(),'manifest_revision'=>$proof['manifest_revision'],
            'grant_hash'=>hash('sha256',$proof['grant']),'request_hash'=>$requestHash,
            'proof_json'=>$json,'proof_sha256'=>hash('sha256',$json),
            'accepted_database'=>DB::connection()->getDatabaseName(),'created_at'=>now('UTC')];
        $row['proof_mac']=$this->mac($row);
        abort_unless(Schema::hasTable(self::TABLE),503,self::ERROR);
        // Do not turn a retry into permission to replace already accepted evidence.
        abort_if(DB::table(self::TABLE)->where('workspace_id',$workspace)->where('command_id',$command)->exists(),409,self::ERROR);
        DB::table(self::TABLE)->insert($row);
    }

    /** A lost reply must use the same authority evidence as the first accepted write. */
    public function assertExisting(string $workspace,string $command,int $actor,string $requestHash): void
    {
        $scope=$this->scope($workspace);
        if ($scope===null) return;
        abort_unless($scope->actor_id===$actor,403,self::ERROR);
        $proof=$this->forCommand($workspace,$command,$actor,$requestHash,$scope->rootFingerprint());
        $incoming=DesktopDashboardWorkspaceExecutionContext::incomingProof();
        if ($incoming!==null) abort_unless(DesktopDashboardWorkspaceAuthority::canonical($incoming)
            ===DesktopDashboardWorkspaceAuthority::canonical($proof),409,self::ERROR);
    }

    /** The physical queue remains globally ordered; it is never filtered to the currently signed-in actor. */
    public function decorate(string $workspace,array $commands): array
    {
        if (!$this->isWorkspace($workspace)) return $commands;
        $this->enabled();
        foreach ($commands as &$command) {
            $id=$command['command_id']??null; $actor=$command['actor_id']??null;
            abort_unless(is_string($id)&&is_int($actor),503,self::ERROR);
            $row=DB::table('desktop_dashboard_commands')->where('device_id',$workspace)->where('command_id',$id)->first();
            abort_unless($row && (int)$row->actor_id===$actor,503,self::ERROR);
            $command['workspace_authority']=$this->forCommand($workspace,$id,$actor,$row->request_hash);
            $hash=app(DesktopDashboardJournal::class)->fingerprint([$actor,$command['route_name'],
                $command['payload'],$command['dependencies']]);
            abort_unless(hash_equals($hash,$command['workspace_authority']['command_sha256']),503,self::ERROR);
        }
        unset($command); return $commands;
    }

    /** Validate captured evidence without consulting today's roster, including a now-retired actor. */
    public function assertHistoryRow(array $row,string $workspace,?string $rootFingerprint=null): array
    {
        foreach (['workspace_id','command_id','root_fingerprint','grant_hash','request_hash','proof_json',
            'proof_sha256','proof_mac','accepted_database'] as $field)
            abort_unless(isset($row[$field])&&is_string($row[$field]),503,self::ERROR);
        abort_unless($row['workspace_id']===$workspace && $this->uuid($workspace)&&$this->uuid($row['command_id'])
            &&$this->digest($row['root_fingerprint'])&&$this->digest($row['grant_hash'])
            &&$this->digest($row['request_hash'])&&$this->digest($row['proof_sha256'])&&$this->digest($row['proof_mac'])
            &&strlen($row['proof_json'])<=2048&&strlen($row['accepted_database'])<=100
            &&hash_equals($row['proof_sha256'],hash('sha256',$row['proof_json']))
            &&hash_equals($row['proof_mac'],$this->mac($row)),503,self::ERROR);
        try { $proof=json_decode($row['proof_json'],true,16,JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { abort(503,self::ERROR); }
        $this->proof($proof,$workspace,$rootFingerprint);
        abort_unless(DesktopDashboardWorkspaceAuthority::canonical($proof)===$row['proof_json']
            &&(string)$proof['actor_id']===(string)($row['actor_id']??'')
            &&(string)$proof['manifest_revision']===(string)($row['manifest_revision']??'')
            &&hash_equals($proof['root_fingerprint'],$row['root_fingerprint'])
            &&hash_equals(hash('sha256',$proof['grant']),$row['grant_hash']),503,self::ERROR);
        return $proof;
    }

    public function assertReplayHistory(string $workspace,array $receipt): void
    {
        $this->enabled();
        abort_unless(($receipt['device_id']??null)===$workspace,503,self::ERROR);
        $root=$receipt['workspace']['root_fingerprint']??$receipt['workspace_binding']['root_fingerprint']
            ??$receipt['workspace_manifest']['root_fingerprint']??null;
        if ($root!==null) abort_unless(is_string($root)&&$this->digest($root),503,self::ERROR);
        foreach (['desktop_dashboard_commands','desktop_dashboard_archived_commands'] as $table) {
            if (!Schema::hasTable($table)) continue;
            DB::table($table)->where('device_id',$workspace)->orderBy('command_id')->chunk(250,
                function($rows) use($workspace,$root) {
                    foreach ($rows as $row) $this->forCommand($workspace,$row->command_id,
                        (int)$row->actor_id,$row->request_hash,$root);
                });
        }
    }

    public function proof($proof,string $workspace,?string $rootFingerprint=null): array
    {
        abort_unless(is_array($proof),422,self::ERROR);
        $keys=array_keys($proof); sort($keys); $expected=self::FIELDS;sort($expected);
        abort_unless($keys===$expected&&$proof['format']===2&&$proof['workspace_id']===$workspace
            &&$this->uuid($workspace)&&is_int($proof['actor_id'])&&$proof['actor_id']>0&&$proof['actor_id']<=9007199254740991
            &&is_int($proof['manifest_revision'])&&$proof['manifest_revision']>0&&$proof['manifest_revision']<=9007199254740991
            &&$this->digest($proof['root_fingerprint'])&&$this->digest($proof['grant'])&&$this->digest($proof['command_sha256']),422,self::ERROR);
        if ($rootFingerprint!==null) abort_unless(hash_equals($rootFingerprint,$proof['root_fingerprint']),403,self::ERROR);
        return $proof;
    }

    private function forCommand(string $workspace,string $command,int $actor,string $requestHash,?string $root=null): array
    {
        abort_unless(Schema::hasTable(self::TABLE),503,self::ERROR);
        $row=DB::table(self::TABLE)->where('workspace_id',$workspace)->where('command_id',$command)->first();
        abort_unless($row&&(int)$row->actor_id===$actor&&hash_equals($row->request_hash,$requestHash),503,self::ERROR);
        return $this->assertHistoryRow((array)$row,$workspace,$root);
    }
    private function scope(string $workspace): ?DesktopDashboardWorkspaceScope
    {
        $scope=DesktopDashboardWorkspaceExecutionContext::current();
        if ($scope!==null) {
            $this->enabled(); abort_unless($scope->id===$workspace,403,self::ERROR); $scope->assertIssued(); return $scope;
        }
        abort_if($this->isWorkspace($workspace),503,self::ERROR); return null;
    }
    private function isWorkspace(string $workspace): bool
    {
        if(config('desktop_dashboard.local')===true&&app(DesktopDashboardWorkspaceLocalAuthority::class)->selected())
            return true;
        return Schema::hasTable('desktop_dashboard_workspaces')&&DB::table('desktop_dashboard_workspaces')->where('id',$workspace)->exists();
    }
    private function enabled(): void { abort_unless(config('desktop_dashboard.workspace_enabled')===true,503,self::ERROR); }
    private function uuid($value): bool { return is_string($value)&&(bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$value); }
    private function digest($value): bool { return is_string($value)&&(bool)preg_match('/^[a-f0-9]{64}$/D',$value); }
    private function mac(array $row): string
    {
        $key=(string)config('app.key');
        if (str_starts_with($key,'base64:')) $key=base64_decode(substr($key,7),true);
        abort_unless(is_string($key)&&strlen($key)>=32,503,self::ERROR);
        $binding=[];
        foreach (['workspace_id','command_id','actor_id','root_fingerprint','manifest_revision','grant_hash',
            'request_hash','proof_sha256','accepted_database'] as $field) {
            abort_unless(isset($row[$field]),503,self::ERROR);
            $binding[$field]=in_array($field,['actor_id','manifest_revision'],true)?(string)$row[$field]:$row[$field];
        }
        return hash_hmac('sha256','desktop-workspace-command-v2\n'.DesktopDashboardWorkspaceAuthority::canonical($binding),$key);
    }
}
