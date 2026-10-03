@extends('admin.index')
@push('custom-css')
@endpush
@section('content')
<style>
    .card-header::after{
        content: unset;
    }
    .card-header a{
        padding: 1rem;
        color: #fff;
        text-decoration: underline !important;
    }
    .owner img{
        border-radius: 50%;
    }
    .owner .info-box{
        gap: 0px
    }
    .owner .info-box .info-box-icon {
        width: 90px;
    }
    tr:has(td.wait) td{
        background:#ff853c8c
    }
</style>
<!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    @if((auth('admin')->id() === 635 || auth('admin')->user()->roles->pluck("id")->first() == 11))
    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row w-100 mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">@lang('main.dashboard')</h1>
          </div><!-- /.col -->
          <div class="col-sm-6">
          </div><!-- /.col -->
        </div><!-- /.row -->
      </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->
    @endif
    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">
      @can('home-list')
      
      @if((auth('admin')->id() === 635 || auth('admin')->user()->roles->pluck("id")->first() == 11))
        <!-- Small boxes (Stat box) -->
        <div class="row gy-3 mb-3">
            @php $main_resturant = \App\Models\Resturant::where('id',1)->first(); @endphp
            @if($main_resturant)
            <div class="col-12 owner">
                <div class="info-box" style="text-align:start">
                    <a href="{{url('/')}}/admin/resturants/{{$main_resturant->id}}" class="link"></a>
                    <span class="info-box-icon bg-info">
                        @if($main_resturant->getFirstMediaUrl('logo','thumb'))
                            <img class="cursor-img" data-toggle="modal" data-target="#exampleModal{{ $main_resturant->id }}"
                                id="image" src="{{$main_resturant->getFirstMediaUrl('logo','thumb')}}" style="width:70%;"
                                alt="@lang('main.NoImageUploaded')">
                            @include('admin.components.modal_photo', [
                                'image' => $main_resturant->getFirstMediaUrl('logo','thumb'),
                                'id' => $main_resturant->id,
                            ])
                        @else
                            <img id="image" src="{{ url('dashboard/dist/img/no-photo.png') }}"
                                style="height: 80px; width: 100px;">
                        @endif 
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text"><b>{{$main_resturant->name}}</b></span>
                        <span class="info-box-number mt-2">الذهاب السريع لتفاصيل مطعمي</span>
                    </div>
                  <!-- /.info-box-content -->
                </div>
                <!-- /.info-box -->
            </div>
            @endif
          <div class="col-lg-6 col-md-6 col-6">
            <div class="info-box">
                <a href="{{url('admin/resturant_map')}}" class="link"></a>
                <span class="info-box-icon bg-info">
                    <!--<i class="fas fa-user-cog"></i>-->
                    <i class="fas fa-map-marked-alt"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('main.resturant_map click here')</span>
                </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <div class="col-lg-6 col-md-6 col-6">
            <div class="info-box">
                <a href="{{url('admin/delegate_map')}}" class="link"></a>
                <span class="info-box-icon bg-info">
                    <!--<i class="fas fa-user-cog"></i>-->
                    <i class="fas fa-map-marked-alt"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('main.delegate_map click here')</span>
                </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/users?account_type=admin')}}" class="link"></a>
                <span class="info-box-icon bg-info">
                    <!--<i class="fas fa-user-cog"></i>-->
                    <i class="fas fa-user-shield"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('main.adminsCount')</span>
                    <span class="info-box-number mt-2">{{$adminsCount}}</span>
                </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/roles')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                  <i class="fas fa-shield-alt"></i>
                </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.rolesCount')</span>
                <span class="info-box-number mt-2">{{$rolesCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/users?account_type=vendor')}}" class="link"></a>
                <span class="info-box-icon bg-info">
                    <i class="fas fa-user-tie"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('main.vendorsCount')</span>
                    <span class="info-box-number mt-2">{{$vendorsCount}}</span>
                </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
         
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/categorys?parent=sub')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-warehouse"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.subcategorysCount')</span>
                <span class="info-box-number mt-2">{{$subcategorysCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/categorys?parent=parent')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-parking"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.categorysCount')</span>
                <span class="info-box-number mt-2">{{$categorysCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/orders')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-door-open"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.ordersCount')</span>
                <span class="info-box-number mt-2">{{$ordersCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>

          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/pending_vendors')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-braille"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.pending_vendorsCount')</span>
                <span class="info-box-number mt-2">{{$pending_vendorsCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>

          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/products')}}" class="link"></a> 
              <span class="info-box-icon bg-info"><i class="fas fa-ticket-alt"></i></span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.productsCount')</span>
                <span class="info-box-number mt-2">{{$productsCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>

          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/users?account_type=delegate')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-car"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.usersDelegateTypeCatCount')</span>
                <span class="info-box-number mt-2">{{$usersDelegateTypeCatCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/users?account_type=user')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                 <i class="fas fa-id-badge"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.usersUserTypeCatCount')</span>
                <span class="info-box-number mt-2">{{$usersUserTypeCatCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          
          
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/areas')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-map"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.areasCount')</span>
                <span class="info-box-number mt-2">{{$areasCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/banners')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-gem"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.bannersCount')</span>
                <span class="info-box-number mt-2">{{$bannersCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/resturants')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-question-circle"></i>

              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.resturantsCount')</span>
                <span class="info-box-number mt-2">{{$resturantsCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/question_answers')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-question-circle"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.faqsCount')</span>
                <span class="info-box-number mt-2">{{$faqsCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <a href="{{url('admin/contacts')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-envelope-open-text"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.contactsCount')</span>
                <span class="info-box-number mt-2">{{$contactsCount}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
        </div>
        
        
              <!-- Custom tabs (Charts with tabs)-->
            <div class="mb-3">
                <div class="card-header d-flex w-100 align-items-center justify-content-between border-0 p-0">
                    <h2 class="card-title p-3">
                        <i class="fas fa-chart-pie mr-1"></i>
                        @lang('main.delegates mostly ordered')
                    </h2>
                    <a href="{{route('users.index',['account_type' => 'delegate'])}}">
                    @lang('main.show all delegates')
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0 table-bordered table-hover">
                                <thead>                             
                                    <th>@lang('main.order_no')</th>
                                    <th>@lang('main.delegate')</th>
                                    <th>@lang('main.status')</th>
                                    <th>@lang('main.details')</th>

                                </thead>
                                <tbody>
                                    @forelse ($delegates_most_ordered as $key => $order)
                                        <tr id="td-{{$order->id}}">
                                            <td>
                                                {{ $order->order_no }}
                                            </td>
                                            <td>
                                                {{ $order->delegate?->name }}
                                            </td>
                                            <td>
                                                {{__('main.'.$order->status)}}
                                            </td>
                                            <td>
                                                <a target="_black" class="btn btn-outline-primary" href="{{route('orders.show',$order->id)}}">@lang('main.more details')</a>
                                            </td>
                                        </tr>
                                    @empty
                                        <td class="text-center text-muted" style="font-size: 25px" colspan="8">
                                            {{ trans('main.Noorders') }}
                                        </td>
                                    @endforelse
                                </tbody>
                            </table>
                    </div>
                </div>
             </div>
              
            <div class="mb-3">
                <div class="card-header d-flex w-100 align-items-center justify-content-between border-0 p-0">
                    <h2 class="card-title p-3">
                      <i class="fas fa-chart-pie mr-1"></i>
                      @lang('main.latest orders')
                    </h2>
                    <a href="{{route('orders.index')}}">
                    @lang('main.orders')
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0 table-bordered table-hover">
                                <thead>                             
                                    <th>@lang('main.order_no')</th>
                                    <th>@lang('main.delegate')</th>
                                    <th>@lang('main.status')</th>
                                    <th>@lang('main.details')</th>

                                </thead>
                                <tbody>
                                    @forelse ($latest_orders as $key => $order)
                                        <tr id="td-{{$order->id}}">
                                            <td>
                                                {{ $order->order_no }}
                                            </td>
                                            
                                            @if($order->delegate_from_out == 'out_resturant')
                                            @if($order->status != 'cancelled')
                                            <td>
                                                <span>{{ $order->delegate?->name }}</span>
                                            </td>
                                            @elseif($order->status == 'another_delegate')
                                            <td class="wait" style="background:#ff853c8c">
                                               <span >@lang('main.order-pending')</span> 
                                              </td>
                                               @else
                                            <td>
                                                -
                                            </td>
                                            @endif
                                            
                                            
                                            @elseif($order->delegate_from_out == 'in_resturant')
                                            <td>
                                                @lang('main.in_resturant')
                                            </td>
                                            @else
                                            <td>
                                                -
                                            </td>
                                            @endif
                                            <td>
                                                {{__('main.'.$order->status)}}
                                            </td>
                                            <td>
                                                <a target="_black" class="btn btn-outline-primary" href="{{route('orders.show',$order->id)}}">@lang('main.more details')</a>
                                            </td>
                                        </tr>
                                    @empty
                                        <td class="text-center text-muted" style="font-size: 25px" colspan="8">
                                            {{ trans('main.Noorders') }}
                                        </td>
                                    @endforelse
                                </tbody>
                            </table>
                    </div>
                </div>
              </div>

              <div class="mb-3">
                <div class="card-header d-flex w-100 align-items-center justify-content-between border-0 p-0">
                    <h2 class="card-title p-3">
                      <i class="fas fa-chart-pie mr-1"></i>
                      @lang('main.resturants mostly ordered')
                    </h2>
                    <a href="{{route('resturants.index')}}">
                    @lang('main.resturants')</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0 table-bordered table-hover">
                                <thead>                             
                                    <th>@lang('main.order_no')</th>
                                    <th>@lang('main.resturant')</th>
                                    <th>@lang('main.status')</th>
                                    <th>@lang('main.details')</th>

                                </thead>
                                <tbody>
                                    @forelse ($resturants_most_ordered as $key => $order)
                                        <tr id="td-{{$order->id}}">
                                            <td>
                                                {{ $order->order_no }}
                                            </td>
                                            <td>
                                                {{ $order->resturant?->name }}
                                            </td>
                                            <td>
                                                {{__('main.'.$order->status)}}
                                            </td>
                                            <td>
                                                <a target="_black" class="btn btn-outline-primary" href="{{route('orders.show',$order->id)}}">@lang('main.more details')</a>
                                            </td>
                                        </tr>
                                    @empty
                                        <td class="text-center text-muted" style="font-size: 25px" colspan="8">
                                            {{ trans('main.Noorders') }}
                                        </td>
                                    @endforelse
                                </tbody>
                            </table>
                    </div>
                </div>
              </div>
        @else
        @if(auth('admin')->user()->base_resturant)
        
        @php $id_returant = Session::put('id_returant',auth('admin')->user()->base_resturant?->id); @endphp
        
        @if((auth('admin')->user()->base_resturant->status == 'disabled' || auth('admin')->user()->base_resturant->status == 'closed') && auth('admin')->user()->status == 'disabled' && auth('admin')->user()->expiration_date < now() )
            <div class="note">
                <a href="{{route('vendor.get_wallet',['amount' =>(auth('admin')->user()->min_wallet - auth('admin')->user()->balance) ])}}">
                <!--@lang('main.disabled')-->
                <span><i class="fa-solid fa-info"></i></span>
                <p class="m-0">
                    حسابك معلق الان. يجب شحن المحفظة بالحد الادنى
                    <b>{{auth('admin')->user()->min_wallet}} ج.م</b>
                    حتى يتم تفعيل الحساب وتلقي الطلبات
                </p>
                </a>  
            </div>
        @endif
        
            {{--<form method="post" action="{{route('resturants.updateStatus',auth('admin')->user()->base_resturant->id)}}">
                @csrf
                <select class="form-select col-md-12" name="status" onchange="this.form.submit()">
                    <option value="">@lang('main.choose')</option>
                    <option value="opened" @if(auth('admin')->user()->base_resturant->status == 'opened') selected @endif>@lang('main.opened kitchen')</option>
                    <option value="busy" @if(auth('admin')->user()->base_resturant->status == 'busy') selected @endif>@lang('main.busy kitchen')</option>
                    <option value="closed" @if(auth('admin')->user()->base_resturant->status == 'closed') selected @endif>@lang('main.closed kitchen')</option>
                </select>
            </form>--}}
            @if(auth('admin')->user()->expiration_date >= now())
            @php                 $fdate = now();
                $tdate = auth('admin')->user()->expiration_date;
                $datetime1 = new \DateTime($fdate);
                $datetime2 = new \DateTime($tdate);
                $interval = $datetime1->diff($datetime2);
                $diff = $interval->format('%a');
            @endphp
            @if($diff <= 3 && $diff > 0 )
            <div class="note">
                <a href="{{route('vendor.get_wallet',['amount' =>auth('admin')->user()->min_wallet - auth('admin')->user()->balance ])}}">
                <span><i class="fa-solid fa-info"></i></span>
                <p class="m-0">
                 رصيد محفظتك اصبح أقل من الحد الأدني المطلوب ، اشحن رصيدك في خلال فتره السماح وهيا {{$diff}}  أيام بدأ من اليوم

                </p>
                </a>  
            </div>
            @endif
            @endif
            <!--&& (auth('admin')->user()->expiration_date >= now() || auth('admin')->user()->expiration_date == null)-->
            @if(auth('admin')->user()->base_resturant->status != 'disabled' && auth('admin')->user()->status != 'disabled'  )
            <div class="row  align-items-center gy-2 mb-2">
                <div class="col-6">
                    <h1 class="m-0 text-dark fs-4">
                      @lang('main.choose resturant status') ({{auth('admin')->user()->base_resturant?->name}})
                        
                        
                    </h1>
                </div><!-- /.col -->
                <div class="col-6">
                    <ol class="breadcrumb float-sm-left m-0" style="background:transparent">
                                              <li class="breadcrumb-item">
            @if(auth('admin')->user()->base_resturant?->calcualte_star_rate() > 0)
            <button type="button" class="btn btn-success"
            data-bs-toggle="modal" data-bs-target="#showRating">
                @if(auth('admin')->user()->base_resturant?->calcualte_star_rate() > 0)
                    <i style="color:#ffda21;" class="fa fa-star p-0"></i>   
                    {{auth('admin')->user()->base_resturant?->calcualte_star_rate()}} 
                @endif
                <small>( {{auth('admin')->user()->base_resturant?->reviews()->count()}} @lang('main.no ratings') )</small>
            </button>
            
            <!-- Modal -->
            <div class="modal fade" id="showRating" tabindex="-1" aria-labelledby="showRatingLabel" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="showRatingLabel">  {{auth('admin')->user()->base_resturant?->reviews()->count()}} @lang('main.no ratings')</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <ul>
                        <style>
                            .user-comment-container:not(:last-child) {
                               margin-bottom: .5rem;
                               border-bottom: 1px solid #eaeaea;
                            }
                            
                            .user-comment {
                               display: flex;
                               margin-bottom: .5rem;
                            }
                            
                            .rate{
                                font-size: small;
                            }
                            
                            
                            .description-comments .comment-details {
                               display: flex;
                               flex-direction: column;
                               align-items: flex-start;
                            }
                            
                            .comment-details p {
                               color: #B7B7B7;
                               font-size: 14px;
                               margin-top: .4rem;
                               margin-bottom: 0;
                            }
                            
                            .comment {
                               color: #858585;
                               font-size: 15px;
                            }
                            .ord_num{
                                FONT-WEIGHT: BOLD
                                /*color: var(--main)*/
                            }
                        </style>
                        @foreach(auth('admin')->user()->base_resturant?->reviews->sortByDesc('id') as $review)
                            <li class="user-comment-container">
                              <div class="user-comment align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-3">
                                  <img class="avatar" src="https://faskhangy.com/dashboard/dist/img/avatar_icon.png" alt="admin image">
                                  <div class="comment-details">
                                    <h6>{{$review->user?->name}}</h6>
                                    <p>{{$review->created_at->format('Y-m-d')}}</p>
                                  </div>
                                </div>
                                <!--<div class="Stars" style="--rating: 4"></div>-->
                                <div class="rate">{{$review->rate}} <i style="color:#ffda21;" class="fa fa-star p-0"></i></div>
                              </div>
                              <p class="comment">
                               @lang('main.Evaluation of order no')
                                 <span class="ord_num">  #{{$review->order?->order_no}} </span>
                                 {{__('main.review_'.$review->rate)}}
                              </p>
                            </li>
                        @endforeach
                    </ul>
                  </div>
               
                </div>
              </div>
            </div>
            @endif
                                                  </li>  
                                           </ol>
                </div><!-- /.col -->
           </div>
            
            
            <form method="post" action="{{route('resturants.updateStatus',auth('admin')->user()->base_resturant->id)}}" id="searchTypeToggle" class="my-3">
                @csrf
              <div></div>
              <label>
                <input type="radio" name="status" data-location="0" value="closed" @if(auth('admin')->user()->base_resturant->status == 'closed') checked @endif>
                <div>@lang('main.closed kitchen')</div>
              </label>
              <label>
                <input type="radio" name="status" data-location="calc(100% - 8px)" value="busy" @if(auth('admin')->user()->base_resturant->status == 'busy') checked  @endif>
                <div>@lang('main.busy kitchen')</div>
              </label>
              <label>
                <input type="radio" name="status" data-location="calc(200% - 12px)" value="opened" @if(auth('admin')->user()->base_resturant->status == 'opened') checked @endif>
                <div>@lang('main.opened kitchen')</div>
              </label>
            </form>
            @endif
             
            <style>
                form {
                    width: 100%;
                    box-sizing: border-box;
                    box-shadow: 0px 1px 2px 1px rgba(0, 0, 0, 0.4);
                    text-align: center;
                    position: relative;
                    background: linear-gradient(41deg, var(--main) 0.31%, var(--main-light) 119.6%) !important;
                    ; border-radius: 3rem;
                    overflow: hidden;
                    color: #fff;
                    /*font-size: 18px;*/
                    /*font-weight: bold;*/
                    padding-inline: 6px; direction: ltr;
                }

                form > div {
                    color: white;
                    padding-top: 24px;
                    display: block;
                    position: absolute;
                    top: 4px;
                    left: 4px;
                    right: 4px;
                    bottom: 6px;
                    width: calc(33.33%);
                    background-color: #fff;
                    border-radius: 139px;
                    z-index: 1;
                    pointer-events: none;
                    transition: transform 0.3s;
                }

                form label {
                    float: left;
                    width: calc(33.333% - 1px);
                    position: relative;
                    padding: 24px 0px 24px;
                    overflow: hidden;
                    transition: color 0.3s;
                    cursor: pointer;
                    margin: 0;
                    -webkit-tap-highlight-color: rgba(255, 255, 255, 0);
                }

                form label div {
                    z-index: 5;
                    position: absolute;
                    top: 50%;
                    right: 50%;
                    transform: translate(50%, -50%);
                    width: 100%;
                }

                form label input {
                    position: absolute;
                    top: -200%;
                }

                form label.selected {
                    color: var(--main);
                    font-weight: bold
                }
                
                @media (max-width: 576px) {
                    form {
                        font-size: 10px;
                        font-weight: lighter;
                        padding-inline: 14px;
                        direction: ltr;
                        text-overflow: ellipsis;
                    }
                    form label div {
                        text-wrap: pretty;
                    }
                }
            </style>
        @endif

        <div class="row gy-3 mb-3">
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <!--<a href="" class="link"></a>-->
                <span class="info-box-icon bg-info">
                    <!--<i class="fas fa-user-cog"></i>-->
                    <i class="fas fa-user-shield"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('main.balance')</span>
                    <span class="info-box-number mt-2">{{auth('admin')->user()->balance}} @lang('main.egp')</span>
                </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <!--<a href="" class="link"></a>-->
                <span class="info-box-icon bg-info">
                    <!--<i class="fas fa-user-cog"></i>-->
                    <i class="fas fa-user-shield"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('main.all_orders')</span>
                    <span class="info-box-number mt-2">{{$all_orders->count()}}</span>
                </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <!--<a href="" class="link"></a> -->
              <span class="info-box-icon bg-info">
                  <i class="fas fa-shield-alt"></i>
                </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.pending_orders')</span>
                <span class="info-box-number mt-2">{{$all_orders->where('status','pending')->count()}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <!--<a href="" class="link"></a>-->
                <span class="info-box-icon bg-info">
                    <i class="fas fa-user-tie"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">@lang('main.accepted_orders')</span>
                    <span class="info-box-number mt-2">{{$all_orders->where('status','accepted')->count()}}</span>
                </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
         <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <!--<a href="" class="link"></a> -->
              <span class="info-box-icon bg-info">
                <i class="fas fa-door-open"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.another_delegate_orders')</span>
                <span class="info-box-number mt-2">{{$all_orders->where('status','another_delegate')->count()}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <!--<a href="" class="link"></a> -->
              <span class="info-box-icon bg-info">
                <i class="fas fa-warehouse"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.shipped_orders')</span>
                <span class="info-box-number mt-2">{{$all_orders->where('status','shipped')->count()}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <!--<a href="" class="link"></a> -->
              <span class="info-box-icon bg-info">
                <i class="fas fa-parking"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.completed_orders')</span>
                <span class="info-box-number mt-2">{{$all_orders->where('status','completed')->count()}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <div class="col-lg-3 col-md-4 col-6">
            <div class="info-box">
                <!--<a href="" class="link"></a> -->
              <span class="info-box-icon bg-info">
                <i class="fas fa-door-open"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.cancelled_orders')</span>
                <span class="info-box-number mt-2">{{$all_orders->where('status','cancelled')->count()}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>

         
        </div>
        
        @endif
      @elseif(auth('admin')->user()->account_type=='resturant_owner')

      <div class="row gy-3 mb-3 mt-2">
            <div class=" col-6">
            <div class="info-box">
                <a href="{{url('admin/resturants')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-question-circle"></i>

              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.resturantsCount')</span>
                <span class="info-box-number mt-2">{{\App\Models\Resturant::count()}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
           <div class=" col-6">
            <div class="info-box">
                <a href="{{url('admin/orders')}}" class="link"></a> 
              <span class="info-box-icon bg-info">
                <i class="fas fa-door-open"></i>
              </span>

              <div class="info-box-content">
                <span class="info-box-text">@lang('main.ordersCount')</span>
                <span class="info-box-number mt-2">{{\App\Models\Order::orderBy('created_at','desc')->count()}}</span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
      </div>
      
      <div class="row gy-3 mb-3">
          <h4>@lang('main.last 10 orders')</h4>
          <table class="table table-responsive table-bordered table-hover">
                                <thead>
                                    <th>#</th>
                                    <th>@lang('main.resturant')</th>
                                    <th>@lang('main.order_id,date,status')</th>
                                    <th>@lang('main.grand_total')</th>
                                    <th>@lang('main.customer,email,mobile')</th>
                                    <th>@lang('main.order_type')</th>
                                    <th>@lang('main.details')</th>
                                </thead>
                                <tbody>
                                    @forelse (\App\Models\Order::orderBy('created_at','desc')->take(10)->get() as $order)
                                        <tr>
                                            <td>{{ $loop->iteration }}</td>
                                            <td>{{ $order->resturant?->name }}</td>
                                            <td>
                                                <p style="font-weight: bolder;">#{{ $order->order_no }}</p>
                                                <!--<p>{{ $order->order_date ?? $order->created_at }}</p>-->
                                                <p class="mb-1"> 
                                                {{ \Carbon\Carbon::parse($order->created_at)->format('d/m/Y') }}
                                                </p>
                                                <p class="mb-1">
                                                    {{ \Carbon\Carbon::parse($order->created_at)->format('h:i A') }}
                                                </p>
                                                <p class="status {{$order->status}}">{{ __('main.'.$order->status) }}</p>
                                            </td>
                                            <td>
                                                <p>
                                                {{ $order->grand_total}} @lang('main.egp')
                                                </p>
                                            </td>
                                            <td>
                                                @if($order->user)
                                                <p>{{ $order->user?->name }}</p>
                                                <p><a href="mailto:{{ $order->user?->email }}">{{ $order->user?->email }}</a></p>
                                                <p><a href="tel:{{ $order->user?->mobile }}">{{ $order->user?->mobile }}</a></p>
                                                @endif
                                            </td>
                                            <td>
                                                
                                                @if($order->order_type != 'shipping')
                                                    @if($order->status!='cancelled')
                                                    {{$order->delegate_from_out ?__('main.'.$order->delegate_from_out):__('main.still under process')}} 
                                                    @endif
                                                    ( @lang('main.'.$order->order_type))
                                              
                                                @elseif($order->order_type == 'shipping')
                                                     @lang('main.shipping')
                                                
                                                @endif
                                            </td>
                                            <td>
                                                <a href="{{route('orders.show',$order->id)}}"><i class="info fa fa-info-circle"></i></a>
                                            </td>
                                        </tr>
                                    @empty
                                        <td class="text-center text-muted" style="font-size: 25px" colspan="8">
                                            {{ trans('main.Noorders') }}
                                        </td>
                                    @endforelse
                                </tbody>
                            </table>
      </div>
     @endcan

     </div>
    </section>
  <!-- /.content-wrapper -->
</div>
@endsection
@push('custom-js')
    @if(auth()->user()->roles->pluck("id")->first() == 11)
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.7.1/Chart.min.js" charset="utf-8"></script>
        {!! $users_monthlyChart->script() !!}
        {!! $users_yearlyChart->script() !!}
    @endif
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            let currentStatus = "{{ auth('admin')->user()->base_resturant?->status }}";
            let slider = document.querySelector('#searchTypeToggle > div');
            let labels = document.querySelectorAll('#searchTypeToggle label');
            let input = document.querySelector('input[name="status"]:checked');
            console.log(input)
            if (input) {
                slider.style.transform = `translateX(${input.dataset.location})`;
                labels.forEach(function(label){
                    if (label == input.parentElement) {
                        label.classList.add('selected');
                    } else {
                        label.classList.remove('selected');
                    }
                });
            }
        });

        document.querySelector('#searchTypeToggle').addEventListener('click', function(event){ 
            if (event.target.tagName.toLowerCase() == 'input') {
                
                let input = event.target;
                let slider = this.querySelector('div');
                let labels = this.querySelectorAll('label');
                
                // تحديث موقع السلايدر
                slider.style.transform = `translateX(${input.dataset.location})`;
                
                // إزالة وإضافة class="selected" وتحديد الـ input
                labels.forEach(function(label){
                    let labelInput = label.querySelector('input');
                    if (label == input.parentElement) {
                        label.classList.add('selected');
                        labelInput.checked = true; // تعيين checked للـ input
                    } else {
                        label.classList.remove('selected');
                        labelInput.checked = false; // إزالة checked من الـ input الآخر
                    }
                });
                // تقديم الفورم
                this.submit();
        
            }
        });


    </script>
@endpush