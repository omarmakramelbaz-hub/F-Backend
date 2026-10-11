@if(\Illuminate\Support\Facades\Route::has('meta-inbox.index') && app(\App\Services\Dashboard\MetaInboxAccess::class)->canAccess(auth('admin')->user()))
<li class="nav-item"><a href="{{ route('meta-inbox.index') }}" class="nav-link {{ request()->is('admin/meta*') ? 'active' : '' }}"><i class="nav-icon fab fa-facebook-messenger" aria-hidden="true"></i><p>{{ app()->getLocale() === 'ar' ? 'رسائل ميتا' : 'Meta inbox' }}</p></a></li>
@endif
