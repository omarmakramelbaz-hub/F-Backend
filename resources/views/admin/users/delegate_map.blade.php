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

@endsection
@push('custom-js')
<script src="https://maps.googleapis.com/maps/api/js?key={{config('services.maps.browser_key')}}&language=ar"></script>

    <script type="text/javascript">

                    var jArray = <?php echo json_encode($arr); ?>;
                    //console.log(jArray);
                    var locations=[];
                    for(var i=0; i<jArray.length; i++){
                        locations.push(jArray[i]);
                    }
                    var map = new google.maps.Map(document.getElementById('map'), {
                        zoom: 4,
                        center: new google.maps.LatLng(26.866136552951208, 30.043314297356353),
                        mapTypeId: google.maps.MapTypeId.ROADMAP
                    });

                    var infowindow = new google.maps.InfoWindow();

                    var marker, i;

                    for (i = 0; i < locations.length; i++) {
                        marker = new google.maps.Marker({
                            position: new google.maps.LatLng(locations[i][1], locations[i][2]),
                            map: map,
                            url:locations[i][3]
                        });

                        google.maps.event.addListener(marker, 'click', (function(marker, i) {
                            return function() {
                                // console.log();
                                infowindow.setContent('<a target="_blank" href="'+locations[i][3]+'">'+locations[i][0]+'</a><p>'+locations[i][4]+'</p>');
                                      // window.location.href = marker.url;
                                // infowindow.setContent(locations[i][3]);

                                infowindow.open(map, marker);
                            }
                        })(marker, i));
                    }
                </script>
@endpush