<?php
namespace App\Services\Dashboard;

/** Physical print custody and the current actor are deliberately separate authorities. */
final class DesktopDashboardWorkspacePrintAuthority
{
    public function selected(): bool {return app(DesktopDashboardWorkspaceLocalAuthority::class)->selected();}
    public function root(string $device,bool $lock=true): object
    {
        $root=app(DesktopDashboardWorkspaceLocalAuthority::class)->physicalRoot($lock);
        abort_unless($root->id===$device,403);return $root;
    }
    public function facts(object $root): array
    {
        abort_unless(($root->workspace_protocol??null)===2,403);
        // This is exactly the server root fingerprint envelope. Branch/roster changes cannot adopt old effects.
        return ['format'=>2,'kind'=>'desktop-dashboard-workspace-root','workspace_id'=>$root->id,
            'custodian_actor_id'=>(int)$root->custodian_actor_id,'enrollment_hash'=>$root->enrollment_hash,
            'token_hash'=>$root->token_hash,'enrolled_at'=>(string)$root->enrolled_at];
    }
    public function identity(object $root): string {return hash('sha256',DesktopDashboardWorkspaceAuthority::canonical($this->facts($root)));}
    public function actor(string $device,int $actor,?string $branch=null,bool $write=true): DesktopDashboardWorkspaceScope
    {
        $scope=DesktopDashboardWorkspaceExecutionContext::current();
        abort_unless($scope && $scope->isLocal() && $scope->id===$device && $scope->actor_id===$actor,403);
        if($write)abort_unless(!DesktopDashboardWorkspaceExecutionContext::isReadOnly(),403);
        $scope=$scope->revalidated();$state=$scope->localState();abort_unless($state,503);
        if($write)abort_unless($state->state==='ready',409);
        if($branch!==null)abort_unless(in_array($branch,$scope->branchGrants(),true)
            &&in_array($branch,json_decode($state->branches,true,512,JSON_THROW_ON_ERROR),true),403);
        return $scope;
    }
}
