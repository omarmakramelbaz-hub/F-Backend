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
    <style>
        /* Signing in through the GO opening (home-hud.css), as in the GO ERP app: its background, the live hologram on one
           side, and the form as one of its cards on the other. */
        .login-page.dashboard-login-page.hud-login{position:relative;isolation:isolate;display:flex;align-items:center;justify-content:flex-start;min-height:100vh;min-height:100dvh;padding:3vh 6vw;overflow:auto;color:#e9f7ff;background:radial-gradient(ellipse at 50% 53%,#062331 0%,#03131f 39%,#010b14 76%)}
        .hud-backdrop{position:fixed;inset:0;z-index:-1;pointer-events:none}
        .hud-backdrop-lines{position:absolute;inset:0;width:100%;height:100%;opacity:.65}
        .hud-backdrop-figure{position:absolute;top:0;bottom:0;left:0;width:58%}
        html[dir="ltr"] .hud-backdrop-figure{left:auto;right:0}
        .hud-backdrop-figure canvas{display:block;width:100%;height:100%}
        .hud-backdrop-still{position:absolute;top:50%;left:50%;width:min(360px,60%);transform:translate(-50%,-50%);filter:drop-shadow(0 0 18px #41dfff55)}
        .hud-backdrop-still svg,.hud-backdrop-brand svg{display:block;width:100%;height:auto}
        .hud-backdrop-brand{position:absolute;left:3%;bottom:5%;width:54px;filter:drop-shadow(0 0 4px #41dfff66)}
        .hud-backdrop-tagline{position:absolute;left:50%;bottom:1.6%;transform:translateX(-50%);margin:0;white-space:nowrap;font-size:10px;letter-spacing:.08em;color:#73939f}
        .hud-login .dashboard-login{display:block;width:min(430px,100%);padding:0;border:0;border-radius:0;background:none;box-shadow:none}
        .hud-login .dashboard-login-form-panel{padding:30px 30px 22px;border:1px solid #13bcd1a6;border-radius:14px;background:linear-gradient(125deg,#042031e0,#01111bf0);box-shadow:inset 0 1px 0 #c8f7ff0d,0 24px 70px #000a;backdrop-filter:blur(4px)}
        .hud-login .dashboard-login-eyebrow{color:#ffa322}
        .hud-login .dashboard-login-form-panel h1{color:#f1f9ff}
        .hud-login .dashboard-login-description{color:#98b8c8;margin-bottom:24px}
        .hud-login .dashboard-login-field>label,.hud-login .dashboard-login-remember{color:#dcfaff}
        .hud-login .dashboard-login-remember input{accent-color:#28def5}
        .hud-login .dashboard-login-input-wrap{border-color:#13bcd17a;background:#021723cc}
        .hud-login .dashboard-login-input-wrap:focus-within{border-color:#28def5;box-shadow:0 0 0 3px #28def51f}
        .hud-login .dashboard-login-input-wrap>i,.hud-login .dashboard-password-toggle{color:#30e5f8}
        .hud-login .dashboard-login-input-wrap>input{background:transparent;color:#eef9ff}
        .hud-login .dashboard-login-input-wrap>input:focus,.hud-login .dashboard-login-input-wrap>input:focus-visible{outline:none;box-shadow:none}
        .hud-login .dashboard-login-input-wrap>input::placeholder{color:#6f93a3}
        .hud-login .dashboard-login-input-wrap>input:-webkit-autofill{-webkit-text-fill-color:#eef9ff;box-shadow:inset 0 0 0 1000px #021723}
        .hud-login .dashboard-login-submit{border:1px solid #1bd2f1;border-radius:12px;background:linear-gradient(#062134,#020f1c);box-shadow:inset 0 1px 0 #d6fcff22,0 6px 22px #0007;color:#f1f9ff;font-weight:400;font-size:16px}
        .hud-login .dashboard-login-submit>i{color:#ffa322}
        .hud-login .dashboard-login-submit:hover{background:#073148;border-color:#1bd2f1;box-shadow:0 0 20px #1bd2f122}
        @media (max-width:900px){.login-page.dashboard-login-page.hud-login{justify-content:center}.hud-backdrop-figure{width:100%;opacity:.35}}
    </style>
</head>
@php $isArabic = app()->getLocale() === 'ar'; @endphp
@php $goMark = view()->exists('admin.partials.go_hud_mark') ? 'admin.partials.go_hud_mark' : null; @endphp
<body class="login-page dashboard-login-page hud-login">
    <div class="hud-backdrop" aria-hidden="true" data-login-hud>
        <canvas class="hud-backdrop-lines" data-login-lines></canvas>
        {{-- The visible GO mark comes first: the hidden one below uses its colour gradient. --}}
        @if($goMark)<span class="hud-backdrop-brand">@include($goMark)</span>@endif
        <div class="hud-backdrop-figure">
            <canvas data-login-hologram></canvas>
            <span class="hud-backdrop-still" data-login-still hidden>@if($goMark)@include($goMark)@else @include('admin.partials.go_erp_logo', ['logoClass' => 'dashboard-login-logo go-erp-platform-logo'])@endif</span>
        </div>
        <p class="hud-backdrop-tagline" lang="en" dir="ltr">THE INTELLIGENT SYSTEM FOR SALES, INVENTORY AND HR</p>
    </div>
    <main class="dashboard-login" aria-labelledby="dashboard-login-title">

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
    @if(is_file(public_path('dashboard/js/go-hologram.js')))
    <script src="{{ asset('dashboard/js/go-hologram.js') }}?v={{ filemtime(public_path('dashboard/js/go-hologram.js')) }}"></script>
    @endif
    <script>
        // The opening's live background and hologram (the same engine); without WebGL the GO mark stands in for it.
        (function () {
            const lines = document.querySelector('[data-login-lines]');
            const figure = document.querySelector('[data-login-hologram]');
            const still = document.querySelector('[data-login-still]');
            let robot = null;
            try { robot = window.GoHologram ? window.GoHologram.mount(figure, { background: false, interactive: false }) : null; } catch (error) { robot = null; }
            if (!robot && figure && still) { figure.hidden = true; still.hidden = false; }
            const reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
            let frame = 0, last = 0;
            function draw(now) {
                frame = 0;
                if (!lines || document.hidden) return;
                if (!last || now - last > 50) {
                    last = now;
                    const w = lines.clientWidth, h = lines.clientHeight, t = reduce && reduce.matches ? 0 : now / 1000;
                    if (lines.width !== w || lines.height !== h) { lines.width = w; lines.height = h; }
                    const c = lines.getContext('2d');
                    c.clearRect(0, 0, w, h); c.lineWidth = .7;
                    for (let i = 0; i < 18; i++) {
                        const fromLeft = i % 2 === 0, x0 = fromLeft ? -20 : w + 20, y0 = (i / 18) * h, y1 = h * .54 + Math.sin(t * .25 + i) * h * .03;
                        const x1 = w * (fromLeft ? .36 : .64), x2 = w * (fromLeft ? .25 : .75), y2 = y0 + Math.sin(i * 2 + t * .14) * h * .16;
                        c.strokeStyle = 'rgba(37,189,225,.15)'; c.beginPath(); c.moveTo(x0, y0); c.bezierCurveTo(x1, y2, x2, y1, w * .5, h * .7); c.stroke();
                        const p = (t * .025 + i / 18) % 1, q = 1 - p;
                        const x = q*q*q*x0 + 3*q*q*p*x1 + 3*q*p*p*x2 + p*p*p*w*.5, y = q*q*q*y0 + 3*q*q*p*y2 + 3*q*p*p*y1 + p*p*p*h*.7;
                        c.fillStyle = i % 7 === 0 ? '#ffb63a' : '#55dff3'; c.globalAlpha = .45; c.beginPath(); c.arc(x, y, 1.4, 0, 6.283); c.fill(); c.globalAlpha = 1;
                    }
                }
                if (!reduce || !reduce.matches) frame = requestAnimationFrame(draw);
            }
            function wake() { if (!frame && !document.hidden) { last = 0; frame = requestAnimationFrame(draw); } }
            document.addEventListener('visibilitychange', wake);
            wake();
            window.addEventListener('pagehide', function (event) { if (!event.persisted && robot) { robot.destroy(); robot = null; } });
        }());
    </script>
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
