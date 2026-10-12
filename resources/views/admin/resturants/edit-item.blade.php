<!-- Edit Modal -->
<div class="modal fade editModal" id="editItem"  role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
<div class="modal-dialog">
  <div class="modal-content">
      <div class="modal-header" style="direction: ltr;">
          <button type="button" style="    margin: -1rem 16rem -2rem auto;" class="close" data-bs-dismiss="modal" aria-hidden="true">&times;</button>
          <h4 class="modal-title">@lang('main.edit item') </h4>
      </div>
		<div class="modal-body">
		 <div class="row">
      <form class="row gy-2 w-100" method="post" action="{{route('resturant_products.update') }}" enctype="multipart/form-data">
      @csrf
      @method('put')
		<input name="id" type="hidden" class="form-control" id="id" >
		<input name="added_by"value="{{auth('admin')->user()->id}}" type="hidden" class="form-control" id="addedby" >
			<input type="hidden" name="resturant_id" class="form-control" id="resturant_id" value="{{$resturant->id}}">
		<div class=" col-sm-6">
            <label for="category_id"> @lang('main.category')</label><span class="text-danger">*</span>
            <select name="category_id" onChange="getproducts(this.value);" class="form-select category" id="categoryid">
                <option value="">@lang('main.choose')</option>
                @foreach(\App\Models\Category::whereNull('parent_id')->get() as $value)
                    <option value="{{$value->id}}" @if($value->id == old('category_id')) selected @endif >{{$value->name}}</option>
                @endforeach
            </select>
        </div>
		<div class=" col-sm-6" id="div22">
        </div>
        
        <div class=" col-sm-6" id="div221">
        </div>

			<input type="hidden" name="product_id" class="form-control productt" id="product_id" >

	<input type="hidden" name="subcatid" class="form-control" id="subcatid">

		<div class="col-md-6 form-group">
		    <label>@lang('main.product_name'):</label>
			<input type="text" name="product_name" class="form-control" id="productname" >
		</div>
		
		<div class="col-md-12 form-group">
		    <label>@lang('main.product_price'):</label>
			<input type="number" name="product_price" min="0" class="form-control" id="productprice" >
		</div>
	    <div class=" col-sm-12" id="div222">
        </div>
		<div class="col-md-6 form-group">
		    <label>@lang('main.product_description'):</label>
			<input type="text" name="product_description" class="form-control" id="productdescription" >
		</div>
		
		<div class="col-sm-6">
        <label for="product_image">@lang('main.product_image')</label>

        <div class="input-group mb-2">
            <input type="file" name="product_image" class="custom-file-input"
                onchange="document.getElementById('image').src = window.URL.createObjectURL(this.files[0])">
            <label class="custom-file-label" for="image">{{ trans('main.UploadProfileImage') }}</label>
        </div>
        <div class="col-sm-6">
                <img id="_image" src="{{ url('dashboard/dist/img/no-photo.png') }}"
                    style="height: 80px; width: 100px;">
        </div>
    </div>




    <div class="modal-footer">
        <button data-bs-dismiss="modal" class="btn btn-default ml-2 close-btn" type="button">@lang('main.close')</button>
           <button class="btn btn-success" type="submit">@lang('main.Save changes')</button>
    </div>

</form>
  </div>
</div>
</div>
</div>
</div>


@push('custom-js')
<script type="text/javascript">
 var currentAjaxRequest = null;

    // Abort any ongoing AJAX request
    if (currentAjaxRequest) {
      currentAjaxRequest.abort();
    }
    
  // function callAjax(){
    //     var priceParent = $('#div22');
    //     currentAjaxRequest  = $.ajax({
    //         url: "{{url('admin/fetch-subcategory')}}",
    //         type: "POST",
    //         async: true,
    //         data: {
    //             category_id: idproduct, 
    //             product_id: product,
    //             _token: '{{csrf_token()}}'
    //         },
    //         dataType: 'json',
    //         success: function (data) {
    //             $(priceParent).html('');
    //             $(priceParent).html(data.options);
    //         }
    //     });
    // }
// console.log('ddddfgf'+$('#categoryid').find(":selected").val())
//     if ($('#categoryid').val() != "0") {
//         var idproduct = $('#categoryid').val();
//         var product= $('.product_id').val();
//         callAjax();
//     }

    $(document).on('change', 'select[name="category_id"]' , function () {
        var idproduct = this.value;
        var priceParent = $('#div22');
        var product= $('.product_id').val();
        currentAjaxRequest  = $.ajax({
            url: "{{url('admin/fetch-subcategory')}}",
            type: "POST",
            async: true,
            data: {
                product_id: product,
                category_id: idproduct,
                _token: '{{csrf_token()}}'
            },
            dataType: 'json',
            success: function (data) {
                $(priceParent).html('');
                $(priceParent).html(data.options);
            },
      complete: function() {
        // Cleanup after request completes
        currentAjaxRequest = null;
      }
        });
    });   
    
    
    
    
 {{--   
    // function callAjax1(){
    //     var priceParent1 = $('#div221');
    //     currentAjaxRequest  = $.ajax({
    //         url: "{{url('admin/fetch-product')}}",
    //         type: "POST",
    //         async: true,
    //         data: {
    //             category_id: idsubproduct, 
    //             product_id: product,
    //             _token: '{{csrf_token()}}'
    //         },
    //         dataType: 'json',
    //         success: function (data) {
    //             $(priceParent1).html('');
    //             $(priceParent1).html(data.options);
    //         }
    //     });
    // }

    // if ($('select[name="subcategory_id"]').val() != "0") {
    //     var idproduct = $('select[name="subcategory_id"]').val();
    //     var product= $('.product_id').val();
    //     callAjax1();
    // }
    --}}

    function getproducts(val){
        var idsubproduct = val;

        var priceParent11 = $('#div221');
        var priceParent12 = $('#div1');
        var product= $('.product_id').val();
        currentAjaxRequest  = $.ajax({
            url: "{{url('admin/fetch-product')}}",
            type: "POST",
            async: true,
            data: {
                product_id: product,
                subcategory_id: idsubproduct,
                _token: '{{csrf_token()}}'
            },
            dataType: 'json',
            success: function (data) {
                $(priceParent11).html('');
                $(priceParent11).html(data.options);

                $(priceParent12).html('');
                $(priceParent12).html(data.options);
            },
      complete: function() {
        // Cleanup after request completes
        currentAjaxRequest = null;
      }
        });
    }
    
    function getfeatures(val,id){
        var idproduct = val;
        var idrest = $('#resturant_id').val();
        var idpro = $('.productt').val();
        // var id= $('#id').val();
        var priceParent = $('#div2');
// console.log("id"+id+"id_rest"+idrest+"idproduct"+idproduct);
        var priceParent2 = $('#div222');
        currentAjaxRequest  = $.ajax({
            url: "{{url('admin/fetch-feature')}}",
            type: "POST",
            async: true,
            data: {
                product_id: val,
                resturant_id:idrest,
                id: id,
                _token: '{{csrf_token()}}'
            },
            dataType: 'json',
            success: function (data) {
                // console.log('result'+data)
                $(priceParent).html('');
                $(priceParent).html(data.options);

                $(priceParent2).html('');
                $(priceParent2).html(data.options);
            },error:function (er){
                console.log(er);
            },
      complete: function() {
        // Cleanup after request completes
        currentAjaxRequest = null;
      }
        });
    }
</script>
@endpush