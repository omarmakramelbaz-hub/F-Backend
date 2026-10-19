<?php

namespace App\Http\Middleware;

use Closure;

class DashboardSessionCookies
{
    public function handle($request, Closure $next)
    {
        // Keep ordinary dashboard sessions in this browser session. Only the
        // explicit "remember me" authentication cookie survives a browser close.
        if ($request->is('admin') || $request->is('admin/*')) {
            config([
                'session.expire_on_close' => true,
                'session.http_only' => true,
                'session.secure' => $request->isSecure() || (bool) config('session.secure'),
            ]);
            // The cookie service can already have been resolved by another
            // middleware; update its defaults before it issues a remember cookie.
            app('cookie')->setDefaultPathAndDomain(
                config('session.path'), config('session.domain'),
                config('session.secure'), config('session.same_site', 'lax')
            );
        }

        return $next($request);
    }
}
