@php
    $inboxActor = Auth::guard('admin')->user();
    $inboxService = app(\App\Services\Dashboard\SupportInbox::class);
    $inboxCanSupport = $inboxService->canAccess($inboxActor);
    $inboxNotes = \Illuminate\Support\Facades\Schema::hasTable('notifications') ? $inboxActor->unreadNotifications()->latest()->limit(1000)->get() : collect();
    $inboxUnreadCount = \Illuminate\Support\Facades\Schema::hasTable('notifications') ? $inboxActor->unreadNotifications()->count() : 0;
@endphp
<!-- Navbar -->
<nav data-dashboard-inbox
     data-notifications-url="{{ route('dashboard-inbox.notifications') }}"
     data-notifications-read-url="{{ route('dashboard-inbox.notifications.read') }}"
     data-support-url="{{ $inboxCanSupport ? route('dashboard-inbox.support') : '' }}"
     data-inbox-ids="{{ $inboxCanSupport ? implode(',', $inboxService->inboxIds($inboxActor)) : '' }}"
     data-support-staff="{{ $inboxCanSupport && $inboxService->isStaff($inboxActor) ? '1' : '0' }}"
     data-inbox-read-failed="{{ trans('dashboard_inbox.read_failed') }}"
     class="main-header navbar navbar-expand navbar-white navbar-light justify-content-between" @if(\Request::route()->getName() == 'chooseType') style="margin-right:0px;" @endif>
    <!-- Left navbar links -->
    <ul class="navbar-nav align-items-center">
        <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" data-screen-collapse-size="992" href="#"><i class="fas fa-bars"></i></a>
        </li>
        <li class="nav-item">
            <a href="{{ url('/admin/adminLogout') }}" class="nav-link"><i class="fas fa-sign-out-alt"></i> @lang('main.logout')</a>
        </li>
            {{-- <div id="google_translate_button"></div> --}}

      <!--   <li class="nav-item d-none d-sm-inline-block">
            @php $currentTime = \Carbon\Carbon::now()->format('g:i a'); 
                 $todayDate = \Carbon\Carbon::now()->format('Y-m-d');@endphp
            <span>{{$todayDate}} / {{$currentTime}}</span>
        </li> -->
        <li class="nav-item d-none d-sm-flex align-itemc-center">
            <i class="fas fa-globe" style="line-height: 2.2;color: #fd7201;"></i>
            <select onchange="changeLanguage(this.value)" class="form-select">
                <option {{ session()->has('lang_code') ? (session()->get('lang_code') == 'ar' ? 'selected' : '') : '' }}
                    value="ar">Arabic</option>
                <option {{ session()->has('lang_code') ? (session()->get('lang_code') == 'en' ? 'selected' : '') : '' }}
                    value="en">English</option>
            </select>
        </li>
         <li class="nav-item d-none">
            <a href="{{ route('resturantControl') }}" class="nav-link">For Apple Store</a>
        </li>

        
    </ul>
    
    <button id="toggle-sound" class="btn" title="Allowed">
      <i id="sound-icon" class="fas fa-volume-up"></i>
    </button>
    
    <style>
        #toggle-sound {
            position: fixed;
            bottom: 2.4rem;
            inset-inline-end: 1.2rem;
            font-size: 18px;
            color: #fff;
            background-color: var(--main);
            aspect-ratio: 1 / 1;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            box-shadow: 0px 1px 8px 0px #0000006b;
        }
    </style>
    
    <!-- Right navbar links -->
    <ul class="navbar-nav align-items-center">
          <li class="nav-item d-none d-sm-inline-block px-2" data-dashboard-clock data-clock-server="{{ now('UTC')->timestamp * 1000 }}" title="{{ app()->getLocale()==='ar'?'يوم التشغيل من 6 صباحًا إلى 6 صباحًا بتوقيت مصر':'Operating day: 06:00 to 06:00, Cairo time' }}">
            <span>{{ app()->getLocale()==='ar'?'يوم التشغيل':'Operating day' }}: <bdi data-dashboard-operating-date>{{ \App\Services\Dashboard\OperatingDay::date() }}</bdi> / <bdi data-dashboard-local-time>{{ now('Africa/Cairo')->format('g:i a') }}</bdi></span>
        </li>
        {{--<li class="nav-item d-none d-sm-inline-block px-2">
            <select onchange="changeLanguage(this.value)" class="form-select">
                <option {{ session()->has('lang_code') ? (session()->get('lang_code') == 'ar' ? 'selected' : '') : '' }}
                    value="ar">Arabic</option>
                <option {{ session()->has('lang_code') ? (session()->get('lang_code') == 'en' ? 'selected' : '') : '' }}
                    value="en">English</option>
            </select>
        </li>--}}
      
        <div class="dropdown-them">

  <div id="myDropdown" class="dropdown-content">
   <ul>
         
         <li class="theme theme-1" data-theme="theme-1"></li>
          <li class="theme theme-2" data-theme="theme-2"></li>
          <li class="theme theme-3" data-theme="theme-3"></li>
          <li class="theme theme-4" data-theme="theme-4"></li>
          <li class="theme theme-5" data-theme="theme-5"></li>
   </ul>
   
  </div>
