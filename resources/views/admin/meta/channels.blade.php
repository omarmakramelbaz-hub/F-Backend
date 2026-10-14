@php
$isWhatsApp = ($messagesChannel ?? '') === 'whatsapp';
$isArabic = app()->getLocale() === 'ar';
$isHub = !empty($metaMessageHub);
@endphp
<nav class="meta-inbox-channels" aria-label="{{ $isArabic ? 'قناة الرسائل' : 'Message channel' }}">
@if($isHub)
<button type="button" data-hub-channel="" class="wa-inbox-button" aria-pressed="true">{{ $isArabic ? 'الكل' : 'All' }}</button>
<button type="button" data-hub-channel="messenger" class="wa-inbox-button" aria-pressed="false">{{ $isArabic ? 'ماسنجر' : 'Messenger' }}</button>
<button type="button" data-hub-channel="instagram" class="wa-inbox-button" aria-pressed="false">{{ $isArabic ? 'إنستجرام' : 'Instagram' }}</button>
@else
<a href="{{ url('/admin/meta') }}" class="wa-inbox-button">{{ $isArabic ? 'الكل' : 'All' }}</a>
<a href="{{ url('/admin/meta?channel=messenger') }}" class="wa-inbox-button">{{ $isArabic ? 'ماسنجر' : 'Messenger' }}</a>
<a href="{{ url('/admin/meta?channel=instagram') }}" class="wa-inbox-button">{{ $isArabic ? 'إنستجرام' : 'Instagram' }}</a>
@endif
@if(\Illuminate\Support\Facades\Route::has('meta-inbox.whatsapp') && app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->canAccess(auth('admin')->user()))
@if($isHub)
<button type="button" data-hub-channel="whatsapp" class="wa-inbox-button" aria-pressed="false"><i class="fab fa-whatsapp" aria-hidden="true"></i> {{ $isArabic ? 'واتساب' : 'WhatsApp' }}</button>
@else
<a href="{{ url('/admin/meta?channel=whatsapp') }}" class="wa-inbox-button" @if($isWhatsApp) aria-current="page" @endif><i class="fab fa-whatsapp" aria-hidden="true"></i> {{ $isArabic ? 'واتساب' : 'WhatsApp' }}</a>
@endif
@endif
</nav>
