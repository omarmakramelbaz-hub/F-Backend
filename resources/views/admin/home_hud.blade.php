{{-- Live opening; data comes from authorized, read-only dashboard metrics. --}}
@php($goHome = isset($boot['initial']['owner_drawer']))
<section id="go-hud" class="go-hud{{ $goHome ? ' hud-v2' : '' }}" dir="rtl" lang="ar" role="dialog" aria-modal="true" aria-label="مركز تشغيل GO" data-hud-commands="1" data-intro="{{ !empty($boot['intro']) ? '1' : '0' }}">
    <canvas class="hud-background" data-hud-background aria-hidden="true"></canvas>
    <div class="hud-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><circle cx="12" cy="7" r="4"/><path d="M3 23v-3a9 9 0 0 1 18 0v3Z"/></svg><strong data-hud-who>{{ auth('admin')->user()->name }}</strong></div>
    <div class="hud-figure" aria-hidden="true">
        @if($goHome)
        <div class="hud-artwork"><img data-hologram-artwork data-hologram-face='{"eyes":[[0.629,0.315,0.045,0.025],[0.768,0.335,0.040,0.024]],"lip":[0.680,0.462,0.052,0.022],"cavity":[0.038,0.007],"jaw":[0.10,0.45,0.50,0.53,0.58],"head":[0.52,0.66,0.42,0.50,0.88,0.95],"pivot":[0.68,0.58],"depth":[0.68,0.32,0.20,0.28],"emitter":[0.50,0.95],"motion":7,"holo":1}' src="{{ asset('dashboard/branding/go-assistant-holo-20261125.webp') }}" alt="" width="342" height="400" decoding="async" fetchpriority="high"></div>
        @else
        <div class="hud-artwork"><img data-hologram-artwork src="{{ asset('dashboard/branding/go-hologram-premium-20261110.webp') }}" alt="" width="1454" height="1082" decoding="async" fetchpriority="high"></div>
        @endif
        <canvas data-hud-robot></canvas>
        @if($goHome)
        <svg class="hv3-orbits" viewBox="0 0 342 400" preserveAspectRatio="xMidYMax meet" aria-hidden="true">
            <g class="hv3-orbit o1"><ellipse cx="205" cy="150" rx="150" ry="168"/></g>
            <g class="hv3-orbit o2"><ellipse cx="205" cy="150" rx="132" ry="152"/></g>
            <g class="hv3-orbit o3"><ellipse cx="205" cy="150" rx="168" ry="186"/></g>
            <g class="hv3-orbit o4"><ellipse cx="205" cy="150" rx="118" ry="140"/></g>
            <ellipse class="hv3-base b1" cx="171" cy="384" rx="150" ry="17"/>
            <ellipse class="hv3-base b2" cx="171" cy="384" rx="104" ry="11"/>
        </svg>
        @endif
    </div>
