<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Consistent protocol-two snapshot. Custody is audit data; each member remains its own actor. */
final class DesktopDashboardWorkspaceBootstrap extends DesktopDashboardBootstrap
{
    public function __construct(DesktopDashboardDevices $devices,DesktopDashboardData $legacy,
        private DesktopDashboardWorkspaceAuthority $authority,private DesktopDashboardWorkspaceData $workspaceData,
        private DesktopDashboardWorkspaceMedia $workspaceMedia)
    {
        parent::__construct($devices,$legacy);
    }

    public function export(object $root): array
    {
        $this->authority->ready();
        abort_unless(DB::transactionLevel()===0,409,'تجهيز مساحة عمل الجهاز يحتاج معاملة مستقلة.');
        $source=app(DesktopDashboardSource::class)->fingerprint();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return DB::transaction(function()use($root,$source){
            $audience=$this->authority->snapshotAudience($root);$fresh=$audience->root();
            $manifest=$audience->manifest();$actors=$audience->actors();$this->memoryBudget();
            $branches=[];foreach($manifest['members'] as $member)$branches=array_merge($branches,$member['branches']);
            $branches=array_values(array_unique($branches));sort($branches,SORT_STRING);
            $queries=$this->workspaceData->queries($audience);$dataset=$this->workspaceData->rows($queries);
            $snapshot=(string)Str::uuid();
            $media=$this->workspaceMedia->manifestForAudience($dataset,$audience,$snapshot);
            $roleMemberships=app(DesktopDashboardRoleFacts::class)->manifest($dataset['roles']??[]);
            $eligibility=$this->workspaceData->pendingEligibility($queries,$dataset);
            // This is a real member model, not a promoted/fabricated custodian.
            // The Owner's balance may be cached only when the Owner is a member.
            $defaultActor=$actors[1]??reset($actors);
            [$tables,$schemaHash]=$this->transportTables($dataset,$queries,function($table,$row,$columns)use($actors,$defaultActor){
                $actor=$table==='users'?($actors[(int)($row['id']??0)]??$defaultActor):$defaultActor;
                return $this->redactRecord($table,$row,$columns,$actor);
            });
            // The codec verifies every preserved password against the signed
            // roster binding. It also normalizes all nonmember verifier markers.
            $codec=new DesktopDashboardWorkspaceDeltaCodec($manifest);
            abort_unless(isset($tables['users']),409);
            $codec->index('users',$tables['users']['rows'],$tables['users']['ddl']);
            $userCounts=[];foreach($tables['users']['rows'] as $row){$id=(int)($row['id']??0);$userCounts[$id]=($userCounts[$id]??0)+1;}
            foreach($manifest['members'] as $member)abort_unless(($userCounts[$member['actor_id']]??0)===1,409,'بيانات حساب في مساحة عمل الجهاز مفقودة أو مكررة.');
            $this->authority->assertSnapshotAudience($audience);
            abort_unless(app(DesktopDashboardSource::class)->matches($source),409,'مصدر البرنامج تغير أثناء التجهيز؛ أعد المحاولة بعد اكتمال التحديث.');
            $coverage=['write_routes'=>array_merge(DesktopDashboardRoutes::WRITES,array_keys(DesktopDashboardLegacy::ROUTES)),
                'role_memberships'=>$roleMemberships,'full_dashboard'=>false,'print_protocol'=>1,'catalog_scopes'=>0,'media'=>$media['complete'],
                'workspace_protocol'=>2,'manifest_revision'=>$manifest['revision'],'roster_sha256'=>$manifest['roster_sha256'],
                'root_fingerprint'=>$manifest['root_fingerprint']];
            if($eligibility!==null)$coverage['pending_vendor_eligibility']=$eligibility;
            return ['format'=>2,'kind'=>'initial-workspace-data','snapshot_id'=>$snapshot,'device_id'=>$fresh->id,
                'actor_id'=>(int)$fresh->custodian_actor_id,'branches'=>$branches,
                'enrollment'=>['enrollment_hash'=>$fresh->enrollment_hash,'enrolled_at'=>(string)$fresh->enrolled_at,'base_branches'=>$branches],
                'created_scopes'=>[],'generated_at'=>now('UTC')->toIso8601String(),'source'=>$source,
                'transport_php'=>['capture_scope'=>'snapshot_export','php_sapi'=>PHP_SAPI,'php_version'=>PHP_VERSION,
                    'serialize_precision'=>(string)ini_get('serialize_precision'),'precision'=>(string)ini_get('precision')],
                'schema_hash'=>$schemaHash,'tables'=>$tables,'media'=>$media['files'],'media_issues'=>$media['issues'],'coverage'=>$coverage,
                'workspace_manifest'=>$manifest,'workspace_enrollment'=>['format'=>2,'workspace_id'=>$fresh->id,
                    'custodian_actor_id'=>(int)$fresh->custodian_actor_id,'enrollment_hash'=>$fresh->enrollment_hash,
                    'token_hash'=>$fresh->token_hash,'enrolled_at'=>(string)$fresh->enrolled_at]];
        });
    }
}
