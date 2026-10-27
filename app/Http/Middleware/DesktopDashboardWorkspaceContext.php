<?php
namespace App\Http\Middleware;

use App\Services\Dashboard\{DesktopDashboardWorkspaceLocalAuthority,DesktopDashboardWorkspaceExecutionContext};
use Closure;

/** Actual password/remember session selects the actor; renderer headers cannot select a workspace identity. */
final class DesktopDashboardWorkspaceContext
{
    public function handle($request,Closure $next)
    {
        if(config('desktop_dashboard.local')!==true)return $next($request);
        $authority=app(DesktopDashboardWorkspaceLocalAuthority::class);
        if(!$authority->selected())return $next($request);
        // An explicit logout must still be able to revoke cookies for a retired member.
        if($request->route()?->getActionName()===\App\Http\Controllers\Dashboard\HomeController::class.'@adminLogout')
            return $next($request);
        $authority->active();
        $actor=auth('admin')->user();
        if($actor===null)return $next($request);
        $scope=$authority->currentScope($actor);
        return DesktopDashboardWorkspaceExecutionContext::run($scope,fn()=>$next($request));
    }
}
