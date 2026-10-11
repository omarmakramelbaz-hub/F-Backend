@if(\Illuminate\Support\Facades\Route::has('whatsapp-inbox.index') && app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->canAccess(auth('admin')->user()))
<li class="nav-item">
    <a href="{{ route('whatsapp-inbox.index') }}" class="nav-link {{ request()->is('admin/whatsapp*') ? 'active' : '' }}">
        <i class="nav-icon fab fa-whatsapp" aria-hidden="true"></i>
        <p>{{ app()->getLocale() === 'ar' ? 'رسائل واتساب' : 'WhatsApp inbox' }}</p>
    </a>
</li>
@endif
