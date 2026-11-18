@php
$waReplyLabels = app()->getLocale() === 'ar' ? [
    'no_inbound'=>'لم تصل رسالة العميل إلى الداشبورد بعد؛ راجع استقبال الرسائل.',
    'attachment'=>'إرفاق صورة أو ملف', 'attachment_review'=>'راجع المرفق ثم اضغط إرسال.', 'attachment_send'=>'إرسال المرفق',
    'attachment_limit'=>'نوع الملف غير مدعوم أو حجمه أكبر من الحد المتاح: الصور والصوت ٨ ميجابايت، الفيديو والملفات ٢٠ ميجابايت.',
    'attachment_remove'=>'إزالة المرفق', 'forward_review'=>'رسالة لإعادة التوجيه', 'forward_send'=>'إرسال إعادة التوجيه', 'forward_cancel'=>'إلغاء', 'emoji'=>'إضافة إيموجي',
    'title'=>'الرد على العميل', 'placeholder'=>'اكتب ردًا من حساب فسخانستا…', 'send'=>'إرسال الرد',
    'refresh'=>'مراجعة حالة الإرسال', 'loading'=>'جارٍ التحقق…', 'sending'=>'جارٍ إرسال الرد…',
    'accepted'=>'قُبل الإرسال. ستظهر نسخة الرد في المحادثة عند وصولها من ميتا بيزنس.',
    'unknown'=>'حالة الإرسال غير مؤكدة. راجع ميتا بيزنس وحالة الإرسال قبل كتابة رد آخر.',
    'failed'=>'لم يُقبل الإرسال. راجع حالة الخدمة قبل المحاولة مرة أخرى.',
    'unavailable'=>'الرد من الداشبورد غير متاح الآن.', 'expired'=>'انتهت مدة الرد المتاحة. يمكن الرد من ميتا بيزنس.',
    'not_configured'=>'إرسال الردود يحتاج إعداد اتصال ميتا بيزنس على السيرفر.',
    'denied'=>'تغيّرت صلاحية الحساب. أُغلقت بيانات الرد.', 'offline'=>'إرسال الرد يحتاج اتصالًا بالإنترنت.',
    'ready'=>'يمكنك إرسال رد من حساب النشاط.', 'count'=>'حرف', 'record'=>'تسجيل رسالة صوتية',
    'stop'=>'إيقاف التسجيل', 'cancel'=>'إلغاء التسجيل', 'send_voice'=>'إرسال الرسالة الصوتية',
    'recording'=>'جارٍ التسجيل…', 'voice_ready'=>'راجع التسجيل ثم اضغط إرسال.',
    'voice_unavailable'=>'التسجيل الصوتي غير متاح في هذا المتصفح أو اتصال ميتا بيزنس الحالي.',
    'microphone_denied'=>'تعذّر فتح الميكروفون. راجع إذن الميكروفون في المتصفح.',
    'voice_limit'=>'الحد الأقصى للتسجيل ٦٠ ثانية و٨ ميجابايت.', 'audit'=>'ردود الإدارة', 'voice_message'=>'رسالة صوتية',
] : [
    'no_inbound'=>'The customer message has not reached the dashboard yet; check message reception.',
    'attachment'=>'Attach image or file', 'attachment_review'=>'Review the attachment, then choose Send.', 'attachment_send'=>'Send attachment',
    'attachment_limit'=>'Unsupported file or size limit exceeded: images/audio 8 MiB; videos/files 20 MiB.',
    'attachment_remove'=>'Remove attachment', 'forward_review'=>'Message to forward', 'forward_send'=>'Send forward', 'forward_cancel'=>'Cancel', 'emoji'=>'Add emoji',
    'title'=>'Reply to customer', 'placeholder'=>'Write a reply from Fasakhansta…', 'send'=>'Send reply',
    'refresh'=>'Check send status', 'loading'=>'Checking…', 'sending'=>'Sending reply…',
    'accepted'=>'Sending was accepted. A copy will appear when the Meta webhook arrives.',
    'unknown'=>'Send status is uncertain. Check Meta and the send status before composing another reply.',
    'failed'=>'Sending was not accepted. Check the service before trying again.',
    'unavailable'=>'Replies from the dashboard are temporarily unavailable.',
    'expired'=>'The reply window has expired. You can reply through Meta Business.',
    'not_configured'=>'Dashboard replies require a Meta connection configured on the server.',
    'denied'=>'Your access has changed. Reply information was cleared.', 'offline'=>'Sending a reply requires an internet connection.',
    'ready'=>'You can send a reply from the business account.', 'count'=>'characters', 'record'=>'Record voice message',
    'stop'=>'Stop recording', 'cancel'=>'Cancel recording', 'send_voice'=>'Send voice message',
    'recording'=>'Recording…', 'voice_ready'=>'Review the recording, then choose Send.',
    'voice_unavailable'=>'Voice recording is unavailable in this browser or Meta connection.',
    'microphone_denied'=>'The microphone could not be opened. Check the browser microphone permission.',
    'voice_limit'=>'Recording is limited to 60 seconds and 8 MiB.', 'audit'=>'Dashboard replies', 'voice_message'=>'Voice message',
];
$metaMessageHub = $metaMessageHub ?? false;
$waReplyConfig = ['meta_chat'=>true, 'conversations_base_url'=>url('/admin/meta/conversations'), 'csrf'=>csrf_token(), 'text_limit'=>1000, 'labels'=>$waReplyLabels];
if ($metaMessageHub) $waReplyConfig = array_merge($waReplyConfig, ['hub_chat'=>true, 'event_prefix'=>'meta']);
@endphp
<section class="wa-replies" data-wa-replies hidden aria-labelledby="meta-reply-title">
    <header class="wa-replies-heading"><h3 id="meta-reply-title" class="sr-only">{{ $waReplyLabels['title'] }}</h3><p class="wa-replies-status" data-wa-reply-status role="status" aria-live="polite"></p><button type="button" class="wa-inbox-button wa-icon-button" data-wa-reply-refresh title="{{ $waReplyLabels['refresh'] }}" aria-label="{{ $waReplyLabels['refresh'] }}"><i class="fas fa-sync-alt" aria-hidden="true"></i></button></header>
    <form data-wa-reply-form autocomplete="off">
        <label class="sr-only" for="meta-reply-text">{{ $waReplyLabels['title'] }}</label>
        <input type="file" data-meta-attachment-input accept="image/jpeg,image/png,image/gif,video/mp4,audio/mpeg,audio/mp4,audio/ogg,audio/wav,application/pdf,text/plain,application/zip,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.presentationml.presentation" hidden disabled>
        <div class="meta-attachment-preview" id="meta-reply-attachment-preview" data-meta-attachment-preview hidden></div>
        <div class="meta-attachment-tools"><button type="button" class="wa-inbox-button wa-icon-button" data-meta-attachment-remove hidden title="{{ $waReplyLabels['attachment_remove'] }}" aria-label="{{ $waReplyLabels['attachment_remove'] }}"><i class="far fa-trash-alt" aria-hidden="true"></i></button><button type="button" class="wa-inbox-button wa-icon-button wa-replies-send" data-meta-attachment-send hidden disabled title="{{ $waReplyLabels['attachment_send'] }}" aria-label="{{ $waReplyLabels['attachment_send'] }}"><i class="fas fa-paper-plane" aria-hidden="true"></i></button></div>
        <div class="wa-replies-compose">
            <div class="wa-replies-emoji"><button type="button" class="wa-inbox-button wa-icon-button" data-wa-reply-emoji disabled aria-expanded="false" aria-controls="meta-reply-emoji-picker" title="{{ $waReplyLabels['emoji'] }}" aria-label="{{ $waReplyLabels['emoji'] }}"><i class="far fa-smile" aria-hidden="true"></i></button><div id="meta-reply-emoji-picker" class="wa-replies-emoji-picker" data-wa-reply-emoji-picker hidden role="group" aria-label="{{ $waReplyLabels['emoji'] }}">@foreach(['😀','😊','❤️','👍','🙏','🎉','✅','🙂','😂','👋','👌','💬'] as $metaEmoji)<button type="button" data-wa-emoji="{{ $metaEmoji }}" aria-label="{{ $metaEmoji }}">{{ $metaEmoji }}</button>@endforeach</div></div>
            <textarea id="meta-reply-text" data-wa-reply-text dir="auto" rows="1" maxlength="1000" placeholder="{{ $waReplyLabels['placeholder'] }}" disabled></textarea>
            <button type="button" class="wa-inbox-button wa-icon-button" data-meta-attachment-pick disabled aria-controls="meta-reply-attachment-preview" title="{{ $waReplyLabels['attachment'] }}" aria-label="{{ $waReplyLabels['attachment'] }}"><i class="fas fa-paperclip" aria-hidden="true"></i></button>
            <button type="button" class="wa-inbox-button wa-icon-button wa-replies-mic" data-wa-voice-record disabled aria-pressed="false" title="{{ $waReplyLabels['record'] }}" aria-label="{{ $waReplyLabels['record'] }}"><i class="fas fa-microphone" aria-hidden="true"></i></button>
            <button type="submit" class="wa-inbox-button wa-icon-button wa-replies-send" data-wa-reply-send disabled title="{{ $waReplyLabels['send'] }}" aria-label="{{ $waReplyLabels['send'] }}"><i class="fas fa-paper-plane" aria-hidden="true"></i><span class="sr-only" data-wa-reply-send-label>{{ $waReplyLabels['send'] }}</span></button>
        </div>
        <footer class="wa-replies-meta"><span class="wa-replies-keyboard">{{ app()->getLocale() === 'ar' ? 'Enter للإرسال · Shift+Enter لسطر جديد' : 'Enter to send · Shift+Enter for a new line' }}</span><span data-wa-reply-count dir="ltr">0 / 1000</span></footer>
    </form>
    <div class="wa-replies-voice"><button type="button" class="wa-inbox-button" data-wa-voice-stop hidden>{{ $waReplyLabels['stop'] }}</button><span class="wa-replies-record-time" data-wa-voice-time dir="ltr" hidden></span><audio data-wa-voice-preview controls preload="metadata" hidden></audio><button type="button" class="wa-inbox-button wa-icon-button wa-replies-voice-cancel" data-wa-voice-cancel hidden title="{{ $waReplyLabels['cancel'] }}" aria-label="{{ $waReplyLabels['cancel'] }}"><i class="far fa-trash-alt" aria-hidden="true"></i></button><button type="button" class="wa-inbox-button wa-icon-button wa-replies-send" data-wa-voice-send hidden disabled title="{{ $waReplyLabels['send_voice'] }}" aria-label="{{ $waReplyLabels['send_voice'] }}"><i class="fas fa-paper-plane" aria-hidden="true"></i><span class="sr-only">{{ $waReplyLabels['send_voice'] }}</span></button></div>
    <p class="wa-replies-status" data-wa-voice-status hidden></p>
    <section class="meta-forward-draft" data-meta-forward-draft hidden><strong>{{ $waReplyLabels['forward_review'] }}</strong><p data-meta-forward-preview></p><div><button type="button" class="wa-inbox-button" data-meta-forward-cancel>{{ $waReplyLabels['forward_cancel'] }}</button><button type="button" class="wa-inbox-button wa-replies-send" data-meta-forward-send disabled>{{ $waReplyLabels['forward_send'] }}</button></div></section>
    <details class="wa-replies-audit" data-wa-reply-audit hidden><summary>{{ $waReplyLabels['audit'] }}</summary><ol data-wa-reply-audit-list></ol></details>
</section>
<script type="application/json" id="{{ $metaMessageHub ? 'meta-social-replies-bootstrap' : 'whatsapp-replies-bootstrap' }}">@json($waReplyConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
