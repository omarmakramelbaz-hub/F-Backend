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
                        <h1 class="m-0 text-dark"> @lang('main.resturants report') </h1>
                    </div><!-- /.col -->
                    <div class="col-6">
                        <ol class="breadcrumb float-sm-left">
                             <li><a href="{{url('admin/reports')}}" class="btn btn-primary">@lang('main.showAll') @lang('main.resturants report')</a></li>
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
                
                    <div class="row">
                            <section class="col-lg-12">
                                <form method="get">
                                    <label>@lang('main.choose resturant')</label>
                                    <select name="resturant_id" onChange="this.form.submit()" class="form-select mb-5">
                                        <option value="">{{ __(auth('admin')->user()->account_type==='admin' && empty(auth('admin')->user()->owner_resturant_id) ? 'home_overview.all_branches' : 'main.choose') }}</option>
                                        @foreach(\App\Models\Resturant::get() as $value)
                                        <option value="{{$value->id}}" @if($value->id == request('resturant_id')) selected @endif>{{$value->name}}</option>
                                        @endforeach
                                    </select>
                                </form>
                                <div class="row gy-3 mt-4">
                                    <div class="col-lg-4 col-md-4 col-sm-4 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.orderDelegateOutdoorCount') <small>(@lang('main.shipped'))</small></span>
                                        <span class="info-box-number mt-2 mr-4">{{$orders->where('status','shipped')->count()}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-4 col-md-4 col-sm-4 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.orderAcceptedCount') <small>(@lang('main.accepted'))</small></span>
                                        <span class="info-box-number mt-2 mr-4">{{$orders->where('status','accepted')->count()}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                  
                                    <div class="col-lg-4 col-md-4 col-sm-4 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.total_cash_order')</span>
                                        <span class="info-box-number mt-2 mr-4">{{$total_cash_order}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    <div class="col-lg-4 col-md-4 col-sm-4 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.total_gain_from_app')</span>
                                        <span class="info-box-number mt-2 mr-4">{{$total_gain_from_app}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-4 col-md-4 col-sm-4 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.gain_online')</span>
                                        <span class="info-box-number mt-2 mr-4">{{$gain_online}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-4 col-md-4 col-sm-4 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.gain_cash')</span>
                                        <span class="info-box-number mt-2 mr-4">{{$gain_cash}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    <div class="col-lg-4 col-md-4 col-sm-4 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.ordersPaid')</span>
                                        <span class="info-box-number mt-2 mr-4">{{$transfer_cash_orders}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-4 col-md-4 col-sm-4 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.ordersNotPaid')</span>
                                        <span class="info-box-number mt-2 mr-4">{{$not_transfer_cash_orders}}</span>
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

  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.7.1/Chart.min.js" charset="utf-8"></script>
@endpush
