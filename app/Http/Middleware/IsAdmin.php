<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Auth;
class IsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle($request, Closure $next, $guard = null) {
        if (Auth::guard('admin')->check()) {
            session()->put('guard', 'admin');
            session()->put('id_user', Auth::guard('admin')->id());
            if(config('desktop_dashboard.local')===true&&getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')==='true')
                return app(\App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority::class)
                    ->run($request,fn()=>$next($request));
            return $next($request);
        }else{
            // A page's background request (polling, saving) whose session ended: a short answer the page recognises
            // (it opens the sign-in page once), instead of rendering the sign-in page for every poll.
            if($request->expectsJson()||$request->ajax())
                return response()->json(['success'=>false,'message'=>'انتهت الجلسة. سجّل الدخول مرة أخرى.'],401)->header('X-GO-Session','expired');
            return redirect(url('admin/login'));
        }
    }
}
