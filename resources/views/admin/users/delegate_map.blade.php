@extends('admin.index')
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 text-dark">@lang('main.delegates on map')</h1>
                    </div><!-- /.col -->
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
							<li class="btn main-btn" style="    color: #f91313;
    font-weight: bold;">@lang('main.delegate count') : {{count($arr)}} </li>                        
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
                  <div id="map"  style="width: 100%; height: 600px;"></div>
			         <input type="hidden" name="vendors" value="">
			    </div>
    	    </div>
		</section>            

</div>
@endsection
@push('custom-js')
@include('admin.maps.directory', ['mapKind'=>'delegate'])
@endpush
