<input type="number" name="added_by" value="{{ Auth::guard('admin')->user()->id }}" class="form-control" hidden>
<div class="row">

    @if(request()->route()->getName() == 'resturants.edit')
    <div class="form-group col-sm-6">
        <label for="user_id"> @lang('main.choose vendor')</label><span class="text-danger">*</span>
        <input type="hidden" name="user_id" class="form-control" value="{{$resturant->user_id}}">
        <input type="text" readonly class="form-control" value="{{$resturant->user?->name}}">
    </div>
    @else
        {{--@if(! request('resturant_id'))--}}
                <div class="form-group col-sm-6">
                    <label for="user_id"> @lang('main.choose vendor')</label><span class="text-danger">*</span>
                    <select class="form-select" name="user_id">
                    @foreach(\App\Models\User::has('user_resturants','=',0)->where('account_type','vendor')->get() as $user)
                    <option value="{{$user->id}}">{{$user->name}}</option>
                    @endforeach
                </select>
                </div>
        {{--@endif--}}
    @endif
<div class="form-group col-sm-6">
    @if(request('resturant_id') && (request('resturant_id') != $resturant->id))
    @php $parent_resturant = App\Models\Resturant::where('id',request('resturant_id'))->first(); @endphp
    <input type="hidden" name="parent_id" value="{{$parent_resturant->id}}">
    @endif
    @if(auth('admin')->user()->account_type=='resturant_owner')
    <input type="hidden" name="parent_id" value="{{auth('admin')->user()->owner_resturant_id}}">
    @endif
    <label for="name"> @lang('main.resturant_name') </label><span class="text-danger">*</span>
    <input type="text" name="name"  @if(request('resturant_id') && (request('resturant_id') != $resturant->id)) value="{{$parent_resturant->name}}" @else value="{{ old('name', $resturant->name) }}" @endif
        class="form-control @error('name') is-invalid @enderror" id="name" placeholder="">
</div>

<div class="form-group col-sm-6">
    <label for="delivery_time"> @lang('main.delivery_time') <small>(@lang('main.Approximate delivery time'))</small></label><span class="text-danger">*</span>
    <input type="text" name="delivery_time" value="{{ old('delivery_time', $resturant->delivery_time) }}"
        class="form-control @error('delivery_time') is-invalid @enderror" id="delivery_time" placeholder="">
</div>
 <div class="form-group col-sm-6">
    <label for="resturant_phone"> @lang('main.resturant_phone')</label><span class="text-danger">*</span>
    <input type="text" name="resturant_phone" value="{{ old('resturant_phone', $resturant->resturant_phone) }}"
        class="form-control @error('resturant_phone') is-invalid @enderror" id="resturant_phone" placeholder="">
</div>
 <div class="form-group col-sm-6">
    <label for="open_at"> @lang('main.open_at')</label><span class="text-danger">*</span>
    <input type="time" name="open_at" value="{{ old('open_at', $resturant->open_at) }}"
        class="form-control @error('open_at') is-invalid @enderror" id="open_at" placeholder="">
</div>
 <div class="form-group col-sm-6">
    <label for="close_at"> @lang('main.close_at')</label><span class="text-danger">*</span>
    <input type="time" name="close_at" value="{{ old('close_at', $resturant->close_at) }}"
        class="form-control @error('close_at') is-invalid @enderror" id="close_at" placeholder="">
</div>

 <div class="form-group col-sm-6">
    <label for="min_order_price"> @lang('main.min_order_price')</label><span class="text-danger">*</span>
        <div class="input-group mb-3">
     <span class="input-group-text" id="basic-addon1">@lang('main.egp')</span>

    <input type="number" min="0" name="min_order_price" value="{{ old('min_order_price', $resturant->min_order_price) }}"
        class="form-control @error('min_order_price') is-invalid @enderror" id="min_order_price" placeholder="">
        </div>
</div>
 <div class="form-group col-sm-6">
    <label for="km_price"> @lang('main.km_price')</label><span class="text-danger">*</span>
         <div class="input-group mb-3">
     <span class="input-group-text" id="basic-addon1">@lang('main.egp')</span>

    <input type="number" min="0" name="km_price" @if(auth()->user()->roles->pluck("id")->first() == 2||auth()->user()->roles->pluck("id")->first() == 13) readonly @endif value="{{ old('km_price', $resturant->km_price?$resturant->km_price:auth('admin')->user()->owner_resturant?->km_price) }}"
        class="form-control @error('km_price') is-invalid @enderror" id="km_price" placeholder="">
        </div>
