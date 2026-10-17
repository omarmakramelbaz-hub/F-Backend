<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Auth;
class GuestAdmin
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
            $user = Auth::guard('admin')->user();
            // Rebuild branch context when a remembered login creates a fresh session.
            session()->put('id_user', $user->id);
            session()->put('guard', 'admin');
            if($user->account_type=='admin'){
            return redirect('admin/dashboard');
           }elseif($user->account_type=='vendor'){
               return redirect('admin/applies-orders');
           }
           elseif($user->account_type=='resturant_owner'){
               return redirect('admin/resturants');
           }
           
           
            // return redirect(url('/admin/dashboard'));
        }else{
            return $next($request);
        }
    }
}