@if($goHome)
    {{-- The owner's home: the figures of the day, profit, the week's sales, the branches and what needs attention. --}}
    @php($go = function ($name, $parameters = []) { return \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : null; })
    @php($links = $boot['hud_links'] ?? [])
    <div class="hv3-user"><span><b>{{ auth('admin')->user()->name }}</b><small>المالك - Owner</small></span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10.5"/><circle cx="12" cy="9.5" r="3.6"/><path d="M5.5 19a7.5 7.5 0 0 1 13 0"/></svg></div>
    <button type="button" class="hv2-bell" data-hv2-bell aria-label="التنبيهات"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9 M10 21a2 2 0 0 0 4 0"/></svg><b data-hv2-bell-count hidden></b></button>
    <label class="hv3-branch"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l2-6h14l2 6 M3 9v12h18V9 M3 9h18 M9 21v-6h6v6"/></svg><span class="sr-only">الفرع</span><select data-hv3-branch aria-label="الفرع"><option value="">جميع الفروع</option>@foreach(($boot['initial']['branches'] ?? []) as $branch)<option value="{{ $branch['value'] }}">{{ $branch['name'] }}</option>@endforeach</select></label>
    <div class="hv2-date"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v16H3z M3 10h18 M8 3v4 M16 3v4 M7 14h2 M11 14h2 M15 14h2 M7 18h2 M11 18h2"/></svg><span><b data-hv2-day></b><small data-hv2-time></small></span></div>
    <section class="hv3-summary" aria-labelledby="hv3-summary-title"><h2 id="hv3-summary-title">ملخص أداء اليوم في فسخانستا:</h2><ul data-hv3-summary></ul></section>
    <div class="hv2-kpis" aria-label="أرقام اليوم">
        <a class="hv2-kpi is-expenses" data-hv2-kpi="expenses" href="{{ $links['expenses'] ?? '#' }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h18v13H3z M16 13h5 M3 7l3-4h12l3 4"/></svg><span>المصروفات</span><b>—</b><small></small></a>
        <a class="hv2-kpi is-profit" data-hv2-kpi="profit" href="{{ $go('branch-stock.index') ? $go('branch-stock.index').'#profit' : '#' }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21h18 M5 21v-5 M9 21v-8 M13 21v-6 M17 21V9 M4 12l5-5 4 3 7-7 M15 3h5v5"/></svg><span>صافي الربح</span><b>—</b><small></small></a>
        <a class="hv2-kpi is-sales" data-hv2-kpi="sales" href="{{ $links['sales'] ?? '#' }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6c0-2 16-2 16 0s-16 2-16 0 M4 6v4c0 2 16 2 16 0V6 M4 10v4c0 2 16 2 16 0v-4 M4 14v4c0 2 16 2 16 0v-4"/></svg><span>مبيعات اليوم</span><b>—</b><small></small></a>
        <a class="hv2-kpi is-orders" data-hv2-kpi="orders" href="{{ $links['sales'] ?? '#' }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6 M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2 M18 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2"/></svg><span>طلبات اليوم</span><b>—</b><small></small></a>
    </div>
@endif
    <nav class="hud-columns" aria-label="أقسام الداشبورد"><div class="hud-column hud-column-right" data-hud-right></div><div class="hud-column hud-column-left" data-hud-left></div></nav>
