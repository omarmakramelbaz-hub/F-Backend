@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-inbox.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-inbox.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-orders.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-orders.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-replies.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-replies.css')) }}">
@endpush
@section('content')
<div class="content-wrapper wa-inbox-wrapper">
<main id="whatsapp-inbox" class="wa-inbox" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" aria-labelledby="wa-inbox-title">
    <header class="wa-inbox-heading">
        <div class="wa-inbox-brand"><span class="wa-inbox-icon"><i class="fab fa-whatsapp" aria-hidden="true"></i></span><div><h1 id="wa-inbox-title">{{ $waInbox['labels']['title'] }}</h1><p>{{ $waInbox['labels']['subtitle'] }}</p></div></div>
        <div class="wa-inbox-tools"><span class="wa-inbox-unread-total" data-wa-unread-total role="status" aria-live="polite" hidden></span><span class="wa-inbox-connection" data-wa-connection role="status" aria-live="polite">{{ $waInbox['labels']['loading'] }}</span><button type="button" class="wa-inbox-button wa-icon-button" data-wa-refresh title="{{ $waInbox['labels']['refresh'] }}" aria-label="{{ $waInbox['labels']['refresh'] }}"><i class="fas fa-sync-alt" aria-hidden="true"></i></button></div>
    </header>
    <div class="wa-inbox-alert" data-wa-alert role="status" aria-live="polite" hidden></div>
    <div class="wa-inbox-workspace">
        <aside class="wa-inbox-threads" aria-labelledby="wa-conversations-title">
            <header class="wa-inbox-panel-heading"><h2 id="wa-conversations-title">{{ $waInbox['labels']['conversations'] }}</h2><span class="wa-inbox-count" data-wa-count></span></header>
            <p class="wa-inbox-list-status" data-wa-list-status>{{ $waInbox['labels']['loading'] }}</p>
            <div class="wa-inbox-thread-scroll" data-wa-thread-scroll tabindex="0" aria-label="{{ $waInbox['labels']['conversations'] }}"><ol class="wa-inbox-thread-list" data-wa-thread-list></ol><div class="wa-scroll-sentinel" data-wa-conversation-sentinel aria-hidden="true"></div></div>
            <footer class="wa-inbox-list-footer"><button type="button" class="wa-inbox-button" data-wa-more-threads hidden>{{ $waInbox['labels']['more_conversations'] }}</button></footer>
        </aside>
        <section class="wa-inbox-chat" aria-labelledby="wa-conversation-title">
            <header class="wa-inbox-chat-heading"><button type="button" class="wa-inbox-back wa-inbox-button wa-icon-button" data-wa-back hidden title="{{ $waInbox['labels']['back'] }}" aria-label="{{ $waInbox['labels']['back'] }}"><i class="fas fa-arrow-right" aria-hidden="true"></i></button><span class="wa-inbox-chat-avatar" aria-hidden="true"><i class="far fa-user"></i></span><div class="wa-inbox-chat-summary"><h2 id="wa-conversation-title" data-wa-title>{{ $waInbox['labels']['choose_conversation'] }}</h2><p data-wa-customer></p></div><div class="wa-inbox-chat-tools"><span class="wa-inbox-scope">{{ $waInbox['labels']['central_scope'] }}</span><button type="button" class="wa-inbox-button wa-icon-button" data-wa-select-mode aria-pressed="false" title="{{ app()->getLocale() === 'ar' ? 'تحديد الرسائل' : 'Select messages' }}" aria-label="{{ app()->getLocale() === 'ar' ? 'تحديد الرسائل' : 'Select messages' }}"><i class="far fa-check-square" aria-hidden="true"></i></button></div></header>
            <div class="wa-selection-toolbar" data-wa-selection-toolbar hidden role="group" aria-label="{{ app()->getLocale() === 'ar' ? 'الرسائل المحددة' : 'Selected messages' }}"><span class="wa-selection-count" data-wa-selection-count role="status" aria-live="polite"></span><button type="button" class="wa-inbox-button" data-wa-select-all title="{{ app()->getLocale() === 'ar' ? 'اختيار كل رسائل المحادثة الحالية' : 'Select every existing message in this conversation' }}">{{ app()->getLocale() === 'ar' ? 'تحديد الكل' : 'Select all' }}</button><button type="button" class="wa-inbox-button wa-selection-delete" data-wa-delete-selected disabled><i class="far fa-trash-alt" aria-hidden="true"></i><span>{{ app()->getLocale() === 'ar' ? 'حذف من الداشبورد' : 'Delete from dashboard' }}</span></button><button type="button" class="wa-inbox-button wa-icon-button" data-wa-selection-cancel title="{{ app()->getLocale() === 'ar' ? 'إلغاء التحديد' : 'Cancel selection' }}" aria-label="{{ app()->getLocale() === 'ar' ? 'إلغاء التحديد' : 'Cancel selection' }}"><i class="fas fa-times" aria-hidden="true"></i></button></div>
            <div class="wa-inbox-message-area"><div class="wa-inbox-message-scroll" data-wa-scroll tabindex="0" aria-label="{{ $waInbox['labels']['messages'] }}">
                <div class="wa-inbox-older"><button type="button" class="wa-inbox-button" data-wa-older hidden>{{ $waInbox['labels']['older_messages'] }}</button></div>
                <div class="wa-scroll-sentinel" data-wa-message-sentinel aria-hidden="true"></div>
                <p class="wa-inbox-chat-status" data-wa-chat-status>{{ $waInbox['labels']['choose_conversation'] }}</p>
                <ol class="wa-inbox-message-list" data-wa-messages></ol>
            </div><div class="wa-new-messages"><button type="button" class="wa-inbox-button" data-wa-new-messages hidden><i class="fas fa-arrow-down" aria-hidden="true"></i>{{ app()->getLocale() === 'ar' ? 'رسائل جديدة' : 'New messages' }}</button></div></div>
            @include('admin.whatsapp.replies')
        </section>
    </div>
    <details class="wa-inbox-order-drawer" data-wa-order-drawer><summary><span><i class="fas fa-clipboard-list" aria-hidden="true"></i>{{ app()->getLocale() === 'ar' ? 'طلب المحادثة' : 'Conversation order' }}</span><small>{{ app()->getLocale() === 'ar' ? 'التحليل وبيانات العميل وإرسال الطلب للفرع' : 'Analysis, customer details and branch dispatch' }}</small><i class="fas fa-chevron-down" aria-hidden="true"></i></summary><div class="wa-inbox-order-content">@include('admin.whatsapp.orders')</div></details>
