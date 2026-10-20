@if(\Illuminate\Support\Facades\Route::has('meta-ai-control.state') && \Illuminate\Support\Facades\Route::has('meta-ai-control.update'))
@php
$metaAiArabic = app()->getLocale() === 'ar';
$metaAiControlsBootstrap = ['state_url'=>route('meta-ai-control.state'), 'update_url'=>route('meta-ai-control.update'),
    'csrf'=>csrf_token(), 'lang'=>$metaAiArabic ? 'ar' : 'en'];
@endphp
<section id="meta-ai-controls" class="meta-ai-controls" aria-label="{{ $metaAiArabic ? 'إعدادات الرد وتحليل الرسائل وإرسال الطلبات' : 'Reply, message analysis and order dispatch settings' }}" hidden>
    <details class="meta-ai-disclosure">
        <summary class="meta-ai-disclosure-summary">
            <span>{{ $metaAiArabic ? 'الرد وتحليل الرسائل وإرسال الطلبات' : 'Replies, message analysis and order dispatch' }}</span>
            <svg class="meta-ai-disclosure-arrow" width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false"><path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </summary>
        <div class="meta-ai-disclosure-body">
    <div class="meta-ai-controls-heading">
        <button type="button" class="wa-inbox-button meta-ai-toggle" data-meta-ai-toggle disabled aria-pressed="false">{{ $metaAiArabic ? 'تشغيل تحليل وإرسال الطلبات' : 'Enable analysis and order dispatch' }}</button>
        <span class="meta-ai-status" data-meta-ai-status role="status" aria-live="polite">{{ $metaAiArabic ? 'جارٍ تحميل حالة AI' : 'Loading AI status' }}</span>
        <button type="button" class="wa-inbox-button meta-ai-refresh" data-meta-ai-refresh>{{ $metaAiArabic ? 'تحديث الحالة' : 'Refresh status' }}</button>
    </div>
    <p class="meta-ai-note" data-meta-ai-dispatch-status role="status" aria-live="polite">{{ $metaAiArabic ? 'جارٍ تحميل حالة إرسال الطلبات' : 'Loading order dispatch status' }}</p>
    <p class="meta-ai-note" data-meta-ai-analysis-status role="status" aria-live="polite"></p>
    <p class="meta-ai-note" data-meta-ai-reply-status role="status" aria-live="polite"></p>
    <ul class="meta-ai-channel-status" data-meta-ai-channel-status aria-live="polite"></ul>
    <p class="meta-ai-message" data-meta-ai-compatibility-note hidden></p>
    <p class="meta-ai-message" data-meta-ai-message role="status" aria-live="polite" hidden></p>
    <details class="meta-ai-settings" data-meta-ai-settings hidden>
        <summary>{{ $metaAiArabic ? 'وضع الرد والقنوات ومواعيد المعالجة' : 'Reply mode, channels and processing hours' }}</summary>
        <form data-meta-ai-form data-spa-off>
            <fieldset class="meta-ai-fields">
                <legend>{{ $metaAiArabic ? 'دور الداشبورد في الرسائل' : 'Dashboard role in messages' }}</legend>
                <label>{{ $metaAiArabic ? 'الوضع' : 'Mode' }}
                    <select data-meta-ai-mode data-dropdown-parent="#meta-ai-controls" disabled aria-describedby="meta-ai-mode-help">
                        <option value="" selected disabled>{{ $metaAiArabic ? 'جارٍ تحميل الوضع المحفوظ' : 'Loading saved mode' }}</option>
                        <option value="ANALYZE_ONLY">{{ $metaAiArabic ? 'تحليل وإرسال الطلبات فقط' : 'Analysis and order dispatch only' }}</option>
                        <option value="REPLY_AND_ANALYZE">{{ $metaAiArabic ? 'ردود تلقائية وتحليل وإرسال الطلبات' : 'Automatic replies, analysis and order dispatch' }}</option>
                    </select>
                </label>
            </fieldset>
            <p id="meta-ai-mode-help" class="meta-ai-note" data-meta-ai-analysis-note data-meta-ai-mode-help></p>
            <fieldset class="meta-ai-schedule-settings" data-meta-ai-handoff-settings hidden>
                <legend>{{ $metaAiArabic ? 'قبل تفعيل الردود' : 'Before enabling replies' }}</legend>
                <label><input type="checkbox" data-meta-ai-external-paused autocomplete="off" disabled>
                    {{ $metaAiArabic ? 'أؤكد أني أوقفت أي مساعد خارجي يرد تلقائيًا على كل القنوات المحددة.' : 'I confirm that I have paused any external assistant that automatically replies on every selected channel.' }}
                </label>
                <p class="meta-ai-note">{{ $metaAiArabic ? 'ضع العلامة بعد إيقافه فعليًا من إعداداته. التأكيد لا يوقف المساعد نيابةً عنك، ويُطلب مجددًا عند الحاجة إلى تفعيل جديد.' : 'Check this only after actually pausing it in its settings. This confirmation does not pause the assistant for you and is requested again when a new activation needs it.' }}</p>
            </fieldset>
            <fieldset class="meta-ai-fields">
                <legend>{{ $metaAiArabic ? 'القنوات التي يعمل عليها AI' : 'Channels where AI operates' }}</legend>
                <label><input type="checkbox" value="whatsapp" data-meta-ai-channel disabled> {{ $metaAiArabic ? 'واتساب' : 'WhatsApp' }}</label>
                <label><input type="checkbox" value="messenger" data-meta-ai-channel disabled> {{ $metaAiArabic ? 'ماسنجر' : 'Messenger' }}</label>
                <label><input type="checkbox" value="instagram" data-meta-ai-channel disabled> {{ $metaAiArabic ? 'إنستجرام' : 'Instagram' }}</label>
            </fieldset>
            <fieldset class="meta-ai-schedule-settings" data-meta-ai-analysis-settings>
            <legend>{{ $metaAiArabic ? 'مواعيد الرد وتحليل الرسائل حسب الوضع المختار' : 'Reply and message analysis hours for the selected mode' }}</legend>
            <div class="meta-ai-schedule-row">
                <label><input type="checkbox" data-meta-ai-schedule disabled> {{ $metaAiArabic ? 'جدولة الرد والتحليل' : 'Schedule replies and analysis' }}</label>
                <label><input type="checkbox" data-meta-ai-all-day disabled> {{ $metaAiArabic ? 'طوال اليوم' : 'All day' }}</label>
                <label>{{ $metaAiArabic ? 'من' : 'From' }} <input type="time" value="09:00" step="60" data-meta-ai-start disabled></label>
                <label>{{ $metaAiArabic ? 'إلى' : 'To' }} <input type="time" value="23:00" step="60" data-meta-ai-end disabled></label>
            </div>
            <fieldset class="meta-ai-fields meta-ai-days">
                <legend>{{ $metaAiArabic ? 'أيام بداية الرد والتحليل' : 'Days when replies and analysis start' }}</legend>
                @foreach(($metaAiArabic ? ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'] : ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday']) as $metaAiDay => $metaAiDayName)
                <label><input type="checkbox" value="{{ $metaAiDay }}" data-meta-ai-day disabled> {{ $metaAiDayName }}</label>
                @endforeach
            </fieldset>
            <p class="meta-ai-note">{{ $metaAiArabic ? 'عند إيقاف هذه الجدولة، يعمل الرد والتحليل المسموح بهما في الوضع المختار طوال اليوم. وضع التحليل فقط لا يرسل ردودًا تلقائية.' : 'Turning off this schedule allows the replies and analysis permitted by the selected mode all day. Analysis-only mode does not send automatic replies.' }}</p>
            </fieldset>
            <fieldset class="meta-ai-schedule-settings meta-ai-dispatch-settings">
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
                <p class="meta-ai-note">{{ $metaAiArabic ? 'جدول إرسال الطلبات مستقل عن جدول تحليل الرسائل. عند إيقاف جدولة الإرسال، يمكن إرسال الطلبات طوال اليوم.' : 'Order dispatch has its own schedule, separate from message analysis. Turning off dispatch scheduling allows orders to be sent all day.' }}</p>
            </fieldset>
            <p class="meta-ai-note">{{ $metaAiArabic ? 'كل المواعيد بتوقيت القاهرة. إذا كانت النهاية في اليوم التالي، يبدأ الموعد في اليوم المحدد ويستمر بعد منتصف الليل.' : 'All times use Cairo time. Overnight hours start on the selected day and continue after midnight.' }} <span data-meta-ai-current-time></span></p>
            <button type="submit" class="wa-inbox-button meta-ai-save" data-meta-ai-save disabled>{{ $metaAiArabic ? 'حفظ الإعدادات' : 'Save settings' }}</button>
        </form>
    </details>
    <p class="meta-ai-note" data-meta-ai-dispatch-note>{{ $metaAiArabic ? 'تُرسل الطلبات المكتملة إلى الفروع بعد تأكيد العميل، حسب مواعيد إرسال الطلبات التي تحددها. الموعد الافتراضي يوميًا من 9 صباحًا إلى 12:30 بعد منتصف الليل (09:00–00:30) بتوقيت القاهرة. الرد اليدوي متاح دائمًا.' : 'Completed orders are sent to branches after customer confirmation, during your selected order dispatch hours. The default is daily from 9:00 AM to 12:30 AM after midnight (09:00–00:30), Cairo time. Manual replies remain available.' }}</p>
        </div>
    </details>
</section>
@push('custom-css')
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-ai-controls.css') }}?v={{ filemtime(public_path('css/dashboard-meta-ai-controls.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-inbox-viewport.css') }}?v={{ filemtime(public_path('css/dashboard-meta-inbox-viewport.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-inbox-expanded.css') }}?v={{ filemtime(public_path('css/dashboard-meta-inbox-expanded.css')) }}">
@endpush
@push('custom-js')
<script type="application/json" id="meta-ai-controls-bootstrap">@json($metaAiControlsBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="{{ asset('js/dashboard-meta-ai-controls-replies-20261020-v2.js') }}?v={{ filemtime(public_path('js/dashboard-meta-ai-controls-replies-20261020-v2.js')) }}"></script>
<script data-dashboard-page-init>
if (window.DashboardMetaAiControls) window.DashboardMetaAiControls.mount();
</script>
@endpush
@endif
