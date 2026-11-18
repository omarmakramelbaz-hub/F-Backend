<?php
namespace App\Http\Middleware;

use App\Services\Dashboard\{DesktopDashboardWorkspaceReadAuthority,DesktopDashboardWorkspaceReadRoutes};
use Closure;

/** Before binding/querying an unsupported local row; actual targets are checked again after binding. */
final class DesktopDashboardWorkspaceReadGate
{
    public function handle($request,Closure $next)
    {
        $authority=app(DesktopDashboardWorkspaceReadAuthority::class);$route=$request->route();
        if(!$authority->selected() || !$route || !DesktopDashboardWorkspaceReadRoutes::isRead($route))return $next($request);
        $policy=DesktopDashboardWorkspaceReadRoutes::classify($route);
        abort_unless($policy!==null,503,'هذه الصفحة تحتاج مراجعة صلاحيات النسخة المحلية.');
        abort_if(in_array($policy,['unavailable','connected-only','outside-desktop'],true),501,'هذه الصفحة تحتاج الداشبورد المتصل.');
        return $next($request);
    }
}
