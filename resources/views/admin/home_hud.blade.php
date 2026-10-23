{{-- GO live home screen. It opens over the usual home page each time; GO in the middle steps into that page as it always was,
     and each section opens its own page. The start-up sequence plays once per sign-in. --}}
<section id="go-hud" class="go-hud" dir="rtl" lang="ar" data-intro="{{ !empty($boot['intro']) ? '1' : '0' }}" aria-label="مركز تشغيل GO">
    <canvas aria-hidden="true"></canvas>
    <span class="hud-status" data-hud-pill><i></i><span data-hud-status>جاري فحص النظام</span></span>
    <div class="hud-title"><strong data-hud-who></strong><span data-hud-day></span></div>
    <div class="hud-core" data-hud-core>@include('admin.home_hud_solar')</div>
    <div class="hud-nodes" data-hud-nodes></div>
    <section class="hud-checks" aria-label="فحص النظام"><h2>فحص النظام</h2><ol data-hud-checks></ol><div class="hud-tally" data-hud-tally aria-live="polite"></div></section>
    <button type="button" class="hud-enter" data-hud-enter hidden><b>GO</b> اضغط على اللوجو للدخول على الرئيسية</button>
    <button type="button" class="hud-skip" data-hud-skip hidden>تخطي</button>
</section>
