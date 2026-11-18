<?php
namespace App\Services\Dashboard;

use App\Models\User;

/** Original module adapters keep their checks and additionally honor accepted workspace grants. */
final class DesktopDashboardWorkspaceModuleAuthority
{
    public function scope(object $scope,User $actor,array $permissions=[],?string $module=null): ?DesktopDashboardWorkspaceScope
    {
        $context=DesktopDashboardWorkspaceExecutionContext::current();
        if(!$scope instanceof DesktopDashboardWorkspaceScope){abort_if($context!==null,403);return null;}
        $scope->assertIssued();
        abort_unless($context!==null&&$context->id===$scope->id&&$context->actor_id===(int)$actor->id
            &&$scope->actor_id===(int)$actor->id&&hash_equals($context->rootFingerprint(),$scope->rootFingerprint()),403);
        $context->revalidated();$facts=$context->permissionFacts();
        foreach($permissions as$name)abort_unless($facts['super_admin']||in_array($name,$facts['names'],true),403);
        if($module!==null&&$context->isLocal())DesktopDashboardWorkspaceModuleCoverage::forScope($context,$module);
        return $context;
    }
}
