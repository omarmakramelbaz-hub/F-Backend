<?php
namespace App\Http\Middleware;

use Closure;

/** Disabled before dependency resolution; installing source alone cannot activate protocol two. */
final class DesktopDashboardWorkspaceEnabled
{
    public function handle($request,Closure $next)
    {
        abort_unless(config('desktop_dashboard.workspace_enabled')===true,503,'التجهيز المشترك للجهاز لم يُفعّل بعد.');
        return $next($request);
    }
}
