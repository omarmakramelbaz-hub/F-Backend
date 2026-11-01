{{-- Live opening; data comes from authorized, read-only dashboard metrics. --}}
<section id="go-hud" class="go-hud" dir="rtl" lang="ar" role="dialog" aria-modal="true" aria-label="مركز تشغيل GO" data-intro="{{ !empty($boot['intro']) ? '1' : '0' }}">
    <canvas class="hud-background" data-hud-background aria-hidden="true"></canvas>
    <div class="hud-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><circle cx="12" cy="7" r="4"/><path d="M3 23v-3a9 9 0 0 1 18 0v3Z"/></svg><strong data-hud-who>{{ auth('admin')->user()->name }}</strong></div>
    <div class="hud-figure" aria-hidden="true"><canvas data-hud-robot></canvas></div>
    <nav class="hud-columns" aria-label="أقسام الداشبورد"><div class="hud-column hud-column-right" data-hud-right></div><div class="hud-column hud-column-left" data-hud-left></div></nav>
    <button class="hud-enter" data-hud-enter type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 9-8 9 8M5 10v11h14V10M9 21v-7h6v7"/></svg><span>الدخول للرئيسية</span></button>
    <span class="hud-brand">@include('admin.partials.go_hud_mark')</span>
    <div class="hud-live" role="status"><span data-hud-scope></span><span data-hud-freshness></span></div>
    <button type="button" class="hud-voice" data-hud-voice hidden aria-label="كتم الترحيب">كتم الترحيب</button>
    <p class="hud-announcement" data-hud-announcement aria-live="polite"></p>
</section>
