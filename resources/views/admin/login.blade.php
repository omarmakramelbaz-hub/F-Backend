<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <!-- FASAKHANSTA SEO AUTH NOINDEX START -->
    <meta name="robots" content="noindex, nofollow">
    <!-- FASAKHANSTA SEO AUTH NOINDEX END -->
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $settings->site_name }}</title>
    <link rel="icon" type="image/png" href="{{ asset('dashboard/branding/fasakhansta-logo-transparent.png') }}">
    <link rel="stylesheet" href="{{ url('dashboard') }}/plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="{{ url('dashboard') }}/dist/css/adminlte.min.css">
    @if(!config('desktop_dashboard.local'))
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @endif
    <link href="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://fonts.googleapis.com/css2?family=Almarai:wght@400;700&display=swap') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('dashboard/branding/dashboard-brand.css') }}?v=20261017-go-solar-1">
</head>
@php $isArabic = app()->getLocale() === 'ar'; @endphp
<body class="login-page dashboard-login-page">
    <main class="dashboard-login" aria-labelledby="dashboard-login-title">
        <section class="dashboard-login-identity" aria-label="{{ $isArabic ? 'فسخانستا وجو' : 'Fasakhansta and GO' }}">
            <div class="dashboard-login-brand">
                @include('admin.partials.go_erp_logo', ['logoClass' => 'dashboard-login-logo go-erp-platform-logo'])
            </div>
            <p class="dashboard-login-brand-title go-erp-tagline" lang="en" dir="ltr">THE INTELLIGENT SYSTEM FOR SALES, INVENTORY AND HR</p>
            <p class="dashboard-login-brand-subtitle">{{ $isArabic ? 'لوحة واحدة لإدارة التطبيقات والطلبات' : 'One dashboard for your applications and orders' }}</p>
        </section>

        <section class="dashboard-login-form-panel">
            <span class="dashboard-login-eyebrow">{{ $isArabic ? 'لوحة التحكم' : 'Dashboard' }}</span>
            <h1 id="dashboard-login-title">@lang('main.sign in to dashboard')</h1>
            <p class="dashboard-login-description">{{ $isArabic ? 'أدخل بيانات حسابك للمتابعة' : 'Enter your account details to continue' }}</p>

            @if(count($errors))
                <div class="dashboard-login-alert dashboard-login-alert-error" role="alert">
                    <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if(Session::has('error'))
                <div class="dashboard-login-alert dashboard-login-alert-error" role="alert">{{ Session::get('error') }}</div>
            @endif
            @if(Session::has('success'))
                <div class="dashboard-login-alert dashboard-login-alert-success" role="status">{{ Session::get('success') }}</div>
            @endif

            <form class="dashboard-login-form" action="{{ route('admin.login') }}" method="post" autocomplete="off" data-dashboard-login>
                @csrf
                <div class="dashboard-login-field">
                    <label for="dashboard-login-email">{{ $isArabic ? 'البريد الإلكتروني أو رقم الموبايل' : 'Email or mobile number' }}</label>
                    <div class="dashboard-login-input-wrap">
                        <i class="fas fa-user" aria-hidden="true"></i>
                        <input id="dashboard-login-email" type="text" name="email" value="{{ old('email') }}" autocomplete="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore placeholder="{{ $isArabic ? 'أدخل بريدك الإلكتروني أو رقم الموبايل' : 'Enter your email or mobile number' }}" required @error('email') aria-invalid="true" @enderror>
                    </div>
                </div>

                <div class="dashboard-login-field">
                    <label for="dashboard-login-password">{{ $isArabic ? 'كلمة المرور' : 'Password' }}</label>
                    <div class="dashboard-login-input-wrap">
                        <i class="fas fa-lock" aria-hidden="true"></i>
                        <input id="dashboard-login-password" type="password" name="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="@lang('main.enter password')" required @error('password') aria-invalid="true" @enderror>
                        <button class="dashboard-password-toggle" type="button" data-password-toggle aria-controls="dashboard-login-password" aria-label="{{ $isArabic ? 'إظهار كلمة المرور' : 'Show password' }}" aria-pressed="false" data-show-label="{{ $isArabic ? 'إظهار كلمة المرور' : 'Show password' }}" data-hide-label="{{ $isArabic ? 'إخفاء كلمة المرور' : 'Hide password' }}">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <label class="dashboard-login-remember" for="remember">
                    <input type="checkbox" id="remember" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                    <span>@lang('main.remember me')</span>
                </label>

                <button class="dashboard-login-submit" type="submit">
                    <span>@lang('main.login')</span>
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                </button>
            </form>
        </section>
    </main>
    <script>
        (function () {
            const form = document.querySelector('[data-dashboard-login]');
            const username = document.getElementById('dashboard-login-email');
            const usernameKey = 'fasakhansta.dashboard.username';
            const toggle = document.querySelector('[data-password-toggle]');
            const password = document.getElementById('dashboard-login-password');
            if (!form || !username || !toggle || !password) return;

            // Persist only the account name. Authentication stays in HttpOnly cookies.
            try {
                if (!username.value) username.value = localStorage.getItem(usernameKey) || '';
            } catch (error) { /* Login also works when browser storage is unavailable. */ }
            form.addEventListener('submit', function () {
                try {
                    const value = username.value.trim();
                    if (value) localStorage.setItem(usernameKey, value);
                    else localStorage.removeItem(usernameKey);
                } catch (error) { /* Do not block authentication. */ }
            });

            function clearPassword() {
                password.value = '';
                password.type = 'password';
                toggle.setAttribute('aria-pressed', 'false');
                toggle.setAttribute('aria-label', toggle.dataset.showLabel);
                toggle.querySelector('i').className = 'fas fa-eye';
            }
            clearPassword();
            window.addEventListener('pageshow', clearPassword);
            window.addEventListener('pagehide', clearPassword);
            toggle.addEventListener('click', function () {
                const visible = password.type === 'password';
                password.type = visible ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
                toggle.setAttribute('aria-label', visible ? toggle.dataset.hideLabel : toggle.dataset.showLabel);
                toggle.querySelector('i').className = visible ? 'fas fa-eye-slash' : 'fas fa-eye';
            });
        }());
    </script>
</body>
</html>