</div>
            
             
                  
           
        <!-- Messages Dropdown Menu -->
        <!--  <li class="nav-item dropdown">
        <a class="nav-link" data-toggle="dropdown" href="#">
          <i class="far fa-comments"></i>
          <span class="badge badge-danger navbar-badge">3</span>
        </a>
        <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
          <a href="#" class="dropdown-item">
            <div class="media">
              <img src="dist/img/user1-128x128.jpg" alt="User Avatar" class="img-size-50 mr-3 img-circle">
              <div class="media-body">
                <h3 class="dropdown-item-title">
                  Brad Diesel
                  <span class="float-right text-sm text-danger"><i class="fas fa-star"></i></span>
                </h3>
                <p class="text-sm">Call me whenever you can...</p>
                <p class="text-sm text-muted"><i class="far fa-clock mr-1"></i> 4 Hours Ago</p>
              </div>
            </div>
          </a>
          <div class="dropdown-divider"></div>
          <a href="#" class="dropdown-item">
            <div class="media">
              <img src="dist/img/user8-128x128.jpg" alt="User Avatar" class="img-size-50 img-circle mr-3">
              <div class="media-body">
                <h3 class="dropdown-item-title">
                  John Pierce
                  <span class="float-right text-sm text-muted"><i class="fas fa-star"></i></span>
                </h3>
                <p class="text-sm">I got your message bro</p>
                <p class="text-sm text-muted"><i class="far fa-clock mr-1"></i> 4 Hours Ago</p>
              </div>
            </div>
          </a>
          <div class="dropdown-divider"></div>
          <a href="#" class="dropdown-item">
            <div class="media">
              <img src="dist/img/user3-128x128.jpg" alt="User Avatar" class="img-size-50 img-circle mr-3">
              <div class="media-body">
                <h3 class="dropdown-item-title">
                  Nora Silvester
                  <span class="float-right text-sm text-warning"><i class="fas fa-star"></i></span>
                </h3>
                <p class="text-sm">The subject goes here</p>
                <p class="text-sm text-muted"><i class="far fa-clock mr-1"></i> 4 Hours Ago</p>
              </div>
            </div>
          </a>
          <div class="dropdown-divider"></div>
          <a href="#" class="dropdown-item dropdown-footer">See All Messages</a>
        </div>
      </li> -->
        @if($inboxCanSupport)
        <li class="nav-item">
            <a class="nav-link position-relative" href="{{ url('/admin/chat').($inboxService->isStaff($inboxActor) ? '' : '?user_id=1') }}" title="{{ trans('dashboard_inbox.support') }}" aria-label="{{ trans('dashboard_inbox.support') }}">
                <i class="far fa-comments fa-lg"></i>
                <span class="badge dashboard-support-badge navbar-badge" data-support-unread hidden aria-live="polite" style="background:#fd7201;color:#fff"></span>
            </a>
        </li>
        @endif
        <!-- Notifications Dropdown Menu -->
        <li class="dropdown" data-notification-dropdown>
            <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ trans('main.notification') }}">
                <i class="far fa-bell fa-lg"></i>
                <span class="badge badge-warning navbar-badge" data-notification-count @if(!$inboxUnreadCount) hidden @endif aria-live="polite">{{ $inboxUnreadCount }}</span>
            </a>
            <div class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                <span class="dropdown-item dropdown-header"><span data-notification-header-count>{{ $inboxUnreadCount }}</span> @lang('main.notification')</span>
                <div id="dashboard-notification-list">
                    @foreach($inboxNotes as $note)
                    <a href="{{ url('/admin/notifications') }}#{{ $note->id }}" class="dropdown-item" data-notification-id="{{ $note->id }}">
                        <i class="fas fa-envelope me-2"></i><span>{{ $note->data['title'] ?? '' }}</span>
                        <small class="d-block text-muted">{{ $note->created_at->diffForHumans() }}</small>
                    </a>
                    @endforeach
                </div>
                <span class="dropdown-item text-danger small" data-inbox-read-error hidden></span>
                <a href="{{ url('/admin/notifications') }}" class="dropdown-item dropdown-footer">@lang('main.Get all notifications')</a>
            </div>
        </li>

    </ul>
</nav>
<!-- /.navbar -->
<script>
    function myFunction() {
  document.getElementById("myDropdown").classList.toggle("show");
}

// Close the dropdown if the user clicks outside of it
window.onclick = function(event) {
  if (!event.target.matches('.dropbtn')) {
    var dropdowns = document.getElementsByClassName("dropdown-content");
    var i;
    for (i = 0; i < dropdowns.length; i++) {
      var openDropdown = dropdowns[i];
        // openDropdown.toggle('show');
    //   if (openDropdown.classList.contains('show')) {
    //   }
    }
  }
}
</script>
