@extends('admin.index')
@push('custom-css')
<style>
    .select2-container {
        width:100% !important;
    }
</style>
@endpush
@section('content')
    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 text-dark">@lang('main.show') @lang('main.resturants') </h1>
                    </div><!-- /.col -->
                    <div class="col-sm-6">
                         @can('resturant-list')
          <li class="breadcrumb-item">
            <ol class="breadcrumb float-sm-left"><a href="{{ url('admin/resturants?resturant_id='.request('resturant_id'))}}" class="btn btn-primary">@lang('main.showAll') 
             @if( request('resturant_id'))
             @lang('main.branches')
             @else
            @lang('main.resturants')  @endif</a>                         </ol>
    </li>
    @endcan
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
                        <div class="card show-data">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label> @lang('main.addedBy')</label>
                                            <span>{{$resturant->admin?->name}}</span>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label> @lang('main.resturant_name')</label>
                                            <span>{{$resturant->name}}</span>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label> @lang('main.resturant_owner')</label>
                                            <span>{{$resturant->user?->name}}</span>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label> @lang('main.address') </label>
                                            <span>{{$resturant->country_name}} /{{$resturant->city_name}} /{{$resturant->address}} </span>
                                        </div>
                                    </div>    
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label> @lang('main.avg_rate') </label>
                                            <span>{{$resturant->avg_rate}} <i class="fa fa-star"></i></span>
                                        </div>
                                    </div>    
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label> @lang('main.delivery_time') </label>
                                            <span>{{$resturant->delivery_time}} </span>
                                        </div>
                                    </div>    
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label> @lang('main.resturant_status') </label>
                                            <span>{{__('main.'.$resturant->status)}} </span>
                                        </div>
                                    </div> 
                                    <div class="col-sm-6">
                                        <div class="form-group">
                                            <label> @lang('main.created_at')</label>
                                            <span>{{$resturant->created_at?->diffForHumans() ?? '—'}}</span>
                                        </div>
                                    </div>
                                    
                                    <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.lat'), @lang('main.lng')</label>
                                        <span>{{ $resturant->lat }}, {{ $resturant->lng }}</span>
                                    </div>
                                </div>

                                <div class="col-sm-6">
                                    <div class='form-group'>
                                        <label> @lang('main.area')</label>
                                        <span>{{ $resturant->area?->title }}</span>
                                    </div>
                                </div>
                                
                                </div>
                                
                                <hr>
                                
                                <ul class="nav nav-pills my-3 pb-3 border-bottom user-pills" id="pills-tab" role="tablist">
                                    <li class="nav-item active">
                                        <a class="nav-link active" id="pills-resturant_products-tab" data-bs-toggle="pill" data-bs-target="#pills-resturant_products" type="button" role="tab" aria-controls="pills-resturant_products" aria-selected="true"><i class="fa fa-paperclip"></i> @lang('main.show all resturant products')</a>
                                    </li>
                                    @if(auth('admin')->user()->roles->pluck("id")->first() == 11)
                                    <li class="nav-item">
                                        <a class="nav-link" id="pills-resturant_reviews-tab" data-bs-toggle="pill" data-bs-target="#pills-resturant_reviews" type="button" role="tab" aria-controls="pills-resturant_reviews" aria-selected="true"><i class="fa fa-star"></i> @lang('main.show all resturant reviews')</a>
                                    </li>
                                    @endif
                                    <li class="nav-item">
                                        <a class="nav-link" id="pills-resturant_favorites-tab" data-bs-toggle="pill" data-bs-target="#pills-resturant_favorites" type="button" role="tab" aria-controls="pills-resturant_favorites" aria-selected="true"><i class="fa fa-heart"></i> @lang('main.show all resturant favorites')</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="{{route('orders.index',['resturant_id'=> auth('admin')->user()->base_resturant?->id])}}"><i class="fas fa-hand-holding-usd"></i> @lang('main.show all resturant orders')</a>
                                    </li>
                                </ul>
    
                                <div class="tab-content" id="pills-tabContent">
                                    <div class="tab-pane fade active show" id="pills-resturant_products" role="tabpanel" aria-labelledby="pills-resturant_products-tab">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <h5 class="fw-bold"> @lang('main.product items') ({{$resturant->resturant_products->count()}})</h5>
                                        @include('admin.resturants.add-modal')
                                    </div>
                                    <div class="">
                                    <ul class="nav nav-pills my-3 pb-3 border-bottom gates-pills" id="gatespills-tab" role="tablist">
                                        @foreach(\App\Models\ResturantProduct::where('resturant_id',$resturant->id)->groupBy('category_id')->orderBy('category_id','ASC')->get() as $key => $value)
                                        <li class="nav-item active">
                                            <a class="nav-link @if($key == 0) active @endif" id="pills-gates{{$key}}-tab" data-bs-toggle="pill" data-bs-target="#pills-gates{{$key}}" type="button" role="tab" aria-controls="pills-gates{{$key}}" aria-selected="true"><i class="fa fa-paperclip"></i> {{$value->category?->name}}</a>
                                        </li>
                                        @endforeach
                                    </ul>    
                                        @foreach(\App\Models\ResturantProduct::where('resturant_id',$resturant->id)->groupBy('category_id')->orderBy('category_id','ASC')->get() as $key => $val)
                                        <div class="tab-content" id="gatespills-tabContent">
                                            <div class="tab-pane fade @if($key == 0) active show @endif" id="pills-gates{{$key}}" role="tabpanel" aria-labelledby="pills-gates{{$key}}-tab">
                                              <div class="row">
                                                  <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <th>#</th>
                                            <th>@lang('main.product_feature')</th>
                                            <th>@lang('main.product_name')</th>
                                            <th>@lang('main.product_description')</th>
                                            <th>@lang('main.product_price')</th>
                                             <th>@lang('main.status')</th>
                                             <th>@lang('main.highest_rated')</th>
                                            <th>@lang('main.created_at')</th>
                                            <th>@lang('main.actions')</th>
                                        </thead>
                                        <tbody>
                                            @forelse($resturant->resturant_products->where('category_id', $val->category_id) as $item)
                                                <tr>
                                                    <td>{{ $loop->iteration }}</td>
                                                    <td>
                                                        {{$item->product?->name}}
                                                    </td>
                                                    <td>
                                                        {{ $item->product_name }}
                                                    </td>
                                                    <td>
                                                        {{ $item->product_description }}
                                                    </td>
                                                    <td>
                                                        {{ $item->product_price }} @lang('main.egp')
                                                    </td>
                                                     <td>
                                                        @if($item->status == 'show')
                                                        <a href="{{route('resturant_products.update.status',[$item->id,'status' => 'hide'])}}" class="btn btn-danger">@lang('main.for hide click here')</a>
                                                        @elseif($item->status == 'hide')
                                                        <a href="{{route('resturant_products.update.status',[$item->id,'status' => 'show'])}}" class="btn btn-primary">@lang('main.for show click here')</a>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if($item->highest_rated == 'yes')
                                                        <a href="{{route('resturant_products.update.highest_rated',[$item->id,'highest_rated' => 'no'])}}" class="btn btn-danger">@lang('main.remove from highest rated')</a>
                                                        @elseif($item->highest_rated == 'no')
                                                        <a href="{{route('resturant_products.update.highest_rated',[$item->id,'highest_rated' => 'yes'])}}" class="btn btn-primary">@lang('main.add to highest rated')</a>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        {{$item->created_at?->diffForHumans() ?? '—'}}
                                                    </td>
                                                    <td width="250px">
                                                            <button class="edit-item btn btn-warning p-1 clickeditbtn" type="submit" 
                                                            data-info="{{$item->id}},{{$item->resturant_id}},{{$item->product_id}},{{$item->product_name}},{{$item->product_description}},{{$item->product_price}},{{$item->category_id}},{{json_decode($item->price)->extra_combo}},{{json_decode($item->price)->extra_large}},{{json_decode($item->price)->extra_medium}},{{json_decode($item->price)->extra_clean}},{{json_decode($item->price)->extra_clear}},{{$item->product?->subcategory_id}},{{$item->getFirstMediaUrl('product_image','thumb')}},{{json_decode($item->price)->extra_vacuim}}"><i class='fa fa-edit'></i></button>
                                                            {!! \App\Support\NativeDeleteForm::open([
                                                                'method' => 'DELETE',
                                                                'route' => ['resturant_products.destroy', $item->id],
                                                                'style' => 'display:inline',
                                                            ]) !!}
                                                            <button type="submit"
                                                                class="btn btn-danger show_confirm p-1"><i class="fa fa-trash"></i></button>
                                                            {!! \App\Support\NativeDeleteForm::close() !!}
                                                    </td>
                                                </tr>
                                            @empty
                                                <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                    {{ trans('main.Nousercars') }}
                                                </td>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                              </div>
                                            </div>
                                        </div>
                                        @endforeach
                                        </div>
                                    </div>
                               
                                    <div class="tab-pane fade" id="pills-resturant_reviews" role="tabpanel" aria-labelledby="pills-resturant_reviews-tab">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <h5 class="fw-bold"> @lang('main.resturant reviews') ({{$resturant->reviews->count()}})</h5>
                                    </div>
                                    <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead>
                                            <th>#</th>
                                            <th>@lang('main.username')</th>
                                            <th>@lang('main.rate')</th>
                                            <th>@lang('main.created_at')</th>
                                            <th>@lang('main.delete')</th>
                                        </thead>
                                        <tbody>
                                            @forelse($resturant->reviews as $review)
                                                <tr>
                                                    <td>{{ $loop->iteration }}</td>
                                                    <td>
                                                        <a href="{{route('users.show',[$review->user_id,'account_type'=>'user'])}}">{{$review->user?->name}}</a>
                                                    </td>
                                                    <td>
                                                        {{$review->rate}} <i style="color:#ffda21;" class="fa fa-star"></i>  {{ __('main.review_'.$review->rate) }}
                                                    </td>
                                                    <td>
                                                        {{$review->created_at?->diffForHumans() ?? '—'}}
                                                    </td>
                                                    <td>
                                                        {!! \App\Support\NativeDeleteForm::open([
                                                            'method' => 'DELETE',
                                                            'route' => ['resturant_reviews.destroy', $review->id],
                                                            'style' => 'display:inline',
                                                        ]) !!}
                                                        <button type="submit"
                                                            class="btn btn-danger show_confirm"><i class="fa fa-trash"></i></button>
                                                        {!! \App\Support\NativeDeleteForm::close() !!}
                                                    </td>
                                                </tr>
                                            @empty
                                                <td class="text-center text-muted" style="font-size: 25px" colspan="7">
                                                    {{ trans('main.Noreviews') }}
                                                </td>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                    </div>
                                
                                    <div class="tab-pane fade" id="pills-resturant_favorites" role="tabpanel" aria-labelledby="pills-resturant_favorites-tab">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <h5 class="fw-bold"> @lang('main.resturant favorites') ({{$resturant->wishlists->count()}})</h5>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
    
        @include('admin.resturants.edit-item')

