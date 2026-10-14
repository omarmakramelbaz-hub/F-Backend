@extends('admin.index')
@php
$metaCommentsArabic = app()->getLocale() === 'ar';
$metaCommentsLabels = $metaCommentsArabic ? [
    'title'=>'تعليقات السوشيال ميديا', 'subtitle'=>'تعليقات بوستات فيسبوك وإنستجرام · الرد باسم الصفحة',
    'all'=>'الكل', 'facebook'=>'فيسبوك', 'instagram'=>'إنستجرام', 'account'=>'الصفحة / الحساب', 'select_account'=>'اختر الحساب',
    'posts'=>'البوستات الحديثة', 'comments'=>'التعليقات', 'refresh'=>'تحديث', 'connect'=>'إعداد ربط ميتا',
    'loading'=>'جارٍ التحميل…', 'updating'=>'جارٍ التحديث…', 'empty_accounts'=>'لا توجد صفحات أو حسابات متصلة متاحة للتعليقات. راجع ربط ميتا وصلاحيات التعليقات.',
    'empty_posts'=>'لا توجد بوستات متاحة لهذا الحساب.', 'choose_post'=>'اختر بوستًا لعرض تعليقاته والرد عليها.', 'empty_comments'=>'لا توجد تعليقات متاحة لهذا البوست.',
    'more_posts'=>'بوستات أقدم', 'more_comments'=>'تعليقات إضافية', 'more_replies'=>'ردود إضافية', 'show_replies'=>'عرض الردود', 'hide_replies'=>'إخفاء الردود',
    'reply'=>'رد', 'replies'=>'ردود', 'public'=>'الرد علني باسم', 'choose_comment'=>'اختر تعليقًا للرد عليه.', 'reply_to'=>'الرد على',
    'placeholder'=>'اكتب ردًا باسم الصفحة…', 'send'=>'نشر الرد', 'sending'=>'جارٍ إرسال الرد…', 'accepted'=>'تم نشر الرد.',
    'unknown'=>'حالة نشر الرد غير مؤكدة. تم إيقاف إعادة الإرسال لتجنب التكرار؛ راجع التعليق على فيسبوك أو إنستجرام.',
    'failed'=>'تعذّر نشر الرد. راجع الاتصال والصلاحيات ثم حاول مرة أخرى.', 'unavailable'=>'تعذّر التحميل. اضغط تحديث للمحاولة مرة أخرى.',
    'selection_renewed'=>'تم تجديد اختيار التعليق. راجع الرد واضغط نشر مرة أخرى.',
    'denied'=>'هذا الحساب لا يملك صلاحية عرض التعليقات أو الرد عليها.', 'offline'=>'لا يوجد اتصال بالإنترنت. مسودة الرد محفوظة أثناء التنقل.',
    'open_post'=>'فتح البوست', 'no_caption'=>'بوست بدون نص', 'anonymous'=>'مستخدم', 'back'=>'العودة للبوستات',
    'limit'=>'الحد الأقصى ١٠٠٠ حرف.', 'updated'=>'تم التحديث', 'no_reply'=>'الرد غير متاح على هذا التعليق.', 'count'=>'تعليق',
    'emoji'=>'إضافة إيموجي', 'emoji_picker'=>'اختيار إيموجي', 'emoji_reactions'=>'وجوه وتفاعلات', 'emoji_hearts'=>'قلوب وإشارات', 'emoji_extras'=>'رموز ومناسبات', 'emoji_close'=>'إغلاق',
    'permissions'=>'الصلاحيات المطلوبة', 'public_hint'=>'يظهر الرد للجميع أسفل التعليق، باسم الصفحة أو حساب النشاط المحدد.',
] : [
    'title'=>'Social comments', 'subtitle'=>'Facebook and Instagram post comments · Reply as the page',
    'all'=>'All', 'facebook'=>'Facebook', 'instagram'=>'Instagram', 'account'=>'Page / account', 'select_account'=>'Choose an account',
    'posts'=>'Recent posts', 'comments'=>'Comments', 'refresh'=>'Refresh', 'connect'=>'Meta connection settings',
    'loading'=>'Loading…', 'updating'=>'Updating…', 'empty_accounts'=>'No connected pages or accounts are available for comments. Check the Meta connection and comment permissions.',
    'empty_posts'=>'No posts are available for this account.', 'choose_post'=>'Choose a post to read and reply to its comments.', 'empty_comments'=>'No comments are available for this post.',
    'more_posts'=>'Older posts', 'more_comments'=>'More comments', 'more_replies'=>'More replies', 'show_replies'=>'Show replies', 'hide_replies'=>'Hide replies',
    'reply'=>'Reply', 'replies'=>'replies', 'public'=>'Public reply as', 'choose_comment'=>'Choose a comment to reply to.', 'reply_to'=>'Replying to',
    'placeholder'=>'Write a reply as the page…', 'send'=>'Publish reply', 'sending'=>'Publishing…', 'accepted'=>'Reply published.',
    'unknown'=>'Publishing status is uncertain. Resending is blocked to prevent duplicates; check the comment on Facebook or Instagram.',
    'failed'=>'The reply could not be published. Check the connection and permissions, then try again.', 'unavailable'=>'Unable to load. Choose Refresh to try again.',
    'selection_renewed'=>'Comment selection refreshed. Review your reply and publish again.',
    'denied'=>'This account cannot view or reply to comments.', 'offline'=>'No internet connection. Your draft is kept while you navigate.',
    'open_post'=>'Open post', 'no_caption'=>'Post without a caption', 'anonymous'=>'User', 'back'=>'Back to posts',
    'limit'=>'Maximum 1,000 characters.', 'updated'=>'Updated', 'no_reply'=>'Replies are unavailable for this comment.', 'count'=>'comments',
    'emoji'=>'Add emoji', 'emoji_picker'=>'Choose an emoji', 'emoji_reactions'=>'Faces & reactions', 'emoji_hearts'=>'Hearts & gestures', 'emoji_extras'=>'Symbols & occasions', 'emoji_close'=>'Close',
    'permissions'=>'Required permissions', 'public_hint'=>'The reply is visible to everyone below the comment, as the selected page or business account.',
];
$metaCommentsBootstrap = array_merge($metaComments ?? [], ['csrf'=>csrf_token(), 'labels'=>$metaCommentsLabels, 'locale'=>app()->getLocale()]);
$metaCommentAiLabels = $metaCommentsArabic ? [
    'title'=>'الرد الآلي على التعليقات', 'loading'=>'جارٍ تحميل حالة AI…', 'start'=>'تشغيل AI', 'stop'=>'إيقاف AI',
    'schedule'=>'الجدولة', 'always'=>'طوال الوقت', 'weekly'=>'أيام وساعات محددة', 'days'=>'أيام التشغيل',
    'from'=>'من', 'to'=>'إلى', 'apply'=>'حفظ الجدولة', 'timezone'=>'المواعيد بتوقيت مصر · يمتد الموعد لليوم التالي إذا كانت النهاية قبل البداية.',
    'policy'=>'استفسارات الأسعار: توجيه العميل للاتصال على 01285545554.',
    'off'=>'متوقف', 'active'=>'مفعّل الآن', 'scheduled'=>'مفعّل خارج المواعيد', 'saving'=>'جارٍ الحفظ…',
    'unconfigured'=>'AI غير جاهز؛ راجع إعداداته.', 'unavailable'=>'تعذّر تحميل حالة AI. أعد فتح الصفحة للمحاولة.',
    'denied'=>'إدارة الرد الآلي متاحة للمالك فقط.', 'owner_only'=>'يمكن للمالك تغيير التشغيل والمواعيد.',
    'worker_wait'=>'بانتظار اتصال عامل الرد الآلي.', 'invalid'=>'اختر أيام التشغيل وحدد وقت بداية ونهاية مختلفين.',
    'changed'=>'تغيرت الإعدادات. تم تحميل آخر حالة؛ راجعها قبل الحفظ.', 'uncertain'=>'تعذّر تأكيد الحفظ. تم تحديث الحالة؛ راجعها قبل المحاولة.',
    'busy'=>'يوجد رد جارٍ الإرسال. راجع الحالة ثم أعد المحاولة.',
    'review'=>'مفعّل · يحتاج مراجعة', 'cycle_failed'=>'تعذّر تنفيذ آخر دورة للرد. راجع الاتصال وإعدادات AI.',
    'reconnect'=>'ربط ميتا يحتاج تجديدًا قبل الرد الآلي.', 'permissions'=>'راجع صلاحيات التعليقات لحساب النشاط في ميتا.',
    'revoked'=>'تغيرت صلاحيات الحساب؛ يحتاج الرد الآلي مراجعة الربط.',
] : [
    'title'=>'AI comment replies', 'loading'=>'Loading AI status…', 'start'=>'Start AI', 'stop'=>'Stop AI',
    'schedule'=>'Schedule', 'always'=>'Always', 'weekly'=>'Selected days and hours', 'days'=>'Operating days',
    'from'=>'From', 'to'=>'Until', 'apply'=>'Save schedule', 'timezone'=>'Egypt time · An end before the start continues into the next day.',
    'policy'=>'Price questions: direct customers to call 01285545554.',
    'off'=>'Stopped', 'active'=>'Enabled now', 'scheduled'=>'Enabled outside operating hours', 'saving'=>'Saving…',
    'unconfigured'=>'AI is not configured. Check its settings.', 'unavailable'=>'Unable to load AI status. Reopen this page to retry.',
    'denied'=>'Only the Owner can manage AI replies.', 'owner_only'=>'The Owner can change operation and hours.',
    'worker_wait'=>'Waiting for the AI reply worker.', 'invalid'=>'Choose operating days and different start and end times.',
    'changed'=>'Settings changed. The latest state is loaded; review it before saving.', 'uncertain'=>'Saving could not be confirmed. State was refreshed; review it before retrying.',
    'busy'=>'A reply is being sent. Review the state, then retry.',
    'review'=>'Enabled · review needed', 'cycle_failed'=>'The last reply cycle failed. Check the connection and AI settings.',
    'reconnect'=>'Reconnect Meta before automated replies can continue.', 'permissions'=>'Review comment permissions for the business account.',
    'revoked'=>'Account access changed. Review the Meta connection.',
];
$metaCommentAiBootstrap = ['csrf'=>csrf_token(), 'labels'=>$metaCommentAiLabels,
    'urls'=>['status'=>route('meta-comment-ai.status'), 'update'=>route('meta-comment-ai.update')]];
