@extends('admin.index')
@push('custom-css')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row gy-2 mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 text-dark">@lang('main.showAll') @lang('main.customers report')
            <small class="countModule"> </small>
         </h1>
                    </div><!-- /.col -->
                    <div class="col-sm-6">
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
                
                            <section class="col-lg-12 mb-3">
                                
                                <div class="row gy-3">
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.pending_users')</span>
                                        <span class="info-box-number mt-2 mr-4">{{$pending_users}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.accepted_users')</span>
                                        <span class="info-box-number mt-2 mr-4">{{$accepted_users}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.no_wishlits_users')</span>
                                        <span class="info-box-number mt-2 mr-4">{{$no_wishlits_users}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                    
                                    <div class="col-lg-6 col-md-6 col-sm-6 col-6">
                                    <div class="info-box">
                                      <span class="info-box-icon bg-info"><i class="fa fa-address-card"></i></span>
                        
                                      <div class="info-box-content mt-2">
                                        <span class="info-box-text">@lang('main.no_commissions_users')</span>
                                        <span class="info-box-number mt-2 mr-4">{{$no_commissions_users}}</span>
                                      </div>
                                      <!-- /.info-box-content -->
                                    </div>
                                    </div>
                                </div>
                                </section>
                <div class="">
                    <div class="card">
                        @push('card_title')
                            @lang('main.customers report')
                        @endpush
                        @include('admin.partials.card_header_in_index')
                        
                        <div class="card-body" style="width: 80%;margin: 0 auto;">
                            {!! $usersChart->container() !!}
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection
@push('custom-js')

  <script src="{{ App\Services\Dashboard\DesktopDashboardAssets::url('https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.7.1/Chart.min.js') }}" charset="utf-8"></script>
        {!! $usersChart->script() !!}
@endpush