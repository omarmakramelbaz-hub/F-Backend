@if(\Illuminate\Support\Facades\Route::has('meta-ai-control.state') && \Illuminate\Support\Facades\Route::has('meta-ai-control.update'))
@php
$metaAiArabic = app()->getLocale() === 'ar';
$metaAiControlsBootstrap = ['state_url'=>route('meta-ai-control.state'), 'update_url'=>route('meta-ai-control.update'),
    'csrf'=>csrf_token(), 'lang'=>$metaAiArabic ? 'ar' : 'en'];
@endphp
<section id="meta-ai-controls" class="meta-ai-controls" aria-label="{{ $metaAiArabic ? 'إعدادات ردود وتحليل AI' : 'AI replies and analysis settings' }}" hidden>
    <div class="meta-ai-controls-heading">
        <button type="button" class="wa-inbox-button meta-ai-toggle" data-meta-ai-toggle disabled aria-pressed="false">{{ $metaAiArabic ? 'تشغيل ردود وتحليل AI' : 'Enable AI replies and analysis' }}</button>
        <span class="meta-ai-status" data-meta-ai-status role="status" aria-live="polite">{{ $metaAiArabic ? 'جارٍ تحميل حالة AI' : 'Loading AI status' }}</span>
        <button type="button" class="wa-inbox-button meta-ai-refresh" data-meta-ai-refresh>{{ $metaAiArabic ? 'تحديث الحالة' : 'Refresh status' }}</button>
    </div>
    <ul class="meta-ai-channel-status" data-meta-ai-channel-status aria-live="polite"></ul>
    <p class="meta-ai-note" data-meta-ai-handoff-state hidden></p>
    <p class="meta-ai-message" data-meta-ai-message role="status" aria-live="polite" hidden></p>
    <details class="meta-ai-settings" data-meta-ai-settings hidden>
        <summary>{{ $metaAiArabic ? 'القنوات ومواعيد التشغيل' : 'Channels and operating hours' }}</summary>
        <form data-meta-ai-form data-spa-off>
            <fieldset class="meta-ai-fields">
                <legend>{{ $metaAiArabic ? 'القنوات التي يعمل عليها AI' : 'Channels where AI operates' }}</legend>
                <label><input type="checkbox" value="whatsapp" data-meta-ai-channel disabled> {{ $metaAiArabic ? 'واتساب' : 'WhatsApp' }}</label>
                <label><input type="checkbox" value="messenger" data-meta-ai-channel disabled> {{ $metaAiArabic ? 'ماسنجر' : 'Messenger' }}</label>
                <label><input type="checkbox" value="instagram" data-meta-ai-channel disabled> {{ $metaAiArabic ? 'إنستجرام' : 'Instagram' }}</label>
            </fieldset>
            <label class="meta-ai-handoff"><input type="checkbox" data-meta-ai-external-paused disabled> {{ $metaAiArabic ? 'أوقفت ردود مساعد ميتا في القنوات المحددة' : 'I have stopped Meta assistant replies in the selected channels' }}</label>
            <p class="meta-ai-note">{{ $metaAiArabic ? 'في واتساب، أوقف المساعد في المحادثات الحالية أيضًا قبل تسليم الردود إلى AI.' : 'In WhatsApp, pause the assistant in existing conversations too before handing replies over to AI.' }}</p>
            <div class="meta-ai-schedule-row">
                <label><input type="checkbox" data-meta-ai-schedule disabled> {{ $metaAiArabic ? 'التشغيل في مواعيد محددة' : 'Use scheduled operating hours' }}</label>
                <label><input type="checkbox" data-meta-ai-all-day disabled> {{ $metaAiArabic ? 'طوال اليوم' : 'All day' }}</label>
                <label>{{ $metaAiArabic ? 'من' : 'From' }} <input type="time" value="09:00" step="60" data-meta-ai-start disabled></label>
                <label>{{ $metaAiArabic ? 'إلى' : 'To' }} <input type="time" value="23:00" step="60" data-meta-ai-end disabled></label>
            </div>
            <fieldset class="meta-ai-fields meta-ai-days">
                <legend>{{ $metaAiArabic ? 'أيام بداية التشغيل' : 'Days when operation starts' }}</legend>
                @foreach(($metaAiArabic ? ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'] : ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday']) as $metaAiDay => $metaAiDayName)
                <label><input type="checkbox" value="{{ $metaAiDay }}" data-meta-ai-day disabled> {{ $metaAiDayName }}</label>
                @endforeach
            </fieldset>
            <p class="meta-ai-note">{{ $metaAiArabic ? 'كل المواعيد بتوقيت القاهرة. إذا كانت النهاية في اليوم التالي، يبدأ الموعد في اليوم المحدد ويستمر بعد منتصف الليل.' : 'All times use Cairo time. Overnight hours start on the selected day and continue after midnight.' }} <span data-meta-ai-current-time></span></p>
            <button type="submit" class="wa-inbox-button meta-ai-save" data-meta-ai-save disabled>{{ $metaAiArabic ? 'حفظ الإعدادات' : 'Save settings' }}</button>
        </form>
    </details>
    <p class="meta-ai-note">{{ $metaAiArabic ? 'الرد اليدوي متاح دائمًا. الرسائل الحديثة التي لم يُرد عليها خارج المواعيد تنتظر موعد التشغيل التالي، ما دامت مهلة الرد على المنصة تسمح بذلك.' : 'Manual replies remain available. Recent unanswered messages received outside these hours wait until the next operating window while the platform reply window permits.' }}</p>
</section>
@push('custom-css')
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-ai-controls.css') }}?v={{ filemtime(public_path('css/dashboard-meta-ai-controls.css')) }}">
@endpush
@push('custom-js')
<script type="application/json" id="meta-ai-controls-bootstrap">@json($metaAiControlsBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="{{ asset('js/dashboard-meta-ai-controls.js') }}?v={{ filemtime(public_path('js/dashboard-meta-ai-controls.js')) }}"></script>
<script data-dashboard-page-init>
if (window.DashboardMetaAiControls) window.DashboardMetaAiControls.mount();
</script>
@endpush
@endif
