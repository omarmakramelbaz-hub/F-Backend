@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-inbox.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-inbox.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-orders.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-orders.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-whatsapp-replies.css') }}?v={{ filemtime(public_path('css/dashboard-whatsapp-replies.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard-meta-inbox.css') }}?v={{ filemtime(public_path('css/dashboard-meta-inbox.css')) }}">
@endpush
@section('content')
<div class="content-wrapper wa-inbox-wrapper">
@include('admin.whatsapp.chat-pane')
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
