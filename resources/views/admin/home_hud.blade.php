{{-- Live opening; data comes from authorized, read-only dashboard metrics. --}}
@php($goHome = isset($boot['initial']['owner_drawer']))
<section id="go-hud" class="go-hud{{ $goHome ? ' hud-v2' : '' }}" dir="rtl" lang="ar" role="dialog" aria-modal="true" aria-label="مركز تشغيل GO" data-hud-commands="1" data-intro="{{ !empty($boot['intro']) ? '1' : '0' }}">
    <canvas class="hud-background" data-hud-background aria-hidden="true"></canvas>
    <div class="hud-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><circle cx="12" cy="7" r="4"/><path d="M3 23v-3a9 9 0 0 1 18 0v3Z"/></svg><strong data-hud-who>{{ auth('admin')->user()->name }}</strong></div>
    <div class="hud-figure" aria-hidden="true">
        @if($goHome)
        <div class="hud-artwork"><img src="{{ asset('dashboard/branding/go-head-20261125.webp') }}" alt="" width="310" height="340" decoding="async" fetchpriority="high"></div>
        @else
        <div class="hud-artwork"><img data-hologram-artwork src="{{ asset('dashboard/branding/go-hologram-premium-20261110.webp') }}" alt="" width="1454" height="1082" decoding="async" fetchpriority="high"></div>
        @endif
        @unless($goHome)<canvas data-hud-robot></canvas>@endunless
    </div>
@if($goHome)
    {{-- The owner's home: the figures of the day, profit, the week's sales, the branches and what needs attention. --}}
    @php($go = function ($name, $parameters = []) { return \Illuminate\Support\Facades\Route::has($name) ? route($name, $parameters) : null; })
    @php($links = $boot['hud_links'] ?? [])
    <nav class="hv2-side" aria-label="القائمة">
        <span class="hv2-side-link is-current" aria-current="page"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 9-8 9 8M5 10v11h14V10M9 21v-7h6v7"/></svg><span>الرئيسية</span></span>
        @foreach([
            ['ch-app', 'الطلبات', 'M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6 M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2 M18 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2'],
            ['sales', 'المبيعات', 'M3 3v18h18 M7 15l4-4 3 3 6-7'],
            ['go-stores', 'الفروع', 'M3 9l2-6h14l2 6 M3 9v12h18V9 M3 9h18 M9 21v-6h6v6'],
            ['stock', 'المخزون', 'm12 2 9 5v10l-9 5-9-5V7z M3 7l9 5 9-5 M12 12v10'],
            ['expenses', 'المصروفات', 'M3 7h18v13H3z M16 13h5 M3 7l3-4h12l3 4'],
            ['employees', 'الموظفون', 'M9 8a4 4 0 1 0 0-.1 M2 21v-1a7 7 0 0 1 14 0v1 M17 4a4 4 0 0 1 0 8 M22 21v-1a7 7 0 0 0-4-6'],
            ['customers', 'العملاء', 'M12 8a4 4 0 1 0 0-.1 M4 21v-1a8 8 0 0 1 16 0v1'],
        ] as [$key, $label, $path])
            @if(!empty($links[$key]))<a class="hv2-side-link" href="{{ $links[$key] }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $path }}"/></svg><span>{{ $label }}</span></a>@endif
        @endforeach
        @if($go('reports.index'))<a class="hv2-side-link" href="{{ $go('reports.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10 M10 20V4 M16 20v-7 M22 20H2"/></svg><span>التقارير</span></a>@endif
        <a class="hv2-side-link" href="{{ url('/admin/settings') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M12 2v3 M12 19v3 M4.9 4.9l2.1 2.1 M17 17l2.1 2.1 M2 12h3 M19 12h3 M4.9 19.1 7 17 M17 7l2.1-2.1"/></svg><span>الإعدادات</span></a>
        <a class="hv2-side-link hv2-logout" href="{{ url('/admin/adminLogout') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v10 M18.4 6.6a9 9 0 1 1-12.8 0"/></svg><span>خروج</span></a>
    </nav>
    <div class="hv2-date"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v16H3z M3 10h18 M8 3v4 M16 3v4 M7 14h2 M11 14h2 M15 14h2 M7 18h2 M11 18h2"/></svg><span><b data-hv2-day></b><small data-hv2-time></small></span></div>
    <button type="button" class="hv2-bell" data-hv2-bell aria-label="التنبيهات"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9 M10 21a2 2 0 0 0 4 0"/></svg><b data-hv2-bell-count hidden></b></button>
    <div class="hv2-kpis" aria-label="أرقام اليوم">
        <a class="hv2-kpi is-expenses" data-hv2-kpi="expenses" href="{{ $links['expenses'] ?? '#' }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h18v13H3z M16 13h5 M3 7l3-4h12l3 4"/></svg><span>المصروفات</span><b>—</b><small></small></a>
        <a class="hv2-kpi is-profit" data-hv2-kpi="profit" href="{{ $go('branch-stock.index') ? $go('branch-stock.index').'#profit' : '#' }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20h18 M6 16v-3 M10 16v-6 M14 16v-4 M18 16V7 M14 4h5v5"/></svg><span>صافي الربح</span><b>—</b><small></small></a>
        <a class="hv2-kpi is-sales" data-hv2-kpi="sales" href="{{ $links['sales'] ?? '#' }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6c0-2 16-2 16 0s-16 2-16 0 M4 6v4c0 2 16 2 16 0V6 M4 10v4c0 2 16 2 16 0v-4 M4 14v4c0 2 16 2 16 0v-4"/></svg><span>مبيعات اليوم</span><b>—</b><small></small></a>
        <a class="hv2-kpi" data-hv2-kpi="orders" href="{{ $links['sales'] ?? '#' }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6 M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2 M18 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2"/></svg><span>طلبات اليوم</span><b>—</b><small></small></a>
    </div>
