@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-inbox.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-inbox.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-orders.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-orders.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-replies.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-replies.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-inbox.css') }}?v={{ filemtime(public_path('css/dashboard-meta-inbox.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-message-hub.css') }}?v={{ filemtime(public_path('css/dashboard-meta-message-hub.css')) }}">
@endpush
@section('content')
<div class="content-wrapper wa-inbox-wrapper">
<main id="meta-message-hub" class="wa-inbox meta-message-hub" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" aria-labelledby="meta-message-hub-title">
    <header class="wa-inbox-heading">
        <div class="wa-inbox-brand"><span class="wa-inbox-icon"><i class="fas fa-comments" aria-hidden="true"></i></span><div><h1 id="meta-message-hub-title">{{ $waInbox['labels']['title'] }}</h1><p>{{ app()->getLocale() === 'ar' ? 'فسخانستا · واتساب / ماسنجر / إنستجرام' : 'Fasakhansta · WhatsApp / Messenger / Instagram' }}</p></div></div>
        <div class="wa-inbox-tools">@if(!empty($waInbox['connection_url']))<a href="{{ $waInbox['connection_url'] }}" class="wa-inbox-button"><i class="fas fa-link" aria-hidden="true"></i> {{ app()->getLocale() === 'ar' ? 'ربط ميتا' : 'Connect Meta' }}</a>@endif<span class="wa-inbox-unread-total" data-hub-unread hidden></span><button type="button" class="wa-inbox-button wa-icon-button" data-hub-refresh title="{{ $waInbox['labels']['refresh'] }}" aria-label="{{ $waInbox['labels']['refresh'] }}"><i class="fas fa-sync-alt" aria-hidden="true"></i></button></div>
    </header>
    @include('admin.meta.channels', ['metaMessageHub' => true])
    <div class="wa-inbox-workspace">
        <aside class="wa-inbox-threads" aria-labelledby="meta-hub-conversations-title"><header class="wa-inbox-panel-heading"><h2 id="meta-hub-conversations-title">{{ $waInbox['labels']['conversations'] }}</h2><span class="wa-inbox-count" data-hub-count></span></header><p class="wa-inbox-list-status" data-hub-status role="status" aria-live="polite">{{ $waInbox['labels']['loading'] }}</p><div class="wa-inbox-thread-scroll" tabindex="0"><ol class="wa-inbox-thread-list" data-hub-threads></ol></div><footer class="wa-inbox-list-footer"><button type="button" class="wa-inbox-button" data-hub-more hidden>{{ $waInbox['labels']['more_conversations'] }}</button></footer></aside>
        <div class="meta-hub-chat-area"><div class="meta-hub-empty" data-hub-empty><i class="far fa-comments" aria-hidden="true"></i><p>{{ $waInbox['labels']['choose_conversation'] }}</p></div>
            <section class="meta-hub-pane" data-hub-pane="meta" hidden>@include('admin.meta.social-pane', ['metaMessageHub' => true])</section>
            @if($whatsAppInbox)
            <section class="meta-hub-pane" data-hub-pane="whatsapp" hidden>@include('admin.whatsapp.chat-pane', ['waInbox' => $whatsAppInbox, 'metaMessageHub' => true])</section>
            @endif
        </div>
    </div>
