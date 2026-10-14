@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-inbox.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-inbox.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-orders.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-orders.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-replies.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-replies.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-inbox.css') }}?v={{ filemtime(public_path('css/dashboard-meta-inbox.css')) }}">
@endpush
@section('content')
<div class="content-wrapper wa-inbox-wrapper">
<main id="whatsapp-inbox" class="wa-inbox meta-chat" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" aria-labelledby="wa-inbox-title">
    <header class="wa-inbox-heading">
        <div class="wa-inbox-brand"><span class="wa-inbox-icon"><i class="fab fa-facebook-messenger" aria-hidden="true"></i></span><div><h1 id="wa-inbox-title">{{ $waInbox['labels']['title'] }}</h1><p>{{ $waInbox['labels']['subtitle'] }}</p></div></div>
        <div class="wa-inbox-tools">@if(!empty($waInbox['connection_url']))<a href="{{ $waInbox['connection_url'] }}" class="wa-inbox-button btn-warning"><i class="fas fa-link" aria-hidden="true"></i> {{ app()->getLocale() === 'ar' ? 'ربط حساب ميتا' : 'Connect Meta' }}</a>@endif<span class="wa-inbox-unread-total" data-wa-unread-total role="status" aria-live="polite" hidden></span><span class="wa-inbox-connection" data-wa-connection role="status" aria-live="polite">{{ $waInbox['labels']['loading'] }}</span><button type="button" class="wa-inbox-button" data-wa-refresh><i class="fas fa-sync-alt" aria-hidden="true"></i> {{ $waInbox['labels']['refresh'] }}</button></div>
    </header>
    <div class="wa-inbox-alert" data-wa-alert role="status" aria-live="polite" hidden></div>
    @include('admin.meta.channels')
    <div class="wa-inbox-workspace">
        <aside class="wa-inbox-threads" aria-labelledby="wa-conversations-title">
            <header class="wa-inbox-panel-heading"><h2 id="wa-conversations-title">{{ $waInbox['labels']['conversations'] }}</h2><span class="wa-inbox-count" data-wa-count></span></header>
            <p class="wa-inbox-list-status" data-wa-list-status>{{ $waInbox['labels']['loading'] }}</p>
            <ol class="wa-inbox-thread-list" data-wa-thread-list></ol>
            <footer class="wa-inbox-list-footer"><button type="button" class="wa-inbox-button" data-wa-more-threads hidden>{{ $waInbox['labels']['more_conversations'] }}</button></footer>
        </aside>
        <section class="wa-inbox-chat" aria-labelledby="wa-conversation-title">
            <header class="wa-inbox-chat-heading"><button type="button" class="wa-inbox-back wa-inbox-button" data-wa-back hidden>{{ $waInbox['labels']['back'] }}</button><div><h2 id="wa-conversation-title" data-wa-title>{{ $waInbox['labels']['choose_conversation'] }}</h2><p data-wa-customer></p></div><span class="wa-inbox-scope">{{ $waInbox['labels']['central_scope'] }}</span></header>
            <div class="wa-inbox-message-scroll" data-wa-scroll tabindex="0" aria-label="{{ $waInbox['labels']['messages'] }}">
                <div class="wa-inbox-older"><button type="button" class="wa-inbox-button" data-wa-older hidden>{{ $waInbox['labels']['older_messages'] }}</button></div>
                <p class="wa-inbox-chat-status" data-wa-chat-status>{{ $waInbox['labels']['choose_conversation'] }}</p>
                <ol class="wa-inbox-message-list" data-wa-messages></ol>
            </div>
            @include('admin.meta.replies')
            <footer class="wa-inbox-chat-footer">{{ app()->getLocale() === 'ar' ? 'تظهر الرسائل الصادرة بعد وصول نسخة الرسالة من ميتا.' : 'Outgoing messages appear when a copy arrives from Meta.' }}</footer>
        </section>
    </div>
    <section class="meta-chat-overlay" data-meta-overlay hidden role="dialog" aria-modal="true" aria-labelledby="meta-overlay-title">
        <div class="meta-chat-dialog"><header><h3 id="meta-overlay-title" data-meta-overlay-title></h3><button type="button" class="wa-inbox-button" data-meta-overlay-close aria-label="{{ app()->getLocale() === 'ar' ? 'إغلاق' : 'Close' }}">×</button></header><div data-meta-overlay-body></div></div>
    </section>
    @include('admin.meta.orders')
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
$waInboxBootstrap = array_merge($waInbox, ['meta_chat'=>true, 'rich_labels'=>$metaRichLabels, 'unread_url'=>url('/admin/meta/unread'),
    'read_base_url'=>url('/admin/meta/conversations'), 'csrf'=>csrf_token(),
    'unread_label'=>app()->getLocale() === 'ar' ? 'غير مقروء' : 'Unread']);
@endphp
<script type="application/json" id="whatsapp-inbox-bootstrap">@json($waInboxBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="{{ asset('js/dashboard-meta-inbox.js') }}?v={{ filemtime(public_path('js/dashboard-meta-inbox.js')) }}"></script>
<script src="{{ asset('js/dashboard-whatsapp-orders.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-orders.js')) }}"></script>
<script src="{{ asset('js/dashboard-meta-replies.js') }}?v={{ filemtime(public_path('js/dashboard-meta-replies.js')) }}"></script>
@endpush