@endphp
@push('custom-css')
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-comments.css') }}?v={{ filemtime(public_path('css/dashboard-meta-comments.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-comment-ai.css') }}?v={{ filemtime(public_path('css/dashboard-meta-comment-ai.css')) }}">
@endpush
@section('content')
<div class="content-wrapper meta-comments-wrapper">
<main id="meta-comments" class="meta-comments" dir="{{ $metaCommentsArabic ? 'rtl' : 'ltr' }}" aria-labelledby="meta-comments-title">
    <header class="mc-heading">
        <div class="mc-brand"><span class="mc-brand-icon"><i class="far fa-comment-dots" aria-hidden="true"></i></span><div><h1 id="meta-comments-title">{{ $metaCommentsLabels['title'] }}</h1><p>{{ $metaCommentsLabels['subtitle'] }}</p></div></div>
        <div class="mc-tools">@if(!empty($metaComments['connection_url']))<a class="mc-button mc-secondary" href="{{ $metaComments['connection_url'] }}"><i class="fas fa-link" aria-hidden="true"></i> {{ $metaCommentsLabels['connect'] }}</a>@endif<button type="button" class="mc-button" data-mc-refresh><i class="fas fa-sync-alt" aria-hidden="true"></i> {{ $metaCommentsLabels['refresh'] }}</button></div>
    </header>
    <section id="meta-comment-ai" class="mc-ai" aria-label="{{ $metaCommentAiLabels['title'] }}">
        <div class="mc-ai-heading"><div class="mc-ai-copy"><strong>{{ $metaCommentAiLabels['title'] }}</strong><span class="mc-ai-state" data-mcai-state role="status" aria-live="polite">{{ $metaCommentAiLabels['loading'] }}</span></div><div class="mc-ai-actions"><button type="button" data-mcai-toggle aria-pressed="false" disabled>{{ $metaCommentAiLabels['start'] }}</button><button type="button" data-mcai-schedule-toggle aria-expanded="false" aria-controls="mc-ai-form" disabled>{{ $metaCommentAiLabels['schedule'] }}</button></div></div>
        <p class="mc-ai-policy">{{ $metaCommentAiLabels['policy'] }}</p><p class="mc-ai-worker" data-mcai-worker hidden></p><p class="mc-ai-message" data-mcai-message role="status" aria-live="polite" hidden></p>
        <form id="mc-ai-form" class="mc-ai-form" data-mcai-form hidden>
            <div class="mc-ai-fields"><label class="mc-ai-field"><span>{{ $metaCommentAiLabels['schedule'] }}</span><select data-mcai-mode disabled><option value="always">{{ $metaCommentAiLabels['always'] }}</option><option value="scheduled">{{ $metaCommentAiLabels['weekly'] }}</option></select></label><div data-mcai-schedule-fields hidden><fieldset class="mc-ai-days"><legend>{{ $metaCommentAiLabels['days'] }}</legend>@foreach([6=>['السبت','Saturday'],7=>['الأحد','Sunday'],1=>['الاثنين','Monday'],2=>['الثلاثاء','Tuesday'],3=>['الأربعاء','Wednesday'],4=>['الخميس','Thursday'],5=>['الجمعة','Friday']] as $day=>$dayLabels)<label><input type="checkbox" data-mcai-day value="{{ $day }}" disabled> {{ $dayLabels[$metaCommentsArabic ? 0 : 1] }}</label>@endforeach</fieldset><div class="mc-ai-schedule"><label class="mc-ai-field"><span>{{ $metaCommentAiLabels['from'] }}</span><input type="time" data-mcai-start value="10:00" disabled></label><label class="mc-ai-field"><span>{{ $metaCommentAiLabels['to'] }}</span><input type="time" data-mcai-end value="22:00" disabled></label></div></div><div class="mc-ai-form-actions"><button type="submit" data-mcai-submit disabled>{{ $metaCommentAiLabels['apply'] }}</button><span class="mc-ai-zone">{{ $metaCommentAiLabels['timezone'] }}</span></div></div>
        </form>
    </section>
    <div class="mc-filters"><div class="mc-platforms" role="group" aria-label="{{ $metaCommentsLabels['title'] }}">@foreach([''=>'all','facebook'=>'facebook','instagram'=>'instagram'] as $channel=>$label)<button type="button" class="mc-filter" data-mc-channel="{{ $channel }}" aria-pressed="{{ $channel === '' ? 'true' : 'false' }}">@if($channel)<i class="fab fa-{{ $channel === 'facebook' ? 'facebook-f' : 'instagram' }}" aria-hidden="true"></i>@endif {{ $metaCommentsLabels[$label] }}</button>@endforeach</div><label class="mc-account-field"><span>{{ $metaCommentsLabels['account'] }}</span><select data-mc-account aria-label="{{ $metaCommentsLabels['account'] }}" disabled><option value="">{{ $metaCommentsLabels['loading'] }}</option></select></label></div>
    <p class="mc-alert" data-mc-alert role="status" aria-live="polite" hidden></p>
    <div class="mc-workspace">
        <aside class="mc-posts-panel" aria-labelledby="mc-posts-title"><header class="mc-panel-heading"><h2 id="mc-posts-title">{{ $metaCommentsLabels['posts'] }}</h2><span class="mc-count" data-mc-post-count></span></header><div class="mc-posts-scroll" tabindex="0"><p class="mc-status" data-mc-post-status role="status" aria-live="polite">{{ $metaCommentsLabels['loading'] }}</p><ol class="mc-post-list" data-mc-posts></ol></div><footer class="mc-panel-footer"><button type="button" class="mc-button mc-secondary" data-mc-more-posts hidden>{{ $metaCommentsLabels['more_posts'] }}</button></footer></aside>
        <section class="mc-comments-panel" aria-labelledby="mc-comments-title"><header class="mc-panel-heading mc-post-heading"><button type="button" class="mc-button mc-back" data-mc-back aria-label="{{ $metaCommentsLabels['back'] }}"><i class="fas fa-arrow-right" aria-hidden="true"></i></button><div class="mc-selected-post"><h2 id="mc-comments-title">{{ $metaCommentsLabels['comments'] }}</h2><p data-mc-post-title>{{ $metaCommentsLabels['choose_post'] }}</p></div><a class="mc-button mc-secondary" data-mc-post-link href="#" target="_blank" rel="noopener noreferrer" hidden>{{ $metaCommentsLabels['open_post'] }} <i class="fas fa-external-link-alt" aria-hidden="true"></i></a></header>
            <div class="mc-comments-scroll" data-mc-scroll tabindex="0"><p class="mc-status mc-comments-status" data-mc-comment-status role="status" aria-live="polite">{{ $metaCommentsLabels['choose_post'] }}</p><ol class="mc-comment-list" data-mc-comments></ol><button type="button" class="mc-button mc-secondary mc-more-comments" data-mc-more-comments hidden>{{ $metaCommentsLabels['more_comments'] }}</button></div>
            <form class="mc-composer" data-mc-form autocomplete="off"><div class="mc-reply-heading"><strong data-mc-reply-target>{{ $metaCommentsLabels['choose_comment'] }}</strong><span class="mc-public" data-mc-public>{{ $metaCommentsLabels['public_hint'] }}</span></div><blockquote class="mc-reply-preview" data-mc-reply-preview hidden></blockquote><label class="sr-only" for="mc-reply-text">{{ $metaCommentsLabels['reply'] }}</label><div class="mc-compose-row"><textarea id="mc-reply-text" data-mc-reply-text rows="2" maxlength="2000" dir="auto" placeholder="{{ $metaCommentsLabels['placeholder'] }}" disabled></textarea><div class="mc-emoji-wrap" data-mc-emoji-wrap><button type="button" class="mc-button mc-secondary mc-emoji-toggle" data-mc-emoji-toggle disabled aria-expanded="false" aria-controls="mc-emoji-picker" aria-label="{{ $metaCommentsLabels['emoji'] }}" title="{{ $metaCommentsLabels['emoji'] }}"><i class="far fa-smile" aria-hidden="true"></i></button><section id="mc-emoji-picker" class="mc-emoji-picker" data-mc-emoji-picker role="dialog" aria-label="{{ $metaCommentsLabels['emoji_picker'] }}" hidden><header class="mc-emoji-heading"><strong>{{ $metaCommentsLabels['emoji_picker'] }}</strong><button type="button" class="mc-emoji-close" data-mc-emoji-close aria-label="{{ $metaCommentsLabels['emoji_close'] }}">×</button></header><div class="mc-emoji-tabs" data-mc-emoji-tabs role="group" aria-label="{{ $metaCommentsLabels['emoji_picker'] }}"></div><div class="mc-emoji-grid" data-mc-emoji-grid role="group" aria-label="{{ $metaCommentsLabels['emoji_picker'] }}"></div></section></div><button type="submit" class="mc-button mc-send" data-mc-send disabled><i class="fas fa-paper-plane" aria-hidden="true"></i> <span data-mc-send-label>{{ $metaCommentsLabels['send'] }}</span></button></div><div class="mc-compose-footer"><p class="mc-send-status" data-mc-send-status role="status" aria-live="polite"></p><span data-mc-letter-count dir="ltr">0 / 1000</span></div></form>
        </section>
    </div>
</main>
</div>
@endsection
@push('custom-js')
<script type="application/json" id="meta-comments-bootstrap">@json($metaCommentsBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script type="application/json" id="meta-comment-ai-bootstrap">@json($metaCommentAiBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="{{ asset('js/dashboard-meta-comment-ai.js') }}?v={{ filemtime(public_path('js/dashboard-meta-comment-ai.js')) }}"></script>
<script data-dashboard-page-init>(function () { if (window.DashboardMetaCommentAi) window.DashboardMetaCommentAi.mount(); }());</script>
<script src="{{ asset('js/dashboard-meta-comments.js') }}?v={{ filemtime(public_path('js/dashboard-meta-comments.js')) }}"></script>
<script data-dashboard-page-init>
(function () {
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    if (window.DashboardMetaComments) window.DashboardMetaComments.mount();
}());
</script>
@endpush