</main>
</div>
@endsection
@push('custom-js')
@php
$metaRichLabels = app()->getLocale() === 'ar' ? [
    'media_unavailable'=>'تعذّر تحميل المرفق.', 'actions'=>'إجراءات الرسالة', 'copy'=>'نسخ النص', 'copied'=>'تم نسخ النص', 'copy_failed'=>'تعذّر نسخ النص',
    'forward'=>'إعادة توجيه', 'hide'=>'مسح من الداشبورد', 'hide_note'=>'ستُمسح الرسالة من لوحتك فقط، وتظل المحادثة محفوظة لدى العميل وميتا.',
    'hidden_message'=>'تم مسح هذه الرسالة من الداشبورد.', 'image'=>'صورة', 'document'=>'ملف', 'open_image'=>'عرض الصورة',
    'choose_part'=>'اختر النص أو المرفق المطلوب إعادة توجيهه.', 'text_part'=>'النص', 'forward_unavailable'=>'هذا النوع من الرسائل لا يدعم إعادة التوجيه حاليًا.',
    'choose_recipient'=>'اختر العميل، ثم راجع الرسالة واضغط إرسال داخل محادثته.', 'no_recipients'=>'لا توجد محادثات أخرى من نفس حساب ميتا في القائمة الحالية.',
] : [
    'media_unavailable'=>'Media unavailable.', 'actions'=>'Message actions', 'copy'=>'Copy text', 'copied'=>'Text copied', 'copy_failed'=>'Copy unavailable',
    'forward'=>'Forward', 'hide'=>'Delete from dashboard', 'hide_note'=>'This only hides the message in your dashboard; the customer and Meta keep the conversation.',
    'hidden_message'=>'This message was deleted from your dashboard.', 'image'=>'Image', 'document'=>'File', 'open_image'=>'Open image',
    'choose_part'=>'Choose the text or attachment to forward.', 'text_part'=>'Text', 'forward_unavailable'=>'Forwarding is unavailable for this message type.',
    'choose_recipient'=>'Choose a customer, then review and send in their conversation.', 'no_recipients'=>'No other conversations from this Meta account are loaded.',
];
$waInboxBootstrap = array_merge($waInbox, ['meta_chat'=>true, 'hub_chat'=>true, 'rich_labels'=>$metaRichLabels, 'unread_url'=>url('/admin/meta/unread'),
    'read_base_url'=>url('/admin/meta/conversations'), 'csrf'=>csrf_token(),
    'unread_label'=>app()->getLocale() === 'ar' ? 'غير مقروء' : 'Unread']);
@endphp
<script type="application/json" id="meta-social-inbox-bootstrap">@json($waInboxBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
@if($whatsAppInbox)
@php
$whatsAppBootstrap = array_merge($whatsAppInbox, ['hub_chat'=>true, 'unread_url'=>url('/admin/whatsapp/unread'), 'read_base_url'=>url('/admin/whatsapp/conversations'), 'csrf'=>csrf_token(), 'unread_label'=>app()->getLocale() === 'ar' ? 'غير مقروء' : 'Unread']);
@endphp
<script type="application/json" id="whatsapp-inbox-bootstrap">@json($whatsAppBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="{{ asset('js/dashboard-whatsapp-inbox.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-inbox.js')) }}"></script>
<script src="{{ asset('js/dashboard-whatsapp-replies.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-replies.js')) }}"></script>
@endif
<script src="{{ asset('js/dashboard-meta-inbox.js') }}?v={{ filemtime(public_path('js/dashboard-meta-inbox.js')) }}"></script>
<script src="{{ asset('js/dashboard-whatsapp-orders.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-orders.js')) }}"></script>
<script src="{{ asset('js/dashboard-meta-replies.js') }}?v={{ filemtime(public_path('js/dashboard-meta-replies.js')) }}"></script>
<script type="application/json" id="meta-message-hub-bootstrap">@json(['initial_channel'=>$waInbox['initial_channel'], 'labels'=>$waInbox['labels']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="{{ asset('js/dashboard-meta-message-hub.js') }}?v={{ filemtime(public_path('js/dashboard-meta-message-hub.js')) }}"></script>
<script data-dashboard-page-init>
(function () {
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    ['DashboardWhatsAppInbox','DashboardWhatsAppReplies','DashboardMetaInbox','DashboardMetaReplies','DashboardWhatsAppOrders','DashboardMetaMessageHub'].forEach(function(name) {
        var module=window[name]; if(module && module.mount) module.mount();
    });
}());
</script>
@endpush