</main>
</div>
@endsection
@push('custom-js')
@php
$waInboxBootstrap = array_merge($waInbox, ['unread_url'=>url('/admin/whatsapp/unread'),
    'read_base_url'=>url('/admin/whatsapp/conversations'), 'csrf'=>csrf_token(),
    'unread_label'=>app()->getLocale() === 'ar' ? 'محادثات غير مقروءة' : 'Unread conversations']);
@endphp
<script type="application/json" id="whatsapp-inbox-bootstrap">@json($waInboxBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="{{ asset('js/dashboard-whatsapp-inbox.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-inbox.js')) }}"></script>
<script src="{{ asset('js/dashboard-whatsapp-orders.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-orders.js')) }}"></script>
<script src="{{ asset('js/dashboard-whatsapp-replies.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-replies.js')) }}"></script>
<script data-dashboard-page-init>
(function () {
    'use strict';
    var spa = window.DashboardSPA;
    if (spa && typeof spa.isCurrentPage === 'function' && !spa.isCurrentPage()) return;
    // The dashboard caches these assets; mount their existing modules for each visit.
    ['DashboardWhatsAppInbox', 'DashboardWhatsAppOrders', 'DashboardWhatsAppReplies'].forEach(function (name) {
        var module = window[name];
        if (module && typeof module.mount === 'function') module.mount();
    });
}());
</script>
@endpush
