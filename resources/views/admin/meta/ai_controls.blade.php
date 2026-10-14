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
    <p class="meta-ai-note" data-meta-ai-dispatch-status role="status" aria-live="polite">{{ $metaAiArabic ? 'جارٍ تحميل حالة إرسال الطلبات' : 'Loading order dispatch status' }}</p>
    <ul class="meta-ai-channel-status" data-meta-ai-channel-status aria-live="polite"></ul>
    <p class="meta-ai-note" data-meta-ai-handoff-state hidden></p>
    <p class="meta-ai-message" data-meta-ai-message role="status" aria-live="polite" hidden></p>
    <details class="meta-ai-settings" data-meta-ai-settings hidden>
        <summary>{{ $metaAiArabic ? 'القنوات ومواعيد الردود وإرسال الطلبات' : 'Channels, reply hours and order dispatch hours' }}</summary>
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
                <label><input type="checkbox" data-meta-ai-schedule disabled> {{ $metaAiArabic ? 'تحديد مواعيد ردود وتحليل AI' : 'Schedule AI replies and analysis' }}</label>
                <label><input type="checkbox" data-meta-ai-all-day disabled> {{ $metaAiArabic ? 'طوال اليوم' : 'All day' }}</label>
                <label>{{ $metaAiArabic ? 'من' : 'From' }} <input type="time" value="09:00" step="60" data-meta-ai-start disabled></label>
                <label>{{ $metaAiArabic ? 'إلى' : 'To' }} <input type="time" value="23:00" step="60" data-meta-ai-end disabled></label>
            </div>
            <fieldset class="meta-ai-fields meta-ai-days">
                <legend>{{ $metaAiArabic ? 'أيام بداية الردود والتحليل' : 'Days when replies and analysis start' }}</legend>
                @foreach(($metaAiArabic ? ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'] : ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday']) as $metaAiDay => $metaAiDayName)
                <label><input type="checkbox" value="{{ $metaAiDay }}" data-meta-ai-day disabled> {{ $metaAiDayName }}</label>
                @endforeach
            </fieldset>
            <fieldset class="meta-ai-dispatch-settings">
                <legend>{{ $metaAiArabic ? 'مواعيد إرسال الطلبات للفروع' : 'Order dispatch hours for branches' }}</legend>
                <div class="meta-ai-schedule-row">
                    <label><input type="checkbox" data-meta-ai-dispatch-schedule disabled> {{ $metaAiArabic ? 'جدولة إرسال الطلبات' : 'Schedule order dispatch' }}</label>
                    <label><input type="checkbox" data-meta-ai-dispatch-all-day disabled> {{ $metaAiArabic ? 'طوال اليوم' : 'All day' }}</label>
                    <label>{{ $metaAiArabic ? 'من' : 'From' }} <input type="time" value="09:00" step="60" data-meta-ai-dispatch-start disabled></label>
                    <label>{{ $metaAiArabic ? 'إلى' : 'To' }} <input type="time" value="00:30" step="60" data-meta-ai-dispatch-end disabled></label>
                </div>
                <fieldset class="meta-ai-fields meta-ai-days">
                    <legend>{{ $metaAiArabic ? 'أيام بداية إرسال الطلبات' : 'Days when order dispatch starts' }}</legend>
                    @foreach(($metaAiArabic ? ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'] : ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday']) as $metaAiDispatchDay => $metaAiDispatchDayName)
                    <label><input type="checkbox" value="{{ $metaAiDispatchDay }}" data-meta-ai-dispatch-day disabled> {{ $metaAiDispatchDayName }}</label>
                    @endforeach
                </fieldset>
                <p class="meta-ai-note">{{ $metaAiArabic ? 'جدول إرسال الطلبات مستقل عن جدول الردود والتحليل. عند إيقاف جدولة الإرسال، يمكن إرسال الطلبات طوال اليوم.' : 'Order dispatch has its own schedule, separate from replies and analysis. Turning off dispatch scheduling allows orders to be sent all day.' }}</p>
            </fieldset>
            <p class="meta-ai-note">{{ $metaAiArabic ? 'كل المواعيد بتوقيت القاهرة. إذا كانت النهاية في اليوم التالي، يبدأ الموعد في اليوم المحدد ويستمر بعد منتصف الليل.' : 'All times use Cairo time. Overnight hours start on the selected day and continue after midnight.' }} <span data-meta-ai-current-time></span></p>
            <button type="submit" class="wa-inbox-button meta-ai-save" data-meta-ai-save disabled>{{ $metaAiArabic ? 'حفظ الإعدادات' : 'Save settings' }}</button>
        </form>
    </details>
    <p class="meta-ai-note" data-meta-ai-unanswered-note>{{ $metaAiArabic ? 'عند تشغيل AI، يراجع الرسائل التي لم يُرد عليها، ما دامت مهلة الرد على المنصة تسمح بذلك. الردود وطلب البيانات الناقصة والتحليل تعمل حسب مواعيد الردود المحددة أعلاه.' : 'When enabled, AI reviews unanswered messages while the platform reply window permits. Replies, requests for missing information and analysis follow the reply hours selected above.' }}</p>
    <p class="meta-ai-note" data-meta-ai-dispatch-note>{{ $metaAiArabic ? 'تُرسل الطلبات المكتملة إلى الفروع بعد تأكيد العميل، حسب مواعيد إرسال الطلبات التي تحددها. الموعد الافتراضي يوميًا من 9 صباحًا إلى 12:30 بعد منتصف الليل (09:00–00:30) بتوقيت القاهرة. الرد اليدوي متاح دائمًا.' : 'Completed orders are sent to branches after customer confirmation, during your selected order dispatch hours. The default is daily from 9:00 AM to 12:30 AM after midnight (09:00–00:30), Cairo time. Manual replies remain available.' }}</p>
</section>
@push('custom-css')
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-ai-controls.css') }}?v={{ filemtime(public_path('css/dashboard-meta-ai-controls.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-inbox-viewport.css') }}?v={{ filemtime(public_path('css/dashboard-meta-inbox-viewport.css')) }}">
@endpush
@push('custom-js')
<script type="application/json" id="meta-ai-controls-bootstrap">@json($metaAiControlsBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="{{ asset('js/dashboard-meta-ai-controls.js') }}?v={{ filemtime(public_path('js/dashboard-meta-ai-controls.js')) }}"></script>
<script data-dashboard-page-init>
if (window.DashboardMetaAiControls) window.DashboardMetaAiControls.mount();
</script>
@endpush
@endif
