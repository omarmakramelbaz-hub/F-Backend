<?php
namespace App\Http\Middleware;

use App\Services\OwnerDashboards\Runtime;
use Closure;

final class OwnerDashboardGuard
{
    public function handle($request,Closure $next)
    {
        if(!Runtime::tenant()){
            abort_if(preg_match('/^go-[0-9a-f]{16}\./D',strtolower($request->getHost())),404);
            return $next($request);
        }
        $r=Runtime::facts();
        abort_if($request->is('clear-cache-admin'),403);
        abort_unless($r['state']==='active'&&!Runtime::blocked($r['id']),403,'هذه اللوحة موقوفة مؤقتًا.');
        $response=$next($request);
        $response->headers->set('Cache-Control','private, no-store');
        $response->headers->set('X-Content-Type-Options','nosniff');
        return $response;
    }
}
