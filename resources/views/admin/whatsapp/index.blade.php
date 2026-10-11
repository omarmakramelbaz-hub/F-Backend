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
        <div class="wa-inbox-tools"><span class="wa-inbox-unread-total" data-wa-unread-total role="status" aria-live="polite" hidden></span><span class="wa-inbox-connection" data-wa-connection role="status" aria-live="polite">{{ $waInbox['labels']['loading'] }}</span><button type="button" class="wa-inbox-button" data-wa-refresh><i class="fas fa-sync-alt" aria-hidden="true"></i> {{ $waInbox['labels']['refresh'] }}</button></div>
    </header>
    <p class="wa-inbox-note"><i class="fas fa-info-circle" aria-hidden="true"></i> {{ $waInbox['labels']['read_only'] }}</p>
    <div class="wa-inbox-alert" data-wa-alert role="status" aria-live="polite" hidden></div>
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
            @include('admin.whatsapp.replies')
            <footer class="wa-inbox-chat-footer">{{ app()->getLocale() === 'ar' ? 'تظهر الرسائل الصادرة بعد وصول نسخة الرسالة من واتساب.' : 'Outgoing messages appear when a copy arrives from WhatsApp.' }}</footer>
        </section>
    </div>
    @include('admin.whatsapp.orders')
</main>
</div>
@endsection
@push('custom-js')
@php
$waInboxBootstrap = array_merge($waInbox, ['unread_url'=>url('/admin/whatsapp/unread'),
    'read_base_url'=>url('/admin/whatsapp/conversations'), 'csrf'=>csrf_token(),
    'unread_label'=>app()->getLocale() === 'ar' ? 'غير مقروء' : 'Unread']);
@endphp
<script type="application/json" id="whatsapp-inbox-bootstrap">@json($waInboxBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="{{ asset('js/dashboard-whatsapp-inbox.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-inbox.js')) }}"></script>
<script src="{{ asset('js/dashboard-whatsapp-orders.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-orders.js')) }}"></script>
<script src="{{ asset('js/dashboard-whatsapp-replies.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-replies.js')) }}"></script>
@endpush
