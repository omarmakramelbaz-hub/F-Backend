@extends('admin.index')
@push('custom-css')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style type="text/css">
        .card-slot{
            border: 1px solid #c1c0c0 !important;
                margin: 0px 2px;
                border-radius: 11px;        
                margin-bottom: 20px;
            }
        .empty{
            background: #45d845;
            color: #fff;
        }
        .busy{
            background: #e91d1d;
            color: #fff;
        }
    </style>
@endpush
@section('content')
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row justify-content-between align-items-center gy-2 mb-2">
                <div class="col-auto">
                    <h1 class="m-0 text-dark">@lang('main.show') {{__('main.'.request('account_type'))}}</h1>
                </div><!-- /.col -->
                <div class="col-auto">
                    <ol class="breadcrumb float-sm-left">
                        <li class="breadcrumb-item"><a
                            href="{{ url('admin/users?account_type='.request('account_type')) }}"
                            class="btn btn-primary">@lang('main.showAll') {{__('main.'.request('account_type'))}}</a></li>
                        </ol>
                    </div><!-- /.col -->
                </div><!-- /.row -->
            </div><!-- /.container-fluid -->
        </div>
        <!-- /.content-header -->

        <!-- Main content -->
        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-lg-12 col-md-12">
                        <div class="card">
                            <div class="card-body show-data row">
                                @if($user->account_type!='user')
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label for="email">@lang('main.ProfileImage') </label>
                                        @if ($user->getFirstMediaUrl('photo_profile','thumb'))
                                        <img src="{{ $user->getFirstMediaUrl('photo_profile','thumb') }}" data-toggle="modal" data-target="#exampleModal{{ $user->id }}" width="10%">
                                        @include('admin.components.modal_photo', [
                                        'image' => $user->getFirstMediaUrl('photo_profile','thumb'),
                                        'id' => $user->id,
                                        ])
                                        @else
                                        <span> @lang('main.NoOfferImage')</span>
                                        @endif
                                    </div>
                                </div>
                                @endif
                                @if($user->name)
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.name')</label>
                                        <span>{{ $user->name }}</span>
                                    </div>
                                </div>
                                @endif
                                @if($user->email)
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.email')</label>
                                        <span>{{ $user->email }}</span>
                                    </div>
                                </div>
                                @endif
                                @if($user->mobile)
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.mobile')</label>
                                        <span>{{ $user->country_code }}{{ $user->mobile }}+</span>
                                    </div>
                                </div>
                                @endif
                                @if(request('account_type') != 'admin')
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.mobile_verified_at')</label>
                                        <span>{{ $user->mobile_verified_at }}</span>
                                    </div>
                                </div>
                                @endif
                                
                                
                                @if(request('account_type') == 'delegate')
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.lat'), @lang('main.lng')</label>
                                        <span>{{ $user->lat }}, {{ $user->lng }}</span>
                                    </div>
                                </div>

                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.area')</label>
                                        <span>{{ $user->area?->title }}</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.connected')</label>
                                        <span>{{ __('main.'.$user->connected) }}</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.balance')</label>
                                        <span>{{ $user->balance }} @lang('main.egp')</span>
                                    </div>
                                </div>
                                @endif
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.account_type')</label>
                                        <span>{{ __('main.'.$user->account_type) }}</span>
                                    </div>
                                </div>
                                @if(request('account_type') == 'vendor' && $user->base_resturant)
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.resturant')</label>
                                        <span><a href="{{route('resturants.show',$user->base_resturant?->id)}}">{{$user->base_resturant?->name}}</a></span>
                                    </div>
                                </div>
                                @endif
                                
                                @if(request('account_type') == 'vendor' || request('account_type') == 'delegate' )
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.decline_reason client')</label>
                                        <span>{{$user->decline_reason}}</span>
                                    </div>
                                </div>
                                @endif
                                
                                @if(request('account_type') == 'resturant_owner' )
                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.resturant')</label>
                                        <span>{{$user->owner_resturant?->name}}</span>
                                    </div>
                                </div>
                                @endif
                                
                                <!--<div class="col-sm-6">-->
                                <!--    <div class='form-group'>-->
                                        
                                <!--        <label> @lang('main.status')</label>-->
                                <!--        <span>{{ __('main.'.$user->status) }}</span>-->
                                    
                                <!--    </div>-->
                                <!--</div>-->
                                  <div class="col-12">
                                    <hr>
                                </div>

                            @if($user->account_type == 'user')
                                <ul class="nav nav-pills mb-3 user-pills border-bottom" id="pills-tab" role="tablist">
                                    <li class="nav-item mt-3 ml-3 mb-3 active">
                                        <a class="nav-link active" id="pills-userorders-tab" data-bs-toggle="pill" data-bs-target="#pills-userorders" type="button" role="tab" aria-controls="pills-userorders" aria-selected="true"> <i class="fa fa-blog"></i> @lang('main.show all user orders')</a>
                                    </li>
                                    <li class="nav-item mt-3 ml-3 mb-3 ">
                                        <a class="nav-link " id="pills-userwishlist-tab" data-bs-toggle="pill" data-bs-target="#pills-userwishlist" type="button" role="tab" aria-controls="pills-userwishlist" aria-selected="true"><i class="fa fa-paperclip"></i> @lang('main.show all user wishlist')</a>
                                    </li>
                                    <li class="nav-item mt-3 ml-3 mb-3">
                                        <a class="nav-link" id="pills-useraddresses-tab" data-bs-toggle="pill" data-bs-target="#pills-useraddresses" type="button" role="tab" aria-controls="pills-useraddresses" aria-selected="true"><i class="fa fa-paperclip"></i> @lang('main.show all user addresses')</a>
                                    </li>
                                    <li class="nav-item mt-3 ml-3 mb-3">
                                        <a class="nav-link" id="pills-userwallet-tab" data-bs-toggle="pill" data-bs-target="#pills-userwallet" type="button" role="tab" aria-controls="pills-userwallet" aria-selected="true"> <i class="fa fa-blog"></i> @lang('main.show all user wallet')</a>
                                    </li>
                                </ul>
    
                                <div class="tab-content" id="pills-tabContent">
                                    <div class="tab-pane fade " id="pills-userwishlist" role="tabpanel" aria-labelledby="pills-userwishlist-tab">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <th>#</th>
                                            <th>@lang('main.resturant_logo')</th>
                                            <th>@lang('main.resturant_name')</th>
                                            <th>@lang('main.created_at')</th>
                                            <th>@lang('main.actions')</th>    
                                        </thead>
                                        <tbody>
                                            @forelse($user->wishlists as $wishlist)
                                                <tr>
                                                    <td>{{ $loop->iteration }}</td>
                                                    
                                                    <td>
                                                        @if ($wishlist->getFirstMediaUrl('logo','thumb'))
                                                        <img src="{{ $wishlist->getFirstMediaUrl('logo','thumb') }}" data-toggle="modal" data-target="#exampleModall{{ $wishlist->id }}" width="10%">
                                                        @include('admin.components.modal_photo', [
                                                        'image' => 'l'.$wishlist->getFirstMediaUrl('logo','thumb'),
                                                        'id' => $wishlist->id,
                                                        ])
                                                        @else
                                                        <span> @lang('main.NoOfferImage')</span>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        {{$wishlist->resturant?->name}}
                                                    </td>
                                                    <td>
                                                        {{$wishlist->created_at->diffForHumans()}}
                                                    </td>
                                                    <td width="250px">
                                                            {!! \App\Support\NativeDeleteForm::open([
                                                                'method' => 'DELETE',
                                                                'route' => ['userwishlists.destroy', $wishlist->id],
                                                                'style' => 'display:inline',
                                                            ]) !!}
                                                            <button type="submit"
                                                                class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                            {!! \App\Support\NativeDeleteForm::close() !!}
                                                    </td>
                                                </tr>
                                            @empty
                                                <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                    {{ trans('main.Nouserwishlist') }}
                                                </td>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                    </div>
                                </div>
                                
                                <div class="tab-content" id="pills-tabContent">
                                    <div class="tab-pane fade" id="pills-useraddresses" role="tabpanel" aria-labelledby="pills-useraddresses-tab">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <th>#</th>
                                            <th>@lang('main.address_details')</th>
                                            <th>@lang('main.mobile')</th>
                                            <th>@lang('main.address_name')</th>
                                            <th>@lang('main.created_at')</th>
                                            <th>@lang('main.actions')</th>    
                                        </thead>
                                        <tbody>
                                            @forelse($user->addresses as $address)
                                                <tr>
                                                    <td>{{ $loop->iteration }}</td>
                                                    <td>
                                                        {{$address->area_name}}, {{$address->apartment_no}}, {{$address->floor_no}}, {{$address->street_name}},
                                                    </td>
                                                    <td>
                                                        {{ $address->mobile }}
                                                    </td>
                                                    <td>
                                                        {{ $address->address_name }} / {{__('main.'.$address->type)}}
                                                    </td>
                                                    <td>
                                                        {{$address->created_at->diffForHumans()}}
                                                    </td>
                                                    <td width="250px">
                                                            {!! \App\Support\NativeDeleteForm::open([
                                                                'method' => 'DELETE',
                                                                'route' => ['useraddresses.destroy', $address->id],
                                                                'style' => 'display:inline',
                                                            ]) !!}
                                                            <button type="submit"
                                                                class="btn btn-danger show_confirm">@lang('main.delete')</button>
                                                            {!! \App\Support\NativeDeleteForm::close() !!}
                                                    </td>
                                                </tr>
                                            @empty
                                                <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                    {{ trans('main.Nouseraddresses') }}
                                                </td>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                    </div>
                                </div>
    
                                <div class="tab-content" id="pills-tabContent">
                                    <div class="tab-pane fade active show" id="pills-userorders" role="tabpanel" aria-labelledby="pills-userorders-tab">
                                        <div class="table-responsive">
                                                <table class="table table-bordered table-hover">
                                                    <thead>
                                                        <th>@lang('main.no orders')</th>
                                                        <th>@lang('main.no accepted orders')</th>
                                                        <th>@lang('main.no shipped orders')</th>
                                                        <th>@lang('main.no cancelled orders')</th>
                                                        <th>@lang('main.no completed orders')</th>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td>{{$user->orders->whereNotNull('status')->where('status','!=','pending')->where('type','current')->count()}}</td>
                                                            <td>{{$user->orders->where('status','accepted')->count()}}</td>
                                                            <td>{{$user->orders->where('status','shipped')->count()}}</td>
                                                            <td>{{$user->orders->where('status','cancelled')->count()}}</td>
                                                            <td>{{$user->orders->where('status','completed')->count()}}</td>
                                                        </tr>
                                                    </tbody>
                                                    
                                                </table>
                                            </div>
                                        <h5 class="mb-3 fw-bold">كل الطلبات</h5>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-hover">
                                                <thead>
                                                    <th>#</th>
                                                    <th>@lang('main.order_no')</th>
                                                    <th>@lang('main.order_type')</th>
                                                    <th>@lang('main.payment_type')</th>
                                                    <th>@lang('main.created_at')</th>
                                                    <th>@lang('main.details')</th>
                                                </thead>
                                                <tbody>
                                                    @forelse($user->orders()->whereNotNull('status')->where('status','!=','pending')->where('type','current')->orderBy('created_at','desc')->get() as $order)
                                                        <tr>
                                                            <td>{{ $loop->iteration }}</td>
                                                            <td>
                                                                {{$order->order_no}}
                                                            </td>
                                                            <td>
                                                                {{ __('main.order-'.$order->order_type) }}
                                                            </td>
                                                            <td>
                                                                {{ __('main.'.$order->payment_type) }}
                                                            </td>
                                                            <td>
                                                                {{$order->created_at->diffForHumans()}}
                                                            </td>
                                                            <td width="250px">
                                                               <a href="{{route('orders.show',$order->id)}}"><i class="info fa fa-info-circle"></i></a>
                                                            </td>
                                                        </tr>
                                                    @empty
                                                        <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                            {{ trans('main.NoOrders') }}
                                                        </td>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
    
    
                                <div class="tab-content" id="pills-tabContent">
                                    <div class="tab-pane fade" id="pills-userwallet" role="tabpanel" aria-labelledby="pills-userwallet-tab">
                                                  <div class="row">
                                            <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <th>#</th>
                                            <th>@lang('main.from_user')</th>
                                            <th>@lang('main.to_user')</th>
                                            <th>@lang('main.type')</th>
                                            <th>@lang('main.amount')</th>
                                            <th>@lang('main.created_at')</th>
                                        </thead>
                                        <tbody>
                                            @forelse(\App\Models\Wallet::where('status','completed')->where('from_user',$user->id)->orWhere('to_user',$user->id)->orderBy('created_at','desc')->get() as $transaction)
                                                <tr>
                                                    <td>{{ $loop->iteration }}</td>
                                                    <td>
                                                       {{$transaction->from?->name?? __('main.wallet')}}

                                                    </td>
                                                    <td>
                                                        {{$transaction->to?->name?? __('main.wallet')}}
                                                    </td>
                                                    <td>
                                                        {{ __('main.'.$transaction->type) }}
                                                    </td>
                                                    <td>
                                                        {{$transaction->amount}} @lang('main.egp')
                                                    </td>
                                                    <td>
                                                        {{$transaction->created_at->diffForHumans()}}
                                                    </td>
                                                </tr>
                                            @empty
                                                <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                    {{ trans('main.NoTransfer') }}
                                                </td>
                                            @endforelse
                                        </tbody>
                                    </table>
                                    </div>
                                                  </div>
                                                </div>
                                </div>
                            @endif
                             @if($user->account_type == 'delegate')
                                <ul class="nav nav-pills mb-3 user-pills" id="pills-tab" role="tablist">
                                   
                                    <li class="nav-item mt-3 ml-3 mb-3 active">
                                        <a class="nav-link active" id="pills-delegateOrders-tab" data-bs-toggle="pill" data-bs-target="#pills-delegateOrders" type="button" role="tab" aria-controls="pills-delegateOrders" aria-selected="true"> <i class="fa fa-blog"></i> @lang('main.orders')</a>
                                    </li>
                                    <li class="nav-item mt-3 ml-3 mb-3">
                                        <a class="nav-link" id="pills-delegateCommissions-tab" data-bs-toggle="pill" data-bs-target="#pills-delegateCommissions" type="button" role="tab" aria-controls="pills-delegateCommissions" aria-selected="true"> <i class="fa fa-blog"></i> @lang('main.delegateCommissions')</a>
                                    </li>
                                   
                                </ul>

                                <div class="tab-content" id="pills-tabContent">
                                    <div class="tab-pane fade active show" id="pills-delegateOrders" role="tabpanel" aria-labelledby="pills-delegateOrders-tab">
                                        <div class="table-responsive">
                                                <table class="table table-bordered table-hover">
                                                    <thead>
                                                        <th>@lang('main.no orders')</th>
                                                        <th>@lang('main.no accepted orders')</th>
                                                        <th>@lang('main.no shipped orders')</th>
                                                        <th>@lang('main.no cancelled orders')</th>
                                                        <th>@lang('main.no completed orders')</th>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td>{{$user->delegate_orders->whereNotNull('status')->where('status','!=','pending')->where('type','current')->count()}}</td>
                                                            <td>{{$user->delegate_orders->where('status','accepted')->count()}}</td>
                                                            <td>{{$user->delegate_orders->where('status','shipped')->count()}}</td>
                                                            <td>{{$user->delegate_orders->where('status','cancelled')->count()}}</td>
                                                            <td>{{$user->delegate_orders->where('status','completed')->count()}}</td>
                                                        </tr>
                                                    </tbody>
                                                    
                                                </table>
                                            </div>
                                        <h5 class="fw-bold mb-3">كل الطلبات</h5>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-hover">
                                                <thead>
                                                    <th>#</th>
                                                    <th>@lang('main.order_no')</th>
                                                    <th>@lang('main.order_type')</th>
                                                    <th>@lang('main.payment_type')</th>
                                                    <th>@lang('main.created_at')</th>
                                                    <th>@lang('main.details')</th>
                                                </thead>
                                                <tbody>
                                                    @forelse($user->delegate_orders()->orderBy('created_at','desc')->get() as $order)
                                                        <tr>
                                                            <td>{{ $loop->iteration }}</td>
                                                            <td>
                                                                {{$order->order_no}}
                                                            </td>
                                                            <td>
                                                                {{ __('main.'.$order->order_type) }}
                                                            </td>
                                                            <td>
                                                                {{ __('main.'.$order->payment_type) }}
                                                            </td>
                                                            <td>
                                                                {{$order->created_at->diffForHumans()}}
                                                            </td>
                                                            <td width="250px">
                                                               <a href="{{route('orders.show',$order->id)}}"><i class="info fa fa-info-circle"></i></a>
                                                            </td>
                                                        </tr>
                                                    @empty
                                                        <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                            {{ trans('main.NoOrders') }}
                                                        </td>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                        <div class="tab-pane fade" id="pills-delegateCommissions" role="tabpanel" aria-labelledby="pills-delegateCommissions-tab">
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-hover">
                                                <thead>
                                                    <th>#</th>
                                                    <th>@lang('main.order_no')</th>
                                                    <th>@lang('main.username')</th>
                                                    <th>@lang('main.commission')</th>
                                                    <th>@lang('main.created_at')</th>
                                                    <th>@lang('main.details')</th>
                                                </thead>
                                                <tbody>
                                                    @forelse($user->delegate_commissions as $commission)
                                                        <tr>
                                                            <td>{{ $loop->iteration }}</td>
                                                            <td>
                                                                {{$commission->order?->order_no}}
                                                            </td>
                                                            <td>
                                                                {{$commission->user?->name}}
                                                            </td>
                                                            <td>
                                                                {{$commission->commission}} @lang('main.egp')
                                                            </td>
                                                            <td>
                                                                {{$commission->created_at->diffForHumans()}}
                                                            </td>
                                                            <td width="250px">
                                                               <a href="{{route('orders.show',$commission->order_id)}}"><i class="info fa fa-info-circle"></i></a>
                                                            </td>
                                                        </tr>
                                                    @empty
                                                        <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                            {{ trans('main.NoOrders') }}
                                                        </td>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                               
                                </div>
        <div class="row">
                                            <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <th>#</th>
                                            <th>@lang('main.from_user')</th>
                                            <th>@lang('main.to_user')</th>
                                            <th>@lang('main.type')</th>
                                            <th>@lang('main.amount')</th>
                                            <th>@lang('main.created_at')</th>
                                        </thead>
                                        <tbody>
                                            @forelse(\App\Models\Wallet::where('status','completed')->where('from_user',$user->id)->orWhere('to_user',$user->id)->orderBy('created_at','desc')->get() as $transaction)
                                                <tr>
                                                    <td>{{ $loop->iteration }}</td>
                                                    <td>
                                                        {{$transaction->from?->name?? __('main.wallet')}}
                                                    </td>
                                                    <td>
                                                        {{$transaction->to?->name?? __('main.wallet')}}
                                                    </td>
                                                    <td>
                                                        {{ __('main.'.$transaction->type) }}
                                                    </td>
                                                    <td>
                                                        {{$transaction->amount}} @lang('main.egp')
                                                    </td>
                                                    <td>
                                                        {{$transaction->created_at->diffForHumans()}}
                                                    </td>
                                                </tr>
                                            @empty
                                                <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                    {{ trans('main.NoTransfer') }}
                                                </td>
                                            @endforelse
                                        </tbody>
                                    </table>
                                    </div>
                                                  </div>
                            @endif

                            @if($user->account_type == 'vendor')
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <th>@lang('main.no orders')</th>
                                        <th>@lang('main.no accepted orders')</th>
                                        <th>@lang('main.no shipped orders')</th>
                                        <th>@lang('main.no cancelled orders')</th>
                                        <th>@lang('main.no completed orders')</th>
                                    </thead>
                                    <tbody>
                                        @php $orders = \App\Models\Order::where('resturant_id',$user->base_resturant?->id)->whereNotNull('resturant_id')->whereNotNull('status')->orderBy('created_at','desc')->get(); @endphp
                                        @if($orders)
                                        <tr>
                                            <td><a style="color: #3133db;text-decoration: underline;" href="{{route('orders.index',['resturant_id' => $user->base_resturant?->id])}}">{{$orders->whereNotNull('status')->where('status','!=','pending')->where('type','current')->count()}}</a></td>
                                            <td>{{$orders->where('status','accepted')->count()}}</td>
                                            <td>{{$orders->where('status','shipped')->count()}}</td>
                                            <td>{{$orders->where('status','cancelled')->count()}}</td>
                                            <td>{{$orders->where('status','completed')->count()}}</td>
                                        </tr>
                                        @endif
                                    </tbody>
                                                    
                                </table>
                            </div>
                            
                             <div class="row">
                                            <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <th>#</th>
                                            <th>@lang('main.from_user')</th>
                                            <th>@lang('main.to_user')</th>
                                            <th>@lang('main.type')</th>
                                            <th>@lang('main.amount')</th>
                                            <th>@lang('main.created_at')</th>
                                        </thead>
                                        <tbody>
                                            @forelse(\App\Models\Wallet::where('status','completed')->where('from_user',$user->id)->orWhere('to_user',$user->id)->orderBy('created_at','desc')->get() as $transaction)
                                                <tr>
                                                    <td>{{ $loop->iteration }}</td>
                                                    <td>
                                                        {{$transaction->from?->name?? __('main.wallet')}}
                                                    </td>
                                                    <td>
                                                        {{$transaction->to?->name?? __('main.wallet')}}
                                                    </td>
                                                    <td>
                                                        {{ __('main.'.$transaction->type) }}
                                                    </td>
                                                    <td>
                                                        {{$transaction->amount}} @lang('main.egp')
                                                    </td>
                                                    <td>
                                                        {{$transaction->created_at->diffForHumans()}}
                                                    </td>
                                                </tr>
                                            @empty
                                                <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                    {{ trans('main.NoTransfer') }}
                                                </td>
                                            @endforelse
                                        </tbody>
                                    </table>
                                    </div>
                                                  </div>
                            @endif


                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
</div>
@endsection
