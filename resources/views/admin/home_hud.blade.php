{{-- GO live control room. Its start-up sequence plays once per sign-in; afterwards it opens assembled and follows the page's live data. --}}
<section id="go-hud" class="go-hud" dir="rtl" lang="ar" data-intro="{{ !empty($boot['intro']) ? '1' : '0' }}" aria-label="مركز تشغيل GO">
    <canvas aria-hidden="true"></canvas>
    <span class="hud-status"><i></i><span data-hud-status>جاري فحص النظام</span></span>
    <section class="hud-checks" aria-label="فحص النظام"><h2>فحص النظام</h2><ol data-hud-checks></ol><div class="hud-tally" data-hud-tally aria-live="polite"></div></section>
    <div class="hud-core" data-hud-core><span class="hud-ring dash"></span><span class="hud-ring thin"></span><span class="hud-ring"></span>
        <div class="hud-core-in"><span class="hud-go">GO</span><span class="hud-who" data-hud-who></span><span class="hud-day" data-hud-day></span></div></div>
    <div data-hud-nodes></div>
    <button type="button" class="hud-skip" data-hud-skip hidden>تخطي</button>
</section>
