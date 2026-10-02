@if($fasV3Actor)
<nav class="main-header navbar navbar-expand navbar-white navbar-light fas-v3-topbar">
    <div class="d-flex align-items-center gap-3">
        <a class="nav-link" data-widget="pushmenu" href="#" aria-label="فتح القائمة"><i class="fas fa-bars"></i></a>
        <div class="fas-topbar-user">
            <div class="avatar">{{ mb_substr(Auth::guard('admin')->user()->name,0,1) }}</div>
            <div>
                <strong>{{ Auth::guard('admin')->user()->name }}</strong>
                <small>{{ ['owner'=>'المالك','deputy_manager'=>'أدمن إداري','branch_manager'=>'مدير الفرع'][$fasV3Actor->role] ?? $fasV3Actor->role }}</small>
            </div>
        </div>
    </div>

    <form class="fas-topbar-search" method="get" action="{{ url('/admin/applies-orders') }}">
        <i class="fas fa-search"></i>
        <input type="search" name="q" placeholder="بحث عن طلب، عميل، هاتف..." autocomplete="off">
    </form>

    <div class="d-flex align-items-center gap-3">
        <div class="d-none d-md-flex align-items-center gap-2 text-muted" style="font-size:10px">
            <i class="far fa-clock" style="color:#ff7100"></i>
            <span>{{ now(config('erp.timezone'))->format('Y/m/d · h:i A') }}</span>
        </div>

        <div class="d-none d-sm-flex align-items-center gap-1">
            <i class="fas fa-globe" style="color:#ff7100"></i>
            <select onchange="changeLanguage(this.value)" class="form-select form-select-sm" style="border:0;background:transparent;font-size:10px">
                <option {{ session('lang_code','ar') == 'ar' ? 'selected' : '' }} value="ar">Arabic</option>
                <option {{ session('lang_code') == 'en' ? 'selected' : '' }} value="en">English</option>
            </select>
        </div>

        <div class="dropdown">
            <a class="nav-link position-relative" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="far fa-bell"></i>
                @if(Auth::guard('admin')->user()->unreadNotifications->count())
                <span class="badge navbar-badge">{{ Auth::guard('admin')->user()->unreadNotifications->count() }}</span>
                @endif
            </a>
            <div class="dropdown-menu dropdown-menu-end" style="min-width:310px">
                <span class="dropdown-item dropdown-header">{{ Auth::guard('admin')->user()->unreadNotifications->count() }} إشعار</span>
                @forelse(Auth::guard('admin')->user()->unreadNotifications->take(6) as $note)
                    <a href="{{ url('/admin/notifications') }}#{{ $note->id }}" class="dropdown-item">
                        <i class="fas fa-envelope me-2 text-warning"></i>
                        <span style="font-size:10px">{{ mb_strimwidth((string)($note->data['title'] ?? 'إشعار جديد'), 0, 42, '…', 'UTF-8') }}</span>
                    </a>
                @empty
                    <span class="dropdown-item text-muted text-center">لا توجد إشعارات جديدة</span>
                @endforelse
                <div class="dropdown-divider"></div>
                <a href="{{ url('/admin/notifications') }}" class="dropdown-item text-center">عرض كل الإشعارات</a>
            </div>
        </div>

        <a href="{{ url('/admin/adminLogout') }}" class="nav-link" title="تسجيل الخروج"><i class="fas fa-sign-out-alt"></i></a>
    </div>
</nav>
@else
<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light justify-content-between" @if(\Request::route()->getName() == 'chooseType') style="margin-right:0px;" @endif>
    <!-- Left navbar links -->
    <ul class="navbar-nav align-items-center">
        <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a>
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
          <li class="nav-item d-none d-sm-inline-block px-2">
            @php $currentTime = \Carbon\Carbon::now()->format('g:i a'); 
                 $todayDate = \Carbon\Carbon::now()->format('Y-m-d');@endphp
            <span>{{$todayDate}} / {{$currentTime}}</span>
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
        <!-- Notifications Dropdown Menu -->
        <li class="dropdown">
            <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="far fa-bell fa-lg"></i>
                <span
                    class="badge badge-warning navbar-badge">{{ Auth::guard('admin')->user()->unreadNotifications->count() }}</span>
            </a>
            <div class="dropdown-menu" aria-labelledby="dropdownMenuLink">
                <span class="dropdown-item dropdown-header">{{ Auth::guard('admin')->user()->unreadNotifications->count() }}
                    @lang('main.notification')</span>
                @foreach (Auth::guard('admin')->user()->unreadNotifications as $note)
                    <!--<div class="dropdown-divider"></div>-->
                    <a href="{{url('/admin/notifications')}}#{{$note->id}}"
                            class="dropdown-item">
                        <i class="fas fa-envelope me-2"></i>{{ $note->data['title'] }}
                        <div class="dropdown-divider">
                            <span class="mt-2 text-muted text-sm"> {{$note->created_at}} @php
                                    $now = \Carbon\Carbon::now();
                                    $created = $note->created_at;
                                    $x = $created->diffForHumans($now);
                                    echo $x;
                            @endphp</span>
                        </div>
                    </a>
                @endforeach
                <a href="{{ url('/admin/notifications') }}" class="dropdown-item dropdown-footer" >@lang('main.Get all notifications')</a>
                <style>
                    .dropdown-footer{
                        position: sticky;
                        width: 96%;
                        border-radius: 70px;
                        bottom: 0px;
                        font-weight: bold;
                        color: #fff;
                        z-index: 45;
                        background: var(--main);
                        margin: auto;
                    }
                </style>
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

@endif