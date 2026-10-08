@extends('admin.index')
@push('custom-css')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style type="text/css"></style>
@endpush
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-6">
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.orders')  ({{__('main.'.request('q'))}}) <small class="countModule"> ({{$orders->count()}}) </small></h1>
                    </div><!-- /.col -->
                    <div class="col-6">
                        <ol class="breadcrumb float-sm-left">
                            <li><a href="{{url('admin/reports')}}" class="btn btn-primary">@lang('main.showAll') @lang('main.reports')</a></li>
                        </ol>
                    </div><!-- /.col -->
                </div><!-- /.row -->
            </div><!-- /.container-fluid -->
        </div>
        <!-- /.content-header -->

        <!-- Main content -->
        <section class="content">
            <div class="container-fluid">
                <div class="">
                    <div class="card">
                        @push('card_title')
                            @lang('main.orders')
                        @endpush
                        @include('admin.partials.card_header_in_index')

                        <div class="card-body">
                            <div class="d-flex flex-wrap gy-3 align-items-center justify-content-between mb-3">
                                <ul class="nav nav-pills">
                                  <li class="nav-item">
                                    <a class="nav-link @if(request('q') == 'daily') active @endif" href="{{route('reports.orders',['q' => 'daily', 'status' => request('status')])}}">@lang('main.daily')</a>
                                  </li>
                                  <li class="nav-item">
                                    <a class="nav-link @if(request('q') == 'weekly') active @endif" href="{{route('reports.orders',['q' => 'weekly', 'status' => request('status')])}}">@lang('main.weekly')</a>
                                  </li>
                                  <li class="nav-item">
                                    <a class="nav-link @if(request('q') == 'monthly') active @endif" href="{{route('reports.orders',['q' => 'monthly', 'status' => request('status')])}}">@lang('main.monthly')</a>
                                  </li>
                                  <li class="nav-item">
                                    <a class="nav-link @if(request('q') == 'yearly') active @endif" href="{{route('reports.orders',['q' => 'yearly', 'status' => request('status')])}}">@lang('main.yearly')</a>
                                  </li>
    
                                </ul>
                                <form method="get" action="{{route('reports.orders',['q' => request('q') , 'status' => request('status')])}}">
                                    <input type="hidden" name="status" value="{{request('status')}}">
                                <select class="form-select col-md-12" name="status">
                                    <option value="">@lang('main.choose')</option>
                                    <option value="pending" @if(request('status') == 'pending') selected @endif>@lang('main.order-pending')</option>
                                    <option value="accepted" @if(request('status') == 'accepted') selected @endif>@lang('main.order-accepted')</option>
                                    <option value="shipped" @if(request('status') == 'shipped') selected @endif>@lang('main.order-shipped')</option>
                                    <option value="cancelled" @if(request('status') == 'cancelled') selected @endif>@lang('main.order-cancelled')</option>
                                    <option value="completed" @if(request('status') == 'completed') selected @endif>@lang('main.order-completed')</option>
                                </select>
                                </form>
                                {{--<a class="nav-link" href="{{ route('exportorder.excel',['q' => request('q')]) }}" style="border:1px solid ;">
                                    @lang('main.export excel')</a>--}}
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                <thead>
                                    <!--<th width="50px"><input type="checkbox" id="master"></th>-->
                                    <th>#</th>
                                    <th>@lang('main.order_no')</th>
                                    <th>@lang('main.total_price')</th>
                                    <th>@lang('main.delivery_price_for_delegate')</th>
                                    <th>@lang('main.grand_total')</th>
                                    <th>@lang('main.vendor_tax')</th>
                                    <th>@lang('main.app_percentage')</th>
                                    <th>@lang('main.status')</th>
                                    <th>@lang('main.details')</th>

                                </thead>
                                <tbody>
                                    @forelse ($orders as $key => $order)
                                        <tr id="td-{{$order->id}}">
                                            <!--<td><input type="checkbox" class="sub_chk" data-id="{{ $order->id }}">-->
                                            <!--</td>-->
                                            <td width="30px">{{$key+1}}</td>
                                            <td width="50px">
                                                {{ $order->order_no }}
                                            </td>
                                            <td width="50px">
                                                {{ $order->total }} @lang('main.egp')
                                            </td>
                                            <td>
                                                {{ $order->grand_total }} @lang('main.egp')
                                            </td>
                                            <td>
                                                {{ $order->delivery_price }} @lang('main.egp')
                                            </td>
                                            <td>
                                                {{ $order->vendor_percentage }} @lang('main.egp')
                                            </td>
                                            <td>
                                                {{ $order->app_percentage }} @lang('main.egp')
                                            </td>
                                            <td>
                                                {{__('main.order-'.$order->status)}}
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
                    <div class="row">
                      <section class="col-lg-5">
                            <!-- Custom tabs (Charts with tabs)-->
                            <div class="card">
                              <div class="card-header d-flex p-0" style="background: white !important;">
                                <a href=""><h2 class="card-title p-3">
                                  <i class="fas fa-chart-pie mr-1"></i>
                                  @lang('main.show all orders status')
                                </h2></a>
                              </div>
                              <div class="card-body" style="width: 80%;margin: 0 auto;">
                                  {!! $registrationsChart->container() !!}
                              </div>
                            </div>
                            <!-- /.card -->
                          </section>

                            <section class="col-lg-7">
                                
                                <div class="row">
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-12 mb-2">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.countDailyOrders')</span>
                                        <span class="info-box-number mt-2 mr-4">{{App\Models\Order::whereNotNull('status')->where('type','current')->whereDay('updated_at', now()->day)->count()}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-12 mb-2">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.countWeeklyOrders')</span>
                                        <span class="info-box-number mt-2 mr-4">{{App\Models\Order::whereNotNull('status')->where('type','current')->
                                        whereBetween('updated_at', [\Carbon\Carbon::now()->startOfWeek(\Carbon\Carbon::SUNDAY), \Carbon\Carbon::now()->endOfWeek(\Carbon\Carbon::SATURDAY)])->count()}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-12 mb-2">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.countMonthlyOrders')</span>
                                        <span class="info-box-number mt-2 mr-4">{{App\Models\Order::whereNotNull('status')->where('type','current')->whereMonth('updated_at', \Carbon\Carbon::now()->month)->count()}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-12 mb-2">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.countYearlyOrders')</span>
                                        <span class="info-box-number mt-2 mr-4">{{App\Models\Order::whereNotNull('status')->where('type','current')->whereYear('updated_at', \Carbon\Carbon::now()->year)->count()}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-12 mb-2">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.grandTotal') {{__('main.'.request('q'))}}</span>
                                        <span class="info-box-number mt-2 mr-4">{{$orders->sum('grand_total')}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-12 mb-2">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.grandTax') {{__('main.'.request('q'))}}</span>
                                        <span class="info-box-number mt-2 mr-4">{{$orders->sum('vendor_percentage')}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-12 mb-2">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.grandAppPercentage') {{__('main.'.request('q'))}}</span>
                                        <span class="info-box-number mt-2 mr-4">{{$orders->sum('app_percentage')}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-12 mb-2">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.grandShipping') {{__('main.'.request('q'))}}</span>
                                        <span class="info-box-number mt-2 mr-4">{{$orders->sum('delivery_price')}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                </div>
                            </section>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection
@push('custom-js')
<script>
    $('form select').on('change', function(){
    $(this).closest('form').submit();
});
</script>
  <script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.7.1/Chart.min.js') }}" charset="utf-8"></script>
         {!! $registrationsChart->script() !!}
@endpush