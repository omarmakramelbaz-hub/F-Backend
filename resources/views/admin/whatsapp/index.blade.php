@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-inbox.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-inbox.css')) }}">
@endpush
@section('content')
<div class="content-wrapper wa-inbox-wrapper">
<main id="whatsapp-inbox" class="wa-inbox" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" aria-labelledby="wa-inbox-title">
    <header class="wa-inbox-heading">
        <div class="wa-inbox-brand"><span class="wa-inbox-icon"><i class="fab fa-whatsapp" aria-hidden="true"></i></span><div><h1 id="wa-inbox-title">{{ $waInbox['labels']['title'] }}</h1><p>{{ $waInbox['labels']['subtitle'] }}</p></div></div>
        <div class="wa-inbox-tools"><span class="wa-inbox-connection" data-wa-connection role="status" aria-live="polite">{{ $waInbox['labels']['loading'] }}</span><button type="button" class="wa-inbox-button" data-wa-refresh><i class="fas fa-sync-alt" aria-hidden="true"></i> {{ $waInbox['labels']['refresh'] }}</button></div>
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
            <footer class="wa-inbox-chat-footer">{{ $waInbox['labels']['no_send'] }}</footer>
        </section>
    </div>
</main>
</div>
@endsection
@push('custom-js')
<script type="application/json" id="whatsapp-inbox-bootstrap">@json($waInbox, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="{{ asset('js/dashboard-whatsapp-inbox.js') }}?v={{ filemtime(public_path('js/dashboard-whatsapp-inbox.js')) }}"></script>
@endpush
