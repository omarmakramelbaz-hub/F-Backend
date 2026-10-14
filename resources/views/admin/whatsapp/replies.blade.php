@php
$waReplyLabels = app()->getLocale() === 'ar' ? [
    'title'=>'الرد على العميل', 'placeholder'=>'اكتب ردًا من حساب فسخانستا…', 'send'=>'إرسال الرد',
    'refresh'=>'مراجعة حالة الإرسال', 'loading'=>'جارٍ التحقق…', 'sending'=>'جارٍ إرسال الرد…',
    'accepted'=>'قُبل الإرسال ويظهر ردك في المحادثة.',
    'api_source'=>'رد من الداشبورد · قبله واتساب',
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
    'quote_reply'=>'الرد على رسالة', 'remove_quote'=>'إلغاء الرد على الرسالة', 'emoji'=>'إضافة إيموجي',
    'attachment'=>'إرفاق صورة أو ملف', 'attachment_preview'=>'معاينة الصورة المرفقة', 'attachment_cancel'=>'إزالة المرفق',
    'attachment_caption'=>'أضف وصفًا للمرفق…', 'attachment_ready'=>'المرفق جاهز. راجعه ثم اضغط إرسال.',
    'attachment_invalid'=>'اختر صورة أو ملفًا مدعومًا ضمن الحجم المتاح.',
    'attachment_limits'=>'صور JPG/PNG حتى {image_limit} · ملفات PDF/TXT حتى {file_limit}',
    'attachment_unavailable'=>'إرسال المرفقات غير متاح الآن.',
    'attachment_single'=>'اختر مرفقًا واحدًا في كل رسالة.',
] : [
    'title'=>'Reply to customer', 'placeholder'=>'Write a reply from Fasakhansta…', 'send'=>'Send reply',
    'refresh'=>'Check send status', 'loading'=>'Checking…', 'sending'=>'Sending reply…',
    'accepted'=>'WhatsApp accepted the send; your reply appears in the conversation.',
    'api_source'=>'Dashboard reply · accepted by WhatsApp',
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
    'quote_reply'=>'Reply to message', 'remove_quote'=>'Remove quoted message', 'emoji'=>'Add emoji',
    'attachment'=>'Attach an image or file', 'attachment_preview'=>'Attached image preview', 'attachment_cancel'=>'Remove attachment',
    'attachment_caption'=>'Add a caption…', 'attachment_ready'=>'The attachment is ready. Review it, then send.',
    'attachment_invalid'=>'Choose a supported image or file within the allowed size.',
    'attachment_limits'=>'JPG/PNG up to {image_limit} · PDF/TXT up to {file_limit}',
    'attachment_unavailable'=>'Attachments are unavailable now.',
    'attachment_single'=>'Choose one attachment per message.',
];
$waReplyConfig = ['conversations_base_url'=>url('/admin/whatsapp/conversations'), 'csrf'=>csrf_token(), 'labels'=>$waReplyLabels];
@endphp
<section class="wa-replies" data-wa-replies hidden aria-labelledby="wa-reply-title">
    <header class="wa-replies-heading"><h3 id="wa-reply-title" class="sr-only">{{ $waReplyLabels['title'] }}</h3><p class="wa-replies-status" data-wa-reply-status role="status" aria-live="polite"></p><button type="button" class="wa-inbox-button wa-icon-button" data-wa-reply-refresh title="{{ $waReplyLabels['refresh'] }}" aria-label="{{ $waReplyLabels['refresh'] }}"><i class="fas fa-sync-alt" aria-hidden="true"></i></button></header>
    <div class="wa-replies-quote" data-wa-reply-quote hidden><div><strong>{{ $waReplyLabels['quote_reply'] }}</strong><p data-wa-reply-quote-text></p></div><button type="button" class="wa-inbox-button wa-icon-button" data-wa-reply-quote-cancel title="{{ $waReplyLabels['remove_quote'] }}" aria-label="{{ $waReplyLabels['remove_quote'] }}"><i class="fas fa-times" aria-hidden="true"></i></button></div>
    <form data-wa-reply-form autocomplete="off">
        <label class="sr-only" for="wa-reply-text">{{ $waReplyLabels['title'] }}</label>
        <input type="file" data-wa-attachment-input hidden disabled accept=".jpg,.jpeg,.png,.pdf,.txt,image/jpeg,image/png,application/pdf,text/plain" aria-label="{{ $waReplyLabels['attachment'] }}">
        <div class="wa-replies-attachment" id="wa-reply-attachment-preview" data-wa-attachment-preview hidden><img data-wa-attachment-image hidden alt="{{ $waReplyLabels['attachment_preview'] }}"><i class="far fa-file-alt" aria-hidden="true"></i><div class="wa-replies-attachment-info"><strong data-wa-attachment-name dir="auto"></strong><span data-wa-attachment-size dir="ltr"></span></div><button type="button" class="wa-inbox-button wa-icon-button" data-wa-attachment-cancel title="{{ $waReplyLabels['attachment_cancel'] }}" aria-label="{{ $waReplyLabels['attachment_cancel'] }}"><i class="fas fa-times" aria-hidden="true"></i></button></div>
        <div class="wa-replies-compose"><div class="wa-replies-emoji"><button type="button" class="wa-inbox-button wa-icon-button" data-wa-reply-emoji disabled aria-expanded="false" aria-controls="wa-reply-emoji-picker" title="{{ $waReplyLabels['emoji'] }}" aria-label="{{ $waReplyLabels['emoji'] }}"><i class="far fa-smile" aria-hidden="true"></i></button><div id="wa-reply-emoji-picker" class="wa-replies-emoji-picker" data-wa-reply-emoji-picker hidden role="group" aria-label="{{ $waReplyLabels['emoji'] }}">@foreach(['😀','😊','❤️','👍','🙏','🎉','✅','🙂','😂','👋','👌','💬'] as $waEmoji)<button type="button" data-wa-emoji="{{ $waEmoji }}" aria-label="{{ $waEmoji }}">{{ $waEmoji }}</button>@endforeach</div></div><textarea id="wa-reply-text" data-wa-reply-text dir="auto" rows="1" maxlength="4096" placeholder="{{ $waReplyLabels['placeholder'] }}" disabled></textarea><button type="button" class="wa-inbox-button wa-icon-button" data-wa-attachment-select disabled aria-controls="wa-reply-attachment-preview" title="{{ $waReplyLabels['attachment'] }}" aria-label="{{ $waReplyLabels['attachment'] }}"><i class="fas fa-paperclip" aria-hidden="true"></i></button><button type="button" class="wa-inbox-button wa-icon-button wa-replies-mic" data-wa-voice-record disabled aria-pressed="false" title="{{ $waReplyLabels['record'] }}" aria-label="{{ $waReplyLabels['record'] }}"><i class="fas fa-microphone" aria-hidden="true"></i></button><button type="submit" class="wa-inbox-button wa-icon-button wa-replies-send" data-wa-reply-send disabled title="{{ $waReplyLabels['send'] }}" aria-label="{{ $waReplyLabels['send'] }}"><i class="fas fa-paper-plane" aria-hidden="true"></i><span class="sr-only" data-wa-reply-send-label>{{ $waReplyLabels['send'] }}</span></button></div>
        <footer class="wa-replies-meta"><span class="wa-replies-keyboard">{{ app()->getLocale() === 'ar' ? 'Enter للإرسال · Shift+Enter لسطر جديد' : 'Enter to send · Shift+Enter for a new line' }}</span><span data-wa-reply-count dir="ltr">0 / 4096</span></footer>
    </form>
    <div class="wa-replies-voice"><button type="button" class="wa-inbox-button" data-wa-voice-stop hidden>{{ $waReplyLabels['stop'] }}</button><span class="wa-replies-record-time" data-wa-voice-time dir="ltr" hidden></span><audio data-wa-voice-preview controls preload="metadata" hidden></audio><button type="button" class="wa-inbox-button wa-icon-button wa-replies-voice-cancel" data-wa-voice-cancel hidden title="{{ $waReplyLabels['cancel'] }}" aria-label="{{ $waReplyLabels['cancel'] }}"><i class="far fa-trash-alt" aria-hidden="true"></i></button><button type="button" class="wa-inbox-button wa-icon-button wa-replies-send" data-wa-voice-send hidden disabled title="{{ $waReplyLabels['send_voice'] }}" aria-label="{{ $waReplyLabels['send_voice'] }}"><i class="fas fa-paper-plane" aria-hidden="true"></i><span class="sr-only">{{ $waReplyLabels['send_voice'] }}</span></button></div>
    <p class="wa-replies-status" data-wa-voice-status hidden></p>
    <details class="wa-replies-audit" data-wa-reply-audit hidden><summary>{{ $waReplyLabels['audit'] }}</summary><ol data-wa-reply-audit-list></ol></details>
</section>
<script type="application/json" id="whatsapp-replies-bootstrap">@json($waReplyConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
