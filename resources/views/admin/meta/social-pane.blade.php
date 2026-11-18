<div id="meta-social-inbox" class="wa-inbox meta-chat" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" aria-label="{{ $waInbox['labels']['title'] }}">
    <header class="wa-inbox-heading">
        <div class="wa-inbox-brand"><span class="wa-inbox-icon"><i class="fab fa-facebook-messenger" aria-hidden="true"></i></span><div><h1 id="meta-social-title">{{ $waInbox['labels']['title'] }}</h1><p>{{ $waInbox['labels']['subtitle'] }}</p></div></div>
        <div class="wa-inbox-tools">@if(!empty($waInbox['connection_url']))<a href="{{ $waInbox['connection_url'] }}" class="wa-inbox-button btn-warning"><i class="fas fa-link" aria-hidden="true"></i> {{ app()->getLocale() === 'ar' ? 'ربط حساب ميتا' : 'Connect Meta' }}</a>@endif<span class="wa-inbox-unread-total" data-wa-unread-total role="status" aria-live="polite" hidden></span><span class="wa-inbox-connection" data-wa-connection role="status" aria-live="polite">{{ $waInbox['labels']['loading'] }}</span><button type="button" class="wa-inbox-button" data-wa-refresh><i class="fas fa-sync-alt" aria-hidden="true"></i> {{ $waInbox['labels']['refresh'] }}</button></div>
    </header>
    <div class="wa-inbox-alert" data-wa-alert role="status" aria-live="polite" hidden></div>
    <div class="wa-inbox-workspace">
        <aside class="wa-inbox-threads" aria-labelledby="meta-social-conversations-title">
            <header class="wa-inbox-panel-heading"><h2 id="meta-social-conversations-title">{{ $waInbox['labels']['conversations'] }}</h2><span class="wa-inbox-count" data-wa-count></span></header>
            <p class="wa-inbox-list-status" data-wa-list-status>{{ $waInbox['labels']['loading'] }}</p>
            <ol class="wa-inbox-thread-list" data-wa-thread-list></ol>
            <footer class="wa-inbox-list-footer"><button type="button" class="wa-inbox-button" data-wa-more-threads hidden>{{ $waInbox['labels']['more_conversations'] }}</button></footer>
        </aside>
        <section class="wa-inbox-chat" aria-labelledby="meta-social-conversation-title">
            <header class="wa-inbox-chat-heading"><button type="button" class="wa-inbox-back wa-inbox-button" data-wa-back hidden>{{ $waInbox['labels']['back'] }}</button><div><h2 id="meta-social-conversation-title" data-wa-title>{{ $waInbox['labels']['choose_conversation'] }}</h2><p data-wa-customer></p></div><span class="wa-inbox-scope">{{ $waInbox['labels']['central_scope'] }}</span></header>
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
    <details class="wa-inbox-order-drawer" data-wa-order-drawer><summary>{{ app()->getLocale() === 'ar' ? 'طلب المحادثة' : 'Conversation order' }}</summary><div class="wa-inbox-order-content">@include('admin.meta.orders')</div></details>
</div>