</div>
     <div class="form-group col-sm-6">
                  <label for="default_0_1">@lang('main.default_0_1')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.egp')</span>
                    <input type="number" name="default_0_1" value="{{$resturant->default_0_1}}" min="0" class="form-control" id="default_0_1" placeholder="@lang('main.default_0_1')">
                  </div>
                </div>
                 <div class="form-group col-sm-6">
                  <label for="default_1_2">@lang('main.default_1_2')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.egp')</span>
                    <input type="number" name="default_1_2" value="{{$resturant->default_1_2}}" min="0" class="form-control" id="default_1_2" placeholder="@lang('main.default_1_2')">
                  </div>
                </div>
                 <div class="form-group col-sm-6">
                  <label for="default_2_3">@lang('main.default_2_3')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.egp')</span>
                    <input type="number" name="default_2_3" value="{{$resturant->default_2_3}}" min="0" class="form-control" id="default_2_3" placeholder="@lang('main.default_2_3')">
                  </div>
                </div>
 <div class="form-group col-sm-6">
         <label for="service_fees">{{ __('go_fees.store_rate') }}</label><span class="text-danger">*</span>
     <div class="input-group mb-3">
     <span class="input-group-text" id="basic-addon1">%</span>
    <input type="number" min="0" max="100" step="0.01" name="service_fees" @if(auth()->user()->roles->pluck("id")->first() == 2||auth()->user()->roles->pluck("id")->first() == 13) readonly @endif value="{{ old('service_fees', $resturant->service_fees ?? auth('admin')->user()?->owner_resturant?->service_fees) }}"
        class="form-control @error('service_fees') is-invalid @enderror" id="service_fees" placeholder="">
        </div>
</div>

<div class="form-group col-sm-6">
        <label for="status"> @lang('main.status of resturant')</label><span class="text-danger">*</span>
        <select name="status" class="form-select">
            <option value="opened" @if($resturant->status == 'opened') selected @endif>@lang('main.opened kitchen')</option>
            <option value="busy" @if($resturant->status == 'busy') selected @endif>@lang('main.busy kitchen')</option>
            <option value="closed" @if($resturant->status == 'closed') selected @endif>@lang('main.closed kitchen')</option>
            @if(auth()->user()->roles->pluck("id")->first() == 11 || auth()->user()->roles->pluck("id")->first() == 13)
            <option value="hide" @if($resturant->status == 'hide') selected @endif>@lang('main.hide')</option>
            @endif
        </select>
    </div>
    
<div class="form-group col-12"><hr></div>

<div class="form-group col-sm-6">
        <label for="logo">@lang('main.resturant logo')</label>

        <div class="input-group mb-2">
            <input type="file" name="logo" id="logo" class="form-control"
                onchange="document.getElementById('image').src = window.URL.createObjectURL(this.files[0])">
        </div>
        <div class="col-sm-6">
            @if($resturant->getFirstMediaUrl('logo','thumb'))
                <img class="cursor-img" data-bs-toggle="modal" data-bs-target="#exampleModal{{ $resturant->id }}"
                    id="image" src="{{$resturant->getFirstMediaUrl('logo','thumb')}}" style="width:70%;"
                    alt="@lang('main.NoImageUploaded')">
                @include('admin.components.modal_photo', [
                    'image' => $resturant->getFirstMediaUrl('logo','thumb'),
                    'id' => $resturant->id,
                ])
            @else
                <img id="image" src="{{ url('dashboard/dist/img/no-photo.png') }}"
                    style="height: 80px; width: 100px;">
            @endif
        </div>
    </div>


