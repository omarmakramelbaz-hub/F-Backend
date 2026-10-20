@extends('admin.index')
@push('custom-css')
    <style type="text/css">
  .span-txt{
    position: absolute;
    top: 39px;
    left: 50px;
    color: red;
    font-weight: bolder;
  }
  form .nav-item{
    border:1px solid #6c757d !important;
    border-radius:30px;
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
          <h1 class="m-0 text-dark">@lang('main.settings')</h1>
        </div><!-- /.col -->
        <div class="col-sm-6">
        </div><!-- /.col -->
      </div><!-- /.row -->
    </div><!-- /.container-fluid -->
  </div>
  <!-- /.content-header -->

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <div class="row">
        <div class="col-lg-12 col-md-12 card">
          <div class="card-body">
           @if(count($errors))
           <div class="alert alert-danger">
            <ul>
              @foreach($errors->all() as $error)
              <li>{{ $error }}</li>
              @endforeach
            </ul>
          </div>
          @endif
          <form method="post" action="{{route('updateAdvertising')}}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
              <li class="nav-item ml-2 mb-2">
                <a class="nav-link active" id="pills-home-tab" data-bs-toggle="pill" data-bs-target="#pills-home" type="button" role="tab" aria-controls="pills-home" aria-selected="true">@lang('main.advertising')</a>
              </li>
              
          </ul>
            <div class="tab-content" id="pills-tabContent">
              <div class="tab-pane fade active show" id="pills-home" role="tabpanel" aria-labelledby="pills-home-tab">
                <div class="row">
                <div class="form-group col-sm-10">
                  <label for="site_name">@lang('main.site name')</label>
                  <select class="form-select" name="advertise_resturant_id">
                      <option>@lang('main.choose') @lang('main.resturant')</option>
                      @foreach(\App\Models\Resturant::get() as $resturant)
                         <option value="{{$resturant->id}}" {{$resturant->id==$settings->advertise_resturant_id?'selected':''}}>{{$resturant->name}}</option>
                      @endforeach
                  </select>
                </div>
                
               
                <div class="form-group col-sm-10">
                  <label for="advertise_image">@lang('main.advertise_image')</label>
                  <input type="file" name="advertise_image"  class="form-control" id="advertise_image" >
                  @if(config('desktop_dashboard.local'))<small class="text-muted">PNG، JPG، WEBP فقط؛ مجموع الصور حتى 5 ميجابايت، وأبعاد كل صورة حتى 4096 × 4096.</small>@endif
                  <img src="{{url('/storage/'.$settings->advertise_image)}}" width="120px">
                </div>
                
              </div>
              </div>
          
           </div>
          <button type="submit" class="btn btn-success">@lang('main.save')</button>
         </form>
       </div>
     </div>
   </div>
 </div>
</section>
</div>               
@endsection