<?php

namespace App\Http\Middleware;

use App\Services\Erp\Access;
use Closure;
use Illuminate\Support\Facades\View;

class ErpAccess
{
    public function handle($request, Closure $next)
    {
        abort_unless(config('erp.enabled'), 404);
        abort_unless(Access::ready(), 503, 'يلزم تجهيز قاعدة بيانات ERP قبل الدخول.');
        $actor = Access::actor();
        if (!$actor) { return config('erp.standalone_auth', false) ? redirect()->route('erp.login') : redirect('/admin/login'); }
        if (!$actor->allBranches()) { $actor->branch($actor->branchId, true); }
        $request->attributes->set('erp_actor', $actor);
        View::share('actor', $actor);
        return $next($request);
    }
}
