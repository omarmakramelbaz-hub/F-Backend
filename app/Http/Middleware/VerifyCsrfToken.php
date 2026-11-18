<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Session\TokenMismatchException;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        "admin/update-coupon-status",
    ];

    /**
     * A sign-in from a sign-in page that is no longer current (its session ended meanwhile) opens the sign-in page
     * again, with a word on why, instead of the bare «419 PAGE EXPIRED»: nothing was done, and signing in again works.
     */
    public function handle($request, Closure $next)
    {
        try {
            return parent::handle($request, $next);
        } catch (TokenMismatchException $error) {
            if (!$request->isMethod('POST') || $request->path() !== 'admin/signin' || $request->expectsJson()) throw $error;
            return redirect('admin/login')->withInput($request->only('email', 'remember'))
                ->with('error', 'انتهت صلاحية صفحة الدخول. سجّل الدخول مرة أخرى.');
        }
    }
}
