<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary ">
    <!-- Brand Logo -->
    <!--<hr>-->
    <a href="{{ url('/admin/dashboard') }}" class="brand-link" style="font-size: 16px;">
        <span class="brand-text font-weight-light">{{ app(App\Models\GeneralSettings::class)->site_name }}</span>
        <!--<img class="logo-ar" src="{{url('dashboard/dist/img/Layer.svg')}}" width="145px" alt="admin image">-->
        <!--<img class="logo-en" src="{{url('dashboard/dist/img/logo-en.svg')}}" width="145px" alt="admin image">-->
    </a>
    <hr>
    <!-- Sidebar -->
    <div class="sidebar">
        <!-- Sidebar user panel (optional) -->
        <div class="container user-panel mt-1 mb-1 d-flex">
            <div class="d-flex align-items-center gap-2">
                <div class="">
                    @if(auth('admin')->user()->getFirstMediaUrl('photo_profile', 'thumb'))
                        <img class="avatar" src="{{auth('admin')->user()->getFirstMediaUrl('photo_profile', 'thumb')}}"
                            alt="admin image">
                    @else
                        <!--<img class="avatar" src="{{url('dashboard/dist/img/avatar_icon.png')}}" alt="admin image">-->
                        <i class="fas fa-user-gear"></i>
                    @endif

                </div>
                <div class="">
                    @if(auth('admin')->user()->id == 1)
                        <a style="line-height: 45px;"
                            href="{{ url('/admin/users/' . Auth::guard('admin')->user()->id . '/edit?account_type=admin') }}"
                            class="d-block welcome">@lang('main.hello') / {{ Auth::guard('admin')->user()->name }}</a>
                    @else
                        <a style="line-height: 45px;"
                            href="{{ url('/admin/users/' . Auth::guard('admin')->user()->id . '/edit/?account_type=' . auth('admin')->user()->account_type) }}"
                            class="d-block welcome">@lang('main.hello') / {{ Auth::guard('admin')->user()->name }}</a>
                    @endif
                </div>
            </div>
        </div>
        <hr>
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" style="padding:0px"
                data-accordion="false">

                <!-- الصفحة الرئيسيه -->
                <li class="nav-item">
                    <a href="{{ url('/admin/dashboard') }}"
                        class="nav-link {{ request()->is('admin/dashboard') ? 'active' : '' }}">
                        <i class="nav-icon fa fa-home"></i>
                        <p>
                            @lang('main.dashboard')
                        </p>
                    </a>
                </li>
                {{-- @if(session()->get('menu') == 'application') --}}
                <!-- الاعدادات -->
                @if(Auth::guard('admin')->user()->can('setting-list'))
                    <li class="nav-item">
                        <a href="{{ url('/admin/settings') }}"
                            class="nav-link {{ request()->is('admin/settings') ? 'active' : '' }}">
                            <i class="fas fa-cog nav-icon"></i>
                            <p>@lang('main.main setting')</p>
                        </a>
                    </li>
                @endif
                @if(Auth::guard('admin')->user()->can('paymob-list'))
                    <li class="nav-item">
                        <a href="{{ url('/admin/env-setting') }}"
                            class="nav-link {{ request()->is('admin/env-setting') ? 'active' : '' }}">
                            <i class="nav-icon fa-solid fa-credit-card"></i>
                            <p>@lang('main.env setting')</p>
                        </a>
                    </li>
                @endif
                @if(Auth::guard('admin')->user()->can('wallet-list'))
                    <li class="nav-item">
                        <a href="{{ url('/admin/wallet/transactions') }}"
                            class="nav-link {{ request()->is('admin/wallets/transactions') ? 'active' : '' }}">
                            <i class="fas fa-wallet nav-icon"></i>
                            <p>@lang('main.wallet transactions')</p>
                        </a>
                    </li>
                    <!--<li class="nav-item">-->
                    <!--    <a href="{{ url('/admin/wallets') }}"-->
                    <!--    class="nav-link {{ request()->is('admin/wallets') ? 'active' : '' }}">-->
                    <!--        <i class="fas fa-wallet nav-icon"></i>-->
                    <!--        <p>@lang('main.transfer from') @lang('main.wallets')</p>-->
                    <!--    </a>-->
                    <!--</li>-->
                @endif
                @if(Auth::guard('admin')->user()->can('support_contact-list'))
                    @if(auth()->user()->roles->pluck("id")->first() == 11)
                        <li class="nav-item">
                            <a href="{{ url('/admin/chat') }}"
                                class="nav-link {{ request()->is('admin/chat') ? 'active' : '' }}">
                                <i class="fas fa-message nav-icon"></i>
                                <p>@lang('main.Contact technical support')</p>
                            </a>
                        </li>
                    @elseif(auth()->user()->roles->pluck("id")->first() == 2)
                        <li class="nav-item">
                            <a href="{{ url('/admin/chat?user_id=1') }}"
                                class="nav-link {{ request()->is('admin/chat?user_id=1') ? 'active' : '' }}">
                                <i class="fas fa-message nav-icon"></i>
                                <p>@lang('main.Contact technical support')</p>
                            </a>
                        </li>
                    @endif
                @endif
                @if(Auth::guard('admin')->user()->can('fcm_notification-create'))
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fas fa-bell"></i>
                            <p>
                                @lang('main.send notification')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">

                            <li class="nav-item">
                                <a href="{{ url('/admin/fcm_notifications/create') }}"
                                    class="nav-link {{ request()->is('admin/fcm_notifications/create') ? 'active' : '' }}">
                                    <!--<i class="fas fa-bell nav-icon"></i>-->
                                    <p>@lang('main.send notifications')</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ url('/admin/fcm_notifications/create/?account_type=vendor') }}"
                                    class="nav-link {{ request()->is('admin/fcm_notifications/create/?account_type=vendor') ? 'active' : '' }}">
                                    <!--<i class="fas fa-bell nav-icon"></i>-->
                                    <p>@lang('main.send notifications for vendor') </p>
                                </a>
                            </li>

                            <li class="nav-item">
                                <a href="{{ url('/admin/fcm_notifications/create/?account_type=delegate') }}"
                                    class="nav-link {{ request()->is('admin/fcm_notifications/create/?account_type=delegate') ? 'active' : '' }}">
                                    <!--<i class="fas fa-bell nav-icon"></i>-->
                                    <p>@lang('main.send notifications for delegate')</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ url('/admin/fcm_notifications/create/?account_type=resturant_owner') }}"
                                    class="nav-link {{ request()->is('admin/fcm_notifications/create/?account_type=resturant_owner') ? 'active' : '' }}">
                                    <!--<i class="fas fa-bell nav-icon"></i>-->
                                    <p>@lang('main.send notifications for resturant_owner')</p>
                                </a>
                            </li>
                        </ul>
                    </li>

                @endif
                <!-- الاشعارات -->
                @if(Auth::guard('admin')->user()->can('notification-list'))
                    <li class="nav-item">
                        <a href="{{ url('/admin/bulk-notifications') }}"
                            class="nav-link {{ request()->is('admin/bulk-notifications') ? 'active' : '' }}">
                            <i class="far fa-bell nav-icon"></i>
                            <p>@lang('main.bulk notifications')</p>
                        </a>
                    </li>
                @endif

                @if(Auth::guard('admin')->user()->can('coupon_wheel-list'))
                    <li class="nav-item">
                        <a href="{{ url('/admin/coupon_wheels') }}"
                            class="nav-link {{ request()->is('admin/coupon_wheels') ? 'active' : '' }}">
                            <i class="fa-solid fa-gift nav-icon"></i>
                            <p>@lang('main.coupon_wheel')</p>
                        </a>
                    </li>
                @endif

                @if(Auth::guard('admin')->user()->can('areas-list'))
                    <li class="nav-item">
                        <a href="{{ url('/admin/areas') }}"
                            class="nav-link {{ request()->is('admin/areas') ? 'active' : '' }}">
                            <i class="fas fa-map nav-icon"></i>
                            <p>@lang('main.areas')</p>
                        </a>
                    </li>
                @endif

                <!-- االصلاحيات -->
                @if (
                        Auth::guard('admin')->user()->can('role-create') ||
                        Auth::guard('admin')->user()->can('role-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fas fa-shield-alt"></i>
                            <p>
                                @lang('main.Roles')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            @can('role-list')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/roles') }}"
                                        class="nav-link {{ request()->is('admin/roles') ? 'active' : '' }}">
                                        <i class="fas fa-eye nav-icon"></i>
                                        <p>@lang('main.showAll') @lang('main.Roles')</p>
                                    </a>
                                </li>
                            @endcan
                            @can('role-create')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/roles/create') }}"
                                        class="nav-link {{ request()->is('admin/roles/create') ? 'active' : '' }}">
                                        <i class="fas fa-plus nav-icon"></i>
                                        <p>@lang('main.AddRole')</p>
                                    </a>
                                </li>
                            @endcan

                        </ul>
                    </li>
                @endif

                <!-- المديرين -->
                @if (
                        Auth::guard('admin')->user()->can('admin-create') ||
                        Auth::guard('admin')->user()->can('admin-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fas fa-user-shield"></i>
                            <p>
                                @lang('main.Admins')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            @can('admin-list')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/users?account_type=admin') }}"
                                        class="nav-link {{ request()->is('admin/users?account_type=admin') ? 'active' : '' }}">
                                        <i class="fas fa-eye nav-icon"></i>
                                        <p>@lang('main.showAll') @lang('main.Admins')</p>
                                    </a>
                                </li>
                            @endcan
                            @can('admin-create')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/users/create?account_type=admin') }}"
                                        class="nav-link {{ request()->is('admin/users/create?account_type=admin') ? 'active' : '' }}">
                                        <i class="fas fa-plus nav-icon"></i>
                                        <p>@lang('main.AddAdmin')</p>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif

                <!-- المديرين -->
                @if (
                        Auth::guard('admin')->user()->can('resturant_owner-create') ||
                        Auth::guard('admin')->user()->can('resturant_owner-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="fa-solid fa-user-tag nav-icon"></i>
                            <p>
                                @lang('main.resturant_owners')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            @can('resturant_owner-list')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/users?account_type=resturant_owner') }}"
                                        class="nav-link {{ request()->is('admin/users?account_type=resturant_owner') ? 'active' : '' }}">
                                        <i class="fas fa-eye nav-icon"></i>
                                        <p>@lang('main.showAll') @lang('main.resturant_owners')</p>
                                    </a>
                                </li>
                            @endcan
                            @can('resturant_owner-create')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/users/create?account_type=resturant_owner') }}"
                                        class="nav-link {{ request()->is('admin/users/create?account_type=resturant_owner') ? 'active' : '' }}">
                                        <i class="fas fa-plus nav-icon"></i>
                                        <p>@lang('main.Addresturant_owner')</p>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif

                <!-- أصحاب المرافق -->
                @if (
                        Auth::guard('admin')->user()->can('delegate-create') ||
                        Auth::guard('admin')->user()->can('delegate-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fa-solid fa-person-biking"></i>
                            <p>
                                الشركاء
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            @can('delegate-list')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/users?account_type=delegate') }}"
                                        class="nav-link {{ request()->is('admin/users?account_type=delegate') ? 'active' : '' }}">
                                        <i class="fas fa-eye nav-icon"></i>
                                        <p>عرض كل الشركاء</p>
                                    </a>
                                </li>
                            @endcan
                            @can('delegate-create')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/users/create?account_type=delegate') }}"
                                        class="nav-link {{ request()->is('admin/users/create?account_type=delegate') ? 'active' : '' }}">
                                        <i class="fas fa-plus nav-icon"></i>
                                        <p>إضافة شريك</p>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif

                @if (
                        Auth::guard('admin')->user()->can('vendor-create') ||
                        Auth::guard('admin')->user()->can('vendor-list')
                    )
                    <!--  مصف السيارة -->
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">

                            <i class="nav-icon fa fa-building-user"></i>
                            <p>
                                @lang('main.vendors')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ url('/admin/users?account_type=vendor') }}"
                                    class="nav-link {{request()->is('admin/users?account_type=vendor') ? 'active' : ''}}">
                                    <i class="fas fa-eye nav-icon"></i>
                                    <p>@lang('main.showAll') @lang('main.vendors') </p>
                                </a>
                            </li>
                            @can('vendor-create')
                                <li class="nav-item">
                                    <a href="{{ url('admin/users/create?account_type=vendor') }}"
                                        class="nav-link {{ request()->is('admin/users?account_type=vendor') ? 'active' : '' }}">
                                        <i class="fas fa-plus nav-icon"></i>
                                        <p>@lang('main.add') @lang('main.vendors')</p>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif
                @if (Auth::guard('admin')->user()->can('user-list'))
                    <!-- مستخدمين vip -->
                    <li class="nav-item">
                        <a href="{{ url('/admin/users?account_type=user') }}"
                            class="nav-link {{ request()->is('admin/users?account_type=user') ? 'active' : '' }}">
                            <i class="nav-icon fa fa-id-badge"></i>
                            <p>@lang('main.showAll') @lang('main.users')</p>
                        </a>
                    </li>
                @endif

                <!-- صفحة من نحن -->
                @if (
                        Auth::guard('admin')->user()->can('pending_vendor-create') ||
                        Auth::guard('admin')->user()->can('pending_vendor-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fas fa-question-circle"></i>
                            <p>
                                طلبات انضمام الشركاء
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ url('/admin/pending_vendors') }}"
                                    class="nav-link {{request()->is('admin/pending_vendors') ? 'active' : ''}}">
                                    <i class="fas fa-eye nav-icon"></i>
                                    <p>عرض طلبات انضمام الشركاء</p>
                                </a>
                            </li>

                        </ul>
                    </li>
                @endif

                <!-- أنواع المرافق -->
                @if (
                        Auth::guard('admin')->user()->can('order-create') ||
                        Auth::guard('admin')->user()->can('order-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fas fa-shopping-cart"></i>
                            <p>
                                @lang('main.orders')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ url('/admin/orders') }}"
                                    class="nav-link {{request()->is('admin/orders') ? 'active' : ''}}">
                                    <i class="fas fa-eye nav-icon"></i>
                                    <p>@lang('main.showAll') @lang('main.orders') </p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    @if(in_array(auth()->user()->roles->pluck("id")->first(), [2, 13]))
                        <li class="nav-item">
                            <a href="{{ url('/admin/applies-orders') }}"
                                class="nav-link {{ request()->is('admin/applies-orders') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-hand-holding-usd"></i>
                                <p>
                                    @lang('main.orders applies')
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ url('/admin/resturant-reports?report_type=week') }}"
                                class="nav-link {{ request()->is('admin/resturant-reports?report_type=week') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-chart-line"></i>
                                <p>
                                    @lang('main.vendor resturant-reports')
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ url('/admin/get/charging/wallet') }}"
                                class="nav-link {{ request()->is('admin/get/charging/wallet') ? 'active' : '' }}">
                                <i class="nav-icon fa fa-home"></i>
                                <p>
                                    @lang('main.vendor wallet')
                                </p>
                            </a>
                        </li>
                    @endif
                @endif
                @if (
                        Auth::guard('admin')->user()->can('contract-create') ||
                        Auth::guard('admin')->user()->can('contract-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="fa-solid fa-file-signature nav-icon"></i>
                            <p>
                                @lang('main.contracts')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ url('/admin/contracts') }}"
                                    class="nav-link {{request()->is('admin/contracts') ? 'active' : ''}}">
                                    <i class="fas fa-eye nav-icon"></i>
                                    <p>@lang('main.showAll') @lang('main.contracts') </p>
                                </a>
                            </li>
                            @can('contract-create')
                                <li class="nav-item">
                                    <a href="{{ url('admin/contracts/create') }}" class="nav-link">
                                        <i class="fas fa-plus nav-icon"></i>
                                        <p>@lang('main.add') @lang('main.contracts')</p>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif

                <!-- المرافق -->
                @if (
                        Auth::guard('admin')->user()->can('category-create') ||
                        Auth::guard('admin')->user()->can('category-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fa-solid fa-list"></i>
                            <p>
                                @lang('main.categorys')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ url('/admin/categorys') }}"
                                    class="nav-link {{request()->is('admin/categorys') ? 'active' : ''}}">
                                    <i class="fas fa-eye nav-icon"></i>
                                    <p>@lang('main.showAll') @lang('main.categorys') </p>
                                </a>
                            </li>
                            @can('category-create')
                                <li class="nav-item">
                                    <a href="{{ url('admin/categorys/create?parent=parent') }}" class="nav-link">
                                        <i class="fas fa-plus nav-icon"></i>
                                        <p>@lang('main.add') @lang('main.categorys')</p>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ url('admin/categorys/create?parent=sub') }}" class="nav-link">
                                        <i class="fas fa-plus nav-icon"></i>
                                        <p>@lang('main.add') @lang('main.subcategorys')</p>
                                    </a>
                                </li>
                            @endcan

                        </ul>
                    </li>
                @endif

                <!-- بوابات -->
                @if (
                        Auth::guard('admin')->user()->can('product-create') ||
                        Auth::guard('admin')->user()->can('product-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fa-solid fa-bars-staggered"></i>
                            <p>
                                @lang('main.products')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ url('/admin/products') }}"
                                    class="nav-link {{request()->is('admin/products') ? 'active' : ''}}">
                                    <i class="fas fa-eye nav-icon"></i>
                                    <p>@lang('main.showAll') @lang('main.products') </p>
                                </a>
                            </li>
                            @can('product-create')
                                <li class="nav-item">
                                    <a href="{{ url('admin/products/create') }}" class="nav-link">
                                        <i class="fas fa-plus nav-icon"></i>
                                        <p>@lang('main.add') @lang('main.product')</p>
                                    </a>
                                </li>
                            @endcan

                        </ul>
                    </li>
                @endif

                <!-- مواضع توقف السيارات -->
                @if (
                        Auth::guard('admin')->user()->can('resturant-create') ||
                        Auth::guard('admin')->user()->can('resturant-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fas fa-utensils"></i>
                            <p>
                                @lang('main.resturants')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ url('/admin/resturants') }}"
                                    class="nav-link {{request()->is('admin/resturants') ? 'active' : ''}}">
                                    <i class="fas fa-eye nav-icon"></i>
                                    <p>@lang('main.showAll') @lang('main.resturants') </p>
                                </a>
                            </li>
                            @can('resturant-create')
                                <li class="nav-item">
                                    <a href="{{ url('admin/resturants/create') }}" class="nav-link">
                                        <i class="fas fa-plus nav-icon"></i>
                                        <p>@lang('main.add') @lang('main.resturants')</p>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif




                @if (Auth::guard('admin')->user()->can('report-list'))
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fas  fa-scroll"></i>
                            <p>
                                @lang('main.reports')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ url('/admin/reports') }}"
                                    class="nav-link {{request()->is('admin/reports') ? 'active' : ''}}">
                                    <i class="fas fa-eye nav-icon"></i>
                                    <p>@lang('main.showAll') @lang('main.reports') </p>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif

                <!-- التحكم في صفحات الموقع -->
                @if (
                        Auth::guard('admin')->user()->can('question_answer-create') ||
                        Auth::guard('admin')->user()->can('question_answer-list') || Auth::guard('admin')->user()->can('banner-create') ||
                        Auth::guard('admin')->user()->can('banner-list') ||
                        Auth::guard('admin')->user()->can('contact-list') || Auth::guard('admin')->user()->can('setting-list') || Auth::guard('admin')->user()->can('slidear-list')
                    )
                    <li class="nav-item has-treeview">
                        <a href="#" class="nav-link">
                            <i class="nav-icon fa-solid fa-mobile-screen"></i>
                            <p>
                                @lang('main.website control')
                                <i class="fas fa-angle-down left"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            @can('banner-list')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/banners') }}"
                                        class="nav-link {{request()->is('admin/banners') ? 'active' : ''}}">
                                        <!--<i class="fas fa-eye nav-icon"></i>-->
                                        <i class="fas fa-images nav-icon"></i>
                                        <p>@lang('main.showAll') @lang('main.banners') </p>
                                    </a>
                                </li>
                            @endcan
                            @if(Auth::guard('admin')->user()->can('slidear-list'))
                                <li class="nav-item">
                                    <a href="{{ url('/admin/slidears') }}"
                                        class="nav-link {{ request()->is('admin/slidears') ? 'active' : '' }}">
                                        <i class="fa-solid fa-sliders nav-icon"></i>
                                        <p>@lang('main.slidears')</p>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="{{ url('/admin/advertisings') }}"
                                        class="nav-link {{ request()->is('admin/advertisings') ? 'active' : '' }}">
                                        <!--<i class="fas fa-images nav-icon"></i>-->
                                        <i class="fa-solid fa-rectangle-ad nav-icon"></i>
                                        <p>@lang('main.advertisings')</p>
                                    </a>
                                </li>
                            @endif

                            @can('service-list')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/services') }}"
                                        class="nav-link {{request()->is('admin/services') ? 'active' : ''}}">
                                        <i class="fas fa-eye nav-icon"></i>
                                        <p>@lang('main.showAll') @lang('main.services') </p>
                                    </a>
                                </li>
                            @endcan
                            @can('question_answer-list')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/question_answers') }}"
                                        class="nav-link {{request()->is('admin/question_answers') ? 'active' : ''}}">
                                        <i class="fas fa-eye nav-icon"></i>
                                        <p>@lang('main.showAll') @lang('main.question_answers') </p>
                                    </a>
                                </li>
                            @endcan
                            @can('contact-list')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/contacts') }}"
                                        class="nav-link {{request()->is('admin/contacts') ? 'active' : ''}}">
                                        <i class="fas fa-eye nav-icon"></i>
                                        <p>@lang('main.showAll') @lang('main.contacts') </p>
                                    </a>
                                </li>

                            @endcan
                            @can('feature-list')
                                <li class="nav-item">
                                    <a href="{{ url('/admin/features') }}"
                                        class="nav-link {{request()->is('admin/features') ? 'active' : ''}}">
                                        <i class="fas fa-eye nav-icon"></i>
                                        <p>@lang('main.showAll') @lang('main.features') </p>
                                    </a>
                                </li>

                            @endcan

                        </ul>
                    </li>
                @endif

                <!-- الشكاوى -->
                {{-- @if (Auth::guard('admin')->user()->can('complaint-list') )
                <li class="nav-item has-treeview">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-frown"></i>
                        <p>
                            @lang('main.complaints')
                            <i class="fas fa-angle-down left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="{{ url('/admin/complaints') }}"
                                class="nav-link {{ request()->is('admin/complaints') ? 'active' : '' }}">
                                <i class="fas fa-eye nav-icon"></i>
                                <p>@lang('main.showAllcomplaint')</p>
                            </a>
                        </li>
                    </ul>
                </li>
                @endif --}}
                {{-- @endif --}}
            </ul>
        </nav>
    </div>
    </div>
</aside>