<div class="form-group col-sm-6">
        <label for="bg_image">@lang('main.resturant bg_image')</label>

        <div class="input-group mb-2">
            <input type="file" name="bg_image" id="bg_image" class="form-control"
                onchange="document.getElementById('bg_image2').src = window.URL.createObjectURL(this.files[0])">
        </div>
        <div class="col-sm-6">
            @if($resturant->getFirstMediaUrl('bg_image','thumb'))
                <img class="cursor-img" data-bs-toggle="modal" data-bs-target="#exampleModaldd{{ $resturant->id }}"
                    id="bg_image2" src="{{$resturant->getFirstMediaUrl('bg_image','thumb')}}" style="width:70%;"
                    alt="@lang('main.NoImageUploaded')">
                @include('admin.components.modal_photo', [
                    'image' => $resturant->getFirstMediaUrl('bg_image','thumb'),
                    'id' => 'dd'.$resturant->id,
                ])
            @else
                <img id="image" src="{{ url('dashboard/dist/img/no-photo.png') }}"
                    style="height: 80px; width: 100px;">
            @endif
        </div>
    </div>
    
<div class="form-group col-12"><hr></div>


<div class="form-group ">
     <div id="mapid" style="width:100%; height: 300px;"></div>
    <input name="lat" value="{{$resturant->lat?$resturant->lat:'30.0444'}}" id="lat"  style="display:none">
    <input name="lng" value="{{$resturant->lng?$resturant->lng:'31.2357'}}" id="lng"style="display:none">
    <div id="map" class="gmaps"></div>
                                                   
</div>
<div class="form-group col-md-4">
        <input class="form-control" type="text" id="latInput" placeholder="Enter Latitude" value="{{$resturant->lat}}">
    <input class="form-control" type="text" id="lngInput" placeholder="Enter Longitude" value="{{$resturant->lng}}">
    <button class="btn btn-outline-primary" id="searchBtn">Search</button>
</div>
@if(auth()->user()->roles->pluck("id")->first() == 11||auth()->user()->roles->pluck("id")->first() == 13)
<h5 class="my-2" style="font-weight: bolder;">*@lang('main.add zones to deliver')</h5>
<style>
    /*.copydiv .clone:first-child .btn-del-select{*/
    /*    display:none;*/
    /*}*/
</style>
    @if(\Route::currentRouteName() == 'resturants.create')
    <div class="col-sm-12">
        <div class="row-main create">
            <div class="row py-3 border-bottom position-relative">
                <div class="form-group col-sm-6">
                    <label for="area_id"> @lang('main.choose govern')</label>
                    <select class="form-select areas" name="area_id[]">
                        <option value="">اختياري</option>
                        @foreach(\App\Models\Area::whereNull('cairo_id')->whereNotNull('parent_id')->get() as $area)
                        <option value="{{$area->id}}">{{$area->title}}</option>
                        @endforeach
                    </select>
                </div>
                @php $key = 0; @endphp
                <div class="form-group col-sm-6">
                    <label for="expected_delivery" class="expected_delivery">{{('main.expected_delivery_km')}}</label><span class="text-danger">*</span>
                    <input required type="text" name="expected_delivery[]"
                        class="form-control @error('expected_delivery') is-invalid @enderror" id="expected_delivery" placeholder="">
                </div>
                <div class="form-group col-sm-12">
                    <input type="hidden" name="area_lat[]" class="area-lat">
                    <input type="hidden" name="area_lng[]" class="area-lng">
                    <div class="area-map-container" style="width:100%; height: 200px;"></div>
                </div>
            </div>
            <div class="clone-row"></div>
            <!-- Button to add new row -->
        </div>
    </div>
    @endif

    <!-- Template for cloning -->
    <template class="copydiv">
        <div class="row clone py-3 border-bottom position-relative">
            <div class="form-group col-sm-6">
                <label for="area_id"> @lang('main.choose govern')</label>
                <select class="form-select areas" name="area_id[]">
                    <option value="">اختياري</option>
                    @foreach(\App\Models\Area::whereNotNull('parent_id')->get() as $area)
                    <option value="{{$area->id}}">{{$area->title}}</option>
                    @endforeach
                </select>
            </div>
            @php $key = 0; @endphp
            <div class="form-group col-sm-6">
                <label for="expected_delivery" class="expected_delivery">
                    {{__('main.expected_delivery_km')}}
                </label><span class="text-danger">*</span>
                <input required type="text" name="expected_delivery[]"
                    class="form-control @error('expected_delivery') is-invalid @enderror" id="expected_delivery" placeholder="">
            </div>
            <div class="form-group col-sm-12">
                <input type="hidden" name="area_lat[]" class="area-lat">
                <input type="hidden" name="area_lng[]" class="area-lng">
                <div class="area-map-container" style="width:100%; height: 200px;"></div>
            </div>
            <span class="btn btn-danger pull-right btn-del-select py-2">
                <i class="fas fa-trash-alt"></i>
            </span>
        </div>
    </template>
    
    @if(\Route::currentRouteName() == 'resturants.edit')
    <div class="col-sm-12">
        <div class="row-main ">
            <div class="clone-row">
            @foreach($resturant->resturant_areas as $key => $val)
            <div class="row py-3 border-bottom position-relative">
                <div class="form-group col-sm-6">
                    <label for="area_id"> @lang('main.choose govern')</label>
                    <select class="form-select areas"  name="area_id[]">
                        <option value="">اختياري</option>
                        @foreach(\App\Models\Area::whereNotNull('parent_id')->get() as $area)
                        <option value="{{$area->id}}"  @if($val->area_id == $area->id) selected @endif>{{$area->title}}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-sm-6">
                    <label for="expected_delivery" class="expected_delivery">{{__('main.expected_delivery_km')}} </label><span class="text-danger">*</span>
                    <input required type="text"  name="expected_delivery[]" value="{{($val->expected_delivery) }}"
                    class="form-control @error('expected_delivery') is-invalid @enderror" id="expected_delivery" placeholder="">
                </div>
                <div class="form-group col-sm-12">
                    <input type="hidden" name="area_lat[]" class="area-lat" value="{{$val->lat}}">
                    <input type="hidden" name="area_lng[]" class="area-lng" value="{{$val->lng}}">
                    <div class="area-map-container" style="width:100%; height: 200px;"></div>
                </div>
                <span class="btn btn-danger pull-right btn-del-select py-2">
                    <i class="fas fa-trash-alt"></i>
                </span>
            </div>
            @endforeach
            </div>
        </div>
    </div>
    @endif
    <span class="addTopic add-select btn btn-info mb-5 mt-3 col-md-6">
        <i class="fas fa-plus px-2"></i>
        <span>اضف منطقة اخرى</span>
    </span>
