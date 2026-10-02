<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\Access;
use App\Services\Erp\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function login()
    {
        abort_unless(config('erp.enabled'), 404);
        abort_unless(Access::ready(), 503, 'يلزم تجهيز قاعدة بيانات ERP قبل الدخول.');
        if (Access::actor()) { return redirect()->route('erp.home'); }
        return view('erp.login');
    }

    public function signin(Request $request)
    {
        abort_unless(Access::ready(), 404);
        $data = $request->validate(['email' => 'required|email|max:190', 'password' => 'required|string|max:200']);
        $email = mb_strtolower(trim($data['email']));
        $key = 'erp-login:'.hash('sha256', $request->ip().'|'.$email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'محاولات كثيرة؛ حاول بعد دقيقة.'])->withInput(['email' => $email]);
        }
        RateLimiter::hit($key, 60);
        if (!Auth::guard('erp')->attempt(['email' => $email, 'password' => $data['password'], 'active' => true, 'role' => Actor::ROLES])) {
            return back()->withErrors(['email' => 'بيانات الدخول غير صحيحة أو الحساب غير متاح؛ راجع المالك.'])->withInput(['email' => $email]);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        return redirect()->route('erp.home');
    }

    public function logout(Request $request)
    {
        Auth::guard('erp')->logout();
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('erp.login');
    }
}
