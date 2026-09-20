<?php

namespace App\Http\Middleware;

use Closure;

class AppScopeGuard
{
    public function handle($request, Closure $next)
    {
        $user = auth('api')->user();
        if (!$user) {
            return $next($request);
        }

        $requestedScope = $request->header('X-App-Scope');
        $expectedScope = $requestedScope === 'go'
            ? 'go'
            : ($requestedScope === 'go_partner' ? 'go_partner' : 'fasakhansta');

        $userScope = $user->app_scope ?: 'fasakhansta';

        // Existing delivery delegates pre-date GO Partners. Keep them working
        // during the transition while every new professional uses go_partner.
        $legacyGoPartnerDelegate = $expectedScope === 'go_partner'
            && $user->account_type === 'delegate'
            && $userScope === 'fasakhansta';

        if ($userScope !== $expectedScope && !$legacyGoPartnerDelegate) {
            return response()->json([
                'message' => 'هذه الجلسة تخص تطبيقاً آخر. سجل الدخول إلى التطبيق الحالي مرة أخرى.',
            ], 401);
        }

        return $next($request);
    }
}