@endif
</div>
<div class="form-group col-sm-10">
    <button type="submit" class="btn btn-success">@lang('main.save')</button>
</div>

<style>
    .btn-del-select {
        line-height: normal;
        position: absolute;
        inset-inline-end: 0;
        top: 15px;
        /* transform: translate(-50%, -50%); */
        width: 25px;
        height: 25px;
        font-size: small;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
</style>

@push('custom-js')
<script>
    var map, marker, geocoder, infowindow;
    window.gm_authFailure = function () {
        var container = document.getElementById('mapid');
        if (!container || document.getElementById('dashboard-map-error')) return;
        var notice = document.createElement('p'); notice.id = 'dashboard-map-error'; notice.className = 'alert alert-warning'; notice.setAttribute('role', 'alert');
        notice.textContent = @json(__('dashboard_maps.unavailable'));
        container.before(notice);
    };

    // Initialize the map with a default location
    function initialize() {
        if (!document.getElementById('mapid')) return;
        var defaultLat = $("#lat").val() || 30.0444; // Default Latitude
        var defaultLng = $('#lng').val() || 31.2357; // Default Longitude

        var e = new google.maps.LatLng(defaultLat, defaultLng); // Default position
        var t = {
            zoom: 5,
            center: e,
            panControl: true,
            scrollwheel: true,
            scaleControl: true,
            overviewMapControl: true,
            overviewMapControlOptions: { opened: true },
            mapTypeId: google.maps.MapTypeId.terrain
        };

        // Create the map
        map = new google.maps.Map(document.getElementById("mapid"), t);
        geocoder = new google.maps.Geocoder();
        marker = new google.maps.Marker({
            position: e,
            map: map
        });

        map.streetViewControl = false;
        infowindow = new google.maps.InfoWindow({ content: "(24.701925,46.675415)" });

        google.maps.event.addListener(map, "click", function (e) {
            marker.setPosition(e.latLng);
            var position = e.latLng;
            var coords = "(" + position.lat() + ", " + position.lng() + ")";
            infowindow.setContent(coords);
            infowindow.open(map, marker);
            document.getElementById("lat").value = position.lat();
            document.getElementById("lng").value = position.lng();
            document.getElementById("latInput").value = position.lat();
            document.getElementById("lngInput").value = position.lng();
        });
        setupAreaMapsOnPageLoad();
    }

    // Function to search the map based on Latitude and Longitude entered
    function searchLocation(event) {
        // Prevent the default form submit behavior (if any)
        event.preventDefault();

        var lat = parseFloat(document.getElementById('latInput').value);
        var lng = parseFloat(document.getElementById('lngInput').value);

        if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            alert(@json(__('dashboard_maps.invalid_coordinates')));
            return;
        }

        document.getElementById('lat').value = lat;
        document.getElementById('lng').value = lng;
        if (!map || !marker || !window.google || !google.maps) return;
        var position = new google.maps.LatLng(lat, lng);
        marker.setPosition(position);
        map.setCenter(position);
        map.setZoom(14); // Zoom level to focus on the searched location

        // Display the coordinates in the infowindow
        var coords = "(" + lat + ", " + lng + ")";
        infowindow.setContent(coords);
        infowindow.open(map, marker);

        // Optionally, update hidden lat/lng inputs if you are using them
        document.getElementById('lat').value = lat;
        document.getElementById('lng').value = lng;
    }

    // Add event listener to the Search button
    document.getElementById("searchBtn").addEventListener("click", searchLocation);

    var areaMaps = [];
    function initAreaMapForRow(rowElem) {
        if (!window.google || !google.maps) return;
        var container = rowElem.querySelector('.area-map-container');
        if (!container || container.dataset.initialized) return;
        container.dataset.initialized = '1';
        var baseLat = parseFloat(document.getElementById('lat').value) || 30.0444;
        var baseLng = parseFloat(document.getElementById('lng').value) || 31.2357;
        var position = new google.maps.LatLng(baseLat, baseLng);
        var m = new google.maps.Map(container, { zoom: 12, center: position, mapTypeId: google.maps.MapTypeId.terrain, streetViewControl: false });
        var mk = new google.maps.Marker({ position: position, map: m });
        var latInput = rowElem.querySelector('input.area-lat');
        var lngInput = rowElem.querySelector('input.area-lng');
        var distInput = rowElem.querySelector('input[name="expected_delivery[]"]');
        if (latInput && lngInput && latInput.value && lngInput.value) {
            var pos = new google.maps.LatLng(parseFloat(latInput.value), parseFloat(lngInput.value));
            mk.setPosition(pos);
            m.setCenter(pos);
        } else {
            if (latInput && lngInput) { latInput.value = baseLat; lngInput.value = baseLng; }
        }
        var initialRadius = 0;
        if (distInput) {
            var r = parseFloat(distInput.value);
            if (!isNaN(r) && r > 0) initialRadius = r * 1000; // km -> m
        }
        var circ = new google.maps.Circle({
            map: m,
            center: mk.getPosition(),
            radius: initialRadius,
            fillColor: '#4285F4',
            fillOpacity: 0.15,
            strokeColor: '#4285F4',
            strokeOpacity: 0.6,
            strokeWeight: 1
        });
        google.maps.event.addListener(m, 'click', function(ev) {
            mk.setPosition(ev.latLng);
            if (latInput) latInput.value = ev.latLng.lat();
            if (lngInput) lngInput.value = ev.latLng.lng();
            circ.setCenter(ev.latLng);
        });
        if (distInput) {
            distInput.addEventListener('input', function() {
                var val = parseFloat(this.value);
                circ.setRadius(!isNaN(val) && val > 0 ? val * 1000 : 0);
            });
        }
        areaMaps.push({ map: m, marker: mk, circle: circ, row: rowElem });
    }

    function setupAreaMapsOnPageLoad() {
        document.querySelectorAll('.area-map-container').forEach(function(container) {
            var row = container.closest('.row');
            if (row) { initAreaMapForRow(row); }
        });
    }

    document.addEventListener('click', function(e) {
        if (e.target.closest('.add-select')) {
            setTimeout(setupAreaMapsOnPageLoad, 0);
        }
    });

</script>

<!-- Load the Google Maps API -->
@if(config('services.maps.browser_key'))
<script src="https://maps.googleapis.com/maps/api/js?key={{config('services.maps.browser_key')}}&language=ar&callback=initialize" onerror="gm_authFailure()" async defer></script>
@else
<script>gm_authFailure();</script>
@endif


@endpush