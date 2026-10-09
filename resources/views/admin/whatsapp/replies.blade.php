@php
$waReplyLabels = app()->getLocale() === 'ar' ? [
    'title'=>'الرد على العميل', 'placeholder'=>'اكتب ردًا من حساب فسخانستا…', 'send'=>'إرسال الرد',
    'refresh'=>'مراجعة حالة الإرسال', 'loading'=>'جارٍ التحقق…', 'sending'=>'جارٍ إرسال الرد…',
    'accepted'=>'قُبل الإرسال. ستظهر نسخة الرد في المحادثة عند وصولها من واتساب.',
    'unknown'=>'حالة الإرسال غير مؤكدة. راجع واتساب وحالة الإرسال قبل كتابة رد آخر.',
    'failed'=>'لم يُقبل الإرسال. راجع حالة الخدمة قبل المحاولة مرة أخرى.',
    'unavailable'=>'الرد من الداشبورد غير متاح الآن.', 'expired'=>'انتهت مدة الرد المتاحة. يمكن الرد من واتساب بيزنس.',
    'not_configured'=>'إرسال الردود يحتاج إعداد اتصال واتساب على السيرفر.',
    'denied'=>'تغيّرت صلاحية الحساب. أُغلقت بيانات الرد.', 'offline'=>'إرسال الرد يحتاج اتصالًا بالإنترنت.',
    'ready'=>'يمكنك إرسال رد من حساب النشاط.', 'count'=>'حرف', 'record'=>'تسجيل رسالة صوتية',
    'stop'=>'إيقاف التسجيل', 'cancel'=>'إلغاء التسجيل', 'send_voice'=>'إرسال الرسالة الصوتية',
    'recording'=>'جارٍ التسجيل…', 'voice_ready'=>'راجع التسجيل ثم اضغط إرسال.',
    'voice_unavailable'=>'التسجيل الصوتي غير متاح في هذا المتصفح أو اتصال واتساب الحالي.',
    'microphone_denied'=>'تعذّر فتح الميكروفون. راجع إذن الميكروفون في المتصفح.',
    'voice_limit'=>'الحد الأقصى للتسجيل ٦٠ ثانية و٨ ميجابايت.', 'audit'=>'ردود الإدارة', 'voice_message'=>'رسالة صوتية',
] : [
    'title'=>'Reply to customer', 'placeholder'=>'Write a reply from Fasakhansta…', 'send'=>'Send reply',
    'refresh'=>'Check send status', 'loading'=>'Checking…', 'sending'=>'Sending reply…',
    'accepted'=>'Sending was accepted. A copy will appear when the WhatsApp webhook arrives.',
    'unknown'=>'Send status is uncertain. Check WhatsApp and the send status before composing another reply.',
    'failed'=>'Sending was not accepted. Check the service before trying again.',
    'unavailable'=>'Replies from the dashboard are temporarily unavailable.',
    'expired'=>'The reply window has expired. You can reply through WhatsApp Business.',
    'not_configured'=>'Dashboard replies require a WhatsApp connection configured on the server.',
    'denied'=>'Your access has changed. Reply information was cleared.', 'offline'=>'Sending a reply requires an internet connection.',
    'ready'=>'You can send a reply from the business account.', 'count'=>'characters', 'record'=>'Record voice message',
    'stop'=>'Stop recording', 'cancel'=>'Cancel recording', 'send_voice'=>'Send voice message',
    'recording'=>'Recording…', 'voice_ready'=>'Review the recording, then choose Send.',
    'voice_unavailable'=>'Voice recording is unavailable in this browser or WhatsApp connection.',
    'microphone_denied'=>'The microphone could not be opened. Check the browser microphone permission.',
    'voice_limit'=>'Recording is limited to 60 seconds and 8 MiB.', 'audit'=>'Dashboard replies', 'voice_message'=>'Voice message',
];
$waReplyConfig = ['conversations_base_url'=>url('/admin/whatsapp/conversations'), 'csrf'=>csrf_token(), 'labels'=>$waReplyLabels];
@endphp
<section class="wa-replies" data-wa-replies hidden aria-labelledby="wa-reply-title">
    <header class="wa-replies-heading"><h3 id="wa-reply-title">{{ $waReplyLabels['title'] }}</h3><button type="button" class="wa-inbox-button" data-wa-reply-refresh>{{ $waReplyLabels['refresh'] }}</button></header>
    <p class="wa-replies-status" data-wa-reply-status role="status" aria-live="polite"></p>
    <form data-wa-reply-form autocomplete="off">
        <label class="sr-only" for="wa-reply-text">{{ $waReplyLabels['title'] }}</label>
        <textarea id="wa-reply-text" data-wa-reply-text rows="2" maxlength="4096" placeholder="{{ $waReplyLabels['placeholder'] }}" disabled></textarea>
        <footer><span data-wa-reply-count dir="ltr">0 / 4096</span><button type="submit" class="wa-inbox-button wa-replies-send" data-wa-reply-send disabled>{{ $waReplyLabels['send'] }}</button></footer>
    </form>
    <div class="wa-replies-voice"><button type="button" class="wa-inbox-button" data-wa-voice-record disabled><i class="fas fa-microphone" aria-hidden="true"></i> {{ $waReplyLabels['record'] }}</button><button type="button" class="wa-inbox-button" data-wa-voice-stop hidden>{{ $waReplyLabels['stop'] }}</button><button type="button" class="wa-inbox-button" data-wa-voice-cancel hidden>{{ $waReplyLabels['cancel'] }}</button><span data-wa-voice-time dir="ltr" hidden></span><audio data-wa-voice-preview controls preload="metadata" hidden></audio><button type="button" class="wa-inbox-button wa-replies-send" data-wa-voice-send hidden disabled>{{ $waReplyLabels['send_voice'] }}</button></div>
    <p class="wa-replies-status" data-wa-voice-status hidden></p>
    <details class="wa-replies-audit" data-wa-reply-audit hidden><summary>{{ $waReplyLabels['audit'] }}</summary><ol data-wa-reply-audit-list></ol></details>
</section>
<script type="application/json" id="whatsapp-replies-bootstrap">@json($waReplyConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