@endsection
@push('custom-js')

<script type="text/javascript">
$(document).ready(function(){
    $(document).on('click', '.edit-item', function() {
        var stuff = $(this).data('info').split(',');
        fillmodalData(stuff)
        $('#editItem').modal('show');
        var _product =stuff[2];
        var idproduct=stuff[6];
        console.log(_product)
        function callAjax(){
        var priceParent = $('#div22');
        $.ajax({
            url: "{{url('admin/fetch-subcategory')}}",
            type: "POST",
            async: true,
            data: {
                category_id: idproduct, 
                product_id: _product,
                _token: '{{csrf_token()}}'
            },
            dataType: 'json',
            success: function (data) {
                console.log(data)
                $(priceParent).html('');
                $(priceParent).html(data.options);
            },error:function(er){
                console.log(er);
            }
            
            
        });
    }
    if ($('#categoryid').find(":selected").val() != "0") {
        var idproduct = $('#categoryid').find(":selected").val();
        // alert(idproduct)
        var _product =stuff[2];
        callAjax();
    }


    if ($('#subcategoryid').find(":selected").val() != "0") {
        var idsubproduct2 = $('#subcatid').val();
        var _product2 =stuff[2];
        // alert('seeee'+idsubproduct2)
        callAjax1();
    }

    function callAjax1(){
        var priceParent1 = $('#div221');
        var _product2 =stuff[2];
        var idproduct2=stuff[12];
        console.log('ss'+_product2)
        $.ajax({
            url: "{{url('admin/fetch-product')}}",
            type: "POST",
            async: true,
            data: {
                subcategory_id: idsubproduct2, 
                product_id: _product2,
                _token: '{{csrf_token()}}'
            },
            dataType: 'json',
            success: function (data) {
                $(priceParent1).html('');
                $(priceParent1).html(data.options);
            }
            ,error:function(er){
                console.log(er);
            }
        });
        console.log('DEE'+stuff[10])
        getfeatures(_product2,stuff[0])
    
        $('#extra_combo').val(stuff[7]);
        $('#extra_large').val(stuff[8]);
        $('#extra_medium').val(stuff[9]);
        $('#extra_clean').val(stuff[10]);
        $('#extra_clear').val(stuff[11]);
        $('#extra_vacuim').val(stuff[14]);
    }

    

    });
    function fillmodalData(details)
    {
        $('#id').val(details[0]);
        $('#resturant_id').val(details[1]);
        $('#product_id').val(details[2]);
        $('#productid').val(details[2]);
        $("#productid option[value="+details[2]+"]").attr("selected","selected");

        $('#productname').val(details[3]);
        $('#productdescription').val(details[4]);
        $('#productprice').val(details[5]);
        $('#categoryid').val(details[6]);
        $("#categoryid option[value="+details[6]+"]").attr("selected","selected");

        $('#extra_combo').val(details[7]);
        $('#extra_large').val(details[8]);
        $('#extra_medium').val(details[9]);
        $('#extra_clean').val(details[10]);
        $('#extra_clear').val(details[11]);
        $('#subcategoryid').val(details[12]);
        $('#subcatid').val(details[12]);
        // alert(details[13])
        $('#_image').attr('src',details[13]);

// $("#subcategoryid option").each(function(){
// alert('d')
//   if ($(this).val() == details[12])
//     $(this).attr("selected","selected");
// });
    }
});


</script>
@endpush