@if($goHome)
    <div class="hv2-panels">
        <section class="hv2-panel hv2-alerts" id="hv2-alerts" tabindex="-1" aria-labelledby="hv2-alerts-title"><h2 id="hv2-alerts-title"><span class="hv3-bell-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9 M10 21a2 2 0 0 0 4 0"/></svg><b data-hv3-alert-count hidden></b></span>التنبيهات الذكية</h2><ul data-hv2-alerts class="hv2-alert-list"></ul></section>
        <section class="hv2-panel hv2-branches" aria-labelledby="hv2-branches-title"><h2 id="hv2-branches-title"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l2-6h14l2 6 M3 9v12h18V9 M3 9h18 M9 21v-6h6v6"/></svg>أداء الفروع اليوم</h2><ol data-hv2-branches class="hv3-store-list" data-store-art="{{ asset('dashboard/branding/go-store-1-20261125.webp') }}|{{ asset('dashboard/branding/go-store-2-20261125.webp') }}|{{ asset('dashboard/branding/go-store-3-20261125.webp') }}|{{ asset('dashboard/branding/go-store-4-20261125.webp') }}"></ol></section>
        <section class="hv2-panel hv2-chart" aria-labelledby="hv2-chart-title"><h2 id="hv2-chart-title"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21h18 M5 21v-5 M9 21v-8 M13 21v-6 M17 21V9 M4 12l5-5 4 3 7-7"/></svg>المبيعات خلال آخر 7 أيام</h2><div data-hv2-chart class="hv2-chart-box"></div></section>
    </div>
    <div class="hv3-lower">
        <section class="hv2-panel hv3-drawers" aria-labelledby="hv3-drawers-title"><h2 id="hv3-drawers-title"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10h18v11H3z M3 15h18 M10 12.5h4 M10 18h4 M6 10V5h8v5 M8 7h4"/></svg>أرصدة الأدراج</h2><div class="hv3-drawers-body"><dl data-hv3-drawers><dt>جاري التحميل…</dt></dl><img src="{{ asset('dashboard/branding/go-drawer-20261125.webp') }}" alt="" width="124" height="94" loading="lazy"></div></section>
        <section class="hv2-panel hv3-attendance" aria-labelledby="hv3-attendance-title"><h2 id="hv3-attendance-title"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 8a4 4 0 1 0 0-.1 M2 21v-1a7 7 0 0 1 14 0v1 M17 4a4 4 0 0 1 0 8 M22 21v-1a7 7 0 0 0-4-6"/></svg>الموظفون والحضور</h2><div class="hv3-attendance-body" data-hv3-attendance><p class="hv3-muted">جاري التحميل…</p></div></section>
        <section class="hv2-panel hv3-stock" aria-labelledby="hv3-stock-title"><h2 id="hv3-stock-title"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 9 5v10l-9 5-9-5V7z M3 7l9 5 9-5 M12 12v10"/></svg>حالة المخزون</h2><div class="hv3-stock-body" data-hv3-stock><p class="hv3-muted">جاري التحميل…</p></div></section>
        <section class="hv2-panel hv3-top" aria-labelledby="hv3-top-title"><h2 id="hv3-top-title"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 3 6.5 7 .8-5.2 4.8 1.5 7L12 17.6 5.7 21l1.5-7L2 9.3l7-.8z"/></svg>أكثر الأصناف مبيعًا اليوم</h2><ol data-hv3-top class="hv3-top-list"><li class="hv3-muted">جاري التحميل…</li></ol></section>
    </div>
    <div class="hv2-actions">
        <details class="hv3-quick"><summary class="hv2-action is-quick"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v16 M4 12h16"/></svg><span>إضافة سريع</span></summary><div class="hv3-quick-menu">@if(!empty($links['ch-takeaway']))<a href="{{ $links['ch-takeaway'] }}">طلب تيك أواي</a>@endif @if(!empty($links['expenses']))<a href="{{ $links['expenses'] }}">مصروف</a>@endif @if(!empty($links['stock']))<a href="{{ $links['stock'] }}">حركة بضاعة</a>@endif @if(!empty($links['employees']))<a href="{{ $links['employees'] }}">موظف</a>@endif</div></details>
        <button type="button" class="hv2-action is-analysis" data-hud-enter><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21h18 M6 17v-4 M10 17V9 M14 17v-6 M18 17V6"/></svg><span>تحليل الأداء</span></button>
        @if($go('reports.index'))<a class="hv2-action" href="{{ $go('reports.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6z M14 2v6h6 M9 13h8 M9 17h8"/></svg><span>تقارير جاهزة</span></a>@endif
        @if(!empty($links['ch-app']))<a class="hv2-action" href="{{ $links['ch-app'] }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v17H4z M8 2v4 M16 2v4 M4 9h16 M8.5 15l2.5 2.5 4.5-5"/></svg><span>مهام اليوم</span></a>@endif
        @if(!empty($links['stock']))<a class="hv2-action is-forecast" href="{{ $links['stock'] }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9 M12 7a5 5 0 1 0 5 5 M12 11a1 1 0 1 0 1 1 M12 12l7-7 M16 4h4v4"/></svg><span>توقعات ذكية</span></a>@endif
        <button type="button" class="hv2-action is-voice" data-hv2-voice><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14v-2a8 8 0 0 1 16 0v2 M4 14h3v6H4z M17 14h3v6h-3z M17 20a5 5 0 0 1-5 2"/></svg><span>تحدث مع GO</span></button>
        <span class="hv3-sep" aria-hidden="true"></span>
        <a class="hv2-action is-logout" href="{{ url('/admin/adminLogout') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v10 M18.4 6.6a9 9 0 1 1-12.8 0"/></svg><span>خروج</span></a>
        <a class="hv2-action is-home" href="{{ url('/admin/dashboard') }}" aria-current="page"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 9-8 9 8M5 10v11h14V10M9 21v-7h6v7"/></svg><span>الرئيسية</span></a>
    </div>
@else
    <button class="hud-enter" data-hud-enter type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 9-8 9 8M5 10v11h14V10M9 21v-7h6v7"/></svg><span>الدخول للرئيسية</span></button>
@endif
    <span class="hud-brand">@include('admin.partials.go_hud_mark')</span>
    <div class="hud-live" role="status"><span data-hud-scope></span><span data-hud-freshness></span></div>
    <button type="button" class="hud-voice" data-hud-voice hidden aria-label="كتم الترحيب">كتم الترحيب</button>
    <p class="hud-announcement" data-hud-announcement aria-live="polite"></p>
</section>
