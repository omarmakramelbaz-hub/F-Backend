@if(\Illuminate\Support\Facades\Route::has('meta-inbox.index') && app(\App\Services\Dashboard\MetaInboxAccess::class)->canAccess(auth('admin')->user()))
<li class="nav-item"><a href="{{ route('meta-inbox.index') }}" class="nav-link {{ request()->is('admin/meta*', 'admin/whatsapp*') && !request()->is('admin/meta/comments*') ? 'active' : '' }}"><i class="nav-icon fab fa-facebook-messenger" aria-hidden="true"></i><p>{{ app()->getLocale() === 'ar' ? 'رسائل ميتا' : 'Meta inbox' }}</p></a></li>
@if(\Illuminate\Support\Facades\Route::has('meta-comments.index'))
<li class="nav-item"><a href="{{ route('meta-comments.index') }}" class="nav-link {{ request()->is('admin/meta/comments*') ? 'active' : '' }}"><i class="nav-icon far fa-comment-dots" aria-hidden="true"></i><p>{{ app()->getLocale() === 'ar' ? 'تعليقات السوشيال ميديا' : 'Social comments' }}</p></a></li>
@endif
@endif
