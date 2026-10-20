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
  .switch {
  position: relative;
  display: inline-block;
  width: 60px;
  height: 34px;
}

.switch input { 
  opacity: 0;
  width: 0;
  height: 0;
}

.slider {
  position: absolute;
  cursor: pointer;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: #ccc;
  -webkit-transition: .4s;
  transition: .4s;
  border-radius: 64px;
}

.slider:before {
  position: absolute;
  content: "";
  height: 26px;
  width: 26px;
  right: 4px;
  bottom: 4px;
  background-color: white;
  -webkit-transition: .4s;
  transition: .4s;
  border-radius: 50%;
}

input:checked + .slider {
  background-color: var(--main);
}
input:checked +.slider:before{
    right: calc(100% - 30px);
}

  /*.slider{*/
  /*  border-radius: 64px  */
  /*}*/
  /*.slider:before{*/
  /*  border-radius: 50%*/
  /*}*/
  /*input:checked + .slider {*/
  /*  background-color: var(--main);*/
  /*}*/
#addSocialModal .input-group input{
    opacity: 0;
    display: none;
}
#addSocialModal .input-group  label{
    color: grey;
    font-size: 3rem;
    cursor: pointer;
}

/*#addSocialModal .form-check-input:checked  + label.form-label {*/
/*    color: black;*/
/*}*/
#addSocialModal .input-group input:checked + label {
    color: black !important;
}
#addSocialModal .input-group input:checked + label svg path {
    fill: #000 !important;
}
.modal-header .close {
    padding: 1rem;
    margin: 0 !important;
}
.no_margin label{
    margin: 0;
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
          <h1 class="m-0 text-dark">Paymob Integration Setting</h1>
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
          @if(config('desktop_dashboard.local'))
          <div class="alert alert-info" role="status">مفاتيح الاتصال وتفعيل الدفع بالكارت والمحفظة تُعدّل من الداشبورد المتصلة فقط. الإعدادات المحفوظة على الجهاز لا تُغيّر إعدادات مزوّد الدفع.</div>
          @else
          <form method="post" action="{{route('updateEnv')}}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            
              <div class="" role="tabpanel" aria-labelledby="pills-home-tab">
                <div class="row">
                <div class="form-group col-sm-12">
                  <label for="PAYMOB_API_KEY">PAYMOB_API_KEY</label>
                  <textarea type="text" name="PAYMOB_API_KEY" class="form-control" id="PAYMOB_API_KEY" 
                  placeholder="Enter PAYMOB_API_KEY">{{env('PAYMOB_API_KEY')}}</textarea>
                </div>
                
                <div class="form-group col-sm-12">
                  <label for="PAYMOB_CARD_INTEGRATION_ID">PAYMOB_CARD_INTEGRATION_ID</label>
                  <textarea type="text" name="PAYMOB_CARD_INTEGRATION_ID" class="form-control " id="PAYMOB_CARD_INTEGRATION_ID" 
                  placeholder="Enter PAYMOB_CARD_INTEGRATION_ID">{{env('PAYMOB_CARD_INTEGRATION_ID')}}</textarea>
                </div>
                
                <div class="form-group col-sm-12">
                  <label for="PAYMOB_CARD_IFRAME_ID">PAYMOB_CARD_IFRAME_ID</label>
                  <textarea type="text" name="PAYMOB_CARD_IFRAME_ID" class="form-control " id="PAYMOB_CARD_IFRAME_ID" 
                  placeholder="Enter PAYMOB_CARD_IFRAME_ID">{{env('PAYMOB_CARD_IFRAME_ID')}}</textarea>
                </div>
                
                <div class="form-group col-sm-12">
                  <label for="PAYMOB_MOBILE_WALLET_INTEGRATION_ID">PAYMOB_MOBILE_WALLET_INTEGRATION_ID</label>
                  <textarea type="text" name="PAYMOB_MOBILE_WALLET_INTEGRATION_ID" class="form-control " id="PAYMOB_MOBILE_WALLET_INTEGRATION_ID" 
                  placeholder="Enter PAYMOB_MOBILE_WALLET_INTEGRATION_ID">{{env('PAYMOB_MOBILE_WALLET_INTEGRATION_ID')}}</textarea>
                </div>
               
              </div>
              </div>
          <button type="submit" class="btn btn-success">@lang('main.save')</button>
         </form>
         @endif
         <div class="my-3">
            <hr>
         </div>
         <h4 class="mb-3 mt-3">@lang('main.activate integration payments')</h4>
         @if(!config('desktop_dashboard.local'))
         <form method="post" action="{{route('updatePaymentActivation')}}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            
              <div class="no_margin" role="tabpanel" aria-labelledby="pills-home-tab">
                <div class="row">
                <div class="form-group d-flex gap-2 col-sm-10">
                    <div class='d-flex align-items-center gap-1'>
                      <label class="switch">
                      <input type="checkbox" name="payment_card_activate" @if($settings->payment_card_activate == true) checked @endif>
                      <span class="slider"></span>
                    </label>
                   </div>
                    <label for="payment_card_activate">@lang('main.payment_card_activate')</label>

                </div>
                
                <div class="form-group d-flex gap-2 col-sm-10">
                    <div class='d-flex align-items-center gap-1'>
                      <label class="switch">
                      <input type="checkbox" name="wallet_card_activate" @if($settings->wallet_card_activate == true) checked @endif>
                      <span class="slider"></span>
                    </label>
                   </div>
                    <label for="wallet_card_activate">@lang('main.wallet_card_activate')</label>

                </div>
               
              </div>
              </div>
          <button type="submit" class="btn btn-success">@lang('main.save')</button>
         </form>
         @endif
       </div>
     </div>
   </div>
 </div>
</section>
</div>               
@endsection
