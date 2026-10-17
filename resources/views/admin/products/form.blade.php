<input type="number" name="added_by" value="{{ Auth::guard('admin')->user()->id }}" class="form-control" hidden>
<div class="row gy-3">

<div class="form-group col-sm-6">
    <label for="category_id"> @lang('main.category')</label><span class="text-danger">*</span>
    <select name="category_id" class="form-select category">
        <option value="">@lang('main.choose')</option>
        @foreach(\App\Models\Category::whereNull('parent_id')->get() as $value)
            <option value="{{$value->id}}" @if($value->id == old('category_id', $product->category_id)) selected @endif >{{$value->name}}</option>
        @endforeach
    </select>
</div>

@if(request()->route()->getName() == 'products.edit')
    <input type="hidden" class="product_id" name="product_id" value="{{$product->id}}">
@endif

<div class="form-group col-sm-6" id="div">
</div>
<div class="form-group col-sm-6">
    <label for="name_ar"> @lang('main.product_name')</label><span class="text-danger">*</span>
    <input type="text" name="name_ar" value="{{ old('name_ar', $product->name_ar) }}"
        class="form-control @error('name_ar') is-invalid @enderror" id="name_ar" placeholder="@lang('main.enter') @lang('main.product_name')">
</div>

<div class="form-group col-sm-6">
    <label for="product_features"> @lang('main.product_features')</label>
    <select name="product_features[]" class="form-select d-block select-component w-100" multiple>
        <option value="kilo" {{ (collect(old('product_features'))->contains('kilo')) ? 'selected':'' }}  @if(($product->product_features)->isNotEmpty()) {{ in_array('kilo',$product->product_features()->pluck('name')->toArray()) ?'selected':''}} @endif >@lang('main.kilo')</option>
        <option value="half" {{ (collect(old('product_features'))->contains('half')) ? 'selected':'' }}  @if(($product->product_features)->isNotEmpty()) {{ in_array('half',$product->product_features()->pluck('name')->toArray()) ?'selected':''}} @endif >@lang('main.half')</option>
        <option value="quarter" {{ (collect(old('product_features'))->contains('quarter')) ? 'selected':'' }}  @if(($product->product_features)->isNotEmpty()) {{ in_array('quarter',$product->product_features()->pluck('name')->toArray()) ?'selected':''}} @endif >@lang('main.quarter')</option>
        <option value="combo" {{ (collect(old('product_features'))->contains('combo')) ? 'selected':'' }}  @if(($product->product_features)->isNotEmpty()) {{ in_array('combo',$product->product_features()->pluck('name')->toArray()) ?'selected':''}} @endif >@lang('main.combo')</option>
        <option value="small" {{ (collect(old('product_features'))->contains('small')) ? 'selected':'' }}  @if(($product->product_features)->isNotEmpty()) {{ in_array('small',$product->product_features()->pluck('name')->toArray()) ?'selected':''}} @endif >@lang('main.small')</option>
        <option value="medium" {{ (collect(old('product_features'))->contains('medium')) ? 'selected':'' }}  @if(($product->product_features)->isNotEmpty()) {{ in_array('medium',$product->product_features()->pluck('name')->toArray()) ?'selected':''}} @endif >@lang('main.medium')</option>
        <option value="large" {{ (collect(old('product_features'))->contains('large')) ? 'selected':'' }}  @if(($product->product_features)->isNotEmpty()) {{ in_array('large',$product->product_features()->pluck('name')->toArray()) ?'selected':''}} @endif >@lang('main.large')</option>


    </select>
</div>
<div class="form-group col-sm-6">
        <label for="has_clean"> @lang('main.has_clean')</label><span class="text-danger">*</span>
        <select name="has_clean" class="form-select">
            <option value="0" @if($product->has_clean == 0) selected @endif>@lang('main.no')</option>
            <option value="1" @if($product->has_clean == 1) selected @endif>@lang('main.yes')</option>
        </select>
    </div>

<div class="form-group col-sm-6">
        <label for="status"> @lang('main.status')</label><span class="text-danger">*</span>
        <select name="status" class="form-select">
            <option value="show" @if($product->status == 'show') selected @endif>@lang('main.show')</option>
            <option value="hide" @if($product->status == 'hide') selected @endif>@lang('main.hide')</option>
        </select>
    </div>
</div>

<div class="form-group col-sm-10">
    <button type="submit" class="btn btn-success">@lang('main.save')</button>
</div>

@push('custom-js')
<script type="text/javascript">
    function callAjax(){
        var priceParent = $('#div');
        $.ajax({
            url: "{{url('admin/fetch-subcategory')}}",
            type: "POST",
            async: true,
            data: {
                category_id: idproduct, 
                product_id: product,
                _token: '{{csrf_token()}}'
            },
            dataType: 'json',
            success: function (data) {
                $(priceParent).html('');
                $(priceParent).html(data.options);
            }
        });
    }

    if ($('select[name="category_id"]').val() != "0") {
        var idproduct = $('select[name="category_id"]').val();
        var product= $('.product_id').val();
        callAjax();
    }

    $(document).on('change', 'select[name="category_id"]' , function () {
        var idproduct = this.value;
        var priceParent = $('#div');
        var product= $('.product_id').val();
        $.ajax({
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
            }
        });
    });   
</script>
@endpush
