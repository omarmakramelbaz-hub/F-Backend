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
    /*border:1px solid #6c757d !important;*/
    border-radius:30px;
  }
  label{
    display: flex;
    align-text: center;
    justify-content: space-between;
  }
  label a{
    color: #0056b3;
    text-decoration: underline !important;
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
          <form method="post" action="{{route('updateSetting')}}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <ul class="nav nav-pills border-bottom mb-3" id="pills-tab" role="tablist">
              <li class="nav-item ml-2 mb-2">
                <a class="nav-link active" id="pills-home-tab" data-bs-toggle="pill" data-bs-target="#pills-home" type="button" role="tab" aria-controls="pills-home" aria-selected="true">@lang('main.main setting')</a>
              </li>
              <li class="nav-item ml-2 mb-2">
                <a class="nav-link" id="pills-profile-tab" data-bs-toggle="pill" data-bs-target="#pills-profile" type="button" role="tab" aria-controls="pills-profile" aria-selected="false">@lang('main.social link')</a>
              </li>
              <li class="nav-item ml-2 mb-2">
                <a class="nav-link" id="pills-home-section-tab" data-bs-toggle="pill" data-bs-target="#pills-home-section" type="button" role="tab" aria-controls="pills-home-section" aria-selected="false">@lang('main.termcondition section')</a>
              </li>

              {{--<li class="nav-item ml-2 mb-2">
                <a class="nav-link" id="pills-meta-tab" data-bs-toggle="pill" data-bs-target="#pills-meta" type="button" role="tab" aria-controls="pills-meta" aria-selected="false">@lang('main.meta site')</a>
              </li>--}}
              <li class="nav-item ml-2 mb-2">
                <a class="nav-link" id="pills-privacy-tab" data-bs-toggle="pill" data-bs-target="#pills-privacy" type="button" role="tab" aria-controls="pills-privacy" aria-selected="false">@lang('main.privacy')</a>
              </li> 
              <li class="nav-item ml-2 mb-2">
                <a class="nav-link" id="pills-privacy-tab" data-bs-toggle="pill" data-bs-target="#pills-about" type="button" role="tab" aria-controls="pills-about" aria-selected="false">@lang('main.about')</a>
              </li> 
          </ul>
            <div class="tab-content" id="pills-tabContent">
              <div class="tab-pane fade active show" id="pills-home" role="tabpanel" aria-labelledby="pills-home-tab">
                <div class="row">
                <div class="form-group col-sm-6">
                  <label for="site_name">@lang('main.site name')</label>
                  <input type="text" name="site_name" value="{{$settings->site_name}}" class="form-control" id="site_name" placeholder="Enter Site name">
                </div>
                
                <div class="form-group col-sm-6">
                  <label for="email">@lang('main.email')</label>
                  <input type="email" name="email" value="{{$settings->email}}" class="form-control" id="email" placeholder="@lang('main.email')">
                </div>
                <div class="form-group col-sm-6">
                  <label for="phone">@lang('main.customer service')</label>
                  <input type="text" name="phone" value="{{$settings->phone}}" class="form-control" id="phone" placeholder="@lang('main.phone')">
                </div>
                {{--<div class="form-group col-sm-6">
                  <label for="another_phone">@lang('main.another_phone')</label>
                  <input type="text" name="another_phone" value="{{$settings->another_phone}}" class="form-control" id="another_phone" placeholder="@lang('main.another_phone')">
                </div>--}}
                <div class="form-group col-sm-6">
                  <label for="address">@lang('main.address')</label>
                  <input type="text" name="address" value="{{$settings->address}}" class="form-control" id="address" placeholder="@lang('main.address')">
                </div>
                <div class="form-group col-sm-6 d-none">
                  <label for="km_price">@lang('main.km_price')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.egp')</span>
                    <input type="number" name="km_price" value="{{$settings->km_price}}" class="form-control" id="km_price" placeholder="@lang('main.km_price')">
                  </div>
                </div>
                <div class="form-group col-sm-6 d-none">
                  <label for="app_banner_background_color">@lang('main.app_banner_background_color')</label>
                  <div class="input-group mb-3">
                    <input type="color" name="app_banner_background_color" value="{{$settings->app_banner_background_color}}" class="form-control" id="app_banner_background_color" placeholder="@lang('main.app_banner_background_color')">
                  </div>
                </div>
                 <!--<div class="form-group col-sm-6"></div>-->
                <div class="form-group col-sm-6 d-none">
                  <label for="min_order_price">@lang('main.min_order_price')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.egp')</span>
                    <input type="number" name="min_order_price" value="{{$settings->min_order_price}}" class="form-control" id="min_order_price" placeholder="@lang('main.min_order_price')">
                  </div>
                </div>
                
                <div class="form-group col-sm-6">
                  <label for="shipping_km_price">@lang('main.shipping_km_price')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.egp')</span>
                    <input type="number" name="shipping_km_price" value="{{$settings->shipping_km_price}}" class="form-control" id="shipping_km_price" placeholder="@lang('main.shipping_km_price')">
                  </div>
                </div>
                <div class="form-group col-sm-6 ">
                  <label for="shipping_min_price">@lang('main.shipping_min_price')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.percent')</span>
                    <input type="number" name="shipping_min_price" value="{{$settings->shipping_min_price}}" class="form-control" id="shipping_min_price" placeholder="@lang('main.shipping_min_price')">
                  </div>
                </div>
                <div class="form-group col-sm-6">
                  <label for="shipping_cancelled_block_no">@lang('main.shipping_cancelled_block_no')</label>
                  <div class="input-group mb-3">
                    <input type="number" name="shipping_cancelled_block_no" min="1" value="{{$settings->shipping_cancelled_block_no}}" class="form-control" id="shipping_cancelled_block_no" placeholder="@lang('main.shipping_cancelled_block_no')">
                  </div>
                </div>
                <div class="form-group col-sm-6"></div>

                <div class="form-group col-sm-6">
                  <label for="default_0_1">@lang('main.default_0_1')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.egp')</span>
                    <input type="number" name="default_0_1" value="{{$settings->default_0_1}}" min="0" class="form-control" id="default_0_1" placeholder="@lang('main.default_0_1')">
                  </div>
                </div>
                <div class="form-group col-sm-6">
                  <label for="default_1_2">@lang('main.default_1_2')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.egp')</span>
                    <input type="number" name="default_1_2" value="{{$settings->default_1_2}}" min="0" class="form-control" id="default_1_2" placeholder="@lang('main.default_1_2')">
                  </div>
                </div>
                <div class="form-group col-sm-6">
                  <label for="default_2_3">@lang('main.default_2_3')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.egp')</span>
                    <input type="number" name="default_2_3" value="{{$settings->default_2_3}}" min="0" class="form-control" id="default_2_3" placeholder="@lang('main.default_2_3')">
                  </div>
                </div>
                
                <!--<div class="form-group col-sm-6"></div>-->
                <div class="form-group col-sm-6">
                    <label for="tax">@lang('main.tax')</label>
                    <div class="input-group mb-3">
                      <span class="input-group-text" id="basic-addon1">@lang('main.percent')</span>
                      <input type="number" name="tax" value="{{$settings->tax}}" class="form-control" id="tax" placeholder="@lang('main.tax')%" aria-describedby="basic-addon1">
                    </div>
                </div>
                <div class="form-group col-sm-6">
                    <label for="service_fees">@lang('main.service_fees')</label>
                    <div class="input-group mb-3">
                      <span class="input-group-text" id="basic-addon1">@lang('main.percent')</span>
                      <input type="number" name="service_fees" value="{{$settings->service_fees}}" class="form-control" id="service_fees" placeholder="@lang('main.service_fees')%" aria-describedby="basic-addon1">
                    </div>
                </div>
                 <div class="form-group col-sm-6 d-none">
                  <label for="vendor_tax">@lang('main.vendor_tax')</label>
                  <div class="input-group mb-3">
                    <span class="input-group-text">@lang('main.percent')</span>
                    <input type="number" name="vendor_tax" value="{{$settings->vendor_tax}}" class="form-control" id="vendor_tax" placeholder="@lang('main.vendor_tax')%">
                  </div>
                </div>
                 <div class="form-group col-sm-6">
                  <label for="app_balance">@lang('main.app_balance') 
                    @if(!config('desktop_dashboard.local'))
                    <a href="{{route('adminWallet')}}" target="_blank"><i class="fa-solid fa-wallet"></i><span>تفاصيل المحفظة</span>
                    </a>
                    @endif
                  </label> 
                  <div class="input-group mb-3">
                    @if(config('desktop_dashboard.local'))
                    <p class="form-control-plaintext mb-0">رصيد التطبيق يُعرض من الداشبورد المتصلة فقط، ولا يتغير عند حفظ هذه الإعدادات.</p>
                    @else
                    <span class="input-group-text">@lang('main.egp')</span>
                    <input type="number" readonly name="app_balance" value="{{$settings->app_balance}}" class="form-control" id="app_balance" placeholder="@lang('main.app_balance')">
                    @endif
                  </div>
                </div>
                
                <div class="form-group col-sm-6"></div>
                
                <div class="form-group col-sm-6">
                  <label for="contact_text_ar">@lang('main.contact_text_ar')</label>
                  <input type="text" name="contact_text_ar" value="{{$settings->contact_text_ar}}" class="form-control" id="contact_text_ar" placeholder="@lang('main.contact_text_ar')">
                </div>
                
                <div class="form-group col-sm-6">
                  <label for="contact_text_en">@lang('main.contact_text_en')</label>
                  <input type="text" name="contact_text_en" value="{{$settings->contact_text_en}}" class="form-control" id="contact_text_en" placeholder="@lang('main.contact_text_en')">
                </div>
                {{--<div class="form-group col-sm-6">
                  <label for="address_map">@lang('main.address_map')</label>
                  <input type="text" name="address_map" value="{{$settings->address_map}}" class="form-control" id="address_map" placeholder="@lang('main.address_map')">
                </div>
                <div class="form-group col-sm-6">
                  <label for="embed_map">@lang('main.embed_map')</label>
                  <input type="text" name="embed_map" value="{{$settings->embed_map}}" class="form-control" id="embed_map" placeholder="@lang('main.embed_map')">
                </div>--}}
                {{--<div class="form-group col-sm-6">
                  <label for="base_logo">@lang('main.base_logo')</label>
                  <input type="file" name="base_logo"  class="form-control" id="base_logo" >
                  <img src="{{url('/storage/'.$settings->base_logo)}}" width="120px">
                </div>--}}
                <div class="form-group col-sm-6">
                  <label for="logo">@lang('main.logo')</label>
                  <input type="file" name="logo"  class="form-control" id="logo" >
                  @if(config('desktop_dashboard.local'))<small class="text-muted">PNG، JPG، WEBP فقط؛ مجموع الصور حتى 5 ميجابايت، وأبعاد كل صورة حتى 4096 × 4096.</small>@endif
                  <img src="{{url('/storage/'.$settings->logo)}}" width="120px">
                </div>
                <div class="form-group col-sm-6">
                  <label for="favicon">@lang('main.favicon')</label>
                  <input type="file" name="favicon"  class="form-control" id="favicon" >
                  @if(config('desktop_dashboard.local'))<small class="text-muted">PNG، JPG، WEBP فقط؛ مجموع الصور حتى 5 ميجابايت، وأبعاد كل صورة حتى 4096 × 4096.</small>@endif
                  <img src="{{url('/storage/'.$settings->favicon)}}" width="120px">
                </div>
              </div>
              </div>
              <div class="tab-pane fade" id="pills-profile" role="tabpanel" aria-labelledby="pills-profile-tab">
                  <div class="row">
                    {{--<div class="form-group col-sm-6">
                      <label for="twitter_link">@lang('main.twitter_link')</label>
                      <input type="url" name="twitter_link" value="{{$settings->twitter_link}}" class="form-control" id="twitter_link" placeholder="@lang('main.twitter_link')">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="facebook_link">@lang('main.facebook_link')</label>
                      <input type="url" name="facebook_link" value="{{$settings->facebook_link}}" class="form-control" id="facebook_link" placeholder="@lang('main.facebook_link')">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="instagram_link">@lang('main.instagram_link')</label>
                      <input type="url" name="instagram_link" value="{{$settings->instagram_link}}" class="form-control" id="instagram_link" placeholder="@lang('main.instagram_link')">
                    </div>
                    <div class="form-group col-sm-6 ">
                      <label for="google_link">@lang('main.google_link')</label>
                      <input type="url" name="google_link" value="{{$settings->google_link}}" class="form-control" id="google_link" placeholder="@lang('main.google_link')">
                    </div>--}}
    
                    <div class="form-group col-sm-6">
                      <label for="googleplay_link">@lang('main.googleplay_link')</label>
                      <input type="url" name="googleplay_link" value="{{$settings->googleplay_link}}" class="form-control" id="googleplay_link" placeholder="@lang('main.googleplay_link')">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="applestore_link">@lang('main.applestore_link')</label>
                      <input type="url" name="applestore_link" value="{{$settings->applestore_link}}" class="form-control" id="applestore_link" placeholder="@lang('main.applestore_link')">
                    </div>
                    
                    <div class="form-group col-sm-6">
                      <label for="vendor_googleplay_link">@lang('main.vendor_googleplay_link')</label>
                      <input type="url" name="vendor_googleplay_link" value="{{$settings->vendor_googleplay_link}}" class="form-control" id="vendor_googleplay_link" placeholder="@lang('main.vendor_googleplay_link')">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="vendor_applestore_link">@lang('main.vendor_applestore_link')</label>
                      <input type="url" name="vendor_applestore_link" value="{{$settings->vendor_applestore_link}}" class="form-control" id="vendor_applestore_link" placeholder="@lang('main.vendor_applestore_link')">
                    </div>
                    
                    <div class="form-group col-sm-6">
                      <label for="delegate_googleplay_link">@lang('main.delegate_googleplay_link')</label>
                      <input type="url" name="delegate_googleplay_link" value="{{$settings->delegate_googleplay_link}}" class="form-control" id="delegate_googleplay_link" placeholder="@lang('main.delegate_googleplay_link')">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="delegate_applestore_link">@lang('main.delegate_applestore_link')</label>
                      <input type="url" name="delegate_applestore_link" value="{{$settings->delegate_applestore_link}}" class="form-control" id="delegate_applestore_link" placeholder="@lang('main.delegate_applestore_link')">
                    </div>
                  </div>
             </div>

             <div class="tab-pane fade" id="pills-home-section" role="tabpanel" aria-labelledby="pills-home-section-tab">
                 <div class="row">
                    <div class="form-group col-sm-6">
                      <label for="terms_ar">@lang('main.terms_ar')</label>
                      <textarea rows="5" name="terms_ar" class="form-control ckeditor" id="terms_ar" placeholder="@lang('main.terms_ar')">{{$settings->terms_ar}}</textarea>
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="terms_en">@lang('main.terms_en')</label>
                      <textarea rows="5" name="terms_en" class="form-control ckeditor" id="terms_en" placeholder="@lang('main.terms_en')">{{$settings->terms_en}}</textarea>
                    </div>
                 </div>
             </div>

             {{--<div class="tab-pane fade" id="pills-meta" role="tabpanel" aria-labelledby="pills-meta-tab">
                <div class="form-group col-sm-10">
                  <label for="meta_tags_ar">@lang('main.meta_tags_ar')</label>
                  <textarea rows="5" name="meta_tags_ar" class="form-control" id="meta_tags_ar" placeholder="@lang('main.meta_tags_ar')">{{$settings->meta_tags_ar}}</textarea>
                </div>
                <div class="form-group col-sm-10">
                  <label for="meta_tags_en">@lang('main.meta_tags_en')</label>
                  <textarea rows="5" name="meta_tags_en" class="form-control" id="meta_tags_en" placeholder="@lang('main.meta_tags_en')">{{$settings->meta_tags_en}}</textarea>
                </div>
                <div class="form-group col-sm-10">
                  <label for="meta_description_ar">@lang('main.meta_description_ar')</label>
                  <input type="text" name="meta_description_ar" value="{{$settings->meta_description_ar}}" class="form-control" id="meta_description_ar" placeholder="@lang('main.meta_description_ar')">
                </div>
                <div class="form-group col-sm-10">
                  <label for="meta_description_en">@lang('main.meta_description_en')</label>
                  <input type="text" name="meta_description_en" value="{{$settings->meta_description_en}}" class="form-control" id="meta_description_en" placeholder="@lang('main.meta_description_en')">
                </div>
             </div>--}}

             <div class="tab-pane fade" id="pills-privacy" role="tabpanel" aria-labelledby="pills-privacy-tab">
                 <div class="row">
                    <div class="form-group col-sm-6">
                      <label for="privacy_ar">@lang('main.privacy_ar')</label>
                      <textarea rows="5" name="policy_ar" class="form-control ckeditor" id="privacy_ar" placeholder="@lang('main.privacy_ar')">{{$settings->policy_ar}}</textarea>
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="privacy_en">@lang('main.privacy_en')</label>
                      <textarea rows="5" name="policy_en" class="form-control ckeditor" id="privacy_en" placeholder="@lang('main.privacy_en')">{{$settings->policy_en}}</textarea>
                    </div>
                 </div>
                
             </div>
             
             <div class="tab-pane fade" id="pills-about" role="tabpanel" aria-labelledby="pills-privacy-tab">
                 <div class="row">
                    <div class="form-group col-sm-6">
                      <label for="slider_title_ar">@lang('main.slider_title_ar')</label>
                      <input type="text" name="slider_title_ar" value="{{$settings->slider_title_ar}}" class="form-control" id="slider_title_ar" placeholder="@lang('main.slider_title_ar')">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="slider_title_en">@lang('main.slider_title_en')</label>
                      <input type="text" name="slider_title_en" value="{{$settings->slider_title_en}}" class="form-control" id="slider_title_en" placeholder="@lang('main.slider_title_en')">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="slider_text_ar">@lang('main.slider_text_ar')</label>
                      <textarea rows="5" name="slider_text_ar" class="form-control" id="slider_text_ar" placeholder="@lang('main.slider_text_ar')">{{$settings->slider_text_ar}}</textarea>
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="slider_text_en">@lang('main.slider_text_en')</label>
                      <textarea rows="5" name="slider_text_en" class="form-control" id="slider_text_en" placeholder="@lang('main.slider_text_en')">{{$settings->slider_text_en}}</textarea>
                    </div>
                    
                    <div class="form-group col-sm-6">
                      <label for="about_ar">@lang('main.about_ar')</label>
                      <textarea rows="5" name="about_ar" class="form-control ckeditor" id="about_ar" placeholder="@lang('main.about_ar')">{{$settings->about_ar}}</textarea>
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="about_en">@lang('main.about_en')</label>
                      <textarea rows="5" name="about_en" class="form-control ckeditor" id="about_en" placeholder="@lang('main.about_en')">{{$settings->about_en}}</textarea>
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