@endif
    <nav class="hud-columns" aria-label="أقسام الداشبورد"><div class="hud-column hud-column-right" data-hud-right></div><div class="hud-column hud-column-left" data-hud-left></div></nav>
@if($goHome)
    <div class="hv2-panels">
        <section class="hv2-panel hv2-alerts" id="hv2-alerts" tabindex="-1" aria-labelledby="hv2-alerts-title"><h2 id="hv2-alerts-title">تنبيهات ذكية <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9 M10 21a2 2 0 0 0 4 0"/></svg></h2><ul data-hv2-alerts class="hv2-alert-list"></ul></section>
        <section class="hv2-panel hv2-branches" aria-labelledby="hv2-branches-title"><h2 id="hv2-branches-title">أداء الفروع اليوم <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l2-6h14l2 6 M3 9v12h18V9 M3 9h18 M9 21v-6h6v6"/></svg></h2><ol data-hv2-branches class="hv2-branch-list"></ol><p class="hv2-hint">النسبة = مبيعات النهارده لحد دلوقتي من متوسط يوم كامل في آخر 7 أيام</p></section>
        <section class="hv2-panel hv2-chart" aria-labelledby="hv2-chart-title"><h2 id="hv2-chart-title">المبيعات خلال 7 أيام</h2><div data-hv2-chart class="hv2-chart-box"></div></section>
    </div>
    <div class="hv2-actions">
        <button type="button" class="hv2-action is-analysis" data-hud-enter><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20h18 M6 16v-4 M10 16V8 M14 16v-6 M18 16V5"/></svg><span>تحليل الأداء</span></button>
        @if($go('reports.index'))<a class="hv2-action" href="{{ $go('reports.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6z M14 2v6h6 M9 13h8 M9 17h8"/></svg><span>تقارير جاهزة</span></a>@endif
        @if(!empty($links['ch-app']))<a class="hv2-action" href="{{ $links['ch-app'] }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v17H4z M8 2v4 M16 2v4 M4 9h16 M8.5 15l2.5 2.5 4.5-5"/></svg><span>مهام اليوم</span></a>@endif
        @if(!empty($links['stock']))<a class="hv2-action is-forecast" href="{{ $links['stock'] }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9 M12 7a5 5 0 1 0 5 5 M12 11a1 1 0 1 0 1 1 M12 12l7-7 M16 4h4v4"/></svg><span>توقعات ذكية</span></a>@endif
        <button type="button" class="hv2-action is-voice" data-hv2-voice><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14v-2a8 8 0 0 1 16 0v2 M4 14h3v6H4z M17 14h3v6h-3z"/></svg><span>تحدث صوتيا</span></button>
    </div>
@else
    <button class="hud-enter" data-hud-enter type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 9-8 9 8M5 10v11h14V10M9 21v-7h6v7"/></svg><span>الدخول للرئيسية</span></button>
@endif
    <span class="hud-brand">@include('admin.partials.go_hud_mark')</span>
    <div class="hud-live" role="status"><span data-hud-scope></span><span data-hud-freshness></span></div>
    <button type="button" class="hud-voice" data-hud-voice hidden aria-label="كتم الترحيب">كتم الترحيب</button>
    <p class="hud-announcement" data-hud-announcement aria-live="polite"></p>
</section>
